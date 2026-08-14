<?php

declare(strict_types=1);

use App\Domain\Platform\AdminAccountException;
use App\Domain\Platform\AdminAccountService;
use App\Models\AdminUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Regression: two concurrent demotes/deactivations of DIFFERENT superadmins
 * used to each lock only their own target row, and each plain READ COMMITTED
 * guard read still saw the other's uncommitted 'superadmin' row — both
 * passed, both committed, zero active superadmins remained, and the whole
 * superadmin-gated surface was locked permanently. The fix serialises the
 * last-superadmin guard under a constant-key pg_advisory_xact_lock.
 */
function superadminRacePdo(): PDO
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

function superadminRaceCleanup(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->exec("DELETE FROM admin_users WHERE email IN ('race-sa-a@example.com', 'race-sa-b@example.com')");
    } catch (PDOException) {
        // A failing test can still hold row locks in the wrapper
        // transaction; leftovers are wiped by the next migrate:fresh and
        // must not mask the real failure.
    }
}

it('makes a concurrent demote WAIT on the guard lock instead of passing on a stale read', function () {
    $pdo = superadminRacePdo();
    superadminRaceCleanup($pdo);

    // Seed the only two active superadmins as COMMITTED rows, visible to
    // both connections (the test wrapper transaction is READ COMMITTED).
    $pdo->exec("INSERT INTO admin_users (name, email, password, role, is_active, created_at, updated_at) VALUES
        ('Race A', 'race-sa-a@example.com', 'x', 'superadmin', true, now(), now()),
        ('Race B', 'race-sa-b@example.com', 'x', 'superadmin', true, now(), now())");

    $a = AdminUser::query()->where('email', 'race-sa-a@example.com')->sole();
    $b = AdminUser::query()->where('email', 'race-sa-b@example.com')->sole();

    try {
        // The concurrent transaction (B demoting A) has row-locked its
        // target, taken the guard lock, passed the guard and written its
        // demotion — but NOT committed yet.
        $pdo->beginTransaction();
        $pdo->query("SELECT * FROM admin_users WHERE id = {$a->id} FOR UPDATE");
        $pdo->query(sprintf(
            'SELECT pg_advisory_xact_lock(%d::int, %d::int)',
            AdminAccountService::SUPERADMIN_GUARD_LOCK_CLASS,
            AdminAccountService::SUPERADMIN_GUARD_LOCK_KEY,
        ));
        $pdo->exec("UPDATE admin_users SET role = 'admin' WHERE id = {$a->id}");

        // A demoting B now must WAIT on the guard lock (surfaced here as a
        // lock timeout) — before the fix it sailed through on a stale read
        // and committed, leaving zero active superadmins.
        DB::statement("SET LOCAL lock_timeout = '400ms'");

        expect(fn () => app(AdminAccountService::class)->update($b, $a, role: 'admin'))
            ->toThrow(QueryException::class);

        expect($b->refresh()->role)->toBe('superadmin');

        // Had the concurrent demotion committed first, the freshly visible
        // state would refuse this one outright — never zero superadmins.
        $pdo->commit();

        expect(fn () => app(AdminAccountService::class)->update($b, $a->refresh(), role: 'admin'))
            ->toThrow(AdminAccountException::class);

        expect($b->refresh()->role)->toBe('superadmin')
            ->and(
                (int) $pdo->query("SELECT count(*) FROM admin_users WHERE role = 'superadmin' AND is_active")
                    ->fetchColumn()
            )->toBeGreaterThanOrEqual(1);
    } finally {
        superadminRaceCleanup($pdo);
    }
});

it('takes the guard lock inside the transaction for both demotion and deactivation', function () {
    $actor = AdminUser::factory()->create(['role' => 'superadmin']);
    $targetDemote = AdminUser::factory()->create(['role' => 'superadmin']);
    $targetDeactivate = AdminUser::factory()->create(['role' => 'superadmin']);
    AdminUser::factory()->create(['role' => 'superadmin']); // guard passes

    $lockCalls = 0;
    DB::listen(function ($query) use (&$lockCalls) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $lockCalls++;
            expect($query->bindings[0])->toBe(AdminAccountService::SUPERADMIN_GUARD_LOCK_CLASS)
                ->and($query->bindings[1])->toBe(AdminAccountService::SUPERADMIN_GUARD_LOCK_KEY);
        }
    });

    $service = app(AdminAccountService::class);

    $service->update($targetDemote, $actor, role: 'admin');
    expect($lockCalls)->toBe(1);

    $service->update($targetDeactivate, $actor, isActive: false);
    expect($lockCalls)->toBe(2);

    // Updates that can never remove a superadmin skip the guard lock.
    $plain = AdminUser::factory()->create(['role' => 'admin']);
    $service->update($plain, $actor, role: 'superadmin');
    expect($lockCalls)->toBe(2);
});
