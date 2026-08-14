<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_no');
            $table->string('account_name');
            $table->char('currency', 3)->default('MVR');
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        // Exactly one active primary: a partial unique index over the rows
        // where (active AND is_primary) — every such row carries is_primary =
        // true, so a second one collides.
        DB::statement(
            'CREATE UNIQUE INDEX platform_bank_accounts_one_active_primary'
            .' ON platform_bank_accounts (is_primary) WHERE active AND is_primary'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_bank_accounts');
    }
};
