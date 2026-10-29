import type { Metadata } from 'next';
import './globals.css';

export const metadata: Metadata = {
  // Trang khách tự đặt tiêu đề theo shop; đây chỉ là chữ dự phòng.
  title: 'Web bán hàng',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="vi">
      <body>{children}</body>
    </html>
  );
}
