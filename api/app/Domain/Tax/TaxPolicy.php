<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\TaxSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The GST terms IN FORCE, read once per credit path rather than once per
 * row, and cached for 60 seconds exactly as PlatformConfig caches its own
 * settings. Writes bust the entry immediately, so a superadmin who enables
 * GST sees the next sale priced under it rather than up to a minute later.
 *
 * This answers ONE question: what would a sale priced RIGHT NOW freeze onto
 * itself? It is never consulted about a sale that has already been priced —
 * that row carries its own stamp, and reading this instead would re-price
 * history, which is the single thing this feature must never do.
 */
final class TaxPolicy
{
    private const string CACHE_KEY = 'tax_settings.fee_tax';

    private const int CACHE_TTL_SECONDS = 60;

    /**
     * The deploy-order probe, answered ONCE per instance — a table cannot
     * appear and disappear under a live process, so asking pg_class again on
     * every credit is a round-trip that can only ever repeat itself.
     * Memoised exactly as TermsResolver and FeeTierScheduleResolver memoise
     * their own probes.
     */
    private ?bool $tableExists = null;

    /** The terms a sale priced now would be stamped with. */
    public function current(): FeeTax
    {
        // Deploy-order safety, the same probe TermsResolver uses for
        // transaction_lines: this code can reach production a moment before
        // its migration runs, and it executes INSIDE the credit's database
        // transaction where a failed query would abort the sale. No table
        // means no tax, which is also the correct answer.
        $this->tableExists ??= Schema::hasTable('tax_settings');

        if ($this->tableExists === false) {
            return FeeTax::none();
        }

        /** @var array{rate_bp: int, treatment: string} $cached */
        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $setting = TaxSetting::current();
            $tax = $setting->feeTax();

            return ['rate_bp' => $tax->rateBp, 'treatment' => $tax->treatment->value];
        });

        return FeeTax::of((int) $cached['rate_bp'], (string) $cached['treatment']);
    }

    /** Called by the settings write path; the next read re-queries. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
