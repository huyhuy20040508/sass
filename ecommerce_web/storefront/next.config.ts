import type { NextConfig } from 'next';

const config: NextConfig = {
  // Chạy bằng `node server.js` dưới systemd, không cần node_modules trên máy chủ.
  output: 'standalone',
  poweredByHeader: false,
};

export default config;
