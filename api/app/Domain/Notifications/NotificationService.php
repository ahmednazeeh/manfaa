<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Money\Laari;
use App\Jobs\SendCustomerSms;
use App\Models\Customer;
use App\Models\NotificationTemplate;
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
        try {
            $template = $this->template($key);

            if ($template === null || ! $template->active) {
                return;
            }

            $phone = trim((string) $customer->phone);

            if ($phone === '') {
                return;
            }

            $body = self::render($template->body(), $variables);

            if (trim($body) === '') {
                return;
            }

            // afterCommit, not dispatch(): the queue worker can pick a job up
            // before the writing transaction commits, and would then read a
            // balance that does not exist yet — or send about a sale that
            // never happened. Outside a transaction this runs immediately.
            DB::afterCommit(function () use ($phone, $body, $key, $customer): void {
                try {
                    SendCustomerSms::dispatch($phone, $body, $key->value, $customer->id);
                } catch (Throwable $exception) {
                    $this->swallow($key, $customer, $exception);
                }
            });
        } catch (Throwable $exception) {
            $this->swallow($key, $customer, $exception);
        }
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
        return Cache::remember(
            self::CACHE_PREFIX.$key->value,
            self::CACHE_TTL_SECONDS,
            fn (): ?NotificationTemplate => NotificationTemplate::query()->where('key', $key->value)->first(),
        );
    }

    /**
     * Money as the template's own language writes it: "1,234.56 ރުފިޔާ" for a
     * Dhivehi body, "MVR 1,234.56" for an English one.
     *
     * The word goes AFTER the figure in Dhivehi and before it in English —
     * the same rule the panels follow — and never "MVR" in a Thaana
     * sentence.
     */
    public static function money(int $laari, bool $dhivehi): string
    {
        $amount = Laari::of($laari)->formatMvr();

        return $dhivehi ? $amount.' ރުފިޔާ' : 'MVR '.$amount;
    }

    public static function forget(NotificationTemplateKey $key): void
    {
        Cache::forget(self::CACHE_PREFIX.$key->value);
    }

    private function swallow(NotificationTemplateKey $key, Customer $customer, Throwable $exception): void
    {
        // The customer id, never the phone number: this line goes to a log
        // that is not the place to accumulate a list of mobile numbers.
        Log::warning('Customer notification not queued.', [
            'template' => $key->value,
            'customer_id' => $customer->id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
