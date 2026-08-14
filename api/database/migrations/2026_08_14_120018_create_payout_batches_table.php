<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestampTz('cutoff_at');
            $table->string('state')->default('draft');
            $table->bigInteger('total_laari')->default(0);
            $table->char('currency', 3)->default('MVR');
            $table->integer('customer_count')->default(0);
            // Dual approval: two distinct admins, enforced in the domain layer.
            $table->foreignId('approved_by_first')->nullable()->constrained('admin_users');
            $table->foreignId('approved_by_second')->nullable()->constrained('admin_users');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE payout_batches ADD CONSTRAINT payout_batches_state_check CHECK (state IN ('draft', 'approved', 'processing', 'sent', 'completed', 'partially_failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batches');
    }
};
