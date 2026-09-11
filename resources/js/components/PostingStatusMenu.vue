<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import PostingStatusGlyph from './PostingStatusGlyph.vue';
import { statusClass, statusLabel } from '../journal';
import type { PostingStatus } from '../types';

const props = defineProps<{
    status: PostingStatus;
    matched?: boolean;
    detail?: string;
    align?: 'left' | 'right';
}>();

const emit = defineEmits<{
    select: [status: PostingStatus];
}>();

const statuses: PostingStatus[] = ['pending', 'cleared', 'reconciled'];

const open = ref(false);
const highlighted = ref<PostingStatus>(props.status);
const trigger = ref<HTMLButtonElement | null>(null);
const menu = ref<HTMLUListElement | null>(null);
const position = ref({ top: 0, left: 0 });

function place(): void {
    const rect = trigger.value?.getBoundingClientRect();

    if (rect) {
        position.value = { top: rect.bottom + 4, left: props.align === 'left' ? rect.left : rect.right };
    }
}

async function show(): Promise<void> {
    highlighted.value = props.status;
    place();
    open.value = true;
    await nextTick();
    menu.value?.focus();
}

function hide(): void {
    open.value = false;
}

function choose(status: PostingStatus): void {
    hide();

    if (status !== props.status) {
        emit('select', status);
    }

    trigger.value?.focus();
}

function onDocumentPointerDown(event: PointerEvent): void {
    const target = event.target as Node | null;

    if (target && !menu.value?.contains(target) && !trigger.value?.contains(target)) {
        hide();
    }
}

function onMenuKeydown(event: KeyboardEvent): void {
    const index = statuses.indexOf(highlighted.value);

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        hide();
        trigger.value?.focus();
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        highlighted.value = statuses[Math.min(index + 1, statuses.length - 1)];
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        highlighted.value = statuses[Math.max(index - 1, 0)];
    } else if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        choose(highlighted.value);
    } else if (event.key === 'Tab') {
        hide();
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('pointerdown', onDocumentPointerDown);
        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
    } else {
        document.removeEventListener('pointerdown', onDocumentPointerDown);
        window.removeEventListener('scroll', hide, true);
        window.removeEventListener('resize', hide);
    }
});

onBeforeUnmount(() => {
    open.value = false;
});
</script>

<template>
    <button
        ref="trigger"
        type="button"
        class="shrink-0 rounded-sm font-mono text-sm not-italic transition-colors hover:bg-edge/60 hover:text-foreground"
        :class="[statusClass(matched ?? false), align === 'left' ? 'px-1 text-center' : 'w-8 text-right']"
        :title="detail ? `${statusLabel(status)} · ${detail}` : statusLabel(status)"
        aria-haspopup="menu"
        :aria-expanded="open"
        @click.stop="open ? hide() : show()"
    >
        <PostingStatusGlyph :status="status" />
        <span class="sr-only">{{ statusLabel(status) }}{{ matched ? ', matched to bank' : '' }}</span>
    </button>

    <Teleport to="body">
        <ul
            v-if="open"
            ref="menu"
            role="menu"
            tabindex="-1"
            class="fixed z-[70] w-36 rounded-sm border border-edge bg-surface py-1 outline-none"
            :class="align === 'left' ? '' : '-translate-x-full'"
            :style="{ top: `${position.top}px`, left: `${position.left}px` }"
            @keydown="onMenuKeydown"
            @click.stop
        >
            <li
                v-for="option in statuses"
                :key="option"
                role="menuitemradio"
                :aria-checked="option === status"
                class="flex cursor-pointer items-center gap-3 px-3 py-1.5 text-sm"
                :class="option === highlighted ? 'bg-background text-foreground' : 'text-muted'"
                @mousemove="highlighted = option"
                @click="choose(option)"
            >
                <span class="w-4 text-center font-mono" :class="option === status ? 'text-accent' : ''">
                    <PostingStatusGlyph :status="option" />
                </span>
                <span>{{ statusLabel(option) }}</span>
                <span v-if="option === status" class="ml-auto font-mono text-xs text-accent">✓</span>
            </li>
        </ul>
    </Teleport>
</template>
