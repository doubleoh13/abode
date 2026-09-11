<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

function scheduledEvent(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, $command));
}

test('prices are fetched daily at five in the morning Eastern', function () {
    $event = scheduledEvent('financial:fetch-prices');

    expect($event->expression)->toBe('0 5 * * *')
        ->and($event->timezone)->toBe('America/New_York');
});

test('recurring transactions are posted after the price fetch', function () {
    $event = scheduledEvent('financial:post-recurring');

    expect($event->expression)->toBe('30 5 * * *')
        ->and($event->timezone)->toBe('America/New_York');
});

test('a failed scheduled command logs the output the scheduler would otherwise discard', function (string $command) {
    $event = scheduledEvent($command);
    file_put_contents($event->output, "AAA: nope\n");
    Log::spy();

    $event->finish(app(), 1);

    Log::shouldHaveReceived('error')->once()->with("{$command} failed", ['output' => 'AAA: nope']);
    unlink($event->output);
})->with(['financial:fetch-prices', 'financial:post-recurring']);
