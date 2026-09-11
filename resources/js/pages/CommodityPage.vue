<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { accountRoute, setPageTitle } from '../router';
import CommodityForm from '../components/CommodityForm.vue';
import DateInput from '../components/DateInput.vue';
import ModalDialog from '../components/ModalDialog.vue';
import PaginationBar from '../components/PaginationBar.vue';
import PriceChart from '../components/PriceChart.vue';
import SkeletonList from '../components/SkeletonList.vue';
import PostingStatusMenu from '../components/PostingStatusMenu.vue';
import {
    accountPathAncestor,
    accountPathLeaf,
} from '../journal';
import {
    allocateBasis,
    decimalToScaledInteger,
    formatAmount,
    marketValue,
    scaledIntegerToDecimal,
    subtractAmounts,
} from '../money';
import type { Account, Commodity, CommodityBalance, CommodityPrice, Lot, Paginated, Posting, PostingStatus } from '../types';

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

const totalMarket = computed(() =>
    commodity.value && commodity.value.kind !== 'currency'
        ? marketValue(totalHeld.value, commodity.value)
        : null,
);

const totalUnrealized = computed(() =>
    totalMarket.value !== null && totalBasis.value !== null
        ? subtractAmounts(totalMarket.value, totalBasis.value)
        : null,
);

function formatUsd(amount: string | null): string {
    return amount !== null && usd.value ? formatAmount(amount, usd.value) : '—';
}

function amountClass(amount: string | null): string {
    return amount !== null && amount.startsWith('-') ? 'text-danger' : '';
}

function lotUnrealized(lot: Lot): string | null {
    const market = commodity.value ? marketValue(lot.open_quantity ?? '0', commodity.value) : null;

    return market !== null ? subtractAmounts(market, lotBasisShare(lot)) : null;
}

const priceSeries = ref<Array<[string, string]>>([]);
const pricePoints = ref<CommodityPrice[]>([]);
const pricePage = ref(1);
const priceLastPage = ref(1);

async function loadPriceSeries(): Promise<void> {
    priceSeries.value = (
        await axios.get<{ data: Array<[string, string]> }>(
            `/api/v1/financial/commodities/${commodityId.value}/price-series`,
        )
    ).data.data;
}

async function loadPricePoints(): Promise<void> {
    const response = (
        await axios.get<Paginated<CommodityPrice>>('/api/v1/financial/commodity-prices', {
            params: { financial_commodity_id: commodityId.value, page: pricePage.value },
        })
    ).data;

    pricePoints.value = response.data;
    priceLastPage.value = response.meta.last_page;
}

async function changePricePage(target: number): Promise<void> {
    pricePage.value = target;
    await loadPricePoints();
}

const pricePointsOpen = ref(false);
const priceFormOpen = ref(false);
const priceForm = reactive({ priced_at: '', price: '' });
const priceErrors = ref<Record<string, string[]>>({});

function openPriceForm(): void {
    priceForm.priced_at = new Date().toISOString().slice(0, 10);
    priceForm.price = '';
    priceErrors.value = {};
    priceFormOpen.value = true;
}

const editFormOpen = ref(false);

async function commoditySaved(): Promise<void> {
    editFormOpen.value = false;
    await reloadPrices();
}

async function reloadPrices(): Promise<void> {
    const [commodityResponse] = await Promise.all([
        axios.get<{ data: Commodity }>(`/api/v1/financial/commodities/${commodityId.value}`),
        loadPriceSeries(),
        loadPricePoints(),
    ]);

    commodity.value = commodityResponse.data.data;
    setPageTitle(commodity.value.code);
}

async function savePrice(): Promise<void> {
    priceErrors.value = {};

    try {
        await axios.post('/api/v1/financial/commodity-prices', {
            financial_commodity_id: commodityId.value,
            priced_at: `${priceForm.priced_at}T00:00:00Z`,
            price: priceForm.price,
        });
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            priceErrors.value = error.response.data.errors ?? {};
            return;
        }

        throw error;
    }

    priceFormOpen.value = false;
    await reloadPrices();
}

async function deletePrice(point: CommodityPrice): Promise<void> {
    if (!confirm(`Delete the ${point.priced_at.slice(0, 10)} price?`)) {
        return;
    }

    await axios.delete(`/api/v1/financial/commodity-prices/${point.id}`);
    await reloadPrices();
}

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

async function setStatus(posting: Posting, status: PostingStatus): Promise<void> {
    await axios.patch(`/api/v1/financial/postings/${posting.id}`, { status });
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
            loadPriceSeries(),
            loadPricePoints(),
        ]);

    commodity.value = commodityResponse.data.data;
    setPageTitle(commodity.value.code);
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
                <button type="button" class="tracking-wider uppercase transition-colors hover:text-foreground" @click="editFormOpen = true">
                    Edit
                </button>
            </span>
        </div>

        <ModalDialog :open="editFormOpen" @close="editFormOpen = false">
            <CommodityForm
                v-if="commodity"
                :key="commodity.id"
                :commodity="commodity"
                @saved="commoditySaved"
                @cancelled="editFormOpen = false"
            />
        </ModalDialog>

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
                <div
                    class="rounded-md border border-edge bg-surface px-4 py-3"
                    :title="commodity?.latest_priced_at ? `priced ${commodity.latest_priced_at}` : undefined"
                >
                    <p class="field-label">Market value</p>
                    <p class="mt-1 truncate font-mono text-lg" :class="{ 'text-muted': totalMarket === null }">
                        {{ formatUsd(totalMarket) }}
                    </p>
                </div>
                <div class="rounded-md border border-edge bg-surface px-4 py-3">
                    <p class="field-label">Unrealized</p>
                    <p
                        class="mt-1 truncate font-mono text-lg"
                        :class="totalUnrealized === null ? 'text-muted' : amountClass(totalUnrealized)"
                    >
                        {{ formatUsd(totalUnrealized) }}
                    </p>
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
                                                :to="accountRoute(holding.account)"
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
                                    <td class="px-4 py-2 text-right font-mono">
                                        {{ formatUsd(commodity && commodity.kind !== 'currency' ? marketValue(holding.balance, commodity) : null) }}
                                    </td>
                                </tr>
                                <tr v-if="holding.account && expandedAccounts.includes(holding.account.id)">
                                    <td colspan="3" class="bg-background/40 px-4 py-3">
                                        <table class="w-full text-sm">
                                            <thead>
                                                <tr class="font-mono text-xs tracking-wider text-muted uppercase">
                                                    <th class="py-1 pr-4 text-left font-medium">Acquired</th>
                                                    <th class="px-4 py-1 text-right font-medium">Open here</th>
                                                    <th class="px-4 py-1 text-right font-medium">Acquired qty</th>
                                                    <th class="px-4 py-1 text-right font-medium">Open basis</th>
                                                    <th class="px-4 py-1 text-right font-medium">Market value</th>
                                                    <th class="py-1 pl-4 text-right font-medium">Unrealized</th>
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
                                                    <td class="px-4 py-1 text-right font-mono">
                                                        {{ formatUsd(lotBasisShare(lot)) }}
                                                    </td>
                                                    <td class="px-4 py-1 text-right font-mono">
                                                        {{ formatUsd(commodity ? marketValue(lot.open_quantity ?? '0', commodity) : null) }}
                                                    </td>
                                                    <td class="py-1 pl-4 text-right font-mono" :class="amountClass(lotUnrealized(lot))">
                                                        {{ formatUsd(lotUnrealized(lot)) }}
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

            <section v-if="commodity && commodity.kind !== 'currency'" class="mt-8">
                <div class="flex items-center justify-between">
                    <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Prices</h2>

                    <div class="flex items-center gap-3">
                        <button type="button" class="button-subtle" @click="pricePointsOpen = true">
                            Price points
                        </button>
                        <button
                            v-if="commodity.price_source === 'manual'"
                            type="button"
                            class="button-subtle"
                            @click="openPriceForm"
                        >
                            New price
                        </button>
                    </div>
                </div>

                <ModalDialog :open="pricePointsOpen" @close="pricePointsOpen = false">
                    <div class="mx-auto flex w-96 flex-col gap-3 rounded-md border border-edge bg-surface p-5">
                        <h3 class="font-mono text-sm tracking-wider uppercase">Price points</h3>

                        <p v-if="pricePoints.length === 0" class="text-sm text-muted">No price points yet.</p>

                        <ul v-else class="max-h-[60vh] divide-y divide-edge overflow-y-auto rounded-md border border-edge">
                            <li
                                v-for="point in pricePoints"
                                :key="point.id"
                                class="group flex items-center justify-between gap-4 px-4 py-1.5"
                            >
                                <span class="font-mono text-xs text-muted">{{ point.priced_at.slice(0, 10) }}</span>

                                <span class="flex items-center gap-3">
                                    <span class="font-mono text-sm">{{ formatUsd(point.price) }}</span>
                                    <button
                                        type="button"
                                        class="font-mono text-xs tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-danger"
                                        @click="deletePrice(point)"
                                    >
                                        Delete
                                    </button>
                                </span>
                            </li>
                        </ul>

                        <PaginationBar :page="pricePage" :last-page="priceLastPage" @change="changePricePage" />
                    </div>
                </ModalDialog>

                <ModalDialog :open="priceFormOpen" @close="priceFormOpen = false">
                    <form
                        class="mx-auto flex w-80 flex-col gap-5 rounded-md border border-edge bg-surface p-5"
                        @submit.prevent="savePrice"
                    >
                        <h3 class="font-mono text-sm tracking-wider uppercase">Price point</h3>

                        <label class="flex flex-col gap-1.5">
                            <span class="field-label">Date</span>
                            <DateInput v-model="priceForm.priced_at" required />
                            <p v-if="priceErrors.priced_at" class="text-sm text-danger">
                                {{ priceErrors.priced_at[0] }}
                            </p>
                        </label>

                        <label class="flex flex-col gap-1.5">
                            <span class="field-label">Price (USD)</span>
                            <input v-model="priceForm.price" type="text" required class="input font-mono" />
                            <p v-if="priceErrors.price" class="text-sm text-danger">
                                {{ priceErrors.price[0] }}
                            </p>
                        </label>

                        <div class="flex justify-end gap-3">
                            <button type="button" class="button-subtle" @click="priceFormOpen = false">
                                Cancel
                            </button>
                            <button type="submit" class="button-primary">Save</button>
                        </div>
                    </form>
                </ModalDialog>

                <div v-if="priceSeries.length > 1 && usd" class="mt-2 rounded-md border border-edge bg-surface p-4">
                    <PriceChart :series="priceSeries" :usd="usd" />
                </div>
                <p v-else class="mt-2 text-sm text-muted">No price points yet.</p>
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
                            :to="accountRoute(posting.account)"
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

                        <PostingStatusMenu
                            v-if="posting.status !== null"
                            :status="posting.status"
                            @select="setStatus(posting, $event)"
                        />
                        <span v-else class="w-8 shrink-0"></span>
                    </li>
                </ul>

                <PaginationBar :page="page" :last-page="lastPage" @change="changePage" />
            </section>
        </template>

        <SkeletonList v-else />
    </div>
</template>
