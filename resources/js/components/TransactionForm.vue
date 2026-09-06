<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, ref } from 'vue';
import AccountForm from './AccountForm.vue';
import ComboBox from './ComboBox.vue';
import ModalDialog from './ModalDialog.vue';
import PostingRow from './PostingRow.vue';
import {
    allocateBasis,
    amountToInput,
    formatAmount,
    parseAmount,
    totalCostFromUnitCost,
} from '../money';
import type {
    Account,
    Commodity,
    Institution,
    Lot,
    Payee,
    Posting,
    PostingDraft,
    Transaction,
} from '../types';

const props = defineProps<{
    transaction: Transaction | null;
    accounts: Account[];
    commodities: Commodity[];
    institutions: Institution[];
    payees: Payee[];
}>();

const emit = defineEmits<{
    saved: [];
    cancelled: [];
    payeeCreated: [Payee];
    accountCreated: [Account];
}>();

const baseCurrency = computed(() => {
    const usd = props.commodities.find((commodity) => commodity.code === 'USD');

    if (!usd) {
        throw new Error('The base currency is missing from the commodity list.');
    }

    return usd;
});

const knownLots = ref<Record<number, Lot>>({});
const availablePayees = ref([...props.payees]);
const availableAccounts = ref([...props.accounts]);
const creatingPayee = ref(false);
const payeeCreationError = ref<string | null>(null);
const accountFormOpen = ref(false);
const accountPostingIndex = ref<number | null>(null);

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
        lotMode: posting.lot && BigInt(posting.amount) > 0n ? 'new' : 'existing',
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
    availablePayees.value
        .toSorted((first, second) => first.name.localeCompare(second.name))
        .map((payee) => ({ value: payee.id, label: payee.name })),
);

async function createPayee(name: string): Promise<void> {
    if (creatingPayee.value) {
        return;
    }

    creatingPayee.value = true;
    payeeCreationError.value = null;

    try {
        const payee = (
            await axios.post<{ data: Payee }>('/api/v1/financial/payees', { name })
        ).data.data;

        availablePayees.value.push(payee);
        form.value.financial_payee_id = payee.id;
        emit('payeeCreated', payee);
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            payeeCreationError.value = error.response.data.errors.name?.[0] ?? 'Unable to create payee.';
        } else {
            throw error;
        }
    } finally {
        creatingPayee.value = false;
    }
}

function selectPayee(payeeId: number | null): void {
    form.value.financial_payee_id = payeeId;
    payeeCreationError.value = null;
}

function openAccountForm(postingIndex: number): void {
    accountPostingIndex.value = postingIndex;
    accountFormOpen.value = true;
}

function closeAccountForm(): void {
    accountFormOpen.value = false;
    accountPostingIndex.value = null;
}

function accountCreated(account: Account): void {
    availableAccounts.value.push(account);

    if (accountPostingIndex.value !== null) {
        postings.value[accountPostingIndex.value].financial_account_id = account.id;
    }

    emit('accountCreated', account);
    closeAccountForm();
}

function commodityById(id: number | null): Commodity | null {
    return props.commodities.find((commodity) => commodity.id === id) ?? null;
}

function lotCostMinor(draft: PostingDraft): string | null {
    const commodity = commodityById(draft.financial_commodity_id);

    if (draft.lotCostMode === 'total') {
        return parseAmount(draft.lotCost, baseCurrency.value.precision);
    }

    const unitCost = parseAmount(draft.lotCost, baseCurrency.value.precision);
    const quantity = commodity === null ? null : parseAmount(draft.amount, commodity.precision);

    if (unitCost === null || quantity === null || commodity === null || BigInt(unitCost) < 0n) {
        return null;
    }

    return totalCostFromUnitCost(unitCost, quantity, commodity.precision);
}

interface Balance {
    faceSums: string[];
    residual: string | null;
    approximate: boolean;
}

function calculateBalance(postingDrafts: PostingDraft[]): Balance {
    const faceSumsByCommodity = new Map<number, bigint>();
    const lotGroups = new Map<number, { cost: string; total: string; amounts: string[] }>();
    let residual = 0n;
    let approximate = false;

    for (const draft of postingDrafts) {
        const commodity = commodityById(draft.financial_commodity_id);
        const amount = commodity === null ? null : parseAmount(draft.amount, commodity.precision);

        if (commodity === null || amount === null) {
            return { faceSums: [], residual: null, approximate: false };
        }

        faceSumsByCommodity.set(
            commodity.id,
            (faceSumsByCommodity.get(commodity.id) ?? 0n) + BigInt(amount),
        );

        if (commodity.id === baseCurrency.value.id) {
            residual += BigInt(amount);
        } else if (draft.lotMode === 'new') {
            const cost = lotCostMinor(draft);

            if (cost === null) {
                return { faceSums: [], residual: null, approximate: false };
            }

            residual += BigInt(cost);
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
            group.amounts.map((amount) => {
                const value = BigInt(amount);

                return (value < 0n ? -value : value).toString();
            }),
        );

        group.amounts.forEach((amount, index) => {
            residual += (BigInt(amount) < 0n ? -1n : 1n) * BigInt(shares[index]);
        });
    }

    const faceSums = [...faceSumsByCommodity.entries()].map(([commodityId, sum]) => {
        const commodity = commodityById(commodityId);

        return commodity === null ? '' : formatAmount(sum.toString(), commodity);
    });

    return { faceSums, residual: residual.toString(), approximate };
}

const balance = computed<Balance>(() => calculateBalance(postings.value));

const balancingAmount = computed<string | null>(() => {
    const lastPosting = postings.value.at(-1);

    if (
        lastPosting === undefined ||
        lastPosting.amount.trim() !== '' ||
        lastPosting.financial_commodity_id !== baseCurrency.value.id
    ) {
        return null;
    }

    const precedingBalance = calculateBalance(postings.value.slice(0, -1));

    if (
        precedingBalance.residual === null ||
        precedingBalance.residual === '0' ||
        precedingBalance.approximate
    ) {
        return null;
    }

    return amountToInput((-BigInt(precedingBalance.residual)).toString(), baseCurrency.value.precision);
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
    <ModalDialog :open="accountFormOpen" nested @close="closeAccountForm">
        <AccountForm
            :account="null"
            :accounts="availableAccounts"
            :institutions="institutions"
            @saved="accountCreated"
            @cancelled="closeAccountForm"
        />
    </ModalDialog>

    <form class="rounded-md border border-edge bg-surface p-5" @submit.prevent="save">
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
                <ComboBox
                    :model-value="form.financial_payee_id"
                    :options="payeeOptions"
                    nullable
                    creatable
                    @update:model-value="selectPayee"
                    @create="createPayee"
                />
                <p v-if="errors.financial_payee_id" class="text-sm text-danger">
                    {{ errors.financial_payee_id[0] }}
                </p>
                <p v-if="payeeCreationError" class="text-sm text-danger">
                    {{ payeeCreationError }}
                </p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Memo</span>
                <input v-model="form.memo" type="text" class="input" />
                <p v-if="errors.memo" class="text-sm text-danger">{{ errors.memo[0] }}</p>
            </label>
        </div>

        <div class="mt-6 overflow-visible rounded-sm border border-edge bg-background/40">
            <div class="hidden grid-cols-[minmax(16rem,1fr)_9rem_8rem_8rem_2rem] gap-3 border-b border-edge px-3 py-2 lg:grid">
                <span class="field-label">Account</span>
                <span class="field-label text-right">Amount</span>
                <span class="field-label">Commodity</span>
                <span class="field-label">Status</span>
                <span></span>
            </div>

            <div class="divide-y divide-edge">
                <PostingRow
                    v-for="(draft, index) in postings"
                    :key="index"
                    :draft="draft"
                    :index="index"
                    :accounts="availableAccounts"
                    :commodities="commodities"
                    :base-currency="baseCurrency"
                    :transaction-date="form.date"
                    :known-lots="knownLots"
                    :errors="errors"
                    :removable="postings.length > 2"
                    :suggested-amount="index === postings.length - 1 ? balancingAmount : null"
                    @remove="removePosting(index)"
                    @lots-loaded="registerLots"
                    @create-account="openAccountForm(index)"
                />
            </div>

            <p v-if="errors.postings" class="border-t border-edge px-3 py-2 text-sm text-danger">
                {{ errors.postings[0] }}
            </p>

            <div class="flex items-center justify-between gap-4 border-t border-edge px-3 py-2 font-mono text-xs">
                <button
                    type="button"
                    class="tracking-wider text-muted uppercase transition-colors hover:text-foreground"
                    @click="addPosting"
                >
                    + Add posting
                </button>

                <div class="flex items-center gap-5">
                    <span class="text-muted">
                        {{ balance.faceSums.join(' · ') || '—' }}
                    </span>
                    <span
                        v-if="balance.residual !== null"
                        :class="balance.residual === '0' ? 'tracking-wider text-accent uppercase' : 'text-danger'"
                    >
                        {{
                            balance.residual === '0'
                                ? `Balanced${balance.approximate ? ' (approx.)' : ''}`
                                : `Off by ${formatAmount(balance.residual, baseCurrency)}`
                        }}
                    </span>
                    <span v-else class="tracking-wider text-muted uppercase">Incomplete</span>
                </div>
            </div>
        </div>

        <div class="mt-5 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Save</button>
            <button type="button" class="button-subtle" @click="emit('cancelled')">Cancel</button>
        </div>
    </form>
</template>
