<?php

namespace App\Support\Financial;

/**
 * The single home of balance-at-cost math: base-currency legs count at face,
 * lot-bearing legs at their pro-rata share of the lot's total cost. All math
 * is plain 64-bit integer arithmetic; cost x quantity stays far inside the
 * signed 64-bit range at personal scale.
 */
class CostBasisBalancer
{
    /**
     * Signed base-currency residual of a transaction's legs, in input
     * (position) order. Zero means balanced. Lot-bearing legs without a
     * usable lot (missing, or no acquired quantity) contribute nothing,
     * which lets the issue checker value broken history without dividing.
     *
     * @param  list<array{is_base: bool, amount: int, lot_key: int|string|null, lot_cost: int|null, lot_total_quantity: int|null}>  $legs
     */
    public function residual(array $legs): int
    {
        $residual = 0;

        /** @var array<int|string, list<int>> $lotGroups */
        $lotGroups = [];

        foreach ($legs as $index => $leg) {
            if ($leg['is_base']) {
                $residual += $leg['amount'];

                continue;
            }

            if ($leg['lot_key'] === null || ($leg['lot_total_quantity'] ?? 0) <= 0) {
                continue;
            }

            $lotGroups[$leg['lot_key']][] = $index;
        }

        foreach ($lotGroups as $indices) {
            $first = $legs[$indices[0]];

            $allocations = $this->allocate(
                (int) $first['lot_cost'],
                (int) $first['lot_total_quantity'],
                array_map(fn (int $index): int => abs($legs[$index]['amount']), $indices),
            );

            foreach ($indices as $offset => $index) {
                $residual += ($legs[$index]['amount'] <=> 0) * $allocations[$offset];
            }
        }

        return $residual;
    }

    /**
     * Pro-rata allocation of one lot's total cost across quantities drawn
     * from it within one transaction: floor each share, then distribute the
     * shortfall against the combined target one unit at a time by descending
     * remainder, ties broken by input order.
     *
     * @param  list<int>  $quantities
     * @return list<int>
     */
    public function allocate(int $cost, int $totalQuantity, array $quantities): array
    {
        $shares = [];
        $remainders = [];

        foreach ($quantities as $index => $quantity) {
            $shares[$index] = intdiv($cost * $quantity, $totalQuantity);
            $remainders[$index] = ($cost * $quantity) % $totalQuantity;
        }

        $target = intdiv($cost * array_sum($quantities), $totalQuantity);
        $shortfall = $target - array_sum($shares);

        arsort($remainders);

        foreach (array_keys($remainders) as $index) {
            if ($shortfall <= 0) {
                break;
            }

            $shares[$index]++;
            $shortfall--;
        }

        return $shares;
    }
}
