<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one line went from, and to. */
class SuborderAmendmentLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty_before' => 'integer',
            'qty_after' => 'integer',
            'refund_laari' => 'integer',
        ];
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(SuborderAmendment::class, 'amendment_id');
    }
}
