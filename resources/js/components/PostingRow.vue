<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import DateInput from './DateInput.vue';
import ComboBox from './ComboBox.vue';
import LotPicker from './LotPicker.vue';
import PostingStatusMenu from './PostingStatusMenu.vue';
import { decimalToScaledInteger, formatAmount, parseAmount } from '../money';
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

const emit = defineEmits<{ remove: []; lotsLoaded: [Lot[]]; createAccount: []; amountEnter: [KeyboardEvent]; unmatch: [] }>();
const memoOpen = ref(props.draft.memo !== '');

const bankAmountDiffers = computed(() => {
    const entered = parseAmount(props.draft.amount);

    return props.draft.bankTransaction !== null
        && entered !== null
        && decimalToScaledInteger(props.draft.bankTransaction.amount) !== decimalToScaledInteger(entered);
});
const accountPicker = ref<{ focus: () => void } | null>(null);

onMounted(() => {
    if (props.autofocus) {
        accountPicker.value?.focus();
    }
});


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
            props.draft.status = 'pending';
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

const lockedAccountPath = computed(() => props.accounts.find((candidate) => candidate.id === props.draft.financial_account_id)?.path ?? '—');
const lockedCommodity = computed(() => props.commodities.find((candidate) => candidate.id === props.draft.financial_commodity_id) ?? null);

function changeLockedStatus(status: PostingStatus): void {
    props.draft.status = status;
    props.draft.locked = status === 'reconciled';
}

function errorFor(field: string): string | null {
    return props.errors[`postings.${props.index}.${field}`]?.[0] ?? null;
}
</script>

<template>
    <div v-if="draft.locked" class="px-3 py-2.5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:items-center" :class="removable ? 'lg:grid-cols-[minmax(16rem,1fr)_9rem_8rem_3rem]' : 'lg:grid-cols-[minmax(16rem,1fr)_9rem_8rem_1.5rem]'">
            <span class="truncate text-sm">{{ lockedAccountPath }}</span>
            <span class="text-right font-mono text-sm">
                {{ lockedCommodity ? formatAmount(draft.amount, lockedCommodity) : draft.amount }}
            </span>
            <span class="text-sm text-muted">{{ lockedCommodity?.code ?? '' }}</span>
            <span class="flex items-center">
                <PostingStatusMenu status="reconciled" align="left" @select="changeLockedStatus" />
            </span>
        </div>
        <label v-if="memoOpen" class="mt-3 flex flex-col gap-1.5">
            <span class="field-label">Posting memo</span>
            <input v-model="draft.memo" type="text" class="input" />
            <p v-if="errorFor('memo')" class="text-sm text-danger">{{ errorFor('memo') }}</p>
        </label>
        <div v-else class="mt-1.5 pl-1">
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

    <div v-else class="px-3 py-2.5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:items-start" :class="removable ? 'lg:grid-cols-[minmax(16rem,1fr)_9rem_8rem_3rem]' : 'lg:grid-cols-[minmax(16rem,1fr)_9rem_8rem_1.5rem]'">
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
                <span v-if="carriesStatus" class="field-label sr-only">Status</span>
                <span class="flex h-[2.375rem] items-center gap-1">
                    <PostingStatusMenu
                        v-if="carriesStatus && draft.status !== null"
                        :status="draft.status"
                        align="left"
                        @select="draft.status = $event"
                    />
                    <button
                        v-if="removable"
                        type="button"
                        class="px-1 font-mono text-lg leading-none text-muted transition-colors hover:text-danger"
                        tabindex="-1"
                        title="Remove posting"
                        aria-label="Remove posting"
                        @click="emit('remove')"
                    >
                        ×
                    </button>
                </span>
                <p v-if="errorFor('status')" class="text-sm text-danger">
                    {{ errorFor('status') }}
                </p>
            </div>
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

        <div
            v-if="draft.bankTransaction"
            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-sm border border-accent/30 bg-accent/5 px-3 py-1.5 font-mono text-xs"
            :class="{ italic: draft.bankTransaction.pending }"
        >
            <span class="size-1.5 shrink-0 rounded-full bg-accent"></span>
            <span class="text-muted">{{ draft.bankTransaction.posted_on }}</span>
            <span v-if="draft.bankTransaction.pending" class="tracking-wider text-accent uppercase">pending</span>
            <span class="min-w-0 flex-1 truncate">
                {{ draft.bankTransaction.payee ?? draft.bankTransaction.description ?? draft.bankTransaction.external_id }}
                <span v-if="draft.bankTransaction.payee && draft.bankTransaction.description" class="text-muted">
                    · {{ draft.bankTransaction.description }}
                </span>
            </span>
            <span
                :class="bankAmountDiffers ? 'rounded-sm bg-danger/20 px-1 text-danger' : draft.bankTransaction.amount.startsWith('-') ? 'text-danger' : ''"
                :title="bankAmountDiffers ? 'The bank amount differs from this posting' : undefined"
            >
                {{ formatAmount(draft.bankTransaction.amount, baseCurrency) }}
            </span>
            <button
                v-if="draft.id !== null"
                type="button"
                tabindex="-1"
                class="tracking-wider text-muted uppercase not-italic transition-colors hover:text-danger"
                @click="emit('unmatch')"
            >
                Unmatch
            </button>
        </div>
    </div>
</template>
