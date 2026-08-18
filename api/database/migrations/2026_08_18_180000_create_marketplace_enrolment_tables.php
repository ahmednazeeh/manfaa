<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP1 — marketplace enrolment (PLAN-marketplace.md §2.1, §9).
 *
 * Marketplace is OPTIONAL for a merchant and OFF for the platform until a
 * superadmin says otherwise. These two tables hold the opt-in and the
 * business verification behind it; nothing here makes a store visible to a
 * shopper, and no customer-facing surface exists yet.
 *
 * Separate from `merchants` on purpose. A merchant is a merchant whether or
 * not they ever sell online, and a nullable column per marketplace field on
 * the main row would spread an optional product across the table every part
 * of the platform reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_marketplace_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained()->cascadeOnDelete();

            // not_enrolled exists as a row only if a merchant started and
            // stopped; the ABSENCE of a row is the ordinary "never asked".
            $table->string('state')->default('pending_kyb');
            $table->string('business_type')->nullable();
            $table->string('fulfilment')->default('delivery');

            // The "30–60 min" chip. Nullable until the profile sheet is done.
            $table->unsignedSmallInteger('prep_time_min')->nullable();
            $table->unsignedSmallInteger('prep_time_max')->nullable();

            // NULL means "use the platform default" (marketplace_fee_bp), so
            // moving the default moves every store that has not been given
            // its own rate. Basis points, never a float (§10).
            $table->unsignedInteger('order_fee_bp')->nullable();

            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);

            $table->timestampTz('enrolled_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users');
            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();

            $table->index('state');
        });

        DB::statement("
            ALTER TABLE merchant_marketplace_profiles ADD CONSTRAINT marketplace_state_check
            CHECK (state IN ('not_enrolled', 'pending_kyb', 'active', 'rejected', 'suspended'))
        ");

        DB::statement("
            ALTER TABLE merchant_marketplace_profiles ADD CONSTRAINT marketplace_fulfilment_check
            CHECK (fulfilment IN ('delivery', 'pickup', 'both'))
        ");

        // A prep window that runs backwards is a data error, not a display
        // problem — refuse it at the column rather than in four UIs.
        DB::statement('
            ALTER TABLE merchant_marketplace_profiles ADD CONSTRAINT marketplace_prep_window_check
            CHECK (prep_time_min IS NULL OR prep_time_max IS NULL OR prep_time_max >= prep_time_min)
        ');

        Schema::create('merchant_kyb_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('kind');

            // Private disk. These are identity papers: they are never served
            // from a public URL and never guessable from a merchant id.
            $table->string('path');
            $table->string('original_name');
            $table->string('mime');
            $table->unsignedInteger('size');

            $table->string('state')->default('pending');
            $table->text('reject_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admin_users');
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();

            // One live document per kind per merchant — a replacement
            // supersedes rather than accumulates.
            $table->unique(['merchant_id', 'kind']);
        });

        DB::statement("
            ALTER TABLE merchant_kyb_documents ADD CONSTRAINT kyb_kind_check
            CHECK (kind IN ('business_registration', 'owner_id', 'bank_letter', 'tin_certificate'))
        ");

        DB::statement("
            ALTER TABLE merchant_kyb_documents ADD CONSTRAINT kyb_state_check
            CHECK (state IN ('pending', 'accepted', 'rejected'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_kyb_documents');
        Schema::dropIfExists('merchant_marketplace_profiles');
    }
};
