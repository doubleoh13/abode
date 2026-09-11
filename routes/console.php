<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The scheduler discards command output, so without this a failed run leaves no trace in the container logs.
$logFailedOutput = fn (string $command): Closure => function (Stringable $output) use ($command): void {
    Log::error("{$command} failed", ['output' => trim((string) $output)]);
};

// Fund and 529 NAVs post the evening before, and 05:00 sits outside the DST changeover window.
Schedule::command('financial:fetch-prices')
    ->dailyAt('05:00')
    ->onFailure($logFailedOutput('financial:fetch-prices'));

// Runs after the price fetch so the day's new postings never race it.
Schedule::command('financial:post-recurring')
    ->dailyAt('05:30')
    ->onFailure($logFailedOutput('financial:post-recurring'));

Schedule::command('financial:simplefin-sync')
    ->everyFourHours()
    ->onFailure($logFailedOutput('financial:simplefin-sync'));
