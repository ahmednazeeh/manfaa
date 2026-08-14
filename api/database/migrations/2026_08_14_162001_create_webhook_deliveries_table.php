<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §9.3 delivery ledger: ONE ROW PER DELIVERY (event × endpoint), not one
     * row per attempt. `attempt` counts tries so far and `last_error` /
     * `response_status` describe only the most recent one — the retry
     * schedule is fixed and short (7 attempts over ~35 hours), so a full
     * per-attempt audit trail buys nothing the application log does not
     * already hold, while a mutable counter keeps "has this event reached
     * this endpoint yet?" a single-row read.
     *
     * status:
     *  - pending    queued, no attempt made yet
     *  - delivered  a 2xx was received; delivered_at/response_status stamped
     *  - failed     last attempt failed; a retry is scheduled for
     *               next_attempt_at
     *  - exhausted  every attempt failed; parked for operations, never
     *               silently dropped (docs/openapi.yaml WebhookEvent)
     *
     * `payload` is the full signed envelope {id, type, created_at, data}.
     * jsonb round-trips with canonical (sorted) key order, so every attempt
     * re-serialises to byte-identical raw bytes — and therefore an identical
     * signature — as the spec promises.
     */
    public function up(): void
    {
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->jsonb('payload');
            $table->smallInteger('attempt')->default(0);
            $table->string('status')->default('pending');
            $table->smallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->index(['webhook_endpoint_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
        });

        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('pending', 'delivered', 'failed', 'exhausted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
