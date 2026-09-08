<script setup lang="ts">
import axios from 'axios';
import { formatAmount } from '../money';
import { onMounted, ref } from 'vue';
import CommodityForm from '../components/CommodityForm.vue';
import ModalDialog from '../components/ModalDialog.vue';
import SkeletonList from '../components/SkeletonList.vue';
import type { Commodity } from '../types';

const commodities = ref<Commodity[]>([]);
const loaded = ref(false);
const formOpen = ref(false);
const editingCommodity = ref<Commodity | null>(null);

async function loadCommodities(): Promise<void> {
    commodities.value = (await axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities')).data.data;
    loaded.value = true;
}

onMounted(loadCommodities);

function openCreateForm(): void {
    editingCommodity.value = null;
    formOpen.value = true;
}

function openEditForm(commodity: Commodity): void {
    editingCommodity.value = commodity;
    formOpen.value = true;
}

function closeForm(): void {
    formOpen.value = false;
    editingCommodity.value = null;
}

async function commoditySaved(): Promise<void> {
    closeForm();
    await loadCommodities();
}

async function deleteCommodity(commodity: Commodity): Promise<void> {
    if (!confirm(`Delete ${commodity.code}?`)) {
        return;
    }

    await axios.delete(`/api/v1/financial/commodities/${commodity.id}`);
    await loadCommodities();
}

function exampleAmount(commodity: Commodity): string {
    return formatAmount('1234.5678', commodity);
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Commodities</h1>

            <button type="button" class="button-primary" @click="openCreateForm">
                New commodity
            </button>
        </div>

        <template v-if="loaded">
            <ModalDialog :open="formOpen" @close="closeForm">
                <CommodityForm
                    :key="editingCommodity?.id ?? 'new'"
                    :commodity="editingCommodity"
                    @saved="commoditySaved"
                    @cancelled="closeForm"
                />
            </ModalDialog>

            <div
                v-if="commodities.length"
                class="mt-6 overflow-x-auto rounded-md border border-edge bg-surface"
            >
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-edge font-mono text-xs tracking-wider text-muted uppercase">
                            <th class="px-4 py-2 text-left font-medium">Code</th>
                            <th class="px-4 py-2 text-left font-medium">Name</th>
                            <th class="px-4 py-2 text-left font-medium">Kind</th>
                            <th class="px-4 py-2 text-right font-medium">Display</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-edge">
                        <tr v-for="commodity in commodities" :key="commodity.id" class="group">
                            <td class="px-4 py-2 font-mono">
                                <RouterLink
                                    :to="{ name: 'finances.commodity', params: { id: commodity.id } }"
                                    class="transition-colors hover:text-accent"
                                >
                                    {{ commodity.code }}
                                </RouterLink>
                            </td>
                            <td class="px-4 py-2">{{ commodity.name }}</td>
                            <td class="px-4 py-2 text-muted">{{ commodity.kind }}</td>
                            <td class="px-4 py-2 text-right font-mono text-muted">
                                {{ exampleAmount(commodity) }}
                            </td>
                            <td class="px-4 py-2">
                                <span
                                    class="flex justify-end gap-3 font-mono text-xs tracking-wider text-muted uppercase"
                                >
                                    <button
                                        type="button"
                                        class="opacity-0 transition-opacity group-hover:opacity-100 hover:text-foreground"
                                        @click="openEditForm(commodity)"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="opacity-0 transition-opacity group-hover:opacity-100 hover:text-danger"
                                        @click="deleteCommodity(commodity)"
                                    >
                                        Delete
                                    </button>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>

        <SkeletonList v-else />
    </div>
</template>
