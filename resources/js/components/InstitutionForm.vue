<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { onMounted, reactive, ref } from 'vue';
import type { Institution } from '../types';

const props = defineProps<{ institution: Institution | null }>();

const emit = defineEmits<{ saved: []; cancelled: [] }>();

const form = reactive({
    name: props.institution?.name ?? '',
});

const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const nameInput = ref<HTMLInputElement | null>(null);

onMounted(() => nameInput.value?.focus());

async function save(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    try {
        if (props.institution) {
            await axios.put(`/api/v1/financial/institutions/${props.institution.id}`, { name: form.name });
        } else {
            await axios.post('/api/v1/financial/institutions', { name: form.name });
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
    <form class="mx-auto max-w-md rounded-md border border-edge bg-surface p-6" @submit.prevent="save">
        <h2 class="font-mono text-xs tracking-wider text-muted uppercase">
            {{ institution ? 'Edit institution' : 'New institution' }}
        </h2>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="flex flex-col gap-1.5">
                <span class="field-label">Name</span>
                <input ref="nameInput" v-model="form.name" type="text" required class="input" />
                <p v-if="errors.name" class="text-sm text-danger">{{ errors.name[0] }}</p>
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Save</button>
            <button type="button" class="button-subtle" @click="emit('cancelled')">Cancel</button>
        </div>
    </form>
</template>
