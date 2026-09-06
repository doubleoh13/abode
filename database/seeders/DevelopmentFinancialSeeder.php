<?php

namespace Database\Seeders;

use App\Enums\Financial\AccountType;
use App\Enums\Financial\CommodityKind;
use App\Enums\Financial\PostingStatus;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\CommodityPrice;
use App\Models\Financial\Institution;
use App\Models\Financial\Lot;
use App\Models\Financial\Payee;
use App\Models\Financial\Posting;
use App\Models\Financial\Transaction;
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
        $this->seedPayees();
        $this->seedJournal();
    }

    private function seedPayees(): void
    {
        foreach ([
            'Kroger',
            'Costco',
            'Amazon',
            'Shell',
            'Netflix',
            'City Utilities',
            'State Farm',
            'Chipotle',
        ] as $name) {
            Payee::query()->create(['name' => $name]);
        }
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
            'capital-gains' => [],
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
                'financial_institution_id' => isset($definition['institution']) ? $definition['institution']->id : null,
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
                'financial_commodity_id' => $house->id,
                'price' => $appraisal,
                'priced_at' => now()->modify($when)->setTime(12, 0),
            ]);
        }
    }

    private function seedJournal(): void
    {
        $usd = Commodity::query()->where('code', 'USD')->firstOrFail();
        $fbtc = Commodity::query()->where('code', 'FBTC')->firstOrFail();

        $checking = $this->accountNamed('checking');
        $brokerage = $this->accountNamed('brokerage');
        $creditCard = $this->accountNamed('credit-card');
        $salary = $this->accountNamed('salary');
        $capitalGains = $this->accountNamed('capital-gains');
        $groceries = $this->accountNamed('groceries');
        $diningOut = $this->accountNamed('dining-out');
        $utilities = $this->accountNamed('utilities');

        $kroger = Payee::query()->where('name', 'Kroger')->firstOrFail();
        $cityUtilities = Payee::query()->where('name', 'City Utilities')->firstOrFail();
        $chipotle = Payee::query()->where('name', 'Chipotle')->firstOrFail();

        for ($daysAgo = 90; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo);

            if (in_array($date->day, [1, 15], true)) {
                $this->createJournalTransaction($daysAgo, null, 'Paycheck', [
                    [$checking, $usd, 260_000, null],
                    [$salary, $usd, -260_000, null],
                ]);
            }

            if ($date->isSaturday()) {
                $amount = fake()->numberBetween(80_00, 160_00);
                $this->createJournalTransaction($daysAgo, $kroger, null, [
                    [$checking, $usd, -$amount, null],
                    [$groceries, $usd, $amount, null],
                ]);
            }

            if ($date->day === 5) {
                $this->createJournalTransaction($daysAgo, $cityUtilities, null, [
                    [$checking, $usd, -145_50, null],
                    [$utilities, $usd, 145_50, null],
                ]);
            }

            if ($date->day % 9 === 0) {
                $amount = fake()->numberBetween(12_00, 48_00);
                $this->createJournalTransaction($daysAgo, $chipotle, null, [
                    [$creditCard, $usd, -$amount, null],
                    [$diningOut, $usd, $amount, null],
                ]);
            }

            if ($date->day === 20) {
                $this->createJournalTransaction($daysAgo, null, 'Card payment', [
                    [$checking, $usd, -500_00, null],
                    [$creditCard, $usd, 500_00, null],
                ]);
            }
        }

        $lot = Lot::query()->create([
            'financial_commodity_id' => $fbtc->id,
            'acquired_at' => now()->subDays(45)->toDateString(),
            'cost' => 425_000,
            'metadata' => [],
        ]);

        $this->createJournalTransaction(45, null, 'Buy FBTC', [
            [$brokerage, $fbtc, 50_000_000, $lot->id],
            [$checking, $usd, -425_000, null],
        ]);

        $this->createJournalTransaction(10, null, 'Sell FBTC', [
            [$brokerage, $fbtc, -20_000_000, $lot->id],
            [$checking, $usd, 190_000, null],
            [$capitalGains, $usd, -20_000, null],
        ]);
    }

    private function accountNamed(string $name): Account
    {
        return Account::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @param  list<array{Account, Commodity, int, int|null}>  $legs
     */
    private function createJournalTransaction(int $daysAgo, ?Payee $payee, ?string $memo, array $legs): void
    {
        $status = match (true) {
            $daysAgo > 30 => PostingStatus::Reconciled,
            $daysAgo > 2 => PostingStatus::Cleared,
            default => PostingStatus::Pending,
        };

        $transaction = Transaction::query()->create([
            'date' => now()->subDays($daysAgo)->toDateString(),
            'financial_payee_id' => $payee?->id,
            'memo' => $memo,
            'metadata' => [],
        ]);

        foreach ($legs as $position => [$account, $commodity, $amount, $lotId]) {
            Posting::query()->create([
                'financial_transaction_id' => $transaction->id,
                'position' => $position,
                'status' => $status,
                'financial_account_id' => $account->id,
                'financial_commodity_id' => $commodity->id,
                'financial_lot_id' => $lotId,
                'amount' => $amount,
                'memo' => null,
                'metadata' => [],
            ]);
        }
    }

    private function seedDailyPrices(Commodity $commodity, float $startingPrice, float $maximumDailyMovePercent = 3.0): void
    {
        $price = $startingPrice;

        for ($daysAgo = 90; $daysAgo >= 0; $daysAgo--) {
            $price *= 1 + fake()->randomFloat(4, -$maximumDailyMovePercent, $maximumDailyMovePercent) / 100;

            CommodityPrice::query()->create([
                'financial_commodity_id' => $commodity->id,
                'price' => round($price, 6),
                'priced_at' => now()->subDays($daysAgo)->setTime(16, 0),
            ]);
        }
    }
}
