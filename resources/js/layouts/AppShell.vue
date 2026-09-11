<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { auth, hasPermission, logout } from '../auth';

const route = useRoute();
const router = useRouter();
const mobileNavigationOpen = ref(false);

async function endSession(): Promise<void> {
    await logout();
    router.push({ name: 'login' });
}
</script>

<template>
    <div class="min-h-screen">
        <header
            class="flex items-center justify-between border-b border-edge bg-surface px-4 py-3 md:hidden"
        >
            <RouterLink :to="{ name: 'home' }" class="font-mono text-base font-semibold">
                abode<span class="text-accent">_</span>
            </RouterLink>

            <button
                type="button"
                aria-label="Open navigation"
                class="text-muted transition-colors hover:text-foreground"
                @click="mobileNavigationOpen = true"
            >
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
        </header>

        <div
            v-if="mobileNavigationOpen"
            class="fixed inset-0 z-30 bg-black/60 md:hidden"
            @click="mobileNavigationOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col border-r border-edge bg-surface transition-transform duration-200 ease-out md:translate-x-0"
            :class="mobileNavigationOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex items-center justify-between px-6 py-4">
                <RouterLink :to="{ name: 'home' }" class="font-mono text-base font-semibold">
                    abode<span class="text-accent">_</span>
                </RouterLink>

                <button
                    type="button"
                    aria-label="Close navigation"
                    class="text-muted transition-colors hover:text-foreground md:hidden"
                    @click="mobileNavigationOpen = false"
                >
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex flex-1 flex-col gap-1 px-3 py-2">
                <template v-if="hasPermission('view-finances')">
                    <RouterLink
                        :to="{ name: 'finances' }"
                        class="rounded-sm border-l-2 px-3 py-2 text-sm transition-colors"
                        :class="
                            String(route.name).startsWith('finances')
                                ? 'border-accent bg-background text-foreground'
                                : 'border-transparent text-muted hover:bg-background/60 hover:text-foreground'
                        "
                        @click="mobileNavigationOpen = false"
                    >
                        Finances
                    </RouterLink>

                    <div
                        v-if="String(route.name).startsWith('finances')"
                        class="ml-5 flex flex-col gap-1"
                    >
                        <RouterLink
                            v-for="subItem in [
                                { name: 'finances.journal', label: 'Journal' },
                                { name: 'finances.inbox', label: 'Inbox' },
                                { name: 'finances.schedules', label: 'Schedules' },
                                { name: 'finances.accounts', label: 'Accounts' },
                                { name: 'finances.commodities', label: 'Commodities' },
                                { name: 'finances.reports', label: 'Reports' },
                                { name: 'finances.institutions', label: 'Institutions' },
                                { name: 'finances.payees', label: 'Payees' },
                            ]"
                            :key="subItem.name"
                            :to="{ name: subItem.name }"
                            class="rounded-sm px-3 py-1.5 text-sm transition-colors"
                            :class="
                                String(route.name).startsWith(subItem.name)
                                    ? 'bg-background text-foreground'
                                    : 'text-muted hover:bg-background/60 hover:text-foreground'
                            "
                            @click="mobileNavigationOpen = false"
                        >
                            {{ subItem.label }}
                        </RouterLink>
                    </div>
                </template>
            </nav>

            <div class="border-t border-edge px-6 py-4">
                <p class="text-sm font-medium">{{ auth.user?.name }}</p>
                <p class="text-xs text-muted">{{ auth.user?.email }}</p>

                <div class="mt-3 flex items-center gap-4 font-mono text-xs tracking-wider text-muted uppercase">
                    <RouterLink
                        :to="{ name: 'settings' }"
                        class="transition-colors hover:text-foreground"
                        @click="mobileNavigationOpen = false"
                    >
                        Settings
                    </RouterLink>

                    <button
                        type="button"
                        class="transition-colors hover:text-foreground"
                        @click="endSession"
                    >
                        Log out
                    </button>
                </div>
            </div>
        </aside>

        <main class="px-6 py-8 md:ml-60">
            <RouterView />
        </main>
    </div>
</template>
