import type { Metadata } from 'next';

export const metadata: Metadata = { title: 'Quản trị web' };

// Trang giữ chỗ của khu quản trị (chủ shop đăng nhập bằng tài khoản POS — dựng ở bước sau).
export default function QuanTri() {
  return (
    <main className="giu-cho">
      <h1>Quản trị web</h1>
      <p>Khung dự án — khu quản trị sẽ dựng ở đây.</p>
    </main>
  );
}
