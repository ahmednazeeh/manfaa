<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->index()->constrained('payout_batches');
            $table->foreignId('customer_id')->index()->constrained();
            $table->bigInteger('amount_laari');
            $table->char('currency', 3)->default('MVR');
            // Snapshot of the customer's payout account at batch time.
            $table->string('bank')->nullable();
            $table->string('account')->nullable();
            $table->string('state')->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE payout_items ADD CONSTRAINT payout_items_state_check CHECK (state IN ('pending', 'sent', 'paid', 'failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_items');
    }
};
