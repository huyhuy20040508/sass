import type { Metadata } from 'next';
import { layCuaHang } from '@/lib/cua-hang';

export const dynamic = 'force-dynamic';

export async function generateMetadata(): Promise<Metadata> {
  const kq = await layCuaHang();
  return { title: kq.mo ? kq.ch.chi_nhanh.ten : 'Web bán hàng' };
}

const TIEU_DE: Record<string, string> = {
  khong_co: 'Không tìm thấy cửa hàng',
  het_han: 'Web tạm đóng',
  tam_ngung: 'Cửa hàng tạm ngừng',
  chi_nhanh_dong: 'Chi nhánh ngừng bán online',
};

// Giữ chỗ của khung: chỉ cho thấy tên miền đã nhận đúng cửa hàng + chi nhánh. Trang chủ thật dựng ở bước sau.
export default async function TrangChu() {
  const kq = await layCuaHang();
  if (!kq.mo) {
    return (
      <main className="giu-cho">
        <h1>{TIEU_DE[kq.lyDo] || 'Web tạm thời không mở được'}</h1>
        <p>{kq.thongBao}</p>
      </main>
    );
  }
  const { cua_hang: c, chi_nhanh: cn } = kq.ch;
  return (
    <main className="giu-cho">
      <h1>{cn.ten}</h1>
      <p>Web bán hàng của chi nhánh — khung dự án.</p>
      <ul>
        <li>Cửa hàng<span>{c.ten} ({c.ma})</span></li>
        <li>Chi nhánh<span>{cn.ma}</span></li>
        {cn.dien_thoai && <li>Điện thoại<span>{cn.dien_thoai}</span></li>}
        {cn.dia_chi && <li>Địa chỉ<span>{cn.dia_chi}</span></li>}
      </ul>
    </main>
  );
}
