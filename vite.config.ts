/// <reference types="vitest/config" />
import tailwindcss from "@tailwindcss/vite";
import vue from "@vitejs/plugin-vue";
import { defineConfig } from "vite";

/**
 * Builds the self-contained yikes island into dist/ (committed — composer
 * consumers never run npm). Fixed entry/css names are cache-busted by the
 * server via ?v=; code-split chunks (snapdom) are content-hashed.
 */
export default defineConfig({
    plugins: [vue(), tailwindcss()],
    build: {
        outDir: "dist",
        emptyOutDir: true,
        cssCodeSplit: false,
        rollupOptions: {
            input: "resources/js/standalone.ts",
            output: {
                entryFileNames: "yikes.js",
                chunkFileNames: "yikes-[hash].js",
                assetFileNames: "yikes[extname]",
            },
        },
    },
    test: {
        environment: "happy-dom",
        include: ["resources/js/**/*.spec.ts"],
    },
});
