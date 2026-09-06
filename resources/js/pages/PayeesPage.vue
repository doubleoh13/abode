<script setup lang="ts">
import axios from 'axios';
import { onMounted, ref } from 'vue';
import ModalDialog from '../components/ModalDialog.vue';
import PayeeForm from '../components/PayeeForm.vue';
import type { Payee } from '../types';

const payees = ref<Payee[]>([]);
const loaded = ref(false);
const formOpen = ref(false);
const editingPayee = ref<Payee | null>(null);

async function loadPayees(): Promise<void> {
    payees.value = (await axios.get<{ data: Payee[] }>('/api/v1/financial/payees')).data.data;
    loaded.value = true;
}

onMounted(loadPayees);

function openCreateForm(): void {
    editingPayee.value = null;
    formOpen.value = true;
}

function openEditForm(payee: Payee): void {
    editingPayee.value = payee;
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    editingPayee.value = null;
}

async function payeeSaved(): Promise<void> {
    closeForm();
    await loadPayees();
}

async function deletePayee(payee: Payee): Promise<void> {
    if (!confirm(`Delete ${payee.name}?`)) {
        return;
    }

    await axios.delete(`/api/v1/financial/payees/${payee.id}`);
    await loadPayees();
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Payees</h1>

            <button type="button" class="button-primary" @click="openCreateForm">New payee</button>
        </div>

        <template v-if="loaded">
            <ModalDialog :open="formOpen" @close="closeForm">
                <PayeeForm
                    :key="editingPayee?.id ?? 'new'"
                    :payee="editingPayee"
                    @saved="payeeSaved"
                    @cancelled="closeForm"
                />
            </ModalDialog>

            <p v-if="payees.length === 0" class="mt-6 text-sm text-muted">No payees yet.</p>

            <ul
                v-else
                class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
            >
                <li
                    v-for="payee in payees"
                    :key="payee.id"
                    class="group flex items-center justify-between gap-4 px-4 py-2"
                >
                    <span class="text-sm">{{ payee.name }}</span>

                    <span class="flex items-center gap-3 font-mono text-xs text-muted">
                        <button
                            type="button"
                            class="tracking-wider uppercase opacity-0 transition-opacity group-hover:opacity-100 hover:text-foreground"
                            @click="openEditForm(payee)"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="tracking-wider uppercase opacity-0 transition-opacity group-hover:opacity-100 hover:text-danger"
                            @click="deletePayee(payee)"
                        >
                            Delete
                        </button>
                    </span>
                </li>
            </ul>
        </template>
    </div>
</template>
