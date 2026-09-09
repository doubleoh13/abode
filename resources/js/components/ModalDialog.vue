<script lang="ts">
const openDialogs: symbol[] = [];
</script>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';

const props = defineProps<{ open: boolean; nested?: boolean }>();

const emit = defineEmits<{ close: [] }>();
const dialogId = Symbol();

function removeFromStack(): void {
    const index = openDialogs.indexOf(dialogId);

    if (index !== -1) {
        openDialogs.splice(index, 1);
    }
}

watch(
    () => props.open,
    (open) => {
        removeFromStack();

        if (open) {
            openDialogs.push(dialogId);
        }
    },
    { immediate: true },
);

function handleKeydown(event: KeyboardEvent): void {
    if (props.open && event.key === 'Escape' && openDialogs.at(-1) === dialogId) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => {
    removeFromStack();
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="open"
                class="fixed inset-0 flex items-start justify-center overflow-y-auto p-6 pt-[6vh]"
                :class="nested ? 'z-[60] bg-black/40' : 'z-50 bg-black/60'"
                @click.self="emit('close')"
            >
                <div
                    class="modal-panel w-full max-w-5xl"
                    role="dialog"
                    aria-modal="true"
                    @click.self="emit('close')"
                >
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 150ms ease;
}

.modal-enter-active .modal-panel,
.modal-leave-active .modal-panel {
    transition:
        opacity 150ms ease,
        transform 150ms ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

.modal-enter-from .modal-panel,
.modal-leave-to .modal-panel {
    opacity: 0;
    transform: translateY(-0.5rem) scale(0.98);
}
</style>
