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

    // The MR9 review outcome. A live store's public claims — its name,
    // category, logo, terms, branches — wait for an admin now, so the store
    // has to be told when the wait ends, either way. Reaches the staff who
    // hold the permission that governs the change (ChangeKind::permission).
    case StoreChangeApproved = 'store_change_approved';
    case StoreChangeRejected = 'store_change_rejected';

    // A store took ITSELF off the app, or put itself back (owner decision
    // 2026-08-18). Reaches the customers who have actually earned cashback
    // there — someone who has shopped at a store has a reason to be told it
    // has paused, and someone who never has does not. Rate-limited to one of
    // each per store per day, in the database, so a merchant playing with
    // the switch cannot turn it into a broadcast.
    case StorePaused = 'store_paused';
    case StoreResumed = 'store_resumed';

    // The store passed review and is LIVE (owner decision 2026-08-18). The
    // one moment in a merchant's life with us that earns both channels: they
    // have been waiting on a human decision they cannot chase, and they may
    // not have the app open — or installed — when it lands.
    case StoreApproved = 'store_approved';

    // Marketplace enrolment outcomes (PLAN-marketplace.md §9). The merchant
    // handed us identity documents and waited on a human; silence after
    // that is the one thing this must not be.
    case MarketplaceApproved = 'marketplace_approved';
    case MarketplaceRejected = 'marketplace_rejected';

    // Marketplace order moments. Each one is something the customer cannot
    // see for themselves — they are not holding the screen when a shop
    // accepts, cuts or hands over their shopping.
    case OrderPlaced = 'order_placed';
    case OrderAccepted = 'order_accepted';
    case OrderRejected = 'order_rejected';
    case OrderAmended = 'order_amended';
    case OrderReady = 'order_ready';
    case OrderOutForDelivery = 'order_out_for_delivery';
    case OrderDelivered = 'order_delivered';

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
            self::StoreChangeApproved => 'Store change approved',
            self::StoreChangeRejected => 'Store change rejected',
            self::StorePaused => 'Store paused cashback',
            self::StoreResumed => 'Store resumed cashback',
            self::StoreApproved => 'Store approved',
            self::MarketplaceApproved => 'Marketplace approved',
            self::MarketplaceRejected => 'Marketplace not approved',
            self::OrderPlaced => 'New order',
            self::OrderAccepted => 'Order accepted',
            self::OrderRejected => 'Order refused',
            self::OrderAmended => 'Order changed',
            self::OrderReady => 'Ready for pickup',
            self::OrderOutForDelivery => 'Out for delivery',
            self::OrderDelivered => 'Order delivered',
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
            self::StoreChangeApproved => 'When an admin approves a queued store change — a profile edit, a logo, a new or changed branch. The change is live at that moment.',
            self::StoreChangeRejected => 'When an admin refuses a queued store change, with the reason. The store has to act, so this one earns an interruption.',
            self::StorePaused => 'When a store takes itself off the app. Goes to customers who have earned cashback there before, so they do not make a trip for an offer that is not running.',
            self::StoreResumed => 'When a store that had paused puts itself back on the app. Goes to the same customers — the ones who already know the shop.',
            self::OrderPlaced => 'When a paid marketplace order reaches a shop. Goes to the staff who work the order queue.',
            self::OrderAccepted => 'When a shop accepts a customer order and starts preparing it.',
            self::OrderRejected => 'When a shop refuses an order, with the reason. The customer is owed their money back, so this earns an interruption.',
            self::OrderAmended => 'When a shop reduces an order — an item out of stock, say. Names the refund, because the customer paid for goods they will not receive.',
            self::OrderReady => 'When a collection order is ready at the counter.',
            self::OrderOutForDelivery => 'When a delivery leaves the shop.',
            self::OrderDelivered => 'When an order is handed over. Cashback follows validation.',
            self::MarketplaceApproved => 'When an admin approves a store to sell on the marketplace. The shop can list products from that moment.',
            self::MarketplaceRejected => 'When an admin refuses a marketplace application, with the reason. The store has to act, so it earns an interruption.',
            self::StoreApproved => 'When an admin approves a new store and it goes live. Sent by SMS as well as push — the merchant has been waiting on a decision and may not have the app open.',
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
            self::StoreChangeApproved => [
                'change' => 'What was reviewed, e.g. "store profile change" or "new branch"',
                'store' => 'The store name',
            ],
            self::StoreChangeRejected => [
                'change' => 'What was reviewed, e.g. "store profile change" or "new branch"',
                'store' => 'The store name',
                'reason' => 'Why the change was refused',
            ],
            self::StorePaused, self::StoreResumed, self::StoreApproved,
            self::MarketplaceApproved => [
                'store' => 'The store name',
            ],
            self::MarketplaceRejected => [
                'store' => 'The store name',
                'reason' => 'Why the application was refused',
            ],
            self::OrderPlaced => [
                'reference' => 'The order reference for this shop',
                'amount' => 'What the order is worth, formatted with its currency',
            ],
            self::OrderAccepted, self::OrderReady,
            self::OrderOutForDelivery, self::OrderDelivered => [
                'store' => 'The store name',
                'reference' => 'The order reference',
            ],
            self::OrderRejected => [
                'store' => 'The store name',
                'reference' => 'The order reference',
                'reason' => 'Why the shop refused it',
            ],
            self::OrderAmended => [
                'store' => 'The store name',
                'reference' => 'The order reference',
                'amount' => 'What is being refunded, formatted with its currency',
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
            self::ReminderDay10, self::UrgentDay13, self::DueDay15,
            self::StoreChangeApproved, self::StoreChangeRejected => true,
            self::OrderPlaced => true,
            self::OrderAccepted, self::OrderRejected, self::OrderAmended,
            self::OrderReady, self::OrderOutForDelivery, self::OrderDelivered => false,
            // Customer-facing: these go to shoppers, not to the store's till.
            self::StorePaused, self::StoreResumed => false,
            self::StoreApproved,
            self::MarketplaceApproved, self::MarketplaceRejected => true,
        };
    }

    /**
     * Whether this moment may spend an SMS.
     *
     * SMS costs money per message and interrupts a phone that may not have
     * the app on it, so it is reserved for moments about the CUSTOMER'S OWN
     * money — cashback earned, confirmed, paid out. A store pausing is news
     * about someone else's shop: worth a push to the people who know that
     * shop, never worth texting every one of them, least of all on a switch
     * the merchant controls and we do not pay for.
     */
    public function usesSms(): bool
    {
        return match ($this) {
            self::StorePaused, self::StoreResumed => false,
            // Order progress reaches a shopper who is probably holding their
            // phone anyway, and a push is free. The two that COST them money
            // — a refusal and a cut order — are the two worth a text, since
            // those are the ones they must not miss.
            self::OrderAccepted, self::OrderReady,
            self::OrderOutForDelivery, self::OrderDelivered => false,
            default => true,
        };
    }

    /**
     * Whether a MERCHANT-facing moment also earns an SMS, sent to the store's
     * own contact number.
     *
     * ON by default (owner decision 2026-08-18). Every merchant moment we
     * raise is one the shop has to act on or account for — a settlement
     * falling due, a receipt refused, a change approved, the store going
     * live — and a push that lands on a handset nobody is holding is not a
     * message that was delivered. Merchant staff carry no phone numbers, so
     * the destination is the STORE'S number: the one given at signup and
     * verified by OTP, which is also the number the shop already answers.
     *
     * These are per-store and event-driven, not a broadcast: the busiest of
     * them is the settlement cycle, so a merchant who pays on time hears
     * from us a few times a month at most. If a future key is chatty enough
     * to be a nuisance, name it here and return false — the exception is
     * cheaper to write than the apology.
     */
    public function smsToMerchantContact(): bool
    {
        return match ($this) {
            // Customer-facing keys never reach this method (it is asked only
            // inside sendToMerchantStaff), but answering honestly costs
            // nothing and stops a future reader concluding the platform
            // texts shops about their customers' deliveries.
            self::CashbackEarned, self::CashbackConfirmed, self::PayoutPaid,
            self::StorePaused, self::StoreResumed,
            self::OrderAccepted, self::OrderRejected, self::OrderAmended,
            self::OrderReady, self::OrderOutForDelivery, self::OrderDelivered => false,
            // Name a key here to keep a MERCHANT moment push-only.
            default => true,
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
            self::StoreChangeApproved => ['en' => 'Change approved', 'dv' => 'ބަދަލު ފާސްވެއްޖެ'],
            self::StoreChangeRejected => ['en' => 'Change refused', 'dv' => 'ބަދަލު ބަލައިނުގަނެވުނު'],
            self::StorePaused => ['en' => 'Cashback paused', 'dv' => 'ކޭޝްބެކް މެދުކެނޑިއްޖެ'],
            self::StoreResumed => ['en' => 'Cashback is back', 'dv' => 'ކޭޝްބެކް އަލުން ފެށިއްޖެ'],
            self::StoreApproved => ['en' => 'Store approved', 'dv' => 'ފިހާރަ ފާސްވެއްޖެ'],
            self::MarketplaceApproved => ['en' => 'Marketplace approved', 'dv' => 'މާކެޓްޕްލޭސް ފާސްވެއްޖެ'],
            self::MarketplaceRejected => ['en' => 'Application refused', 'dv' => 'ހުށަހެޅުން ބަލައިނުގަނެވުނު'],
            self::OrderPlaced => ['en' => 'New order', 'dv' => 'އައު އޯޑަރެއް'],
            self::OrderAccepted => ['en' => 'Order accepted', 'dv' => 'އޯޑަރު ބަލައިގަނެފި'],
            self::OrderRejected => ['en' => 'Order refused', 'dv' => 'އޯޑަރު ބަލައިނުގަނެވުނު'],
            self::OrderAmended => ['en' => 'Order changed', 'dv' => 'އޯޑަރަށް ބަދަލެއް'],
            self::OrderReady => ['en' => 'Ready for pickup', 'dv' => 'ނަގަން ތައްޔާރު'],
            self::OrderOutForDelivery => ['en' => 'On its way', 'dv' => 'ގެންދަނީ'],
            self::OrderDelivered => ['en' => 'Delivered', 'dv' => 'ލިބިއްޖެ'],
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
