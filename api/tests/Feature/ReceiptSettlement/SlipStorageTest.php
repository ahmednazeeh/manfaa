<?php

declare(strict_types=1);

use App\Domain\Settlement\InvalidSlipException;
use App\Domain\Settlement\SlipStorage;
use App\Domain\Settlement\SlipWriteFailedException;
use App\Models\AdminUser;
use App\Models\Settlement;
use App\Models\SettlementPayment;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\ReceiptSettlement\Slips;
use Tests\Feature\Settlement\SettlementFixture;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LedgerAccountSeeder::class);
    Storage::fake('slips');
    $this->fixture = SettlementFixture::payableBatch();
    $this->admin = AdminUser::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function submitSlip(UploadedFile $slip, string $bankRef = 'BML-SLIP-1'): TestResponse
{
    return test()->actingAs(test()->fixture->user, 'merchant')
        ->post('/api/merchant/settlements', [
            'settle_all' => '1',
            'amount' => 11825,
            'bank_ref' => $bankRef,
            'slip' => $slip,
        ]);
}

it('stores an accepted slip privately, and nowhere the web can reach it', function () {
    submitSlip(Slips::jpeg())->assertCreated();

    $payment = SettlementPayment::query()->sole();
    $settlement = Settlement::query()->sole();

    // Path shape: settlements/{merchant}/{settlement}/{uuid}.{ext}, with the
    // extension derived from the BYTES, not the uploaded filename.
    expect($payment->slip_path)->toStartWith("settlements/{$this->fixture->merchant->id}/{$settlement->id}/")
        ->and($payment->slip_path)->toEndWith('.jpg')
        ->and($payment->slip_mime)->toBe('image/jpeg')
        ->and($payment->slip_size_bytes)->toBeGreaterThan(0)
        ->and($payment->uploaded_by)->toBe($this->fixture->user->id)
        ->and(Storage::disk(SlipStorage::DISK)->exists($payment->slip_path))->toBeTrue();

    // It is NOT on the public disk, and the public disk cannot see it.
    expect(Storage::disk('public')->exists($payment->slip_path))->toBeFalse();

    // The disk itself is un-URLable: no `url`, and `serve` off, so Laravel
    // will not generate — nor serve — any path to it, signed or otherwise.
    expect(config('filesystems.disks.slips.url'))->toBeNull()
        ->and(config('filesystems.disks.slips.serve'))->toBeFalse()
        ->and(config('filesystems.disks.slips.root'))->not->toContain('app/public')
        ->and(str_starts_with((string) config('filesystems.disks.slips.root'), public_path()))->toBeFalse();

    // And no route serves it: the /storage fallback (which DOES serve the
    // `local` disk) refuses the slips path — the file is not under it.
    expect($this->get('/storage/'.$payment->slip_path)->getStatusCode())->toBeGreaterThanOrEqual(400)
        ->and($this->get('/'.$payment->slip_path)->getStatusCode())->toBeGreaterThanOrEqual(400);
});

it('streams the slip to an authenticated admin and to nobody else', function () {
    submitSlip(Slips::pdf())->assertCreated();

    $settlementId = Settlement::query()->sole()->id;

    // Guests and merchants are refused; only the admin guard streams it.
    $this->get("/api/admin/settlements/{$settlementId}/slip")->assertUnauthorized();

    $this->actingAs($this->fixture->user, 'merchant')
        ->get("/api/admin/settlements/{$settlementId}/slip")
        ->assertUnauthorized();

    $response = $this->actingAs($this->admin, 'admin')
        ->get("/api/admin/settlements/{$settlementId}/slip")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toStartWith('%PDF-');
});

it('404s the slip route on a settlement that has none', function () {
    $this->actingAs($this->admin, 'admin');

    $settlement = Settlement::query()->create([
        'merchant_id' => $this->fixture->merchant->id,
        'reference' => 'ST-2026-09999',
        'state' => 'draft',
        'funding_method' => 'bank',
        'currency' => 'MVR',
    ]);

    $this->get("/api/admin/settlements/{$settlement->id}/slip")->assertNotFound();
});

/*
 * The validation matrix. Every accepted format is accepted by its BYTES;
 * every refusal is a refusal of the bytes, not of the filename — a renamed
 * SVG and an HTML page wearing a .png name and an image/png Content-Type are
 * exactly the attacks a `mimes:` rule waves through.
 */
it('accepts jpg, png, webp and pdf by their magic bytes', function (string $factory, string $mime, string $extension) {
    submitSlip(Slips::$factory(), 'BML-MATRIX-'.$extension)->assertCreated();

    $payment = SettlementPayment::query()->sole();

    expect($payment->slip_mime)->toBe($mime)
        ->and($payment->slip_path)->toEndWith('.'.$extension);
})->with([
    'jpeg' => ['jpeg', 'image/jpeg', 'jpg'],
    'png' => ['png', 'image/png', 'png'],
    'webp' => ['webp', 'image/webp', 'webp'],
    'pdf' => ['pdf', 'application/pdf', 'pdf'],
]);

it('refuses an SVG even though it is a perfectly ordinary image extension', function () {
    submitSlip(Slips::svg())
        ->assertUnprocessable()
        ->assertJsonPath('code', InvalidSlipException::CODE_UNSUPPORTED);

    expect(Settlement::query()->count())->toBe(0);
});

it('refuses HTML bytes wearing a .png name and an image/png content type', function () {
    submitSlip(Slips::spoofedPng())
        ->assertUnprocessable()
        ->assertJsonPath('code', InvalidSlipException::CODE_UNSUPPORTED);

    expect(Settlement::query()->count())->toBe(0);
});

it('refuses an SVG renamed to .png', function () {
    submitSlip(Slips::svg('receipt.png'))
        ->assertUnprocessable()
        ->assertJsonPath('code', InvalidSlipException::CODE_UNSUPPORTED);
});

it('refuses an empty file', function () {
    submitSlip(Slips::empty())->assertUnprocessable();

    expect(Settlement::query()->count())->toBe(0);
});

it('refuses a slip over 5 MB', function () {
    submitSlip(Slips::oversizeJpeg())->assertUnprocessable();

    expect(Settlement::query()->count())->toBe(0);
});

/*
 * A failed write must never be silent. The `slips` disk is configured
 * throw=false/report=false, so putFileAs answers FALSE and logs nothing when
 * the write fails (ENOSPC, a quota, a permissions change under
 * storage/app/slips). Taking that as success would commit a payment_review
 * settlement whose slip_path points at nothing — PLAN §1's "no settlement
 * without a receipt" broken with no trace, and the admin's only review route
 * able to answer nothing but 404.
 */

/** Swaps the slips disk for one whose writes always fail. */
function failingSlipDisk(): void
{
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->andReturn(false);
    $disk->shouldReceive('delete')->andReturn(true);
    $disk->shouldReceive('exists')->andReturn(false);

    Storage::set(SlipStorage::DISK, $disk);
}

it('creates no settlement at all when the private disk refuses the write', function () {
    failingSlipDisk();

    submitSlip(Slips::jpeg(), 'BML-DISK-FULL')->assertStatus(500);

    // The whole submission rolled back: no batch, no payment claiming a
    // transfer, and no line frozen out of the merchant's next settlement.
    expect(Settlement::query()->count())->toBe(0)
        ->and(SettlementPayment::query()->count())->toBe(0)
        ->and(DB::table('settlement_lines')->count())->toBe(0);

    // And the merchant can still settle — nothing was claimed or burnt.
    $this->actingAs($this->fixture->user, 'merchant')
        ->getJson('/api/merchant/outstanding')
        ->assertOk()
        ->assertJsonPath('data.total.count', 4);
});

it('refuses a failed write in the domain, not only at the HTTP boundary', function () {
    failingSlipDisk();

    $settlement = Settlement::query()->create([
        'merchant_id' => $this->fixture->merchant->id,
        'reference' => 'ST-2026-09998',
        'state' => 'draft',
        'funding_method' => 'bank',
        'currency' => 'MVR',
    ]);

    expect(fn () => app(SlipStorage::class)->store(
        $this->fixture->merchant,
        $settlement,
        Slips::jpeg(),
        ['mime' => 'image/jpeg', 'extension' => 'jpg', 'size' => 64],
    ))->toThrow(SlipWriteFailedException::class);
});

it('enforces the size ceiling in the domain, not only at the HTTP boundary', function () {
    // The controller's `max:` rule is a first-pass gate; SlipStorage is the
    // authority, and it must refuse on its own.
    expect(fn () => app(SlipStorage::class)->inspect(Slips::oversizeJpeg()))
        ->toThrow(InvalidSlipException::class);

    try {
        app(SlipStorage::class)->inspect(Slips::oversizeJpeg());
    } catch (InvalidSlipException $exception) {
        expect($exception->errorCode)->toBe(InvalidSlipException::CODE_TOO_LARGE);
    }

    expect(SlipStorage::MAX_BYTES)->toBe(5 * 1024 * 1024);
});
