<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import AccountForm from './AccountForm.vue';
import DateInput from './DateInput.vue';
import ComboBox from './ComboBox.vue';
import ModalDialog from './ModalDialog.vue';
import PostingRow from './PostingRow.vue';
import {
    allocateBasis,
    decimalToScaledInteger,
    negateAmount,
    scaledIntegerToDecimal,
    formatAmount,
    parseAmount,
    totalCostFromUnitCost,
} from '../money';
import type {
    Account,
    BankTransaction,
    Commodity,
    Institution,
    Lot,
    Payee,
    Posting,
    PostingDraft,
    RecurrenceFrequency,
    RecurringPosting,
    RecurringTransaction,
    Transaction,
} from '../types';

const props = defineProps<{
    transaction: Transaction | null;
    schedule?: RecurringTransaction | null;
    duplicateOf?: Transaction | null;
    bankTransaction?: BankTransaction | null;
    repeatRequired?: boolean;
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
    bankTransactionUnmatched: [];
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
        status: 'pending',
        financial_account_id: null,
        financial_commodity_id: baseCurrency.value.id,
        amount: '',
        memo: '',
        financial_lot_id: null,
        financial_bank_transaction_id: null,
        bankTransaction: null,
        lotMode: 'existing',
        lotCost: '',
        lotCostMode: 'total',
        lotAcquiredAt: '',
    };
}

function draftFromPosting(posting: Posting): PostingDraft {
    if (posting.lot) {
        registerLots([posting.lot]);
    }

    return {
        id: posting.id,
        status: posting.status,
        financial_account_id: posting.financial_account_id,
        financial_commodity_id: posting.financial_commodity_id,
        amount: posting.amount,
        memo: posting.memo ?? '',
        financial_lot_id: posting.financial_lot_id,
        financial_bank_transaction_id: null,
        bankTransaction: posting.bank_transaction ?? null,
        lotMode: posting.lot && decimalToScaledInteger(posting.amount) > 0n ? 'new' : 'existing',
        lotCost: posting.lot ? posting.lot.cost : '',
        lotCostMode: 'total',
        lotAcquiredAt: posting.lot?.acquired_at ?? '',
    };
}

function draftFromRecurringPosting(posting: RecurringPosting): PostingDraft {
    return {
        ...emptyDraft(),
        status: posting.status,
        financial_account_id: posting.financial_account_id,
        financial_commodity_id: posting.financial_commodity_id,
        amount: posting.amount,
        memo: posting.memo ?? '',
    };
}

const prefill = props.transaction ?? props.schedule ?? props.duplicateOf ?? null;

function payeeIdNamed(name: string | null): number | null {
    if (name === null) {
        return null;
    }

    return props.payees.find((payee) => payee.name.toLowerCase() === name.trim().toLowerCase())?.id ?? null;
}

const form = ref({
    date: props.transaction?.date ?? props.schedule?.next_due_on ?? props.bankTransaction?.posted_on ?? new Date().toISOString().slice(0, 10),
    financial_payee_id: prefill?.financial_payee_id ?? payeeIdNamed(props.bankTransaction?.payee ?? null),
    memo: prefill?.memo ?? props.bankTransaction?.description ?? '',
});

function initialDrafts(): PostingDraft[] {
    if (props.transaction?.postings) {
        return props.transaction.postings.map(draftFromPosting);
    }

    if (props.schedule?.postings) {
        return props.schedule.postings.map(draftFromRecurringPosting);
    }

    if (props.duplicateOf?.postings) {
        return props.duplicateOf.postings.map((posting) => ({ ...draftFromPosting(posting), id: null, bankTransaction: null }));
    }

    if (props.bankTransaction) {
        return [
            {
                ...emptyDraft(),
                status: props.bankTransaction.pending ? 'pending' : 'cleared',
                financial_account_id: props.bankTransaction.financial_account_id,
                amount: props.bankTransaction.amount,
                financial_bank_transaction_id: props.bankTransaction.id,
                bankTransaction: props.bankTransaction,
            },
            { ...emptyDraft(), amount: negateAmount(props.bankTransaction.amount) },
        ];
    }

    return [emptyDraft(), emptyDraft()];
}

const postings = ref<PostingDraft[]>(initialDrafts());

const repeatAvailable = props.transaction === null;

const repeat = ref<{ enabled: boolean; frequency: RecurrenceFrequency; interval: string; ends_on: string; lead_days: string }>({
    enabled: props.schedule !== null && props.schedule !== undefined || props.repeatRequired === true,
    frequency: props.schedule?.frequency ?? 'monthly',
    interval: String(props.schedule?.interval ?? 1),
    ends_on: props.schedule?.ends_on ?? '',
    lead_days: props.schedule?.lead_days === null || props.schedule?.lead_days === undefined ? '' : String(props.schedule.lead_days),
});

const frequencyOptions = computed<Array<{ value: RecurrenceFrequency; label: string }>>(() => {
    const plural = Number(repeat.value.interval) === 1 ? '' : 's';

    return [
        { value: 'daily', label: `day${plural}` },
        { value: 'weekly', label: `week${plural}` },
        { value: 'monthly', label: `month${plural}` },
        { value: 'yearly', label: `year${plural}` },
    ];
});

const isSchedule = computed(() => repeatAvailable && repeat.value.enabled);

const title = computed(() => {
    if (props.transaction) {
        return 'Edit transaction';
    }

    if (props.schedule) {
        return 'Edit schedule';
    }

    return isSchedule.value ? 'New schedule' : 'New transaction';
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const dateInput = ref<{ focus: () => void } | null>(null);
const autofocusPostingIndex = ref<number | null>(null);

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

function lotCostDecimal(draft: PostingDraft): string | null {
    const commodity = commodityById(draft.financial_commodity_id);

    if (draft.lotCostMode === 'total') {
        return parseAmount(draft.lotCost);
    }

    const unitCost = parseAmount(draft.lotCost);
    const quantity = commodity === null ? null : parseAmount(draft.amount);

    if (unitCost === null || quantity === null || commodity === null || decimalToScaledInteger(unitCost) < 0n) {
        return null;
    }

    return totalCostFromUnitCost(unitCost, quantity);
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
        const amount = commodity === null ? null : parseAmount(draft.amount);

        if (commodity === null || amount === null) {
            return { faceSums: [], residual: null, approximate: false };
        }

        faceSumsByCommodity.set(
            commodity.id,
            (faceSumsByCommodity.get(commodity.id) ?? 0n) + decimalToScaledInteger(amount),
        );

        if (commodity.id === baseCurrency.value.id) {
            residual += decimalToScaledInteger(amount);
        } else if (draft.lotMode === 'new') {
            const cost = lotCostDecimal(draft);

            if (cost === null) {
                return { faceSums: [], residual: null, approximate: false };
            }

            residual += decimalToScaledInteger(cost);
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
        if (decimalToScaledInteger(group.total) <= 0n) {
            approximate = true;
            continue;
        }

        const shares = allocateBasis(
            group.cost,
            group.total,
            group.amounts.map((amount) => {
                const value = decimalToScaledInteger(amount);

                return scaledIntegerToDecimal(value < 0n ? -value : value);
            }),
        );

        group.amounts.forEach((amount, index) => {
            residual += (decimalToScaledInteger(amount) < 0n ? -1n : 1n) * decimalToScaledInteger(shares[index]);
        });
    }

    const faceSums = [...faceSumsByCommodity.entries()].map(([commodityId, sum]) => {
        const commodity = commodityById(commodityId);

        return commodity === null ? '' : formatAmount(scaledIntegerToDecimal(sum), commodity);
    });

    return { faceSums, residual: scaledIntegerToDecimal(residual), approximate };
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

    return scaledIntegerToDecimal(-decimalToScaledInteger(precedingBalance.residual));
});

function addPosting(): void {
    postings.value.push(emptyDraft());
}

/**
 * Enter on the last posting's amount starts the next posting instead of
 * submitting; anywhere else Enter still saves.
 */
function handleAmountEnter(index: number, event: KeyboardEvent): void {
    if (index !== postings.value.length - 1) {
        return;
    }

    event.preventDefault();
    autofocusPostingIndex.value = postings.value.length;
    addPosting();
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
        amount: commodity === null ? null : parseAmount(draft.amount),
        memo: draft.memo || null,
    };

    if (draft.id !== null) {
        payload.id = draft.id;
    }

    if (draft.financial_bank_transaction_id !== null) {
        payload.financial_bank_transaction_id = draft.financial_bank_transaction_id;
    }

    if (commodity !== null && commodity.id !== baseCurrency.value.id) {
        if (draft.lotMode === 'existing') {
            payload.financial_lot_id = draft.financial_lot_id;
        } else {
            payload.lot = {
                cost: lotCostDecimal(draft),
                ...(draft.lotAcquiredAt ? { acquired_at: draft.lotAcquiredAt } : {}),
            };
        }
    }

    return payload;
}

function transactionPayload(): Record<string, unknown> {
    return {
        date: form.value.date,
        financial_payee_id: form.value.financial_payee_id,
        memo: form.value.memo || null,
        postings: postings.value.map(postingPayload),
    };
}

function schedulePayload(): Record<string, unknown> {
    return {
        ...transactionPayload(),
        frequency: repeat.value.frequency,
        interval: Number(repeat.value.interval),
        ends_on: repeat.value.ends_on || null,
        lead_days: repeat.value.lead_days.trim() === '' ? null : Number(repeat.value.lead_days),
    };
}

const scheduleUrl = props.schedule
    ? `/api/v1/financial/recurring-transactions/${props.schedule.id}`
    : '/api/v1/financial/recurring-transactions';
const previewUrl = props.schedule
    ? `/api/v1/financial/recurring-transactions/preview/${props.schedule.id}`
    : '/api/v1/financial/recurring-transactions/preview';

const previewDueDates = ref<string[] | null>(null);
let previewDebounce: number | undefined;
let previewRequest = 0;

async function loadPreview(): Promise<void> {
    const request = ++previewRequest;

    const incomplete = postings.value.some(
        (draft) => draft.financial_account_id === null || parseAmount(draft.amount) === null,
    );

    if (!isSchedule.value || incomplete) {
        previewDueDates.value = null;
        return;
    }

    try {
        const response = await axios.post<{ data: { due_dates: string[] } }>(previewUrl, schedulePayload());

        if (request === previewRequest) {
            previewDueDates.value = response.data.data.due_dates;
        }
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            if (request === previewRequest) {
                previewDueDates.value = null;
            }
        } else {
            throw error;
        }
    }
}

watch(
    [form, postings, repeat],
    () => {
        window.clearTimeout(previewDebounce);
        previewDebounce = window.setTimeout(() => void loadPreview(), 300);
    },
    { deep: true, immediate: true },
);

async function unmatchBankTransaction(draft: PostingDraft): Promise<void> {
    if (!draft.bankTransaction || !confirm('Unmatch this bank transaction from the posting?')) {
        return;
    }

    await axios.post(`/api/v1/financial/bank-transactions/${draft.bankTransaction.id}/unmatch`);
    draft.bankTransaction = null;
    emit('bankTransactionUnmatched');
}

async function save(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    try {
        if (props.transaction) {
            await axios.put(`/api/v1/financial/transactions/${props.transaction.id}`, transactionPayload());
        } else if (isSchedule.value) {
            if (props.schedule) {
                await axios.put(scheduleUrl, schedulePayload());
            } else {
                await axios.post(scheduleUrl, schedulePayload());
            }
        } else {
            await axios.post('/api/v1/financial/transactions', transactionPayload());
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
        <h2 class="font-mono text-xs tracking-wider text-muted uppercase">{{ title }}</h2>

        <div class="mt-4 grid grid-cols-1 gap-4" :class="repeatAvailable ? 'sm:grid-cols-[1fr_1fr_1fr_auto]' : 'sm:grid-cols-3'">
            <label class="flex flex-col gap-1.5">
                <span class="field-label">Date</span>
                <DateInput ref="dateInput" v-model="form.date" required />
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

            <label v-if="repeatAvailable" class="flex flex-col gap-1.5">
                <span class="field-label">Repeat</span>
                <span class="flex h-[2.375rem] items-center">
                    <input
                        v-model="repeat.enabled"
                        type="checkbox"
                        class="size-4 accent-accent"
                        :disabled="repeatRequired"
                    />
                </span>
            </label>
        </div>

        <div v-if="isSchedule" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-2 text-sm text-muted">
            <span class="field-label">Repeats</span>
            <span>every</span>
            <input
                v-model="repeat.interval"
                type="number"
                min="1"
                max="365"
                class="input w-16 text-right font-mono"
                aria-label="Interval"
            />
            <ComboBox v-model="repeat.frequency" :options="frequencyOptions" class="w-28" />
            <span>until</span>
            <DateInput v-model="repeat.ends_on" aria-label="End date" />
            <span>, posting</span>
            <input
                v-model="repeat.lead_days"
                type="number"
                min="0"
                max="365"
                class="input w-16 text-right font-mono"
                placeholder="14"
                aria-label="Lead days"
            />
            <span>days ahead</span>

            <p v-if="errors.frequency || errors.interval || errors.ends_on || errors.lead_days" class="basis-full text-danger">
                {{ (errors.frequency ?? errors.interval ?? errors.ends_on ?? errors.lead_days)?.[0] }}
            </p>
        </div>

        <p v-if="isSchedule" class="mt-2 text-sm text-muted">
            <template v-if="previewDueDates === null">
                <span class="font-mono text-xs tracking-wider uppercase">Complete the form to preview</span>
            </template>
            <template v-else-if="previewDueDates.length === 0">
                Nothing posts now. The first occurrence is due {{ form.date }}.
            </template>
            <template v-else>
                Posts now:
                <span class="font-mono text-xs text-foreground">{{ previewDueDates.join(' · ') }}</span>
            </template>
        </p>

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
                    :autofocus="index === autofocusPostingIndex"
                    @amount-enter="handleAmountEnter(index, $event)"
                    @remove="removePosting(index)"
                    @unmatch="unmatchBankTransaction(draft)"
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
                    tabindex="-1"
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
                                : `Off by ${balance.residual} ${baseCurrency.code}`
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
