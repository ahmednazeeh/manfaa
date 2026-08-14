<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Customer;

/**
 * A vendor-supplied customer_ref (§9.2; original-spec online model 3 —
 * phone-keyed posting). EITHER the 6-digit customer code shown at the till,
 * OR the customer's Maldivian mobile: full +960XXXXXXX E.164, or the
 * 7-digit local form (mobiles start 7 or 9), normalised to E.164 before any
 * lookup. The two spaces never collide — codes are exactly six digits,
 * local mobiles exactly seven — so resolution is unambiguous by shape alone.
 */
final readonly class CustomerRef
{
    /**
     * Every accepted shape: 6-digit code, +960 mobile, 7-digit local
     * mobile. Suitable as a Laravel `regex:` rule — anything else is a
     * validation failure, never a lookup.
     */
    public const string PATTERN = '/^(\d{6}|\+960[79]\d{6}|[79]\d{6})$/';

    private function __construct(
        public string $raw,
        public bool $isPhone,
        /** The lookup key: the customer_code for codes, +960 E.164 for phones. */
        public string $normalized,
    ) {}

    public static function parse(string $raw): ?self
    {
        if (preg_match('/^\d{6}$/', $raw) === 1) {
            return new self($raw, isPhone: false, normalized: $raw);
        }

        if (preg_match('/^\+960[79]\d{6}$/', $raw) === 1) {
            return new self($raw, isPhone: true, normalized: $raw);
        }

        if (preg_match('/^[79]\d{6}$/', $raw) === 1) {
            return new self($raw, isPhone: true, normalized: '+960'.$raw);
        }

        return null;
    }

    /**
     * The transaction origin a credit keyed by this ref records: 'api_phone'
     * for phone-keyed postings, 'pos' for code-keyed ones (§5 origin CHECK).
     */
    public function origin(): string
    {
        return $this->isPhone ? 'api_phone' : 'pos';
    }

    /** The customer this ref names, or null — phones are unique, codes too. */
    public function resolve(): ?Customer
    {
        return Customer::query()
            ->where($this->isPhone ? 'phone' : 'customer_code', $this->normalized)
            ->first();
    }
}
