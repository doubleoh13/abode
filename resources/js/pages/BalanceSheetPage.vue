<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { accountRoute } from '../router';
import DateInput from '../components/DateInput.vue';
import SkeletonList from '../components/SkeletonList.vue';
import { decimalToScaledInteger, formatAmount, scaledIntegerToDecimal } from '../money';
import type { Account, AccountType, BalanceSheet, BalanceSheetRow, Commodity } from '../types';

const accounts = ref<Account[]>([]);
const commodities = ref<Commodity[]>([]);
const report = ref<BalanceSheet | null>(null);
const loaded = ref(false);
const collapsedAccountIds = ref(new Set<number>());
const showHoldingsStorageKey = 'abode.balance-sheet.show-holdings';

function readShowHoldings(): boolean {
    try {
        return localStorage.getItem(showHoldingsStorageKey) === '1';
    } catch {
        return false;
    }
}

const showHoldings = ref(readShowHoldings());

function toggleShowHoldings(): void {
    showHoldings.value = !showHoldings.value;

    try {
        localStorage.setItem(showHoldingsStorageKey, showHoldings.value ? '1' : '0');
    } catch {
        // The preference is a convenience; a blocked store just means it does not persist.
    }
}

function localToday(): string {
    const now = new Date();

    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');
}

const asOf = ref(localToday());

async function loadReport(): Promise<void> {
    const response = await axios.get<{ data: BalanceSheet }>(
        '/api/v1/financial/reports/balance-sheet',
        { params: { as_of: asOf.value } },
    );

    report.value = response.data.data;
}

onMounted(async () => {
    const [accountsResponse, commoditiesResponse] = await Promise.all([
        axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        loadReport(),
    ]);

    accounts.value = accountsResponse.data.data;
    commodities.value = commoditiesResponse.data.data;
    loaded.value = true;
});

watch(asOf, () => {
    if (/^\d{4}-\d{2}-\d{2}$/.test(asOf.value)) {
        void loadReport();
    }
});

const commoditiesById = computed<Map<number, Commodity>>(
    () => new Map(commodities.value.map((commodity) => [commodity.id, commodity])),
);

const usd = computed<Commodity | undefined>(() =>
    commodities.value.find((commodity) => commodity.code === 'USD'),
);

function formatBaseAmount(value: string): string {
    return usd.value ? formatAmount(value, usd.value) : value;
}

interface HoldingLine {
    financial_commodity_id: number;
    quantity: string;
    unpriced: boolean;
}

function formatQuantity(holding: HoldingLine): string {
    const commodity = commoditiesById.value.get(holding.financial_commodity_id);

    return commodity ? formatAmount(holding.quantity, commodity) : holding.quantity;
}

interface SectionRow {
    account: Account;
    depth: number;
    hasChildren: boolean;
    collapsed: boolean;
    balance: string | null;
    holdings: HoldingLine[];
    holdingsListable: boolean;
}

interface SubtreeAggregate {
    marketValue: bigint;
    hasHoldings: boolean;
    quantities: Map<number, { quantity: bigint; unpriced: boolean }>;
}

const rowsByAccount = computed<Map<number, BalanceSheetRow[]>>(() => {
    const rows = new Map<number, BalanceSheetRow[]>();

    for (const row of report.value?.rows ?? []) {
        rows.set(row.financial_account_id, [...(rows.get(row.financial_account_id) ?? []), row]);
    }

    return rows;
});

const childrenByParent = computed<Map<number | null, Account[]>>(() => {
    const children = new Map<number | null, Account[]>();

    for (const account of accounts.value) {
        children.set(account.parent_id, [...(children.get(account.parent_id) ?? []), account]);
    }

    return children;
});

function sumMarketValues(rows: BalanceSheetRow[]): bigint {
    let total = 0n;

    for (const row of rows) {
        total += row.market_value !== null ? decimalToScaledInteger(row.market_value) : 0n;
    }

    return total;
}

const aggregates = computed<Map<number, SubtreeAggregate>>(() => {
    const subtrees = new Map<number, SubtreeAggregate>();

    const aggregate = (account: Account): SubtreeAggregate => {
        const ownRows = rowsByAccount.value.get(account.id) ?? [];
        const subtree: SubtreeAggregate = {
            marketValue: sumMarketValues(ownRows),
            hasHoldings: ownRows.length > 0,
            quantities: new Map(),
        };

        for (const row of ownRows) {
            subtree.quantities.set(row.financial_commodity_id, {
                quantity: decimalToScaledInteger(row.quantity),
                unpriced: row.market_value === null,
            });
        }

        for (const child of childrenByParent.value.get(account.id) ?? []) {
            const childSubtree = aggregate(child);
            subtree.hasHoldings ||= childSubtree.hasHoldings;
            subtree.marketValue += childSubtree.marketValue;

            for (const [commodityId, holding] of childSubtree.quantities) {
                const merged = subtree.quantities.get(commodityId) ?? { quantity: 0n, unpriced: false };
                subtree.quantities.set(commodityId, {
                    quantity: merged.quantity + holding.quantity,
                    unpriced: merged.unpriced || holding.unpriced,
                });
            }
        }

        subtrees.set(account.id, subtree);

        return subtree;
    };

    for (const root of childrenByParent.value.get(null) ?? []) {
        aggregate(root);
    }

    return subtrees;
});

const sections: Array<{ type: AccountType; label: string }> = [
    { type: 'asset', label: 'Assets' },
    { type: 'liability', label: 'Liabilities' },
];

const rowsByType = computed<Map<AccountType, SectionRow[]>>(() => {
    const rows = new Map<AccountType, SectionRow[]>();

    for (const { type } of sections) {
        const sectionRows: SectionRow[] = [];

        const walk = (nodes: Account[], depth: number): void => {
            for (const account of nodes) {
                const subtree = aggregates.value.get(account.id);

                if (!subtree?.hasHoldings) {
                    continue;
                }

                const children = (childrenByParent.value.get(account.id) ?? []).filter(
                    (child) => aggregates.value.get(child.id)?.hasHoldings,
                );
                const collapsed = collapsedAccountIds.value.has(account.id);
                const ownRows = rowsByAccount.value.get(account.id) ?? [];

                const isNonCurrency = (commodityId: number): boolean =>
                    commoditiesById.value.get(commodityId)?.kind !== 'currency';

                const holdings: HoldingLine[] = children.length > 0 && collapsed
                    ? [...subtree.quantities.entries()]
                        .filter(([commodityId, holding]) => isNonCurrency(commodityId) && holding.quantity !== 0n)
                        .sort(([first], [second]) => first - second)
                        .map(([commodityId, holding]) => ({
                            financial_commodity_id: commodityId,
                            quantity: scaledIntegerToDecimal(holding.quantity),
                            unpriced: holding.unpriced,
                        }))
                    : ownRows
                        .filter((row) => isNonCurrency(row.financial_commodity_id))
                        .map((row) => ({
                            financial_commodity_id: row.financial_commodity_id,
                            quantity: row.quantity,
                            unpriced: row.market_value === null,
                        }));

                let balance: string | null = scaledIntegerToDecimal(subtree.marketValue);

                if (children.length > 0 && !collapsed) {
                    balance = ownRows.length > 0
                        ? scaledIntegerToDecimal(sumMarketValues(ownRows))
                        : null;
                }

                sectionRows.push({
                    account,
                    depth,
                    hasChildren: children.length > 0,
                    collapsed,
                    balance,
                    holdings,
                    holdingsListable: holdings.length > 0,
                });

                if (!collapsed) {
                    walk(children, depth + 1);
                }
            }
        };

        walk(
            (childrenByParent.value.get(null) ?? []).filter((account) => account.account_type === type),
            0,
        );

        rows.set(type, sectionRows);
    }

    return rows;
});

const hasUnpricedHoldings = computed(() =>
    (report.value?.rows ?? []).some((row) => row.market_value === null),
);

const hasRows = computed(() => (report.value?.rows.length ?? 0) > 0);

function toggleInSet(ids: Set<number>, accountId: number): Set<number> {
    const toggled = new Set(ids);

    if (toggled.has(accountId)) {
        toggled.delete(accountId);
    } else {
        toggled.add(accountId);
    }

    return toggled;
}

function toggleCollapsed(accountId: number): void {
    collapsedAccountIds.value = toggleInSet(collapsedAccountIds.value, accountId);
}

</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Balance sheet</h1>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="button-subtle"
                    :aria-pressed="showHoldings"
                    @click="toggleShowHoldings"
                >
                    {{ showHoldings ? 'Hide holdings' : 'Show holdings' }}
                </button>

                <label class="flex items-center gap-2">
                    <span class="field-label">As of</span>
                    <DateInput v-model="asOf" />
                </label>
            </div>
        </div>

        <template v-if="loaded && report">
            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Assets</p>
                    <p class="mt-1 truncate font-mono text-lg">
                        {{ formatBaseAmount(report.totals.assets) }}
                    </p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Liabilities</p>
                    <p class="mt-1 truncate font-mono text-lg">
                        {{ formatBaseAmount(report.totals.liabilities) }}
                    </p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Net worth</p>
                    <p class="mt-1 truncate font-mono text-lg text-accent">
                        {{ formatBaseAmount(report.totals.net_worth) }}
                    </p>
                </div>
            </div>

            <p v-if="hasUnpricedHoldings" class="mt-3 text-xs text-muted">
                Holdings marked unpriced have no price on or before this date and are excluded from
                balances and totals.
            </p>

            <p v-if="!hasRows" class="mt-6 text-sm text-muted">No holdings as of this date.</p>

            <div v-else class="mt-6 flex flex-col gap-8">
                <section
                    v-for="section in sections.filter((candidate) => rowsByType.get(candidate.type)?.length)"
                    :key="section.type"
                >
                    <h2 class="font-mono text-xs tracking-wider text-muted uppercase">
                        {{ section.label }}
                    </h2>

                    <ul
                        class="mt-2 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
                    >
                        <li
                            v-for="row in rowsByType.get(section.type)"
                            :key="row.account.id"
                            class="px-4 py-2"
                        >
                            <div class="flex items-center justify-between gap-4">
                                <span
                                    class="flex min-w-0 items-center gap-1.5"
                                    :style="{ paddingLeft: `${row.depth * 1.25}rem` }"
                                >
                                    <button
                                        v-if="row.hasChildren"
                                        type="button"
                                        class="w-4 shrink-0 font-mono text-xs text-muted transition-colors hover:text-foreground"
                                        :aria-expanded="!row.collapsed"
                                        @click="toggleCollapsed(row.account.id)"
                                    >
                                        {{ row.collapsed ? '▸' : '▾' }}
                                    </button>
                                    <span v-else class="w-4 shrink-0" />

                                    <RouterLink
                                        :to="accountRoute(row.account)"
                                        class="truncate text-sm transition-colors hover:text-accent"
                                    >
                                        {{ row.account.name }}
                                    </RouterLink>
                                </span>

                                <span class="flex shrink-0 flex-col items-end">
                                    <span class="font-mono text-sm">
                                        {{ row.balance !== null ? formatBaseAmount(row.balance) : '' }}
                                    </span>

                                    <template v-if="showHoldings && row.holdingsListable">
                                        <span
                                            v-for="holding in row.holdings"
                                            :key="holding.financial_commodity_id"
                                            class="font-mono text-xs text-muted"
                                        >
                                            {{ formatQuantity(holding) }}{{ holding.unpriced ? ' (unpriced)' : '' }}
                                        </span>
                                    </template>
                                </span>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <SkeletonList v-else />
    </div>
</template>
