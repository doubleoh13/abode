<?php

namespace App\Enums;

enum Permission: string
{
    case ViewFinances = 'view-finances';
    case ManageFinances = 'manage-finances';
}
