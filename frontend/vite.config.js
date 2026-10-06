import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// En desarrollo, Vite hace de proxy hacia Laravel y FastAPI:
// el navegador solo habla con localhost:5173 y no hay problemas de CORS.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const laravel = env.VITE_PROXY_LARAVEL || 'http://127.0.0.1:8000';
  const ia = env.VITE_PROXY_IA || 'http://127.0.0.1:8001';

  return {
    plugins: [react(), tailwindcss()],
    server: {
      port: 5173,
      proxy: {
        '/api': { target: laravel, changeOrigin: true },
        '/ia': {
          target: ia,
          changeOrigin: true,
          rewrite: (ruta) => ruta.replace(/^\/ia/, ''),
        },
      },
    },
  };
});
