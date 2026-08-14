<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->char('customer_code', 6)->unique();
            $table->string('phone')->unique();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('active');
            $table->string('payout_bank')->nullable();
            $table->string('payout_account')->nullable();
            $table->string('payout_account_name')->nullable();
            $table->string('kyc_status')->default('none');
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('active', 'suspended', 'closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
