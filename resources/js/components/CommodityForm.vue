<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { onMounted, reactive, ref } from 'vue';
import ComboBox from './ComboBox.vue';
import type { Commodity, CommodityKind, SymbolPlacement } from '../types';

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
    precision: props.commodity?.precision ?? 4,
    symbol: props.commodity?.symbol ?? '',
    symbol_placement: props.commodity?.symbol_placement ?? null,
});

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
        precision: form.precision,
        symbol: form.symbol || null,
        symbol_placement: form.symbol_placement,
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
                <span class="field-label">Precision</span>
                <input v-model.number="form.precision" type="number" min="0" max="255" required class="input" />
                <p v-if="errors.precision" class="text-sm text-danger">{{ errors.precision[0] }}</p>
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
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Save</button>
            <button type="button" class="button-subtle" @click="emit('cancelled')">Cancel</button>
        </div>
    </form>
</template>
