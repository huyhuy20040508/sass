// Chữ trắng hay đen trên nền màu chính — shop chọn màu vàng nhạt thì chữ trắng sẽ không đọc được.
// Ngưỡng 0.179: điểm mà độ tương phản với trắng và với đen bằng nhau (WCAG).
export function chuTrenNen(hex: string): string {
  const [r, g, b] = [1, 3, 5]
    .map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.179 ? '#111827' : '#ffffff';
}
