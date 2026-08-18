<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MerchantKybDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business-verification document (PLAN-marketplace.md §9).
 *
 * These are identity papers. They live on the PRIVATE disk, they are served
 * only through an authenticated route, and the path is never published to
 * any client — see MarketplaceKybController.
 */
class MerchantKybDocument extends Model
{
    /** @use HasFactory<MerchantKybDocumentFactory> */
    use HasFactory;

    protected $guarded = [];

    /** The papers a Maldivian business is asked for. */
    public const array KINDS = [
        'business_registration',
        'owner_id',
        'bank_letter',
        'tin_certificate',
    ];

    /** The disk. Private, always — never `public`. */
    public const string DISK = 'local';

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
