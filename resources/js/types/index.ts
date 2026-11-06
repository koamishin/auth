export * from './auth';
export * from './navigation';
export * from './ui';

import type { Auth } from './auth';
import type { ColorTheme } from '@/conf/themes';

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    authLayout: 'simple' | 'card' | 'split';
    authColorTheme?: ColorTheme | null;
    authBackground?: 'gradient' | 'spotlight' | 'grid' | null;
    appearancePreview?: { environment: string; backUrl: string } | null;
    [key: string]: unknown;
};
