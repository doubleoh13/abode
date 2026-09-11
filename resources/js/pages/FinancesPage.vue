<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import { recurrenceLabel } from '../journal';
import { formatAmount } from '../money';
import type { BalanceSheet, BankTransaction, Commodity, JournalIssue, Paginated, RecurringTransaction } from '../types';

const report = ref<BalanceSheet | null>(null);
const schedules = ref<RecurringTransaction[]>([]);
const issues = ref<JournalIssue[]>([]);
const inboxCount = ref(0);
const usd = ref<Commodity | null>(null);
const loaded = ref(false);

const upcoming = computed(() => schedules.value.slice(0, 5));

function formatBaseAmount(value: string): string {
    return usd.value ? formatAmount(value, usd.value) : value;
}

onMounted(async () => {
    const [reportResponse, schedulesResponse, issuesResponse, commoditiesResponse, inboxResponse] = await Promise.all([
        axios.get<{ data: BalanceSheet }>('/api/v1/financial/reports/balance-sheet'),
        axios.get<{ data: RecurringTransaction[] }>('/api/v1/financial/recurring-transactions'),
        axios.get<{ data: JournalIssue[] }>('/api/v1/financial/journal-issues'),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        axios.get<Paginated<BankTransaction>>('/api/v1/financial/bank-transactions'),
    ]);

    inboxCount.value = inboxResponse.data.meta.total;
    report.value = reportResponse.data.data;
    schedules.value = schedulesResponse.data.data;
    issues.value = issuesResponse.data.data;
    usd.value = commoditiesResponse.data.data.find((commodity) => commodity.code === 'USD') ?? null;
    loaded.value = true;
});
</script>

<template>
    <div>
        <h1 class="text-xl font-semibold">Finances</h1>

        <template v-if="loaded && report">
            <RouterLink
                v-if="issues.length > 0"
                :to="{ name: 'finances.journal' }"
                class="mt-4 block rounded-md border border-danger/40 bg-danger/10 px-4 py-2 text-sm text-danger transition-colors hover:bg-danger/20"
            >
                {{ issues.length }} journal {{ issues.length === 1 ? 'issue needs' : 'issues need' }} attention.
            </RouterLink>

            <RouterLink
                v-if="inboxCount > 0"
                :to="{ name: 'finances.inbox' }"
                class="mt-4 block rounded-md border border-accent/40 bg-accent/10 px-4 py-2 text-sm transition-colors hover:bg-accent/20"
            >
                {{ inboxCount }} bank {{ inboxCount === 1 ? 'transaction' : 'transactions' }} to review.
            </RouterLink>

            <RouterLink :to="{ name: 'finances.reports.balance-sheet' }" class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-md border border-edge bg-surface px-4 py-3 transition-colors hover:border-accent/60">
                    <p class="field-label">Assets</p>
                    <p class="mt-1 truncate font-mono text-lg">{{ formatBaseAmount(report.totals.assets) }}</p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3 transition-colors hover:border-accent/60">
                    <p class="field-label">Liabilities</p>
                    <p class="mt-1 truncate font-mono text-lg">{{ formatBaseAmount(report.totals.liabilities) }}</p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3 transition-colors hover:border-accent/60">
                    <p class="field-label">Net worth</p>
                    <p class="mt-1 truncate font-mono text-lg text-accent">{{ formatBaseAmount(report.totals.net_worth) }}</p>
                </div>
            </RouterLink>

            <section class="mt-8">
                <div class="flex items-center justify-between">
                    <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Upcoming</h2>
                    <RouterLink :to="{ name: 'finances.schedules' }" class="font-mono text-xs tracking-wider text-muted uppercase transition-colors hover:text-foreground">
                        All schedules
                    </RouterLink>
                </div>

                <p v-if="upcoming.length === 0" class="mt-2 text-sm text-muted">No schedules yet.</p>

                <ul v-else class="mt-2 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface">
                    <li v-for="schedule in upcoming" :key="schedule.id" class="flex items-center gap-4 px-4 py-2">
                        <span class="w-20 font-mono text-xs whitespace-nowrap text-muted">{{ schedule.next_due_on }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm">
                            {{ schedule.payee?.name ?? schedule.memo ?? '—' }}
                            <span v-if="schedule.payee && schedule.memo" class="text-muted">· {{ schedule.memo }}</span>
                        </span>
                        <span class="font-mono text-xs tracking-wider text-muted uppercase">
                            {{ recurrenceLabel(schedule.frequency, schedule.interval) }}
                        </span>
                    </li>
                </ul>
            </section>
        </template>

        <div v-else aria-hidden="true" class="mt-6 grid animate-pulse grid-cols-1 gap-4 sm:grid-cols-3">
            <div v-for="tile in 3" :key="tile" class="h-20 rounded-md border border-edge bg-surface"></div>
        </div>
    </div>
</template>
