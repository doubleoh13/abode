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

type Comparison = 'previous' | 'last-year' | 'ytd';

const comparisons: Array<{ key: Comparison; label: string }> = [
    { key: 'previous', label: 'Previous period' },
    { key: 'last-year', label: 'Same period last year' },
    { key: 'ytd', label: 'Year to date' },
];

const enabledComparisons = ref(new Set<Comparison>());

function toggleComparison(key: Comparison): void {
    const toggled = new Set(enabledComparisons.value);

    if (toggled.has(key)) {
        toggled.delete(key);
    } else {
        toggled.add(key);
    }

    enabledComparisons.value = toggled;
}

function parseDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function lastDayOfMonth(year: number, month: number): string {
    return isoDate(new Date(year, month, 0));
}

function isFirstOfMonth(value: string): boolean {
    return value.endsWith('-01');
}

function isFirstOfYear(value: string): boolean {
    return value.endsWith('-01-01');
}

/**
 * A whole or running month steps back to the full previous month, a whole or
 * running year to the full previous year, and anything else shifts back by
 * its own length in days.
 */
function previousPeriod(range: DateRange): DateRange {
    const start = parseDate(range.from);
    const stop = parseDate(range.to);

    if (isFirstOfYear(range.from) && stop.getFullYear() === start.getFullYear()) {
        const year = start.getFullYear() - 1;

        return { from: `${year}-01-01`, to: `${year}-12-31` };
    }

    if (isFirstOfMonth(range.from) && stop.getFullYear() === start.getFullYear() && stop.getMonth() === start.getMonth()) {
        const previous = new Date(start.getFullYear(), start.getMonth() - 1, 1);

        return { from: isoDate(previous), to: lastDayOfMonth(previous.getFullYear(), previous.getMonth() + 1) };
    }

    const lengthInDays = Math.round((stop.getTime() - start.getTime()) / 86_400_000) + 1;

    return {
        from: isoDate(new Date(start.getFullYear(), start.getMonth(), start.getDate() - lengthInDays)),
        to: isoDate(new Date(stop.getFullYear(), stop.getMonth(), stop.getDate() - lengthInDays)),
    };
}

function samePeriodLastYear(range: DateRange): DateRange {
    const start = parseDate(range.from);
    const stop = parseDate(range.to);

    return {
        from: isoDate(new Date(start.getFullYear() - 1, start.getMonth(), start.getDate())),
        to: isoDate(new Date(stop.getFullYear() - 1, stop.getMonth(), stop.getDate())),
    };
}

function yearToDate(range: DateRange): DateRange {
    return { from: `${range.to.slice(0, 4)}-01-01`, to: range.to };
}

function rangeLabel(range: DateRange): string {
    const start = parseDate(range.from);
    const stop = parseDate(range.to);
    const year = start.getFullYear();

    if (isFirstOfYear(range.from) && stop.getFullYear() === year) {
        return range.to === `${year}-12-31` ? String(year) : `${year} YTD`;
    }

    if (isFirstOfMonth(range.from) && stop.getFullYear() === year && stop.getMonth() === start.getMonth()) {
        return range.from.slice(0, 7);
    }

    return `${range.from} – ${range.to}`;
}

interface Column {
    label: string;
    range: DateRange;
}

const primaryRange = computed<DateRange>(() => ({ from: from.value, to: to.value }));

const comparisonRanges = computed<Record<Comparison, DateRange>>(() => ({
    previous: previousPeriod(primaryRange.value),
    'last-year': samePeriodLastYear(primaryRange.value),
    ytd: yearToDate(primaryRange.value),
}));

function sameRange(first: DateRange, second: DateRange): boolean {
    return first.from === second.from && first.to === second.to;
}

/**
 * A comparison that would repeat the primary window adds nothing, so its
 * toggle is disabled rather than silently folded away.
 */
function isRedundant(key: Comparison): boolean {
    return sameRange(comparisonRanges.value[key], primaryRange.value);
}

const columns = computed<Column[]>(() => {
    const result: Column[] = [{ label: rangeLabel(primaryRange.value), range: primaryRange.value }];

    for (const { key } of comparisons) {
        const range = comparisonRanges.value[key];

        if (enabledComparisons.value.has(key) && !result.some((column) => sameRange(column.range, range))) {
            result.push({ label: rangeLabel(range), range });
        }
    }

    return result;
});

const reports = ref<IncomeStatement[]>([]);
const report = computed<IncomeStatement | null>(() => reports.value[0] ?? null);

async function loadReport(): Promise<void> {
    const responses = await Promise.all(
        columns.value.map((column) =>
            axios.get<{ data: IncomeStatement }>('/api/v1/financial/reports/income-statement', {
                params: { from: column.range.from, to: column.range.to },
            }),
        ),
    );

    reports.value = responses.map((response) => response.data.data);
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

watch(columns, () => {
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

interface Cell {
    amount: string | null;
    quantities: QuantityLine[];
}

interface SectionRow {
    account: Account;
    depth: number;
    hasChildren: boolean;
    collapsed: boolean;
    cells: Cell[];
}

interface SubtreeAggregate {
    amount: bigint;
    hasActivity: boolean;
    quantities: Map<number, bigint>;
}

const childrenByParent = computed<Map<number | null, Account[]>>(() => {
    const children = new Map<number | null, Account[]>();

    for (const account of accounts.value) {
        children.set(account.parent_id, [...(children.get(account.parent_id) ?? []), account]);
    }

    return children;
});

function groupRowsByAccount(rows: IncomeStatementRow[]): Map<number, IncomeStatementRow[]> {
    const grouped = new Map<number, IncomeStatementRow[]>();

    for (const row of rows) {
        grouped.set(row.financial_account_id, [...(grouped.get(row.financial_account_id) ?? []), row]);
    }

    return grouped;
}

function sumCurrencyAmounts(rows: IncomeStatementRow[]): bigint {
    let total = 0n;

    for (const row of rows) {
        if (isCurrency(row.financial_commodity_id)) {
            total += decimalToScaledInteger(row.amount);
        }
    }

    return total;
}

function buildAggregates(rowsByAccount: Map<number, IncomeStatementRow[]>): Map<number, SubtreeAggregate> {
    const subtrees = new Map<number, SubtreeAggregate>();

    const aggregate = (account: Account): SubtreeAggregate => {
        const ownRows = rowsByAccount.get(account.id) ?? [];
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
}

interface ColumnData {
    rowsByAccount: Map<number, IncomeStatementRow[]>;
    aggregates: Map<number, SubtreeAggregate>;
}

const columnData = computed<ColumnData[]>(() =>
    reports.value.map((columnReport) => {
        const rowsByAccount = groupRowsByAccount(columnReport.rows);

        return { rowsByAccount, aggregates: buildAggregates(rowsByAccount) };
    }),
);

function hasActivity(accountId: number): boolean {
    return columnData.value.some((column) => column.aggregates.get(accountId)?.hasActivity);
}

const sections: Array<{ type: AccountType; label: string; total: 'income' | 'expenses' }> = [
    { type: 'income', label: 'Income', total: 'income' },
    { type: 'expense', label: 'Expenses', total: 'expenses' },
];

const rowsByType = computed<Map<AccountType, SectionRow[]>>(() => {
    const rows = new Map<AccountType, SectionRow[]>();

    for (const { type } of sections) {
        const sectionRows: SectionRow[] = [];

        const walk = (nodes: Account[], depth: number): void => {
            for (const account of nodes) {
                if (!hasActivity(account.id)) {
                    continue;
                }

                const children = (childrenByParent.value.get(account.id) ?? []).filter((child) => hasActivity(child.id));
                const collapsed = collapsedAccountIds.value.has(account.id);
                const showSubtree = children.length === 0 || collapsed;

                const cells = columnData.value.map((column): Cell => {
                    const subtree = column.aggregates.get(account.id);
                    const ownRows = column.rowsByAccount.get(account.id) ?? [];

                    if (showSubtree) {
                        return {
                            amount: subtree ? scaledIntegerToDecimal(subtree.amount) : null,
                            quantities: [...(subtree?.quantities.entries() ?? [])]
                                .filter(([, quantity]) => quantity !== 0n)
                                .sort(([first], [second]) => first - second)
                                .map(([commodityId, quantity]) => ({ financial_commodity_id: commodityId, quantity: scaledIntegerToDecimal(quantity) })),
                        };
                    }

                    return {
                        amount: ownRows.length > 0 ? scaledIntegerToDecimal(sumCurrencyAmounts(ownRows)) : null,
                        quantities: ownRows
                            .filter((row) => !isCurrency(row.financial_commodity_id))
                            .map((row) => ({ financial_commodity_id: row.financial_commodity_id, quantity: row.amount })),
                    };
                });

                sectionRows.push({ account, depth, hasChildren: children.length > 0, collapsed, cells });

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

const hasRows = computed(() => reports.value.some((columnReport) => columnReport.rows.length > 0));

const amountColumnsStyle = computed(() => ({
    gridTemplateColumns: `minmax(0, 1fr) repeat(${columns.value.length}, 8.5rem)`,
}));

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

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <span class="field-label">Compare</span>
            <button
                v-for="comparison in comparisons"
                :key="comparison.key"
                type="button"
                class="rounded-sm border px-2.5 py-1 font-mono text-xs tracking-wider uppercase transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                :class="enabledComparisons.has(comparison.key) && !isRedundant(comparison.key) ? 'border-accent/60 text-foreground' : 'border-edge text-muted hover:text-foreground'"
                :aria-pressed="enabledComparisons.has(comparison.key) && !isRedundant(comparison.key)"
                :disabled="isRedundant(comparison.key)"
                @click="toggleComparison(comparison.key)"
            >
                {{ comparison.label }}
            </button>
        </div>

        <template v-if="loaded && report">
            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Income · {{ columns[0].label }}</p>
                    <p class="mt-1 truncate font-mono text-lg">{{ formatBaseAmount(report.totals.income) }}</p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Expenses · {{ columns[0].label }}</p>
                    <p class="mt-1 truncate font-mono text-lg">{{ formatBaseAmount(report.totals.expenses) }}</p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Net · {{ columns[0].label }}</p>
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
                        <li v-if="columns.length > 1" class="grid items-center gap-x-4 bg-background/40 px-4 py-2" :style="amountColumnsStyle">
                            <span />
                            <span v-for="column in columns" :key="column.label" class="field-label text-right">{{ column.label }}</span>
                        </li>

                        <li
                            v-for="row in rowsByType.get(section.type)"
                            :key="row.account.id"
                            class="grid items-start gap-x-4 px-4 py-2"
                            :style="amountColumnsStyle"
                        >
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

                            <span v-for="(cell, index) in row.cells" :key="index" class="flex flex-col items-end">
                                <span class="font-mono text-sm">{{ cell.amount !== null ? formatBaseAmount(cell.amount) : '' }}</span>
                                <span v-for="line in cell.quantities" :key="line.financial_commodity_id" class="font-mono text-xs text-muted">
                                    {{ formatQuantity(line) }}
                                </span>
                            </span>
                        </li>

                        <li class="grid items-center gap-x-4 bg-background/40 px-4 py-2" :style="amountColumnsStyle">
                            <span class="field-label">Total</span>
                            <span v-for="(columnReport, index) in reports" :key="index" class="text-right font-mono text-sm">
                                {{ formatBaseAmount(columnReport.totals[section.total]) }}
                            </span>
                        </li>
                    </ul>
                </section>

                <ul class="divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface">
                    <li class="grid items-center gap-x-4 px-4 py-2" :style="amountColumnsStyle">
                        <span class="field-label">Net</span>
                        <span
                            v-for="(columnReport, index) in reports"
                            :key="index"
                            class="text-right font-mono text-sm"
                            :class="columnReport.totals.net.startsWith('-') ? 'text-danger' : 'text-accent'"
                        >
                            {{ formatBaseAmount(columnReport.totals.net) }}
                        </span>
                    </li>
                </ul>
            </div>
        </template>

        <SkeletonList v-else />
    </div>
</template>
