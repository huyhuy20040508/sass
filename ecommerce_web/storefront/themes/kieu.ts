import type { CaiDat, NhomHang } from '@/lib/khung';

export type O = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
export type Khoi = { id: string; khoi: string; o: O };
export type Hang = { id: number; ten: string; slug: string; anh: string; gia: number; gia_cu: number; nhieu_gia: boolean; het_hang: boolean };
export type NguCanh = { host: string; xemTruoc: boolean; nhom: NhomHang[]; cd: CaiDat };
