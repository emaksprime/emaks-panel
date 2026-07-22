import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

const normalizeDevServerOrigin = (value: string | undefined) => {
    if (!value) {
        return undefined;
    }

    try {
        const parsed = new URL(value);

        if (!['http:', 'https:'].includes(parsed.protocol)) {
            return undefined;
        }

        parsed.pathname = '';
        parsed.search = '';
        parsed.hash = '';

        return parsed.toString().replace(/\/$/, '');
    } catch {
        return undefined;
    }
};

const devServerOrigin = normalizeDevServerOrigin(process.env.VITE_DEV_SERVER_ORIGIN);
const devServerOriginUrl = devServerOrigin ? new URL(devServerOrigin) : null;
const localDevCorsOrigins = [
    /^https?:\/\/localhost(?::\d+)?$/,
    /^https?:\/\/127\.0\.0\.1(?::\d+)?$/,
    /^https?:\/\/\[::1\](?::\d+)?$/,
    /^https?:\/\/10\.\d{1,3}\.\d{1,3}\.\d{1,3}(?::\d+)?$/,
    /^https?:\/\/192\.168\.\d{1,3}\.\d{1,3}(?::\d+)?$/,
    /^https?:\/\/172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}(?::\d+)?$/,
];

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
    server: {
        ...(devServerOrigin ? {
            origin: devServerOrigin,
            cors: {
                origin: localDevCorsOrigins,
            },
            hmr: {
                host: devServerOriginUrl?.hostname,
                clientPort: devServerOriginUrl?.port ? Number(devServerOriginUrl.port) : undefined,
                protocol: devServerOriginUrl?.protocol === 'https:' ? 'wss' : 'ws',
            },
        } : {}),
    },
});
