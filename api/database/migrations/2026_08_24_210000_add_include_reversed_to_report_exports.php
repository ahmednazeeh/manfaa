<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversed rows are OUT of every report by default (owner, 2026-08-24), and
 * an admin who deliberately asks for them back gets that choice recorded.
 *
 * It belongs on the audit row rather than being inferred later, because the
 * same period exported twice — once clean, once with reversals — produces
 * two different files, and a year from now "which one did they send the
 * auditor" is only answerable if the row says so.
 *
 * The column is NOT NULL with a false default so every reader gets a plain
 * boolean instead of a three-way branch forever. The rows already written
 * were all built before the flag existed, back when reversals were simply
 * INCLUDED, so that default states the opposite of the truth for them —
 * 2026_08_24_213000 corrects those rows immediately afterwards rather than
 * leaving the audit table answering its one question backwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->boolean('include_reversed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('report_exports', function (Blueprint $table) {
            $table->dropColumn('include_reversed');
        });
    }
};
