<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\MerchantUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The guided-setup tasklist (owner, 2026-08-25): a short list of the things
 * a shop's first week is actually made of, shown in the panel's sidebar and
 * on the app's home screen, skippable, and gone five days after the person
 * reading it first arrived.
 *
 * THREE RULES DECIDE EVERYTHING HERE.
 *
 * 1. PER PERSON, not per store. The anchor lives on merchant_users, so a
 *    cashier added in three months gets their own five days rather than
 *    inheriting an owner's expired ones. It is stamped the first time the
 *    guide is ASKED for — never as a login side effect, because a write
 *    hung off sign-in is a write that can fail a sign-in.
 *
 * 2. FIVE DAYS IS A HARD STOP, and it is DERIVED. `expires_at` is
 *    anchor + 5 days, computed on every read; there is no expired flag and
 *    no cron, because a flag that has to be swept is wrong between sweeps.
 *    The five days are five whole 24-hour days from the anchor INSTANT, not
 *    five business dates: a merchant who signs up at 23:00 would otherwise
 *    get a "first day" thirty minutes long, and the answer would change
 *    depending on whether the reader's clock or ours drew the date line.
 *
 * 3. EVERY TASK IS DERIVED FROM REAL STATE. Nothing here is a checkbox
 *    someone ticks: "credit your first customer" is done when a transaction
 *    exists, and only then. A tasklist that can be lied to is worse than no
 *    tasklist, because it will be lied to on the day it matters — the day a
 *    shop reports it settled and never did.
 *
 * COST. This renders in a sidebar on EVERY page load, so the whole tasklist
 * is ONE query: a single row on merchants carrying the store's status, its
 * bank identity and three EXISTS subqueries, each on an indexed merchant_id.
 * When the guide is over — skipped or past five days, which is the state
 * every merchant is in forever after their first week — it costs ZERO
 * queries, because there is nothing left to compute.
 */
final class OnboardingGuide
{
    /** The owner's hard rule: five days from the anchor, then gone. */
    public const int WINDOW_DAYS = 5;

    /**
     * The whole guide as a client sees it. `show` is the only thing a
     * sidebar needs to consult: false means draw nothing at all.
     *
     * @return array<string, mixed>
     */
    public function state(MerchantUser $user): array
    {
        $now = CarbonImmutable::now('UTC');
        $startedAt = $this->anchor($user, $now);
        $expiresAt = $startedAt->addDays(self::WINDOW_DAYS);

        $skipped = $user->onboarding_skipped_at !== null;
        $expired = $now->greaterThanOrEqualTo($expiresAt);
        $show = ! $skipped && ! $expired;

        // Nothing to draw, nothing to count. The overwhelmingly common
        // case after week one, and the reason this endpoint is safe to hang
        // off every page load.
        $tasks = $show ? $this->tasks($user) : [];
        $done = count(array_filter($tasks, static fn (array $task): bool => $task['done']));

        return [
            'show' => $show,
            'skipped' => $skipped,
            'expired' => $expired,
            'tour_completed' => $user->onboarding_tour_completed_at !== null,
            'started_at' => $startedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            // Whole days left, rounded UP: 5 on the day they arrive, 1
            // through the final 24 hours, 0 only once it is over. What a
            // "3 days left" chip prints.
            'days_remaining' => $this->daysRemaining($now, $expiresAt),
            'window_days' => self::WINDOW_DAYS,
            'title_en' => 'Getting started',
            'title_dv' => 'ފެށުމުގެ ފިޔަވަޅުތައް',
            'tasks' => $tasks,
            'tasks_done' => $done,
            'tasks_total' => count($tasks),
            'all_done' => $tasks !== [] && $done === count($tasks),
        ];
    }

    /**
     * Dismiss it, for this person only and for good. Idempotent: skipping
     * twice is skipping once, and the second call must not move the stamp
     * that evidences when they decided.
     *
     * @return array<string, mixed>
     */
    public function skip(MerchantUser $user): array
    {
        if ($user->onboarding_skipped_at === null) {
            $now = CarbonImmutable::now('UTC');

            // Anchor first: a person who skips before ever reading the
            // guide still has a start date, so the row is never a skip
            // hanging off nothing.
            $this->anchor($user, $now);

            MerchantUser::query()
                ->whereKey($user->getKey())
                ->whereNull('onboarding_skipped_at')
                ->update(['onboarding_skipped_at' => $now]);

            $user->onboarding_skipped_at = $now;
        }

        return $this->state($user);
    }

    /**
     * They finished the walkthrough. Deliberately NOT a skip: the tour is
     * the explaining, the tasklist is the work, and a shop that has watched
     * the tour still has not credited anyone.
     *
     * @return array<string, mixed>
     */
    public function completeTour(MerchantUser $user): array
    {
        if ($user->onboarding_tour_completed_at === null) {
            $now = CarbonImmutable::now('UTC');

            $this->anchor($user, $now);

            MerchantUser::query()
                ->whereKey($user->getKey())
                ->whereNull('onboarding_tour_completed_at')
                ->update(['onboarding_tour_completed_at' => $now]);

            $user->onboarding_tour_completed_at = $now;
        }

        return $this->state($user);
    }

    /**
     * When this person's five days started — stamped on first use and
     * never moved afterwards.
     *
     * The UPDATE carries its own `whereNull`, so two parallel first
     * requests cannot each write their own anchor: the loser changes no
     * rows and re-reads the winner's value rather than believing its own.
     */
    private function anchor(MerchantUser $user, CarbonImmutable $now): CarbonImmutable
    {
        if ($user->onboarding_started_at !== null) {
            return $user->onboarding_started_at;
        }

        $stamped = MerchantUser::query()
            ->whereKey($user->getKey())
            ->whereNull('onboarding_started_at')
            ->update(['onboarding_started_at' => $now]);

        if ($stamped === 0) {
            $existing = MerchantUser::query()
                ->whereKey($user->getKey())
                ->value('onboarding_started_at');

            if ($existing !== null) {
                $user->onboarding_started_at = $existing;

                return $user->onboarding_started_at;
            }
        }

        $user->onboarding_started_at = $now;

        return $now;
    }

    /** Whole days left, rounded up; 0 once the window has closed. */
    private function daysRemaining(CarbonImmutable $now, CarbonImmutable $expiresAt): int
    {
        if ($now->greaterThanOrEqualTo($expiresAt)) {
            return 0;
        }

        return (int) ceil(($expiresAt->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    /**
     * The five things a shop's first week is made of, each answered from
     * data.
     *
     * WHY THESE FIVE. They are the shortest path from "we signed up" to
     * "the platform works for us end to end", in the order the money moves:
     * get the store approved (nothing else is possible before it), tell us
     * where money is matched and returned, do the daily act at the counter,
     * close the loop by paying what the cashback cost, and hand the till to
     * the people who actually stand at it. The middle two are the ones the
     * owner named; the other three are what makes those two work.
     *
     * `permission` names the slug a person needs for the task to be theirs
     * to do. It is published rather than filtered on, because the client
     * already holds the signed-in account's resolved permission set (see
     * MerchantUserResource) — filtering here would cost a role lookup on
     * every page load to answer a question the reader can already answer.
     * A cashier must not be shown "add your bank account".
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(MerchantUser $user): array
    {
        $facts = $this->facts($user);

        return [
            [
                'key' => 'finish_setup',
                'label_en' => 'Finish setup and submit your store',
                'label_dv' => 'ސެޓަޕް ފުރިހަމަކުރައްވައި ފިހާރަ ހުށަހަޅުއްވާ',
                'help_en' => 'Fill in your store details, pin your shop on the map and pick your cashback rate, then submit for review. You can credit customers once Manfaa approves the store.',
                'help_dv' => 'ފިހާރައިގެ ތަފްޞީލުތައް ފުރިހަމަކުރައްވައި، ޗާޓުގައި ފިހާރަ ފާހަގަކުރައްވައި، ކޭޝްބެކް ރޭޓް ކަނޑައެޅުއްވުމަށްފަހު ރިވިއުއަށް ހުށަހަޅުއްވާ. މަންފާއިން ފިހާރަ އެޕްރޫވް ކުރުމާއެކު ކަސްޓަމަރުންނަށް ކްރެޑިޓް ދެއްވަން ފެއްޓެވޭނެއެވެ.',
                // Real state: the store has left the wizard. draft and
                // rejected are the two statuses the wizard still owns.
                'done' => ! in_array($facts['status'], OnboardingService::WIZARD_STATUSES, true),
                'permission' => 'setup.submit',
                'target' => 'setup',
                'web_path' => '/setup',
            ],
            [
                'key' => 'bank_account',
                'label_en' => 'Add your bank account',
                'label_dv' => 'ބޭންކް އެކައުންޓް އިތުރުކުރައްވާ',
                'help_en' => 'Your settlement transfers are matched against this account, and anything Manfaa returns to you goes back to it. Add the bank, the account number and the account name.',
                'help_dv' => 'ސެޓްލްމަންޓަށް ފޮނުއްވާ ފައިސާ ދިމާކުރަނީ މި އެކައުންޓާއެވެ. އަދި މަންފާއިން ފިހާރައަށް އަނބުރާ ދޭ ފައިސާ ވެސް ފޮނުވާނީ މި އެކައުންޓަށެވެ. ބޭންކާއި، އެކައުންޓް ނަންބަރާއި، އެކައުންޓްގެ ނަން ލިޔުއްވާ.',
                // Real state: the whole identity triple is on file. A half
                // identity matches no payment, so a half identity is not done.
                'done' => $facts['has_bank_account'],
                'permission' => 'bank_account.update',
                'target' => 'bank_account',
                'web_path' => '/settings/bank-account',
            ],
            [
                'key' => 'credit_customer',
                'label_en' => 'Credit your first customer',
                'label_dv' => 'ފުރަތަމަ ކަސްޓަމަރަށް ކްރެޑިޓްކުރައްވާ',
                // The code, NOT a phone number: every till on both surfaces
                // asks for `customer_code` (digits:6) and no Manfaa screen
                // has ever taken a phone number for a credit.
                'help_en' => 'At the counter, open Credit customer, ask for the customer\'s 6-digit Manfaa code — or scan the QR in their app — and key in what they spent. Manfaa works out their cashback and tells them it is coming.',
                'help_dv' => 'ކައުންޓަރުގައި ‘ކަސްޓަމަރަށް ކްރެޑިޓްކުރައްވާ’ ހުޅުއްވައި، ކަސްޓަމަރުގެ 6 އަކުރުގެ މަންފާ ކޯޑު ހޯއްދަވާ — ނުވަތަ އެ ބޭފުޅާގެ އެޕުގައިވާ QR ސްކޭންކުރައްވާ — އަދި ވިޔަފާރިކުރެއްވި އަދަދު ލިޔުއްވާ. ކޭޝްބެކް ހިސާބުކޮށް ކަސްޓަމަރަށް އަންގާނީ މަންފާއިންނެވެ.',
                // Real state: a transaction exists for this store.
                'done' => $facts['has_transaction'],
                'permission' => 'credits.create',
                'target' => 'credit',
                'web_path' => '/credit',
            ],
            [
                'key' => 'settle_bill',
                'label_en' => 'Settle your first bill',
                'label_dv' => 'ފުރަތަމަ ބިލް ސެޓްލްކުރައްވާ',
                'help_en' => 'The cashback your customers earned, plus the platform fee, is collected as one bill. Open Settlements, check the amount due now, transfer it and upload the receipt.',
                'help_dv' => 'ކަސްޓަމަރުންނަށް ލިބުނު ކޭޝްބެކާއި ޕްލެޓްފޯމް ފީ އެއްކޮށްލައިގެން ބިލެއްގެ ގޮތުގައި ދައްކަވަން ޖެހޭނެއެވެ. ‘ސެޓްލްމަންޓްތައް’ ހުޅުއްވައި، މިހާރު ދައްކަންޖެހޭ އަދަދު ބައްލަވައި، ފައިސާ ފޮނުއްވުމަށްފަހު ރަސީދު އަޕްލޯޑްކުރައްވާ.',
                // Real state: a settlement row exists for this store.
                'done' => $facts['has_settlement'],
                'permission' => 'settlements.create',
                'target' => 'settlements',
                'web_path' => '/settlements',
            ],
            [
                'key' => 'add_staff',
                'label_en' => 'Add the people who work your till',
                'label_dv' => 'ކައުންޓަރުގައި މަސައްކަތްކުރައްވާ މުވައްޒަފުން އިތުރުކުރައްވާ',
                'help_en' => 'Everyone at the counter needs their own account — never share yours. Add an employee, give them a role, and every credit carries the name of whoever keyed it.',
                'help_dv' => 'ކައުންޓަރުގައި މަސައްކަތްކުރައްވާ ކޮންމެ ބޭފުޅަކަށް ވަކި އެކައުންޓެއް ބޭނުންވާނެއެވެ — ތިޔަ ބޭފުޅާގެ އެކައުންޓް ހިއްސާނުކުރައްވާ. މުވައްޒަފެއް އިތުރުކުރައްވައި ދައުރެއް ދެއްވުމުން، ކޮންމެ ކްރެޑިޓަކާއެކު އެކަން ކުރެއްވި ބޭފުޅާގެ ނަން ފެންނާނެއެވެ.',
                // Real state: an active account other than this one.
                'done' => $facts['has_other_staff'],
                'permission' => 'staff.invite',
                'target' => 'staff',
                'web_path' => '/settings/staff',
            ],
        ];
    }

    /**
     * Every fact the five tasks need, in ONE row.
     *
     * Each EXISTS stops at the first matching row on an indexed
     * merchant_id — transactions and settlements both carry one — so this
     * costs the same whether the shop has one sale or a hundred thousand.
     * The booleans are cast to int in SQL rather than trusted to come back
     * as PHP bools: PDO's pgsql driver has answered 't'/'f' in the past,
     * and a truthy 'f' would tick a task nobody had done.
     *
     * @return array{status: string, has_bank_account: bool, has_transaction: bool, has_settlement: bool, has_other_staff: bool}
     */
    private function facts(MerchantUser $user): array
    {
        $row = DB::table('merchants as m')
            ->selectRaw('m.status as status')
            ->selectRaw(<<<'SQL'
                (coalesce(m.bank_name, '') <> ''
                    and coalesce(m.bank_account, '') <> ''
                    and coalesce(m.bank_account_name, '') <> '')::int as has_bank_account
            SQL)
            ->selectRaw('(exists (select 1 from transactions t where t.merchant_id = m.id))::int as has_transaction')
            ->selectRaw('(exists (select 1 from settlements s where s.merchant_id = m.id))::int as has_settlement')
            ->selectRaw(
                '(exists (select 1 from merchant_users u where u.merchant_id = m.id and u.id <> ? and coalesce(u.is_active, true)))::int as has_other_staff',
                [$user->getKey()],
            )
            ->where('m.id', $user->merchant_id)
            ->first();

        return [
            'status' => (string) ($row->status ?? 'draft'),
            'has_bank_account' => (int) ($row->has_bank_account ?? 0) === 1,
            'has_transaction' => (int) ($row->has_transaction ?? 0) === 1,
            'has_settlement' => (int) ($row->has_settlement ?? 0) === 1,
            'has_other_staff' => (int) ($row->has_other_staff ?? 0) === 1,
        ];
    }
}
