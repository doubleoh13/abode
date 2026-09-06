import axios, { isAxiosError } from 'axios';
import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import { router } from './router';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common.Accept = 'application/json';
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

axios.interceptors.response.use(undefined, (error: unknown) => {
    const status = isAxiosError(error) ? error.response?.status : undefined;

    if ((status === 401 || status === 419) && auth.user !== null) {
        auth.user = null;
        router.push({ name: 'login' });
    }

    return Promise.reject(error);
});

createApp(App).use(router).mount('#app');
