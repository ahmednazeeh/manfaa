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
        return [
            'auto_transfer_enabled' => 'boolean',
            'auto_max_laari' => 'integer',
            'auto_verify_enabled' => 'boolean',
            'verify_window_minutes' => 'integer',
            'verify_min_score' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TransferProfile::class, 'profile_id');
    }

    /**
     * The profile history is READ through. Deliberately its own setting:
     * money arrives in one account and leaves from another, and reading is a
     * different risk from paying.
     */

    /** There is exactly one, always. */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'auto_transfer_enabled' => false,
            'auto_max_laari' => 500000,
        ]);
    }
}
