import type { Metadata } from 'next';
import FormTaiKhoan from '@/components/khach/FormTaiKhoan';

export const metadata: Metadata = { title: 'Tạo tài khoản' };

export default function DangKy() {
  return (
    <main className="tk-trang">
      <FormTaiKhoan cheDo="dang-ky" />
    </main>
  );
}
