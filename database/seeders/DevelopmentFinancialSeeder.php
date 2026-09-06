<?php

namespace Database\Seeders;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\CommodityKind;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use App\Models\Financial\Institution;
use Illuminate\Database\Seeder;

class DevelopmentFinancialSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        if (Account::query()->exists()) {
            return;
        }

        $fidelity = Institution::query()->create(['name' => 'Fidelity']);
        $chase = Institution::query()->create(['name' => 'Chase']);

        $this->seedAccounts($fidelity, $chase);
        $this->seedCommoditiesWithPrices();
    }

    private function seedAccounts(Institution $fidelity, Institution $chase): void
    {
        $this->createTree(AccountType::Asset, [
            'checking' => ['institution' => $chase, 'opened_at' => '2019-03-01'],
            'savings' => ['institution' => $chase],
            'brokerage' => ['institution' => $fidelity],
            'retirement' => ['institution' => $fidelity],
            'house' => [],
        ]);

        $this->createTree(AccountType::Liability, [
            'credit-card' => ['institution' => $chase],
            'mortgage' => [],
        ]);

        $this->createTree(AccountType::Income, [
            'salary' => [],
            'interest' => [],
        ]);

        $this->createTree(AccountType::Expense, [
            'food' => ['children' => ['groceries', 'dining-out']],
            'housing' => ['children' => ['utilities', 'repairs']],
            'transportation' => [],
            'entertainment' => [],
        ]);

        $this->createTree(AccountType::Equity, [
            'opening-balances' => [],
        ]);
    }

    /**
     * @param  array<string, array{institution?: Institution, opened_at?: string, children?: array<int, string>}>  $accounts
     */
    private function createTree(AccountType $accountType, array $accounts): void
    {
        foreach ($accounts as $name => $definition) {
            $parent = Account::query()->create([
                'account_type' => $accountType,
                'institution_id' => isset($definition['institution']) ? $definition['institution']->id : null,
                'name' => $name,
                'opened_at' => $definition['opened_at'] ?? null,
            ]);

            foreach ($definition['children'] ?? [] as $childName) {
                Account::query()->create([
                    'account_type' => $accountType,
                    'parent_id' => $parent->id,
                    'name' => $childName,
                ]);
            }
        }
    }

    private function seedCommoditiesWithPrices(): void
    {
        $fbtc = Commodity::query()->create([
            'code' => 'FBTC',
            'name' => 'Fidelity Wise Origin Bitcoin Fund',
            'kind' => CommodityKind::Traded,
            'precision' => 8,
        ]);
        $this->seedDailyPrices($fbtc, 85.0);

        $eth = Commodity::query()->create([
            'code' => 'ETH',
            'name' => 'Ether',
            'kind' => CommodityKind::Traded,
            'precision' => 8,
        ]);
        $this->seedDailyPrices($eth, 3200.0);

        $spaxx = Commodity::query()->create([
            'code' => 'SPAXX',
            'name' => 'Fidelity Government Money Market Fund',
            'kind' => CommodityKind::Traded,
            'precision' => 3,
        ]);
        $this->seedDailyPrices($spaxx, 1.0, 0.01);

        $house = Commodity::query()->create([
            'code' => 'HOUSE',
            'name' => 'Primary Residence',
            'kind' => CommodityKind::Custom,
            'precision' => 0,
        ]);

        foreach ([['-2 years', 380000], ['-1 year', 405000], ['-1 month', 430000]] as [$when, $appraisal]) {
            CommodityPrice::query()->create([
                'commodity_id' => $house->id,
                'price' => $appraisal,
                'priced_at' => now()->modify($when)->setTime(12, 0),
            ]);
        }
    }

    private function seedDailyPrices(Commodity $commodity, float $startingPrice, float $maximumDailyMovePercent = 3.0): void
    {
        $price = $startingPrice;

        for ($daysAgo = 90; $daysAgo >= 0; $daysAgo--) {
            $price *= 1 + fake()->randomFloat(4, -$maximumDailyMovePercent, $maximumDailyMovePercent) / 100;

            CommodityPrice::query()->create([
                'commodity_id' => $commodity->id,
                'price' => round($price, 6),
                'priced_at' => now()->subDays($daysAgo)->setTime(16, 0),
            ]);
        }
    }
}
