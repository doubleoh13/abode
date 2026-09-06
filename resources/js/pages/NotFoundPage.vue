<script setup lang="ts">
import { useRoute } from 'vue-router';

const route = useRoute();
</script>

<template>
    <div>
        <h1 class="sr-only">Page not found</h1>

        <div class="max-w-xl rounded-md border border-edge bg-surface p-6 font-mono text-sm">
            <p>
                <span class="text-accent">abode:~$</span>
                cd {{ route.fullPath }}
            </p>
            <p class="mt-2 text-danger">cd: no such file or directory: {{ route.fullPath }}</p>
            <p class="mt-4">
                <span class="text-accent">abode:~$</span>
                <RouterLink
                    :to="{ name: 'home' }"
                    class="ml-2 underline decoration-edge underline-offset-4 transition-colors hover:text-accent"
                >
                    cd ~
                </RouterLink>
                <span class="terminal-cursor ml-2 inline-block h-4 w-2 bg-accent align-middle" />
            </p>
        </div>
    </div>
</template>

<style scoped>
.terminal-cursor {
    animation: blink 1.1s steps(2, start) infinite;
}

@keyframes blink {
    to {
        visibility: hidden;
    }
}
</style>
