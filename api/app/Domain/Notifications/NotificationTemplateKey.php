<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * The moments the platform speaks to a customer OR to a store's staff, and
 * the words available to it at each one.
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
    case CashbackConfirmed = 'cashback_confirmed';
    case PayoutPaid = 'payout_paid';

    // Merchant-facing (M4). These reach the till app rather than a phone
    // number: a store's staff have no SMS relationship with the platform,
    // and settlement is the one thing a merchant genuinely wants
    // interrupting them — the prompt-payment discount is money.
    case SettlementDue = 'settlement_due';
    case SettlementAccepted = 'settlement_accepted';
    case SettlementRejected = 'settlement_rejected';

    // Deadline reminders (MR4). Fired by the daily manfaa:remind-settlements
    // walk at 09:00 business time; the DAY each one fires on is computed from
    // the live platform settings (prompt_discount_max_age_days,
    // settlement_due_days), never hardcoded here or there.
    case PromptDiscountExpiring = 'prompt_discount_expiring';
    case SettlementDueSoon = 'settlement_due_soon';

    // The §7 escalation ladder's rungs (MR4: the ladder now reaches phones).
    // The KEYS are the merchant_notices type names, verbatim — one moment,
    // one name, whether you are reading the evidence table or the push log.
    // The day numbers are §7's own fixed rungs, exactly as EscalationLadder
    // hardcodes them.
    case ReminderDay10 = 'reminder_day10';
    case UrgentDay13 = 'urgent_day13';
    case DueDay15 = 'due_day15';

    public function label(): string
    {
        return match ($this) {
            self::CashbackEarned => 'Cashback earned',
            self::CashbackConfirmed => 'Cashback confirmed',
            self::PayoutPaid => 'Payout paid',
            self::SettlementDue => 'Settlement due',
            self::SettlementAccepted => 'Settlement accepted',
            self::SettlementRejected => 'Settlement rejected',
            self::PromptDiscountExpiring => 'Prompt discount expiring',
            self::SettlementDueSoon => 'Settlement due soon',
            self::ReminderDay10 => 'Day-10 reminder',
            self::UrgentDay13 => 'Day-13 urgent reminder',
            self::DueDay15 => 'Day-15 payment due',
        };
    }

    /** When it fires, in the words of someone deciding whether to switch it on. */
    public function description(): string
    {
        return match ($this) {
            self::CashbackEarned => 'The moment a store credits a sale. The highest-volume message on the platform — one per sale, per customer.',
            self::CashbackConfirmed => 'When a sale\'s refund window closes (or a hold is released) and the pending cashback becomes confirmed. One per sale — skipped when it confirms in the same breath it was earned.',
            self::PayoutPaid => 'When a payout item is marked paid and the money is on its way to the customer\'s bank. One per customer per payout run.',
            self::SettlementDue => 'When a store creates a settlement and owes a transfer. Reaches staff who may see settlements.',
            self::SettlementAccepted => 'When Manfaa matches a store\'s transfer receipt and the settlement is paid off.',
            self::SettlementRejected => 'When a transfer receipt is refused, with the reason. The store has to act, so this one earns an interruption.',
            self::PromptDiscountExpiring => 'The morning of the LAST day the prompt-payment discount can still be kept. Sent only when settling everything today would actually save money.',
            self::SettlementDueSoon => 'The two mornings before the store\'s oldest outstanding sale becomes overdue. Once per morning, per store.',
            self::ReminderDay10 => 'The §7 ladder\'s day-10 notice: the oldest unfunded sale has turned ten days old.',
            self::UrgentDay13 => 'The §7 ladder\'s day-13 urgent notice — two days before payment is due.',
            self::DueDay15 => 'The §7 ladder\'s day-15 payment-due notice. Automatic suspension follows on day 16, so this one earns an interruption.',
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
            self::CashbackConfirmed => [
                'amount' => 'The cashback confirmed, formatted with its currency',
                'store' => 'The store name',
            ],
            self::PayoutPaid => [
                'amount' => 'The amount paid out, formatted with its currency',
                'reference' => 'The bank reference for the transfer',
            ],
            self::SettlementDue => [
                'amount' => 'The amount to transfer, formatted with its currency',
                'reference' => 'The settlement reference',
            ],
            self::SettlementAccepted => [
                'reference' => 'The settlement reference',
            ],
            self::SettlementRejected => [
                'reference' => 'The settlement reference',
                'reason' => 'Why the receipt was refused',
            ],
            self::PromptDiscountExpiring => [
                'amount' => 'What settling everything today saves, formatted with its currency',
                'rate' => 'The prompt-payment discount rate, e.g. "5%"',
            ],
            self::SettlementDueSoon => [
                'amount' => 'The outstanding total, formatted with its currency',
                'date' => 'The business-timezone date the oldest sale becomes overdue',
            ],
            self::ReminderDay10, self::UrgentDay13, self::DueDay15 => [
                'amount' => 'The outstanding total, formatted with its currency',
                'date' => 'The business-timezone date the oldest sale falls due',
            ],
        };
    }

    /**
     * Who this moment is addressed to.
     *
     * Decides both the delivery route and the recipient list: a customer
     * moment reaches one phone number and that customer's devices, while a
     * merchant moment reaches the till devices of staff who may act on it.
     */
    public function isForMerchantStaff(): bool
    {
        return match ($this) {
            self::CashbackEarned, self::CashbackConfirmed, self::PayoutPaid => false,
            self::SettlementDue, self::SettlementAccepted, self::SettlementRejected,
            self::PromptDiscountExpiring, self::SettlementDueSoon,
            self::ReminderDay10, self::UrgentDay13, self::DueDay15 => true,
        };
    }

    /**
     * The push notification's TITLE, in code rather than in the editable
     * template.
     *
     * A title is structural — two or three words naming what happened — and
     * an admin editing copy is editing the sentence, not the label above it.
     * Keeping it here also means a title can never come back empty, which on
     * both platforms renders as a notification with no heading.
     *
     * @return array{en: string, dv: string}
     */
    public function pushTitle(): array
    {
        return match ($this) {
            self::CashbackEarned => ['en' => 'Cashback earned', 'dv' => 'ކޭޝްބެކް ލިބިއްޖެ'],
            self::CashbackConfirmed => ['en' => 'Cashback confirmed', 'dv' => 'ކޭޝްބެކް ކަށަވަރުވެއްޖެ'],
            self::PayoutPaid => ['en' => 'Payout sent', 'dv' => 'ފައިސާ ފޮނުވިއްޖެ'],
            self::SettlementDue => ['en' => 'Settlement due', 'dv' => 'ސެޓްލްމަންޓް ދައްކަންޖެހޭ'],
            self::SettlementAccepted => ['en' => 'Settlement accepted', 'dv' => 'ސެޓްލްމަންޓް ބަލައިގަނެވިއްޖެ'],
            self::SettlementRejected => ['en' => 'Receipt refused', 'dv' => 'ރަސީދު ބަލައިނުގަނެވުނު'],
            self::PromptDiscountExpiring => ['en' => 'Discount expiring', 'dv' => 'ޑިސްކައުންޓް ގެއްލިދާނެ'],
            self::SettlementDueSoon => ['en' => 'Payment due soon', 'dv' => 'ސުންގަޑި ކައިރިވަނީ'],
            self::ReminderDay10 => ['en' => 'Settlement reminder', 'dv' => 'ސެޓްލްމަންޓް ހަނދާންކޮށްދިނުން'],
            self::UrgentDay13 => ['en' => 'Urgent reminder', 'dv' => 'އަވަސް ހަނދާންކޮށްދިނުން'],
            self::DueDay15 => ['en' => 'Payment due', 'dv' => 'ފައިސާ ދައްކަންޖެހޭ'],
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
