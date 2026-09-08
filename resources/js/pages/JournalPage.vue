<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, ref } from 'vue';
import ModalDialog from '../components/ModalDialog.vue';
import TransactionForm from '../components/TransactionForm.vue';
import { formatAmount } from '../money';
import type {
    Account,
    Commodity,
    Institution,
    JournalIssue,
    Paginated,
    Payee,
    PostingStatus,
    Transaction,
} from '../types';

const transactions = ref<Transaction[]>([]);
const page = ref(1);
const lastPage = ref(1);
const accounts = ref<Account[]>([]);
const commodities = ref<Commodity[]>([]);
const institutions = ref<Institution[]>([]);
const payees = ref<Payee[]>([]);
const issues = ref<JournalIssue[]>([]);
const issuesChecked = ref(false);
const loaded = ref(false);
const formOpen = ref(false);
const editingTransaction = ref<Transaction | null>(null);

const issuesByTransaction = computed(() => {
    const map = new Map<number, JournalIssue[]>();

    for (const issue of issues.value) {
        if (issue.financial_posting_id !== undefined) {
            continue;
        }

        map.set(issue.financial_transaction_id, [
            ...(map.get(issue.financial_transaction_id) ?? []),
            issue,
        ]);
    }

    return map;
});

const issuesByPosting = computed(() => {
    const map = new Map<number, JournalIssue[]>();

    for (const issue of issues.value) {
        if (issue.financial_posting_id !== undefined) {
            map.set(issue.financial_posting_id, [
                ...(map.get(issue.financial_posting_id) ?? []),
                issue,
            ]);
        }
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
    issuesChecked.value = false;
    issues.value = (
        await axios.get<{ data: JournalIssue[] }>('/api/v1/financial/journal-issues')
    ).data.data;
    issuesChecked.value = true;
}

onMounted(async () => {
    void loadIssues();

    const [accountsResponse, commoditiesResponse, institutionsResponse, payeesResponse] = await Promise.all([
        axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        axios.get<{ data: Institution[] }>('/api/v1/financial/institutions'),
        axios.get<{ data: Payee[] }>('/api/v1/financial/payees'),
        loadTransactions(),
    ]);

    accounts.value = accountsResponse.data.data;
    commodities.value = commoditiesResponse.data.data;
    institutions.value = institutionsResponse.data.data;
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

function registerPayee(payee: Payee): void {
    if (!payees.value.some((candidate) => candidate.id === payee.id)) {
        payees.value.push(payee);
    }
}

function registerAccount(account: Account): void {
    if (!accounts.value.some((candidate) => candidate.id === account.id)) {
        accounts.value.push(account);
    }
}

function closeForm(): void {
    formOpen.value = false;
    editingTransaction.value = null;
}

async function transactionSaved(): Promise<void> {
    closeForm();
    await Promise.all([loadTransactions(), loadIssues()]);
}

function accountPathAncestor(path: string | undefined): string {
    const segments = path?.split(':') ?? [];

    return segments.length > 1 ? `${segments.slice(0, -1).join(':')}:` : '';
}

function accountPathLeaf(path: string | undefined): string {
    return path?.split(':').at(-1) ?? '';
}

function statusSymbol(status: PostingStatus | null): string {
    if (status === 'pending') {
        return '○';
    }

    if (status === 'cleared') {
        return '✓';
    }

    if (status === 'reconciled') {
        return '✓✓';
    }

    return '—';
}

function statusLabel(status: PostingStatus | null): string {
    return status === null ? 'No status' : status[0].toUpperCase() + status.slice(1);
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
                    :institutions="institutions"
                    :payees="payees"
                    @saved="transactionSaved"
                    @cancelled="closeForm"
                    @payee-created="registerPayee"
                    @account-created="registerAccount"
                />
            </ModalDialog>

            <p v-if="!issuesChecked" class="mt-4 font-mono text-xs tracking-wider text-muted uppercase">
                Checking journal integrity…
            </p>

            <p v-else-if="issues.length > 0" class="mt-4 rounded-md border border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger">
                {{ issues.length }} journal {{ issues.length === 1 ? 'issue needs' : 'issues need' }} attention.
            </p>

            <p v-if="transactions.length === 0" class="mt-6 text-sm text-muted">
                No transactions yet.
            </p>

            <ul
                v-else
                class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
            >
                <li v-for="transaction in transactions" :key="transaction.id" class="group px-4 py-2">
                    <div class="flex items-center gap-4">
                        <span class="font-mono text-xs text-muted">{{ transaction.date }}</span>

                        <span
                            v-if="issuesByTransaction.has(transaction.id)"
                            class="cursor-help text-danger"
                            :title="issuesByTransaction.get(transaction.id)?.map((issue) => issue.message).join('\n')"
                        >
                            ⚠
                        </span>

                        <span
                            class="w-8 shrink-0 font-mono text-sm text-muted"
                            :title="statusLabel(transaction.status)"
                        >
                            <span aria-hidden="true">{{ statusSymbol(transaction.status) }}</span>
                            <span class="sr-only">{{ statusLabel(transaction.status) }}</span>
                        </span>

                        <span class="min-w-0 flex-1 truncate text-sm">
                            {{ transaction.payee?.name ?? transaction.memo ?? '—' }}
                            <span v-if="transaction.payee && transaction.memo" class="text-muted">
                                · {{ transaction.memo }}
                            </span>
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
                    </div>

                    <div class="mt-1 flex flex-col">
                        <div
                            v-for="posting in transaction.postings"
                            :key="posting.id"
                            class="flex items-center gap-4 py-0.5 pl-24"
                        >
                            <span
                                v-if="issuesByPosting.has(posting.id)"
                                class="-ml-6 w-6 cursor-help text-danger"
                                :title="issuesByPosting.get(posting.id)?.map((issue) => issue.message).join('\n')"
                            >
                                ⚠
                            </span>

                            <span
                                class="w-8 shrink-0 font-mono text-sm text-muted"
                                :title="posting.status !== null && posting.status !== transaction.status ? statusLabel(posting.status) : undefined"
                            >
                                <template v-if="posting.status !== null && posting.status !== transaction.status">
                                    <span aria-hidden="true">{{ statusSymbol(posting.status) }}</span>
                                    <span class="sr-only">{{ statusLabel(posting.status) }}</span>
                                </template>
                            </span>

                            <span class="min-w-0 flex-1 truncate text-sm">
                                <span class="text-muted">{{ accountPathAncestor(posting.account?.path) }}</span><span>{{ accountPathLeaf(posting.account?.path) }}</span>
                                <span v-if="posting.memo" class="text-muted"> · {{ posting.memo }}</span>
                            </span>

                            <span class="text-right font-mono text-sm">
                                {{ posting.commodity ? formatAmount(posting.amount, posting.commodity) : posting.amount }}
                            </span>
                        </div>
                    </div>
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

        <div
            v-else
            aria-hidden="true"
            class="mt-6 animate-pulse divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
        >
            <div v-for="row in 6" :key="row" class="px-4 py-2">
                <div class="flex items-center gap-4">
                    <span class="h-3 w-20 rounded-sm bg-edge/60"></span>
                    <span class="h-3 w-4 rounded-sm bg-edge/60"></span>
                    <span class="h-3 w-64 rounded-sm bg-edge/60"></span>
                </div>
                <div class="mt-2.5 flex flex-col gap-2 pb-1 pl-24">
                    <div v-for="line in 2" :key="line" class="flex items-center justify-between">
                        <span class="h-3 w-72 rounded-sm bg-edge/60"></span>
                        <span class="h-3 w-24 rounded-sm bg-edge/60"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
