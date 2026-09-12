<script setup lang="ts">
const reports = [
    {
        name: 'finances.reports.balance-sheet',
        label: 'Balance sheet',
        description: 'Assets and liabilities as of a date, valued at market, with net worth.',
    },
    {
        name: 'finances.reports.income-statement',
        label: 'Income statement',
        description: 'Income and expenses over a period, by account, with the net.',
    },
];
</script>

<template>
    <div>
        <h1 class="text-xl font-semibold">Reports</h1>

        <ul class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface">
            <li v-for="report in reports" :key="report.name">
                <RouterLink
                    :to="{ name: report.name }"
                    class="group flex items-center justify-between gap-4 px-4 py-3 transition-colors hover:bg-background/60"
                >
                    <span>
                        <span class="block text-sm transition-colors group-hover:text-accent">
                            {{ report.label }}
                        </span>
                        <span class="mt-0.5 block text-xs text-muted">{{ report.description }}</span>
                    </span>

                    <span class="font-mono text-xs text-muted">→</span>
                </RouterLink>
            </li>
        </ul>
    </div>
</template>
