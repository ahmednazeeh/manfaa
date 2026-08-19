<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A marketplace product belongs to the merchant's OWN cashback category
 * (owner report 2026-08-19).
 *
 * It pointed at `marketplace_categories` instead — a separate twelve-row
 * list I seeded for browse aisles, with no connection to the categories a
 * merchant actually curates. The consequence was not cosmetic:
 *
 *   - a merchant's category OVERRIDE ("Fruits and Vegetables → 10%") was
 *     silently ignored on every online order, and
 *   - an EXCLUDED category still earned cashback, because nothing on the
 *     marketplace path ever read the exclusion.
 *
 * Both are money, decided by a rule the merchant set and we did not honour.
 *
 * Two taxonomies for one idea was the mistake. A merchant already maintains
 * exactly one list of what they sell and what it earns; the marketplace has
 * no business inventing a second. Browse within a store now groups by that
 * same list, which is also what a shopper expects — a shop's own aisles.
 *
 * Cross-store browse (\"rice from any shop\") wants a shared vocabulary and
 * will need a mapping when search is built. That is a real problem, but a
 * later one, and keeping a wrong wiring alive for it would only mislead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            // The merchant's own cashback category. Null is the default
            // "everything else" bucket, exactly as it is in-store.
            $table->foreignId('category_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('merchant_product_categories')
                ->nullOnDelete();
        });

        // Nothing referenced the aisles — no product exists yet — so this
        // drops a table rather than data. Left standing it would be a
        // second answer to a question that now has one.
        Schema::dropIfExists('marketplace_categories');
    }

    public function down(): void
    {
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
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('merchant_id')
                ->constrained('marketplace_categories')->nullOnDelete();
        });

        DB::table('marketplace_categories')->insert([
            ['slug' => 'others', 'name_en' => 'Others', 'sort' => 99, 'active' => true,
                'created_at' => now(), 'updated_at' => now()],
        ]);
    }
};
