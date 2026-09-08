<script setup lang="ts">
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
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
import { allocateBasis, decimalToScaledInteger, formatAmount, scaledIntegerToDecimal } from '../money';
import type { Account, Commodity, CommodityBalance, Lot, Paginated, Posting } from '../types';

const route = useRoute();

const commodity = ref<Commodity | null>(null);
const balances = ref<CommodityBalance[]>([]);
const lots = ref<Lot[]>([]);
const accounts = ref<Account[]>([]);
const usd = ref<Commodity | null>(null);
const postings = ref<Posting[]>([]);
const page = ref(1);
const lastPage = ref(1);
const loaded = ref(false);

const commodityId = computed(() => Number(route.params.id));

const accountsById = computed(() => new Map(accounts.value.map((account) => [account.id, account])));

const heldIn = computed(() =>
    balances.value
        .filter((balance) => decimalToScaledInteger(balance.balance) !== 0n)
        .map((balance) => ({
            account: accountsById.value.get(balance.financial_account_id),
            balance: balance.balance,
        })),
);

const openLots = computed(() =>
    lots.value.filter((lot) => decimalToScaledInteger(lot.open_quantity ?? '0') > 0n),
);

function lotBasisShare(lot: Lot): string {
    return allocateBasis(lot.cost, lot.acquired_quantity ?? '0', [lot.open_quantity ?? '0'])[0];
}

const expandedAccounts = ref<number[]>([]);
const lotsByAccount = ref(new Map<number, Lot[]>());

async function toggleAccountLots(accountId: number): Promise<void> {
    if (expandedAccounts.value.includes(accountId)) {
        expandedAccounts.value = expandedAccounts.value.filter((candidate) => candidate !== accountId);
        return;
    }

    if (!lotsByAccount.value.has(accountId)) {
        const rows = (
            await axios.get<{ data: Lot[] }>('/api/v1/financial/lots', {
                params: { financial_account_id: accountId, financial_commodity_id: commodityId.value },
            })
        ).data.data;

        lotsByAccount.value = new Map(lotsByAccount.value).set(accountId, rows);
    }

    expandedAccounts.value = [...expandedAccounts.value, accountId];
}

const totalHeld = computed(() =>
    scaledIntegerToDecimal(
        balances.value.reduce((sum, balance) => sum + decimalToScaledInteger(balance.balance), 0n),
    ),
);

const totalBasis = computed(() => {
    if (commodity.value?.kind === 'currency') {
        return null;
    }

    return scaledIntegerToDecimal(
        openLots.value.reduce((sum, lot) => sum + decimalToScaledInteger(lotBasisShare(lot)), 0n),
    );
});

async function loadPostings(): Promise<void> {
    const response = (
        await axios.get<Paginated<Posting>>('/api/v1/financial/postings', {
            params: { financial_commodity_id: commodityId.value, page: page.value },
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

async function loadCommodity(): Promise<void> {
    loaded.value = false;
    page.value = 1;

    const [commodityResponse, balancesResponse, lotsResponse, accountsResponse, commoditiesResponse] =
        await Promise.all([
            axios.get<{ data: Commodity }>(`/api/v1/financial/commodities/${commodityId.value}`),
            axios.get<{ data: CommodityBalance[] }>(`/api/v1/financial/commodities/${commodityId.value}/balances`),
            axios.get<{ data: Lot[] }>('/api/v1/financial/lots', {
                params: { financial_commodity_id: commodityId.value },
            }),
            axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
            axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
            loadPostings(),
        ]);

    commodity.value = commodityResponse.data.data;
    balances.value = balancesResponse.data.data;
    lots.value = lotsResponse.data.data;
    accounts.value = accountsResponse.data.data;
    usd.value = commoditiesResponse.data.data.find((candidate) => candidate.code === 'USD') ?? null;
    loaded.value = true;
}

onMounted(loadCommodity);
watch(commodityId, () => {
    void loadCommodity();
});
</script>

<template>
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <h1 class="text-xl font-semibold">
                {{ commodity?.code }}
                <span class="text-muted">· {{ commodity?.name }}</span>
            </h1>

            <span v-if="commodity" class="flex items-center gap-3 font-mono text-xs tracking-wider text-muted uppercase">
                <span>{{ commodity.kind }}</span>
                <span>precision {{ commodity.display_precision }}</span>
            </span>
        </div>

        <template v-if="loaded">
            <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Total held</p>
                    <p class="mt-1 truncate font-mono text-lg">
                        {{ commodity ? formatAmount(totalHeld, commodity) : totalHeld }}
                    </p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Cost basis</p>
                    <p class="mt-1 truncate font-mono text-lg">
                        {{ totalBasis !== null && usd ? formatAmount(totalBasis, usd) : '—' }}
                    </p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Market value</p>
                    <p class="mt-1 font-mono text-lg text-muted">—</p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Unrealized</p>
                    <p class="mt-1 font-mono text-lg text-muted">—</p>
                </div>
            </div>

            <section v-if="heldIn.length > 0" class="mt-8">
                <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Held in</h2>

                <div class="mt-2 overflow-x-auto rounded-md border border-edge bg-surface">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-edge font-mono text-xs tracking-wider text-muted uppercase">
                                <th class="px-4 py-2 text-left font-medium">Account</th>
                                <th class="px-4 py-2 text-right font-medium">Quantity</th>
                                <th class="px-4 py-2 text-right font-medium">Market value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-edge">
                            <template v-for="holding in heldIn" :key="holding.account?.id ?? holding.balance">
                                <tr>
                                    <td class="px-4 py-2">
                                        <span class="flex items-center gap-2">
                                            <button
                                                v-if="holding.account && openLots.length > 0"
                                                type="button"
                                                class="font-mono text-xs text-muted transition-colors hover:text-accent"
                                                :aria-expanded="expandedAccounts.includes(holding.account.id)"
                                                @click="toggleAccountLots(holding.account.id)"
                                            >
                                                {{ expandedAccounts.includes(holding.account.id) ? '▾' : '▸' }}
                                            </button>
                                            <RouterLink
                                                v-if="holding.account"
                                                :to="{ name: 'finances.account', params: { id: holding.account.id } }"
                                                class="transition-colors hover:text-accent"
                                            >
                                                <span class="text-muted">{{ accountPathAncestor(holding.account.path) }}</span>{{ accountPathLeaf(holding.account.path) }}
                                            </RouterLink>
                                            <span v-else class="text-muted">—</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono">
                                        {{ commodity ? formatAmount(holding.balance, commodity) : holding.balance }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono text-muted">—</td>
                                </tr>
                                <tr v-if="holding.account && expandedAccounts.includes(holding.account.id)">
                                    <td colspan="3" class="bg-background/40 px-4 py-3">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="font-mono text-xs tracking-wider text-muted uppercase">
                                                    <th class="py-1 pr-4 text-left font-medium">Acquired</th>
                                                    <th class="px-4 py-1 text-right font-medium">Open here</th>
                                                    <th class="px-4 py-1 text-right font-medium">Acquired qty</th>
                                                    <th class="py-1 pl-4 text-right font-medium">Open basis</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="lot in lotsByAccount.get(holding.account.id)" :key="lot.id">
                                                    <td class="py-1 pr-4 font-mono text-xs text-muted">
                                                        {{ lot.acquired_at }}
                                                    </td>
                                                    <td class="px-4 py-1 text-right font-mono">{{ lot.open_quantity }}</td>
                                                    <td class="px-4 py-1 text-right font-mono text-muted">
                                                        {{ lot.acquired_quantity }}
                                                    </td>
                                                    <td class="py-1 pl-4 text-right font-mono">
                                                        {{ usd ? formatAmount(lotBasisShare(lot), usd) : lotBasisShare(lot) }}
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
                <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Register</h2>

                <p v-if="postings.length === 0" class="mt-2 text-sm text-muted">No postings yet.</p>

                <ul
                    v-else
                    class="mt-2 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
                >
                    <li
                        v-for="posting in postings"
                        :key="posting.id"
                        class="grid grid-cols-[5.5rem_minmax(0,1fr)_minmax(0,1fr)_8rem_8.5rem_2rem] items-center gap-x-4 px-4 py-2"
                        :class="{ italic: posting.status === 'pending' }"
                    >
                        <span class="font-mono text-xs text-muted">{{ posting.transaction?.date }}</span>

                        <span class="truncate text-sm">
                            {{ posting.transaction?.payee?.name ?? posting.transaction?.memo ?? '—' }}
                            <span v-if="posting.transaction?.payee && posting.transaction?.memo" class="text-muted">
                                · {{ posting.transaction.memo }}
                            </span>
                        </span>

                        <RouterLink
                            v-if="posting.account"
                            :to="{ name: 'finances.account', params: { id: posting.account.id } }"
                            class="truncate text-sm text-muted transition-colors hover:text-accent"
                            :title="posting.account.path"
                        >
                            {{ posting.account.path }}
                        </RouterLink>
                        <span v-else class="truncate text-sm text-muted">—</span>

                        <span class="text-right font-mono text-sm">
                            {{ commodity ? formatAmount(posting.amount, commodity) : posting.amount }}
                        </span>

                        <span class="text-right font-mono text-sm text-muted">
                            {{ posting.running_balance !== undefined && commodity
                                ? formatAmount(posting.running_balance, commodity)
                                : '—' }}
                        </span>

                        <button
                            v-if="posting.status !== null"
                            type="button"
                            class="w-8 shrink-0 rounded-sm text-right font-mono text-sm not-italic transition-colors hover:bg-edge/60 hover:text-foreground"
                            :class="statusClass(posting.status)"
                            :title="`${statusLabel(posting.status)} — click to mark ${statusLabel(nextStatus(posting.status)).toLowerCase()}`"
                            @click="flipStatus(posting)"
                        >
                            <span aria-hidden="true">{{ statusSymbol(posting.status) }}</span>
                            <span class="sr-only">{{ statusLabel(posting.status) }}</span>
                        </button>
                        <span v-else class="w-8 shrink-0"></span>
                    </li>
                </ul>

                <PaginationBar :page="page" :last-page="lastPage" @change="changePage" />
            </section>
        </template>

        <SkeletonList v-else />
    </div>
</template>
