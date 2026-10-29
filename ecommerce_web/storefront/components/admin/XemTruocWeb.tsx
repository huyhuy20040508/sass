'use client';

import { useEffect, useRef, useState } from 'react';
import { adminApi } from '@/lib/adminAuth';
import { api } from '@/lib/api';
import { tien } from '@/lib/dinh-dang';
import { FONT_LOP } from '@/lib/fonts';
import type { HangPos } from '@/lib/pos';

export type VungXem = 'logo' | 'hotline' | 'gt' | 'lh' | 'mxh';

type CaiDatXem = {
  ten_hien_thi: string; logo_url: string; gioi_thieu_ngan: string;
  hotline: string; zalo: string; email: string; dia_chi: string; gio_mo_cua: string;
  facebook: string; instagram: string; tiktok: string; youtube: string;
};

const MXH = [
  { khoa: 'facebook', icon: 'fa-facebook-f' },
  { khoa: 'instagram', icon: 'fa-instagram' },
  { khoa: 'tiktok', icon: 'fa-tiktok' },
  { khoa: 'youtube', icon: 'fa-youtube' },
] as const;

const BANNER = ['Hàng mới về', 'Ưu đãi cuối tuần', 'Giao nhanh toàn quốc'];

/**
 * Trang web thu nhỏ của shop: nhóm hàng và vài món thật đọc từ POS, đầu/chân trang
 * theo các ô đang sửa (chưa cần lưu). Đang sửa ô nào thì cuộn tới và nháy đúng vùng
 * đó; không sửa gì thì tự cuộn chậm lên xuống cho thấy cả trang.
 */
export default function XemTruocWeb({ cd, vung }: { cd: CaiDatXem; vung: VungXem | null }) {
  const [che, setChe] = useState<'pc' | 'mb'>('pc');
  const [host, setHost] = useState('');
  const [nhom, setNhom] = useState<string[]>([]);
  const [hang, setHang] = useState<HangPos[]>([]);
  const [reTay, setReTay] = useState(false);
  const cuon = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setHost(window.location.host);
    api<{ nhom_hang: { ten: string }[] }>('/khung').then(({ data }) => setNhom(data.nhom_hang.map((n) => n.ten))).catch(() => {});
    // Chưa kết nối POS thì 409 — để ô hàng mẫu.
    adminApi<HangPos[]>('/admin/pos/hang?page=1').then(({ data }) => setHang(data.filter((h) => h.dang_ban).slice(0, 4))).catch(() => {});
  }, []);

  useEffect(() => {
    const el = cuon.current;
    if (!el) return;
    if (vung) {
      const dich = el.querySelector<HTMLElement>(`[data-vung="${vung}"]`);
      if (dich) {
        const lech = dich.getBoundingClientRect().top - el.getBoundingClientRect().top + el.scrollTop;
        el.scrollTo({ top: Math.max(0, lech - 48), behavior: 'smooth' });
      }
      return;
    }
    if (reTay || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    let xuong = el.scrollTop < 10;
    const id = window.setInterval(() => {
      el.scrollTo({ top: xuong ? el.scrollHeight : 0, behavior: 'smooth' });
      xuong = !xuong;
    }, 4500);
    return () => window.clearInterval(id);
  }, [vung, reTay, che]);

  // Ô đang sửa mà còn trống: vẫn hiện mờ chỗ nó sẽ nằm.
  const hien = (gt: string, v: VungXem) => !!gt || vung === v;
  const lop = (v: VungXem, gt = 'x') => `${vung === v ? 'xw-on' : ''}${gt ? '' : ' xw-ma'}`;
  const ten = cd.ten_hien_thi || 'Tên cửa hàng';
  const logo = cd.logo_url ? <img src={cd.logo_url} alt="" /> : <span>{ten}</span>;
  const mxh = MXH.filter((m) => cd[m.khoa]);
  const pc = che === 'pc';

  return (
    <div className="xw">
      <div className="xw-tabs" role="tablist">
        <button type="button" className={pc ? 'on' : ''} onClick={() => setChe('pc')}>
          <i className="fa-solid fa-desktop" /> Máy tính
        </button>
        <button type="button" className={pc ? '' : 'on'} onClick={() => setChe('mb')}>
          <i className="fa-solid fa-mobile-screen" /> Điện thoại
        </button>
      </div>

      <div key={che} className={`xw-may xw-${che}`}>
        {pc ? (
          <div className="xw-chrome">
            <span className="xw-dots"><i /><i /><i /></span>
            <span className="xw-url"><i className="fa-solid fa-lock" />{host}</span>
          </div>
        ) : (
          <div className="xw-tai"><span>9:41</span><i /><span><i className="fa-solid fa-signal" /> <i className="fa-solid fa-battery-three-quarters" /></span></div>
        )}

        <div
          className={`xw-cuon ${FONT_LOP}`}
          ref={cuon}
          onMouseEnter={() => setReTay(true)}
          onMouseLeave={() => setReTay(false)}
          onTouchStart={() => setReTay(true)}
        >
          <div className="xw-head">
            {!pc && <i className="fa-solid fa-bars xw-burger" />}
            <span className={`xw-logo ${lop('logo')}`} data-vung="logo">{logo}</span>
            {pc && <span className="xw-tim">Tìm sản phẩm…<i className="fa-solid fa-magnifying-glass" /></span>}
            {pc && hien(cd.hotline, 'hotline') && (
              <span className={`xw-hot ${lop('hotline', cd.hotline)}`} data-vung="hotline">
                <i className="fa-solid fa-phone-volume" />
                <span><small>Hotline</small><b>{cd.hotline || '0900 000 000'}</b></span>
              </span>
            )}
            <span className="xw-gio"><i className="fa-solid fa-bag-shopping" /><em>2</em></span>
          </div>
          {!pc && <div className="xw-tim xw-tim-mb">Tìm sản phẩm…<i className="fa-solid fa-magnifying-glass" /></div>}
          {pc && (
            <nav className="xw-nav">
              <span className="on">Trang chủ</span>
              {(nhom.length ? nhom : ['Nhóm hàng', 'Nhóm hàng', 'Nhóm hàng']).slice(0, 4).map((n, i) => <span key={i}>{n}</span>)}
            </nav>
          )}

          <div className="xw-banner">
            <div className="xw-slides">
              {[...BANNER, BANNER[0]].map((b, i) => (
                <div key={i} className={`xw-slide s${i % 3}`}>
                  <small>{ten}</small>
                  <b>{b}</b>
                  <span>Mua ngay</span>
                </div>
              ))}
            </div>
            <div className="xw-cham"><i /><i /><i /></div>
          </div>

          <div className="xw-muc"><b>Sản phẩm nổi bật</b><span>Xem tất cả ›</span></div>
          <div className="xw-luoi">
            {(hang.length ? hang : Array.from({ length: 4 }, () => null)).map((h, i) => (
              <div key={h?.id ?? `mau${i}`} className="xw-sp" style={{ animationDelay: `${i * 0.12}s` }}>
                <div className={`xw-anh${h ? '' : ' trong'}`}>
                  {h?.anh ? <img src={h.anh} alt="" /> : <i className="fa-regular fa-image" />}
                </div>
                {h ? <p>{h.ten}</p> : <p className="xw-sk" />}
                {h ? <b>{tien(h.gia_thap)}</b> : <b className="xw-sk ngan" />}
              </div>
            ))}
          </div>

          <footer className="xw-foot">
            <div className={lop('gt')} data-vung="gt">
              <span className="xw-foot-ten">{logo}</span>
              {hien(cd.gioi_thieu_ngan, 'gt') && <p className={cd.gioi_thieu_ngan ? '' : 'xw-ma'}>{cd.gioi_thieu_ngan || 'Giới thiệu ngắn về cửa hàng'}</p>}
              {(mxh.length > 0 || vung === 'mxh') && (
                <div className={`xw-mxh ${lop('mxh', mxh.length ? 'x' : '')}`} data-vung="mxh">
                  {(mxh.length ? mxh : MXH).map((m) => <i key={m.khoa} className={`fa-brands ${m.icon}`} />)}
                </div>
              )}
            </div>
            {(cd.dia_chi || cd.hotline || cd.zalo || cd.email || cd.gio_mo_cua || vung === 'lh') && (
              <div className={lop('lh')} data-vung="lh">
                <h4>Liên hệ</h4>
                {hien(cd.dia_chi, 'lh') && <p className={cd.dia_chi ? '' : 'xw-ma'}><i className="fa-solid fa-location-dot" /><span>{cd.dia_chi || 'Địa chỉ cửa hàng'}</span></p>}
                {cd.hotline && <p><i className="fa-solid fa-phone" /><span>{cd.hotline}</span></p>}
                {cd.zalo && <p><i className="fa-solid fa-comment-dots" /><span>Zalo {cd.zalo}</span></p>}
                {cd.email && <p><i className="fa-solid fa-envelope" /><span>{cd.email}</span></p>}
                {cd.gio_mo_cua && <p><i className="fa-solid fa-clock" /><span>{cd.gio_mo_cua}</span></p>}
              </div>
            )}
            <div className="xw-ban-quyen">© {new Date().getFullYear()} {ten}</div>
          </footer>
        </div>
      </div>
    </div>
  );
}
