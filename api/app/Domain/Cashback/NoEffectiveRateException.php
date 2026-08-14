<?php

declare(strict_types=1);

namespace App\Domain\Cashback;

use App\Models\Merchant;
use App\Models\MerchantRate;
use Carbon\CarbonImmutable;
use DomainException;

final class NoEffectiveRateException extends DomainException
{
    public static function for(Merchant $merchant, CarbonImmutable $occurredAt): self
    {
        $timezone = (string) config('app.business_timezone', 'Indian/Maldives');
        $saleTime = $occurredAt->setTimezone($timezone)->format('j M Y, H:i');

        $firstEffective = MerchantRate::query()
            ->where('merchant_id', $merchant->id)
            ->orderBy('effective_from')
            ->value('effective_from');

        $hint = $firstEffective === null
            ? sprintf('%s has no cashback rate set up yet.', $merchant->name)
            : sprintf(
                '%s\'s cashback rates begin on %s.',
                $merchant->name,
                CarbonImmutable::parse($firstEffective)->setTimezone($timezone)->format('j M Y'),
            );

        return new self(sprintf(
            'No cashback rate was active at the sale time (%s). %s',
            $saleTime,
            $hint,
        ));
    }
}
