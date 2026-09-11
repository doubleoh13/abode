<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

function priceFetchEvent(): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'financial:fetch-prices'));
}

test('prices are fetched daily at five in the morning Eastern', function () {
    $event = priceFetchEvent();

    expect($event->expression)->toBe('0 5 * * *')
        ->and($event->timezone)->toBe('America/New_York');
});

test('a failed price fetch logs the output the scheduler would otherwise discard', function () {
    $event = priceFetchEvent();
    file_put_contents($event->output, "AAA: nope\n");
    Log::spy();

    $event->finish(app(), 1);

    Log::shouldHaveReceived('error')->once()->with('financial:fetch-prices failed', ['output' => 'AAA: nope']);
    unlink($event->output);
});
