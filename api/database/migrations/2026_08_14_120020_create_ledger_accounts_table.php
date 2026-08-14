<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type');
            $table->string('scope')->default('global');
            $table->bigInteger('owner_id')->nullable();
            $table->timestampsTz();

            $table->index(['scope', 'owner_id']);
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (type IN ('asset', 'liability', 'income', 'expense'))");
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_scope_check CHECK (scope IN ('global', 'merchant', 'customer'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
