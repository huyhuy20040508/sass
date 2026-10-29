import 'server-only';
import { request } from 'node:http';

// Gọi API PHP từ PHÍA MÁY CHỦ (component server), qua cổng nội bộ của nginx.
// Dùng node:http chứ không dùng fetch: fetch của Node không cho đặt header Host,
// mà PHP nhận shop theo đúng Host của trang đang mở.
const NOI_BO = new URL(process.env.WEB_API_INTERNAL || 'http://127.0.0.1:8098');

export type KetQua<T> = { status: number; success: boolean; data?: T; message?: string };

export function shopApi<T>(path: string, host: string): Promise<KetQua<T>> {
  return new Promise((resolve, reject) => {
    const req = request(
      {
        hostname: NOI_BO.hostname,
        port: NOI_BO.port,
        path: `/api${path}`,
        method: 'GET',
        headers: { Host: host, Accept: 'application/json' },
        timeout: 8000,
      },
      (res) => {
        let raw = '';
        res.setEncoding('utf8');
        res.on('data', (c) => (raw += c));
        res.on('end', () => {
          try {
            const json = JSON.parse(raw);
            resolve({ status: res.statusCode || 0, success: !!json.success, data: json.data, message: json.message });
          } catch {
            resolve({ status: res.statusCode || 0, success: false, message: 'Máy chủ trả dữ liệu lạ' });
          }
        });
      },
    );
    req.on('timeout', () => req.destroy(new Error('API nội bộ không trả lời')));
    req.on('error', reject);
    req.end();
  });
}
