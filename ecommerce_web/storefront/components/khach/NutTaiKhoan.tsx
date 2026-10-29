'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { khachApi, layToken, xoaToken, type Khach } from '@/lib/khachAuth';

/**
 * Nút tài khoản trên đầu trang: chưa đăng nhập thì mời đăng nhập, đã đăng nhập
 * thì bày tên và cho thoát ra.
 *
 * Hỏi lại máy chủ mỗi lần mở trang thay vì tin localStorage: phiên có thể đã hết
 * hạn hoặc bị xoá, mà vẫn bày tên khách thì bấm vào đâu cũng báo lỗi.
 */
export default function NutTaiKhoan() {
  const [khach, setKhach] = useState<Khach | null>(null);
  const [xong, setXong] = useState(false);

  useEffect(() => {
    if (!layToken()) {
      setXong(true);

      return;
    }
    khachApi<{ khach: Khach }>('/khach/toi')
      .then(({ data }) => setKhach(data.khach))
      .catch(() => setKhach(null))
      .finally(() => setXong(true));
  }, []);

  // Chưa biết đang đăng nhập hay chưa thì không bày gì: hiện "Đăng nhập" rồi đổi
  // thành tên khách ngay sau đó là một cú nhấp nháy khó chịu ở mọi lần mở trang.
  if (!xong) return <span className="sf-tk sf-tk-cho" aria-hidden="true" />;

  if (!khach) {
    return (
      <Link className="sf-tk" href="/dang-nhap">
        <i className="fa-regular fa-user" />
        <span>Đăng nhập</span>
      </Link>
    );
  }

  async function thoat() {
    try {
      await khachApi('/khach/dang-xuat', { method: 'POST' });
    } catch {
      // Phiên đã chết bên máy chủ: vẫn xoá token ở máy này cho đúng ý người bấm.
    }
    xoaToken();
    window.location.href = '/';
  }

  return (
    <span className="sf-tk sf-tk-vao">
      <i className="fa-regular fa-user" />
      <span>
        <small>Xin chào</small>
        <b>{khach.full_name}</b>
      </span>
      <button type="button" onClick={thoat} aria-label="Đăng xuất">
        <i className="fa-solid fa-right-from-bracket" />
      </button>
    </span>
  );
}
