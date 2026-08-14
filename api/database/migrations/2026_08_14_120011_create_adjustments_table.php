<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Post-confirmation corrections — history is never edited.
        Schema::create('adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->index()->constrained();
            $table->bigInteger('amount_laari');
            $table->char('currency', 3)->default('MVR');
            $table->string('reason_code');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustments');
    }
};
