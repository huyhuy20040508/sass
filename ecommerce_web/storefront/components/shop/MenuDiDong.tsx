'use client';

import { useEffect, useState } from 'react';
import type { CaiDat, NhomHang } from '@/lib/khung';

// Điện thoại: thanh danh mục gập vào nút ☰, mở thành ngăn kéo bên trái.
export default function MenuDiDong({ cd, nhom }: { cd: CaiDat; nhom: NhomHang[] }) {
  const [mo, setMo] = useState(false);

  useEffect(() => {
    document.body.style.overflow = mo ? 'hidden' : '';
    const dong = (e: KeyboardEvent) => e.key === 'Escape' && setMo(false);
    window.addEventListener('keydown', dong);
    return () => window.removeEventListener('keydown', dong);
  }, [mo]);

  const muc = (n: NhomHang, cap: number) =>
    n.con.length > 0 ? (
      <details key={n.id} className={`cap${cap}`}>
        <summary>
          <a href={`/danh-muc/${encodeURIComponent(n.slug)}`}>{n.ten}</a>
          <i className="fa-solid fa-chevron-down" />
        </summary>
        {n.con.map((c) => muc(c, cap + 1))}
      </details>
    ) : (
      <a key={n.id} className={`cap${cap}`} href={`/danh-muc/${encodeURIComponent(n.slug)}`}>{n.ten}</a>
    );

  return (
    <>
      <button className="sf-burger" type="button" aria-label="Mở menu" aria-expanded={mo} onClick={() => setMo(true)}>
        <i className="fa-solid fa-bars" />
      </button>
      <div className={`sf-drawer${mo ? ' mo' : ''}`} aria-hidden={!mo}>
        <div className="sf-drawer__nen" onClick={() => setMo(false)} />
        <aside>
          <div className="sf-drawer__dau">
            <b>{cd.ten_hien_thi}</b>
            <button type="button" aria-label="Đóng menu" onClick={() => setMo(false)}>
              <i className="fa-solid fa-xmark" />
            </button>
          </div>
          <nav>
            <a className="cap1" href="/">Trang chủ</a>
            {nhom.map((n) => muc(n, 1))}
          </nav>
          {cd.hotline && (
            <a className="sf-drawer__goi" href={`tel:${cd.hotline}`}>
              <i className="fa-solid fa-phone" /> {cd.hotline}
            </a>
          )}
        </aside>
      </div>
    </>
  );
}
