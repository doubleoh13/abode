import type { Commodity } from './types';

export function parseAmount(input: string, precision: number): string | null {
    const match = input.trim().match(/^(-?)(\d+)(?:\.(\d+))?$/);

    if (!match) {
        return null;
    }

    const [, sign, whole, fraction = ''] = match;

    if (fraction.length > precision) {
        return null;
    }

    const minor =
        BigInt(whole) * 10n ** BigInt(precision) + BigInt(fraction.padEnd(precision, '0') || '0');
    const value = (sign === '-' ? -1n : 1n) * minor;
    const digits = value.toString().replace('-', '');

    if (digits.length > 78) {
        return null;
    }

    return value.toString();
}

export function amountToInput(minor: string, precision: number): string {
    const value = BigInt(minor);
    const digits = (value < 0n ? -value : value).toString().padStart(precision + 1, '0');
    const whole = precision > 0 ? digits.slice(0, -precision) : digits;
    const fraction = precision > 0 ? `.${digits.slice(-precision)}` : '';

    return `${value < 0n ? '-' : ''}${whole}${fraction}`;
}

export function formatAmount(minor: string, commodity: Commodity): string {
    const value = BigInt(minor);
    const digits = (value < 0n ? -value : value).toString().padStart(commodity.precision + 1, '0');
    const whole = (
        commodity.precision > 0 ? digits.slice(0, -commodity.precision) : digits
    ).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = commodity.precision > 0 ? `.${digits.slice(-commodity.precision)}` : '';
    const sign = value < 0n ? '-' : '';
    const number = `${whole}${fraction}`;

    if (commodity.symbol && commodity.symbol_placement === 'prefix') {
        return `${sign}${commodity.symbol}${number}`;
    }

    if (commodity.symbol && commodity.symbol_placement === 'suffix') {
        return `${sign}${number}${commodity.symbol}`;
    }

    return `${sign}${number} ${commodity.code}`;
}

/**
 * Mirrors the server's pro-rata basis allocation for live balance display:
 * floor each share of the lot's total cost, then distribute the shortfall
 * against the combined target by descending remainder, ties by input order.
 */
export function allocateBasis(cost: string, totalQuantity: string, quantities: string[]): string[] {
    const costValue = BigInt(cost);
    const totalQuantityValue = BigInt(totalQuantity);
    const quantityValues = quantities.map(BigInt);
    const shares = quantityValues.map((quantity) => (costValue * quantity) / totalQuantityValue);
    const total = quantityValues.reduce((sum, quantity) => sum + quantity, 0n);
    let shortfall = (costValue * total) / totalQuantityValue - shares.reduce((sum, share) => sum + share, 0n);

    const order = quantityValues
        .map((quantity, index) => ({ index, remainder: (costValue * quantity) % totalQuantityValue }))
        .toSorted((a, b) => {
            if (a.remainder === b.remainder) {
                return a.index - b.index;
            }

            return a.remainder > b.remainder ? -1 : 1;
        });

    for (const { index } of order) {
        if (shortfall <= 0n) {
            break;
        }

        shares[index] += 1n;
        shortfall -= 1n;
    }

    return shares.map(String);
}

export function totalCostFromUnitCost(
    unitCost: string,
    quantity: string,
    quantityPrecision: number,
): string {
    const numerator = BigInt(unitCost) * BigInt(quantity);
    const divisor = 10n ** BigInt(quantityPrecision);
    const quotient = numerator / divisor;
    const remainder = numerator % divisor;

    return (remainder * 2n >= divisor ? quotient + 1n : quotient).toString();
}
