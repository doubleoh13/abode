import { createRouter, createWebHistory, type LocationQuery } from 'vue-router';
import { auth, resolveAuthenticatedUser, setupRequired } from './auth';
import AccountPage from './pages/AccountPage.vue';
import AccountsPage from './pages/AccountsPage.vue';
import AppShell from './layouts/AppShell.vue';
import BalanceSheetPage from './pages/BalanceSheetPage.vue';
import CommoditiesPage from './pages/CommoditiesPage.vue';
import CommodityPage from './pages/CommodityPage.vue';
import FinancesPage from './pages/FinancesPage.vue';
import HomePage from './pages/HomePage.vue';
import InstitutionsPage from './pages/InstitutionsPage.vue';
import JournalPage from './pages/JournalPage.vue';
import LoginPage from './pages/LoginPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';
import PayeesPage from './pages/PayeesPage.vue';
import ReportsPage from './pages/ReportsPage.vue';
import SchedulesPage from './pages/SchedulesPage.vue';
import SettingsPage from './pages/SettingsPage.vue';
import SetupPage from './pages/SetupPage.vue';

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
                            path: 'schedules',
                            name: 'finances.schedules',
                            component: SchedulesPage,
                        },
                        {
                            path: 'accounts',
                            name: 'finances.accounts',
                            component: AccountsPage,
                        },
                        {
                            path: 'accounts/:id',
                            name: 'finances.account',
                            component: AccountPage,
                        },
                        {
                            path: 'commodities',
                            name: 'finances.commodities',
                            component: CommoditiesPage,
                        },
                        {
                            path: 'commodities/:id',
                            name: 'finances.commodity',
                            component: CommodityPage,
                        },
                        {
                            path: 'reports',
                            name: 'finances.reports',
                            component: ReportsPage,
                        },
                        {
                            path: 'reports/balance-sheet',
                            name: 'finances.reports.balance-sheet',
                            component: BalanceSheetPage,
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
                { path: 'settings', name: 'settings', component: SettingsPage },
                { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
            ],
        },
        { path: '/login', name: 'login', component: LoginPage },
        { path: '/setup', name: 'setup', component: SetupPage },
    ],
});

export function intendedDestination(query: LocationQuery): string {
    const redirect = query.redirect;

    return typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//')
        ? redirect
        : '/';
}

router.beforeEach(async (to) => {
    if (!auth.resolved) {
        await resolveAuthenticatedUser();
    }

    if (!auth.user && (await setupRequired())) {
        return to.name === 'setup' ? undefined : { name: 'setup' };
    }

    if (to.name === 'setup') {
        return auth.user ? intendedDestination(to.query) : { name: 'login' };
    }

    if (to.meta.requiresAuth && !auth.user) {
        return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
    }

    if (to.name === 'login' && auth.user) {
        return intendedDestination(to.query);
    }
});
