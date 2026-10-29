// Giỏ hàng lưu ở localStorage của khách; mỗi shop một tên miền nên giỏ tự tách theo shop.
// Giai đoạn 4 (thêm vào giỏ, thanh toán) dùng chung khoá và sự kiện này.
export const KHOA_GIO = 'ecw_gio';
export const SU_KIEN_GIO = 'ecw-gio';

type Dong = { so_luong: number };

export function soMonTrongGio(): number {
  try {
    const ds = JSON.parse(localStorage.getItem(KHOA_GIO) || '[]') as Dong[];
    return Array.isArray(ds) ? ds.reduce((t, d) => t + (Number(d.so_luong) || 0), 0) : 0;
  } catch {
    return 0;
  }
}
