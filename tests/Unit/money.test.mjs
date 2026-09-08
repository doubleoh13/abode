import assert from 'node:assert/strict';
import { test } from 'node:test';
import { parseAmount, formatAmount, allocateBasis, totalCostFromUnitCost, decimalToScaledInteger, scaledIntegerToDecimal } from '../../resources/js/money.ts';

const usd = { code: 'USD', display_precision: 2, symbol: '$', symbol_placement: 'prefix' };

test('decimal API values are unscaled and preserve sub-cent costs', () => {
    assert.equal(parseAmount('4040.60278'), '4040.60278');
    assert.equal(parseAmount('100.00'), '100');
    assert.equal(parseAmount('-0.00'), '0');
    assert.equal(parseAmount('0.' + '0'.repeat(24) + '1'), '0.' + '0'.repeat(24) + '1');
    assert.equal(parseAmount('9'.repeat(53) + '.' + '9'.repeat(25)), '9'.repeat(53) + '.' + '9'.repeat(25));
});

test('decimal input rejects unsupported syntax and storage overflow', () => {
    for (const input of ['1e3', '+1', '01', '1.2.3', '9'.repeat(54), '0.' + '1'.repeat(26)]) {
        assert.equal(parseAmount(input), null);
    }
});

test('display rounding does not change exact values', () => {
    assert.equal(formatAmount('100', usd), '$100.00');
    assert.equal(formatAmount('4040.60278', usd), '$4,040.60');
    assert.equal(formatAmount('1.005', usd), '$1.01');
    assert.equal(formatAmount('-1.005', usd), '-$1.01');
    assert.equal(formatAmount('-0.001', usd), '$0.00');
    assert.equal(formatAmount('999.999', usd), '$1,000.00');
    assert.equal(formatAmount('4040.60278', { ...usd, display_precision: 5 }), '$4,040.60278');
    assert.equal(formatAmount('9007199254740993.12', usd), '$9,007,199,254,740,993.12');
});

test('allocation matches server rounding at 25 fractional places', () => {
    assert.deepEqual(allocateBasis('100', '7', ['3', '2', '2']), [
        '42.8571428571428571428571428', '28.5714285714285714285714286', '28.5714285714285714285714286',
    ]);
    assert.deepEqual(allocateBasis('4040.60278', '426.674', ['426.674']), ['4040.60278']);
});

test('unit cost multiplication is exact and rejects rounding or overflow', () => {
    assert.equal(totalCostFromUnitCost('9.47', '426.674'), '4040.60278');
    assert.equal(totalCostFromUnitCost('0.' + '0'.repeat(24) + '1', '0.1'), null);
    assert.equal(totalCostFromUnitCost('9'.repeat(53), '2'), null);
});

test('decimal balance arithmetic preserves the smallest residual', () => {
    const residual = decimalToScaledInteger('4040.6027800000000000000000001') - decimalToScaledInteger('4040.60278');
    assert.equal(scaledIntegerToDecimal(residual), '0.0000000000000000000000001');
});
