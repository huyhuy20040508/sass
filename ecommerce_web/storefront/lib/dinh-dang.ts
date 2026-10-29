/** Tiền ghi đủ số, không rút gọn: 50.000.000 ₫. */
export const tien = (n: number) => `${Math.round(n).toLocaleString('vi-VN')} ₫`;

/** "2026-10-28 11:42:09" (giờ máy chủ) → "11:42 28/10/2026". */
export function ngayGio(s?: string) {
  if (!s) return '';
  const d = new Date(s.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return s;
  const p = (x: number) => String(x).padStart(2, '0');
  return `${p(d.getHours())}:${p(d.getMinutes())} ${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()}`;
}
