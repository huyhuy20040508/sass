'use client';

import { useEffect, useState } from 'react';
import { soMonTrongGio, SU_KIEN_GIO } from '@/lib/gio-hang';

// Giỏ nằm ở trình duyệt khách (giai đoạn 4); đầu trang chỉ cần biết có bao nhiêu món.
export default function GioHangNut() {
  const [so, setSo] = useState(0);

  useEffect(() => {
    const cap = () => setSo(soMonTrongGio());
    cap();
    window.addEventListener(SU_KIEN_GIO, cap);
    // Thêm hàng ở tab khác thì tab này cũng đổi số.
    window.addEventListener('storage', cap);
    return () => {
      window.removeEventListener(SU_KIEN_GIO, cap);
      window.removeEventListener('storage', cap);
    };
  }, []);

  return (
    <a className="sf-cart" href="/gio-hang" aria-label={`Giỏ hàng, ${so} món`}>
      <i className="fa-solid fa-bag-shopping" />
      {so > 0 && <span>{so > 99 ? '99+' : so}</span>}
    </a>
  );
}
