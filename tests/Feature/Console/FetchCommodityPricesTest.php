<?php

use App\Exceptions\Financial\PriceFetchFailed;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

function yahooChart(array $days): array
{
    return [
        'chart' => ['result' => [[
            'timestamp' => array_map(
                fn (string $date): int => CarbonImmutable::parse($date, 'UTC')->timestamp,
                array_keys($days),
            ),
            'indicators' => ['quote' => [['close' => array_values($days)]]],
        ]]],
    ];
}

test('yahoo closes fill gaps for finalized days only', function () {
    $vti = Commodity::factory()->create([
        'display_precision' => 4,
        'price_source' => 'yahoo',
        'price_symbol' => 'VTI',
    ]);
    $today = CarbonImmutable::now('UTC');
    // An existing newer point must not block the older gap day.
    CommodityPrice::factory()->create([
        'financial_commodity_id' => $vti->id,
        'priced_at' => $today->subDay()->startOfDay(),
        'price' => '12.25',
    ]);

    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response(yahooChart([
            $today->subDays(3)->toDateString() => 10.0,
            $today->subDays(2)->toDateString() => null,
            $today->subDay()->toDateString() => 12.25,
            $today->toDateString() => 99.0,
        ])),
    ]);

    $this->artisan('financial:fetch-prices')
        ->expectsOutputToContain(': 1 new price point(s)')
        ->assertSuccessful();

    $stored = CommodityPrice::query()->where('financial_commodity_id', $vti->id)->orderBy('priced_at')->get();

    expect($stored)->toHaveCount(2)
        ->and((string) $stored[0]->price)->toBe('10')
        ->and($stored[0]->priced_at->toDateString())->toBe($today->subDays(3)->toDateString());
});

test('in529 pages are scraped after the cookie handshake', function () {
    $fund = Commodity::factory()->create([
        'display_precision' => 4,
        'price_source' => 'in529',
        'price_symbol' => '4123',
    ]);
    $yesterday = CarbonImmutable::now('UTC')->subDay();
    $html = '<td><span>a</span> '.$yesterday->format('m/d/Y').' </td><td><span>b</span> $31.42 </td>';

    Http::fake(['www.indiana529direct.com/*' => Http::response($html)]);

    $this->artisan('financial:fetch-prices')->assertSuccessful();

    Http::assertSentCount(3);
    expect((string) CommodityPrice::query()->where('financial_commodity_id', $fund->id)->sole()->price)
        ->toBe('31.42');
});

test('a failing source is reported without stopping the others', function () {
    Exceptions::fake();
    Commodity::factory()->create(['price_source' => 'yahoo', 'price_symbol' => 'AAA', 'code' => 'AAA']);
    $working = Commodity::factory()->create(['price_source' => 'yahoo', 'price_symbol' => 'ZZZ', 'code' => 'ZZZ']);
    $yesterday = CarbonImmutable::now('UTC')->subDay();

    Http::fake([
        'query1.finance.yahoo.com/v8/finance/chart/AAA*' => Http::response('nope', 500),
        'query1.finance.yahoo.com/v8/finance/chart/ZZZ*' => Http::response(yahooChart([
            $yesterday->toDateString() => 5.5,
        ])),
    ]);

    $this->artisan('financial:fetch-prices')
        ->expectsOutputToContain('AAA (AAA via yahoo): HTTP request returned status code 500')
        ->assertFailed();

    Exceptions::assertReported(fn (PriceFetchFailed $failure): bool => str_starts_with($failure->getMessage(), 'AAA (AAA via yahoo)'));
    expect(CommodityPrice::query()->where('financial_commodity_id', $working->id)->count())->toBe(1);
});

test('the commodity option limits the fetch to one code', function () {
    Commodity::factory()->create(['price_source' => 'yahoo', 'price_symbol' => 'AAA', 'code' => 'AAA']);
    Commodity::factory()->create(['price_source' => 'yahoo', 'price_symbol' => 'ZZZ', 'code' => 'ZZZ']);
    Http::fake(['query1.finance.yahoo.com/*' => Http::response(yahooChart([]))]);

    $this->artisan('financial:fetch-prices', ['--commodity' => 'ZZZ'])
        ->expectsOutputToContain('ZZZ: 0 new price point(s)')
        ->doesntExpectOutputToContain('AAA')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

test('an unknown commodity code fails', function () {
    Http::fake();

    $this->artisan('financial:fetch-prices', ['--commodity' => 'NOPE'])
        ->expectsOutputToContain('NOPE: no such commodity')
        ->assertFailed();

    Http::assertNothingSent();
});

test('overwrite replaces stored prices for returned days', function () {
    $vti = Commodity::factory()->create(['price_source' => 'yahoo', 'price_symbol' => 'VTI']);
    $yesterday = CarbonImmutable::now('UTC')->subDay();
    CommodityPrice::factory()->create([
        'financial_commodity_id' => $vti->id,
        'priced_at' => $yesterday->startOfDay(),
        'price' => '12.25',
    ]);
    Http::fake([
        'query1.finance.yahoo.com/*' => Http::response(yahooChart([
            $yesterday->subDay()->toDateString() => 10.0,
            $yesterday->toDateString() => 13.0,
        ])),
    ]);

    $this->artisan('financial:fetch-prices', ['--overwrite' => true])
        ->expectsOutputToContain(': 1 new price point(s), 1 overwritten')
        ->assertSuccessful();

    $stored = CommodityPrice::query()->where('financial_commodity_id', $vti->id)->orderBy('priced_at')->get();

    expect($stored)->toHaveCount(2)
        ->and((string) $stored[1]->price)->toBe('13');
});
