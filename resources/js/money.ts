import type { Commodity } from './types';

const decimalPlaces = 25;
const decimalScale = 10n ** BigInt(decimalPlaces);

export function decimalToScaledInteger(value: string): bigint {
    const match = value.match(/^(-?)(\d+)(?:\.(\d{1,25}))?$/);

    if (!match) {
        throw new Error('Invalid decimal value.');
    }

    const [, sign, whole, fraction = ''] = match;
    const magnitude = BigInt(whole) * decimalScale + BigInt(fraction.padEnd(decimalPlaces, '0'));

    return sign === '-' ? -magnitude : magnitude;
}

export function scaledIntegerToDecimal(value: bigint): string {
    const digits = (value < 0n ? -value : value).toString().padStart(decimalPlaces + 1, '0');
    const fraction = digits.slice(-decimalPlaces).replace(/0+$/, '');

    return `${value < 0n ? '-' : ''}${digits.slice(0, -decimalPlaces)}${fraction ? `.${fraction}` : ''}`;
}

export function parseAmount(input: string): string | null {
    const value = input.trim();

    if (!/^-?(?:0|[1-9][0-9]{0,52})(?:\.[0-9]{1,25})?$/.test(value)) {
        return null;
    }

    return scaledIntegerToDecimal(decimalToScaledInteger(value));
}

export function formatAmount(amount: string, commodity: Commodity): string {
    const value = decimalToScaledInteger(amount);
    const magnitude = value < 0n ? -value : value;
    const divisor = 10n ** BigInt(decimalPlaces - commodity.display_precision);
    const rounded = magnitude / divisor + (magnitude % divisor * 2n >= divisor ? 1n : 0n);
    const digits = rounded.toString().padStart(commodity.display_precision + 1, '0');
    const whole = (commodity.display_precision > 0 ? digits.slice(0, -commodity.display_precision) : digits)
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = commodity.display_precision > 0 ? `.${digits.slice(-commodity.display_precision)}` : '';
    const sign = value < 0n && rounded !== 0n ? '-' : '';
    const number = `${whole}${fraction}`;

    if (commodity.symbol && commodity.symbol_placement === 'prefix') {
        return `${sign}${commodity.symbol}${number}`;
    }

    if (commodity.symbol && commodity.symbol_placement === 'suffix') {
        return `${sign}${number}${commodity.symbol}`;
    }

    return `${sign}${number} ${commodity.code}`;
}

/** Allocate at 25 fractional places, distributing remainders in the same order as the server. */
export function allocateBasis(cost: string, totalQuantity: string, quantities: string[]): string[] {
    const costValue = decimalToScaledInteger(cost);
    const totalQuantityValue = decimalToScaledInteger(totalQuantity);
    const quantityValues = quantities.map(decimalToScaledInteger);
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

    return shares.map(scaledIntegerToDecimal);
}

export function subtractAmounts(minuend: string, subtrahend: string): string {
    return scaledIntegerToDecimal(decimalToScaledInteger(minuend) - decimalToScaledInteger(subtrahend));
}

export function marketValue(quantity: string, commodity: Commodity): string | null {
    if (commodity.kind === 'currency') {
        return quantity;
    }

    if (!commodity.latest_price) {
        return null;
    }

    return totalCostFromUnitCost(commodity.latest_price, quantity);
}

export function totalCostFromUnitCost(unitCost: string, quantity: string): string | null {
    const numerator = decimalToScaledInteger(unitCost) * decimalToScaledInteger(quantity);

    if (numerator % decimalScale !== 0n) {
        return null;
    }

    return parseAmount(scaledIntegerToDecimal(numerator / decimalScale));
}
