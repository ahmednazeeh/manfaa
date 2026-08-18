<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SuborderAmendmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One reduction, with who made it and why (§2.7). */
class SuborderAmendment extends Model
{
    /** @use HasFactory<SuborderAmendmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['refund_laari' => 'integer'];
    }

    public function suborder(): BelongsTo
    {
        return $this->belongsTo(Suborder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SuborderAmendmentLine::class, 'amendment_id');
    }
}
