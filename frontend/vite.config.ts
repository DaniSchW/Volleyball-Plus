import { fileURLToPath } from 'node:url';
import type { Plugin } from 'vite';
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

/**
 * The build goes straight into Laravel's document root. The entry page is
 * app.html (not index.html) so the web server never serves it directly:
 * every app URL goes through Laravel, which adds the security headers and
 * HTTPS checks.
 */
const ENTRY = 'app.html';

/** Dev server: answer app navigations (e.g. /ssv-ulm/cash) with app.html. */
function spaFallback(): Plugin {
  return {
    name: 'volleyball-plus:spa-fallback',
    configureServer(server) {
      server.middlewares.use((req, _res, next) => {
        const url = req.url ?? '/';
        const isPage = req.headers.accept?.includes('text/html') && !/^\/(api|cron|@|src|node_modules)/.test(url);
        if (isPage && !url.includes('.')) req.url = `/${ENTRY}`;
        next();
      });
    },
  };
}

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['icons/apple-touch-icon.png', 'icons/favicon.svg'],
      manifest: {
        name: 'Volleyball Plus',
        short_name: 'Volleyball+',
        description: 'Team-Organisation für Volleyball-Abteilungen',
        lang: 'de',
        start_url: '/',
        scope: '/',
        display: 'standalone',
        orientation: 'portrait',
        background_color: '#ffffff',
        theme_color: '#1d4ed8',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        navigateFallback: `/${ENTRY}`,
        navigateFallbackDenylist: [/^\/api\//, /^\/cron\//, /^\/up$/],
      },
    }),
    spaFallback(),
  ],
  build: {
    outDir: '../backend/public',
    // Laravel's index.php, .htaccess etc. live there too.
    emptyOutDir: false,
    assetsDir: 'assets',
    rolldownOptions: {
      input: fileURLToPath(new URL(ENTRY, import.meta.url)),
    },
  },
  server: {
    proxy: {
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['src/test/setup.ts'],
    globals: false,
  },
});
