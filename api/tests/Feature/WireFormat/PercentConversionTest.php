<?php

declare(strict_types=1);

use App\Domain\Money\Percent;
use App\Models\Merchant;
use App\Models\MerchantRate;
use App\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    MerchantRate::factory()->for($this->merchant)->create([
        'rate_bp' => 200,
        'effective_from' => now()->subYear(),
        'effective_to' => null,
    ]);
    $this->owner = MerchantUser::factory()->for($this->merchant)->owner()->create();
    $this->actingAs($this->owner, 'merchant');
});

it('round-trips percent <-> basis points exactly across the whole 50..2000 range', function () {
    for ($bp = 50; $bp <= 2000; $bp++) {
        $percent = Percent::format($bp);

        expect($percent)->toMatch('/^\d+\.\d{2}$/')
            ->and(Percent::toBasisPoints($percent))->toBe($bp)
            // The same digits sent as a JSON number must land on the same
            // integer: the float goes through its shortest round-trip
            // decimal string, never a multiplication.
            ->and(Percent::toBasisPoints((float) $percent))->toBe($bp);
    }
});

it('formats the §4 fixtures the way the wire quotes them', function () {
    expect(Percent::format(200))->toBe('2.00')
        ->and(Percent::format(75))->toBe('0.75')
        ->and(Percent::format(1250))->toBe('12.50')
        ->and(Percent::format(0))->toBe('0.00')
        ->and(Percent::format(2000))->toBe('20.00')
        ->and(Percent::formatOrNull(null))->toBeNull()
        ->and(Percent::formatDelta(-26))->toBe('-0.26');
});

it('accepts a string or a JSON number with at most 2 decimals', function () {
    expect(Percent::toBasisPoints('2'))->toBe(200)
        ->and(Percent::toBasisPoints('2.5'))->toBe(250)
        ->and(Percent::toBasisPoints('2.50'))->toBe(250)
        ->and(Percent::toBasisPoints(2))->toBe(200)
        ->and(Percent::toBasisPoints(2.5))->toBe(250)
        ->and(Percent::toBasisPoints(0.5))->toBe(50)
        ->and(Percent::toBasisPoints(12.75))->toBe(1275)
        ->and(Percent::toBasisPoints('20'))->toBe(2000);
});

it('refuses over-precise, negative, out-of-range and non-numeric percents', function (mixed $bad) {
    expect(fn () => Percent::toBasisPoints($bad))->toThrow(InvalidArgumentException::class);
})->with([
    '2.555 (3 decimals)' => '2.555',
    '2.555 as a float' => 2.555,
    'negative string' => '-1',
    'negative number' => -1,
    'letters' => 'abc',
    'empty' => '',
    'whitespace' => ' 2.00 ',
    'thousands separator' => '1,000',
    'exponent' => '2e1',
    'below the floor' => '0.49',
    'above the ceiling' => '20.01',
    'infinite' => INF,
]);

it('takes every accepted wire form on POST /api/merchant/rate and stores the same basis points', function (mixed $sent, int $expectedBp) {
    $this->postJson('/api/merchant/rate', ['cashback_rate_percent' => $sent])
        ->assertOk()
        ->assertJsonPath('data.current.cashback_rate_percent', Percent::format($expectedBp));

    expect(MerchantRate::query()->where('merchant_id', $this->merchant->id)->max('rate_bp'))->toBe($expectedBp);
})->with([
    'string integer' => ['3', 300],
    'json integer' => [3, 300],
    'string with one decimal' => ['2.5', 250],
    'json number with one decimal' => [2.5, 250],
    'string with two decimals' => ['2.55', 255],
]);

it('refuses a malformed percent as a field error, never a silent reinterpretation', function (mixed $bad) {
    $this->postJson('/api/merchant/rate', ['cashback_rate_percent' => $bad])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cashback_rate_percent');

    // The standing rate is untouched — nothing was written.
    expect(MerchantRate::query()->where('merchant_id', $this->merchant->id)->count())->toBe(1);
})->with([
    'three decimals' => '2.555',
    'three decimals as a number' => 2.555,
    'negative' => -1,
    'negative string' => '-1',
    'letters' => 'abc',
    'true' => true,
    'array' => [[2]],
]);
