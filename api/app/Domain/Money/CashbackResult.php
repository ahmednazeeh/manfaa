<?php

declare(strict_types=1);

namespace App\Domain\Money;

final readonly class CashbackResult
{
    public function __construct(
        public int $cashbackLaari,
        public int $feeLaari,
        public int $rateBp,
        public int $feeBp,
    ) {}

    public function cashback(): Laari
    {
        return Laari::of($this->cashbackLaari);
    }

    public function fee(): Laari
    {
        return Laari::of($this->feeLaari);
    }

    public function due(): Laari
    {
        return Laari::of($this->cashbackLaari + $this->feeLaari);
    }
}
