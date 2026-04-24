import type { SharedPageProps } from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedPageProps;
    }
}
