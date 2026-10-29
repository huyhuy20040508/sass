export type TrangThaiPos = {
  ket_noi: boolean;
  key_prefix?: string;
  ket_noi_boi?: string;
  ket_noi_luc?: string;
  tinh_trang?: 'tot' | 'khoa_bi_thu_hoi' | 'cua_hang_khoa' | 'pos_khong_tra_loi' | 'loi';
  thong_bao?: string;
  so_nhom?: number;
  so_hang?: number;
};

export type HangPos = {
  id: number;
  ten: string;
  sku: string;
  anh: string;
  nhom: string;
  gia_thap: number;
  gia_cao: number;
  so_bien_the: number;
  ton: number;
  dang_ban: boolean;
};

export const ketNoiTot = (t: TrangThaiPos | null) => !!t?.ket_noi && t.tinh_trang === 'tot';
