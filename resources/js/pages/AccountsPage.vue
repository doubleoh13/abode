<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import type { Account, AccountType } from '../types';

const accounts = ref<Account[]>([]);
const loaded = ref(false);

onMounted(async () => {
    accounts.value = (await axios.get<{ data: Account[] }>('/api/v1/accounts')).data.data;
    loaded.value = true;
});

const sections: Array<{ type: AccountType; label: string }> = [
    { type: 'asset', label: 'Assets' },
    { type: 'liability', label: 'Liabilities' },
    { type: 'income', label: 'Income' },
    { type: 'expense', label: 'Expenses' },
    { type: 'equity', label: 'Equity' },
];

interface AccountRow {
    account: Account;
    depth: number;
}

const rowsByType = computed<Map<AccountType, AccountRow[]>>(() => {
    const childrenByParent = new Map<number, Account[]>();
    const roots: Account[] = [];

    for (const account of accounts.value) {
        if (account.parent_id === null) {
            roots.push(account);
        } else {
            childrenByParent.set(account.parent_id, [
                ...(childrenByParent.get(account.parent_id) ?? []),
                account,
            ]);
        }
    }

    const rows = new Map<AccountType, AccountRow[]>();

    for (const { type } of sections) {
        const sectionRows: AccountRow[] = [];

        const walk = (nodes: Account[], depth: number): void => {
            for (const account of nodes) {
                sectionRows.push({ account, depth });
                walk(childrenByParent.get(account.id) ?? [], depth + 1);
            }
        };

        walk(
            roots.filter((account) => account.account_type === type),
            0,
        );

        rows.set(type, sectionRows);
    }

    return rows;
});

const hasAccounts = computed(() => accounts.value.length > 0);
</script>

<template>
    <div>
        <h1 class="text-xl font-semibold">Accounts</h1>

        <template v-if="loaded">
            <p v-if="!hasAccounts" class="mt-6 text-sm text-muted">No accounts yet.</p>

            <div v-else class="mt-6 flex max-w-2xl flex-col gap-8">
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
                            v-for="{ account, depth } in rowsByType.get(section.type)"
                            :key="account.id"
                            class="flex items-center justify-between gap-4 px-4 py-2"
                        >
                            <span
                                class="text-sm"
                                :class="account.closed_at ? 'text-muted line-through' : ''"
                                :style="{ paddingLeft: `${depth * 1.25}rem` }"
                            >
                                {{ account.name }}
                            </span>

                            <span class="flex items-center gap-3 font-mono text-xs text-muted">
                                <span v-if="account.institution">{{ account.institution.name }}</span>
                                <span v-if="account.closed_at">closed {{ account.closed_at }}</span>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </template>
    </div>
</template>
