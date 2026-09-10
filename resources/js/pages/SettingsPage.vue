<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { onMounted, reactive, ref } from 'vue';
import ApiTokenForm from '../components/ApiTokenForm.vue';
import ModalDialog from '../components/ModalDialog.vue';
import { auth, type AuthenticatedUser } from '../auth';
import type { ApiToken } from '../types';

const profile = reactive({
    name: auth.user?.name ?? '',
    email: auth.user?.email ?? '',
});
const profileErrors = ref<Record<string, string[]>>({});
const profileSubmitting = ref(false);
const profileSaved = ref(false);

const password = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const passwordErrors = ref<Record<string, string[]>>({});
const passwordSubmitting = ref(false);
const passwordSaved = ref(false);

const tokens = ref<ApiToken[]>([]);
const tokenFormOpen = ref(false);
const tokenFormKey = ref(0);

onMounted(loadTokens);

async function loadTokens(): Promise<void> {
    tokens.value = (await axios.get<{ data: ApiToken[] }>('/api/v1/tokens')).data.data;
}

function flash(indicator: { value: boolean }): void {
    indicator.value = true;
    setTimeout(() => (indicator.value = false), 2000);
}

async function saveProfile(): Promise<void> {
    profileSubmitting.value = true;
    profileErrors.value = {};

    try {
        const { data } = await axios.patch<{ data: AuthenticatedUser }>('/api/v1/user', profile);
        auth.user = data.data;
        flash(profileSaved);
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            profileErrors.value = error.response.data.errors;
        } else {
            throw error;
        }
    } finally {
        profileSubmitting.value = false;
    }
}

async function changePassword(): Promise<void> {
    passwordSubmitting.value = true;
    passwordErrors.value = {};

    try {
        await axios.put('/api/v1/user/password', password);
        password.current_password = '';
        password.password = '';
        password.password_confirmation = '';
        flash(passwordSaved);
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            passwordErrors.value = error.response.data.errors;
        } else {
            throw error;
        }
    } finally {
        passwordSubmitting.value = false;
    }
}

function openTokenForm(): void {
    tokenFormKey.value++;
    tokenFormOpen.value = true;
}

async function revokeToken(token: ApiToken): Promise<void> {
    if (!confirm(`Revoke ${token.name}?`)) {
        return;
    }

    await axios.delete(`/api/v1/tokens/${token.id}`);
    await loadTokens();
}

function formatDate(value: string | null): string {
    return value === null ? 'Never' : new Date(value).toLocaleDateString();
}
</script>

<template>
    <div class="max-w-2xl">
        <h1 class="text-xl font-semibold">Settings</h1>

        <form class="mt-6 rounded-md border border-edge bg-surface p-6" @submit.prevent="saveProfile">
            <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Profile</h2>

            <div class="mt-4 flex flex-col gap-4">
                <label class="flex flex-col gap-1.5">
                    <span class="field-label">Name</span>
                    <input v-model="profile.name" type="text" required class="input" />
                    <p v-if="profileErrors.name" class="text-sm text-danger">
                        {{ profileErrors.name[0] }}
                    </p>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="field-label">Email</span>
                    <input
                        v-model="profile.email"
                        type="email"
                        required
                        autocomplete="email"
                        class="input"
                    />
                    <p v-if="profileErrors.email" class="text-sm text-danger">
                        {{ profileErrors.email[0] }}
                    </p>
                </label>
            </div>

            <div class="mt-6 flex items-center gap-4">
                <button type="submit" :disabled="profileSubmitting" class="button-primary">
                    Save
                </button>
                <span v-if="profileSaved" class="field-label">Saved</span>
            </div>
        </form>

        <form
            class="mt-6 rounded-md border border-edge bg-surface p-6"
            @submit.prevent="changePassword"
        >
            <h2 class="font-mono text-xs tracking-wider text-muted uppercase">Password</h2>

            <div class="mt-4 flex flex-col gap-4">
                <label class="flex flex-col gap-1.5">
                    <span class="field-label">Current password</span>
                    <input
                        v-model="password.current_password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="input"
                    />
                    <p v-if="passwordErrors.current_password" class="text-sm text-danger">
                        {{ passwordErrors.current_password[0] }}
                    </p>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="field-label">New password</span>
                    <input
                        v-model="password.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="input"
                    />
                    <p v-if="passwordErrors.password" class="text-sm text-danger">
                        {{ passwordErrors.password[0] }}
                    </p>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="field-label">Confirm new password</span>
                    <input
                        v-model="password.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        class="input"
                    />
                </label>
            </div>

            <div class="mt-6 flex items-center gap-4">
                <button type="submit" :disabled="passwordSubmitting" class="button-primary">
                    Change password
                </button>
                <span v-if="passwordSaved" class="field-label">Password changed</span>
            </div>
        </form>

        <section class="mt-6 rounded-md border border-edge bg-surface p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-mono text-xs tracking-wider text-muted uppercase">API tokens</h2>

                <button type="button" class="button-subtle" @click="openTokenForm">New token</button>
            </div>

            <ModalDialog :open="tokenFormOpen" @close="tokenFormOpen = false">
                <ApiTokenForm
                    :key="tokenFormKey"
                    @created="loadTokens"
                    @done="tokenFormOpen = false"
                />
            </ModalDialog>

            <p v-if="tokens.length === 0" class="mt-4 text-sm text-muted">No API tokens yet.</p>

            <ul v-else class="mt-4 divide-y divide-edge border-t border-edge">
                <li
                    v-for="token in tokens"
                    :key="token.id"
                    class="group flex items-center justify-between gap-4 py-3"
                >
                    <span>
                        <span class="text-sm">{{ token.name }}</span>
                        <span class="mt-0.5 block font-mono text-xs text-muted">
                            Created {{ formatDate(token.created_at) }} · Last used
                            {{ formatDate(token.last_used_at) }}
                        </span>
                    </span>

                    <button
                        type="button"
                        class="font-mono text-xs tracking-wider text-muted uppercase opacity-0 transition-opacity group-hover:opacity-100 hover:text-danger"
                        @click="revokeToken(token)"
                    >
                        Revoke
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
