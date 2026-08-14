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
