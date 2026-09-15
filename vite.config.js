import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';

// The Laravel app is served over HTTPS, so the dev server must serve TLS too,
// otherwise the browser blocks the injected script and the HMR websocket as mixed content.
function devServerHttpsOptions() {
    const certDir = fileURLToPath(new URL('./storage/app/vite-dev', import.meta.url));
    const keyPath = `${certDir}/key.pem`;
    const certPath = `${certDir}/cert.pem`;

    try {
        if (!fs.existsSync(keyPath) || !fs.existsSync(certPath)) {
            fs.mkdirSync(certDir, { recursive: true });
            execFileSync('openssl', [
                'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                '-keyout', keyPath,
                '-out', certPath,
                '-days', '825',
                '-subj', '/CN=192.168.0.250',
                '-addext', 'subjectAltName=IP:192.168.0.250',
            ]);
        }

        return { key: fs.readFileSync(keyPath), cert: fs.readFileSync(certPath) };
    } catch (error) {
        console.warn('Could not generate/load self-signed cert for the dev server:', error.message);

        return undefined;
    }
}

export default defineConfig(({ command }) => ({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        tailwindcss(),
        react(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
        host: true,
        cors: true,
        https: command === 'serve' ? devServerHttpsOptions() : undefined,
        hmr: {
            host: '192.168.0.250',
            protocol: 'wss',
        },
    },
}));
