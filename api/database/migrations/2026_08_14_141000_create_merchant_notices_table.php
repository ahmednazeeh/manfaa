<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: every clock notice (§7) writes one of these so the
        // escalation ladder can be evidenced later. Rows are never updated.
        Schema::create('merchant_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->string('type');
            $table->string('channel')->default('log');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('sent_at');

            // The escalation dedupe query: latest notice of a type per merchant.
            $table->index(['merchant_id', 'type', 'sent_at']);
        });

        DB::statement("ALTER TABLE merchant_notices ADD CONSTRAINT merchant_notices_type_check CHECK (type IN ('reminder_day10', 'urgent_day13', 'due_day15', 'suspended', 'reinstated', 'write_off'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_notices');
    }
};
