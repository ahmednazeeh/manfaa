<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MarketplaceCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A curated aisle. Merchants pick from these; they never invent them. */
class MarketplaceCategory extends Model
{
    /** @use HasFactory<MarketplaceCategoryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort' => 'integer'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /** The name in the reader's script, falling back to English. */
    public function label(bool $dhivehi): string
    {
        return $dhivehi && ($this->name_dv ?? '') !== ''
            ? (string) $this->name_dv
            : (string) $this->name_en;
    }
}
