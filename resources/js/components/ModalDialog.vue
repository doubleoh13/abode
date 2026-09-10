<script lang="ts">
const openDialogs: symbol[] = [];
</script>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; nested?: boolean }>();

const emit = defineEmits<{ close: [] }>();
const dialogId = Symbol();
const panel = ref<HTMLElement | null>(null);
let opener: Element | null = null;

const FOCUSABLE = 'a[href], button:not([disabled]):not([tabindex="-1"]), input:not([disabled]):not([tabindex="-1"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusables(): HTMLElement[] {
    return [...(panel.value?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [])];
}

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
            opener = document.activeElement;

            void nextTick(() => {
                if (!panel.value?.contains(document.activeElement)) {
                    focusables()[0]?.focus();
                }
            });
        } else if (opener instanceof HTMLElement && document.contains(opener)) {
            opener.focus();
            opener = null;
        }
    },
    { immediate: true },
);

function handleKeydown(event: KeyboardEvent): void {
    if (!props.open || openDialogs.at(-1) !== dialogId) {
        return;
    }

    if (event.key === 'Escape') {
        emit('close');
    }

    if (event.key === 'Tab') {
        trapFocus(event);
    }
}

function trapFocus(event: KeyboardEvent): void {
    const elements = focusables();

    if (elements.length === 0) {
        return;
    }

    const first = elements[0];
    const last = elements[elements.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || !panel.value?.contains(active))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (active === last || !panel.value?.contains(active))) {
        event.preventDefault();
        first.focus();
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
                    ref="panel"
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
