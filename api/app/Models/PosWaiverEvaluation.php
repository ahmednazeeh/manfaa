<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosWaiverEvaluation extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'volume_laari' => 'integer',
            'cashback_laari' => 'integer',
            'min_rate_bp' => 'integer',
            'overdue_laari' => 'integer',
            'qualified' => 'boolean',
            'evaluated_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
