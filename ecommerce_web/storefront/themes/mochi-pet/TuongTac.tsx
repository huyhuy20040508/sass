'use client';

import { useEffect, useState } from 'react';
import type { O } from '../kieu';

/** Ảnh lớn banner chính: các slide mờ dần sang nhau mỗi 5 giây; chữ tô vàng là `to_mau` trong tiêu đề. */
export function SlideAnh({ ds }: { ds: O[] }) {
  const [i, setI] = useState(0);
  const [dung, setDung] = useState(false);
  useEffect(() => {
    if (ds.length < 2 || dung || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const id = window.setInterval(() => setI((x) => (x + 1) % ds.length), 5000);
    return () => window.clearInterval(id);
  }, [ds.length, dung]);

  return (
    <div className="mpk-slide-hop" onMouseEnter={() => setDung(true)} onMouseLeave={() => setDung(false)}>
      {ds.map((s, j) => {
        const vi = s.to_mau ? String(s.tieu_de).indexOf(s.to_mau) : -1;
        return (
          <div key={j} className={`mpk-slide${j === i ? ' on' : ''}`} aria-hidden={j !== i}>
            {s.anh && <img src={s.anh} alt="" />}
            <div className="chu">
              {s.nhan && <span className="mpk-nhan">{s.nhan}</span>}
              {s.tieu_de && (
                <h1>{vi < 0 ? s.tieu_de : <>{s.tieu_de.slice(0, vi)}<em>{s.to_mau}</em>{s.tieu_de.slice(vi + s.to_mau.length)}</>}</h1>
              )}
              {s.mo_ta && <p>{s.mo_ta}</p>}
              {s.nut_chu && s.nut_link && (
                <a className="mpk-nut mpk-nut-bo" href={s.nut_link} tabIndex={j === i ? 0 : -1}>{s.nut_chu} <i className="fa-solid fa-arrow-right" /></a>
              )}
            </div>
          </div>
        );
      })}
      {ds.length > 1 && (
        <div className="mpk-cham">
          {ds.map((_, j) => <button key={j} type="button" aria-label={`Ảnh ${j + 1}`} className={j === i ? 'on' : ''} onClick={() => setI(j)} />)}
        </div>
      )}
    </div>
  );
}
