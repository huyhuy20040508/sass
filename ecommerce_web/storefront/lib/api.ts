import 'server-only';
import { request } from 'node:http';

// Gọi API PHP từ PHÍA MÁY CHỦ qua cổng nội bộ của nginx. Dùng node:http chứ không dùng fetch:
// fetch của Node không cho đặt header Host, mà PHP nhận shop theo đúng Host của trang đang mở.
const NOI_BO = new URL(process.env.WEB_API_INTERNAL || 'http://127.0.0.1:8098');

export type KetQua<T> = { status: number; success: boolean; data?: T; message?: string };

export function goiApi<T>(path: string, host: string): Promise<KetQua<T>> {
  return new Promise((resolve) => {
    const req = request(
      { hostname: NOI_BO.hostname, port: NOI_BO.port, path: `/api${path}`, method: 'GET', headers: { Host: host, Accept: 'application/json' }, timeout: 8000 },
      (res) => {
        let raw = '';
        res.setEncoding('utf8');
        res.on('data', (c) => (raw += c));
        res.on('end', () => {
          try {
            const j = JSON.parse(raw);
            resolve({ status: res.statusCode || 0, success: !!j.success, data: j.data, message: j.message });
          } catch {
            resolve({ status: res.statusCode || 0, success: false, message: 'API trả dữ liệu lạ' });
          }
        });
      },
    );
    req.on('timeout', () => req.destroy(new Error('API nội bộ không trả lời')));
    req.on('error', (e) => resolve({ status: 0, success: false, message: e.message }));
    req.end();
  });
}
