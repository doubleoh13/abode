<?php

namespace App\Console\Commands\Financial;

use App\Models\Financial\Commodity;
use Illuminate\Console\Command;

class DeleteCommodityPrices extends Command
{
    protected $signature = 'financial:delete-prices {commodity : Commodity code}';

    protected $description = 'Delete every stored price point for a commodity.';

    public function handle(): int
    {
        $code = $this->argument('commodity');
        $commodity = Commodity::query()->where('code', $code)->first();

        if ($commodity === null) {
            $this->error("{$code}: no such commodity");

            return self::FAILURE;
        }

        $count = $commodity->prices()->count();

        if (! $this->confirm("Delete all {$count} price point(s) for {$code}?")) {
            return self::SUCCESS;
        }

        $commodity->prices()->delete();
        $this->line("{$code}: {$count} price point(s) deleted");

        return self::SUCCESS;
    }
}
