<?php

declare(strict_types=1);

namespace App\Domain\Transfers;

use App\Models\TransferProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reading an account's history (owner spec 2026-08-19).
 *
 * `GET /{profile}/history` for MIB and `GET /bml/history` for BML — two
 * upstreams with two field vocabularies, normalised into one `BankRow`.
 *
 * The three facts that matter per row, and where each bank hides them:
 *
 * | | MIB | BML |
 * |---|---|---|
 * | direction | `baseAmount` carries the sign | `minus` is true for a debit |
 * | reference | `trxNumber2` (short form) | `reference`, else `id` |
 * | payer name | `benefName`, else `descr3` | `narrative3`, else `narrative1` |
 *
 * READ-ONLY. Nothing here moves money, which is why it is safe to poll.
 */
final readonly class BankHistoryClient
{
    private const int TIMEOUT_SECONDS = 20;

    /** See TransferClient: a host that is not there should fail fast. */
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * @return list<BankRow>
     */
    public function history(TransferProfile $profile, string $account, int $page = 1): array
    {
        $key = (string) config('services.transfer.api_key');

        if ($key === '') {
            return [];
        }

        // BML is a different upstream, not a variant of MIB: its own query
        // string, its own response shape, its own row parser.
        $isBml = $profile->isBml();

        if ($isBml && $profile->upstreamProfile() === null) {
            // Without the upstream profile name BML answers for the wrong
            // account or not at all. Refusing here beats reading somebody
            // else's ledger.
            Log::warning('BML profile has no upstream profile name', [
                'profile' => $profile->name,
            ]);

            return [];
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $key])
                ->timeout(self::TIMEOUT_SECONDS)
                // Connecting is not the same as working. A host that is not
                // there refuses or fails to route in moments, so waiting the
                // full ceiling for it buys nothing — while a history read is quick once the far end answers at all. Two limits,
                // because they answer two different questions.
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->get(
                    sprintf(
                        '%s/%s/history',
                        rtrim($profile->base_url, '/'),
                        trim($profile->segment, '/'),
                    ),
                    $isBml
                        ? ['account' => $account, 'profile' => $profile->upstreamProfile()]
                        : ['account' => $account, 'page' => $page],
                );
        } catch (Throwable $e) {
            // A history read that fails is a poll that finds nothing, never
            // an error that stops an order. The admin queue still has it.
            Log::warning('Bank history unreachable', [
                'profile' => $profile->name,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();
        $rows = is_array($body)
            ? (array) ($body['data'] ?? $body['rows'] ?? $body)
            : [];

        $parsed = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $parsed[] = $isBml ? self::fromBml($row) : self::fromMib($row);
        }

        return array_values(array_filter($parsed));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function fromMib(array $row): ?BankRow
    {
        $reference = self::str($row, 'trxNumber2') ?? self::str($row, 'trxNumber');

        if ($reference === null) {
            return null;
        }

        // `baseAmount` is the ONLY signed field: absAmount is always
        // unsigned and trxType is a product code, not a direction.
        $signed = self::amountLaari($row['baseAmount'] ?? null);
        $amount = self::amountLaari($row['absAmount'] ?? null) ?? abs($signed ?? 0);

        return new BankRow(
            reference: $reference,
            name: self::cleanName(
                self::str($row, 'benefName') ?? self::str($row, 'descr3') ?? '',
            ),
            amountLaari: $amount,
            incoming: ($signed ?? 0) > 0,
            at: self::when(self::str($row, 'trxDate') ?? self::str($row, 'trxValDate')),
            raw: $row,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function fromBml(array $row): ?BankRow
    {
        $reference = self::str($row, 'reference') ?? self::str($row, 'id');

        if ($reference === null) {
            return null;
        }

        return new BankRow(
            reference: $reference,
            // narrative3 is the sender's name; narrative1 is the fallback.
            // narrative2 is ambiguous and deliberately not consulted.
            name: self::cleanName(
                self::str($row, 'narrative3') ?? self::str($row, 'narrative1') ?? '',
            ),
            amountLaari: self::amountLaari($row['amount'] ?? null) ?? 0,
            // `minus` true means a debit, so an incoming row is minus=false.
            incoming: ($row['minus'] ?? false) !== true,
            at: self::when(
                self::str($row, 'bookingDate') ?? self::str($row, 'valueDate'),
            ),
            raw: $row,
        );
    }

    /**
     * Strip the bank prefix the counterparty's name arrives wrapped in —
     * "BML - ZEEDHAN ABDULLA" is a person called Zeedhan Abdulla, and
     * matching against the bank's own name would be nonsense.
     */
    private static function cleanName(string $name): string
    {
        $name = trim($name);

        // A leading "<BANK> - " prefix, and any trailing narration after a
        // comma ("…, Deposit ref.@IPS").
        $name = (string) preg_replace('/^[A-Z]{2,5}\s*-\s*/u', '', $name);
        $name = explode(',', $name)[0];

        return trim($name);
    }

    /**
     * MVR decimal → integer laari, WITHOUT going through a float where it
     * can be avoided: the string form is exact and money is not a place for
     * binary rounding.
     */
    private static function amountLaari(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');
        $fraction = substr(str_pad($fraction, 2, '0'), 0, 2);

        $laari = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$laari : $laari;
    }

    private static function when(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // The upstream writes "-" for an absent counterparty account; treat
        // it as absent rather than as a name.
        return $value === '' || $value === '-' ? null : $value;
    }
}
