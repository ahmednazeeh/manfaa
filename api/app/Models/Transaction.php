<?php

namespace App\Models;

use App\Domain\Cashback\TransactionState;
use App\Domain\Platform\FeeRelief;
use App\Domain\Tax\FeeTax;
use App\Domain\Tax\FeeTreatment;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant_id' => 'integer',
            'branch_id' => 'integer',
            'customer_id' => 'integer',
            'promotion_id' => 'integer',
            'eligible_laari' => 'integer',
            'sale_laari' => 'integer',
            'rate_bp' => 'integer',
            'fee_bp' => 'integer',
            'cashback_laari' => 'integer',
            'fee_laari' => 'integer',
            'fee_gst_laari' => 'integer',
            // The GST terms this sale was priced under, frozen at creation
            // beside rate_bp/fee_bp. Reports and amendments read THESE, never
            // the live tax_settings row.
            'fee_gst_bp' => 'integer',
            'fee_treatment' => FeeTreatment::class,
            // The platform fee promotion this sale was priced under, frozen
            // the same way (owner, 2026-08-25).
            'fee_promo_fee_bp' => 'integer',
            'list_fee_bp' => 'integer',
            'fee_forgone_laari' => 'integer',
            'state' => TransactionState::class,
            'backdated' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'clock_start_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * The GST terms this row was priced under, as stamped on it.
     *
     * The ONLY correct source for anything that re-derives tax on this sale
     * — an amendment, a receipt, a report. Reading the live tax_settings row
     * instead would answer a different question (what the platform charges
     * NOW) and would re-price a sale the merchant already holds a receipt
     * for. A row written before the columns existed answers 0 bp / on_top,
     * which is the identity and reproduces its stored zero exactly.
     */
    public function stampedFeeTax(): FeeTax
    {
        return FeeTax::of((int) $this->fee_gst_bp, $this->fee_treatment);
    }

    /**
     * The platform fee promotion this row was priced under, as stamped on
     * it — the same law as stampedFeeTax(), and the same reason.
     *
     * An AMENDMENT re-prices a lined sale through this, so a correction made
     * after the promotion ended (or after its fee moved) reproduces the terms
     * the sale was rung up under instead of today's. A row written before
     * this feature existed answers FeeRelief::none(), which is the identity.
     */
    public function stampedFeeRelief(): FeeRelief
    {
        return FeeRelief::fromStamp($this->fee_promo_kind, $this->fee_promo_fee_bp);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(MerchantBranch::class, 'branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class);
    }

    /**
     * The immutable pricing split, in submitted order. Present only on
     * lined credits; single-rate transactions have no rows here.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TransactionLine::class)->orderBy('sort')->orderBy('id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    public function settlementLines(): HasMany
    {
        return $this->hasMany(SettlementLine::class);
    }
}
