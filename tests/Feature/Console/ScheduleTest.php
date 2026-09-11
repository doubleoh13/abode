<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

test('a failed price fetch logs the output the scheduler would otherwise discard', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'financial:fetch-prices'));
    file_put_contents($event->output, "AAA: nope\n");
    Log::spy();

    $event->finish(app(), 1);

    Log::shouldHaveReceived('error')->once()->with('financial:fetch-prices failed', ['output' => 'AAA: nope']);
    unlink($event->output);
});
