<?php

namespace App\Support\Financial;

use Brick\Math\BigInteger;

/**
 * The single home of balance-at-cost math: base-currency legs count at face,
 * lot-bearing legs at their pro-rata share of the lot's total cost. All math
 * uses arbitrary-precision integers so atomic on-chain quantities stay exact.
 */
class CostBasisBalancer
{
    /**
     * Signed base-currency residual of a transaction's legs, in input
     * (position) order. Zero means balanced. Lot-bearing legs without a
     * usable lot (missing, or no acquired quantity) contribute nothing,
     * which lets the issue checker value broken history without dividing.
     *
     * @param  list<array{is_base: bool, amount: BigInteger|int|string, lot_key: int|string|null, lot_cost: BigInteger|int|string|null, lot_total_quantity: BigInteger|int|string|null}>  $legs
     */
    public function residual(array $legs): BigInteger
    {
        $residual = BigInteger::zero();

        /** @var array<int|string, list<int>> $lotGroups */
        $lotGroups = [];

        foreach ($legs as $index => $leg) {
            if ($leg['is_base']) {
                $residual = $residual->plus($leg['amount']);

                continue;
            }

            if ($leg['lot_key'] === null || BigInteger::of($leg['lot_total_quantity'] ?? 0)->isNegativeOrZero()) {
                continue;
            }

            $lotGroups[$leg['lot_key']][] = $index;
        }

        foreach ($lotGroups as $indices) {
            $first = $legs[$indices[0]];

            $allocations = $this->allocate(
                BigInteger::of($first['lot_cost']),
                BigInteger::of($first['lot_total_quantity']),
                array_map(fn (int $index): BigInteger => BigInteger::of($legs[$index]['amount'])->abs(), $indices),
            );

            foreach ($indices as $offset => $index) {
                $sign = BigInteger::of($legs[$index]['amount'])->getSign();
                $residual = $residual->plus($allocations[$offset]->multipliedBy($sign));
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
     * @param  list<BigInteger|int|string>  $quantities
     * @return list<BigInteger>
     */
    public function allocate(BigInteger|int|string $cost, BigInteger|int|string $totalQuantity, array $quantities): array
    {
        $cost = BigInteger::of($cost);
        $totalQuantity = BigInteger::of($totalQuantity);
        $quantities = array_map(BigInteger::of(...), $quantities);
        $shares = [];
        $remainderOrder = [];

        foreach ($quantities as $index => $quantity) {
            [$share, $remainder] = $cost->multipliedBy($quantity)->quotientAndRemainder($totalQuantity);
            $shares[$index] = $share;
            $remainderOrder[] = ['index' => $index, 'remainder' => $remainder];
        }

        $combinedQuantity = array_reduce(
            $quantities,
            fn (BigInteger $sum, BigInteger $quantity): BigInteger => $sum->plus($quantity),
            BigInteger::zero(),
        );
        $allocated = array_reduce(
            $shares,
            fn (BigInteger $sum, BigInteger $share): BigInteger => $sum->plus($share),
            BigInteger::zero(),
        );
        $target = $cost->multipliedBy($combinedQuantity)->quotient($totalQuantity);
        $shortfall = $target->minus($allocated);

        usort(
            $remainderOrder,
            fn (array $first, array $second): int => $second['remainder']->compareTo($first['remainder'])
                ?: $first['index'] <=> $second['index'],
        );

        foreach ($remainderOrder as ['index' => $index]) {
            if ($shortfall->isNegativeOrZero()) {
                break;
            }

            $shares[$index] = $shares[$index]->plus(1);
            $shortfall = $shortfall->minus(1);
        }

        return $shares;
    }
}
