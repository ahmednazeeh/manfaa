<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchant self-serve credential issuance (PLAN §13b task #21). Two
     * things change on api_credentials:
     *
     *  - `label` — the integration partner NAME as the merchant typed it.
     *    Admin-issued credentials point at a curated `pos_vendors` row; a
     *    shopkeeper does not know our vendor registry (and must not be able
     *    to write to it), so the self-serve path stores free text instead
     *    and the panels render `pos_vendor.name ?? label`.
     *
     *  - `issued_by_merchant_user` / `revoked_by_merchant_user` — the audit
     *    actor for the self-serve path, deliberately SEPARATE columns from
     *    `issued_by` / `revoked_by`, which are foreign keys into
     *    `admin_users`. The two populations are disjoint tables (PLAN §9.1),
     *    so one polymorphic id column would lose the distinction that
     *    matters most here: whether Manfaa or the merchant themselves minted
     *    a token that can write cashback.
     *
     * The check constraints keep the pairs mutually exclusive — a row is
     * issued by exactly one actor, and revoked by at most one.
     */
    public function up(): void
    {
        Schema::table('api_credentials', function (Blueprint $table) {
            $table->string('label', 80)->nullable();
            $table->foreignId('issued_by_merchant_user')->nullable()->constrained('merchant_users');
            $table->foreignId('revoked_by_merchant_user')->nullable()->constrained('merchant_users');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE api_credentials
            ADD CONSTRAINT api_credentials_single_issuer_check
            CHECK (num_nonnulls(issued_by, issued_by_merchant_user) <= 1)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE api_credentials
            ADD CONSTRAINT api_credentials_single_revoker_check
            CHECK (num_nonnulls(revoked_by, revoked_by_merchant_user) <= 1)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE api_credentials DROP CONSTRAINT IF EXISTS api_credentials_single_revoker_check');
        DB::statement('ALTER TABLE api_credentials DROP CONSTRAINT IF EXISTS api_credentials_single_issuer_check');

        Schema::table('api_credentials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by_merchant_user');
            $table->dropConstrainedForeignId('issued_by_merchant_user');
            $table->dropColumn('label');
        });
    }
};
