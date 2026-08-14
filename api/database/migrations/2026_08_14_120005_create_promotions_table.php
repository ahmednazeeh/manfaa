<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->index()->constrained();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches');
            $table->integer('rate_bp');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->bigInteger('min_purchase_laari')->nullable();
            $table->bigInteger('max_cashback_per_customer_laari')->nullable();
            $table->string('status')->default('draft');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_status_check CHECK (status IN ('draft', 'published', 'ended', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
