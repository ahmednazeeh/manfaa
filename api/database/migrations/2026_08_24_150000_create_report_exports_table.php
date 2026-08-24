<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for superadmin report exports (owner, 2026-08-24).
 *
 * Every .xlsx that leaves the platform carries merchant names, customer
 * codes and the whole money trace, so the question "who pulled the numbers,
 * for which period, and how many rows did they get" must be answerable a
 * year later. One append-only row per export — never per preview: a preview
 * shows fifty rows on a screen, an export puts a file on somebody's laptop.
 *
 * `created_at` only: an export is an event, and an event is not edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admin_users');
            $table->string('report');
            // Dates as the admin chose them, in the BUSINESS timezone — the
            // instants the query actually ran against are derived from these
            // and would read an unhelpful five hours off in a UTC column.
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('merchant_id')->nullable()->constrained();
            $table->integer('row_count');
            $table->timestampTz('created_at');

            $table->index(['report', 'created_at']);
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_report_check CHECK (report IN ('cashback', 'payouts', 'earnings'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
