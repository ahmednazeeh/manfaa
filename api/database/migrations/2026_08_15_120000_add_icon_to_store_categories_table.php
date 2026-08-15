<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-category iconography for the storefront rail. The value is a
     * lucide icon NAME from StoreCategory::ICONS, not an uploaded image:
     * the rail draws a monochrome glyph inside a themed tile, so a raster
     * would have to be supplied twice (light and dark) and would still sit
     * wrong at 24px. A name renders identically in both themes, needs no
     * storage, and an unknown one degrades to a neutral tag rather than a
     * broken image.
     *
     * The backfill is the map the web app carried hardcoded until now, so
     * the rail looks the same the moment this lands and admin simply gains
     * the ability to change it.
     */
    public function up(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->string('icon', 40)->nullable()->after('name_dv');
        });

        foreach (self::BACKFILL as $slug => $icon) {
            DB::table('store_categories')
                ->where('slug', $slug)
                ->whereNull('icon')
                ->update(['icon' => $icon]);
        }
    }

    public function down(): void
    {
        Schema::table('store_categories', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }

    /**
     * Slug => icon, exactly as apps/web carried it. Covers more slugs than
     * the seed on purpose — a platform that had already added one of these
     * by hand keeps its icon too.
     *
     * @var array<string, string>
     */
    private const array BACKFILL = [
        'bakery' => 'croissant',
        'beauty' => 'flower-2',
        'books' => 'book-open',
        'cafe' => 'coffee',
        'electronics' => 'smartphone',
        'fashion' => 'shirt',
        'fuel' => 'fuel',
        'furniture' => 'sofa',
        'grocery' => 'shopping-cart',
        'hardware' => 'hammer',
        'health' => 'heart-pulse',
        'home' => 'sofa',
        'jewellery' => 'gem',
        'jewelry' => 'gem',
        'kids' => 'baby',
        'other' => 'package',
        'pets' => 'paw-print',
        'pharmacy' => 'pill',
        'restaurant' => 'utensils-crossed',
        'services' => 'wrench',
        'sports' => 'dumbbell',
        'travel' => 'plane',
    ];
};
