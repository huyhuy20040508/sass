'use client';

import { useEffect } from 'react';
import { usePathname } from 'next/navigation';

const KHOA = 'ecw_khach';

// Báo một lượt xem mỗi lần đổi trang (thẻ Tổng quan ở admin). Mã khách là số ngẫu nhiên lưu
// ở trình duyệt để mỗi khách chỉ tính một lần/ngày; khung xem trước của admin không đếm.
export default function DemLuot() {
  const duong = usePathname();
  useEffect(() => {
    // Trong iframe = khung xem trước / laptop mô phỏng của admin: không phải khách vào web.
    if (window.self !== window.top || new URLSearchParams(location.search).has('xem_truoc')) return;
    let k = '';
    try {
      k = localStorage.getItem(KHOA) || '';
      if (!/^[a-f0-9]{16}$/.test(k)) {
        k = Array.from(crypto.getRandomValues(new Uint8Array(8)), (b) => b.toString(16).padStart(2, '0')).join('');
        localStorage.setItem(KHOA, k);
      }
    } catch {
      return; // trình duyệt chặn lưu: không đếm còn hơn đếm mỗi lượt thành một khách mới
    }
    fetch('/api/dem', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ k }), keepalive: true }).catch(() => {});
  }, [duong]);
  return null;
}
