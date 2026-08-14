<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Key-value platform configuration. Typed access, defaults and validation
 * live in App\Domain\Platform\PlatformConfig — an absent key always means
 * "the hardcoded default", so behaviour is unchanged until an admin writes.
 */
#[Fillable(['key', 'value', 'updated_by'])]
class PlatformSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
