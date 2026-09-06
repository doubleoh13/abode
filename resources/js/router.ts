import { createRouter, createWebHistory } from 'vue-router';
import { auth, resolveAuthenticatedUser } from './auth';
import AccountsPage from './pages/AccountsPage.vue';
import AppShell from './layouts/AppShell.vue';
import CommoditiesPage from './pages/CommoditiesPage.vue';
import FinancesPage from './pages/FinancesPage.vue';
import HomePage from './pages/HomePage.vue';
import InstitutionsPage from './pages/InstitutionsPage.vue';
import JournalPage from './pages/JournalPage.vue';
import LoginPage from './pages/LoginPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';
import PayeesPage from './pages/PayeesPage.vue';

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
                            path: 'journal',
                            name: 'finances.journal',
                            component: JournalPage,
                        },
                        {
                            path: 'accounts',
                            name: 'finances.accounts',
                            component: AccountsPage,
                        },
                        {
                            path: 'commodities',
                            name: 'finances.commodities',
                            component: CommoditiesPage,
                        },
                        {
                            path: 'institutions',
                            name: 'finances.institutions',
                            component: InstitutionsPage,
                        },
                        {
                            path: 'payees',
                            name: 'finances.payees',
                            component: PayeesPage,
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
