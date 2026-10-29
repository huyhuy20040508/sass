import type { ComponentType } from 'react';
import TrangChuMacDinh from './mac-dinh/TrangChu';
import TrangChuMochiPet from './mochi-pet/TrangChu';
import TrangChuMoc from './moc/TrangChu';
import type { Khoi, NguCanh } from './kieu';

// Kho giao diện: mã theme (thư mục themes/<mã>/theme.json) → trang chủ của theme đó.
// Thêm theme = thêm thư mục + một dòng ở đây; PHP tự thấy theme qua theme.json.
const TRANG_CHU: Record<string, ComponentType<{ khoi: Khoi[]; nc: NguCanh }>> = {
  'mac-dinh': TrangChuMacDinh,
  moc: TrangChuMoc,
  'mochi-pet': TrangChuMochiPet,
};

export const trangChuCua = (theme: string) => TRANG_CHU[theme] ?? TrangChuMacDinh;
