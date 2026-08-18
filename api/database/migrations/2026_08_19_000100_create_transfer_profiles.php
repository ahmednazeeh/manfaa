<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP9 — the bank transfer endpoint, configurable by an admin
 * (owner requirement 2026-08-19).
 *
 * The WireGuard peer is not up yet, so nothing about this may be compiled
 * in: the base URL, the profile segment and the debited account all have to
 * be changeable from the admin panel when the tunnel appears, without a
 * deploy.
 *
 * What is NOT here is the API key. A secret belongs in the environment, not
 * in a table an admin session can read — and `x-api-key` is the whole of the
 * upstream's authentication, so a leaked row is a leaked bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');

            // e.g. http://10.99.0.1:3005 — the tunnel address, once it
            // exists.
            $table->string('base_url');
            // e.g. /faisanet, /faisanet2 … Each profile is a different
            // upstream session holding only its own accounts.
            $table->string('segment');

            /*
             * Which of the profile's own accounts is debited. Optional
             * upstream — omitting it takes that profile's default — but we
             * send it explicitly, because "whatever the default is today" is
             * not a thing to reconcile a bank statement against.
             *
             * IGNORED BY DESIGN on /bml/transfer, which is a different
             * upstream entirely. Kept anyway: a profile that changes segment
             * later must not silently lose its account.
             */
            $table->string('from_account')->nullable();

            // Dual control: this upstream can answer 200 with
            // `pending_approval`, which is parked rather than sent. Flagged
            // so an operator reading the queue knows to expect it.
            $table->boolean('dual_control')->default(false);

            $table->boolean('active')->default(false);
            // Exactly one profile is used for automatic transfers.
            $table->boolean('is_default')->default(false);

            $table->timestamps();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users');
        });

        Schema::create('transfer_settings', function (Blueprint $table): void {
            $table->id();
            // OFF until a human turns it on. With no tunnel there is nothing
            // to call, and an automatic payer that silently fails every night
            // is worse than an admin working a visible queue.
            $table->boolean('auto_transfer_enabled')->default(false);
            $table->foreignId('profile_id')->nullable()->constrained('transfer_profiles')->nullOnDelete();
            // A ceiling per automatic transfer. Anything above it waits for a
            // person, whatever the switch says.
            $table->unsignedBigInteger('auto_max_laari')->default(500000);
            $table->timestamps();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users');
        });

        // Exactly one settings row, ever.
        DB::table('transfer_settings')->insert([
            'auto_transfer_enabled' => false,
            'auto_max_laari' => 500000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The profiles as the owner described them, all INACTIVE: the tunnel
        // does not exist yet, and a profile that is switched on before it can
        // be reached would fail every call.
        $now = now();
        $seed = [
            ['Faseyha Faisaa', '/faisanet', '90501400021681001', false],
            ['Interbridge', '/faisanet2', '90101480042281001', false],
            ['Ahmed Nazeeh', '/faisanet3', '90103100810081001', false],
            ['Cleviden', '/faisanet4', '90501480029671000', true],
        ];

        foreach ($seed as [$name, $segment, $account, $dual]) {
            DB::table('transfer_profiles')->insert([
                'name' => $name,
                'base_url' => 'http://10.99.0.1:3005',
                'segment' => $segment,
                'from_account' => $account,
                'dual_control' => $dual,
                'active' => false,
                'is_default' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_settings');
        Schema::dropIfExists('transfer_profiles');
    }
};
