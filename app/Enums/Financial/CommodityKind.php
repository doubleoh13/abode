<?php

namespace App\Enums\Financial;

enum CommodityKind: string
{
    case Currency = 'currency';
    case Traded = 'traded';
    case Custom = 'custom';
}
