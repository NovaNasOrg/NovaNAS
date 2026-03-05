import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Get CSRF token from meta tag
const getCsrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

// Override default fetch to automatically include CSRF token for state-changing requests
const originalFetch = window.fetch;
window.fetch = function(url, options = {}) {
    const csrfToken = getCsrfToken();

    const headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
    };

    if (csrfToken && options.method && !['GET', 'HEAD', 'OPTIONS'].includes(options.method.toUpperCase())) {
        headers['X-CSRF-TOKEN'] = csrfToken;
    }

    return originalFetch(url, {
        method: options.method,
        headers,
        body: options.body,
        credentials: options.credentials,
        mode: options.mode,
    });
};
