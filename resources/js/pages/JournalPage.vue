<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import ComboBox from '../components/ComboBox.vue';
import MergeDialog from '../components/MergeDialog.vue';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationBar from '../components/PaginationBar.vue';
import TransactionForm from '../components/TransactionForm.vue';
import {
    accountPathAncestor,
    accountPathLeaf,
    nextStatus,
    statusClass,
    statusLabel,
    statusSymbol,
} from '../journal';
import { formatAmount } from '../money';
import type {
    Account,
    Commodity,
    Institution,
    JournalIssue,
    Paginated,
    Payee,
    Posting,
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
type StatusFilterMode = 'include' | 'exclude';

const filterAccountId = ref<number | null>(null);
const statusFilterModes = ref<Partial<Record<PostingStatus, StatusFilterMode>>>({});
const filterFrom = ref('');
const filterTo = ref('');
const searchQuery = ref('');

const accountOptions = computed(() =>
    accounts.value.map((account) => ({ value: account.id, label: account.path })),
);

const statusOptions: Array<{ value: PostingStatus; label: string }> = [
    { value: 'pending', label: 'Pending' },
    { value: 'cleared', label: 'Cleared' },
    { value: 'reconciled', label: 'Reconciled' },
];

const includedStatuses = computed(() => statusesInMode('include'));
const excludedStatuses = computed(() => statusesInMode('exclude'));

const hasActiveFilters = computed(
    () =>
        filterAccountId.value !== null ||
        includedStatuses.value.length > 0 ||
        excludedStatuses.value.length > 0 ||
        filterFrom.value !== '' ||
        filterTo.value !== '' ||
        searchQuery.value !== '',
);

function statusesInMode(mode: StatusFilterMode): PostingStatus[] {
    return statusOptions
        .map((option) => option.value)
        .filter((status) => statusFilterModes.value[status] === mode);
}

function nextStatusFilterMode(mode: StatusFilterMode | undefined): StatusFilterMode | undefined {
    if (mode === undefined) {
        return 'include';
    }

    return mode === 'include' ? 'exclude' : undefined;
}

function cycleStatusFilter(status: PostingStatus): void {
    statusFilterModes.value = {
        ...statusFilterModes.value,
        [status]: nextStatusFilterMode(statusFilterModes.value[status]),
    };
}

function statusFilterHint(status: PostingStatus): string {
    const mode = statusFilterModes.value[status];

    if (mode === 'include') {
        return 'Included — click to exclude';
    }

    return mode === 'exclude' ? 'Excluded — click to clear' : 'Click to include';
}
const formOpen = ref(false);
const editingTransaction = ref<Transaction | null>(null);
const duplicatingTransaction = ref<Transaction | null>(null);
const mergeSource = ref<Transaction | null>(null);
const mergeTarget = ref<Transaction | null>(null);

const issuesByTransaction = computed(() => {
    const map = new Map<number, JournalIssue[]>();

    for (const issue of issues.value) {
        if (issue.financial_posting_id !== undefined || issue.financial_transaction_id === undefined) {
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
            params: {
                page: page.value,
                financial_account_id: filterAccountId.value ?? undefined,
                status: includedStatuses.value.length > 0 ? includedStatuses.value : undefined,
                exclude_status: excludedStatuses.value.length > 0 ? excludedStatuses.value : undefined,
                from: filterFrom.value || undefined,
                to: filterTo.value || undefined,
                search: searchQuery.value || undefined,
            },
        })
    ).data;

    transactions.value = response.data;
    lastPage.value = response.meta.last_page;
}

async function applyFilters(): Promise<void> {
    page.value = 1;
    await loadTransactions();
}

watch([filterAccountId, statusFilterModes, filterFrom, filterTo], () => {
    void applyFilters();
});

let searchDebounce: number | undefined;

watch(searchQuery, () => {
    window.clearTimeout(searchDebounce);
    searchDebounce = window.setTimeout(() => void applyFilters(), 300);
});

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
    duplicatingTransaction.value = null;
    formOpen.value = true;
}

function openEditForm(transaction: Transaction): void {
    editingTransaction.value = transaction;
    duplicatingTransaction.value = null;
    formOpen.value = true;
}

function openDuplicateForm(transaction: Transaction): void {
    editingTransaction.value = null;
    duplicatingTransaction.value = transaction;
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
    duplicatingTransaction.value = null;
}

async function transactionSaved(): Promise<void> {
    closeForm();
    await Promise.all([loadTransactions(), loadIssues()]);
}

async function flipStatus(transaction: Transaction, posting: Posting): Promise<void> {
    if (posting.status === null) {
        return;
    }

    await axios.patch(`/api/v1/financial/postings/${posting.id}`, {
        status: nextStatus(posting.status),
    });

    const refreshed = (
        await axios.get<{ data: Transaction }>(`/api/v1/financial/transactions/${transaction.id}`)
    ).data.data;
    const index = transactions.value.findIndex((candidate) => candidate.id === transaction.id);

    if (index !== -1) {
        transactions.value[index] = refreshed;
    }
}

function startMerge(transaction: Transaction): void {
    mergeSource.value = transaction;
    mergeTarget.value = null;
}

function cancelMerge(): void {
    mergeSource.value = null;
    mergeTarget.value = null;
}

function pickMergeTarget(transaction: Transaction): void {
    if (mergeSource.value === null || transaction.id === mergeSource.value.id) {
        return;
    }

    mergeTarget.value = transaction;
}

async function transactionsMerged(): Promise<void> {
    cancelMerge();
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
                    :key="editingTransaction?.id ?? (duplicatingTransaction ? `duplicate-${duplicatingTransaction.id}` : 'new')"
                    :transaction="editingTransaction"
                    :duplicate-of="duplicatingTransaction"
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

            <ModalDialog :open="mergeSource !== null && mergeTarget !== null" @close="cancelMerge">
                <MergeDialog
                    v-if="mergeSource && mergeTarget"
                    :key="`${mergeSource.id}-${mergeTarget.id}`"
                    :first="mergeSource"
                    :second="mergeTarget"
                    :commodities="commodities"
                    @merged="transactionsMerged"
                    @cancelled="cancelMerge"
                />
            </ModalDialog>

            <div
                v-if="mergeSource"
                class="mt-4 flex items-center justify-between gap-4 rounded-md border border-accent/40 bg-accent/10 px-4 py-2 text-sm"
            >
                <span>
                    Merging <span class="font-mono text-xs">{{ mergeSource.date }}</span>
                    {{ mergeSource.payee?.name ?? mergeSource.memo ?? '' }}. Click the other transaction.
                </span>
                <button type="button" class="font-mono text-xs tracking-wider uppercase hover:text-foreground" @click="cancelMerge">
                    Cancel
                </button>
            </div>

            <p v-if="!issuesChecked" class="mt-4 font-mono text-xs tracking-wider text-muted uppercase">
                Checking journal integrity…
            </p>

            <p v-else-if="issues.length > 0" class="mt-4 rounded-md border border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger">
                {{ issues.length }} journal {{ issues.length === 1 ? 'issue needs' : 'issues need' }} attention.
            </p>

            <div class="mt-6 flex flex-wrap items-end gap-3">
                <label class="flex min-w-64 flex-1 flex-col gap-1.5">
                    <span class="field-label">Account</span>
                    <ComboBox v-model="filterAccountId" :options="accountOptions" nullable null-label="(all)" fuzzy />
                </label>

                <div class="flex flex-col gap-1.5">
                    <span class="field-label">Status</span>
                    <div class="flex gap-2">
                        <button
                            v-for="option in statusOptions"
                            :key="option.value"
                            type="button"
                            class="button-subtle"
                            :class="{
                                'border-accent text-accent hover:text-accent': statusFilterModes[option.value] === 'include',
                                'border-danger text-danger line-through hover:text-danger': statusFilterModes[option.value] === 'exclude',
                            }"
                            :aria-pressed="statusFilterModes[option.value] === 'include' ? 'true' : statusFilterModes[option.value] === 'exclude' ? 'mixed' : 'false'"
                            :title="statusFilterHint(option.value)"
                            @click="cycleStatusFilter(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <label class="flex flex-col gap-1.5">
                    <span class="field-label">From</span>
                    <input v-model="filterFrom" type="date" class="input" />
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="field-label">To</span>
                    <input v-model="filterTo" type="date" class="input" />
                </label>

                <label class="flex min-w-48 flex-1 flex-col gap-1.5">
                    <span class="field-label">Search</span>
                    <input v-model="searchQuery" type="search" class="input" placeholder="Payee or memo" />
                </label>
            </div>

            <p v-if="transactions.length === 0" class="mt-6 text-sm text-muted">
                {{ hasActiveFilters ? 'No matching transactions.' : 'No transactions yet.' }}
            </p>

            <ul
                v-else
                class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
            >
                <li
                    v-for="transaction in transactions"
                    :key="transaction.id"
                    class="group px-4 py-2"
                    :class="{
                        'cursor-pointer transition-colors hover:bg-edge/40': mergeSource && mergeSource.id !== transaction.id,
                        'bg-accent/10': mergeSource?.id === transaction.id,
                    }"
                    @click="pickMergeTarget(transaction)"
                >
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1" :class="{ italic: transaction.status === 'pending' }">
                        <span class="font-mono text-xs whitespace-nowrap text-muted">{{ transaction.date }}</span>

                        <span
                            v-if="transaction.financial_recurring_transaction_id !== null"
                            class="cursor-help font-mono text-xs text-muted"
                            title="Posted from a schedule"
                        >
                            ↻
                        </span>

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

                        <span class="flex basis-full items-center justify-end gap-3 font-mono text-xs text-muted not-italic sm:basis-auto">
                            <button
                                type="button"
                                class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-foreground"
                                @click.stop="openEditForm(transaction)"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-foreground"
                                @click.stop="openDuplicateForm(transaction)"
                            >
                                Duplicate
                            </button>
                            <button
                                type="button"
                                class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-foreground"
                                @click.stop="startMerge(transaction)"
                            >
                                Merge
                            </button>
                            <button
                                type="button"
                                class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-danger"
                                @click.stop="deleteTransaction(transaction)"
                            >
                                Delete
                            </button>
                        </span>

                        <span class="hidden w-8 shrink-0 sm:block"></span>
                    </div>

                    <div class="mt-1 flex flex-col">
                        <div
                            v-for="posting in transaction.postings"
                            :key="posting.id"
                            class="flex items-center gap-4 py-0.5 pl-6 sm:pl-24"
                            :class="{
                                italic:
                                    transaction.status === 'pending' &&
                                    posting.status !== 'cleared' &&
                                    posting.status !== 'reconciled',
                            }"
                        >
                            <span
                                v-if="issuesByPosting.has(posting.id)"
                                class="-ml-6 w-6 cursor-help text-danger"
                                :title="issuesByPosting.get(posting.id)?.map((issue) => issue.message).join('\n')"
                            >
                                ⚠
                            </span>

                            <span class="min-w-0 flex-1 truncate text-sm">
                                <span class="text-muted">{{ accountPathAncestor(posting.account?.path) }}</span><span>{{ accountPathLeaf(posting.account?.path) }}</span>
                                <span v-if="posting.memo" class="text-muted"> · {{ posting.memo }}</span>
                            </span>

                            <span class="text-right font-mono text-sm">
                                {{ posting.commodity ? formatAmount(posting.amount, posting.commodity) : posting.amount }}
                            </span>

                            <button
                                v-if="posting.status !== null"
                                type="button"
                                class="w-8 shrink-0 rounded-sm text-right font-mono text-sm transition-colors hover:bg-edge/60 hover:text-foreground"
                                :class="statusClass(posting.status)"
                                :title="`${statusLabel(posting.status)} — click to mark ${statusLabel(nextStatus(posting.status)).toLowerCase()}`"
                                @click.stop="flipStatus(transaction, posting)"
                            >
                                <span aria-hidden="true">{{ statusSymbol(posting.status) }}</span>
                                <span class="sr-only">{{ statusLabel(posting.status) }}</span>
                            </button>

                            <span v-else class="w-8 shrink-0"></span>
                        </div>
                    </div>
                </li>
            </ul>

            <PaginationBar :page="page" :last-page="lastPage" @change="changePage" />
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
                <div class="mt-2.5 flex flex-col gap-2 pb-1 pl-6 sm:pl-24">
                    <div v-for="line in 2" :key="line" class="flex items-center justify-between">
                        <span class="h-3 w-72 rounded-sm bg-edge/60"></span>
                        <span class="h-3 w-24 rounded-sm bg-edge/60"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
