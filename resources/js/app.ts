import axios from 'axios';
import { createApp } from 'vue';
import App from './App.vue';
import { router } from './router';

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common.Accept = 'application/json';
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;

createApp(App).use(router).mount('#app');
