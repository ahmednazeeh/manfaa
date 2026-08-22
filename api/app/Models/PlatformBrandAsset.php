<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One uploaded brand mark. Absence of a row means the packaged default is
 * in use — see App\Domain\Platform\BrandAsset.
 */
class PlatformBrandAsset extends Model
{
    protected $fillable = ['slot', 'path', 'original_name', 'updated_by'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }
}
