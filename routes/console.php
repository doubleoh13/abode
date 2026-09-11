<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Midnight UTC is after the US market close, so every stored day is final.
Schedule::command('financial:fetch-prices')
    ->daily()
    // The scheduler discards command output, so without this a failed run leaves no trace in the container logs.
    ->onFailure(function (Stringable $output): void {
        Log::error('financial:fetch-prices failed', ['output' => trim((string) $output)]);
    });
