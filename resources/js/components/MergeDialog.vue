<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, ref } from 'vue';
import PostingStatusGlyph from './PostingStatusGlyph.vue';
import { accountPathAncestor, accountPathLeaf, statusLabel } from '../journal';
import { decimalToScaledInteger, formatAmount, scaledIntegerToDecimal } from '../money';
import type { Commodity, Posting, Transaction } from '../types';

const props = defineProps<{
    first: Transaction;
    second: Transaction;
    commodities: Commodity[];
}>();

const emit = defineEmits<{ merged: []; cancelled: [] }>();

type Side = 'first' | 'second';
type HeaderField = 'date' | 'financial_payee_id' | 'memo';

const sides: Side[] = ['first', 'second'];

const baseCurrency = computed(() => {
    const usd = props.commodities.find((commodity) => commodity.code === 'USD');

    if (!usd) {
        throw new Error('The base currency is missing from the commodity list.');
    }

    return usd;
});

function preferNonEmpty(field: HeaderField): Side {
    const firstValue = props.first[field];

    return firstValue === null || firstValue === '' ? 'second' : 'first';
}

const picks = ref<Record<HeaderField, Side>>({
    date: 'first',
    financial_payee_id: preferNonEmpty('financial_payee_id'),
    memo: preferNonEmpty('memo'),
});

function samePosting(candidate: Posting, existing: Posting): boolean {
    return (
        candidate.financial_account_id === existing.financial_account_id &&
        candidate.financial_commodity_id === existing.financial_commodity_id &&
        decimalToScaledInteger(candidate.amount) === decimalToScaledInteger(existing.amount)
    );
}

const firstPostings = props.first.postings ?? [];
const secondPostings = props.second.postings ?? [];

const selectedIds = ref<Set<number>>(
    new Set([
        ...firstPostings.map((posting) => posting.id),
        ...secondPostings
            .filter(
                (posting) =>
                    posting.status === 'reconciled' ||
                    !firstPostings.some((existing) => samePosting(posting, existing)),
            )
            .map((posting) => posting.id),
    ]),
);

function toggle(posting: Posting): void {
    if (posting.status === 'reconciled') {
        return;
    }

    const next = new Set(selectedIds.value);

    if (next.has(posting.id)) {
        next.delete(posting.id);
    } else {
        next.add(posting.id);
    }

    selectedIds.value = next;
}

const selectedPostings = computed(() =>
    [...firstPostings, ...secondPostings].filter((posting) => selectedIds.value.has(posting.id)),
);

const balance = computed<{ residual: string | null; faceSums: string[] }>(() => {
    const sums = new Map<number, bigint>();
    let residual = 0n;
    let exact = true;

    for (const posting of selectedPostings.value) {
        sums.set(
            posting.financial_commodity_id,
            (sums.get(posting.financial_commodity_id) ?? 0n) + decimalToScaledInteger(posting.amount),
        );

        if (posting.financial_commodity_id === baseCurrency.value.id) {
            residual += decimalToScaledInteger(posting.amount);
        } else {
            exact = false;
        }
    }

    const faceSums = [...sums.entries()].map(([commodityId, sum]) => {
        const commodity = props.commodities.find((candidate) => candidate.id === commodityId);

        return commodity ? formatAmount(scaledIntegerToDecimal(sum), commodity) : scaledIntegerToDecimal(sum);
    });

    return { residual: exact ? scaledIntegerToDecimal(residual) : null, faceSums };
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

function headerValue(side: Side, field: HeaderField): string {
    const transaction = props[side];

    if (field === 'financial_payee_id') {
        return transaction.payee?.name ?? '—';
    }

    return transaction[field] ?? '—';
}

function postingPayload(posting: Posting): Record<string, unknown> {
    return {
        status: posting.status,
        financial_account_id: posting.financial_account_id,
        financial_commodity_id: posting.financial_commodity_id,
        amount: posting.amount,
        memo: posting.memo,
        ...(posting.financial_commodity_id === baseCurrency.value.id ? {} : { financial_lot_id: posting.financial_lot_id }),
    };
}

async function merge(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    try {
        await axios.post('/api/v1/financial/transactions/merge', {
            financial_transaction_ids: [props.first.id, props.second.id],
            date: props[picks.value.date].date,
            financial_payee_id: props[picks.value.financial_payee_id].financial_payee_id,
            memo: props[picks.value.memo].memo,
            postings: selectedPostings.value.map(postingPayload),
        });

        emit('merged');
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

const errorMessages = computed(() => Object.values(errors.value).map((messages) => messages[0]));

const headerFields: Array<{ field: HeaderField; label: string }> = [
    { field: 'date', label: 'Date' },
    { field: 'financial_payee_id', label: 'Payee' },
    { field: 'memo', label: 'Memo' },
];
</script>

<template>
    <form class="rounded-md border border-edge bg-surface p-5" @submit.prevent="merge">
        <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Merge transactions</h2>

        <div class="mt-4 overflow-hidden rounded-sm border border-edge bg-background/40">
            <div class="grid grid-cols-[6rem_1fr_1fr] border-b border-edge">
                <span class="px-3 py-2"></span>
                <span v-for="side in sides" :key="side" class="field-label px-3 py-2">
                    {{ props[side].date }} · {{ props[side].payee?.name ?? props[side].memo ?? '—' }}
                </span>
            </div>

            <div v-for="{ field, label } in headerFields" :key="field" class="grid grid-cols-[6rem_1fr_1fr] border-b border-edge">
                <span class="field-label px-3 py-2">{{ label }}</span>
                <label
                    v-for="side in sides"
                    :key="side"
                    class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm transition-colors hover:bg-edge/40"
                    :class="picks[field] === side ? 'text-foreground' : 'text-muted'"
                >
                    <input v-model="picks[field]" type="radio" :name="field" :value="side" class="accent-accent" />
                    <span class="truncate">{{ headerValue(side, field) }}</span>
                </label>
            </div>

            <div class="grid grid-cols-2 divide-x divide-edge">
                <div v-for="side in sides" :key="side" class="flex flex-col">
                    <label
                        v-for="posting in props[side].postings"
                        :key="posting.id"
                        class="flex items-center gap-3 px-3 py-1.5 text-sm transition-colors"
                        :class="[
                            posting.status === 'reconciled' ? 'cursor-not-allowed' : 'cursor-pointer hover:bg-edge/40',
                            selectedIds.has(posting.id) ? 'text-foreground' : 'text-muted line-through',
                        ]"
                        :title="posting.status === 'reconciled' ? 'Reconciled postings are always kept' : undefined"
                    >
                        <input
                            type="checkbox"
                            class="accent-accent"
                            :checked="selectedIds.has(posting.id)"
                            :disabled="posting.status === 'reconciled'"
                            @change="toggle(posting)"
                        />
                        <span class="min-w-0 flex-1 truncate">
                            <span class="text-muted">{{ accountPathAncestor(posting.account?.path) }}</span>{{ accountPathLeaf(posting.account?.path) }}
                            <span v-if="posting.memo" class="text-muted"> · {{ posting.memo }}</span>
                        </span>
                        <span class="font-mono">
                            {{ posting.commodity ? formatAmount(posting.amount, posting.commodity) : posting.amount }}
                        </span>
                        <span class="w-4 text-right font-mono text-muted" :title="statusLabel(posting.status)">
                            <PostingStatusGlyph :status="posting.status" />
                        </span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end gap-5 border-t border-edge px-3 py-2 font-mono text-xs">
                <span class="text-muted">{{ balance.faceSums.join(' · ') || '—' }}</span>
                <span
                    v-if="balance.residual !== null"
                    :class="balance.residual === '0' ? 'tracking-wider text-accent uppercase' : 'text-danger'"
                >
                    {{ balance.residual === '0' ? 'Balanced' : `Off by ${balance.residual} ${baseCurrency.code}` }}
                </span>
                <span v-else class="tracking-wider text-muted uppercase">Balance checked on save</span>
            </div>
        </div>

        <ul v-if="errorMessages.length > 0" class="mt-3 flex flex-col gap-1 text-sm text-danger">
            <li v-for="message in errorMessages" :key="message">{{ message }}</li>
        </ul>

        <div class="mt-5 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Merge</button>
            <button type="button" class="button-subtle" @click="emit('cancelled')">Cancel</button>
        </div>
    </form>
</template>
