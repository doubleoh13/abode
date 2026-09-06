import { createRouter, createWebHistory } from 'vue-router';
import { auth, resolveAuthenticatedUser } from './auth';
import AccountsPage from './pages/AccountsPage.vue';
import AppShell from './layouts/AppShell.vue';
import FinancesPage from './pages/FinancesPage.vue';
import HomePage from './pages/HomePage.vue';
import LoginPage from './pages/LoginPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            component: AppShell,
            meta: { requiresAuth: true },
            children: [
                { path: '', name: 'home', component: HomePage },
                {
                    path: 'finances',
                    children: [
                        { path: '', name: 'finances', component: FinancesPage },
                        {
                            path: 'accounts',
                            name: 'finances.accounts',
                            component: AccountsPage,
                        },
                    ],
                },
                { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
            ],
        },
        { path: '/login', name: 'login', component: LoginPage },
    ],
});

router.beforeEach(async (to) => {
    if (!auth.resolved) {
        await resolveAuthenticatedUser();
    }

    if (to.meta.requiresAuth && !auth.user) {
        return { name: 'login' };
    }

    if (to.name === 'login' && auth.user) {
        return { name: 'home' };
    }
});
