<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Line-item pricing splits (Task #25). Each row is an immutable
     * snapshot of one priced line of a lined credit: the per-line §4
     * ceiling result, the terms it earned under (effective_rate_bp /
     * fee_bp), and WHY it priced that way (priced_by). The transaction
     * row's totals equal the SUM of these stored integers — totals are
     * never recomputed from aggregates, and reversals reverse the stored
     * transaction totals (line rows are never touched).
     *
     * product_category_id NULL = the default "everything else" bucket
     * (standing rate); category_slug / category_name_en are creation-time
     * snapshots so renames and deactivations never rewrite history.
     *
     * Append-only: created_at only, no updated_at; the model write-guard
     * refuses updates and deletes like ledger entries.
     */
    public function up(): void
    {
        Schema::create('transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->index()->constrained();
            $table->foreignId('product_category_id')->nullable()->constrained('merchant_product_categories');
            $table->string('category_slug', 80)->nullable();
            $table->string('category_name_en', 120)->nullable();
            $table->bigInteger('amount_laari');
            $table->char('currency', 3)->default('MVR');
            $table->integer('effective_rate_bp');
            $table->integer('fee_bp');
            $table->bigInteger('cashback_laari');
            $table->bigInteger('fee_laari');
            $table->string('priced_by', 16);
            $table->integer('sort');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_priced_by_check CHECK (priced_by IN ('excluded', 'category', 'standing', 'promotion'))");
        DB::statement('ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_amount_check CHECK (amount_laari >= 1)');
        // The default bucket has no category reference and no snapshot;
        // a category line always carries both.
        DB::statement('ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_default_bucket_check CHECK ((product_category_id IS NULL) = (category_slug IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_lines');
    }
};
