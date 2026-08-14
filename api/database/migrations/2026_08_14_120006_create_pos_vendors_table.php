<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact')->nullable();
            $table->string('integration_status')->default('pending');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_vendors');
    }
};
