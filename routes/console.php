<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fund and 529 NAVs post the evening before, and 05:00 sits outside the DST changeover window.
Schedule::command('financial:fetch-prices')
    ->dailyAt('05:00')
    // The scheduler discards command output, so without this a failed run leaves no trace in the container logs.
    ->onFailure(function (Stringable $output): void {
        Log::error('financial:fetch-prices failed', ['output' => trim((string) $output)]);
    });
