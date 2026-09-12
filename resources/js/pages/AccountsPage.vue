<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, nextTick, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { accountRoute } from '../router';
import AccountForm from '../components/AccountForm.vue';
import ComboBox from '../components/ComboBox.vue';
import ModalDialog from '../components/ModalDialog.vue';
import SkeletonList from '../components/SkeletonList.vue';
import type { Account, AccountType, Institution } from '../types';

const route = useRoute();
const accounts = ref<Account[]>([]);
const institutions = ref<Institution[]>([]);
const loaded = ref(false);
const formOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const showClosedAccounts = ref(false);

async function loadAccounts(): Promise<void> {
    const [accountsResponse, institutionsResponse] = await Promise.all([
        axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
        axios.get<{ data: Institution[] }>('/api/v1/financial/institutions'),
    ]);

    accounts.value = accountsResponse.data.data;
    institutions.value = institutionsResponse.data.data;
    loaded.value = true;
}

onMounted(async () => {
    await loadAccounts();

    if (route.hash) {
        await nextTick();
        document.getElementById(route.hash.slice(1))?.scrollIntoView();
    }
});

const sections: Array<{ type: AccountType; slug: string; label: string }> = [
    { type: 'asset', slug: 'assets', label: 'Assets' },
    { type: 'liability', slug: 'liabilities', label: 'Liabilities' },
    { type: 'income', slug: 'income', label: 'Income' },
    { type: 'expense', slug: 'expenses', label: 'Expenses' },
    { type: 'equity', slug: 'equity', label: 'Equity' },
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
                const visible = showClosedAccounts.value || account.closed_at === null;

                if (visible) {
                    sectionRows.push({ account, depth });
                }

                walk(childrenByParent.get(account.id) ?? [], visible ? depth + 1 : depth);
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
const hasClosedAccounts = computed(() => accounts.value.some((account) => account.closed_at !== null));
const hasVisibleAccounts = computed(() =>
    sections.some((section) => (rowsByType.value.get(section.type)?.length ?? 0) > 0),
);

function openCreateForm(): void {
    editingAccount.value = null;
    formOpen.value = true;
}

function openEditForm(account: Account): void {
    editingAccount.value = account;
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    editingAccount.value = null;
}

async function accountSaved(): Promise<void> {
    closeForm();
    await loadAccounts();
}

const deletingAccount = ref<Account | null>(null);
const mergeTargetId = ref<number | null>(null);
const deleteErrors = ref<Record<string, string[]>>({});

const subtreeIds = computed<Set<number>>(() => {
    const ids = new Set<number>();

    if (!deletingAccount.value) {
        return ids;
    }

    const childrenByParent = new Map<number, number[]>();

    for (const account of accounts.value) {
        if (account.parent_id !== null) {
            childrenByParent.set(account.parent_id, [...(childrenByParent.get(account.parent_id) ?? []), account.id]);
        }
    }

    const queue = [deletingAccount.value.id];

    while (queue.length > 0) {
        const current = queue.shift()!;

        ids.add(current);
        queue.push(...(childrenByParent.get(current) ?? []));
    }

    return ids;
});

const mergeTargetOptions = computed(() =>
    accounts.value
        .filter((candidate) => candidate.account_type === deletingAccount.value?.account_type && !subtreeIds.value.has(candidate.id))
        .map((candidate) => ({ value: candidate.id, label: candidate.path })),
);

async function requestDelete(account: Account): Promise<void> {
    if (account.postings_count) {
        deletingAccount.value = account;
        mergeTargetId.value = null;
        deleteErrors.value = {};

        return;
    }

    if (confirm(`Delete ${account.path}?`)) {
        await removeAccount(account, () => axios.delete(`/api/v1/financial/accounts/${account.id}`));
    }
}

async function mergeAccount(): Promise<void> {
    const account = deletingAccount.value;

    if (!account || mergeTargetId.value === null) {
        return;
    }

    await removeAccount(account, () =>
        axios.post(`/api/v1/financial/accounts/${account.id}/merge`, { target_account_id: mergeTargetId.value }),
    );
}

async function removeAccount(account: Account, request: () => Promise<unknown>): Promise<void> {
    try {
        await request();
        deletingAccount.value = null;
        await loadAccounts();
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            deleteErrors.value = error.response.data.errors;
            return;
        }

        if (isAxiosError(error) && error.response?.status === 409) {
            alert(error.response.data.message);
            return;
        }

        throw error;
    }
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Accounts</h1>

            <div class="flex items-center gap-3">
                <button
                    v-if="loaded && hasClosedAccounts"
                    type="button"
                    class="button-subtle"
                    :aria-pressed="showClosedAccounts"
                    @click="showClosedAccounts = !showClosedAccounts"
                >
                    {{ showClosedAccounts ? 'Hide closed' : 'Show closed' }}
                </button>

                <button type="button" class="button-primary" @click="openCreateForm">
                    New account
                </button>
            </div>
        </div>

        <template v-if="loaded">
            <ModalDialog :open="formOpen" @close="closeForm">
                <AccountForm
                    :key="editingAccount?.id ?? 'new'"
                    :account="editingAccount"
                    :accounts="accounts"
                    :institutions="institutions"
                    @saved="accountSaved"
                    @cancelled="closeForm"
                />
            </ModalDialog>

            <ModalDialog :open="deletingAccount !== null" @close="deletingAccount = null">
                <form
                    class="mx-auto flex w-96 flex-col gap-5 rounded-md border border-edge bg-surface p-5"
                    @submit.prevent="mergeAccount"
                >
                    <h3 class="font-mono text-sm tracking-wider uppercase">Delete {{ deletingAccount?.path }}</h3>

                    <p class="text-sm">
                        {{ deletingAccount?.postings_count === 1
                            ? '1 posting still points here. Choose where it moves.'
                            : `${deletingAccount?.postings_count} postings still point here. Choose where they move.` }}
                    </p>

                    <div class="flex flex-col gap-1.5">
                        <span class="field-label">Move to</span>
                        <ComboBox v-model="mergeTargetId" :options="mergeTargetOptions" />
                        <p v-if="deleteErrors.target_account_id" class="text-sm text-danger">
                            {{ deleteErrors.target_account_id[0] }}
                        </p>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="button-primary" :disabled="mergeTargetId === null">
                            Merge and delete
                        </button>
                        <button type="button" class="button-subtle" @click="deletingAccount = null">Cancel</button>
                    </div>
                </form>
            </ModalDialog>

            <p v-if="!hasAccounts" class="mt-6 text-sm text-muted">No accounts yet.</p>

            <p v-else-if="!hasVisibleAccounts" class="mt-6 text-sm text-muted">
                No open accounts.
            </p>

            <div v-if="hasVisibleAccounts" class="mt-6 flex flex-col gap-8">
                <section
                    v-for="section in sections.filter((candidate) => rowsByType.get(candidate.type)?.length)"
                    :id="section.slug"
                    :key="section.type"
                    class="scroll-mt-8"
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
                            class="group flex items-center justify-between gap-4 px-4 py-2"
                        >
                            <RouterLink
                                :to="accountRoute(account)"
                                class="flex items-center gap-2 text-sm transition-colors hover:text-accent"
                                :class="account.closed_at ? 'text-muted line-through' : ''"
                                :style="{ paddingLeft: `${depth * 1.25}rem` }"
                            >
                                {{ account.name }}
                                <span
                                    v-if="account.unmatched_bank_transactions_count"
                                    class="size-1.5 rounded-full bg-accent"
                                    title="Unmatched bank transactions"
                                />
                            </RouterLink>

                            <span class="flex items-center gap-3 font-mono text-xs text-muted">
                                <span
                                    v-if="account.simplefin_account_id"
                                    class="tracking-wider text-accent uppercase"
                                    title="Mapped to a SimpleFIN account for import"
                                >
                                    SimpleFIN
                                </span>
                                <span v-if="account.institution">{{ account.institution.name }}</span>
                                <span v-if="account.closed_at">closed {{ account.closed_at }}</span>

                                <button
                                    type="button"
                                    class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-foreground"
                                    @click="openEditForm(account)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-danger"
                                    @click="requestDelete(account)"
                                >
                                    Delete
                                </button>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <SkeletonList v-else />
    </div>
</template>
