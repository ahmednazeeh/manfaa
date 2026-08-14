<?php

declare(strict_types=1);

namespace App\Domain\Settlement;

use App\Domain\Cashback\Actor;
use App\Domain\Cashback\TransactionState;
use App\Domain\Cashback\TransitionService;
use App\Models\SettlementLine;
use Carbon\CarbonImmutable;

/**
 * The single confirm-on-allocation path shared by bank matching and wallet
 * settlement (§7: wallet runs the same path — only the funding source
 * differs). Confirmation itself posts nothing to the ledger (§8).
 */
final readonly class LineAllocator
{
    public function __construct(private TransitionService $transitions) {}

    /**
     * Marks one fully-covered line allocated and confirms its transaction
     * through the state machine — event row, confirmed_at, no silent
     * mutation. Lines are only ever allocated whole; partial allocation of a
     * line does not exist anywhere in the system.
     */
    public function allocate(SettlementLine $line, Actor $actor, CarbonImmutable $now): void
    {
        $this->transitions->transition(
            $line->transaction,
            TransactionState::Confirmed,
            $actor,
            'settlement_allocated',
            attributes: ['confirmed_at' => $now],
        );

        $line->forceFill(['allocated_at' => $now])->save();
    }
}
