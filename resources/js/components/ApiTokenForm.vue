<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { onMounted, ref } from 'vue';
import type { ApiToken } from '../types';

const emit = defineEmits<{ created: []; done: [] }>();

const name = ref('');
const plainTextToken = ref('');
const copied = ref(false);
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const nameInput = ref<HTMLInputElement | null>(null);

onMounted(() => nameInput.value?.focus());

async function create(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    try {
        const { data } = await axios.post<{ data: ApiToken; plain_text_token: string }>(
            '/api/v1/tokens',
            { name: name.value },
        );

        plainTextToken.value = data.plain_text_token;
        emit('created');
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

async function copyToken(): Promise<void> {
    await navigator.clipboard.writeText(plainTextToken.value);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <form
        v-if="plainTextToken === ''"
        class="mx-auto max-w-md rounded-md border border-edge bg-surface p-6"
        @submit.prevent="create"
    >
        <h2 class="font-mono text-xs tracking-wider text-muted uppercase">New token</h2>

        <label class="mt-4 flex flex-col gap-1.5">
            <span class="field-label">Name</span>
            <input ref="nameInput" v-model="name" type="text" required class="input" />
            <p v-if="errors.name" class="text-sm text-danger">{{ errors.name[0] }}</p>
        </label>

        <div class="mt-6 flex gap-3">
            <button type="submit" :disabled="submitting" class="button-primary">Create</button>
            <button type="button" class="button-subtle" @click="emit('done')">Cancel</button>
        </div>
    </form>

    <div v-else class="rounded-md border border-edge bg-surface p-6">
        <h2 class="font-mono text-xs tracking-wider text-muted uppercase">{{ name }}</h2>

        <p class="mt-4 text-sm text-muted">
            Copy this token now. It is not shown again.
        </p>

        <p class="mt-3 rounded-sm border border-edge bg-background p-3 font-mono text-sm break-all select-all">
            {{ plainTextToken }}
        </p>

        <div class="mt-6 flex gap-3">
            <button type="button" class="button-subtle" @click="copyToken">
                {{ copied ? 'Copied' : 'Copy' }}
            </button>
            <button type="button" class="button-primary" @click="emit('done')">Done</button>
        </div>
    </div>
</template>
