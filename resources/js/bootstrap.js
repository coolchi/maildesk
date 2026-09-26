import axios from 'axios';
import { router } from '@inertiajs/vue3';
import './echo';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;
window.axios.defaults.xsrfCookieName = 'XSRF-TOKEN';
window.axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

const applyCsrf = (token) => {
    if (!token) {
        return;
    }

    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;

    let meta = document.head.querySelector('meta[name="csrf-token"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        document.head.appendChild(meta);
    }
    meta.setAttribute('content', token);
};

const syncCsrfFromMeta = () => {
    const csrf = document.head.querySelector('meta[name="csrf-token"]');
    if (csrf?.content) {
        applyCsrf(csrf.content);
    }
};

syncCsrfFromMeta();

router.on('success', (event) => {
    applyCsrf(event.detail.page.props.csrf_token);
});

router.on('navigate', (event) => {
    applyCsrf(event.detail.page.props.csrf_token);
});
