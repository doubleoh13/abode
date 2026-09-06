<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, ref } from 'vue';
import ComboBox from './ComboBox.vue';
import PostingRow from './PostingRow.vue';
import { allocateBasis, amountToInput, formatAmount, parseAmount } from '../money';
import type {
    Account,
    Commodity,
    Lot,
    Posting,
    PostingDraft,
    Transaction,
} from '../types';

const props = defineProps<{
    transaction: Transaction | null;
    accounts: Account[];
    commodities: Commodity[];
    payees: Array<{ id: number; name: string }>;
}>();

const emit = defineEmits<{ saved: []; cancelled: [] }>();

const baseCurrency = computed(() => {
    const usd = props.commodities.find((commodity) => commodity.code === 'USD');

    if (!usd) {
        throw new Error('The base currency is missing from the commodity list.');
    }

    return usd;
});

const knownLots = ref<Record<number, Lot>>({});

function registerLots(lots: Lot[]): void {
    for (const lot of lots) {
        knownLots.value[lot.id] = lot;
    }
}

function emptyDraft(): PostingDraft {
    return {
        id: null,
        status: 'cleared',
        financial_account_id: null,
        financial_commodity_id: baseCurrency.value.id,
        amount: '',
        memo: '',
        financial_lot_id: null,
        lotMode: 'existing',
        lotCost: '',
        lotCostMode: 'total',
        lotAcquiredAt: '',
    };
}

function draftFromPosting(posting: Posting): PostingDraft {
    const precision = posting.commodity?.precision ?? 0;

    if (posting.lot) {
        registerLots([posting.lot]);
    }

    return {
        id: posting.id,
        status: posting.status,
        financial_account_id: posting.financial_account_id,
        financial_commodity_id: posting.financial_commodity_id,
        amount: amountToInput(posting.amount, precision),
        memo: posting.memo ?? '',
        financial_lot_id: posting.financial_lot_id,
        lotMode: posting.lot && posting.amount > 0 ? 'new' : 'existing',
        lotCost: posting.lot ? amountToInput(posting.lot.cost, baseCurrency.value.precision) : '',
        lotCostMode: 'total',
        lotAcquiredAt: posting.lot?.acquired_at ?? '',
    };
}

const form = ref({
    date: props.transaction?.date ?? new Date().toISOString().slice(0, 10),
    financial_payee_id: props.transaction?.financial_payee_id ?? null,
    memo: props.transaction?.memo ?? '',
});

const postings = ref<PostingDraft[]>(
    props.transaction?.postings?.map(draftFromPosting) ?? [emptyDraft(), emptyDraft()],
);

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const dateInput = ref<HTMLInputElement | null>(null);

onMounted(() => dateInput.value?.focus());

const payeeOptions = computed(() =>
    props.payees.map((payee) => ({ value: payee.id, label: payee.name })),
);

function commodityById(id: number | null): Commodity | null {
    return props.commodities.find((commodity) => commodity.id === id) ?? null;
}

function lotCostMinor(draft: PostingDraft): number | null {
    const quantity = commodityById(draft.financial_commodity_id);

    if (draft.lotCostMode === 'total') {
        return parseAmount(draft.lotCost, baseCurrency.value.precision);
    }

    const unitCost = Number(draft.lotCost);
    const wholeUnits =
        quantity === null ? null : parseAmount(draft.amount, quantity.precision);

    if (!Number.isFinite(unitCost) || wholeUnits === null || quantity === null) {
        return null;
    }

    return Math.round((unitCost * wholeUnits) / 10 ** quantity.precision * 10 ** baseCurrency.value.precision);
}

const balance = computed<{ faceSums: string[]; residual: number | null; approximate: boolean }>(() => {
    const faceSumsByCommodity = new Map<number, number>();
    const lotGroups = new Map<number, { cost: number; total: number; amounts: number[] }>();
    let residual = 0;
    let approximate = false;

    for (const draft of postings.value) {
        const commodity = commodityById(draft.financial_commodity_id);
        const amount = commodity === null ? null : parseAmount(draft.amount, commodity.precision);

        if (commodity === null || amount === null) {
            return { faceSums: [], residual: null, approximate: false };
        }

        faceSumsByCommodity.set(
            commodity.id,
            (faceSumsByCommodity.get(commodity.id) ?? 0) + amount,
        );

        if (commodity.id === baseCurrency.value.id) {
            residual += amount;
        } else if (draft.lotMode === 'new') {
            const cost = lotCostMinor(draft);

            if (cost === null) {
                return { faceSums: [], residual: null, approximate: false };
            }

            residual += cost;
        } else {
            const lot =
                draft.financial_lot_id !== null ? knownLots.value[draft.financial_lot_id] : undefined;

            if (lot === undefined || lot.acquired_quantity === undefined) {
                approximate = true;
                continue;
            }

            const group = lotGroups.get(lot.id) ?? {
                cost: lot.cost,
                total: lot.acquired_quantity,
                amounts: [],
            };
            group.amounts.push(amount);
            lotGroups.set(lot.id, group);
        }
    }

    for (const group of lotGroups.values()) {
        const shares = allocateBasis(
            group.cost,
            group.total,
            group.amounts.map((amount) => Math.abs(amount)),
        );

        group.amounts.forEach((amount, index) => {
            residual += Math.sign(amount) * shares[index];
        });
    }

    const faceSums = [...faceSumsByCommodity.entries()].map(([commodityId, sum]) => {
        const commodity = commodityById(commodityId);

        return commodity === null ? '' : formatAmount(sum, commodity);
    });

    return { faceSums, residual, approximate };
});

function addPosting(): void {
    postings.value.push(emptyDraft());
}

function removePosting(index: number): void {
    postings.value.splice(index, 1);
}

function postingPayload(draft: PostingDraft): Record<string, unknown> {
    const commodity = commodityById(draft.financial_commodity_id);
    const payload: Record<string, unknown> = {
        status: draft.status,
        financial_account_id: draft.financial_account_id,
        financial_commodity_id: draft.financial_commodity_id,
        amount: commodity === null ? null : parseAmount(draft.amount, commodity.precision),
        memo: draft.memo || null,
    };

    if (draft.id !== null) {
        payload.id = draft.id;
    }

    if (commodity !== null && commodity.id !== baseCurrency.value.id) {
        if (draft.lotMode === 'existing') {
            payload.financial_lot_id = draft.financial_lot_id;
        } else {
            payload.lot = {
                cost: lotCostMinor(draft),
                ...(draft.lotAcquiredAt ? { acquired_at: draft.lotAcquiredAt } : {}),
            };
        }
    }

    return payload;
}

async function save(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const payload = {
        date: form.value.date,
        financial_payee_id: form.value.financial_payee_id,
        memo: form.value.memo || null,
        postings: postings.value.map(postingPayload),
    };

    try {
        if (props.transaction) {
            await axios.put(`/api/v1/financial/transactions/${props.transaction.id}`, payload);
        } else {
            await axios.post('/api/v1/financial/transactions', payload);
        }

        emit('saved');
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            errors.value = error.response.data.errors;
        } else {
            throw error;
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <form class="rounded-md border border-edge bg-surface p-6" @submit.prevent="save">
        <h2 class="font-mono text-xs tracking-wider text-muted uppercase">
            {{ transaction ? 'Edit transaction' : 'New transaction' }}
        </h2>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <label class="flex flex-col gap-1.5">
                <span class="field-label">Date</span>
                <input ref="dateInput" v-model="form.date" type="date" required class="input" />
                <p v-if="errors.date" class="text-sm text-danger">{{ errors.date[0] }}</p>
            </label>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Payee</span>
                <ComboBox v-model="form.financial_payee_id" :options="payeeOptions" nullable />
                <p v-if="errors.financial_payee_id" class="text-sm text-danger">
                    {{ errors.financial_payee_id[0] }}
                </p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Memo</span>
                <input v-model="form.memo" type="text" class="input" />
                <p v-if="errors.memo" class="text-sm text-danger">{{ errors.memo[0] }}</p>
            </label>
        </div>

        <div class="mt-6 flex items-center justify-between">
            <span class="field-label">Postings</span>
            <button type="button" class="button-subtle" @click="addPosting">Add posting</button>
        </div>

        <div class="mt-2 flex flex-col gap-3">
            <PostingRow
                v-for="(draft, index) in postings"
                :key="index"
                :draft="draft"
                :index="index"
                :accounts="accounts"
                :commodities="commodities"
                :base-currency="baseCurrency"
                :transaction-date="form.date"
                :known-lots="knownLots"
                :errors="errors"
                :removable="postings.length > 2"
                @remove="removePosting(index)"
                @lots-loaded="registerLots"
            />
        </div>

        <p v-if="errors.postings" class="mt-3 text-sm text-danger">{{ errors.postings[0] }}</p>

        <div class="mt-3 flex items-center justify-between rounded-md border border-edge bg-background/40 px-4 py-2 font-mono text-xs">
            <span class="tracking-wider text-muted uppercase">
                {{ balance.faceSums.join(' · ') || '—' }}
            </span>
            <span
                v-if="balance.residual !== null"
                :class="balance.residual === 0 ? 'tracking-wider text-accent uppercase' : 'text-danger'"
            >
                {{
                    balance.residual === 0
                        ? `Balanced${balance.approximate ? ' (approx.)' : ''}`
                        : `Off by ${formatAmount(balance.residual, baseCurrency)}`
                }}
            </span>
            <span v-else class="tracking-wider text-muted uppercase">Incomplete</span>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Save</button>
            <button type="button" class="button-subtle" @click="emit('cancelled')">Cancel</button>
        </div>
    </form>
</template>
