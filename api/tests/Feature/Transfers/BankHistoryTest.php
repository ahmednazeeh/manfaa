<?php

declare(strict_types=1);

use App\Domain\Transfers\BankHistoryClient;
use App\Domain\Transfers\TransferClient;
use App\Models\TransferProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Reading the two banks' history.
 *
 * The field names are the BANKS' own — passed through verbatim from MIB's
 * /ajaxAccounts/trxHistory and BML's feed — so these tests are written
 * against the shapes the owner supplied, not against ours. If either bank
 * renames a field, a test here is what tells us.
 */

beforeEach(function (): void {
    config()->set('services.transfer.api_key', 'test-key');
});

function mibProfile(): TransferProfile
{
    return TransferProfile::create([
        'name' => 'MIB Faisanet',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'faisanet',
        'from_account' => '90501400021681001',
        'active' => true,
        'is_default' => true,
    ]);
}

function bmlProfile(): TransferProfile
{
    return TransferProfile::create([
        'name' => 'BML',
        'base_url' => 'http://10.99.0.1:3005',
        'segment' => 'bml',
        // Ignored by design on /bml — the account comes from the profile
        // name instead.
        'from_account' => null,
        'upstream_profile' => 'CLEVIDEN',
        'active' => true,
    ]);
}

it('reads an MIB row using the bank\'s own field names', function (): void {
    Http::fake(['*/faisanet/history*' => Http::response(['data' => [[
        'accountNo' => '90501400021681001',
        'trxNumber' => '1-703337593-804802801-1',
        'trxNumber2' => '804802801',
        'trxDate' => '2026-08-17 17:46:49',
        'absAmount' => 2250,
        'baseAmount' => 2250,
        'descr3' => 'BML - ZEEDHAN ABDULLA, Deposit ref.',
        'benefName' => 'BML - ZEEDHAN ABDULLA',
    ]]])]);

    $rows = app(BankHistoryClient::class)->history(mibProfile(), '90501400021681001');

    expect($rows)->toHaveCount(1);
    // trxNumber2, the short form — the one that matches a transfer's trx_id.
    expect($rows[0]->reference)->toBe('804802801');
    // The "BML - " bank prefix is stripped: it is a routing label, not part
    // of anyone's name.
    expect($rows[0]->name)->toBe('ZEEDHAN ABDULLA');
    expect($rows[0]->amountLaari)->toBe(225000);
    expect($rows[0]->incoming)->toBeTrue();
});

it('reads direction from baseAmount, not from trxType', function (): void {
    Http::fake(['*/faisanet/history*' => Http::response(['data' => [[
        'trxNumber2' => '804802801',
        // Negative: money LEFT. absAmount is unsigned and says nothing
        // about direction, which is exactly the trap.
        'baseAmount' => -2250,
        'absAmount' => 2250,
        'trxType' => 800,
        'benefName' => 'AHMED NAZEEH',
    ]]])]);

    $rows = app(BankHistoryClient::class)->history(mibProfile(), '90501400021681001');

    expect($rows[0]->incoming)->toBeFalse();
    expect($rows[0]->amountLaari)->toBe(225000);
});

it('prefers narrative3 over narrative1 on a BML row', function (): void {
    Http::fake(['*/bml/history*' => Http::response([[
        'id' => 'row-1',
        'reference' => 'FT26082700001',
        'narrative1' => 'IPS TRANSFER',
        'narrative3' => 'AHMED NAZEEH',
        'amount' => 150.5,
        'minus' => false,
        'bookingDate' => '2026-08-17T00:00:00+05:00',
    ]])]);

    $rows = app(BankHistoryClient::class)->history(bmlProfile(), '7730000757923');

    expect($rows[0]->name)->toBe('AHMED NAZEEH');
    expect($rows[0]->reference)->toBe('FT26082700001');
    expect($rows[0]->amountLaari)->toBe(15050);
    expect($rows[0]->incoming)->toBeTrue();
});

it('falls back to narrative1 when narrative3 is absent', function (): void {
    Http::fake(['*/bml/history*' => Http::response([[
        'id' => 'row-1',
        'narrative1' => 'MARIYAM SHIFA',
        'amount' => 20,
        'minus' => false,
    ]])]);

    $rows = app(BankHistoryClient::class)->history(bmlProfile(), '7730000757923');

    expect($rows[0]->name)->toBe('MARIYAM SHIFA');
    // No `reference`, so `id` carries it — the last-resort fallback.
    expect($rows[0]->reference)->toBe('row-1');
});

it('reads minus as the debit flag', function (): void {
    Http::fake(['*/bml/history*' => Http::response([[
        'id' => 'row-1',
        'narrative3' => 'AHMED NAZEEH',
        'amount' => 20,
        'minus' => true,
    ]])]);

    $rows = app(BankHistoryClient::class)->history(bmlProfile(), '7730000757923');

    expect($rows[0]->incoming)->toBeFalse();
});

it('sends the account and page for MIB and the account and profile for BML', function (): void {
    Http::fake([
        '*/faisanet/history*' => Http::response(['data' => []]),
        '*/bml/history*' => Http::response([]),
    ]);

    app(BankHistoryClient::class)->history(mibProfile(), '90501400021681001');
    app(BankHistoryClient::class)->history(bmlProfile(), '7730000757923');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/faisanet/history')
        && str_contains($request->url(), 'account=90501400021681001')
        && str_contains($request->url(), 'page=1'));

    // The UPSTREAM name, not the display name — renaming a profile in the
    // panel must not change what goes on the wire.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/bml/history')
        && str_contains($request->url(), 'account=7730000757923')
        && str_contains($request->url(), 'profile=CLEVIDEN'));
});

it('returns nothing rather than throwing when the bank is unreachable', function (): void {
    Http::fake(['*' => Http::response('gateway down', 502)]);

    // A poll that throws would take a queue worker down with it; an empty
    // history simply means "not matched yet", which is the truth.
    expect(app(BankHistoryClient::class)->history(mibProfile(), '90501400021681001'))->toBe([]);
});

it('will not read BML history without the upstream profile name', function (): void {
    // BML picks the account from the profile name. Without it the call would
    // answer for the wrong account or not at all, and reading somebody
    // else's ledger is worse than reading none.
    Http::fake(['*' => Http::response([])]);

    $profile = bmlProfile();
    $profile->forceFill(['upstream_profile' => null])->save();

    expect(app(BankHistoryClient::class)->history($profile, '7730000757923'))->toBe([]);
    Http::assertNothingSent();
});

it('sends the profile name on a BML transfer and no from_account', function (): void {
    Http::fake(['*' => Http::response(['status' => 'success', 'trx_id' => 'TRX-1'])]);

    $profile = bmlProfile();
    $profile->forceFill(['from_account' => '90501400021681001'])->save();

    app(TransferClient::class)->send(
        $profile,
        account: '7730000757923',
        amountLaari: 12500,
        internalRef: 'MANFAA-TEST-1',
    );

    Http::assertSent(function ($request) {
        // from_account is ignored by that upstream, so we do not send it —
        // a field the other end drops is a field that misleads whoever reads
        // our request log.
        return ($request->data()['profile'] ?? null) === 'CLEVIDEN'
            && ! array_key_exists('from_account', $request->data());
    });
});
