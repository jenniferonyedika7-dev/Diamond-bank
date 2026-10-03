import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  // Sanctum treats localhost:5173 as the stateful SPA origin; keep it fixed.
  server: { host: 'localhost', port: 5173, strictPort: true },
})
