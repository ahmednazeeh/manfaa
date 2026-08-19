<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The BML upstream, which was never seeded (owner report 2026-08-19: the
 * watched-account picker offers only MIB profiles, so our BML account could
 * not be watched at all).
 *
 * Only the four `/faisanet*` profiles existed. `/bml` is a different upstream
 * entirely — it ignores `from_account`, and its history call needs a profile
 * NAME on the query string (`?account=…&profile=CLEVIDEN`) rather than an
 * account number.
 *
 * That name gets its own column instead of reusing `name`. They looked alike
 * enough to conflate, and conflating them means renaming a profile in the
 * panel silently breaks its API calls — a display label and a wire identifier
 * must not be the same string.
 *
 * Seeded INACTIVE with the upstream name blank: it is theirs to fill in, and
 * guessing a wire identifier would produce calls that fail in a way nobody
 * could read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_profiles', function (Blueprint $table): void {
            $table->string('upstream_profile')->nullable();
        });

        if (DB::table('transfer_profiles')->where('segment', 'like', '%bml%')->exists()) {
            return;
        }

        DB::table('transfer_profiles')->insert([
            'name' => 'BML',
            'base_url' => 'http://10.99.0.1:3005',
            'segment' => '/bml',
            // Ignored by design on /bml/transfer. Left null rather than
            // filled with something that looks meaningful and is not.
            'from_account' => null,
            'upstream_profile' => null,
            'bank' => 'bml',
            'dual_control' => false,
            'active' => false,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // While we are here: the MIB profiles all debit MIB accounts, which
        // is what lets a payout to an MIB payee stay inside one bank.
        DB::table('transfer_profiles')
            ->where('segment', 'like', '%faisanet%')
            ->update(['bank' => 'mib']);
    }

    public function down(): void
    {
        DB::table('transfer_profiles')->where('segment', '/bml')->delete();

        Schema::table('transfer_profiles', fn (Blueprint $t) => $t->dropColumn('upstream_profile'));
    }
};
