<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §9.3 outbound webhook endpoints. One row per URL a POS vendor
     * registered to receive events at. The signing secret is issued once at
     * registration, shown once in the 201 body, and stored ENCRYPTED
     * (Laravel `encrypted` cast) — unlike vendor tokens it cannot be stored
     * as a digest, because every delivery must recompute the HMAC with it.
     *
     * `events` is the jsonb list of subscribed event names; an endpoint only
     * ever receives events it subscribed to. Deliveries are additionally
     * scoped per merchant via the vendor's live api_credentials (see
     * WebhookDispatcher) — an endpoint never learns about merchants its
     * vendor holds no credential for.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_vendor_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->text('secret');
            $table->jsonb('events');
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['pos_vendor_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
