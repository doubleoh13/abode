<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { setPageTitle } from '../router';
import AccountForm from '../components/AccountForm.vue';
import DateInput from '../components/DateInput.vue';
import ComboBox from '../components/ComboBox.vue';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationBar from '../components/PaginationBar.vue';
import PostingStatusMenu from '../components/PostingStatusMenu.vue';
import TransactionForm from '../components/TransactionForm.vue';
import SkeletonList from '../components/SkeletonList.vue';
import {
    accountPathAncestor,
    accountPathLeaf,
} from '../journal';
import {
    allocateBasis,
    decimalToScaledInteger,
    formatAmount,
    marketValue,
    scaledIntegerToDecimal,
    subtractAmounts,
} from '../money';
import type {
    Institution,
    Account,
    AccountBalance,
    BalanceAssertion,
    BankTransaction,
    Commodity,
    JournalIssue,
    Lot,
    Paginated,
    Payee,
    Posting,
    PostingStatus,
    Transaction,
} from '../types';

const route = useRoute();

const account = ref<Account | null>(null);
const allAccounts = ref<Account[]>([]);
const institutions = ref<Institution[]>([]);
const editFormOpen = ref(false);
const balances = ref<AccountBalance[]>([]);
const lots = ref<Lot[]>([]);
const commodities = ref<Commodity[]>([]);
const postings = ref<Posting[]>([]);
const page = ref(1);
const lastPage = ref(1);
const loaded = ref(false);

const accountId = computed(() => Number(route.params.id));

const commoditiesById = computed(() => new Map(commodities.value.map((commodity) => [commodity.id, commodity])));

function lotBasisShare(lot: Lot): string {
    return allocateBasis(lot.cost, lot.acquired_quantity ?? '0', [lot.open_quantity ?? '0'])[0];
}

interface Holding {
    commodity: Commodity;
    quantity: string;
    basis: string | null;
}

const holdings = computed<Holding[]>(() => {
    const basisByCommodity = new Map<number, bigint>();

    for (const lot of lots.value) {
        basisByCommodity.set(
            lot.financial_commodity_id,
            (basisByCommodity.get(lot.financial_commodity_id) ?? 0n) +
                decimalToScaledInteger(lotBasisShare(lot)),
        );
    }

    return balances.value
        .filter((balance) => decimalToScaledInteger(balance.balance) !== 0n)
        .flatMap((balance) => {
            const commodity = commoditiesById.value.get(balance.financial_commodity_id);

            if (!commodity) {
                return [];
            }

            const basis = basisByCommodity.get(balance.financial_commodity_id);

            return [{
                commodity,
                quantity: balance.balance,
                basis: basis === undefined ? null : scaledIntegerToDecimal(basis),
            }];
        });
});

const usd = computed(() => commodities.value.find((commodity) => commodity.code === 'USD'));

const hasNonCashHoldings = computed(() => holdings.value.some((holding) => holding.commodity.code !== 'USD'));

interface BankBalance {
    value: string;
    asOf: string;
    difference: string | null;
}

const bankBalance = computed<BankBalance | null>(() => {
    const value = account.value?.simplefin_balance ?? null;
    const asOf = account.value?.simplefin_balance_date ?? null;
    const ledger = headline.value?.value ?? null;

    if (value === null || asOf === null) {
        return null;
    }

    const difference = ledger === null ? null : subtractAmounts(value, ledger);

    return { value, asOf, difference: difference !== null && decimalToScaledInteger(difference) === 0n ? null : difference };
});

interface Headline {
    label: string;
    value: string | null;
    asOf: string | null;
}

const headline = computed<Headline | null>(() => {
    if (holdings.value.length === 0) {
        return null;
    }

    let total = 0n;

    for (const holding of holdings.value) {
        const value = marketValue(holding.quantity, holding.commodity);

        if (value === null) {
            return { label: 'Market value', value: null, asOf: null };
        }

        total += decimalToScaledInteger(value);
    }

    if (!hasNonCashHoldings.value) {
        return { label: 'Balance', value: scaledIntegerToDecimal(total), asOf: null };
    }

    const priceDates = holdings.value
        .filter((holding) => holding.commodity.kind !== 'currency')
        .map((holding) => holding.commodity.latest_priced_at)
        .filter((date): date is string => date !== undefined)
        .sort();

    return { label: 'Market value', value: scaledIntegerToDecimal(total), asOf: priceDates[0] ?? null };
});

const openLots = computed(() =>
    lots.value.filter((lot) => decimalToScaledInteger(lot.open_quantity ?? '0') > 0n),
);

const openLotsByCommodity = computed(() => {
    const map = new Map<number, Lot[]>();

    for (const lot of openLots.value) {
        map.set(lot.financial_commodity_id, [...(map.get(lot.financial_commodity_id) ?? []), lot]);
    }

    return map;
});

const expandedCommodities = ref<number[]>([]);

function toggleLots(commodityId: number): void {
    expandedCommodities.value = expandedCommodities.value.includes(commodityId)
        ? expandedCommodities.value.filter((candidate) => candidate !== commodityId)
        : [...expandedCommodities.value, commodityId];
}

function counterPostings(posting: Posting): Posting[] {
    return (posting.transaction?.postings ?? []).filter(
        (candidate) => candidate.id !== posting.id && candidate.financial_account_id !== posting.financial_account_id,
    );
}

function counterAccountLabel(posting: Posting): string {
    const counters = counterPostings(posting);

    if (counters.length === 0) {
        return (posting.transaction?.postings ?? []).length > 1 ? '(this account)' : '—';
    }

    return counters[0].account?.path ?? '—';
}

function formatCounterAmount(counter: Posting): string {
    const commodity = commoditiesById.value.get(counter.financial_commodity_id);

    return commodity ? formatAmount(counter.amount, commodity) : counter.amount;
}

const expandedCounterparties = ref(new Set<number>());

function toggleCounterparties(posting: Posting): void {
    const next = new Set(expandedCounterparties.value);

    if (!next.delete(posting.id)) {
        next.add(posting.id);
    }

    expandedCounterparties.value = next;
}

const editingTransaction = ref<Transaction | null>(null);
const creatingTransaction = ref(false);

async function transactionAdded(): Promise<void> {
    await Promise.all([loadPostings(), loadBalances(), loadBankTransactions(), loadAssertions()]);
}

async function openTransaction(posting: Posting): Promise<void> {
    if (matchingBankTransaction.value || !posting.transaction) {
        return;
    }

    const response = await axios.get<{ data: Transaction }>(`/api/v1/financial/transactions/${posting.transaction.id}`);

    editingTransaction.value = response.data.data;
}

async function bankTransactionUnmatched(): Promise<void> {
    await Promise.all([loadBankTransactions(), loadPostings()]);
}

async function transactionSaved(): Promise<void> {
    editingTransaction.value = null;
    creatingTransaction.value = false;
    await transactionAdded();
}

function hideReconciledStorageKey(): string {
    return `abode.account.${accountId.value}.hide-reconciled`;
}

function readHideReconciled(): boolean {
    try {
        return localStorage.getItem(hideReconciledStorageKey()) === '1';
    } catch {
        return false;
    }
}

const hideReconciled = ref(readHideReconciled());

async function toggleHideReconciled(): Promise<void> {
    hideReconciled.value = !hideReconciled.value;

    try {
        localStorage.setItem(hideReconciledStorageKey(), hideReconciled.value ? '1' : '0');
    } catch {
        // The preference is a convenience; a blocked store just means it does not persist.
    }

    page.value = 1;
    await loadPostings();
}

async function loadPostings(): Promise<void> {
    const response = (
        await axios.get<Paginated<Posting>>('/api/v1/financial/postings', {
            params: {
                financial_account_id: accountId.value,
                page: page.value,
                ...(hideReconciled.value ? { hide_reconciled: 1 } : {}),
            },
        })
    ).data;

    postings.value = response.data;
    lastPage.value = response.meta.last_page;
}

async function changePage(target: number): Promise<void> {
    page.value = target;
    await loadPostings();
}

function bankAmountDiffers(posting: Posting): boolean {
    return posting.bank_transaction !== null
        && posting.bank_transaction !== undefined
        && decimalToScaledInteger(posting.bank_transaction.amount) !== decimalToScaledInteger(posting.amount);
}

function bankDetail(posting: Posting): string | undefined {
    return posting.bank_transaction
        ? `${posting.bank_transaction.posted_on} · ${bankTransactionLabel(posting.bank_transaction)} · ${formatUsd(posting.bank_transaction.amount)}`
        : undefined;
}

async function setStatus(posting: Posting, status: PostingStatus): Promise<void> {
    await axios.patch(`/api/v1/financial/postings/${posting.id}`, { status });
    await loadPostings();
}

const assertions = ref<BalanceAssertion[]>([]);
const assertionIssues = ref<JournalIssue[]>([]);

const issuesByAssertion = computed(
    () => new Map(assertionIssues.value.map((issue) => [issue.financial_balance_assertion_id, issue])),
);

const bankTransactions = ref<BankTransaction[]>([]);

async function loadBankTransactions(): Promise<void> {
    const response = await axios.get<{ data: BankTransaction[] }>('/api/v1/financial/bank-transactions', {
        params: { financial_account_id: accountId.value },
    });

    bankTransactions.value = response.data.data;
}

const unmatchedBankTransactions = computed(() =>
    bankTransactions.value.filter((bankTransaction) => bankTransaction.candidate_posting_id === null),
);

const proposalsByPosting = computed(() => {
    const map = new Map<number, BankTransaction>();

    for (const bankTransaction of bankTransactions.value) {
        if (bankTransaction.candidate_posting_id !== null) {
            map.set(bankTransaction.candidate_posting_id, bankTransaction);
        }
    }

    return map;
});

function bankTransactionLabel(bankTransaction: BankTransaction): string {
    return bankTransaction.payee ?? bankTransaction.description ?? bankTransaction.external_id;
}

async function approveProposal(bankTransaction: BankTransaction): Promise<void> {
    await axios.post(`/api/v1/financial/bank-transactions/${bankTransaction.id}/match`, {
        financial_posting_id: bankTransaction.candidate_posting_id,
    });
    await Promise.all([loadBankTransactions(), loadPostings()]);
}

const payees = ref<Payee[]>([]);
const convertingBankTransaction = ref<BankTransaction | null>(null);

function registerPayee(payee: Payee): void {
    if (!payees.value.some((candidate) => candidate.id === payee.id)) {
        payees.value.push(payee);
    }
}

function registerAccount(created: Account): void {
    if (!allAccounts.value.some((candidate) => candidate.id === created.id)) {
        allAccounts.value.push(created);
    }
}

const matchingBankTransaction = ref<BankTransaction | null>(null);

function onMatchingKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        matchingBankTransaction.value = null;
    }
}

watch(matchingBankTransaction, (selected) => {
    if (selected) {
        window.addEventListener('keydown', onMatchingKeydown);
    } else {
        window.removeEventListener('keydown', onMatchingKeydown);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onMatchingKeydown);
});

function canMatch(posting: Posting): boolean {
    return matchingBankTransaction.value !== null && !posting.bank_transaction && posting.status !== null;
}

async function matchTo(posting: Posting): Promise<void> {
    const bankTransaction = matchingBankTransaction.value;

    if (!bankTransaction || !canMatch(posting)) {
        return;
    }

    try {
        await axios.post(`/api/v1/financial/bank-transactions/${bankTransaction.id}/match`, {
            financial_posting_id: posting.id,
        });
    } catch (error) {
        if (isAxiosError(error) && (error.response?.status === 422 || error.response?.status === 409)) {
            alert(error.response.data.errors?.financial_posting_id?.[0] ?? error.response.data.message);
            return;
        }

        throw error;
    } finally {
        matchingBankTransaction.value = null;
    }

    await Promise.all([loadBankTransactions(), loadPostings()]);
}

async function bankTransactionConverted(): Promise<void> {
    convertingBankTransaction.value = null;
    await Promise.all([loadBankTransactions(), loadPostings(), loadBalances()]);
}

async function rejectProposal(bankTransaction: BankTransaction): Promise<void> {
    await axios.post(`/api/v1/financial/bank-transactions/${bankTransaction.id}/reject`, {
        financial_posting_id: bankTransaction.candidate_posting_id,
    });
    await loadBankTransactions();
}

async function loadAssertions(): Promise<void> {
    const [assertionsResponse, issuesResponse] = await Promise.all([
        axios.get<{ data: BalanceAssertion[] }>('/api/v1/financial/balance-assertions', {
            params: { financial_account_id: accountId.value },
        }),
        axios.get<{ data: JournalIssue[] }>('/api/v1/financial/journal-issues'),
    ]);

    assertions.value = assertionsResponse.data.data;
    assertionIssues.value = issuesResponse.data.data.filter(
        (issue) => issue.type === 'failed_assertion' && issue.financial_account_id === accountId.value,
    );
}

const assertionFormOpen = ref(false);
const assertionForm = reactive({
    asserted_at: '',
    financial_commodity_id: null as number | null,
    balance: '',
    memo: '',
    reconcile_postings: false,
});
const assertionErrors = ref<Record<string, string[]>>({});

function openAssertionForm(): void {
    assertionForm.asserted_at = new Date().toISOString().slice(0, 10);
    assertionForm.financial_commodity_id =
        balances.value[0]?.financial_commodity_id ?? usd.value?.id ?? null;
    assertionForm.balance = '';
    assertionForm.memo = '';
    assertionForm.reconcile_postings = false;
    assertionErrors.value = {};
    assertionFormOpen.value = true;
    void prefillAssertionBalance();
}

async function prefillAssertionBalance(): Promise<void> {
    if (!assertionFormOpen.value || assertionForm.financial_commodity_id === null || !assertionForm.asserted_at) {
        return;
    }

    const rows = (
        await axios.get<{ data: AccountBalance[] }>(`/api/v1/financial/accounts/${accountId.value}/balances`, {
            params: { as_of: assertionForm.asserted_at },
        })
    ).data.data;

    assertionForm.balance =
        rows.find((row) => row.financial_commodity_id === assertionForm.financial_commodity_id)?.balance ?? '0';
}

watch(
    () => [assertionForm.asserted_at, assertionForm.financial_commodity_id],
    () => {
        void prefillAssertionBalance();
    },
);

async function saveAssertion(): Promise<void> {
    assertionErrors.value = {};

    let holds = true;

    try {
        const response = await axios.post<{ holds: boolean; reconciled_postings: number }>('/api/v1/financial/balance-assertions', {
            financial_account_id: accountId.value,
            financial_commodity_id: assertionForm.financial_commodity_id,
            asserted_at: assertionForm.asserted_at,
            balance: assertionForm.balance,
            memo: assertionForm.memo || null,
            reconcile_postings: assertionForm.reconcile_postings,
        });
        holds = response.data.holds;
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            assertionErrors.value = error.response.data.errors ?? {};
            return;
        }

        throw error;
    }

    assertionFormOpen.value = false;
    await Promise.all([loadAssertions(), loadPostings()]);

    if (assertionForm.reconcile_postings && !holds) {
        alert('The journal does not match this balance, so nothing was marked reconciled.');
    }
}

async function deleteAssertion(assertion: BalanceAssertion): Promise<void> {
    if (!confirm(`Delete the ${assertion.asserted_at} assertion?`)) {
        return;
    }

    await axios.delete(`/api/v1/financial/balance-assertions/${assertion.id}`);
    await loadAssertions();
}

type RegisterRow = { kind: 'posting'; posting: Posting } | { kind: 'assertion'; assertion: BalanceAssertion };

// With reconciled lines hidden, older assertions would stack up with nothing
// between them, so only the newest marker stays.
const visibleAssertions = computed<BalanceAssertion[]>(() => {
    if (!hideReconciled.value) {
        return assertions.value;
    }

    const newest = [...assertions.value].sort(
        (first, second) => second.asserted_at.localeCompare(first.asserted_at) || second.id - first.id,
    )[0];

    return newest ? [newest] : [];
});

const registerRows = computed<RegisterRow[]>(() => {
    if (postings.value.length === 0) {
        return visibleAssertions.value.map((assertion) => ({ kind: 'assertion', assertion }));
    }

    const newest = postings.value[0].transaction?.date ?? '';
    const oldest = postings.value[postings.value.length - 1].transaction?.date ?? '';
    // An assertion marks the end of its day, so it renders above that day's
    // postings; markers outside this page's date span stay on their own page.
    const queue = visibleAssertions.value
        .filter(
            (assertion) =>
                (page.value === 1 || assertion.asserted_at <= newest) &&
                (page.value === lastPage.value || assertion.asserted_at >= oldest),
        )
        .sort((first, second) => second.asserted_at.localeCompare(first.asserted_at));
    const rows: RegisterRow[] = [];

    for (const posting of postings.value) {
        const date = posting.transaction?.date ?? '';

        while (queue.length > 0 && queue[0].asserted_at >= date) {
            rows.push({ kind: 'assertion', assertion: queue.shift()! });
        }

        rows.push({ kind: 'posting', posting });
    }

    return [...rows, ...queue.map((assertion): RegisterRow => ({ kind: 'assertion', assertion }))];
});

const commodityOptions = computed(() =>
    commodities.value.map((commodity) => ({ value: commodity.id, label: commodity.code })),
);

function formatAssertionAmount(assertion: BalanceAssertion, amount: string): string {
    const commodity = commoditiesById.value.get(assertion.financial_commodity_id);

    return commodity ? formatAmount(amount, commodity) : amount;
}

function formatUsd(amount: string | null): string {
    return amount !== null && usd.value ? formatAmount(amount, usd.value) : '—';
}

function unrealized(market: string | null, basis: string | null): string | null {
    return market !== null && basis !== null ? subtractAmounts(market, basis) : null;
}

function holdingUnrealized(holding: Holding): string | null {
    return holding.commodity.kind === 'currency'
        ? null
        : unrealized(marketValue(holding.quantity, holding.commodity), holding.basis);
}

function lotMarketValue(lot: Lot, commodity: Commodity): string | null {
    return commodity.kind === 'currency' ? null : marketValue(lot.open_quantity ?? '0', commodity);
}

function amountClass(amount: string | null): string {
    return amount !== null && amount.startsWith('-') ? 'text-danger' : '';
}

async function loadBalances(): Promise<void> {
    const response = await axios.get<{ data: AccountBalance[] }>(`/api/v1/financial/accounts/${accountId.value}/balances`);

    balances.value = response.data.data;
}

async function loadAccount(): Promise<void> {
    loaded.value = false;
    page.value = 1;
    hideReconciled.value = readHideReconciled();

    const [accountResponse, lotsResponse, commoditiesResponse, accountsResponse, institutionsResponse, payeesResponse] = await Promise.all([
        axios.get<{ data: Account }>(`/api/v1/financial/accounts/${accountId.value}`),
        axios.get<{ data: Lot[] }>('/api/v1/financial/lots', {
            params: { financial_account_id: accountId.value },
        }),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
        axios.get<{ data: Institution[] }>('/api/v1/financial/institutions'),
        axios.get<{ data: Payee[] }>('/api/v1/financial/payees'),
        loadBalances(),
        loadPostings(),
        loadAssertions(),
        loadBankTransactions(),
    ]);

    account.value = accountResponse.data.data;
    allAccounts.value = accountsResponse.data.data;
    institutions.value = institutionsResponse.data.data;
    payees.value = payeesResponse.data.data;
    setPageTitle(account.value.path);
    lots.value = lotsResponse.data.data;
    commodities.value = commoditiesResponse.data.data;
    loaded.value = true;
}

onMounted(loadAccount);
watch(accountId, () => {
    void loadAccount();
});

const syncing = ref(false);

function formatSyncedAt(value: string): string {
    const date = new Date(value);
    const pad = (part: number): string => String(part).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

async function syncSimpleFin(): Promise<void> {
    if (!account.value) {
        return;
    }

    syncing.value = true;

    try {
        const response = await axios.post<{ data: Account }>(`/api/v1/financial/accounts/${account.value.id}/simplefin-sync`);
        account.value = response.data.data;
        await loadBankTransactions();
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 409) {
            alert(error.response.data.message);
            return;
        }

        throw error;
    } finally {
        syncing.value = false;
    }
}

async function accountSaved(): Promise<void> {
    editFormOpen.value = false;
    await loadAccount();
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-baseline gap-3">
                    <h1 class="text-xl font-semibold">
                        <span class="text-muted">{{ accountPathAncestor(account?.path) }}</span>{{ accountPathLeaf(account?.path) }}
                    </h1>

                    <span v-if="account?.simplefin_account_id" class="flex items-center gap-3 font-mono text-xs tracking-wider text-muted uppercase">
                        <span class="rounded-sm border border-accent/40 px-1.5 py-0.5 text-accent" title="Mapped to a SimpleFIN account for import">
                            SimpleFIN
                        </span>
                        <span>{{ account.simplefin_synced_at ? `synced ${formatSyncedAt(account.simplefin_synced_at)}` : 'never synced' }}</span>
                        <button
                            type="button"
                            class="tracking-wider uppercase transition-colors hover:text-foreground disabled:cursor-wait disabled:hover:text-muted"
                            :disabled="syncing"
                            @click="syncSimpleFin"
                        >
                            {{ syncing ? 'Syncing…' : 'Sync' }}
                        </button>
                    </span>
                </div>

                <div v-if="account" class="mt-1 flex flex-wrap items-center gap-3 font-mono text-xs tracking-wider text-muted uppercase">
                    <span>{{ account.account_type }}</span>
                    <span v-if="account.institution">{{ account.institution.name }}</span>
                    <span v-if="account.opened_at">opened {{ account.opened_at }}</span>
                    <span v-if="account.closed_at" class="text-danger">closed {{ account.closed_at }}</span>
                    <button type="button" class="tracking-wider uppercase transition-colors hover:text-foreground" @click="editFormOpen = true">
                        Edit
                    </button>
                </div>
            </div>

            <div v-if="loaded && headline" class="flex items-end gap-8 text-right">
                <button
                    type="button"
                    class="font-mono text-xs tracking-wider text-muted uppercase transition-colors hover:text-foreground"
                    @click="toggleHideReconciled"
                >
                    {{ hideReconciled ? 'Show reconciled' : 'Hide reconciled' }}
                </button>

                <div v-if="bankBalance">
                    <div class="font-mono text-lg tabular-nums text-accent">
                        {{ formatUsd(bankBalance.value) }}
                    </div>
                    <div class="mt-1 font-mono text-xs tracking-wider text-muted uppercase">
                        <span v-if="bankBalance.difference !== null" class="text-danger">off by {{ formatUsd(bankBalance.difference) }}</span>
                        <span v-else>{{ bankBalance.asOf }}</span>
                    </div>
                </div>

                <div>
                    <div class="font-mono text-2xl tabular-nums" :class="amountClass(headline.value)">
                        {{ formatUsd(headline.value) }}
                    </div>
                    <div class="mt-1 font-mono text-xs tracking-wider text-muted uppercase">
                        {{ headline.label }}<span v-if="headline.asOf"> · as of {{ headline.asOf }}</span>
                    </div>
                </div>
            </div>
        </div>

        <ModalDialog :open="editFormOpen" @close="editFormOpen = false">
            <AccountForm
                v-if="account"
                :key="account.id"
                :account="account"
                :accounts="allAccounts"
                :institutions="institutions"
                @saved="accountSaved"
                @cancelled="editFormOpen = false"
            />
        </ModalDialog>

        <template v-if="loaded">
            <section v-if="hasNonCashHoldings" class="mt-6">
                <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Holdings</h2>

                <div class="mt-2 overflow-x-auto rounded-md border border-edge bg-surface">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-edge font-mono text-xs tracking-wider text-muted uppercase">
                                <th class="px-4 py-2 text-left font-medium">Commodity</th>
                                <th class="px-4 py-2 text-right font-medium">Quantity</th>
                                <th class="px-4 py-2 text-right font-medium">Cost basis</th>
                                <th class="px-4 py-2 text-right font-medium">Market value</th>
                                <th class="px-4 py-2 text-right font-medium">Unrealized</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-edge">
                            <template v-for="holding in holdings" :key="holding.commodity.id">
                                <tr>
                                    <td class="px-4 py-2">
                                        <button
                                            v-if="openLotsByCommodity.has(holding.commodity.id)"
                                            type="button"
                                            class="flex items-center gap-2 text-left transition-colors hover:text-accent"
                                            :aria-expanded="expandedCommodities.includes(holding.commodity.id)"
                                            @click="toggleLots(holding.commodity.id)"
                                        >
                                            <span class="font-mono text-xs text-muted">
                                                {{ expandedCommodities.includes(holding.commodity.id) ? '▾' : '▸' }}
                                            </span>
                                            <span>
                                                {{ holding.commodity.code }}
                                                <span class="text-muted"> · {{ holding.commodity.name }}</span>
                                            </span>
                                        </button>
                                        <RouterLink
                                            v-else
                                            :to="{ name: 'finances.commodity', params: { id: holding.commodity.id } }"
                                            class="transition-colors hover:text-accent"
                                        >
                                            {{ holding.commodity.code }}
                                            <span class="text-muted"> · {{ holding.commodity.name }}</span>
                                        </RouterLink>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono">
                                        {{ formatAmount(holding.quantity, holding.commodity) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono">
                                        {{ holding.basis !== null ? formatUsd(holding.basis) : '—' }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right font-mono"
                                        :title="holding.commodity.latest_priced_at ? `priced ${holding.commodity.latest_priced_at}` : undefined"
                                    >
                                        {{ formatUsd(marketValue(holding.quantity, holding.commodity)) }}
                                    </td>
                                    <td
                                        class="px-4 py-2 text-right font-mono"
                                        :class="amountClass(holdingUnrealized(holding))"
                                    >
                                        {{ formatUsd(holdingUnrealized(holding)) }}
                                    </td>
                                </tr>
                                <tr v-if="expandedCommodities.includes(holding.commodity.id)">
                                    <td colspan="5" class="bg-background/40 px-4 py-3">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="font-mono text-xs tracking-wider text-muted uppercase">
                                                    <th class="py-1 pr-4 text-left font-medium">Acquired</th>
                                                    <th class="px-4 py-1 text-right font-medium">Open</th>
                                                    <th class="px-4 py-1 text-right font-medium">Acquired qty</th>
                                                    <th class="px-4 py-1 text-right font-medium">Basis</th>
                                                    <th class="px-4 py-1 text-right font-medium">Market value</th>
                                                    <th class="py-1 pl-4 text-right font-medium">Unrealized</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="lot in openLotsByCommodity.get(holding.commodity.id)"
                                                    :key="lot.id"
                                                >
                                                    <td class="py-1 pr-4 font-mono text-xs text-muted">
                                                        {{ lot.acquired_at }}
                                                    </td>
                                                    <td class="px-4 py-1 text-right font-mono">{{ lot.open_quantity }}</td>
                                                    <td class="px-4 py-1 text-right font-mono text-muted">
                                                        {{ lot.acquired_quantity }}
                                                    </td>
                                                    <td class="px-4 py-1 text-right font-mono">
                                                        {{ formatUsd(lotBasisShare(lot)) }}
                                                    </td>
                                                    <td class="px-4 py-1 text-right font-mono">
                                                        {{ formatUsd(lotMarketValue(lot, holding.commodity)) }}
                                                    </td>
                                                    <td
                                                        class="py-1 pl-4 text-right font-mono"
                                                        :class="amountClass(unrealized(lotMarketValue(lot, holding.commodity), lotBasisShare(lot)))"
                                                    >
                                                        {{ formatUsd(unrealized(lotMarketValue(lot, holding.commodity), lotBasisShare(lot))) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <ModalDialog :open="creatingTransaction" @close="creatingTransaction = false">
                <TransactionForm
                    v-if="creatingTransaction"
                    :transaction="null"
                    :default-account-id="accountId"
                    :accounts="allAccounts"
                    :commodities="commodities"
                    :institutions="institutions"
                    :payees="payees"
                    @saved="transactionSaved"
                    @saved-and-continued="transactionAdded"
                    @cancelled="creatingTransaction = false"
                    @payee-created="registerPayee"
                    @account-created="registerAccount"
                />
            </ModalDialog>

            <ModalDialog :open="editingTransaction !== null" @close="editingTransaction = null">
                <TransactionForm
                    v-if="editingTransaction"
                    :key="editingTransaction.id"
                    :transaction="editingTransaction"
                    :accounts="allAccounts"
                    :commodities="commodities"
                    :institutions="institutions"
                    :payees="payees"
                    @saved="transactionSaved"
                    @cancelled="editingTransaction = null"
                    @payee-created="registerPayee"
                    @account-created="registerAccount"
                    @bank-transaction-unmatched="bankTransactionUnmatched"
                />
            </ModalDialog>

            <section v-if="unmatchedBankTransactions.length > 0" class="mt-8">
                <h2 class="flex items-baseline gap-3 font-mono text-xs tracking-wider text-accent uppercase">
                    <span>{{ unmatchedBankTransactions.length }} unmatched</span>
                    <span v-if="matchingBankTransaction" class="text-muted">
                        pick the register line this settles · Esc to cancel
                    </span>
                </h2>

                <ModalDialog :open="convertingBankTransaction !== null" @close="convertingBankTransaction = null">
                    <TransactionForm
                        v-if="convertingBankTransaction"
                        :key="`bank-${convertingBankTransaction.id}`"
                        :transaction="null"
                        :bank-transaction="convertingBankTransaction"
                        :accounts="allAccounts"
                        :commodities="commodities"
                        :institutions="institutions"
                        :payees="payees"
                        @saved="bankTransactionConverted"
                        @cancelled="convertingBankTransaction = null"
                        @payee-created="registerPayee"
                        @account-created="registerAccount"
                    />
                </ModalDialog>

                <ul class="mt-2 divide-y divide-edge overflow-hidden rounded-md border border-accent/40 bg-surface">
                    <li
                        v-for="bankTransaction in unmatchedBankTransactions"
                        :key="bankTransaction.id"
                        class="group grid grid-cols-[5.5rem_minmax(0,1fr)_auto_8rem] items-center gap-x-4 px-4 py-2"
                        :class="{
                            italic: bankTransaction.pending,
                            'bg-accent/10': matchingBankTransaction?.id === bankTransaction.id,
                            'opacity-50': matchingBankTransaction && matchingBankTransaction.id !== bankTransaction.id,
                        }"
                    >
                        <span class="font-mono text-xs text-muted">{{ bankTransaction.posted_on }}</span>

                        <span class="truncate text-sm">
                            {{ bankTransaction.payee ?? bankTransaction.description ?? '—' }}
                            <span v-if="bankTransaction.payee && bankTransaction.description" class="text-muted">
                                · {{ bankTransaction.description }}
                            </span>
                            <span v-if="bankTransaction.pending" class="ml-2 font-mono text-xs tracking-wider text-muted uppercase not-italic">pending</span>
                        </span>

                        <span class="flex items-center gap-3 font-mono text-xs tracking-wider uppercase not-italic">
                            <button
                                v-if="matchingBankTransaction?.id === bankTransaction.id"
                                type="button"
                                class="text-accent transition-colors hover:text-foreground"
                                @click="matchingBankTransaction = null"
                            >
                                Cancel
                            </button>
                            <template v-else>
                                <button
                                    type="button"
                                    class="text-muted transition-opacity hover:text-foreground sm:opacity-0 sm:group-hover:opacity-100"
                                    @click="matchingBankTransaction = bankTransaction"
                                >
                                    Match
                                </button>
                                <button
                                    type="button"
                                    class="text-muted transition-opacity hover:text-foreground sm:opacity-0 sm:group-hover:opacity-100"
                                    @click="convertingBankTransaction = bankTransaction"
                                >
                                    New transaction
                                </button>
                            </template>
                        </span>

                        <span class="text-right font-mono text-sm" :class="amountClass(bankTransaction.amount)">
                            {{ formatUsd(bankTransaction.amount) }}
                        </span>
                    </li>
                </ul>
            </section>

            <section class="mt-8">
                <div class="flex items-center justify-between">
                    <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Register</h2>

                    <span class="flex items-center gap-3">
                        <button type="button" class="button-subtle" @click="openAssertionForm">
                            New assertion
                        </button>
                        <button type="button" class="button-primary" @click="creatingTransaction = true">
                            New transaction
                        </button>
                    </span>
                </div>

                <ModalDialog :open="assertionFormOpen" @close="assertionFormOpen = false">
                    <form
                        class="mx-auto flex w-80 flex-col gap-5 rounded-md border border-edge bg-surface p-5"
                        @submit.prevent="saveAssertion"
                    >
                        <h3 class="font-mono text-sm tracking-wider uppercase">Balance assertion</h3>

                        <label class="flex flex-col gap-1.5">
                            <span class="field-label">Date</span>
                            <DateInput v-model="assertionForm.asserted_at" required />
                            <p v-if="assertionErrors.asserted_at" class="text-sm text-danger">
                                {{ assertionErrors.asserted_at[0] }}
                            </p>
                        </label>

                        <div class="flex flex-col gap-1.5">
                            <span class="field-label">Commodity</span>
                            <ComboBox v-model="assertionForm.financial_commodity_id" :options="commodityOptions" />
                            <p v-if="assertionErrors.financial_commodity_id" class="text-sm text-danger">
                                {{ assertionErrors.financial_commodity_id[0] }}
                            </p>
                        </div>

                        <label class="flex flex-col gap-1.5">
                            <span class="field-label">Balance at end of day</span>
                            <input v-model="assertionForm.balance" type="text" required class="input font-mono" />
                            <p v-if="assertionErrors.balance" class="text-sm text-danger">
                                {{ assertionErrors.balance[0] }}
                            </p>
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="field-label">Memo</span>
                            <input v-model="assertionForm.memo" type="text" class="input" />
                        </label>

                        <label class="flex items-center gap-3 text-sm">
                            <input v-model="assertionForm.reconcile_postings" type="checkbox" class="size-4 shrink-0 accent-accent" />
                            <span>Reconcile through this date</span>
                        </label>

                        <div class="flex gap-3">
                            <button type="submit" class="button-primary">Save</button>
                            <button type="button" class="button-subtle" @click="assertionFormOpen = false">
                                Cancel
                            </button>
                        </div>
                    </form>
                </ModalDialog>

                <p v-if="registerRows.length === 0" class="mt-2 text-sm text-muted">No postings yet.</p>

                <ul
                    v-else
                    class="mt-2 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
                >
                    <li class="grid grid-cols-[5.5rem_minmax(0,1fr)_minmax(0,1fr)_8rem_8.5rem_2rem] items-center gap-x-4 bg-background/40 px-4 py-2">
                        <span class="field-label">Date</span>
                        <span class="field-label">Payee</span>
                        <span class="field-label">Counterparty</span>
                        <span class="field-label text-right">Amount</span>
                        <span class="field-label text-right">Balance</span>
                        <span></span>
                    </li>
                    <template v-for="row in registerRows" :key="row.kind === 'posting' ? `p-${row.posting.id}` : `a-${row.assertion.id}`">
                    <li
                        v-if="row.kind === 'assertion'"
                        class="grid grid-cols-[5.5rem_minmax(0,1fr)_auto] items-center gap-x-4 px-4 py-1.5 font-mono text-xs"
                        :class="issuesByAssertion.has(row.assertion.id) ? 'text-danger' : 'text-accent'"
                    >
                        <span>{{ row.assertion.asserted_at }}</span>

                        <span class="truncate tracking-wider uppercase">
                            Assertion · {{ commoditiesById.get(row.assertion.financial_commodity_id)?.code }}
                            <span v-if="row.assertion.memo" class="normal-case">· {{ row.assertion.memo }}</span>
                        </span>

                        <span class="flex items-center gap-3">
                            <template v-if="issuesByAssertion.has(row.assertion.id)">
                                expected
                                {{ formatAssertionAmount(row.assertion, row.assertion.balance) }}
                                · actual
                                {{ formatAssertionAmount(row.assertion, issuesByAssertion.get(row.assertion.id)?.actual ?? '0') }}
                            </template>
                            <template v-else>
                                {{ formatAssertionAmount(row.assertion, row.assertion.balance) }} ✓
                            </template>

                            <button
                                type="button"
                                class="tracking-wider uppercase opacity-40 transition-opacity hover:opacity-100 hover:text-danger"
                                @click="deleteAssertion(row.assertion)"
                            >
                                Delete
                            </button>
                        </span>
                    </li>
                    <li
                        v-else
                        class="group grid grid-cols-[5.5rem_minmax(0,1fr)_minmax(0,1fr)_8rem_8.5rem_2rem] items-start gap-x-4 px-4 py-2"
                        :class="{
                            italic: row.posting.status === 'pending',
                            'cursor-pointer transition-colors hover:bg-accent/10': canMatch(row.posting),
                            'opacity-40': matchingBankTransaction && !canMatch(row.posting),
                        }"
                        :role="canMatch(row.posting) ? 'button' : undefined"
                        :tabindex="canMatch(row.posting) ? 0 : undefined"
                        @click="canMatch(row.posting) && matchTo(row.posting)"
                        @keydown.enter="canMatch(row.posting) && matchTo(row.posting)"
                    >
                        <span class="font-mono text-xs leading-5 text-muted">{{ row.posting.transaction?.date }}</span>

                        <span class="min-w-0 text-sm">
                            <span class="flex items-center gap-2">
                                <span class="truncate">
                                    {{ row.posting.transaction?.payee?.name ?? row.posting.transaction?.memo ?? '—' }}
                                    <span v-if="row.posting.transaction?.payee && row.posting.transaction?.memo" class="text-muted">
                                        · {{ row.posting.transaction.memo }}
                                    </span>
                                </span>
                                <button
                                    v-if="!matchingBankTransaction"
                                    type="button"
                                    class="shrink-0 rounded-sm text-muted transition-opacity hover:text-accent focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                                    title="Open transaction"
                                    @click.stop="openTransaction(row.posting)"
                                >
                                    <svg class="size-3.5" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                        <path d="M6.5 3.5H3.5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V9.5" />
                                        <path d="M9.5 2.5h4v4M13.5 2.5 7.5 8.5" />
                                    </svg>
                                    <span class="sr-only">Open transaction</span>
                                </button>
                            </span>

                            <span
                                v-if="bankAmountDiffers(row.posting)"
                                class="mt-0.5 flex items-center gap-2 font-mono text-xs text-danger not-italic"
                                :title="bankDetail(row.posting)"
                            >
                                <span class="size-1.5 shrink-0 rounded-full bg-danger"></span>
                                <span class="truncate">
                                    {{ row.posting.bank_transaction!.posted_on }}
                                    · {{ formatUsd(row.posting.bank_transaction!.amount) }} ≠ {{ formatUsd(row.posting.amount) }}
                                </span>
                            </span>

                            <span
                                v-if="proposalsByPosting.has(row.posting.id)"
                                class="mt-0.5 flex items-center gap-3 font-mono text-xs not-italic"
                            >
                                <span class="flex min-w-0 items-center gap-2 text-accent">
                                    <span class="size-1.5 shrink-0 rounded-full bg-accent"></span>
                                    <span class="truncate">
                                        {{ proposalsByPosting.get(row.posting.id)!.posted_on }}
                                        · {{ bankTransactionLabel(proposalsByPosting.get(row.posting.id)!) }}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-sm border border-accent/40 px-1.5 text-accent transition-colors hover:bg-accent hover:text-background"
                                    title="Approve match"
                                    @click="approveProposal(proposalsByPosting.get(row.posting.id)!)"
                                >
                                    <span aria-hidden="true">✓</span>
                                    <span class="sr-only">Approve match</span>
                                </button>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-sm px-1.5 text-muted transition-colors hover:text-danger"
                                    title="Not a match"
                                    @click="rejectProposal(proposalsByPosting.get(row.posting.id)!)"
                                >
                                    <span aria-hidden="true">✕</span>
                                    <span class="sr-only">Not a match</span>
                                </button>
                            </span>
                        </span>

                        <span class="min-w-0 text-sm text-muted">
                            <template v-if="counterPostings(row.posting).length > 1">
                                <button
                                    type="button"
                                    class="flex max-w-full items-center gap-2 text-left transition-colors hover:text-foreground"
                                    :aria-expanded="expandedCounterparties.has(row.posting.id)"
                                    @click.stop="toggleCounterparties(row.posting)"
                                    @dblclick.stop
                                >
                                    <span class="truncate">{{ counterAccountLabel(row.posting) }}</span>
                                    <span class="shrink-0 font-mono text-xs">
                                        {{ expandedCounterparties.has(row.posting.id) ? '▾' : `+${counterPostings(row.posting).length - 1}` }}
                                    </span>
                                </button>
                                <ul v-if="expandedCounterparties.has(row.posting.id)" class="mt-1 space-y-0.5 font-mono text-xs not-italic">
                                    <li
                                        v-for="counter in counterPostings(row.posting)"
                                        :key="counter.id"
                                        class="flex justify-between gap-3"
                                    >
                                        <span class="truncate">{{ counter.account?.path ?? '—' }}</span>
                                        <span class="shrink-0" :class="amountClass(counter.amount)">{{ formatCounterAmount(counter) }}</span>
                                    </li>
                                </ul>
                            </template>
                            <span v-else class="block truncate" :title="counterAccountLabel(row.posting)">
                                {{ counterAccountLabel(row.posting) }}
                            </span>
                        </span>

                        <span class="text-right font-mono text-sm">
                            {{ row.posting.commodity ? formatAmount(row.posting.amount, row.posting.commodity) : row.posting.amount }}
                        </span>

                        <span class="text-right font-mono text-sm text-muted">
                            {{ row.posting.running_balance !== undefined && row.posting.commodity
                                ? formatAmount(row.posting.running_balance, row.posting.commodity)
                                : '—' }}
                        </span>

                        <PostingStatusMenu
                            v-if="row.posting.status !== null"
                            :status="row.posting.status"
                            :matched="Boolean(row.posting.bank_transaction)"
                            :detail="bankDetail(row.posting)"
                            @select="setStatus(row.posting, $event)"
                        />
                        <span v-else class="w-8 shrink-0"></span>
                    </li>
                    </template>
                </ul>

                <PaginationBar :page="page" :last-page="lastPage" @change="changePage" />
            </section>
        </template>

        <SkeletonList v-else />
    </div>
</template>
