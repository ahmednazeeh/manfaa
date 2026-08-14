<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: every state change on a transaction writes one of these.
        Schema::create('transaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->index()->constrained();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('actor_type');
            $table->bigInteger('actor_id')->nullable();
            $table->string('reason_code')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_events');
    }
};
