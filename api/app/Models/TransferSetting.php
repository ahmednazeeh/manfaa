<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The single row governing automatic transfers. */
class TransferSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['auto_transfer_enabled' => 'boolean', 'auto_max_laari' => 'integer'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TransferProfile::class, 'profile_id');
    }

    /** There is exactly one, always. */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'auto_transfer_enabled' => false,
            'auto_max_laari' => 500000,
        ]);
    }
}
