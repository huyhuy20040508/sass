import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Dev: Vite chuyển /api sang máy chủ PHP để hai bên chung một gốc, khỏi phải mở CORS.
// Chạy thật cũng vậy: nginx phục vụ dist/ và chuyển /api về backend/public/index.php.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5180,
    proxy: {
      '/api': 'http://127.0.0.1:8097',
    },
  },
});
