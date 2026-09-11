<?php

namespace App\Enums\Financial;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Income = 'income';
    case Expense = 'expense';
    case Equity = 'equity';

    /**
     * The top-level segment of an account path: "Expenses:food:dining-out".
     */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::Asset => 'Assets',
            self::Liability => 'Liabilities',
            self::Income => 'Income',
            self::Expense => 'Expenses',
            self::Equity => 'Equity',
        };
    }
}
