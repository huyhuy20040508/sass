import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { notFound } from 'next/navigation';
import '@fortawesome/fontawesome-free/css/all.min.css';
import DemLuot from '@/components/shop/DemLuot';
import NutNoi from '@/components/shop/NutNoi';
import ShopFooter from '@/components/shop/ShopFooter';
import ShopHeader from '@/components/shop/ShopHeader';
import { FONT_LOP } from '@/lib/fonts';
import { bienCss, layKhung } from '@/lib/khung';
import './shop.css';
// CSS mọi theme nạp cùng lúc, mỗi bộ gói trong lớp .theme-<mã> của nó: theme được phép
// mặc lại kiểu cho đầu/chân trang dùng chung, mà đầu/chân trang có trên mọi trang.
import '@/themes/mac-dinh/theme.css';
import '@/themes/moc/theme.css';
import '@/themes/mochi-pet/theme.css';

// Mỗi lượt xem dựng theo Host của shop; cache theo shop làm ở giai đoạn 3 cùng hệ theme.
export const dynamic = 'force-dynamic';

export async function generateMetadata(): Promise<Metadata> {
  const r = await layKhung();
  if (!r.success || !r.data) return { title: 'Không tìm thấy cửa hàng' };
  const ten = r.data.cai_dat.ten_hien_thi;
  return {
    title: { default: ten, template: `%s | ${ten}` },
    description: r.data.cai_dat.gioi_thieu_ngan || undefined,
  };
}

// Phần dùng chung của MỌI trang khách, không theo theme: đầu trang, chân trang, màu/font.
export default async function ShopLayout({ children }: { children: React.ReactNode }) {
  const r = await layKhung();
  if (r.status === 404) notFound();
  if (!r.success || !r.data) {
    return (
      <main className="shop-notice">
        <h1>Cửa hàng tạm thời không mở được</h1>
        <p>{r.message || 'Vui lòng thử lại sau ít phút.'}</p>
      </main>
    );
  }
  const { cai_dat: cd, nhom_hang: nhom } = r.data;
  // Chi nhánh khách vào qua QR (proxy.ts nhớ vào cookie).
  const cn = (await cookies()).get('ecw_branch')?.value ?? '';
  const chiNhanh = /^[A-Za-z0-9_-]{1,30}$/.test(cn) ? cn : '';

  return (
    <div className={`sf theme-${r.data.giao_dien.theme} ${FONT_LOP}`} style={bienCss(r.data.giao_dien)}>
      <ShopHeader cd={cd} gd={r.data.giao_dien.dau_trang} nhom={nhom} chiNhanh={chiNhanh} />
      <main className="sf-main">{children}</main>
      <ShopFooter cd={cd} gd={r.data.giao_dien.chan_trang} nhom={nhom} />
      {r.data.giao_dien.chan_trang.hien_nut_noi && <NutNoi cd={cd} />}
      <DemLuot />
    </div>
  );
}
