<script setup lang="ts">
import { computed } from 'vue';
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
}>();

const emit = defineEmits<{ remove: []; lotsLoaded: [Lot[]] }>();

const statusOptions: Array<{ value: PostingStatus; label: string }> = [
    { value: 'pending', label: 'Pending' },
    { value: 'cleared', label: 'Cleared' },
    { value: 'reconciled', label: 'Reconciled' },
];

const accountOptions = computed(() =>
    props.accounts
        .toSorted((a, b) => a.path.localeCompare(b.path))
        .map((account) => ({ value: account.id, label: account.path })),
);

const commodityOptions = computed(() =>
    props.commodities.map((commodity) => ({ value: commodity.id, label: commodity.code })),
);

const commodity = computed(
    () =>
        props.commodities.find(
            (candidate) => candidate.id === props.draft.financial_commodity_id,
        ) ?? null,
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
    <div class="rounded-md border border-edge bg-background/40 p-3">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="flex flex-col gap-1.5 lg:col-span-2">
                <span class="field-label">Account</span>
                <ComboBox v-model="draft.financial_account_id" :options="accountOptions" nullable />
                <p v-if="errorFor('financial_account_id')" class="text-sm text-danger">
                    {{ errorFor('financial_account_id') }}
                </p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Amount</span>
                <input v-model="draft.amount" type="text" inputmode="decimal" class="input text-right font-mono" />
                <p v-if="errorFor('amount')" class="text-sm text-danger">{{ errorFor('amount') }}</p>
            </label>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Commodity</span>
                <ComboBox v-model="draft.financial_commodity_id" :options="commodityOptions" nullable />
                <p v-if="errorFor('financial_commodity_id')" class="text-sm text-danger">
                    {{ errorFor('financial_commodity_id') }}
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Status</span>
                <ComboBox v-model="draft.status" :options="statusOptions" />
                <p v-if="errorFor('status')" class="text-sm text-danger">{{ errorFor('status') }}</p>
            </div>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <label class="flex flex-col gap-1.5 lg:col-span-2">
                <span class="field-label">Memo</span>
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
                        <input v-model="draft.lotAcquiredAt" type="date" class="input" />
                        <p v-if="errorFor('lot.acquired_at')" class="text-sm text-danger">
                            {{ errorFor('lot.acquired_at') }}
                        </p>
                    </label>
                </template>
            </template>
        </div>

        <div v-if="removable" class="mt-2 flex justify-end">
            <button
                type="button"
                class="font-mono text-xs tracking-wider text-muted uppercase transition-colors hover:text-danger"
                @click="emit('remove')"
            >
                Remove
            </button>
        </div>
    </div>
</template>
