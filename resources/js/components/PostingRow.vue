<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import DateInput from './DateInput.vue';
import ComboBox from './ComboBox.vue';
import LotPicker from './LotPicker.vue';
import type { Account, Commodity, Lot, PostingDraft, PostingStatus } from '../types';

const props = defineProps<{
    draft: PostingDraft;
    index: number;
    accounts: Account[];
    commodities: Commodity[];
    baseCurrency: Commodity;
    transactionDate: string;
    knownLots: Record<number, Lot>;
    errors: Record<string, string[]>;
    removable: boolean;
    suggestedAmount: string | null;
    autofocus?: boolean;
}>();

const emit = defineEmits<{ remove: []; lotsLoaded: [Lot[]]; createAccount: []; amountEnter: [KeyboardEvent] }>();
const memoOpen = ref(props.draft.memo !== '');
const accountPicker = ref<{ focus: () => void } | null>(null);

onMounted(() => {
    if (props.autofocus) {
        accountPicker.value?.focus();
    }
});

const statusOptions: Array<{ value: PostingStatus; label: string }> = [
    { value: 'pending', label: 'Pending' },
    { value: 'cleared', label: 'Cleared' },
    { value: 'reconciled', label: 'Reconciled' },
];

const accountOptions = computed(() => {
    const parentIds = new Set(
        props.accounts.map((account) => account.parent_id).filter((id) => id !== null),
    );

    return props.accounts
        .filter(
            (account) =>
                !parentIds.has(account.id)
                || account.allow_postings
                || account.id === props.draft.financial_account_id,
        )
        .toSorted((a, b) => a.path.localeCompare(b.path))
        .map((account) => ({ value: account.id, label: account.path }));
});

const commodityOptions = computed(() =>
    props.commodities.map((commodity) => ({ value: commodity.id, label: commodity.code })),
);

const commodity = computed(
    () =>
        props.commodities.find(
            (candidate) => candidate.id === props.draft.financial_commodity_id,
        ) ?? null,
);

const account = computed(
    () =>
        props.accounts.find((candidate) => candidate.id === props.draft.financial_account_id) ??
        null,
);

const carriesStatus = computed(
    () => account.value?.account_type === 'asset' || account.value?.account_type === 'liability',
);

watch(
    carriesStatus,
    (statusApplies) => {
        if (statusApplies && props.draft.status === null) {
            props.draft.status = 'cleared';
        }

        if (!statusApplies) {
            props.draft.status = null;
        }
    },
    { immediate: true },
);

const needsLot = computed(
    () => commodity.value !== null && commodity.value.id !== props.baseCurrency.id,
);

const attachedLot = computed(() =>
    props.draft.financial_lot_id !== null
        ? (props.knownLots[props.draft.financial_lot_id] ?? null)
        : null,
);

function errorFor(field: string): string | null {
    return props.errors[`postings.${props.index}.${field}`]?.[0] ?? null;
}
</script>

<template>
    <div class="px-3 py-2.5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(16rem,1fr)_9rem_8rem_8rem_2rem] lg:items-start">
            <div class="flex flex-col gap-1.5">
                <span class="field-label lg:sr-only">Account</span>
                <ComboBox
                    ref="accountPicker"
                    v-model="draft.financial_account_id"
                    :options="accountOptions"
                    fuzzy
                    creatable
                    create-option-label="Create new account"
                    @create="emit('createAccount')"
                />
                <p v-if="errorFor('financial_account_id')" class="text-sm text-danger">
                    {{ errorFor('financial_account_id') }}
                </p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="field-label lg:sr-only">Amount</span>
                <span class="relative">
                    <input
                        v-model="draft.amount"
                        type="text"
                        inputmode="decimal"
                        class="input w-full text-right font-mono"
                        :class="suggestedAmount !== null ? 'pr-8' : ''"
                        @keydown.enter="emit('amountEnter', $event)"
                    />
                    <button
                        v-if="suggestedAmount !== null"
                        type="button"
                        class="absolute top-1/2 right-2 -translate-y-1/2 font-mono text-sm text-accent transition-colors hover:text-foreground"
                        tabindex="-1"
                        title="Fill balancing amount"
                        aria-label="Fill balancing amount"
                        @click="draft.amount = suggestedAmount"
                    >
                        =
                    </button>
                </span>
                <p v-if="errorFor('amount')" class="text-sm text-danger">{{ errorFor('amount') }}</p>
            </label>

            <div class="flex flex-col gap-1.5">
                <span class="field-label lg:sr-only">Commodity</span>
                <ComboBox v-model="draft.financial_commodity_id" :options="commodityOptions" />
                <p v-if="errorFor('financial_commodity_id')" class="text-sm text-danger">
                    {{ errorFor('financial_commodity_id') }}
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <span v-if="carriesStatus" class="field-label lg:sr-only">Status</span>
                <template v-if="carriesStatus">
                    <ComboBox v-model="draft.status" :options="statusOptions" />
                    <p v-if="errorFor('status')" class="text-sm text-danger">
                        {{ errorFor('status') }}
                    </p>
                </template>
            </div>

            <button
                v-if="removable"
                type="button"
                class="self-center font-mono text-lg leading-none text-muted transition-colors hover:text-danger"
                tabindex="-1"
                title="Remove posting"
                aria-label="Remove posting"
                @click="emit('remove')"
            >
                ×
            </button>
            <span v-else class="hidden lg:block"></span>
        </div>

        <div
            v-if="memoOpen || needsLot"
            class="mt-2 grid grid-cols-1 gap-3 border-l border-edge pl-3 sm:grid-cols-2 lg:ml-3"
            :class="memoOpen ? 'lg:grid-cols-4' : 'lg:grid-cols-3'"
        >
            <label
                v-if="memoOpen"
                class="flex flex-col gap-1.5"
                :class="needsLot ? '' : 'sm:col-span-2 lg:col-span-4'"
            >
                <span class="field-label">Posting memo</span>
                <input v-model="draft.memo" type="text" class="input" />
                <p v-if="errorFor('memo')" class="text-sm text-danger">{{ errorFor('memo') }}</p>
            </label>

            <template v-if="needsLot && commodity">
                <div class="flex flex-col gap-1.5">
                    <span class="field-label">Lot</span>
                    <div class="flex gap-1 font-mono text-xs">
                        <button
                            type="button"
                            class="rounded-sm border px-2 py-1 tracking-wider uppercase transition-colors"
                            :class="draft.lotMode === 'existing' ? 'border-accent text-foreground' : 'border-edge text-muted hover:text-foreground'"
                            @click="draft.lotMode = 'existing'"
                        >
                            Existing
                        </button>
                        <button
                            type="button"
                            class="rounded-sm border px-2 py-1 tracking-wider uppercase transition-colors"
                            :class="draft.lotMode === 'new' ? 'border-accent text-foreground' : 'border-edge text-muted hover:text-foreground'"
                            @click="draft.lotMode = 'new'"
                        >
                            New
                        </button>
                    </div>
                </div>

                <template v-if="draft.lotMode === 'existing'">
                    <div class="flex flex-col gap-1.5 lg:col-span-2">
                        <span class="field-label">From lot</span>
                        <LotPicker
                            v-model="draft.financial_lot_id"
                            :account-id="draft.financial_account_id"
                            :commodity="commodity"
                            :base-currency="baseCurrency"
                            :date="transactionDate"
                            :attached-lot="attachedLot"
                            @lots-loaded="emit('lotsLoaded', $event)"
                        />
                        <p v-if="errorFor('financial_lot_id')" class="text-sm text-danger">
                            {{ errorFor('financial_lot_id') }}
                        </p>
                    </div>
                </template>

                <template v-else>
                    <label class="flex flex-col gap-1.5">
                        <span class="field-label">
                            Cost ({{ draft.lotCostMode === 'total' ? 'total' : 'per unit' }})
                            <button
                                type="button"
                                tabindex="-1"
                                class="ml-1 font-mono text-xs tracking-wider text-muted uppercase hover:text-foreground"
                                @click.prevent="draft.lotCostMode = draft.lotCostMode === 'total' ? 'unit' : 'total'"
                            >
                                Swap
                            </button>
                        </span>
                        <input v-model="draft.lotCost" type="text" inputmode="decimal" class="input text-right font-mono" />
                        <p v-if="errorFor('lot.cost') || errorFor('lot')" class="text-sm text-danger">
                            {{ errorFor('lot.cost') ?? errorFor('lot') }}
                        </p>
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="field-label">Acquired</span>
                        <DateInput v-model="draft.lotAcquiredAt" />
                        <p v-if="errorFor('lot.acquired_at')" class="text-sm text-danger">
                            {{ errorFor('lot.acquired_at') }}
                        </p>
                    </label>
                </template>
            </template>
        </div>

        <div v-if="!memoOpen" class="mt-1.5 pl-1">
            <button
                type="button"
                tabindex="-1"
                class="font-mono text-xs tracking-wider text-muted uppercase transition-colors hover:text-foreground"
                @click="memoOpen = true"
            >
                + Memo
            </button>
        </div>
    </div>
</template>
