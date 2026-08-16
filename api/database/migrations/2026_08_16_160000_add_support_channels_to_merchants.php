<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // The number a SHOPPER rings, which is rarely the number we ring.
            // contact_phone reaches whoever signed the store up — an owner, a
            // finance manager — and printing that on a public storefront
            // hands the public someone's personal mobile. Nullable: a store
            // with no separate support line simply shows its contact number,
            // which the panel offers as one tick rather than as retyping.
            $table->string('support_phone', 32)->nullable()->after('contact_phone');

            // The store's own website. Chiefly for online and both-channel
            // stores, where "where do I actually shop" has no map answer.
            $table->string('website_url', 255)->nullable()->after('support_phone');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['support_phone', 'website_url']);
        });
    }
};
