import type { Metadata } from 'next';
import FormTaiKhoan from '@/components/khach/FormTaiKhoan';

export const metadata: Metadata = { title: 'Đăng nhập' };

export default function DangNhap() {
  return (
    <main className="tk-trang">
      <FormTaiKhoan cheDo="dang-nhap" />
    </main>
  );
}
