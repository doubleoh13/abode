<script setup lang="ts">
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import ComboBox from './ComboBox.vue';
import { formatAmount } from '../money';
import type { Commodity, Lot } from '../types';

const props = defineProps<{
    modelValue: number | null;
    accountId: number | null;
    commodity: Commodity;
    baseCurrency: Commodity;
    date: string;
    attachedLot: Lot | null;
}>();

const emit = defineEmits<{ 'update:modelValue': [number | null]; lotsLoaded: [Lot[]] }>();

const lots = ref<Lot[]>([]);

async function loadLots(): Promise<void> {
    if (props.accountId === null) {
        lots.value = [];
        return;
    }

    const params: Record<string, string | number> = {
        financial_account_id: props.accountId,
        financial_commodity_id: props.commodity.id,
    };

    if (props.date) {
        params.as_of = props.date;
    }

    lots.value = (await axios.get<{ data: Lot[] }>('/api/v1/financial/lots', { params })).data.data;
    emit('lotsLoaded', lots.value);
}

watch(() => [props.accountId, props.commodity.id, props.date], loadLots, { immediate: true });

const options = computed(() => {
    const rows = [...lots.value];

    if (props.attachedLot && !rows.some((lot) => lot.id === props.attachedLot?.id)) {
        rows.push(props.attachedLot);
    }

    return rows.map((lot) => ({
        value: lot.id,
        label: `${lot.open_quantity !== undefined ? `${formatAmount(lot.open_quantity, props.commodity)} open · ` : ''}acq ${lot.acquired_at} · ${formatAmount(lot.cost, props.baseCurrency)} basis`,
    }));
});
</script>

<template>
    <ComboBox
        :model-value="modelValue"
        :options="options"
        nullable
        @update:model-value="emit('update:modelValue', $event)"
    />
</template>
