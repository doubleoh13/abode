<?php

namespace App\Enums;

enum CommodityKind: string
{
    case Currency = 'currency';
    case Traded = 'traded';
    case Custom = 'custom';
}
