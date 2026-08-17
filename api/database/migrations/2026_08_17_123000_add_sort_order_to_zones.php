<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Zones carry an admin-editable display order (owner request 2026-08-17):
// the admin arranges the list, and the app's island picker shows islands in
// exactly that order. Backfilled to id order — "added order" is the default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0);
        });

        // Added order, exactly: the id sequence is the order they were made.
        DB::statement('UPDATE zones SET sort_order = id');
    }

    public function down(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
