<?php

namespace App\Models;

use App\Domain\Approvals\ChangeKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued store change awaiting admin review (MR9).
 *
 * `payload` is what the merchant asked for, `snapshot` is what those same
 * fields read when they asked — see the migration for why both exist.
 */
class MerchantChangeRequest extends Model
{
    public const string PENDING = 'pending';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    public const string SUPERSEDED = 'superseded';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ChangeKind::class,
            'payload' => 'array',
            'snapshot' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(MerchantUser::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
