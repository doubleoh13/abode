import { createRouter, createWebHistory, type LocationQuery, type RouteLocationRaw } from 'vue-router';
import { auth, resolveAuthenticatedUser, setupRequired } from './auth';
import AccountPage from './pages/AccountPage.vue';
import AccountsPage from './pages/AccountsPage.vue';
import AppShell from './layouts/AppShell.vue';
import BalanceSheetPage from './pages/BalanceSheetPage.vue';
import CommoditiesPage from './pages/CommoditiesPage.vue';
import CommodityPage from './pages/CommodityPage.vue';
import FinancesPage from './pages/FinancesPage.vue';
import InstitutionsPage from './pages/InstitutionsPage.vue';
import JournalPage from './pages/JournalPage.vue';
import LoginPage from './pages/LoginPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';
import PayeesPage from './pages/PayeesPage.vue';
import ReportsPage from './pages/ReportsPage.vue';
import SchedulesPage from './pages/SchedulesPage.vue';
import SettingsPage from './pages/SettingsPage.vue';
import SetupPage from './pages/SetupPage.vue';
import type { Account } from './types';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            component: AppShell,
            meta: { requiresAuth: true },
            children: [
                { path: '', name: 'home', redirect: { name: 'finances' } },
                {
                    path: 'finances',
                    children: [
                        { path: '', name: 'finances', component: FinancesPage, meta: { title: 'Finances' } },
                        {
                            path: 'journal',
                            name: 'finances.journal',
                            component: JournalPage,
                            meta: { title: 'Journal' },
                        },
                        {
                            path: 'schedules',
                            name: 'finances.schedules',
                            component: SchedulesPage,
                            meta: { title: 'Schedules' },
                        },
                        {
                            path: 'accounts',
                            name: 'finances.accounts',
                            component: AccountsPage,
                            meta: { title: 'Accounts' },
                        },
                        {
                            path: 'accounts/:type(assets|liabilities|income|expenses|equity)',
                            redirect: (to) => ({ name: 'finances.accounts', hash: `#${String(to.params.type)}` }),
                        },
                        {
                            path: 'accounts/:segments+',
                            name: 'finances.account',
                            component: AccountPage,
                            meta: { title: 'Account' },
                        },
                        {
                            path: 'commodities',
                            name: 'finances.commodities',
                            component: CommoditiesPage,
                            meta: { title: 'Commodities' },
                        },
                        {
                            path: 'commodities/:id',
                            name: 'finances.commodity',
                            component: CommodityPage,
                            meta: { title: 'Commodity' },
                        },
                        {
                            path: 'reports',
                            name: 'finances.reports',
                            component: ReportsPage,
                            meta: { title: 'Reports' },
                        },
                        {
                            path: 'reports/balance-sheet',
                            name: 'finances.reports.balance-sheet',
                            component: BalanceSheetPage,
                            meta: { title: 'Balance sheet' },
                        },
                        {
                            path: 'institutions',
                            name: 'finances.institutions',
                            component: InstitutionsPage,
                            meta: { title: 'Institutions' },
                        },
                        {
                            path: 'payees',
                            name: 'finances.payees',
                            component: PayeesPage,
                            meta: { title: 'Payees' },
                        },
                    ],
                },
                { path: 'settings', name: 'settings', component: SettingsPage, meta: { title: 'Settings' } },
                { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundPage, meta: { title: 'Not found' } },
            ],
        },
        { path: '/login', name: 'login', component: LoginPage, meta: { title: 'Log in' } },
        { path: '/setup', name: 'setup', component: SetupPage, meta: { title: 'Setup' } },
    ],
});

export function accountRoute(account: Pick<Account, 'slug_path'>): RouteLocationRaw {
    return { name: 'finances.account', params: { segments: account.slug_path.split('/') } };
}

export function accountTypeRoute(account: Pick<Account, 'slug_path'>): RouteLocationRaw {
    return { name: 'finances.accounts', hash: `#${account.slug_path.split('/')[0]}` };
}

export function setPageTitle(title: string | undefined): void {
    document.title = title ? `${title} · Abode` : 'Abode';
}

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

router.afterEach((to) => {
    setPageTitle(typeof to.meta.title === 'string' ? to.meta.title : undefined);
});
