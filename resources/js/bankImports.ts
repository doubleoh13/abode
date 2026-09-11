import axios from 'axios';
import { reactive } from 'vue';
import type { Account } from './types';

export const bankImports = reactive<{
    unmatchedCountByAccount: Record<number, number>;
}>({
    unmatchedCountByAccount: {},
});

export function hasUnmatchedBankTransactions(accountId?: number): boolean {
    if (accountId !== undefined) {
        return (bankImports.unmatchedCountByAccount[accountId] ?? 0) > 0;
    }

    return Object.values(bankImports.unmatchedCountByAccount).some((count) => count > 0);
}

export function recordUnmatchedBankTransactions(accounts: Account[]): void {
    bankImports.unmatchedCountByAccount = Object.fromEntries(
        accounts.map((account) => [account.id, account.unmatched_bank_transactions_count ?? 0]),
    );
}

export function setUnmatchedBankTransactionCount(accountId: number, count: number): void {
    bankImports.unmatchedCountByAccount[accountId] = count;
}

export async function refreshUnmatchedBankTransactions(): Promise<void> {
    const response = await axios.get<{ data: Account[] }>('/api/v1/financial/accounts');

    recordUnmatchedBankTransactions(response.data.data);
}
