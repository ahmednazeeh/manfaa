<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook endpoints a MERCHANT owns (owner, 2026-08-22).
 *
 * Until now an endpoint belonged to a POS vendor: one platform, one server,
 * one URL, and the dispatcher found it through the vendor behind the
 * merchant's connect credential. That model has two holes for anything that
 * is not a platform:
 *
 *  1. A token the merchant issues in the panel carries no vendor, so no
 *     event can ever be routed to it.
 *  2. A vendor's endpoints receive EVERY merchant's events — fine for a
 *     platform that routes internally, a leak for a self-hosted shop.
 *
 * So an endpoint may now belong to a merchant instead. Exactly one of
 * `pos_vendor_id` / `merchant_id` is set (CHECK below). A merchant endpoint
 * may additionally be tied to the API credential that registered it, so
 * revoking that credential switches the endpoint off with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->foreignId('merchant_id')
                ->nullable()
                ->after('pos_vendor_id')
                ->constrained()
                ->cascadeOnDelete();

            // The credential that registered it over /v1, when one did. A
            // panel-registered endpoint has none and outlives any token.
            $table->foreignId('api_credential_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained()
                ->nullOnDelete();

            // What the merchant called it ("WooCommerce — shop.example.mv").
            $table->string('label', 80)->nullable()->after('url');

            $table->foreignId('created_by_merchant_user_id')
                ->nullable()
                ->after('active')
                ->constrained('merchant_users')
                ->nullOnDelete();

            $table->index(['merchant_id', 'active']);
        });

        DB::statement('ALTER TABLE webhook_endpoints ALTER COLUMN pos_vendor_id DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE webhook_endpoints
            ADD CONSTRAINT webhook_endpoints_one_owner_check
            CHECK ((pos_vendor_id IS NOT NULL)::int + (merchant_id IS NOT NULL)::int = 1)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE webhook_endpoints DROP CONSTRAINT IF EXISTS webhook_endpoints_one_owner_check');
        DB::statement('DELETE FROM webhook_endpoints WHERE pos_vendor_id IS NULL');
        DB::statement('ALTER TABLE webhook_endpoints ALTER COLUMN pos_vendor_id SET NOT NULL');

        Schema::table('webhook_endpoints', function (Blueprint $table): void {
            $table->dropIndex(['merchant_id', 'active']);
            $table->dropConstrainedForeignId('created_by_merchant_user_id');
            $table->dropColumn('label');
            $table->dropConstrainedForeignId('api_credential_id');
            $table->dropConstrainedForeignId('merchant_id');
        });
    }
};
