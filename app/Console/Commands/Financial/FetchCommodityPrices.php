<?php

namespace App\Console\Commands\Financial;

use App\Enums\Financial\PriceSource;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use Carbon\CarbonImmutable;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class FetchCommodityPrices extends Command
{
    protected $signature = 'financial:fetch-prices {--days=7 : How many days back to request}';

    protected $description = 'Fetch daily closing prices for commodities with a fetchable price source. '
        .'Only finalized days (before today, UTC) are stored, since price points are immutable.';

    public function handle(): int
    {
        $failures = 0;

        $commodities = Commodity::query()
            ->whereIn('price_source', [PriceSource::Yahoo, PriceSource::In529])
            ->orderBy('code')
            ->get();

        foreach ($commodities as $commodity) {
            try {
                $points = $commodity->price_source === PriceSource::Yahoo
                    ? $this->fetchYahoo($commodity->price_symbol)
                    : $this->fetchIn529($commodity->price_symbol);
            } catch (Throwable $exception) {
                report($exception);
                $this->error("{$commodity->code}: {$exception->getMessage()}");
                $failures++;

                continue;
            }

            $created = $this->storeNewPoints($commodity, $points);
            $this->line("{$commodity->code}: {$created} new price point(s)");
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Insert any fetched day the commodity does not already have, so a
     * larger --days run can also fill historical gaps.
     *
     * @param  list<array{string, string}>  $points  [date, price] pairs
     */
    private function storeNewPoints(Commodity $commodity, array $points): int
    {
        if ($points === []) {
            return 0;
        }

        $today = CarbonImmutable::now('UTC')->toDateString();
        $dates = array_column($points, 0);
        $existing = CommodityPrice::query()
            ->where('financial_commodity_id', $commodity->id)
            ->whereBetween('priced_at', [min($dates).'T00:00:00Z', max($dates).'T23:59:59Z'])
            ->pluck('priced_at')
            ->map(fn (mixed $pricedAt): string => substr((string) $pricedAt, 0, 10))
            ->flip();
        $created = 0;

        foreach ($points as [$date, $price]) {
            if ($date >= $today || isset($existing[$date])) {
                continue;
            }

            CommodityPrice::query()->create([
                'financial_commodity_id' => $commodity->id,
                'priced_at' => $date.'T00:00:00Z',
                'price' => $price,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * @return list<array{string, string}>
     */
    private function fetchYahoo(string $symbol): array
    {
        $now = CarbonImmutable::now('UTC');
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->timeout(30)
            ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                'period1' => $now->subDays((int) $this->option('days'))->startOfDay()->timestamp,
                'period2' => $now->addDay()->startOfDay()->timestamp,
                'interval' => '1d',
                'events' => 'history',
            ])
            ->throw()
            ->json();

        $result = $response['chart']['result'][0];
        $closes = $result['indicators']['quote'][0]['close'];
        $points = [];

        foreach (array_map(null, $result['timestamp'], $closes) as [$timestamp, $close]) {
            if ($close !== null) {
                $points[] = [
                    CarbonImmutable::createFromTimestampUTC($timestamp)->toDateString(),
                    number_format((float) $close, 2, '.', ''),
                ];
            }
        }

        return $points;
    }

    /**
     * @return list<array{string, string}>
     */
    private function fetchIn529(string $fundId): array
    {
        $url = 'https://www.indiana529direct.com/indtpl/fund/priceHistorySearch.cs';
        $now = CarbonImmutable::now('UTC');
        $parameters = [
            'fundId' => $fundId,
            'startDate' => $now->subDays((int) $this->option('days'))->format('m/d/Y'),
            'endDate' => $now->format('m/d/Y'),
        ];
        $client = Http::withOptions(['cookies' => new CookieJar])->timeout(30)->asForm();

        // The site requires a cookie handshake before returning results.
        $client->post($url, $parameters);
        $client->post($url, [...$parameters, '__cookieCheck' => 'true']);
        $html = $client->post($url, $parameters)->throw()->body();

        preg_match_all('#</span>\s*(\d{2}/\d{2}/\d{4})\s*</td>#', $html, $dates);
        preg_match_all('#</span>\s*(\$[\d.]+)\s*</td>#', $html, $amounts);
        $points = [];

        foreach (array_map(null, $dates[1], $amounts[1]) as [$date, $amount]) {
            if ($date === null || $amount === null) {
                continue;
            }

            $points[] = [
                CarbonImmutable::createFromFormat('m/d/Y', $date)->toDateString(),
                number_format((float) ltrim($amount, '$'), 2, '.', ''),
            ];
        }

        return $points;
    }
}
