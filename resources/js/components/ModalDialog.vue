<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps<{ open: boolean }>();

const emit = defineEmits<{ close: [] }>();

function handleKeydown(event: KeyboardEvent): void {
    if (props.open && event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-6 pt-[10vh]"
                @click.self="emit('close')"
            >
                <div class="modal-panel w-full max-w-3xl" role="dialog" aria-modal="true">
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
