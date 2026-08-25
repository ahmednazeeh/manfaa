<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tax\FeeTax;
use App\Domain\Tax\FeeTreatment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single row governing GST on the platform fee (owner, 2026-08-24).
 *
 * Its own table rather than a PlatformConfig key, following the
 * TransferSetting precedent: PlatformConfig stores INTEGERS, and a TIN, a
 * business name and an activity number are strings — the three facts a tax
 * invoice must carry to be one.
 *
 * Ships DISABLED. Nothing about today's pricing changes until a superadmin
 * enables it, and enabling is refused (422) without all three identity
 * fields.
 */
class TaxSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gst_enabled' => 'boolean',
            'gst_rate_bp' => 'integer',
            'fee_treatment' => FeeTreatment::class,
            'enabled_at' => 'immutable_datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    /** There is exactly one, always. */
    public static function current(): self
    {
        return self::query()->firstOrCreate([], [
            'gst_enabled' => false,
            'gst_rate_bp' => 800,
            'fee_treatment' => FeeTreatment::OnTop->value,
        ]);
    }

    /**
     * The terms a sale priced RIGHT NOW would freeze onto itself.
     *
     * Disabled answers a zero rate, which is the identity in both
     * treatments — so a platform with the switch off prices exactly as it
     * did before this table existed.
     */
    public function feeTax(): FeeTax
    {
        return $this->gst_enabled
            ? FeeTax::of((int) $this->gst_rate_bp, $this->fee_treatment)
            : FeeTax::none();
    }

    /**
     * Can the switch be turned on? A GST-registered platform issues tax
     * invoices, and an invoice that cannot name the registrant is not one —
     * so all three identity facts must be on the row first.
     *
     * @return list<string> the missing field names; empty when ready
     */
    public function missingIdentityFields(): array
    {
        $missing = [];

        foreach (['gst_tin', 'gst_business_name', 'gst_activity_number'] as $field) {
            if (trim((string) $this->{$field}) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * The same list in the words an operator reads above the inputs.
     *
     * `missingIdentityFields()` returns REQUEST KEYS, which is right for a
     * client deciding which input to highlight and wrong for a sentence a
     * person reads. A refusal that says "gst_tin, gst_business_name" is the
     * API telling a superadmin about its own JSON.
     *
     * @return list<string>
     */
    public function missingIdentityLabels(): array
    {
        $labels = [
            'gst_tin' => 'GST TIN',
            'gst_business_name' => 'registered business name',
            'gst_activity_number' => 'tax activity number',
        ];

        return array_values(array_map(
            fn (string $field): string => $labels[$field] ?? $field,
            $this->missingIdentityFields(),
        ));
    }
}
