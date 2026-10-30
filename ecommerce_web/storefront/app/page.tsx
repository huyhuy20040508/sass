import { headers } from 'next/headers';
import { goiApi } from '@/lib/api';

export const dynamic = 'force-dynamic';

type Health = { status: string; doc_pos: boolean; doc_nen_tang: boolean; ghi_bang_web: boolean; khong_ghi_bang_pos: boolean };

// Trang giữ chỗ của khung dự án: chưa có nghiệp vụ, chỉ cho thấy chuỗi Next → API PHP → DB chạy.
export default async function TrangChu() {
  const host = (await headers()).get('host') || '';
  const r = await goiApi<Health>('/health', host);
  const dong: [string, boolean][] = [
    ['API PHP', r.status > 0],
    ['Đọc DB bán hàng POS', !!r.data?.doc_pos],
    ['Đọc DB nền tảng', !!r.data?.doc_nen_tang],
    ['Ghi bảng web_', !!r.data?.ghi_bang_web],
    ['Không ghi được bảng POS / nền tảng', !!r.data?.khong_ghi_bang_pos],
  ];
  return (
    <main className="giu-cho">
      <h1>Web bán hàng</h1>
      <p>Khung dự án — trang khách sẽ dựng ở đây.</p>
      <ul>{dong.map(([ten, ok]) => <li key={ten}>{ten}<span className={ok ? 'ok' : 'loi'}>{ok ? 'Chạy' : 'Lỗi'}</span></li>)}</ul>
    </main>
  );
}
