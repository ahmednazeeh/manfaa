<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP10 — Merchant Settlements: what the PLATFORM owes a shop
 * (owner requirement, PLAN-marketplace.md §5.5).
 *
 * The name is worth pausing on, because this platform already has a thing
 * called a settlement and it points the OTHER WAY. A `settlements` row is a
 * merchant paying US the cashback they granted at their till. These tables
 * are us paying THEM for marketplace orders they fulfilled, after deducting
 * the cashback and our fee.
 *
 * Two ledgers, deliberately not netted (§5.4): a merchant reading one
 * combined figure cannot check either half, and neither can we.
 *
 * Shaped after `payout_batches` / `payout_items` on purpose — the customer
 * payout machinery works, an admin already knows how to drive it, and the
 * same xlsx goes to the same bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('reference')->unique();
            $table->timestampTz('cutoff_at');
            $table->string('state')->default('draft');

            $table->unsignedBigInteger('total_laari')->default(0);
            $table->unsignedInteger('merchant_count')->default(0);
            // Money left behind, and why — a merchant over the threshold but
            // with no bank details on file. Surfaced on the batch so it is
            // visible rather than mysteriously absent.
            $table->unsignedBigInteger('excluded_laari')->default(0);
            $table->unsignedInteger('excluded_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('admin_users');
            $table->foreignId('approved_by')->nullable()->constrained('admin_users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('exported_at')->nullable();
            $table->timestamps();

            $table->index('state');
        });

        DB::statement("
            ALTER TABLE merchant_payout_batches ADD CONSTRAINT merchant_batch_state_check
            CHECK (state IN ('draft', 'approved', 'processing', 'completed', 'cancelled'))
        ");

        Schema::create('merchant_payout_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('merchant_payout_batches')->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained();

            $table->unsignedBigInteger('amount_laari');
            $table->string('currency', 3)->default('MVR');

            // Snapshotted at build time. A merchant editing their bank
            // details afterwards must not redirect a transfer the bank
            // already has, and the sheet must re-export word for word.
            $table->string('merchant_name');
            $table->string('bank')->nullable();
            $table->string('account');
            $table->string('account_name')->nullable();

            /*
             * THE IDEMPOTENCY KEY (owner requirement 2026-08-19). Sent to
             * the bank as `internal_ref`; unique here so the same item can
             * never mint a second one. A stable business key we can
             * regenerate is what makes a retry safe rather than a second
             * payment.
             */
            $table->string('internal_ref')->unique();

            $table->string('state')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('trx_id')->nullable();
            // NOT a transaction reference — a dual-control queue record id.
            $table->string('approval_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestampTz('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'merchant_id']);
            $table->index('state');
        });

        DB::statement("
            ALTER TABLE merchant_payout_items ADD CONSTRAINT merchant_payout_item_state_check
            CHECK (state IN ('pending', 'processing', 'pending_approval', 'sent', 'failed', 'cancelled'))
        ");

        // Which suborders a payout item covers. The link is what stops one
        // being paid twice: an unlinked delivered suborder is unpaid, and a
        // linked one is claimed.
        Schema::table('suborders', function (Blueprint $table): void {
            $table->foreignId('payout_item_id')->nullable()->after('branch_id')
                ->constrained('merchant_payout_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suborders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_item_id');
        });

        Schema::dropIfExists('merchant_payout_items');
        Schema::dropIfExists('merchant_payout_batches');
    }
};
