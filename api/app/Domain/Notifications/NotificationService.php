<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\MerchantAccess\Permission;
use App\Domain\Money\Laari;
use App\Jobs\SendCustomerSms;
use App\Jobs\SendPushNotification;
use App\Models\Customer;
use App\Models\DeviceToken;
use App\Models\Merchant;
use App\Models\MerchantUser;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a customer something happened.
 *
 * Three rules shape everything here, and all three exist because this sits
 * beside code that moves money:
 *
 * 1. NEVER INSIDE THE TRANSACTION. Every caller runs inside a DB
 *    transaction — a credit being recorded, a payout item being paid — and
 *    an SMS cannot be rolled back. Sending eagerly would mean a customer
 *    told they earned cashback on a sale that then failed to commit. Every
 *    send is deferred to afterCommit, so the message follows the fact.
 *
 * 2. NEVER BREAK THE CALLER. A template that will not render, a provider
 *    that is down, a queue that will not accept the job — none of that is a
 *    reason to fail a payout. Everything here is caught and logged, and the
 *    caller is never told, because there is nothing the caller could do.
 *
 * 3. SILENCE IS THE DEFAULT. An inactive template sends nothing, and a
 *    customer with no phone on file sends nothing. Both are ordinary, not
 *    errors.
 */
final class NotificationService
{
    private const string CACHE_PREFIX = 'notification_template:v1:';

    private const int CACHE_TTL_SECONDS = 60;

    /**
     * Queue a message for after the current transaction commits.
     *
     * @param  array<string, string>  $variables  token => already-rendered text
     */
    public function send(NotificationTemplateKey $key, Customer $customer, array $variables): void
    {
        // EVERYTHING runs after the caller's transaction commits — including
        // the template lookup and every query behind it. See deferred().
        $this->deferred($key, $customer::class, (int) $customer->getKey(), function () use ($key, $customer, $variables): void {
            $template = $this->template($key);

            if ($template === null || ! $template->active) {
                return;
            }

            $body = self::render($template->body(), $variables);

            if (trim($body) === '') {
                return;
            }

            $phone = trim((string) $customer->phone);

            // SMS and push are INDEPENDENT. A customer with no number on
            // file can still hold a phone with the app on it, and the old
            // shape returned before either could be considered. Each channel
            // decides for itself whether it has somewhere to deliver.
            if ($phone !== '' && $key->usesSms()) {
                SendCustomerSms::dispatch($phone, $body, $key->value, $customer->id);
            }

            $this->push($key, $customer, $body);
        });
    }

    /**
     * A merchant-facing moment, to the till devices of the staff who may act
     * on it.
     *
     * Push only — a store's staff have no SMS relationship with the
     * platform, and there is no number to fall back to.
     *
     * Addressed by PERMISSION rather than to everyone: a settlement message
     * reaching a cashier who cannot open the settlement screen is noise they
     * cannot act on, and the catalogue already knows who can. Inactive staff
     * are excluded — their tokens are destroyed on deactivation anyway, but
     * the query should not depend on that having happened.
     *
     * @param  array<string, string>  $variables  token => already-rendered text
     * @param  ?callable(): bool  $when  asked against COMMITTED state
     */
    public function sendToMerchantStaff(
        NotificationTemplateKey $key,
        Merchant $merchant,
        array $variables,
        Permission $required,
        ?callable $when = null,
    ): void {
        $this->deferred($key, $merchant::class, (int) $merchant->getKey(), function () use ($key, $merchant, $variables, $required, $when): void {
            // Re-read the row as it COMMITTED. A caller may have moved the
            // settlement on inside the same transaction — submit() is reused
            // by the receipt-first and wallet-funded paths, where the batch
            // never rests in awaiting_payment — so "should this still be
            // sent?" can only be answered honestly from committed state.
            if ($when !== null && ! $when()) {
                return;
            }

            $template = $this->template($key);

            if ($template === null || ! $template->active) {
                return;
            }

            $body = self::render($template->body(), $variables);

            if (trim($body) === '') {
                return;
            }

            $recipients = MerchantUser::query()
                ->where('merchant_id', $merchant->getKey())
                ->where('is_active', true)
                // Eager-loaded: ->can() resolves through the role, and
                // without this the fan-out is one query per staff member.
                ->with('role')
                ->get()
                ->filter(fn (MerchantUser $user): bool => $user->can($required));

            foreach ($recipients as $user) {
                $this->push($key, $user, $body);
            }
        });
    }

    /**
     * Run a notification's ENTIRE body after the caller's transaction
     * commits, and swallow anything it throws.
     *
     * WHY THIS EXISTS, and it is the most important thing in this class:
     * these calls sit INSIDE money transactions — a settlement being
     * submitted, a payment being matched, a sale being credited. Running a
     * query there and catching its exception is not safe on PostgreSQL. One
     * failed statement puts the transaction in an ABORTED state; a swallowed
     * error therefore leaves the caller believing it may continue, and the
     * COMMIT that follows is executed as a ROLLBACK **which reports no
     * error at all**. Laravel sees a successful commit and answers 200 while
     * the ledger postings, the wallet debit and the state change have all
     * been discarded.
     *
     * Deferring the whole body — not merely the dispatch — is what makes the
     * old promise ("nothing here can fail a settlement") actually true:
     * afterCommit callbacks run once the transaction has already committed,
     * so nothing in here shares its fate. Outside a transaction it runs
     * immediately, which is why the try/catch is still required.
     */
    private function deferred(NotificationTemplateKey $key, string $recipientType, int $recipientId, callable $work): void
    {
        DB::afterCommit(function () use ($key, $recipientType, $recipientId, $work): void {
            try {
                $work();
            } catch (Throwable $exception) {
                $this->swallow($key, $recipientType, $recipientId, $exception);
            }
        });
    }

    /**
     * Queue one push per registered device, after the transaction commits.
     *
     * The TITLE is localised per device from the code catalogue — that is
     * the device's own app-language choice; the BODY is always English,
     * exactly as the SMS is (decision 2026-08-17: Dhivehi bodies are gone).
     *
     * The trailing "— Manfaa" is stripped: iOS and Android already print the
     * app's name above the body, and a push gets about two lines.
     */
    private function push(NotificationTemplateKey $key, Model $recipient, string $body): void
    {
        $body = self::stripSignature($body);

        // No afterCommit here: every caller already reaches this from inside
        // deferred(), so the transaction is long gone.
        $devices = DeviceToken::query()
            ->where('tokenable_type', $recipient->getMorphClass())
            ->where('tokenable_id', $recipient->getKey())
            // A token that has expired but has not yet been swept authenticates
            // nothing and is hidden from the user's own device list — pushing
            // to it would announce settlement references to a handset its owner
            // has no way to see, let alone revoke.
            ->whereHas('accessToken', fn ($token) => $token
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->get();

        foreach ($devices as $device) {
            $title = $key->pushTitle()[$device->sendsDhivehi() ? 'dv' : 'en'];

            SendPushNotification::dispatch($device->getKey(), $title, $body, $key->value);
        }
    }

    /**
     * "…— Manfaa" and its Thaana twin, which belong on an SMS and nowhere
     * else.
     */
    private static function stripSignature(string $body): string
    {
        return trim(preg_replace('/\s*[—-]\s*(Manfaa|މަންފާ)\s*$/u', '', $body) ?? $body);
    }

    /**
     * Substitutes {{token}} for its value.
     *
     * Unknown tokens are LEFT AS TYPED rather than blanked: an admin who
     * writes {{ammount}} should see it in the preview and in the message
     * and recognise the mistake, instead of finding a sentence with a hole
     * where a number belongs. Values are inserted verbatim — this is SMS,
     * so there is no markup to escape.
     *
     * @param  array<string, string>  $variables
     */
    public static function render(string $body, array $variables): string
    {
        foreach ($variables as $token => $value) {
            $body = str_replace('{{'.$token.'}}', $value, $body);
        }

        return $body;
    }

    /**
     * Cached briefly: a payout run renders the same template once per
     * customer, and that should be one read rather than one per message.
     * Busted on every edit — see NotificationTemplatesController.
     */
    public function template(NotificationTemplateKey $key): ?NotificationTemplate
    {
        // The cache holds the ATTRIBUTES, never the Eloquent object: a
        // serialized model written by one build can come back as
        // __PHP_Incomplete_Class under another, and that exact failure
        // silently ate the payout-paid push on 2026-08-17. An array
        // deserializes under any build; anything unexpected in the slot is
        // treated as a miss and overwritten rather than trusted.
        $cacheKey = self::CACHE_PREFIX.$key->value;

        $attributes = Cache::get($cacheKey);

        if (! is_array($attributes) && $attributes !== null) {
            Cache::forget($cacheKey); // poisoned by an older build — drop it
            $attributes = null;
        }

        if ($attributes === null) {
            $template = NotificationTemplate::query()->where('key', $key->value)->first();
            // `[]` marks a real "no such template" so absence is cached too.
            Cache::put($cacheKey, $template?->getAttributes() ?? [], self::CACHE_TTL_SECONDS);

            return $template;
        }

        if ($attributes === []) {
            return null;
        }

        return (new NotificationTemplate)->newFromBuilder($attributes);
    }

    /**
     * Money as an English notification writes it: "MVR 1,234.56". Every
     * notification body is English (decision 2026-08-17), so this no longer
     * asks which language the template speaks.
     */
    public static function money(int $laari): string
    {
        return 'MVR '.Laari::of($laari)->formatMvr();
    }

    public static function forget(NotificationTemplateKey $key): void
    {
        Cache::forget(self::CACHE_PREFIX.$key->value);
    }

    private function swallow(NotificationTemplateKey $key, string $recipientType, int $recipientId, Throwable $exception): void
    {
        // The id, never the phone number: this line goes to a log that is
        // not the place to accumulate a list of mobile numbers.
        Log::warning('Notification not queued.', [
            'template' => $key->value,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
