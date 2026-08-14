<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §9.2 idempotency store: one row per (merchant, key). The unique
        // index is the concurrency authority — two racing writes with the
        // same key resolve to one winner; the loser replays the stored
        // response. response_status/response_body stay NULL while the first
        // request is still in flight.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->string('key');
            $table->string('request_hash', 64);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['merchant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
