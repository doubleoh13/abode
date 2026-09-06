<script setup lang="ts">
import axios, { isAxiosError } from 'axios';
import { onMounted, ref } from 'vue';
import InstitutionForm from '../components/InstitutionForm.vue';
import ModalDialog from '../components/ModalDialog.vue';
import type { Institution } from '../types';

const institutions = ref<Institution[]>([]);
const loaded = ref(false);
const formOpen = ref(false);
const editingInstitution = ref<Institution | null>(null);

async function loadInstitutions(): Promise<void> {
    institutions.value = (await axios.get<{ data: Institution[] }>('/api/v1/financial/institutions')).data
        .data;
    loaded.value = true;
}

onMounted(loadInstitutions);

function openCreateForm(): void {
    editingInstitution.value = null;
    formOpen.value = true;
}

function openEditForm(institution: Institution): void {
    editingInstitution.value = institution;
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    editingInstitution.value = null;
}

async function institutionSaved(): Promise<void> {
    closeForm();
    await loadInstitutions();
}

async function deleteInstitution(institution: Institution): Promise<void> {
    if (!confirm(`Delete ${institution.name}?`)) {
        return;
    }

    try {
        await axios.delete(`/api/v1/financial/institutions/${institution.id}`);
        await loadInstitutions();
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 409) {
            alert(error.response.data.message);
            return;
        }

        throw error;
    }
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Institutions</h1>

            <button type="button" class="button-primary" @click="openCreateForm">
                New institution
            </button>
        </div>

        <template v-if="loaded">
            <ModalDialog :open="formOpen" @close="closeForm">
                <InstitutionForm
                    :key="editingInstitution?.id ?? 'new'"
                    :institution="editingInstitution"
                    @saved="institutionSaved"
                    @cancelled="closeForm"
                />
            </ModalDialog>

            <p v-if="institutions.length === 0" class="mt-6 text-sm text-muted">
                No institutions yet.
            </p>

            <ul
                v-else
                class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
            >
                <li
                    v-for="institution in institutions"
                    :key="institution.id"
                    class="group flex items-center justify-between gap-4 px-4 py-2"
                >
                    <span class="text-sm">{{ institution.name }}</span>

                    <span class="flex items-center gap-3 font-mono text-xs text-muted">
                        <button
                            type="button"
                            class="tracking-wider uppercase opacity-0 transition-opacity group-hover:opacity-100 hover:text-foreground"
                            @click="openEditForm(institution)"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="tracking-wider uppercase opacity-0 transition-opacity group-hover:opacity-100 hover:text-danger"
                            @click="deleteInstitution(institution)"
                        >
                            Delete
                        </button>
                    </span>
                </li>
            </ul>
        </template>
    </div>
</template>
