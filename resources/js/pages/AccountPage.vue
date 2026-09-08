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
import type { Account, AccountBalance, Commodity, Lot, Paginated, Posting } from '../types';

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
                                        <template v-else>
                                            {{ holding.commodity.code }}
                                            <span class="text-muted"> · {{ holding.commodity.name }}</span>
                                        </template>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono">
                                        {{ formatAmount(holding.quantity, holding.commodity) }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono">
                                        {{ holding.basis !== null && usd ? formatAmount(holding.basis, usd) : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono text-muted">—</td>
                                    <td class="px-4 py-2 text-right font-mono text-muted">—</td>
                                </tr>
                                <tr v-if="expandedCommodities.includes(holding.commodity.id)">
                                    <td colspan="5" class="bg-background/40 px-4 py-3">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="font-mono text-xs tracking-wider text-muted uppercase">
                                                    <th class="py-1 pr-4 text-left font-medium">Acquired</th>
                                                    <th class="px-4 py-1 text-right font-medium">Open</th>
                                                    <th class="px-4 py-1 text-right font-medium">Acquired qty</th>
                                                    <th class="py-1 pl-4 text-right font-medium">Basis</th>
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

                        <span class="truncate text-sm text-muted" :title="counterAccountLabel(posting)">
                            {{ counterAccountLabel(posting) }}
                        </span>

                        <span class="text-right font-mono text-sm">
                            {{ posting.commodity ? formatAmount(posting.amount, posting.commodity) : posting.amount }}
                        </span>

                        <span class="text-right font-mono text-sm text-muted">
                            {{ posting.running_balance !== undefined && posting.commodity
                                ? formatAmount(posting.running_balance, posting.commodity)
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
