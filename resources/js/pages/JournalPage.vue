<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, ref } from 'vue';
import ModalDialog from '../components/ModalDialog.vue';
import TransactionForm from '../components/TransactionForm.vue';
import { formatAmount } from '../money';
import type {
    Account,
    Commodity,
    JournalIssue,
    Paginated,
    Payee,
    Transaction,
} from '../types';

const transactions = ref<Transaction[]>([]);
const page = ref(1);
const lastPage = ref(1);
const accounts = ref<Account[]>([]);
const commodities = ref<Commodity[]>([]);
const payees = ref<Payee[]>([]);
const issues = ref<JournalIssue[]>([]);
const loaded = ref(false);
const formOpen = ref(false);
const editingTransaction = ref<Transaction | null>(null);

const baseCurrency = computed(
    () => commodities.value.find((commodity) => commodity.code === 'USD') ?? null,
);

const issuesByTransaction = computed(() => {
    const map = new Map<number, JournalIssue[]>();

    for (const issue of issues.value) {
        map.set(issue.financial_transaction_id, [
            ...(map.get(issue.financial_transaction_id) ?? []),
            issue,
        ]);
    }

    return map;
});

async function loadTransactions(): Promise<void> {
    const response = (
        await axios.get<Paginated<Transaction>>('/api/v1/financial/transactions', {
            params: { page: page.value },
        })
    ).data;

    transactions.value = response.data;
    lastPage.value = response.meta.last_page;
}

async function loadIssues(): Promise<void> {
    issues.value = (
        await axios.get<{ data: JournalIssue[] }>('/api/v1/financial/journal-issues')
    ).data.data;
}

onMounted(async () => {
    const [accountsResponse, commoditiesResponse, payeesResponse] = await Promise.all([
        axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        axios.get<{ data: Payee[] }>('/api/v1/financial/payees'),
        loadTransactions(),
        loadIssues(),
    ]);

    accounts.value = accountsResponse.data.data;
    commodities.value = commoditiesResponse.data.data;
    payees.value = payeesResponse.data.data;
    loaded.value = true;
});

async function changePage(target: number): Promise<void> {
    page.value = target;
    await loadTransactions();
}

function openCreateForm(): void {
    editingTransaction.value = null;
    formOpen.value = true;
}

function openEditForm(transaction: Transaction): void {
    editingTransaction.value = transaction;
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    editingTransaction.value = null;
}

async function transactionSaved(): Promise<void> {
    closeForm();
    await Promise.all([loadTransactions(), loadIssues()]);
}

async function deleteTransaction(transaction: Transaction): Promise<void> {
    if (!confirm(`Delete the ${transaction.date} transaction?`)) {
        return;
    }

    try {
        await axios.delete(`/api/v1/financial/transactions/${transaction.id}`);
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 409) {
            alert(error.response.data.message);
            return;
        }

        throw error;
    }

    await Promise.all([loadTransactions(), loadIssues()]);
}

function displayTotal(transaction: Transaction): string {
    if (baseCurrency.value === null) {
        return '';
    }

    const total = (transaction.postings ?? [])
        .filter(
            (posting) =>
                posting.financial_commodity_id === baseCurrency.value?.id &&
                (posting.account?.account_type === 'asset' ||
                    posting.account?.account_type === 'liability'),
        )
        .reduce((sum, posting) => sum + posting.amount, 0);

    return formatAmount(total, baseCurrency.value);
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Journal</h1>

            <button type="button" class="button-primary" @click="openCreateForm">
                New transaction
            </button>
        </div>

        <template v-if="loaded">
            <ModalDialog :open="formOpen" @close="closeForm">
                <TransactionForm
                    :key="editingTransaction?.id ?? 'new'"
                    :transaction="editingTransaction"
                    :accounts="accounts"
                    :commodities="commodities"
                    :payees="payees"
                    @saved="transactionSaved"
                    @cancelled="closeForm"
                />
            </ModalDialog>

            <p v-if="issues.length > 0" class="mt-4 rounded-md border border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger">
                {{ issues.length }} journal {{ issues.length === 1 ? 'issue needs' : 'issues need' }} attention.
            </p>

            <p v-if="transactions.length === 0" class="mt-6 text-sm text-muted">
                No transactions yet.
            </p>

            <ul
                v-else
                class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
            >
                <li
                    v-for="transaction in transactions"
                    :key="transaction.id"
                    class="group flex items-center gap-4 px-4 py-2"
                >
                    <span class="font-mono text-xs text-muted">{{ transaction.date }}</span>

                    <span
                        v-if="issuesByTransaction.has(transaction.id)"
                        class="cursor-help text-danger"
                        :title="issuesByTransaction.get(transaction.id)?.map((issue) => issue.message).join('\n')"
                    >
                        ⚠
                    </span>

                    <span class="min-w-0 flex-1 truncate text-sm">
                        {{ transaction.payee?.name ?? transaction.memo ?? '—' }}
                        <span v-if="transaction.payee && transaction.memo" class="text-muted">
                            · {{ transaction.memo }}
                        </span>
                    </span>

                    <span class="font-mono text-xs tracking-wider text-muted uppercase">
                        {{ transaction.status }}
                    </span>

                    <span class="w-28 text-right font-mono text-sm">
                        {{ displayTotal(transaction) }}
                    </span>

                    <span class="flex items-center gap-3 font-mono text-xs text-muted">
                        <button
                            type="button"
                            class="tracking-wider uppercase opacity-0 transition-opacity group-hover:opacity-100 hover:text-foreground"
                            @click="openEditForm(transaction)"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="tracking-wider uppercase opacity-0 transition-opacity group-hover:opacity-100 hover:text-danger"
                            @click="deleteTransaction(transaction)"
                        >
                            Delete
                        </button>
                    </span>
                </li>
            </ul>

            <div v-if="lastPage > 1" class="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    class="button-subtle"
                    :disabled="page <= 1"
                    @click="changePage(page - 1)"
                >
                    Prev
                </button>

                <span class="font-mono text-xs tracking-wider text-muted uppercase">
                    Page {{ page }} / {{ lastPage }}
                </span>

                <button
                    type="button"
                    class="button-subtle"
                    :disabled="page >= lastPage"
                    @click="changePage(page + 1)"
                >
                    Next
                </button>
            </div>
        </template>
    </div>
</template>
