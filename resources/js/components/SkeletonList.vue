<script setup lang="ts">
withDefaults(defineProps<{ rows?: number }>(), { rows: 8 });
</script>

<template>
    <div
        aria-hidden="true"
        class="mt-6 animate-pulse divide-y divide-edge overflow-hidden rounded-md border border-edge bg-surface"
    >
        <div v-for="row in rows" :key="row" class="flex items-center justify-between gap-4 px-4 py-3">
            <span class="h-3 w-48 rounded-sm bg-edge/60"></span>
            <span class="h-3 w-16 rounded-sm bg-edge/60"></span>
        </div>
    </div>
</template>
