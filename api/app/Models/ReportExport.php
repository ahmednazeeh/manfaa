<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per superadmin .xlsx export (never per preview). Append-only in
 * spirit: the table has no updated_at, and nothing in the product edits a
 * row once written.
 */
class ReportExport extends Model
{
    public const null UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'merchant_id' => 'integer',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'row_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
