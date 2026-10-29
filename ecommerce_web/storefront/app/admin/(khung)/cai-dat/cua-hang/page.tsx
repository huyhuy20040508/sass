'use client';

import { useEffect, useRef, useState } from 'react';
import { adminApi } from '@/lib/adminAuth';
import XemTruocWeb, { type VungXem } from '@/components/admin/XemTruocWeb';
import { ApiError } from '@/lib/api';

type CaiDat = {
  ten_hien_thi: string; logo: string; logo_url: string; gioi_thieu_ngan: string;
  hotline: string; zalo: string; email: string; dia_chi: string; gio_mo_cua: string;
  facebook: string; instagram: string; tiktok: string; youtube: string;
};
type KhoaChu = Exclude<keyof CaiDat, 'logo_url'>;

// Ô đang sửa nằm ở vùng nào của trang web — bản xem trước cuộn tới và nháy vùng đó.
const VUNG: Record<KhoaChu, VungXem> = {
  ten_hien_thi: 'logo', logo: 'logo', gioi_thieu_ngan: 'gt',
  hotline: 'hotline', zalo: 'lh', email: 'lh', dia_chi: 'lh', gio_mo_cua: 'lh',
  facebook: 'mxh', instagram: 'mxh', tiktok: 'mxh', youtube: 'mxh',
};

export default function ThongTinCuaHang() {
  const [goc, setGoc] = useState<CaiDat | null>(null);
  const [cd, setCd] = useState<CaiDat | null>(null);
  const [loiO, setLoiO] = useState<Record<string, string>>({});
  const [thongBao, setThongBao] = useState<{ loai: 'ok' | 'loi'; chu: string } | null>(null);
  const [dangLuu, setDangLuu] = useState(false);
  const [dangTai, setDangTai] = useState(false);
  const [vung, setVung] = useState<VungXem | null>(null);
  const chonTep = useRef<HTMLInputElement>(null);

  useEffect(() => {
    adminApi<CaiDat>('/admin/cai-dat/cua-hang')
      .then(({ data }) => {
        setGoc(data);
        setCd(data);
      })
      .catch((e: Error) => setThongBao({ loai: 'loi', chu: e.message }));
  }, []);

  if (!cd || !goc) {
    return thongBao ? <p className="form-alert">{thongBao.chu}</p> : <section className="card pad muted">Đang tải…</section>;
  }

  const doi = <K extends keyof CaiDat>(k: K, v: CaiDat[K]) => {
    setCd({ ...cd, [k]: v });
    setLoiO(({ [k]: _, ...con }) => con);
    setThongBao(null);
  };
  const daDoi = JSON.stringify(cd) !== JSON.stringify(goc);

  async function taiLogo(tep: File) {
    setDangTai(true);
    setThongBao(null);
    try {
      const fd = new FormData();
      fd.append('anh', tep);
      const { data } = await adminApi<{ ten: string; url: string }>('/admin/anh', { method: 'POST', body: fd });
      setCd((c) => c && { ...c, logo: data.ten, logo_url: data.url });
      setLoiO(({ logo: _, ...con }) => con);
    } catch (e) {
      setLoiO((l) => ({ ...l, logo: (e as Error).message }));
    } finally {
      setDangTai(false);
      if (chonTep.current) chonTep.current.value = '';
    }
  }

  async function luu() {
    setDangLuu(true);
    setThongBao(null);
    try {
      const { data, message } = await adminApi<CaiDat>('/admin/cai-dat/cua-hang', { method: 'PUT', body: cd });
      setGoc(data);
      setCd(data);
      setLoiO({});
      setThongBao({ loai: 'ok', chu: message });
    } catch (e) {
      setLoiO(e instanceof ApiError ? e.errors : {});
      setThongBao({ loai: 'loi', chu: (e as Error).message });
    } finally {
      setDangLuu(false);
    }
  }

  const o = (k: KhoaChu, nhan: string, thuoc: { goiY?: string; kieu?: string; max?: number; rong?: boolean } = {}) => (
    <label className={`f${thuoc.rong ? ' rong' : ''}${loiO[k] ? ' sai' : ''}`} data-o={k}>
      <span>{nhan}</span>
      <input
        type={thuoc.kieu || 'text'}
        value={cd[k]}
        placeholder={thuoc.goiY}
        maxLength={thuoc.max}
        onChange={(e) => doi(k, e.target.value)}
      />
      {loiO[k] && <small>{loiO[k]}</small>}
    </label>
  );

  return (
    <div className="ttch">
      <div
        className="ttch-form"
        onFocus={(e) => setVung(VUNG[(e.target as HTMLElement).closest<HTMLElement>('[data-o]')?.dataset.o as KhoaChu] ?? null)}
        onBlur={() => setVung(null)}
      >
        <section className="card">
          <div className="card-h"><h2>Nhận diện</h2></div>
          <div className="pad form-grid">
            {o('ten_hien_thi', 'Tên hiển thị trên web', { goiY: 'Để trống = dùng tên cửa hàng ở POS', max: 80 })}
            <div className={`f${loiO.logo ? ' sai' : ''}`} data-o="logo">
              <span>Logo</span>
              <div className="logo-o">
                <div className="logo-xem">{cd.logo_url ? <img src={cd.logo_url} alt="Logo" /> : <i className="fa-regular fa-image" />}</div>
                <button className="btn" type="button" onClick={() => chonTep.current?.click()} disabled={dangTai}>
                  <i className="fa-solid fa-upload" />
                  {dangTai ? 'Đang tải…' : cd.logo ? 'Đổi logo' : 'Tải logo'}
                </button>
                {cd.logo && (
                  <button className="btn ghost-danger" type="button" onClick={() => setCd({ ...cd, logo: '', logo_url: '' })}>
                    Bỏ logo
                  </button>
                )}
                <input
                  ref={chonTep}
                  type="file"
                  accept="image/png,image/jpeg,image/webp,image/gif"
                  hidden
                  onChange={(e) => e.target.files?.[0] && taiLogo(e.target.files[0])}
                />
              </div>
              {loiO.logo ? <small>{loiO.logo}</small> : <em>PNG nền trong suốt đẹp nhất, tối đa 2 MB. Không có logo thì hiện tên.</em>}
            </div>
            <label className={`f rong${loiO.gioi_thieu_ngan ? ' sai' : ''}`} data-o="gioi_thieu_ngan">
              <span>Giới thiệu ngắn</span>
              <textarea rows={3} maxLength={300} value={cd.gioi_thieu_ngan} placeholder="Một hai câu về cửa hàng — hiện ở chân trang và kết quả Google" onChange={(e) => doi('gioi_thieu_ngan', e.target.value)} />
              {loiO.gioi_thieu_ngan && <small>{loiO.gioi_thieu_ngan}</small>}
            </label>
          </div>
        </section>

        <section className="card">
          <div className="card-h"><h2>Liên hệ</h2></div>
          <div className="pad form-grid">
            {o('hotline', 'Hotline', { goiY: '0901234567', kieu: 'tel', max: 20 })}
            {o('zalo', 'Số Zalo', { goiY: 'Để trống nếu không dùng Zalo', kieu: 'tel', max: 20 })}
            {o('email', 'Email', { kieu: 'email', max: 150 })}
            {o('gio_mo_cua', 'Giờ mở cửa', { goiY: '8:00 – 21:00 hằng ngày', max: 80 })}
            {o('dia_chi', 'Địa chỉ', { max: 255, rong: true })}
          </div>
        </section>

        <section className="card">
          <div className="card-h"><h2>Mạng xã hội</h2></div>
          <div className="pad form-grid">
            {o('facebook', 'Facebook', { goiY: 'https://facebook.com/…', kieu: 'url' })}
            {o('instagram', 'Instagram', { goiY: 'https://instagram.com/…', kieu: 'url' })}
            {o('tiktok', 'TikTok', { goiY: 'https://tiktok.com/@…', kieu: 'url' })}
            {o('youtube', 'YouTube', { goiY: 'https://youtube.com/@…', kieu: 'url' })}
          </div>
        </section>

      </div>

      <aside className="ttch-xem">
        <section className="card">
          <div className="card-h">
            <h2>Xem trước</h2>
            <div className="sp" />
            <a className="btn" href="/" target="_blank" rel="noopener">
              <i className="fa-solid fa-arrow-up-right-from-square" />
              Xem web
            </a>
          </div>
          <XemTruocWeb cd={cd} vung={vung} />
        </section>
        <div className="ttch-luu">
          {thongBao && <p className={thongBao.loai === 'ok' ? 'form-ok' : 'form-alert'}>{thongBao.chu}</p>}
          <button className="btn" type="button" disabled={!daDoi || dangLuu} onClick={() => { setCd(goc); setLoiO({}); setThongBao(null); }}>
            Bỏ thay đổi
          </button>
          <button className="btn pri" type="button" disabled={!daDoi || dangLuu} onClick={luu}>
            <i className="fa-solid fa-floppy-disk" />
            {dangLuu ? 'Đang lưu…' : 'Lưu'}
          </button>
        </div>
      </aside>
    </div>
  );
}
