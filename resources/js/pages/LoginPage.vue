<script setup lang="ts">
import { isAxiosError } from 'axios';
import { reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { login, type LoginCredentials } from '../auth';

const router = useRouter();

const form = reactive<LoginCredentials>({
    email: '',
    password: '',
    remember: false,
});

const errorMessage = ref('');
const submitting = ref(false);

async function attemptLogin(): Promise<void> {
    submitting.value = true;
    errorMessage.value = '';

    try {
        await login(form);
        router.push({ name: 'home' });
    } catch (error) {
        errorMessage.value =
            (isAxiosError(error) && error.response?.data?.message) ||
            'Something went wrong. Try again.';
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center px-6">
        <form
            class="w-full max-w-sm rounded-md border border-edge bg-surface p-8"
            @submit.prevent="attemptLogin"
        >
            <h1 class="font-mono text-xl font-semibold">
                abode<span class="text-accent">_</span>
            </h1>

            <div class="mt-8 flex flex-col gap-5">
                <label class="flex flex-col gap-1.5">
                    <span class="font-mono text-xs tracking-wider text-muted uppercase">Email</span>
                    <input
                        v-model="form.email"
                        type="email"
                        name="email"
                        required
                        autocomplete="email"
                        autofocus
                        class="rounded-sm border border-edge bg-background px-3 py-2 text-sm transition-colors focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                    />
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
                        autocomplete="current-password"
                        class="rounded-sm border border-edge bg-background px-3 py-2 text-sm transition-colors focus:border-accent focus:ring-1 focus:ring-accent focus:outline-none"
                    />
                </label>

                <label class="flex items-center gap-2">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        name="remember"
                        class="size-4 accent-accent"
                    />
                    <span class="text-sm text-muted">Remember me</span>
                </label>

                <p v-if="errorMessage" class="text-sm text-danger">
                    {{ errorMessage }}
                </p>

                <button
                    type="submit"
                    :disabled="submitting"
                    class="rounded-sm bg-accent px-4 py-2 text-sm font-semibold text-background transition-colors hover:bg-accent/85 disabled:opacity-50"
                >
                    Log in
                </button>
            </div>
        </form>
    </div>
</template>
