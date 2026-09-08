<?php

namespace App\Support\Financial;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

/**
 * The single home of balance-at-cost math: base-currency legs count at face,
 * lot-bearing legs at their pro-rata share of the lot's total cost. All math
 * uses exact decimals with allocation rounded down at 25 fractional places.
 */
class CostBasisBalancer
{
    /**
     * Signed base-currency residual of a transaction's legs, in input
     * (position) order. Zero means balanced. Lot-bearing legs without a
     * usable lot (missing, or no acquired quantity) contribute nothing,
     * which lets the issue checker value broken history without dividing.
     *
     * @param  list<array{is_base: bool, amount: BigDecimal|int|string, lot_key: int|string|null, lot_cost: BigDecimal|int|string|null, lot_total_quantity: BigDecimal|int|string|null}>  $legs
     */
    public function residual(array $legs): BigDecimal
    {
        $residual = BigDecimal::zero();

        /** @var array<int|string, list<int>> $lotGroups */
        $lotGroups = [];

        foreach ($legs as $index => $leg) {
            if ($leg['is_base']) {
                $residual = $residual->plus($leg['amount']);

                continue;
            }

            if ($leg['lot_key'] === null || BigDecimal::of($leg['lot_total_quantity'] ?? 0)->isNegativeOrZero()) {
                continue;
            }

            $lotGroups[$leg['lot_key']][] = $index;
        }

        foreach ($lotGroups as $indices) {
            $first = $legs[$indices[0]];

            $allocations = $this->allocate(
                BigDecimal::of($first['lot_cost']),
                BigDecimal::of($first['lot_total_quantity']),
                array_map(fn (int $index): BigDecimal => BigDecimal::of($legs[$index]['amount'])->abs(), $indices),
            );

            foreach ($indices as $offset => $index) {
                $sign = BigDecimal::of($legs[$index]['amount'])->getSign();
                $residual = $residual->plus($allocations[$offset]->multipliedBy($sign));
            }
        }

        return $residual->strippedOfTrailingZeros();
    }

    /**
     * Pro-rata allocation of one lot's total cost across quantities drawn
     * from it within one transaction: floor each share, then distribute the
     * shortfall against the combined target in units of 10^-25 by descending
     * remainder, ties broken by input order.
     *
     * @param  list<BigDecimal|int|string>  $quantities
     * @return list<BigDecimal>
     */
    public function allocate(BigDecimal|int|string $cost, BigDecimal|int|string $totalQuantity, array $quantities): array
    {
        $cost = BigDecimal::of($cost)->toScale(25)->getUnscaledValue();
        $totalQuantity = BigDecimal::of($totalQuantity)->toScale(25)->getUnscaledValue();
        $quantities = array_map(fn (BigDecimal|int|string $quantity): BigInteger => BigDecimal::of($quantity)->toScale(25)->getUnscaledValue(), $quantities);
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

        return array_map(fn (BigInteger $share): BigDecimal => BigDecimal::ofUnscaledValue($share, 25)->strippedOfTrailingZeros(), $shares);
    }
}
