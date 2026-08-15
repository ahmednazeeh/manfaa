<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A superadmin-curated store category (§1 decision 2026-08-15). Stores pick
 * from the ACTIVE rows only; merchants.category stores the slug string.
 * Deactivation is the only removal, and it is blocked while any ACTIVE
 * merchant still carries the slug.
 */
class StoreCategory extends Model
{
    /**
     * The icons an admin may choose from, as lucide icon names. This list
     * is the contract with every client: the storefront maps each name to a
     * statically imported component (lucide cannot be looked up dynamically
     * without shipping the whole set), so a name absent from the client's
     * map degrades to a neutral tag rather than breaking the rail.
     *
     * Adding a name here is therefore only half the change — the client map
     * in apps/web/components/app/category-rail.tsx must gain it too, and
     * StoreCategoryIconsTest pins the two lists together.
     *
     * @var list<string>
     */
    public const array ICONS = [
        'shopping-cart', 'shopping-bag', 'store', 'package',
        'utensils-crossed', 'coffee', 'croissant', 'cake-slice', 'fish',
        'shirt', 'gem', 'watch', 'glasses',
        'smartphone', 'laptop', 'camera', 'headphones',
        'pill', 'heart-pulse', 'stethoscope', 'dumbbell',
        'flower-2', 'scissors', 'sparkles',
        'baby', 'paw-print', 'gift', 'book-open', 'gamepad-2',
        'sofa', 'lamp-desk', 'hammer', 'wrench', 'paintbrush',
        'fuel', 'car', 'bike', 'ship', 'plane',
        'building-2', 'briefcase', 'graduation-cap', 'tag',
    ];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'active' => 'boolean',
        ];
    }
}
