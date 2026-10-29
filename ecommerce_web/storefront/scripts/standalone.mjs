// Bản standalone của Next không tự kèm tệp tĩnh: chép .next/static và public vào
// cạnh server.js, nếu không trang chạy được nhưng mất hết CSS/JS.
import { cpSync, existsSync } from 'node:fs';

cpSync('.next/static', '.next/standalone/.next/static', { recursive: true });
if (existsSync('public')) cpSync('public', '.next/standalone/public', { recursive: true });
