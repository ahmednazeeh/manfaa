<?php

declare(strict_types=1);

use App\Domain\MerchantSettings\StaffException;
use App\Domain\MerchantSettings\StaffService;
use App\Models\Merchant;
use App\Models\MerchantUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Mirror of AdminSuperadminRaceTest for the merchant-side last-owner
 * guard: two concurrent demotes/deactivations of DIFFERENT owners each
 * lock only their own target row, and each plain READ COMMITTED guard
 * read still sees the other's uncommitted 'owner' row — without the
 * merchant-keyed pg_advisory_xact_lock both would pass and the merchant
 * would end with zero active owners, locking its settings surface
 * permanently.
 */
function ownerRacePdo(): PDO
{
    $config = config('database.connections.pgsql');

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    // Never let this second session hang the suite on a contended row —
    // any unexpected lock wait errors out instead.
    $pdo->exec("SET lock_timeout = '2s'");

    return $pdo;
}

function ownerRaceCleanup(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->exec("DELETE FROM merchant_users WHERE email IN ('race-own-a@example.com', 'race-own-b@example.com')");
        $pdo->exec("DELETE FROM merchants WHERE slug = 'owner-race-merchant'");
    } catch (PDOException) {
        // A failing test can still hold row locks in the wrapper
        // transaction; leftovers are wiped by the next migrate:fresh and
        // must not mask the real failure.
    }
}

it('makes a concurrent demote WAIT on the guard lock instead of passing on a stale read', function () {
    $pdo = ownerRacePdo();
    ownerRaceCleanup($pdo);

    // Seed a COMMITTED merchant with its only two active owners, visible
    // to both connections (the test wrapper transaction is READ COMMITTED).
    $merchantId = (int) $pdo->query("INSERT INTO merchants (name, slug, created_at, updated_at)
        VALUES ('Owner Race Merchant', 'owner-race-merchant', now(), now()) RETURNING id")->fetchColumn();

    $pdo->exec("INSERT INTO merchant_users (merchant_id, name, email, password, role, is_active, created_at, updated_at) VALUES
        ({$merchantId}, 'Race A', 'race-own-a@example.com', 'x', 'owner', true, now(), now()),
        ({$merchantId}, 'Race B', 'race-own-b@example.com', 'x', 'owner', true, now(), now())");

    $a = MerchantUser::query()->where('email', 'race-own-a@example.com')->sole();
    $b = MerchantUser::query()->where('email', 'race-own-b@example.com')->sole();

    try {
        // The concurrent transaction (B demoting A) has row-locked its
        // target, taken the merchant's guard lock, passed the guard and
        // written its demotion — but NOT committed yet.
        $pdo->beginTransaction();
        $pdo->query("SELECT * FROM merchant_users WHERE id = {$a->id} FOR UPDATE");
        $pdo->query(sprintf(
            'SELECT pg_advisory_xact_lock(%d::int, %d::int)',
            StaffService::OWNER_GUARD_LOCK_CLASS,
            $merchantId,
        ));
        $pdo->exec("UPDATE merchant_users SET role = 'staff' WHERE id = {$a->id}");

        // A demoting B now must WAIT on the guard lock (surfaced here as a
        // lock timeout) — without the lock it would sail through on a
        // stale read and commit, leaving zero active owners.
        DB::statement("SET LOCAL lock_timeout = '400ms'");

        expect(fn () => app(StaffService::class)->update($b, $a, role: 'staff'))
            ->toThrow(QueryException::class);

        expect($b->refresh()->role)->toBe('owner');

        // Had the concurrent demotion committed first, the freshly visible
        // state must refuse this one outright — never zero active owners.
        $pdo->commit();

        expect(fn () => app(StaffService::class)->update($b, $a->refresh(), role: 'staff'))
            ->toThrow(StaffException::class);

        expect($b->refresh()->role)->toBe('owner')
            ->and(
                (int) $pdo->query("SELECT count(*) FROM merchant_users WHERE merchant_id = {$merchantId} AND role = 'owner' AND is_active")
                    ->fetchColumn()
            )->toBeGreaterThanOrEqual(1);
    } finally {
        ownerRaceCleanup($pdo);
    }
});

it('takes the merchant-keyed guard lock for both demotion and deactivation', function () {
    $merchant = Merchant::factory()->create();
    $actor = MerchantUser::factory()->for($merchant)->owner()->create();
    $targetDemote = MerchantUser::factory()->for($merchant)->owner()->create();
    $targetDeactivate = MerchantUser::factory()->for($merchant)->owner()->create();
    MerchantUser::factory()->for($merchant)->owner()->create(); // guard passes

    $lockCalls = 0;
    DB::listen(function ($query) use (&$lockCalls, $merchant) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockCalls++;
            expect($query->bindings[0])->toBe(StaffService::OWNER_GUARD_LOCK_CLASS)
                ->and($query->bindings[1])->toBe($merchant->id);
        }
    });

    $service = app(StaffService::class);

    $service->update($targetDemote, $actor, role: 'staff');
    expect($lockCalls)->toBe(1);

    $service->update($targetDeactivate, $actor, isActive: false);
    expect($lockCalls)->toBe(2);

    // Updates that can never remove an owner skip the guard lock.
    $plainStaff = MerchantUser::factory()->for($merchant)->create();
    $service->update($plainStaff, $actor, role: 'owner');
    expect($lockCalls)->toBe(2);
});
