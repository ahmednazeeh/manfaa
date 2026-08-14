<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merchant-editable settings columns (merchant settings module). Only
     * the columns the settings surface needs and the base table lacks:
     *
     *  - contact_email / contact_phone — merchant-facing contact details,
     *    editable by the owner. Nullable: onboarding is admin-created and
     *    these arrive later.
     *  - bank_account_name — completes the bank identity next to the
     *    existing bank_name / bank_account. Used to match INBOUND
     *    settlement payments (merchant -> platform) and for future wallet
     *    withdrawals; Manfaa never pays merchants.
     *
     * category / is_online / eligibility_basis / settlement_method /
     * validation_window_days / min_eligible_laari already exist.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'contact_email')) {
                $table->string('contact_email')->nullable();
            }

            if (! Schema::hasColumn('merchants', 'contact_phone')) {
                $table->string('contact_phone', 32)->nullable();
            }

            if (! Schema::hasColumn('merchants', 'bank_account_name')) {
                $table->string('bank_account_name')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'contact_phone', 'bank_account_name']);
        });
    }
};
