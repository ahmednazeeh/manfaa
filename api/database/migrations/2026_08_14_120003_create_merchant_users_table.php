<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->index()->constrained();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('staff');
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE merchant_users ADD CONSTRAINT merchant_users_role_check CHECK (role IN ('owner', 'staff'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_users');
    }
};
