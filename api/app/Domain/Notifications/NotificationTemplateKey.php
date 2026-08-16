<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * The moments the platform speaks to a customer, and the words available to
 * it at each one.
 *
 * The KEYS are code and the WORDS are data — the same split as the
 * permission catalogue. A template nothing sends is a template that lies to
 * whoever edits it, so the list of moments lives here beside the code that
 * fires them; the admin owns the sentence, never the trigger.
 *
 * Adding a moment is a deploy: a case here, a call at the point it happens,
 * and a seeded row. That is deliberate — a notification is money spent per
 * message and a message a customer cannot unsubscribe from.
 */
enum NotificationTemplateKey: string
{
    case CashbackEarned = 'cashback_earned';
    case PayoutPaid = 'payout_paid';

    public function label(): string
    {
        return match ($this) {
            self::CashbackEarned => 'Cashback earned',
            self::PayoutPaid => 'Payout paid',
        };
    }

    /** When it fires, in the words of someone deciding whether to switch it on. */
    public function description(): string
    {
        return match ($this) {
            self::CashbackEarned => 'The moment a store credits a sale. The highest-volume message on the platform — one per sale, per customer.',
            self::PayoutPaid => 'When a payout item is marked paid and the money is on its way to the customer\'s bank. One per customer per payout run.',
        };
    }

    /**
     * The placeholders this template may use. Anything else in the body is
     * left exactly as typed rather than blanked — an admin who mistypes a
     * name should see their mistake in the preview, not a hole.
     *
     * @return array<string, string> token => what it renders
     */
    public function variables(): array
    {
        return match ($this) {
            self::CashbackEarned => [
                'amount' => 'The cashback earned, formatted with its currency',
                'store' => 'The store name',
            ],
            self::PayoutPaid => [
                'amount' => 'The amount paid out, formatted with its currency',
                'reference' => 'The bank reference for the transfer',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $key): string => $key->value, self::cases());
    }
}
