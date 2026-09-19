import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { viteSingleFile } from 'vite-plugin-singlefile'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:48414',
      '/sanctum': 'http://127.0.0.1:48414',
    },
    port: 58414,
    strictPort: true,
    watch: {
      ignored: ['**/backend/**', '**/trading-engine/**', '**/media/**', '**/docs/**'],
    },
  },
  plugins: [
    react(),
    tailwindcss(),
    ...(process.env.HOSTINGER_SINGLE_FILE === 'true' ? [viteSingleFile()] : []),
  ],
  test: {
    environment: 'jsdom',
    setupFiles: './src/test/setup.ts',
  },
})
