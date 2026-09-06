<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ComboBox from './ComboBox.vue';
import type { Account, AccountType, Institution } from '../types';

const props = defineProps<{
    account: Account | null;
    accounts: Account[];
    institutions: Institution[];
}>();

const emit = defineEmits<{ saved: []; cancelled: [] }>();

const accountTypes: Array<{ value: AccountType; label: string }> = [
    { value: 'asset', label: 'Asset' },
    { value: 'liability', label: 'Liability' },
    { value: 'income', label: 'Income' },
    { value: 'expense', label: 'Expense' },
    { value: 'equity', label: 'Equity' },
];

const form = reactive({
    name: props.account?.name ?? '',
    account_type: (props.account?.account_type ?? 'expense') as AccountType,
    parent_id: props.account?.parent_id ?? null,
    financial_institution_id: props.account?.institution?.id ?? null,
    opened_at: props.account?.opened_at ?? '',
    closed_at: props.account?.closed_at ?? '',
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const typeComboBox = ref<{ focus: () => void } | null>(null);

onMounted(() => typeComboBox.value?.focus());

const parentOptions = computed(() =>
    props.accounts
        .filter(
            (candidate) =>
                candidate.account_type === form.account_type && candidate.id !== props.account?.id,
        )
        .toSorted((a, b) => a.path.localeCompare(b.path))
        .map((candidate) => ({ value: candidate.id, label: candidate.path })),
);

const institutionOptions = computed(() =>
    props.institutions.map((institution) => ({ value: institution.id, label: institution.name })),
);

watch(
    () => form.account_type,
    () => {
        if (!parentOptions.value.some((candidate) => candidate.value === form.parent_id)) {
            form.parent_id = null;
        }
    },
);

async function save(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    const payload = {
        name: form.name,
        account_type: form.account_type,
        parent_id: form.parent_id,
        financial_institution_id: form.financial_institution_id,
        opened_at: form.opened_at || null,
        closed_at: form.closed_at || null,
    };

    try {
        if (props.account) {
            await axios.put(`/api/v1/financial/accounts/${props.account.id}`, payload);
        } else {
            await axios.post('/api/v1/financial/accounts', payload);
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
            {{ account ? 'Edit account' : 'New account' }}
        </h2>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="flex flex-col gap-1.5">
                <span class="field-label">Type</span>
                <ComboBox ref="typeComboBox" v-model="form.account_type" :options="accountTypes" />
                <p v-if="errors.account_type" class="text-sm text-danger">
                    {{ errors.account_type[0] }}
                </p>
            </div>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Parent</span>
                <ComboBox v-model="form.parent_id" :options="parentOptions" nullable />
                <p v-if="errors.parent_id" class="text-sm text-danger">{{ errors.parent_id[0] }}</p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Name</span>
                <input v-model="form.name" type="text" required class="input" />
                <p v-if="errors.name" class="text-sm text-danger">{{ errors.name[0] }}</p>
            </label>

            <div class="flex flex-col gap-1.5">
                <span class="field-label">Institution</span>
                <ComboBox v-model="form.financial_institution_id" :options="institutionOptions" nullable />
                <p v-if="errors.financial_institution_id" class="text-sm text-danger">
                    {{ errors.financial_institution_id[0] }}
                </p>
            </div>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Opened</span>
                <input v-model="form.opened_at" type="date" class="input" />
                <p v-if="errors.opened_at" class="text-sm text-danger">{{ errors.opened_at[0] }}</p>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="field-label">Closed</span>
                <input v-model="form.closed_at" type="date" class="input" />
                <p v-if="errors.closed_at" class="text-sm text-danger">{{ errors.closed_at[0] }}</p>
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Save</button>
            <button type="button" class="button-subtle" @click="emit('cancelled')">Cancel</button>
        </div>
    </form>
</template>
