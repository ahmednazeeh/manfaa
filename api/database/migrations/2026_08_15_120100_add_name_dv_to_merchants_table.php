<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The store's own name in Dhivehi, collected at signup and editable in
     * the merchant profile. Nullable forever: it is the store's choice, and
     * every surface falls back to `name` when it is absent — a Dhivehi
     * visitor sees the Latin name rather than a blank.
     *
     * Deliberately NOT unique and NOT slugged: the slug stays derived from
     * the Latin name so URLs remain ASCII and stable, and two stores may
     * legitimately share a Thaana rendering of different Latin names.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->string('name_dv', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('name_dv');
        });
    }
};
