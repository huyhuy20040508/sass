import { notFound } from 'next/navigation';

// Đường dẫn không khớp trang nào của shop: rơi vào đây để 404 vẫn nằm trong layout của
// shop (có đầu/chân trang). Không có trang này thì Next dùng app/not-found.tsx — trang
// dành cho tên miền không thuộc shop nào.
export default function KhongKhop() {
  notFound();
}
