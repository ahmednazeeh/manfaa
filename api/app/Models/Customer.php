<?php

namespace App\Models;

use App\Domain\Mobile\MobileTokenSubject;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A customer account (§5 data model). `customer_code` is the 6-digit public
 * identity: displayed in-app, printed, and scanned as a QR at the till.
 * New codes never start 7 or 9 — see generateCode() for the truncated-phone
 * collision that reserves those ranges, and for why codes already issued
 * inside them remain valid.
 */
#[Fillable([
    'customer_code',
    'phone',
    'phone_verified_at',
    'name',
    'email',
    'password',
    'status',
    'payout_bank',
    'payout_account',
    'payout_account_name',
    'kyc_status',
])]
#[Hidden(['password', 'remember_token'])]
class Customer extends Authenticatable implements MobileTokenSubject
{
    /** @use HasFactory<CustomerFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'immutable_datetime',
            'password' => 'hashed',
            'referred_by_customer_id' => 'integer',
            'referred_at' => 'immutable_datetime',
            'referral_rewarded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Only an ACTIVE customer may use the app. `suspended` and `closed` are
     * both refusals — a closed account keeps its rows for the ledger's sake,
     * which is precisely why it must not keep its access.
     *
     * Strict comparison against the string, not a `!== 'suspended'` test: a
     * status added later defaults to refused rather than silently allowed.
     */

    /** Saved delivery addresses (PLAN-marketplace.md §2.5). */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /** Marketplace orders — the payment, not the fulfilment (§2.6). */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function mayUseMobileApp(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Size of the issuable code space: 100000–699999 plus 800000–899999.
     */
    private const int CODE_SPACE = 700000;

    /**
     * Generate a unique random 6-digit customer code, skipping the 7xxxxx
     * and 9xxxxx ranges.
     *
     * WHY THE GAP: the till accepts either a customer code or a phone as the
     * customer ref, and every Maldivian mobile is 7 digits starting 7 or 9.
     * A code of the form 7xxxxx / 9xxxxx is therefore shape-identical to a
     * mobile with one digit dropped — a cashier fumbling +9607712345 into
     * 771234 would silently credit whichever stranger holds that code
     * instead of failing the lookup. Skipping those two leading digits makes
     * a truncated phone impossible to mistake for a code; 700,000 codes
     * remain, far beyond any plausible customer count.
     *
     * Codes issued before this rule STAY VALID — they are printed, saved and
     * carried as QR by real customers, and reissuing them would break every
     * copy in the wild. That is also why the rule lives here and not in a
     * column CHECK: Postgres enforces a CHECK (even one added NOT VALID) on
     * every subsequent UPDATE, so a grandfathered 7xxxxx customer could
     * never change their name again.
     */
    public static function generateCode(): string
    {
        do {
            $offset = random_int(0, self::CODE_SPACE - 1);

            // 0–599999 → 100000–699999; 600000–699999 → 800000–899999.
            $code = (string) ($offset < 600000 ? 100000 + $offset : 200000 + $offset);
        } while (static::query()->where('customer_code', $code)->exists());

        return $code;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Who brought this customer in (referral programme, owner 2026-08-23).
     * Set once at signup from a typed customer_code and never after —
     * deliberately NOT fillable, so no mass assignment can rewrite it.
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_customer_id');
    }

    /** The customers this one brought in. */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_customer_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function payoutItems(): HasMany
    {
        return $this->hasMany(PayoutItem::class);
    }
}
