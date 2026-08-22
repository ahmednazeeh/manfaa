<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PUBLIC CLIENTS — "Connect with Manfaa" for software that cannot keep a
 * secret (owner decision 2026-08-22, the WooCommerce plugin).
 *
 * A confidential platform (IsleBooks) has one server, a secret, and a
 * fixed list of callbacks. A plugin is the opposite: one codebase on
 * thousands of stores, each with its own callback, none able to hide a
 * secret in PHP a shop owner can read. So a public client has NO secret —
 * PKCE is its only proof, which is why PKCE was mandatory from day one —
 * and NO registered callback: the callback arrives with the request, the
 * shopkeeper sees its host on the consent screen, and their approval is
 * the registration.
 *
 * `connected_from` remembers which store a grant came from, so the panel
 * can say "Connected from shop.example.mv" and a merchant with two stores
 * keeps two grants instead of the second replacing the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_vendors', function (Blueprint $table): void {
            $table->boolean('public_client')->default(false)->after('connect_enabled');
        });

        Schema::table('api_credentials', function (Blueprint $table): void {
            // The callback's origin (`https://shop.example.mv`), set at
            // exchange. Null for everything that is not a public-client grant.
            $table->string('connected_from')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('api_credentials', function (Blueprint $table): void {
            $table->dropColumn('connected_from');
        });

        Schema::table('pos_vendors', function (Blueprint $table): void {
            $table->dropColumn('public_client');
        });
    }
};
