<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MP2 — the catalogue (PLAN-marketplace.md §2.2, §2.3).
 *
 * The split that shapes all three tables: a product is DESCRIBED once by the
 * merchant and STOCKED per branch. Stock is physical — it sits on a shelf in
 * one building — so a chain cannot honestly publish one number across two
 * shops, and a merchant should still not have to type the product twice.
 *
 * Every merchant on the platform today has one branch, so none of them will
 * ever notice the distinction. It exists for the first chain that signs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The platform's curated tree. Merchants pick from it; they do not
        // invent it, or search becomes a synonym problem on day one.
        Schema::create('marketplace_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('marketplace_categories')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name_en');
            $table->string('name_dv')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'sort']);
        });

        // The DEFINITION. What the thing is — never what it costs or whether
        // it is in stock, because those differ per shop.
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('marketplace_categories')->nullOnDelete();

            $table->string('name');
            $table->string('name_dv')->nullable();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();

            // Null falls back to the store's standing cashback rate, so a
            // merchant who never touches this still pays what they agreed.
            $table->unsignedInteger('cashback_rate_bp')->nullable();

            // The "No substitutions" chip in Order Details.png.
            $table->boolean('allow_substitutions')->default(true);

            // Archived rather than deleted: an order from last month still
            // names this product, and history must not develop holes.
            $table->boolean('archived')->default(false);

            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['merchant_id', 'archived']);
            $table->unique(['merchant_id', 'sku']);
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort']);
        });

        // The LISTING. What this shop charges, and what it actually has.
        Schema::create('branch_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('price_laari');
            // The struck-through "MVR 85.00". Must exceed the live price to
            // mean anything, which the app enforces rather than the column.
            $table->unsignedBigInteger('compare_at_laari')->nullable();

            // NULL = this shop does not count stock for this line. Zero is a
            // different statement entirely: counted, and there is none.
            $table->integer('stock_qty')->nullable();
            $table->unsignedInteger('low_stock_at')->nullable();

            $table->string('state')->default('draft');
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['branch_id', 'state']);
        });

        DB::statement("
            ALTER TABLE branch_products ADD CONSTRAINT branch_product_state_check
            CHECK (state IN ('draft', 'active', 'out_of_stock', 'archived'))
        ");

        // Products join the MR9 review queue rather than growing a second
        // one. `product_id` is to a product change what `branch_id` is to a
        // branch change.
        Schema::table('merchant_change_requests', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('branch_id')
                ->constrained()->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE merchant_change_requests DROP CONSTRAINT merchant_change_requests_kind_check');
        DB::statement("
            ALTER TABLE merchant_change_requests ADD CONSTRAINT merchant_change_requests_kind_check
            CHECK (kind IN ('profile', 'branch_create', 'branch_update', 'branch_delete', 'product_update'))
        ");

        // A product change carries a product; nothing else does.
        DB::statement("
            ALTER TABLE merchant_change_requests ADD CONSTRAINT change_request_product_check
            CHECK ((kind = 'product_update' AND product_id IS NOT NULL)
                OR (kind <> 'product_update' AND product_id IS NULL))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE merchant_change_requests DROP CONSTRAINT change_request_product_check');
        DB::statement('ALTER TABLE merchant_change_requests DROP CONSTRAINT merchant_change_requests_kind_check');
        DB::statement("
            ALTER TABLE merchant_change_requests ADD CONSTRAINT merchant_change_requests_kind_check
            CHECK (kind IN ('profile', 'branch_create', 'branch_update', 'branch_delete'))
        ");

        Schema::table('merchant_change_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::dropIfExists('branch_products');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
        Schema::dropIfExists('marketplace_categories');
    }
};
