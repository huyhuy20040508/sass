'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import QRCode from 'qrcode';
import { adminApi } from '@/lib/adminAuth';
import { ngayGio } from '@/lib/dinh-dang';
import { ketNoiTot, type TrangThaiPos } from '@/lib/pos';

type MauKho = { ma: string; ten: string; nganh: string; mo_ta: string; mau: string };
type GiaoDienMeta = {
  nhap: { theme: string };
  dang_chay: { theme: string; so_khoi: number; so_an: number };
  kho: MauKho[];
  chua_ap_dung: boolean;
  ap_dung_luc: string | null;
  ap_dung_boi: string | null;
  ma_xem_truoc: string;
};
type TruyCap = {
  ngay: { ngay: string; luot: number; khach: number }[];
  tong: { luot: number; khach: number };
  ky_truoc: { luot: number; khach: number };
  don: number | null;
};

/**
 * Website của tôi — theo trang cùng tên của KiotViet: giao diện đang chạy (trang thật thu
 * nhỏ trong laptop/điện thoại), số khách vào web, kho giao diện, lối tắt thiết lập.
 * Màn Chỉnh sửa giao diện mở từ đây (giao-dien/chinh-sua).
 */
export default function WebsiteCuaToi() {
  const router = useRouter();
  const [gd, setGd] = useState<GiaoDienMeta | null>(null);
  const [loi, setLoi] = useState('');
  const [host, setHost] = useState('');
  const [hop, setHop] = useState(false);
  const [bao, setBao] = useState('');
  const [dangDoi, setDangDoi] = useState('');
  const [pos, setPos] = useState<TrangThaiPos | null>(null);
  const [coThongTin, setCoThongTin] = useState<boolean | null>(null);

  useEffect(() => {
    setHost(window.location.host);
    adminApi<GiaoDienMeta>('/admin/giao-dien').then(({ data }) => setGd(data)).catch((e: Error) => setLoi(e.message));
    adminApi<TrangThaiPos>('/admin/pos').then(({ data }) => setPos(data)).catch(() => {});
    adminApi<{ ten_hien_thi: string; hotline: string; logo: string }>('/admin/cai-dat/cua-hang')
      .then(({ data }) => setCoThongTin(!!(data.hotline || data.logo || data.ten_hien_thi)))
      .catch(() => {});
  }, []);

  const baoNhanh = (chu: string) => {
    setBao(chu);
    window.setTimeout(() => setBao(''), 1800);
  };
  const chep = async () => {
    try {
      await navigator.clipboard.writeText(`https://${host}`);
      baoNhanh('Đã chép link website');
    } catch {
      baoNhanh('Trình duyệt không cho chép — bôi đen địa chỉ để chép tay');
    }
  };

  async function dungMau(ma: string) {
    setDangDoi(ma);
    try {
      await adminApi('/admin/giao-dien/doi-mau', { method: 'POST', body: { theme: ma, dung_mau: true } });
      router.push('/admin/giao-dien/chinh-sua');
    } catch (e) {
      setLoi((e as Error).message);
      setDangDoi('');
    }
  }

  if (!gd) return loi ? <p className="form-alert">{loi}</p> : <section className="card pad muted">Đang tải…</section>;

  const chay = gd.kho.find((m) => m.ma === gd.dang_chay.theme) ?? gd.kho[0];
  const nhapKhac = gd.nhap.theme !== gd.dang_chay.theme ? gd.kho.find((m) => m.ma === gd.nhap.theme) : undefined;

  return (
    <>
      {loi && <p className="form-alert">{loi}</p>}

      <section className="card wct-chinh">
        <MayMoPhong />
        <div className="dang-dung">
          <small>Giao diện đang sử dụng</small>
          <h3>
            {chay.ten}
            <span className="chip ok"><i className="fa-solid fa-circle" /> Đang chạy</span>
          </h3>
          <p className="nganh">{chay.nganh}</p>
          <div className="trang-thai">
            <div>
              <i className="fa-solid fa-paper-plane" />
              <span>{gd.ap_dung_luc ? <>Áp dụng lần cuối <b>{ngayGio(gd.ap_dung_luc)}</b>{gd.ap_dung_boi && ` · ${gd.ap_dung_boi}`}</> : 'Chưa áp dụng lần nào — web đang dùng giao diện mặc định'}</span>
            </div>
            <div>
              <i className="fa-solid fa-pen-ruler" />
              {gd.chua_ap_dung ? (
                <span>Bản nháp: <b className="mau-cho">có thay đổi chưa áp dụng{nhapKhac ? ` (đang thử mẫu ${nhapKhac.ten})` : ''}</b></span>
              ) : (
                <span>Bản nháp trùng bản đang chạy</span>
              )}
            </div>
            <div>
              <i className="fa-solid fa-layer-group" />
              <span>Trang chủ có <b>{gd.dang_chay.so_khoi} khối</b>{gd.dang_chay.so_an > 0 && ` · ${gd.dang_chay.so_an} khối đang ẩn`}</span>
            </div>
          </div>
          <div className="dong-nut">
            <Link className="btn pri lg" href="/admin/giao-dien/chinh-sua"><i className="fa-solid fa-wand-magic-sparkles" /> Chỉnh sửa giao diện</Link>
            <Link className="btn lg" href="/admin/giao-dien/chinh-sua?mo=kho"><i className="fa-solid fa-swatchbook" /> Đổi mẫu khác</Link>
          </div>
          <div className="dia-chi">
            <i className="fa-solid fa-globe" />
            <code>{host}</code>
            <button type="button" className="ibtn" title="Chép link" onClick={chep}><i className="fa-regular fa-copy" /></button>
            <button type="button" className="ibtn" title="Chia sẻ / mã QR" onClick={() => setHop(true)}><i className="fa-solid fa-qrcode" /></button>
          </div>
        </div>
      </section>

      <TongQuan />

      <section className="card" id="kho">
        <div className="card-h">
          <h2>Kho giao diện</h2>
          <span className="muted">Đổi mẫu vào bản nháp, xem thử rồi mới áp dụng</span>
        </div>
        <div className="kho-luoi">
          {gd.kho.map((m) => {
            const dang = m.ma === gd.dang_chay.theme;
            return (
              <div key={m.ma} className={`mau-the${dang ? ' dang' : ''}`}>
                <div className="mau-anh">
                  <img src={`/themes/${m.ma}.jpg`} alt={`Mẫu ${m.ten}`} loading="lazy" />
                  {dang && <span className="chip ok nhan-dang"><i className="fa-solid fa-check" /> Đang dùng</span>}
                  {!dang && m.ma === gd.nhap.theme && <span className="chip warn nhan-dang">Đang sửa trong nháp</span>}
                </div>
                <div className="mau-than">
                  <b>{m.ten}</b>
                  <small>{m.nganh}</small>
                  <span>{m.mo_ta}</span>
                </div>
                <div className="mau-chan">
                  {dang || m.ma === gd.nhap.theme ? (
                    <Link className="btn pri" href="/admin/giao-dien/chinh-sua"><i className="fa-solid fa-wand-magic-sparkles" /> Chỉnh sửa</Link>
                  ) : (
                    <>
                      <a className="btn" href={`/?xem_truoc=${encodeURIComponent(gd.ma_xem_truoc)}&thu_mau=${m.ma}`} target="_blank" rel="noopener">
                        <i className="fa-regular fa-eye" /> Xem thử
                      </a>
                      <button type="button" className="btn" disabled={!!dangDoi} onClick={() => dungMau(m.ma)}>
                        {dangDoi === m.ma ? 'Đang chuyển…' : 'Dùng mẫu này'}
                      </button>
                    </>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </section>

      <section className="card">
        <div className="card-h"><h2>Thiết lập website</h2></div>
        <div className="loi-tat">
          <Link href="/admin/cai-dat/cua-hang">
            <i className="fa-solid fa-store" />
            <div><b>Thông tin cửa hàng</b><small>Logo, liên hệ, mạng xã hội</small>
              {coThongTin !== null && <span className={`chip ${coThongTin ? 'ok' : 'warn'} tt`}>{coThongTin ? 'Đã có' : 'Nên làm'}</span>}
            </div>
          </Link>
          <Link href="/admin/cai-dat/ket-noi-pos">
            <i className="fa-solid fa-plug" />
            <div><b>Kết nối POS</b><small>Hàng, giá, tồn lấy từ POS</small>
              {pos && <span className={`chip ${ketNoiTot(pos) ? 'ok' : 'err'} tt`}>{ketNoiTot(pos) ? 'Tốt' : pos.ket_noi ? 'Có lỗi' : 'Chưa kết nối'}</span>}
            </div>
          </Link>
          <Link href="/admin/cai-dat/ten-mien">
            <i className="fa-solid fa-globe" />
            <div><b>Tên miền riêng</b><small>Gắn tên miền của bạn, vd tencuahang.vn</small><span className="chip mute tt">Chưa gắn</span></div>
          </Link>
          <Link href="/admin/cai-dat/seo">
            <i className="fa-solid fa-magnifying-glass-chart" />
            <div><b>SEO &amp; mã theo dõi</b><small>Hiện đẹp trên Google, Zalo, Facebook</small><span className="chip warn tt">Nên làm</span></div>
          </Link>
        </div>
      </section>

      {hop && <HopChiaSe host={host} dong={() => setHop(false)} chep={chep} />}
      <div className={`wct-bao${bao ? ' hien' : ''}`} role="status"><i className="fa-solid fa-check" /><span>{bao}</span></div>
    </>
  );
}

// Trang web THẬT của shop (bản đang chạy) thu nhỏ trong laptop / điện thoại, tự cuộn chậm
// lên xuống; rê chuột vào thì dừng. iframe cùng tên miền nên cuộn được từ đây.
const RONG = { pc: 1280, dt: 390 };

function MayMoPhong() {
  const [che, setChe] = useState<'pc' | 'dt'>('pc');
  const man = useRef<HTMLDivElement>(null);
  const khung = useRef<HTMLIFrameElement>(null);
  const [ti, setTi] = useState(0);
  const dung = useRef(false);

  useEffect(() => {
    const el = man.current;
    if (!el) return;
    const ro = new ResizeObserver(([e]) => setTi(e.contentRect.width / RONG[che]));
    ro.observe(el);
    return () => ro.disconnect();
  }, [che]);

  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    let huong = 1;
    let cho = 120; // khung hình đứng yên ở đầu / cuối trang trước khi đổi chiều
    let id = 0;
    const buoc = () => {
      const w = khung.current?.contentWindow;
      const doc = w?.document.documentElement;
      if (w && doc && !dung.current) {
        const max = doc.scrollHeight - w.innerHeight;
        if (cho > 0) cho--;
        else if (max > 0) {
          w.scrollTo(0, Math.min(max, Math.max(0, w.scrollY + huong * (che === 'pc' ? 2.2 : 1.6))));
          if (w.scrollY >= max - 1 || w.scrollY <= 0) { huong = -huong; cho = 120; }
        }
      }
      id = requestAnimationFrame(buoc);
    };
    id = requestAnimationFrame(buoc);
    return () => cancelAnimationFrame(id);
  }, [che]);

  const cao = che === 'pc' ? RONG.pc * 10 / 16 : RONG.dt * 19 / 9;
  return (
    <div className="may-vung" onMouseEnter={() => (dung.current = true)} onMouseLeave={() => (dung.current = false)}>
      <div className={che === 'pc' ? 'laptop' : 'dt'} key={che}>
        <div className="khung">
          <div className="man" ref={man}>
            {ti > 0 && (
              <iframe
                ref={khung}
                src="/"
                title="Trang chủ đang chạy"
                tabIndex={-1}
                style={{ width: RONG[che], height: cao, transform: `scale(${ti})` }}
              />
            )}
          </div>
        </div>
        {che === 'pc' && <div className="de" />}
      </div>
      <div className="chuyen-may">
        <button type="button" className={che === 'pc' ? 'on' : ''} onClick={() => setChe('pc')} aria-label="Xem trên máy tính"><i className="fa-solid fa-desktop" /></button>
        <button type="button" className={che === 'dt' ? 'on' : ''} onClick={() => setChe('dt')} aria-label="Xem trên điện thoại"><i className="fa-solid fa-mobile-screen" /></button>
      </div>
    </div>
  );
}

function TongQuan() {
  const [ky, setKy] = useState(7);
  const [tc, setTc] = useState<TruyCap | null>(null);
  const [loi, setLoi] = useState('');
  useEffect(() => {
    adminApi<TruyCap>(`/admin/truy-cap?ngay=${ky}`).then(({ data }) => { setTc(data); setLoi(''); }).catch((e: Error) => setLoi(e.message));
  }, [ky]);

  const the = tc && [
    { ten: 'Lượt xem trang', icon: 'fa-eye', lop: 'xanh', mau: '#1890ff', ds: tc.ngay.map((n) => n.luot), tong: tc.tong.luot, truoc: tc.ky_truoc.luot },
    { ten: 'Khách truy cập', icon: 'fa-user', lop: 'luc', mau: '#20a35a', ds: tc.ngay.map((n) => n.khach), tong: tc.tong.khach, truoc: tc.ky_truoc.khach },
  ];
  return (
    <section className="card">
      <div className="card-h">
        <h2>Tổng quan</h2>
        <span className="muted">Khách vào web của bạn</span>
        <div className="sp" />
        <div className="chon-ky">
          {[[7, '7 ngày'], [30, '30 ngày'], [90, '3 tháng']].map(([k, ten]) => (
            <button key={k} type="button" className={ky === k ? 'on' : ''} onClick={() => setKy(k as number)}>{ten}</button>
          ))}
        </div>
      </div>
      {loi && <p className="form-alert pad">{loi}</p>}
      <div className="tq-luoi">
        {the?.map((t) => (
          <div key={t.ten} className={`tq ${t.lop}`}>
            <div className="dau-tq"><i className={`fa-solid ${t.icon}`} />{t.ten}</div>
            <div className="so-tq"><b>{t.tong.toLocaleString('vi-VN')}</b><SoSanh nay={t.tong} truoc={t.truoc} /></div>
            <Duong ds={t.ds} mau={t.mau} />
          </div>
        ))}
        {tc && (
          <div className="tq cam">
            <div className="dau-tq"><i className="fa-solid fa-bag-shopping" />Đơn đặt từ web</div>
            <div className="so-tq"><b>{tc.don ?? 0}</b></div>
            <small className="muted">Có số khi mở đặt hàng online (giỏ hàng, thanh toán) — đơn về màn Bán online ở Thu ngân.</small>
          </div>
        )}
      </div>
      <div className="goi-y-tq">
        <i className="fa-solid fa-lightbulb" />
        <span>Mẹo: in mã QR website lên hoá đơn và dán ở quầy — khách quét là vào xem hàng, đặt online được ngay.</span>
      </div>
    </section>
  );
}

function SoSanh({ nay, truoc }: { nay: number; truoc: number }) {
  if (!truoc) return nay ? <span className="doi len">mới có khách</span> : null;
  const pt = Math.round(((nay - truoc) / truoc) * 100);
  return (
    <span className={`doi ${pt >= 0 ? 'len' : 'xuong'}`}>
      <i className={`fa-solid fa-arrow-${pt >= 0 ? 'up' : 'down'}`} /> {pt >= 0 ? '+' : ''}{pt}% so với kỳ trước
    </span>
  );
}

function Duong({ ds, mau }: { ds: number[]; mau: string }) {
  const w = 300, h = 44;
  const max = Math.max(1, ...ds);
  const d = ds.map((v, i) => `${i ? 'L' : 'M'}${((i * w) / Math.max(1, ds.length - 1)).toFixed(1)} ${(h - 4 - (v / max) * (h - 8)).toFixed(1)}`).join(' ');
  return (
    <svg viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none" aria-hidden="true">
      <path d={`${d} L${w} ${h} L0 ${h}Z`} fill={mau} opacity={0.12} />
      <path d={d} fill="none" stroke={mau} strokeWidth={2.2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
    </svg>
  );
}

function HopChiaSe({ host, dong, chep }: { host: string; dong: () => void; chep: () => void }) {
  const [qr, setQr] = useState('');
  const url = `https://${host}`;
  useEffect(() => {
    QRCode.toDataURL(url, { width: 360, margin: 1, color: { dark: '#0b1b3a', light: '#ffffff' } }).then(setQr).catch(() => {});
    const esc = (e: KeyboardEvent) => e.key === 'Escape' && dong();
    window.addEventListener('keydown', esc);
    return () => window.removeEventListener('keydown', esc);
  }, [url, dong]);
  const chiaSe = () => navigator.share?.({ title: host, url }).catch(() => {});

  return (
    <div className="hop-nen" onClick={(e) => e.target === e.currentTarget && dong()}>
      <div className="hop" role="dialog" aria-label="Chia sẻ website">
        <div className="card-h">
          <h2>Chia sẻ website</h2>
          <div className="sp" />
          <button type="button" className="ibtn" onClick={dong} aria-label="Đóng"><i className="fa-solid fa-xmark" /></button>
        </div>
        <div className="hop-than">
          {qr ? <img className="qr" src={qr} alt={`Mã QR ${url}`} /> : <div className="qr" />}
          <div className="dia-chi">
            <i className="fa-solid fa-globe" />
            <code>{url}</code>
            <button type="button" className="ibtn" onClick={chep} title="Chép link"><i className="fa-regular fa-copy" /></button>
          </div>
          <div className="chia-se">
            {typeof navigator !== 'undefined' && 'share' in navigator && (
              <button type="button" onClick={chiaSe} style={{ background: '#0068ff' }} title="Chia sẻ qua Zalo, Messenger…"><i className="fa-solid fa-share-nodes" /></button>
            )}
            <a href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`} target="_blank" rel="noopener noreferrer" style={{ background: '#1877f2' }} title="Chia sẻ lên Facebook">
              <i className="fa-brands fa-facebook-f" />
            </a>
            {qr && (
              <a href={qr} download={`qr-${host}.png`} style={{ background: '#0b1b3a' }} title="Tải mã QR">
                <i className="fa-solid fa-download" />
              </a>
            )}
          </div>
          <small className="muted">In mã QR lên hoá đơn, tờ rơi, hoặc dán ở quầy.</small>
        </div>
      </div>
    </div>
  );
}
