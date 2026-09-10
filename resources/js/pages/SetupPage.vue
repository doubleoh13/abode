<script setup lang="ts">
import { isAxiosError } from 'axios';
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { completeSetup, type SetupDetails } from '../auth';

const router = useRouter();

const form = reactive<SetupDetails>({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const errors = ref<Record<string, string[]>>({});
const errorMessage = ref('');
const submitting = ref(false);

async function attemptSetup(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    errorMessage.value = '';

    try {
        await completeSetup(form);
        router.push('/');
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            errors.value = error.response.data.errors;
        } else {
            errorMessage.value =
                (isAxiosError(error) && error.response?.data?.message) ||
                'Something went wrong. Try again.';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-6">
        <form
            class="w-full max-w-sm rounded-md border border-edge bg-surface p-8"
            @submit.prevent="attemptSetup"
        >
            <h1 class="font-mono text-xl font-semibold">
                abode<span class="text-accent">_</span>
            </h1>
            <p class="mt-1 font-mono text-xs tracking-wider text-muted uppercase">
                Create the first user
            </p>

            <div class="mt-8 flex flex-col gap-5">
                <label class="flex flex-col gap-1.5">
                    <span class="font-mono text-xs tracking-wider text-muted uppercase">Name</span>
                    <input
                        v-model="form.name"
                        type="text"
                        name="name"
                        required
                        autocomplete="name"
                        autofocus
                        class="rounded-sm border border-edge bg-background px-3 py-2 text-sm transition-colors focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                    />
                    <p v-if="errors.name" class="text-sm text-danger">{{ errors.name[0] }}</p>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="font-mono text-xs tracking-wider text-muted uppercase">Email</span>
                    <input
                        v-model="form.email"
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        class="rounded-sm border border-edge bg-background px-3 py-2 text-sm transition-colors focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                    />
                    <p v-if="errors.email" class="text-sm text-danger">{{ errors.email[0] }}</p>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="font-mono text-xs tracking-wider text-muted uppercase">
                        Password
                    </span>
                    <input
                        v-model="form.password"
                        type="password"
                        name="password"
                        required
                        autocomplete="new-password"
                        class="rounded-sm border border-edge bg-background px-3 py-2 text-sm transition-colors focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                    />
                    <p v-if="errors.password" class="text-sm text-danger">
                        {{ errors.password[0] }}
                    </p>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="font-mono text-xs tracking-wider text-muted uppercase">
                        Confirm password
                    </span>
                    <input
                        v-model="form.password_confirmation"
                        type="password"
                        name="password_confirmation"
                        required
                        autocomplete="new-password"
                        class="rounded-sm border border-edge bg-background px-3 py-2 text-sm transition-colors focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                    />
                </label>

                <p v-if="errorMessage" class="text-sm text-danger">
                    {{ errorMessage }}
                </p>

                <button
                    type="submit"
                    :disabled="submitting"
                    class="rounded-sm bg-accent px-4 py-2 text-sm font-semibold text-background transition-colors hover:bg-accent/85 disabled:opacity-50"
                >
                    Create account
                </button>
            </div>
        </form>
    </div>
</template>
