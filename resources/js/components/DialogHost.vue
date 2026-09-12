<script setup lang="ts">
import ModalDialog from './ModalDialog.vue';
import { dialog, settleDialog } from '../dialogs';
</script>

<template>
    <ModalDialog :open="dialog.pending !== null" nested @close="settleDialog(false)">
        <form
            v-if="dialog.pending"
            class="mx-auto flex w-96 flex-col gap-5 rounded-md border border-edge bg-surface p-5"
            @submit.prevent="settleDialog(true)"
        >
            <p class="text-sm">{{ dialog.pending.message }}</p>

            <div class="flex gap-3">
                <button type="submit" class="button-primary">{{ dialog.pending.confirmLabel }}</button>
                <button
                    v-if="dialog.pending.kind === 'confirm'"
                    type="button"
                    class="button-subtle"
                    @click="settleDialog(false)"
                >
                    Cancel
                </button>
            </div>
        </form>
    </ModalDialog>
</template>
