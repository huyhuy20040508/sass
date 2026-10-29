import macDinh from '@/themes/mac-dinh/theme.json';
import mochiPet from '@/themes/mochi-pet/theme.json';
import moc from '@/themes/moc/theme.json';

// Khuôn theme đọc từ theme.json — cùng tệp PHP dùng để kiểm dữ liệu.
export type OKhai = {
  khoa: string;
  loai: 'chu' | 'doan' | 'link' | 'mau' | 'anh' | 'so' | 'chon' | 'bat_tat' | 'nhom_hang' | 'danh_sach' | 'ngay_gio';
  nhan: string;
  mac_dinh: unknown;
  max?: number;
  min?: number;
  goi_y?: string;
  lua_chon?: { gt: string; nhan: string }[];
  hien_khi?: Record<string, unknown>;
  toi_da?: number;
  ten_muc?: string;
  o?: OKhai[];
};
export type KhoiKhai = { ten: string; icon: string; mo_ta?: string; o: OKhai[] };
export type GiaTri = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
export type KhoiNhap = { id: string; khoi: string; an: boolean; o: GiaTri };
export type BanNhap = {
  theme: string;
  chung: { mau_chinh: string; font: string };
  dau_trang: GiaTri;
  chan_trang: GiaTri;
  trang_chu: KhoiNhap[];
};

export type Khuon = {
  ma: string;
  ten: string;
  nganh: string;
  mo_ta: string;
  mau: string[];
  font: { khoa: string; ten: string }[];
  chung: { mau_chinh: string; font: string };
  dau_trang: KhoiKhai;
  chan_trang: KhoiKhai;
  khoi: Record<string, KhoiKhai>;
};

// Kho giao diện — thêm theme mới thì thêm một dòng (và một dòng ở themes/registry.ts).
const KHO: Record<string, Khuon> = { 'mac-dinh': macDinh as unknown as Khuon, moc: moc as unknown as Khuon, 'mochi-pet': mochiPet as unknown as Khuon };

export const khuonCua = (ma: string): Khuon => KHO[ma] ?? KHO['mac-dinh'];

export const giaTriMacDinh = (khai: OKhai[]): GiaTri => Object.fromEntries(khai.map((o) => [o.khoa, structuredClone(o.mac_dinh)]));

export const maKhoiMoi = () => Math.random().toString(36).slice(2, 10).replace(/[^a-z0-9]/g, 'x').padEnd(6, 'x');
