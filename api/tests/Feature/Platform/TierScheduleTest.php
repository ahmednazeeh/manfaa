<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\FeeTierSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-08-14T12:00:00+05:00'));
    $this->admin = AdminUser::factory()->create();
    $this->actingAs($this->admin, 'admin');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A valid future-dated schedule payload; override pieces to break it.
 */
function tierSchedulePayload(array $overrides = []): array
{
    return [
        'effective_from' => CarbonImmutable::now()->addDay()->toIso8601String(),
        'tiers' => [
            ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 30],
            ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 60],
            ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 90],
            ['from_bp' => 500, 'to_bp' => 1000, 'fee_bp' => 120],
        ],
        ...$overrides,
    ];
}

it('ships the seeded §4 default schedule effective from the far past', function () {
    $seeded = FeeTierSchedule::query()->sole();

    expect($seeded->effective_from->year)->toBe(1970)
        ->and($seeded->created_by)->toBeNull()
        // toEqual: jsonb normalises key order inside each band.
        ->and($seeded->tiers)->toEqual([
            ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 25],
            ['from_bp' => 100, 'to_bp' => 199, 'fee_bp' => 50],
            ['from_bp' => 200, 'to_bp' => 499, 'fee_bp' => 75],
            ['from_bp' => 500, 'to_bp' => 1000, 'fee_bp' => 100],
        ]);

    $this->getJson('/api/admin/platform/fee-tiers')
        ->assertOk()
        ->assertJsonPath('data.current.id', $seeded->id)
        ->assertJsonPath('data.current.tiers.2.fee_bp', 75)
        ->assertJsonCount(1, 'data.history');
});

it('publishes a valid future-dated schedule, audited and appended to history', function () {
    $id = $this->postJson('/api/admin/platform/fee-tiers', tierSchedulePayload())
        ->assertCreated()
        ->assertJsonPath('data.created_by', $this->admin->id)
        ->assertJsonPath('data.tiers.0.fee_bp', 30)
        ->json('data.id');

    // Not yet effective: current is still the seeded default.
    $this->getJson('/api/admin/platform/fee-tiers')
        ->assertOk()
        ->assertJsonPath('data.current.tiers.0.fee_bp', 25)
        ->assertJsonCount(2, 'data.history')
        ->assertJsonPath('data.history.0.id', $id);

    // Once its instant passes, it becomes current — the seeded row stays in
    // history untouched (append-only).
    Carbon::setTestNow(CarbonImmutable::now()->addDays(2));

    $this->getJson('/api/admin/platform/fee-tiers')
        ->assertOk()
        ->assertJsonPath('data.current.id', $id)
        ->assertJsonCount(2, 'data.history');
});

// Cap widening: the ceiling is now the schedule's own last to_bp — a table
// ending below the old fixed 1000bp bound is legal (those upper rates are
// simply not priced, hence not sellable while it is active).
it('publishes a schedule ending below 1000bp — the ceiling is its own last band', function () {
    $this->postJson('/api/admin/platform/fee-tiers', tierSchedulePayload(['tiers' => [
        ['from_bp' => 50, 'to_bp' => 999, 'fee_bp' => 25],
    ]]))->assertCreated();

    expect(FeeTierSchedule::query()->count())->toBe(2);
});

it('rejects malformed or premature schedules', function (array $overrides, string $becauseOf) {
    $this->postJson('/api/admin/platform/fee-tiers', tierSchedulePayload($overrides))
        ->assertStatus(422);

    // Nothing was appended.
    expect(FeeTierSchedule::query()->count())->toBe(1, $becauseOf);
})->with([
    'gap' => [['tiers' => [
        ['from_bp' => 50, 'to_bp' => 98, 'fee_bp' => 25],
        ['from_bp' => 100, 'to_bp' => 1000, 'fee_bp' => 100],
    ]], 'a 1bp gap at 99 must be rejected'],
    'overlap' => [['tiers' => [
        ['from_bp' => 50, 'to_bp' => 100, 'fee_bp' => 25],
        ['from_bp' => 100, 'to_bp' => 1000, 'fee_bp' => 100],
    ]], 'overlapping bands must be rejected'],
    'descending order' => [['tiers' => [
        ['from_bp' => 100, 'to_bp' => 1000, 'fee_bp' => 100],
        ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 25],
    ]], 'bands must ascend'],
    'starts above 50' => [['tiers' => [
        ['from_bp' => 60, 'to_bp' => 1000, 'fee_bp' => 50],
    ]], 'coverage must start at exactly 50bp'],
    'ends above 2000' => [['tiers' => [
        ['from_bp' => 50, 'to_bp' => 2001, 'fee_bp' => 25],
    ]], 'coverage must never exceed the absolute 2000bp ceiling'],
    'inverted band' => [['tiers' => [
        ['from_bp' => 50, 'to_bp' => 49, 'fee_bp' => 25],
        ['from_bp' => 50, 'to_bp' => 1000, 'fee_bp' => 25],
    ]], 'from_bp above to_bp must be rejected'],
    'fee above rate' => [['tiers' => [
        ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 51],
        ['from_bp' => 100, 'to_bp' => 1000, 'fee_bp' => 100],
    ]], 'the fee may never exceed the cashback rate'],
    'zero fee' => [['tiers' => [
        ['from_bp' => 50, 'to_bp' => 99, 'fee_bp' => 0],
        ['from_bp' => 100, 'to_bp' => 1000, 'fee_bp' => 100],
    ]], 'fee_bp must be a positive integer'],
    'past effective_from' => [[
        'effective_from' => '2026-08-13T12:00:00+05:00',
    ], 'a past effective_from would reprice in-flight transactions'],
    'effective_from under one hour ahead' => [[
        'effective_from' => '2026-08-14T12:30:00+05:00',
    ], 'less than one hour of lead time is ambiguous for in-flight rows'],
]);
