import 'server-only';
import { cache } from 'react';
import { headers } from 'next/headers';
import { chuTrenNen } from './mau';
import { shopApi } from './shop-api';

export type NhomHang = { id: number; ten: string; slug: string; anh?: string; con: NhomHang[] };

export type CaiDat = {
  ten_hien_thi: string;
  logo: string;
  gioi_thieu_ngan: string;
  hotline: string;
  zalo: string;
  email: string;
  dia_chi: string;
  gio_mo_cua: string;
  facebook: string;
  instagram: string;
  tiktok: string;
  youtube: string;
};

export type GiaoDien = {
  theme: string;
  chung: { mau_chinh: string; font: string };
  // dai_tren*: chỉ theme có khai (vd Mochi Pet) mới có.
  dau_trang: { hien_menu: boolean; hien_hotline: boolean; dai_tren?: string; dai_tren_lh?: boolean };
  chan_trang: { hien_danh_muc: boolean; ban_quyen: string; hien_nut_noi: boolean };
};

export type Khung = { shop: { code: string; name: string }; cai_dat: CaiDat; giao_dien: GiaoDien; nhom_hang: NhomHang[] };

/**
 * Host của shop + mã xem bản nháp (proxy.ts chép từ ?xem_truoc= của khung xem trước
 * ở màn Chỉnh sửa giao diện). Mã sai/hết hạn PHP tự trả bản đang chạy.
 */
export async function nguCanh() {
  const h = await headers();
  const xt = h.get('x-ecw-xem-truoc') || '';
  const thu = h.get('x-ecw-thu-mau') || '';
  const them = xt ? `xem_truoc=${encodeURIComponent(xt)}${thu ? `&thu_mau=${encodeURIComponent(thu)}` : ''}` : '';
  return { host: h.get('host') || '', xemTruoc: xt, them };
}

// Layout, generateMetadata và trang cùng cần — cache() để một lượt dựng chỉ gọi PHP một lần.
export const layKhung = cache(async () => {
  const { host, them } = await nguCanh();
  return shopApi<Khung>(`/khung${them ? `?${them}` : ''}`, host);
});

/** Biến CSS cho phần dùng chung: mọi trang khách chỉ đọc màu/font qua các biến này. */
export function bienCss(gd: GiaoDien): Record<string, string> {
  return {
    '--sf-chinh': gd.chung.mau_chinh,
    '--sf-tren-chinh': chuTrenNen(gd.chung.mau_chinh),
    '--sf-font': `var(--f-${gd.chung.font}), system-ui, sans-serif`,
  };
}

export const lienKetZalo = (so: string) => `https://zalo.me/${so.replace(/^\+?84/, '0')}`;
