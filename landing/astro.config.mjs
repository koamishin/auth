// @ts-check
import { defineConfig } from 'astro/config';

// https://astro.build/config
export default defineConfig({
    site: 'https://koamishin.github.io',
    base:
        process.env.ASTRO_BASE || (process.env.GITHUB_ACTIONS ? '/auth' : '/'),
});
