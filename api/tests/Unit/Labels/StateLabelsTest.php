<?php

declare(strict_types=1);

use App\Domain\Cashback\TransactionState;
use App\Domain\Claims\ClaimState;
use App\Domain\Payout\PayoutBatchState;
use App\Domain\Settlement\SettlementState;
use App\Models\Merchant;

/**
 * PLAN §13b task #22: no raw snake_case in rendered output.
 *
 * The panels own their own label maps, and TypeScript makes those exhaustive
 * over the api-client unions — a new state fails their typecheck until it has
 * words. The API has no compiler doing that for the OTHER surface: refusal
 * MESSAGES, which controllers hand back as `abort(409, $e->getMessage())` and
 * every panel renders verbatim in a toast. Interpolating `->value` into that
 * prose is how `payable_unfunded` reached an operator's screen.
 *
 * These tests are the missing compiler. `label()` is exhaustive by `match`,
 * so a new enum case fails immediately with an unhandled-match error; what is
 * asserted here is the part `match` cannot check — that the words are words,
 * not the key with different punctuation.
 */
it('gives every §6 transaction state words with no machine key in them', function () {
    // A single-word state ('paid', 'confirmed') legitimately reads the same
    // as its key; what must never survive is the punctuation that makes a key
    // a key.
    foreach (TransactionState::cases() as $state) {
        expect($state->label())
            ->not->toContain('_')
            ->and(trim($state->label()))->not->toBe('');
    }

    // The two the queue actually puts in a sentence.
    expect(TransactionState::PayableUnfunded->label())->toBe('payable (unfunded)')
        ->and(TransactionState::AwaitingValidation->label())->toBe('awaiting validation');
});

it('gives every settlement and payout batch state words with no machine key in them', function () {
    foreach (SettlementState::cases() as $state) {
        expect($state->label())->not->toContain('_')
            ->and(trim($state->label()))->not->toBe('');
    }

    foreach (PayoutBatchState::cases() as $state) {
        expect($state->label())->not->toContain('_')
            ->and(trim($state->label()))->not->toBe('');
    }

    expect(SettlementState::AwaitingPayment->label())->toBe('awaiting payment')
        ->and(PayoutBatchState::PartiallyFailed->label())->toBe('partially failed');
});

it('gives every claim state words with no machine key in them', function () {
    foreach (ClaimState::cases() as $state) {
        expect($state->label())->not->toContain('_')
            ->and(trim($state->label()))->not->toBe('');
    }
});

/**
 * `merchants.status` is a plain string column governed by a CHECK constraint,
 * not an enum, so nothing but this test keeps its labels honest — and an
 * unrecognised value must degrade to prose rather than print itself.
 */
it('gives every merchant lifecycle status words, and degrades unknown ones to prose', function () {
    $statuses = ['draft', 'pending_review', 'rejected', 'active', 'suspended', 'closed'];

    foreach ($statuses as $status) {
        expect(Merchant::statusLabel($status))
            ->not->toContain('_')
            ->and(trim(Merchant::statusLabel($status)))->not->toBe('');
    }

    expect(Merchant::statusLabel('pending_review'))->toBe('awaiting review')
        ->and(Merchant::statusLabel('a_status_from_the_future'))->toBe('not active')
        ->and(Merchant::statusLabel(null))->toBe('not active');
});
