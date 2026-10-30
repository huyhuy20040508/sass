import 'server-only';
import { cache } from 'react';
import { headers } from 'next/headers';
import { goiApi } from '@/lib/api';

export type CuaHang = {
  cua_hang: { ma: string; ten: string };
  chi_nhanh: { ma: string; ten: string; dien_thoai: string | null; email: string | null; dia_chi: string | null; anh: string | null };
};

export type KetQuaCuaHang = { mo: true; ch: CuaHang } | { mo: false; lyDo: string; thongBao: string };

// Một lượt tra cho cả layout lẫn trang trong cùng một request.
export const layCuaHang = cache(async (): Promise<KetQuaCuaHang> => {
  const host = (await headers()).get('host') || '';
  const r = await goiApi<CuaHang>('/cua-hang', host);
  if (r.success && r.data) return { mo: true, ch: r.data };
  return { mo: false, lyDo: r.errors?.ly_do || 'loi', thongBao: r.status ? r.message || 'Web tạm thời không mở được' : 'Web tạm thời không mở được, vui lòng thử lại sau ít phút' };
});
