'use client';

import { useEffect, useState } from 'react';
import type { O } from '../kieu';

/** Chữ banner chính tự đổi mỗi 6 giây; chữ tô màu là phần `nhan` nằm trong tiêu đề. */
export function SlideChu({ ds }: { ds: O[] }) {
  const [i, setI] = useState(0);
  const [hien, setHien] = useState(true);
  useEffect(() => {
    if (ds.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const id = window.setInterval(() => {
      setHien(false);
      window.setTimeout(() => { setI((x) => (x + 1) % ds.length); setHien(true); }, 250);
    }, 6000);
    return () => window.clearInterval(id);
  }, [ds.length]);
  const s = ds[Math.min(i, ds.length - 1)];
  if (!s) return null;
  const vi = s.nhan ? s.tieu_de.indexOf(s.nhan) : -1;
  return (
    <>
      <div className={`mc-slide${hien ? '' : ' an'}`}>
        {s.nho && <small>{s.nho}</small>}
        <h1>{vi < 0 ? s.tieu_de : <>{s.tieu_de.slice(0, vi)}<em>{s.nhan}</em>{s.tieu_de.slice(vi + s.nhan.length)}</>}</h1>
        {s.mo_ta && <p>{s.mo_ta}</p>}
      </div>
      {ds.length > 1 && (
        <div className="mc-cham">
          {ds.map((_, j) => <button key={j} type="button" aria-label={`Slide ${j + 1}`} className={j === i ? 'on' : ''} onClick={() => setI(j)} />)}
        </div>
      )}
    </>
  );
}

/** Tab Mới về / Bán chạy / Giảm giá: ba lưới dựng sẵn phía máy chủ, ở đây chỉ bật/tắt. */
export function TabHang({ tieuDe, tab }: { tieuDe: React.ReactNode; tab: { ma: string; ten: string; luoi: React.ReactNode }[] }) {
  const [dang, setDang] = useState(tab[0]?.ma);
  return (
    <>
      <div className="mc-dau-tab">
        {tieuDe}
        {tab.length > 1 && (
          <div className="mc-tab" role="tablist">
            {tab.map((t) => (
              <button key={t.ma} type="button" role="tab" aria-selected={dang === t.ma} className={dang === t.ma ? 'on' : ''} onClick={() => setDang(t.ma)}>{t.ten}</button>
            ))}
          </div>
        )}
      </div>
      {tab.map((t) => <div key={t.ma} hidden={dang !== t.ma}>{t.luoi}</div>)}
    </>
  );
}

/** Đếm ngược khuyến mãi; hết giờ thì tự ẩn (giờ theo máy khách, đủ cho một banner). */
export function DemNguoc({ toi }: { toi: string }) {
  const [con, setCon] = useState<number | null>(null);
  useEffect(() => {
    const het = new Date(toi).getTime();
    const tinh = () => setCon(Math.max(0, Math.floor((het - Date.now()) / 1000)));
    tinh();
    const id = window.setInterval(tinh, 1000);
    return () => window.clearInterval(id);
  }, [toi]);
  if (con === null || con <= 0) return null;
  const v = [Math.floor(con / 86400), Math.floor((con % 86400) / 3600), Math.floor((con % 3600) / 60), con % 60];
  return (
    <div className="mc-dem">
      {['ngày', 'giờ', 'phút', 'giây'].map((n, i) => <span key={n}><b>{String(v[i]).padStart(2, '0')}</b><small>{n}</small></span>)}
    </div>
  );
}
