<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer correcting their own Thaana name (owner, 2026-08-21).
 *
 * The name is written by a model at registration, and a model transliterating
 * a person's name will sometimes be wrong — a spelling the family does not
 * use, an unusual name it has not seen. This is the correction, and it is the
 * only writer that outranks the job: once a person has set it, {@see
 * \App\Jobs\WriteCustomerDhivehiName} never overwrites it.
 *
 * The ENGLISH name is deliberately not editable here. It is what the merchant
 * sees at the till and what the bank matches a payout against; changing it is
 * a support action, not a self-service one.
 */
final class CustomerProfileController extends Controller
{
    /**
     * Thaana is U+0780–U+07BF, plus ﷲ (U+FDF2) — the conventional spelling of
     * Abdulla, އަބްދުﷲ. A word may not OPEN with one of the combining vowels
     * at U+07A6–U+07B0, which sit on a consonant.
     *
     * The same rules the writer applies to the model's answer, so a customer
     * can type anything the writer is allowed to produce.
     */
    private const string THAANA = '/^(?!.*(?:^|\s)[\x{07A6}-\x{07B0}])[\x{0780}-\x{07BF}\x{FDF2}\s]+$/u';

    public function update(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user('customer');

        $validated = $request->validate([
            // Nullable on purpose: clearing it is how a customer says "the
            // English one is right for me", and the job will not refill it.
            'name_dv' => ['present', 'nullable', 'string', 'max:120', 'regex:'.self::THAANA],
        ], [
            'name_dv.regex' => 'Write the name in Thaana.',
        ]);

        $name = $validated['name_dv'] === null
            ? null
            : trim(preg_replace('/\s+/u', ' ', $validated['name_dv']) ?? '');

        $customer->forceFill(['name_dv' => $name === '' ? null : $name])->save();

        return new JsonResponse([
            'data' => [
                'name' => $customer->name,
                'name_dv' => $customer->name_dv,
            ],
        ]);
    }
}
