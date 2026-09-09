<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, reactive, ref } from 'vue';
import ComboBox from './ComboBox.vue';
import type { Commodity, CommodityKind, PriceSource, SymbolPlacement } from '../types';

const props = defineProps<{ commodity: Commodity | null }>();

const emit = defineEmits<{ saved: []; cancelled: [] }>();

const kinds: Array<{ value: CommodityKind; label: string }> = [
    { value: 'currency', label: 'Currency' },
    { value: 'traded', label: 'Traded' },
    { value: 'custom', label: 'Custom' },
];

const placements: Array<{ value: SymbolPlacement; label: string }> = [
    { value: 'prefix', label: 'Prefix' },
    { value: 'suffix', label: 'Suffix' },
];

const form = reactive({
    code: props.commodity?.code ?? '',
    name: props.commodity?.name ?? '',
    kind: (props.commodity?.kind ?? 'traded') as CommodityKind,
    display_precision: props.commodity?.display_precision ?? 4,
    symbol: props.commodity?.symbol ?? '',
    symbol_placement: props.commodity?.symbol_placement ?? null,
    price_source: props.commodity?.price_source ?? null,
    price_symbol: props.commodity?.price_symbol ?? '',
});

const priceSources: Array<{ value: PriceSource; label: string }> = [
    { value: 'yahoo', label: 'Yahoo' },
    { value: 'in529', label: 'Indiana 529' },
    { value: 'manual', label: 'Manual' },
];

const fetchableSource = computed(() => form.price_source === 'yahoo' || form.price_source === 'in529');

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const codeInput = ref<HTMLInputElement | null>(null);

onMounted(() => codeInput.value?.focus());

async function save(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const payload = {
        code: form.code,
        name: form.name,
        kind: form.kind,
        display_precision: form.display_precision,
        symbol: form.symbol || null,
        symbol_placement: form.symbol_placement,
        price_source: form.price_source,
        price_symbol: fetchableSource.value ? form.price_symbol || null : null,
    };

    try {
        if (props.commodity) {
            await axios.put(`/api/v1/financial/commodities/${props.commodity.id}`, payload);
        } else {
            await axios.post('/api/v1/financial/commodities', payload);
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
            {{ commodity ? 'Edit commodity' : 'New commodity' }}
        </h2>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="flex flex-col gap-1.5">
                <span class="field-label">Code</span>
                <input
                    ref="codeInput"
                    v-model="form.code"
                    type="text"
                    required
                    class="input font-mono"
                />
                <p v-if="errors.code" class="text-sm text-danger">{{ errors.code[0] }}</p>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Name</span>
                <input v-model="form.name" type="text" required class="input" />
                <p v-if="errors.name" class="text-sm text-danger">{{ errors.name[0] }}</p>
            </label>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Kind</span>
                <ComboBox v-model="form.kind" :options="kinds" />
                <p v-if="errors.kind" class="text-sm text-danger">{{ errors.kind[0] }}</p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Display decimals</span>
                <input v-model.number="form.display_precision" type="number" min="0" max="25" required class="input" />
                <p v-if="errors.display_precision" class="text-sm text-danger">{{ errors.display_precision[0] }}</p>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Symbol</span>
                <input v-model="form.symbol" type="text" class="input" />
                <p v-if="errors.symbol" class="text-sm text-danger">{{ errors.symbol[0] }}</p>
            </label>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Symbol placement</span>
                <ComboBox v-model="form.symbol_placement" :options="placements" nullable />
                <p v-if="errors.symbol_placement" class="text-sm text-danger">
                    {{ errors.symbol_placement[0] }}
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Price source</span>
                <ComboBox v-model="form.price_source" :options="priceSources" nullable />
                <p v-if="errors.price_source" class="text-sm text-danger">
                    {{ errors.price_source[0] }}
                </p>
            </div>

            <label v-if="fetchableSource" class="flex flex-col gap-1.5">
                <span class="field-label">Quote symbol</span>
                <input v-model="form.price_symbol" type="text" class="input font-mono" />
                <p v-if="errors.price_symbol" class="text-sm text-danger">
                    {{ errors.price_symbol[0] }}
                </p>
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Save</button>
            <button type="button" class="button-subtle" @click="emit('cancelled')">Cancel</button>
        </div>
    </form>
</template>
