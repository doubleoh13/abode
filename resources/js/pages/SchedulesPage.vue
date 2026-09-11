<script setup lang="ts">
import axios from 'axios';
import { onMounted, ref } from 'vue';
import ModalDialog from '../components/ModalDialog.vue';
import SkeletonList from '../components/SkeletonList.vue';
import TransactionForm from '../components/TransactionForm.vue';
import PostingStatusGlyph from '../components/PostingStatusGlyph.vue';
import { accountPathAncestor, accountPathLeaf, recurrenceLabel, statusLabel } from '../journal';
import { formatAmount } from '../money';
import type { Account, Commodity, Institution, Payee, RecurringTransaction } from '../types';

const schedules = ref<RecurringTransaction[]>([]);
const accounts = ref<Account[]>([]);
const commodities = ref<Commodity[]>([]);
const institutions = ref<Institution[]>([]);
const payees = ref<Payee[]>([]);
const loaded = ref(false);
const formOpen = ref(false);
const editingSchedule = ref<RecurringTransaction | null>(null);

async function loadSchedules(): Promise<void> {
    schedules.value = (
        await axios.get<{ data: RecurringTransaction[] }>('/api/v1/financial/recurring-transactions')
    ).data.data;
}

onMounted(async () => {
    const [accountsResponse, commoditiesResponse, institutionsResponse, payeesResponse] = await Promise.all([
        axios.get<{ data: Account[] }>('/api/v1/financial/accounts'),
        axios.get<{ data: Commodity[] }>('/api/v1/financial/commodities'),
        axios.get<{ data: Institution[] }>('/api/v1/financial/institutions'),
        axios.get<{ data: Payee[] }>('/api/v1/financial/payees'),
        loadSchedules(),
    ]);

    accounts.value = accountsResponse.data.data;
    commodities.value = commoditiesResponse.data.data;
    institutions.value = institutionsResponse.data.data;
    payees.value = payeesResponse.data.data;
    loaded.value = true;
});

function openCreateForm(): void {
    editingSchedule.value = null;
    formOpen.value = true;
}

function openEditForm(schedule: RecurringTransaction): void {
    editingSchedule.value = schedule;
    formOpen.value = true;
}

function registerPayee(payee: Payee): void {
    if (!payees.value.some((candidate) => candidate.id === payee.id)) {
        payees.value.push(payee);
    }
}

function registerAccount(account: Account): void {
    if (!accounts.value.some((candidate) => candidate.id === account.id)) {
        accounts.value.push(account);
    }
}

function closeForm(): void {
    formOpen.value = false;
    editingSchedule.value = null;
}

async function scheduleSaved(): Promise<void> {
    closeForm();
    await loadSchedules();
}

async function deleteSchedule(schedule: RecurringTransaction): Promise<void> {
    if (!confirm(`Delete the ${schedule.payee?.name ?? schedule.memo ?? 'untitled'} schedule? Transactions it already posted stay in the journal.`)) {
        return;
    }

    await axios.delete(`/api/v1/financial/recurring-transactions/${schedule.id}`);
    await loadSchedules();
}
</script>

<template>
    <div>
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Schedules</h1>

            <button type="button" class="button-primary" @click="openCreateForm">New schedule</button>
        </div>

        <template v-if="loaded">
            <ModalDialog :open="formOpen" @close="closeForm">
                <TransactionForm
                    :key="editingSchedule?.id ?? 'new'"
                    :transaction="null"
                    :schedule="editingSchedule"
                    repeat-required
                    :accounts="accounts"
                    :commodities="commodities"
                    :institutions="institutions"
                    :payees="payees"
                    @saved="scheduleSaved"
                    @cancelled="closeForm"
                    @payee-created="registerPayee"
                    @account-created="registerAccount"
                />
            </ModalDialog>

            <p v-if="schedules.length === 0" class="mt-6 text-sm text-muted">No schedules yet.</p>

            <ul
                v-else
                class="mt-6 divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
            >
                <li class="flex items-center gap-4 bg-background/40 px-4 py-2">
                    <span class="field-label w-20">Next due</span>
                    <span class="field-label flex-1">Schedule</span>
                    <span class="field-label">Repeats</span>
                    <span class="w-8 shrink-0"></span>
                </li>
                <li v-for="schedule in schedules" :key="schedule.id" class="group px-4 py-2">
                    <div class="flex items-center gap-4">
                        <span class="w-20 font-mono text-xs whitespace-nowrap text-muted">{{ schedule.next_due_on }}</span>

                        <span class="min-w-0 flex-1 truncate text-sm">
                            {{ schedule.payee?.name ?? schedule.memo ?? '—' }}
                            <span v-if="schedule.payee && schedule.memo" class="text-muted">
                                · {{ schedule.memo }}
                            </span>
                        </span>

                        <span class="font-mono text-xs tracking-wider text-muted uppercase">
                            {{ recurrenceLabel(schedule.frequency, schedule.interval) }}<template v-if="schedule.ends_on"> · until {{ schedule.ends_on }}</template><template v-if="schedule.lead_days !== null"> · {{ schedule.lead_days }}d ahead</template>
                        </span>

                        <span class="flex items-center gap-3 font-mono text-xs text-muted">
                            <button
                                type="button"
                                class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-foreground"
                                @click="openEditForm(schedule)"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                class="tracking-wider uppercase transition-opacity sm:opacity-0 sm:group-hover:opacity-100 hover:text-danger"
                                @click="deleteSchedule(schedule)"
                            >
                                Delete
                            </button>
                        </span>

                        <span class="w-8 shrink-0"></span>
                    </div>

                    <div class="mt-1 flex flex-col">
                        <div
                            v-for="posting in schedule.postings"
                            :key="posting.id"
                            class="flex items-center gap-4 py-0.5 pl-24"
                        >
                            <span class="min-w-0 flex-1 truncate text-sm">
                                <span class="text-muted">{{ accountPathAncestor(posting.account?.path) }}</span><span>{{ accountPathLeaf(posting.account?.path) }}</span>
                                <span v-if="posting.memo" class="text-muted"> · {{ posting.memo }}</span>
                            </span>

                            <span class="text-right font-mono text-sm">
                                {{ posting.commodity ? formatAmount(posting.amount, posting.commodity) : posting.amount }}
                            </span>

                            <span
                                class="w-8 shrink-0 text-right font-mono text-sm text-muted"
                                :title="posting.status !== null ? `Posts as ${statusLabel(posting.status).toLowerCase()}` : undefined"
                            >
                                <PostingStatusGlyph :status="posting.status" />
                                <span v-if="posting.status !== null" class="sr-only">{{ statusLabel(posting.status) }}</span>
                            </span>
                        </div>
                    </div>
                </li>
            </ul>
        </template>

        <SkeletonList v-else />
    </div>
</template>
