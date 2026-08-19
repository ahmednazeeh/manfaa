<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TWO category lists, kept apart, and a product names one of each
 * (owner decision 2026-08-19).
 *
 * A migration ago I merged them, which was wrong. They answer different
 * questions and belong to different people:
 *
 *   - MARKETPLACE categories are the shopper's vocabulary. Global, shared by
 *     every store, and the only thing that can ever make "rice from any
 *     shop" work. A per-merchant list cannot do that job, because two shops
 *     would spell the same aisle differently and browse would splinter.
 *   - CASHBACK categories are the merchant's own pricing. Per merchant, with
 *     exclusions and overrides, and nobody outside that shop should see or
 *     depend on them.
 *
 * So a product carries both, and only one is required. The marketplace
 * category says where it sits on the shelf; the cashback category is
 * OPTIONAL and, left unset, drops the product into the default
 * "everything else" bucket at the standing rate — exactly what an unfiled
 * product does in-store.
 *
 * Both columns are named for what they are. `category_id` was ambiguous
 * enough to cause this in the first place.
 */
return new class extends Migration
{
    /** The shopper-facing aisles, restored. */
    private const AISLES = [
        ['rice-grains', 'Rice & Grains', 'ހަނޑޫ އަދި ގޮވާން', 'wheat'],
        ['cooking-oil', 'Cooking Oil', 'ކެއްކުމުގެ ތެޔޮ', 'droplet'],
        ['beverages', 'Beverages', 'ބުއިންތައް', 'cup-soda'],
        ['snacks', 'Snacks', 'ސްނެކްސް', 'cookie'],
        ['dairy-eggs', 'Dairy & Eggs', 'ކިރު އަދި ބިސް', 'milk'],
        ['fruits-vegetables', 'Fruits & Vegetables', 'މޭވާ އަދި ތަރުކާރީ', 'apple'],
        ['personal-care', 'Personal Care', 'ޒާތީ ސާފުތާހިރުކަން', 'sparkles'],
        ['home-care', 'Home Care', 'ގޭތެރެ ސާފުކުރުން', 'spray-can'],
        ['baby-care', 'Baby Care', 'ކުޑަކުދިންގެ ސާމާނު', 'baby'],
        ['frozen-foods', 'Frozen Foods', 'ގަނޑުކުރި ކާނާ', 'snowflake'],
        ['baking-essentials', 'Baking Essentials', 'ބޭކިންގ ސާމާނު', 'cake-slice'],
        ['household-other', 'Others', 'އެހެނިހެން', 'package'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('marketplace_categories')) {
            Schema::create('marketplace_categories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('parent_id')->nullable()
                    ->constrained('marketplace_categories')->nullOnDelete();
                $table->string('slug')->unique();
                $table->string('name_en');
                $table->string('name_dv')->nullable();
                $table->string('icon')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        $now = now();

        foreach (self::AISLES as $sort => [$slug, $en, $dv, $icon]) {
            // Insert-only: never clobber a name a superadmin has edited.
            if (DB::table('marketplace_categories')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('marketplace_categories')->insert([
                'slug' => $slug,
                'name_en' => $en,
                'name_dv' => $dv,
                'icon' => $icon,
                'sort' => $sort,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('products', function (Blueprint $table): void {
            // The pricing one, named for what it does. It already points at
            // merchant_product_categories; only the name was ambiguous.
            $table->renameColumn('category_id', 'cashback_category_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            // Where it sits on the shelf. Nullable at the database because a
            // draft product may not be filed yet; the merchant API requires
            // it, which is where that judgement belongs.
            $table->foreignId('marketplace_category_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('marketplace_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('marketplace_category_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->renameColumn('cashback_category_id', 'category_id');
        });
    }
};
