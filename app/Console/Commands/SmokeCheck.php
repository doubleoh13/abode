<?php

namespace App\Console\Commands;

use App\Models\Financial\Account;
use App\Models\User;
use Illuminate\Console\Command;

class SmokeCheck extends Command
{
    protected $signature = 'app:smoke';

    protected $description = 'Verify the application boots and core tables are readable. '
        .'Run after production migrations before the container starts serving.';

    public function handle(): int
    {
        $this->line('users: '.User::query()->count());
        $this->line('financial accounts: '.Account::query()->count());

        return self::SUCCESS;
    }
}
