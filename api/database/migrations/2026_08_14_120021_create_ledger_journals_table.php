<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_journals', function (Blueprint $table) {
            $table->id();
            $table->string('reference_type');
            $table->bigInteger('reference_id');
            $table->string('description');
            $table->timestampTz('posted_at');
            $table->timestampsTz();

            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_journals');
    }
};
