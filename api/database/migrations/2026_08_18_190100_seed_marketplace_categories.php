<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform's curated product tree, from `Market View Tablet.png` — the
 * left rail a shopper browses by.
 *
 * Curated rather than merchant-invented on purpose: if every shop names its
 * own categories, "Cooking Oil", "Oils" and "Oil" become three aisles and
 * search becomes a synonym problem before the second merchant signs.
 *
 * Dhivehi names are transliterations of the English retail terms, which is
 * what these are actually called in Maldivian shops; a superadmin can edit
 * any of them from the categories screen.
 */
return new class extends Migration
{
    private const array SEED = [
        ['rice-grains', 'Rice & Grains', 'ހަނޑޫ އަދި ގޮވާން', 'wheat'],
        ['cooking-oil', 'Cooking Oil', 'ކައްކާ ތެޔޮ', 'droplet'],
        ['beverages', 'Beverages', 'ބުއިންތައް', 'cup-soda'],
        ['snacks', 'Snacks', 'ސްނެކްސް', 'cookie'],
        ['dairy-eggs', 'Dairy & Eggs', 'ކިރު އަދި ބިސް', 'milk'],
        ['fruits-vegetables', 'Fruits & Vegetables', 'މޭވާ އަދި ތަރުކާރީ', 'apple'],
        ['personal-care', 'Personal Care', 'ޒާތީ ބޭނުންތައް', 'sparkles'],
        ['home-care', 'Home Care', 'ގޭގެ ސާމާނު', 'house'],
        ['baby-care', 'Baby Care', 'ކުޑަކުދިންގެ ސާމާނު', 'baby'],
        ['frozen-foods', 'Frozen Foods', 'ގަނޑުކުރި ކާނާ', 'snowflake'],
        ['baking-essentials', 'Baking Essentials', 'ބޭކިންގ ސާމާނު', 'cake-slice'],
        ['household-other', 'Others', 'އެހެނިހެން', 'package'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SEED as $sort => [$slug, $en, $dv, $icon]) {
            // Insert-only: never clobber a name a superadmin has edited.
            if (DB::table('marketplace_categories')->where('slug', $slug)->exists()) {
                continue;
            }

            DB::table('marketplace_categories')->insert([
                'slug' => $slug,
                'name_en' => $en,
                'name_dv' => $dv,
                'icon' => $icon,
                'sort' => $sort * 10,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('marketplace_categories')
            ->whereIn('slug', array_column(self::SEED, 0))
            ->delete();
    }
};
