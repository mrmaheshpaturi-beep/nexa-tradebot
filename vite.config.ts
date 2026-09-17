import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { viteSingleFile } from 'vite-plugin-singlefile'
import { defineConfig } from 'vitest/config'

// https://vite.dev/config/
export default defineConfig({
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:43128',
      '/sanctum': 'http://127.0.0.1:43128',
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
