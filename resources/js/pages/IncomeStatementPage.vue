<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { accountRoute } from '../router';
import { isoDate, localToday } from '../journal';
import DateInput from '../components/DateInput.vue';
import SkeletonList from '../components/SkeletonList.vue';
import { decimalToScaledInteger, formatAmount, scaledIntegerToDecimal } from '../money';
import type { Account, AccountType, Commodity, IncomeStatement, IncomeStatementRow } from '../types';

const accounts = ref<Account[]>([]);
const commodities = ref<Commodity[]>([]);
const report = ref<IncomeStatement | null>(null);
const loaded = ref(false);
const collapsedAccountIds = ref(new Set<number>());

interface DateRange {
    from: string;
    to: string;
}

function thisYear(): DateRange {
    return { from: `${new Date().getFullYear()}-01-01`, to: localToday() };
}

function lastYear(): DateRange {
    const year = new Date().getFullYear() - 1;

    return { from: `${year}-01-01`, to: `${year}-12-31` };
}

function monthRange(offset: number): DateRange {
    const now = new Date();
    const first = new Date(now.getFullYear(), now.getMonth() + offset, 1);
    const last = new Date(now.getFullYear(), now.getMonth() + offset + 1, 0);

    return { from: isoDate(first), to: offset === 0 ? localToday() : isoDate(last) };
}

const presets: Array<{ label: string; range: () => DateRange }> = [
    { label: 'This year', range: thisYear },
    { label: 'Last year', range: lastYear },
    { label: 'This month', range: () => monthRange(0) },
    { label: 'Last month', range: () => monthRange(-1) },
];

const from = ref(thisYear().from);
const to = ref(thisYear().to);

function applyPreset(range: DateRange): void {
    from.value = range.from;
    to.value = range.to;
}

function isActivePreset(range: DateRange): boolean {
    return from.value === range.from && to.value === range.to;
}

async function loadReport(): Promise<void> {
    const response = await axios.get<{ data: IncomeStatement }>(
        '/api/v1/financial/reports/income-statement',
        { params: { from: from.value, to: to.value } },
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

watch([from, to], () => {
    if (/^\d{4}-\d{2}-\d{2}$/.test(from.value) && /^\d{4}-\d{2}-\d{2}$/.test(to.value) && from.value <= to.value) {
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

function isCurrency(commodityId: number): boolean {
    return commoditiesById.value.get(commodityId)?.kind === 'currency';
}

interface QuantityLine {
    financial_commodity_id: number;
    quantity: string;
}

function formatQuantity(line: QuantityLine): string {
    const commodity = commoditiesById.value.get(line.financial_commodity_id);

    return commodity ? `${formatAmount(line.quantity, commodity)} ${commodity.code}` : line.quantity;
}

interface SectionRow {
    account: Account;
    depth: number;
    hasChildren: boolean;
    collapsed: boolean;
    amount: string | null;
    quantities: QuantityLine[];
}

interface SubtreeAggregate {
    amount: bigint;
    hasActivity: boolean;
    quantities: Map<number, bigint>;
}

const rowsByAccount = computed<Map<number, IncomeStatementRow[]>>(() => {
    const rows = new Map<number, IncomeStatementRow[]>();

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

function sumCurrencyAmounts(rows: IncomeStatementRow[]): bigint {
    let total = 0n;

    for (const row of rows) {
        if (isCurrency(row.financial_commodity_id)) {
            total += decimalToScaledInteger(row.amount);
        }
    }

    return total;
}

const aggregates = computed<Map<number, SubtreeAggregate>>(() => {
    const subtrees = new Map<number, SubtreeAggregate>();

    const aggregate = (account: Account): SubtreeAggregate => {
        const ownRows = rowsByAccount.value.get(account.id) ?? [];
        const subtree: SubtreeAggregate = {
            amount: sumCurrencyAmounts(ownRows),
            hasActivity: ownRows.length > 0,
            quantities: new Map(),
        };

        for (const row of ownRows) {
            if (!isCurrency(row.financial_commodity_id)) {
                subtree.quantities.set(row.financial_commodity_id, decimalToScaledInteger(row.amount));
            }
        }

        for (const child of childrenByParent.value.get(account.id) ?? []) {
            const childSubtree = aggregate(child);
            subtree.hasActivity ||= childSubtree.hasActivity;
            subtree.amount += childSubtree.amount;

            for (const [commodityId, quantity] of childSubtree.quantities) {
                subtree.quantities.set(commodityId, (subtree.quantities.get(commodityId) ?? 0n) + quantity);
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
    { type: 'income', label: 'Income' },
    { type: 'expense', label: 'Expenses' },
];

const rowsByType = computed<Map<AccountType, SectionRow[]>>(() => {
    const rows = new Map<AccountType, SectionRow[]>();

    for (const { type } of sections) {
        const sectionRows: SectionRow[] = [];

        const walk = (nodes: Account[], depth: number): void => {
            for (const account of nodes) {
                const subtree = aggregates.value.get(account.id);

                if (!subtree?.hasActivity) {
                    continue;
                }

                const children = (childrenByParent.value.get(account.id) ?? []).filter(
                    (child) => aggregates.value.get(child.id)?.hasActivity,
                );
                const collapsed = collapsedAccountIds.value.has(account.id);
                const ownRows = rowsByAccount.value.get(account.id) ?? [];
                const showSubtree = children.length === 0 || collapsed;

                const quantities: QuantityLine[] = showSubtree
                    ? [...subtree.quantities.entries()]
                        .filter(([, quantity]) => quantity !== 0n)
                        .sort(([first], [second]) => first - second)
                        .map(([commodityId, quantity]) => ({ financial_commodity_id: commodityId, quantity: scaledIntegerToDecimal(quantity) }))
                    : ownRows
                        .filter((row) => !isCurrency(row.financial_commodity_id))
                        .map((row) => ({ financial_commodity_id: row.financial_commodity_id, quantity: row.amount }));

                let amount: string | null = scaledIntegerToDecimal(subtree.amount);

                if (!showSubtree) {
                    amount = ownRows.length > 0 ? scaledIntegerToDecimal(sumCurrencyAmounts(ownRows)) : null;
                }

                sectionRows.push({ account, depth, hasChildren: children.length > 0, collapsed, amount, quantities });

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

const hasRows = computed(() => (report.value?.rows.length ?? 0) > 0);

function toggleCollapsed(accountId: number): void {
    const toggled = new Set(collapsedAccountIds.value);

    if (toggled.has(accountId)) {
        toggled.delete(accountId);
    } else {
        toggled.add(accountId);
    }

    collapsedAccountIds.value = toggled;
}
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Income statement</h1>

            <div class="flex flex-wrap items-center gap-4">
                <span class="flex items-center gap-3 font-mono text-xs tracking-wider uppercase">
                    <button
                        v-for="preset in presets"
                        :key="preset.label"
                        type="button"
                        class="transition-colors hover:text-foreground"
                        :class="isActivePreset(preset.range()) ? 'text-foreground' : 'text-muted'"
                        @click="applyPreset(preset.range())"
                    >
                        {{ preset.label }}
                    </button>
                </span>

                <label class="flex items-center gap-2">
                    <span class="field-label">From</span>
                    <DateInput v-model="from" class="w-36" />
                </label>

                <label class="flex items-center gap-2">
                    <span class="field-label">To</span>
                    <DateInput v-model="to" class="w-36" />
                </label>
            </div>
        </div>

        <template v-if="loaded && report">
            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Income</p>
                    <p class="mt-1 truncate font-mono text-lg">{{ formatBaseAmount(report.totals.income) }}</p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Expenses</p>
                    <p class="mt-1 truncate font-mono text-lg">{{ formatBaseAmount(report.totals.expenses) }}</p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Net</p>
                    <p class="mt-1 truncate font-mono text-lg" :class="report.totals.net.startsWith('-') ? 'text-danger' : 'text-accent'">
                        {{ formatBaseAmount(report.totals.net) }}
                    </p>
                </div>
            </div>

            <p v-if="!hasRows" class="mt-6 text-sm text-muted">No activity in this period.</p>

            <div v-else class="mt-6 flex flex-col gap-8">
                <section
                    v-for="section in sections.filter((candidate) => rowsByType.get(candidate.type)?.length)"
                    :key="section.type"
                >
                    <h2 class="font-mono text-xs tracking-wider text-muted uppercase">{{ section.label }}</h2>

                    <ul class="mt-2 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface">
                        <li v-for="row in rowsByType.get(section.type)" :key="row.account.id" class="px-4 py-2">
                            <div class="flex items-center justify-between gap-4">
                                <span class="flex min-w-0 items-center gap-1.5" :style="{ paddingLeft: `${row.depth * 1.25}rem` }">
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

                                    <RouterLink :to="accountRoute(row.account)" class="truncate text-sm transition-colors hover:text-accent">
                                        {{ row.account.name }}
                                    </RouterLink>
                                </span>

                                <span class="flex shrink-0 flex-col items-end">
                                    <span class="font-mono text-sm">{{ row.amount !== null ? formatBaseAmount(row.amount) : '' }}</span>
                                    <span v-for="line in row.quantities" :key="line.financial_commodity_id" class="font-mono text-xs text-muted">
                                        {{ formatQuantity(line) }}
                                    </span>
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
