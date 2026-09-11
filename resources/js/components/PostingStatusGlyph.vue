<script setup lang="ts">
import type { PostingStatus } from '../types';

defineProps<{
    status: PostingStatus | null;
}>();
</script>

<template>
    <svg
        v-if="status === 'reconciled'"
        class="inline-block size-3.5 align-[-0.125em]"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <rect x="3" y="7" width="10" height="7" rx="1" />
        <path d="M5 7V5a3 3 0 0 1 6 0v2" />
    </svg>
    <span v-else aria-hidden="true">{{ status === 'cleared' ? 'C' : status === 'pending' ? '_' : '' }}</span>
</template>
