<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only rate history: sale-time resolution reads this table.
        Schema::create('merchant_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained();
            $table->integer('rate_bp');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_rates');
    }
};
