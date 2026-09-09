<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ComboBox from '../components/ComboBox.vue';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationBar from '../components/PaginationBar.vue';
import SkeletonList from '../components/SkeletonList.vue';
import {
    accountPathAncestor,
    accountPathLeaf,
    nextStatus,
    statusClass,
    statusLabel,
    statusSymbol,
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
    Account,
    AccountBalance,
    BalanceAssertion,
    Commodity,
    JournalIssue,
    Lot,
    Paginated,
    Posting,
} from '../types';

const route = useRoute();

const account = ref<Account | null>(null);
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

function counterAccountLabel(posting: Posting): string {
    const siblings = (posting.transaction?.postings ?? []).filter((candidate) => candidate.id !== posting.id);
    const counters = siblings.filter(
        (candidate) => candidate.financial_account_id !== posting.financial_account_id,
    );

    if (counters.length === 0) {
        return siblings.length > 0 ? '(this account)' : '—';
    }

    const first = counters[0].account?.path ?? '—';

    return counters.length === 1 ? first : `${first} +${counters.length - 1}`;
}

async function loadPostings(): Promise<void> {
    const response = (
        await axios.get<Paginated<Posting>>('/api/v1/financial/postings', {
            params: { financial_account_id: accountId.value, page: page.value },
        })
    ).data;

    postings.value = response.data;
    lastPage.value = response.meta.last_page;
}

async function changePage(target: number): Promise<void> {
    page.value = target;
    await loadPostings();
}

async function flipStatus(posting: Posting): Promise<void> {
    if (posting.status === null) {
        return;
    }

    await axios.patch(`/api/v1/financial/postings/${posting.id}`, {
        status: nextStatus(posting.status),
    });
    await loadPostings();
}

const assertions = ref<BalanceAssertion[]>([]);
const assertionIssues = ref<JournalIssue[]>([]);

const issuesByAssertion = computed(
    () => new Map(assertionIssues.value.map((issue) => [issue.financial_balance_assertion_id, issue])),
);

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
});
const assertionErrors = ref<Record<string, string[]>>({});

function openAssertionForm(): void {
    assertionForm.asserted_at = new Date().toISOString().slice(0, 10);
    assertionForm.financial_commodity_id =
        balances.value[0]?.financial_commodity_id ?? usd.value?.id ?? null;
    assertionForm.balance = '';
    assertionForm.memo = '';
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

    try {
        await axios.post('/api/v1/financial/balance-assertions', {
            financial_account_id: accountId.value,
            financial_commodity_id: assertionForm.financial_commodity_id,
            asserted_at: assertionForm.asserted_at,
            balance: assertionForm.balance,
            memo: assertionForm.memo || null,
        });
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            assertionErrors.value = error.response.data.errors ?? {};
            return;
        }

        throw error;
    }

    assertionFormOpen.value = false;
    await loadAssertions();
}

async function deleteAssertion(assertion: BalanceAssertion): Promise<void> {
    if (!confirm(`Delete the ${assertion.asserted_at} assertion?`)) {
        return;
    }

    await axios.delete(`/api/v1/financial/balance-assertions/${assertion.id}`);
    await loadAssertions();
}

type RegisterRow = { kind: 'posting'; posting: Posting } | { kind: 'assertion'; assertion: BalanceAssertion };

const registerRows = computed<RegisterRow[]>(() => {
    if (postings.value.length === 0) {
        return assertions.value.map((assertion) => ({ kind: 'assertion', assertion }));
    }

    const newest = postings.value[0].transaction?.date ?? '';
    const oldest = postings.value[postings.value.length - 1].transaction?.date ?? '';
    // An assertion marks the end of its day, so it renders above that day's
    // postings; markers outside this page's date span stay on their own page.
    const queue = assertions.value
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

async function loadAccount(): Promise<void> {
    loaded.value = false;
    page.value = 1;

    const [accountResponse, balancesResponse, lotsResponse, commoditiesResponse] = await Promise.all([
        axios.get<{ data: Account }>(`/api/v1/financial/accounts/${accountId.value}`),
        axios.get<{ data: AccountBalance[] }>(`/api/v1/financial/accounts/${accountId.value}/balances`),
        axios.get<{ data: Lot[] }>('/api/v1/financial/lots', {
            params: { financial_account_id: accountId.value },
        }),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        loadPostings(),
        loadAssertions(),
    ]);

    account.value = accountResponse.data.data;
    balances.value = balancesResponse.data.data;
    lots.value = lotsResponse.data.data;
    commodities.value = commoditiesResponse.data.data;
    loaded.value = true;
}

onMounted(loadAccount);
watch(accountId, () => {
    void loadAccount();
});
</script>

<template>
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <h1 class="text-xl font-semibold">
                <span class="text-muted">{{ accountPathAncestor(account?.path) }}</span>{{ accountPathLeaf(account?.path) }}
            </h1>

            <span v-if="account" class="flex items-center gap-3 font-mono text-xs tracking-wider text-muted uppercase">
                <span>{{ account.account_type }}</span>
                <span v-if="account.institution">{{ account.institution.name }}</span>
                <span v-if="account.opened_at">opened {{ account.opened_at }}</span>
                <span v-if="account.closed_at" class="text-danger">closed {{ account.closed_at }}</span>
            </span>
        </div>

        <template v-if="loaded">
            <section v-if="holdings.length > 0" class="mt-6">
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

            <section class="mt-8">
                <div class="flex items-center justify-between">
                    <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Register</h2>

                    <button type="button" class="button-subtle" @click="openAssertionForm">
                        New assertion
                    </button>
                </div>

                <ModalDialog :open="assertionFormOpen" @close="assertionFormOpen = false">
                    <form class="flex w-80 flex-col gap-5" @submit.prevent="saveAssertion">
                        <h3 class="font-mono text-sm tracking-wider uppercase">Balance assertion</h3>

                        <label class="flex flex-col gap-1.5">
                            <span class="field-label">Date</span>
                            <input v-model="assertionForm.asserted_at" type="date" required class="input" />
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

                        <div class="flex justify-end gap-3">
                            <button type="button" class="button-subtle" @click="assertionFormOpen = false">
                                Cancel
                            </button>
                            <button type="submit" class="button-primary">Save</button>
                        </div>
                    </form>
                </ModalDialog>

                <p v-if="registerRows.length === 0" class="mt-2 text-sm text-muted">No postings yet.</p>

                <ul
                    v-else
                    class="mt-2 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
                >
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
                        class="grid grid-cols-[5.5rem_minmax(0,1fr)_minmax(0,1fr)_8rem_8.5rem_2rem] items-center gap-x-4 px-4 py-2"
                        :class="{ italic: row.posting.status === 'pending' }"
                    >
                        <span class="font-mono text-xs text-muted">{{ row.posting.transaction?.date }}</span>

                        <span class="truncate text-sm">
                            {{ row.posting.transaction?.payee?.name ?? row.posting.transaction?.memo ?? '—' }}
                            <span v-if="row.posting.transaction?.payee && row.posting.transaction?.memo" class="text-muted">
                                · {{ row.posting.transaction.memo }}
                            </span>
                        </span>

                        <span class="truncate text-sm text-muted" :title="counterAccountLabel(row.posting)">
                            {{ counterAccountLabel(row.posting) }}
                        </span>

                        <span class="text-right font-mono text-sm">
                            {{ row.posting.commodity ? formatAmount(row.posting.amount, row.posting.commodity) : row.posting.amount }}
                        </span>

                        <span class="text-right font-mono text-sm text-muted">
                            {{ row.posting.running_balance !== undefined && row.posting.commodity
                                ? formatAmount(row.posting.running_balance, row.posting.commodity)
                                : '—' }}
                        </span>

                        <button
                            v-if="row.posting.status !== null"
                            type="button"
                            class="w-8 shrink-0 rounded-sm text-right font-mono text-sm not-italic transition-colors hover:bg-edge/60 hover:text-foreground"
                            :class="statusClass(row.posting.status)"
                            :title="`${statusLabel(row.posting.status)} — click to mark ${statusLabel(nextStatus(row.posting.status)).toLowerCase()}`"
                            @click="flipStatus(row.posting)"
                        >
                            <span aria-hidden="true">{{ statusSymbol(row.posting.status) }}</span>
                            <span class="sr-only">{{ statusLabel(row.posting.status) }}</span>
                        </button>
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
