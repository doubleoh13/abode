<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, ref } from 'vue';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationBar from '../components/PaginationBar.vue';
import SkeletonList from '../components/SkeletonList.vue';
import TransactionForm from '../components/TransactionForm.vue';
import { accountPathAncestor, accountPathLeaf, statusLabel, statusSymbol } from '../journal';
import { formatAmount } from '../money';
import type { Account, BankTransaction, Commodity, Institution, Paginated, Payee, Posting } from '../types';

type InboxState = 'unresolved' | 'ignored';

const rows = ref<BankTransaction[]>([]);
const total = ref(0);
const page = ref(1);
const lastPage = ref(1);
const state = ref<InboxState>('unresolved');
const accounts = ref<Account[]>([]);
const commodities = ref<Commodity[]>([]);
const institutions = ref<Institution[]>([]);
const payees = ref<Payee[]>([]);
const loaded = ref(false);
const creatingFrom = ref<BankTransaction | null>(null);
const busyRowId = ref<number | null>(null);

const usd = computed(() => commodities.value.find((commodity) => commodity.code === 'USD'));

function formatBank(amount: string): string {
    return usd.value ? formatAmount(amount, usd.value) : amount;
}

async function loadRows(): Promise<void> {
    const response = (
        await axios.get<Paginated<BankTransaction>>('/api/v1/financial/bank-transactions', {
            params: { state: state.value, page: page.value },
        })
    ).data;

    rows.value = response.data;
    total.value = response.meta.total;
    lastPage.value = response.meta.last_page;
}

onMounted(async () => {
    const [accountsResponse, commoditiesResponse, institutionsResponse, payeesResponse] = await Promise.all([
        axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        axios.get<{ data: Institution[] }>('/api/v1/financial/institutions'),
        axios.get<{ data: Payee[] }>('/api/v1/financial/payees'),
        loadRows(),
    ]);

    accounts.value = accountsResponse.data.data;
    commodities.value = commoditiesResponse.data.data;
    institutions.value = institutionsResponse.data.data;
    payees.value = payeesResponse.data.data;
    loaded.value = true;
});

async function switchState(target: InboxState): Promise<void> {
    state.value = target;
    page.value = 1;
    await loadRows();
}

async function changePage(target: number): Promise<void> {
    page.value = target;
    await loadRows();
}

async function act(row: BankTransaction, request: () => Promise<unknown>): Promise<void> {
    busyRowId.value = row.id;

    try {
        await request();
        await loadRows();
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            alert(Object.values<string[]>(error.response.data.errors)[0]?.[0] ?? 'That did not work.');
            await loadRows();
            return;
        }

        throw error;
    } finally {
        busyRowId.value = null;
    }
}

function match(row: BankTransaction, posting: Posting): Promise<void> {
    return act(row, () =>
        axios.post(`/api/v1/financial/bank-transactions/${row.id}/match`, { financial_posting_id: posting.id }),
    );
}

function ignore(row: BankTransaction): Promise<void> {
    return act(row, () => axios.post(`/api/v1/financial/bank-transactions/${row.id}/ignore`));
}

function unignore(row: BankTransaction): Promise<void> {
    return act(row, () => axios.delete(`/api/v1/financial/bank-transactions/${row.id}/ignore`));
}

async function created(): Promise<void> {
    creatingFrom.value = null;
    await loadRows();
}

function candidateLabel(posting: Posting): string {
    const transaction = posting.transaction;
    const who = transaction?.payee?.name ?? transaction?.memo ?? '—';

    return `${transaction?.date ?? ''} · ${who}`;
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Inbox</h1>

            <div class="flex gap-2">
                <button
                    v-for="option in [{ value: 'unresolved', label: 'To review' }, { value: 'ignored', label: 'Ignored' }] as Array<{ value: InboxState; label: string }>"
                    :key="option.value"
                    type="button"
                    class="rounded-sm border px-2 py-1.5 font-mono text-xs tracking-wider uppercase transition-colors"
                    :class="state === option.value ? 'border-accent text-accent' : 'border-edge text-muted hover:text-foreground'"
                    :aria-pressed="state === option.value"
                    @click="switchState(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <template v-if="loaded">
            <ModalDialog :open="creatingFrom !== null" @close="creatingFrom = null">
                <TransactionForm
                    v-if="creatingFrom"
                    :key="`bank-${creatingFrom.id}`"
                    :transaction="null"
                    :bank-transaction="creatingFrom"
                    :accounts="accounts"
                    :commodities="commodities"
                    :institutions="institutions"
                    :payees="payees"
                    @saved="created"
                    @cancelled="creatingFrom = null"
                />
            </ModalDialog>

            <p v-if="rows.length === 0" class="mt-6 text-sm text-muted">
                {{ state === 'unresolved' ? 'Nothing to review.' : 'Nothing ignored.' }}
            </p>

            <ul v-else class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface">
                <li v-for="row in rows" :key="row.id" class="px-4 py-3" :class="{ 'opacity-60': busyRowId === row.id }">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="w-20 font-mono text-xs whitespace-nowrap text-muted">{{ row.posted_on }}</span>

                        <span class="min-w-0 flex-1 truncate text-sm">
                            {{ row.payee ?? row.description ?? '—' }}
                            <span v-if="row.payee && row.description && row.description !== row.payee" class="text-muted">
                                · {{ row.description }}
                            </span>
                        </span>

                        <span class="font-mono text-xs tracking-wider text-muted uppercase" :class="{ italic: row.pending }">
                            {{ row.pending ? 'Pending' : '' }}
                        </span>

                        <span class="text-right font-mono text-sm">{{ formatBank(row.amount) }}</span>
                    </div>

                    <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 pl-24 text-sm">
                        <span class="text-muted">
                            <span>{{ accountPathAncestor(row.account?.path) }}</span><span class="text-foreground">{{ accountPathLeaf(row.account?.path) }}</span>
                        </span>

                        <span class="flex flex-1 flex-wrap items-center justify-end gap-2 font-mono text-xs">
                            <template v-if="state === 'unresolved'">
                                <button
                                    v-for="candidate in row.candidates ?? []"
                                    :key="candidate.id"
                                    type="button"
                                    class="rounded-sm border border-accent/40 px-2 py-1 tracking-wider text-accent uppercase transition-colors hover:bg-accent/10"
                                    :title="`Match to this posting (${statusLabel(candidate.status)})`"
                                    @click="match(row, candidate)"
                                >
                                    Match {{ candidateLabel(candidate) }} {{ statusSymbol(candidate.status) }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-sm border border-edge px-2 py-1 tracking-wider text-muted uppercase transition-colors hover:text-foreground"
                                    @click="creatingFrom = row"
                                >
                                    Create
                                </button>
                                <button
                                    type="button"
                                    class="px-2 py-1 tracking-wider text-muted uppercase transition-colors hover:text-danger"
                                    @click="ignore(row)"
                                >
                                    Ignore
                                </button>
                            </template>
                            <button
                                v-else
                                type="button"
                                class="px-2 py-1 tracking-wider text-muted uppercase transition-colors hover:text-foreground"
                                @click="unignore(row)"
                            >
                                Restore
                            </button>
                        </span>
                    </div>
                </li>
            </ul>

            <PaginationBar :page="page" :last-page="lastPage" @change="changePage" />
        </template>

        <SkeletonList v-else />
    </div>
</template>
