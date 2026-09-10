<?php

namespace App\Enums\Financial;

use Carbon\CarbonImmutable;

enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * The anchor shifted by whole periods. Month and year steps clamp to the
     * last day of the target month so a schedule anchored on the 31st never
     * drifts to the 28th.
     */
    public function advance(CarbonImmutable $anchor, int $periods): CarbonImmutable
    {
        return match ($this) {
            self::Daily => $anchor->addDays($periods),
            self::Weekly => $anchor->addWeeks($periods),
            self::Monthly => $anchor->addMonthsNoOverflow($periods),
            self::Yearly => $anchor->addYearsNoOverflow($periods),
        };
    }
}
