<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';
    case Equity = 'equity';

    /**
     * The top-level segment of an account path: "expenses:food:dining-out".
     */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::Asset => 'assets',
            self::Liability => 'liabilities',
            self::Income => 'income',
            self::Expense => 'expenses',
            self::Equity => 'equity',
        };
    }
}
