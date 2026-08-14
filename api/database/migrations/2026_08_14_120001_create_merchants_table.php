<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->string('business_reg_no')->nullable();
            $table->string('tin')->nullable();
            $table->string('settlement_method')->default('bank');
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->integer('validation_window_days')->default(3);
            $table->bigInteger('min_eligible_laari')->default(5000);
            $table->text('eligibility_basis')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchants_status_check CHECK (status IN ('active', 'suspended', 'closed'))");
        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchants_settlement_method_check CHECK (settlement_method IN ('bank', 'wallet'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
