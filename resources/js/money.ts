import type { Commodity } from './types';

export function parseAmount(input: string, precision: number): number | null {
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

    if (value > BigInt(Number.MAX_SAFE_INTEGER) || value < -BigInt(Number.MAX_SAFE_INTEGER)) {
        return null;
    }

    return Number(value);
}

export function amountToInput(minor: number, precision: number): string {
    const digits = String(Math.abs(minor)).padStart(precision + 1, '0');
    const whole = precision > 0 ? digits.slice(0, -precision) : digits;
    const fraction = precision > 0 ? `.${digits.slice(-precision)}` : '';

    return `${minor < 0 ? '-' : ''}${whole}${fraction}`;
}

export function formatAmount(minor: number, commodity: Commodity): string {
    const digits = String(Math.abs(minor)).padStart(commodity.precision + 1, '0');
    const whole = (
        commodity.precision > 0 ? digits.slice(0, -commodity.precision) : digits
    ).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const fraction = commodity.precision > 0 ? `.${digits.slice(-commodity.precision)}` : '';
    const sign = minor < 0 ? '-' : '';
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
export function allocateBasis(cost: number, totalQuantity: number, quantities: number[]): number[] {
    const shares = quantities.map((quantity) => Math.floor((cost * quantity) / totalQuantity));
    const total = quantities.reduce((sum, quantity) => sum + quantity, 0);
    let shortfall = Math.floor((cost * total) / totalQuantity) - shares.reduce((sum, share) => sum + share, 0);

    const order = quantities
        .map((quantity, index) => ({ index, remainder: (cost * quantity) % totalQuantity }))
        .toSorted((a, b) => b.remainder - a.remainder || a.index - b.index);

    for (const { index } of order) {
        if (shortfall <= 0) {
            break;
        }

        shares[index] += 1;
        shortfall -= 1;
    }

    return shares;
}
