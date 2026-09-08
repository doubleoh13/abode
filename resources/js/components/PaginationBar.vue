<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    page: number;
    lastPage: number;
}>();

const emit = defineEmits<{
    change: [number];
}>();

// Windowed page links: first, last, and current±1, with null marking a gap.
const pageLinks = computed<Array<number | null>>(() => {
    const candidates = new Set([1, props.page - 1, props.page, props.page + 1, props.lastPage]);
    const pages = [...candidates]
        .filter((candidate) => candidate >= 1 && candidate <= props.lastPage)
        .sort((first, second) => first - second);
    const links: Array<number | null> = [];
    let previous = 0;

    for (const candidate of pages) {
        if (candidate - previous > 1) {
            links.push(null);
        }

        links.push(candidate);
        previous = candidate;
    }

    return links;
});
</script>

<template>
    <div v-if="lastPage > 1" class="mt-4 flex items-center justify-between">
        <button type="button" class="button-subtle" :disabled="page <= 1" @click="emit('change', page - 1)">
            Prev
        </button>

        <div class="flex items-center gap-1">
            <template v-for="(link, index) in pageLinks" :key="index">
                <span v-if="link === null" class="px-1 font-mono text-xs text-muted">…</span>
                <button
                    v-else
                    type="button"
                    class="min-w-8 rounded-sm border px-2 py-1.5 font-mono text-xs transition-colors"
                    :class="
                        link === page
                            ? 'border-edge bg-surface text-foreground'
                            : 'border-transparent text-muted hover:text-foreground'
                    "
                    :disabled="link === page"
                    @click="emit('change', link)"
                >
                    {{ link }}
                </button>
            </template>
        </div>

        <button type="button" class="button-subtle" :disabled="page >= lastPage" @click="emit('change', page + 1)">
            Next
        </button>
    </div>
</template>
