'use client';

import { useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import BoO, { LinkCoSan, ThemeDangSua, type LinkChon, type NhomChon } from '@/components/admin/giao-dien/BoO';
import { giaTriMacDinh, khuonCua, maKhoiMoi, type BanNhap, type GiaTri, type KhoiKhai, type KhoiNhap } from '@/components/admin/giao-dien/khuon';
import { PhienToanMan } from '@/components/admin/PhienToanMan';
import { adminApi } from '@/lib/adminAuth';
import { api, ApiError } from '@/lib/api';
import { ngayGio } from '@/lib/dinh-dang';
import { FONT_LOP } from '@/lib/fonts';

type MauKho = { ma: string; ten: string; nganh: string; mo_ta: string; mau: string };
type Meta = { kho: MauKho[]; chua_ap_dung: boolean; sua_luc: string | null; ap_dung_luc: string | null; ap_dung_boi: string | null; ma_xem_truoc: string };
type Tab = 'khoi' | 'mau' | 'kho';
type Xem = { loai: 'ds' } | { loai: 'them' } | { loai: 'khoi'; id: string };

// Ô biểu tượng màu cho từng loại khối — nhìn là phân biệt được khối nào với khối nào.
const MAU_IC: Record<string, string> = {
  'banner-chinh': 'lam', 'banner-top': 'cam', 'banner-phu': 'hong', 'hang-hoa': 'luc', 'nhom-hang': 'teal', 'dich-vu': 'vang',
  'dich-vu-gia': 'tim', 'gioi-thieu': 'xam', 'dai-chay': 'cam', 'loi-moi': 'lam',
};
// Khối nào có chỗ neo trên trang để nút "dẫn tới" (trùng id các theme đặt ở TrangChu.tsx).
const NEO: Record<string, [string, string]> = { 'hang-hoa': ['#san-pham', 'Khối Hàng hoá'], 'dich-vu-gia': ['#dich-vu', 'Khối Dịch vụ'], 'gioi-thieu': ['#gioi-thieu', 'Khối Giới thiệu'] };

/** Dòng tóm tắt dưới tên khối: "3 slide · 2 ảnh nhỏ", hoặc tiêu đề đang đặt. */
function tomTat(khai: KhoiKhai, o: GiaTri): string {
  const ds = khai.o.filter((x) => x.loai === 'danh_sach' && (o[x.khoa] ?? []).length).map((x) => `${o[x.khoa].length} ${(x.ten_muc ?? '').toLowerCase()}`);
  if (ds.length) return ds.join(' · ');
  return String(o.tieu_de || o.noi_dung || khai.mo_ta || '');
}

/**
 * Chỉnh sửa giao diện — trang riêng toàn màn hình như trình trang trí của KiotViet: thanh
 * trên riêng, cột chỉnh bên trái (Khối trang · Màu & chữ · Mẫu), trang web thật phủ kín bên
 * phải (khung xem trước chạy bản nháp). Mọi thay đổi tự lưu nháp; Áp dụng mới tới khách.
 * Bản thiết kế: design/html/web-ban-chinh-sua-giao-dien.html (duyệt 29/10).
 */
export default function ChinhSuaGiaoDien() {
  const phien = useContext(PhienToanMan);
  const [nhap, setNhap] = useState<BanNhap | null>(null);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [tab, setTab] = useState<Tab>('khoi');
  const [xem, setXem] = useState<Xem>({ loai: 'ds' });
  const [xemDt, setXemDt] = useState(false); // điện thoại: đang ở tab Xem
  const [che, setChe] = useState<'pc' | 'mb'>('pc');
  const [luu, setLuu] = useState<'' | 'dang' | 'xong' | 'loi'>('');
  const [loiO, setLoiO] = useState<Record<string, string>>({});
  const [thongBao, setThongBao] = useState<{ loai: 'ok' | 'loi'; chu: string } | null>(null);
  const [dangLam, setDangLam] = useState('');
  const [hoiXoa, setHoiXoa] = useState(false);
  const [nhom, setNhom] = useState<NhomChon[]>([]);
  const [doiSang, setDoiSang] = useState('');
  const [dungMau, setDungMau] = useState(true);
  const [srcKhung, setSrcKhung] = useState('');
  const [keoTu, setKeoTu] = useState<number | null>(null);
  const khung = useRef<HTMLIFrameElement>(null);
  const hen = useRef<number>(0);
  const choLuu = useRef<Promise<void> | null>(null);
  const maLuc = useRef(0);

  useEffect(() => {
    adminApi<Meta & { nhap: BanNhap }>('/admin/giao-dien')
      .then(({ data }) => {
        setNhap(data.nhap);
        setMeta(data);
        setSrcKhung(`/?xem_truoc=${encodeURIComponent(data.ma_xem_truoc)}`);
        maLuc.current = Date.now();
        // Từ nút "Đổi mẫu khác" của trang Website của tôi: mở thẳng tab Mẫu.
        if (new URLSearchParams(window.location.search).get('mo') === 'kho') setTab('kho');
      })
      .catch((e: Error) => setThongBao({ loai: 'loi', chu: e.message }));
    // Nhóm hàng (chọn ở khối Hàng hoá, Nhóm hàng; làm đích cho nút): phẳng hoá cây, nhóm con thụt vào.
    api<{ nhom_hang: { id: number; ten: string; slug: string; con: unknown[] }[] }>('/khung')
      .then(({ data }) => {
        const ds: NhomChon[] = [];
        const di = (ns: { id: number; ten: string; slug: string; con: unknown[] }[], sau: number) =>
          ns.forEach((n) => { ds.push({ id: n.id, ten: `${'— '.repeat(sau)}${n.ten}`, slug: n.slug }); di(n.con as typeof ns, sau + 1); });
        di(data.nhom_hang, 0);
        setNhom(ds);
      })
      .catch(() => {});
  }, []);

  const guiKhung = useCallback((data: object) => {
    khung.current?.contentWindow?.postMessage({ nguon: 'ecw-man-chinh', ...data }, window.location.origin);
  }, []);

  const idDangSua = tab === 'khoi' && xem.loai === 'khoi' ? xem.id : null;

  // Bấm một khối trong khung xem trước → mở form khối đó.
  useEffect(() => {
    const nhan = (e: MessageEvent) => {
      if (e.origin !== window.location.origin || e.data?.nguon !== 'ecw-xem-truoc') return;
      if (e.data.loai === 'chon-khoi' && e.data.id) { setTab('khoi'); setXem({ loai: 'khoi', id: e.data.id }); }
      if (e.data.loai === 'san-sang') guiKhung({ loai: 'chon', id: idDangSua });
    };
    window.addEventListener('message', nhan);
    return () => window.removeEventListener('message', nhan);
  }, [guiKhung, idDangSua]);

  useEffect(() => {
    guiKhung({ loai: 'chon', id: idDangSua });
    setHoiXoa(false);
  }, [idDangSua, guiKhung]);

  const luuNgay = useCallback(async (ban: BanNhap) => {
    setLuu('dang');
    try {
      const { data } = await adminApi<Meta>('/admin/giao-dien/nhap', { method: 'PUT', body: ban });
      setMeta(data);
      setLoiO({});
      setLuu('xong');
      // Mã xem trước sống 2 giờ: quá 1 giờ thì nạp lại khung bằng mã mới thay vì làm mới.
      if (Date.now() - maLuc.current > 3_600_000) {
        maLuc.current = Date.now();
        setSrcKhung(`/?xem_truoc=${encodeURIComponent(data.ma_xem_truoc)}`);
      } else {
        guiKhung({ loai: 'lam-moi' });
      }
    } catch (e) {
      setLoiO(e instanceof ApiError ? e.errors : {});
      setLuu('loi');
      setThongBao({ loai: 'loi', chu: (e as Error).message });
    }
  }, [guiKhung]);

  // Sửa → lưu nháp sau 0,6 giây ngừng gõ.
  const doiNhap = (ban: BanNhap) => {
    setNhap(ban);
    setThongBao(null);
    window.clearTimeout(hen.current);
    hen.current = window.setTimeout(() => {
      choLuu.current = luuNgay(ban);
    }, 600);
  };

  async function xongLuu() {
    if (hen.current && nhap) {
      window.clearTimeout(hen.current);
      hen.current = 0;
      await luuNgay(nhap);
    }
    await choLuu.current;
  }

  async function doiMau(ma: string) {
    setDangLam('doi-mau');
    setThongBao(null);
    try {
      await xongLuu();
      const { data, message } = await adminApi<Meta & { nhap: BanNhap }>('/admin/giao-dien/doi-mau', { method: 'POST', body: { theme: ma, dung_mau: dungMau } });
      setMeta(data);
      setNhap(data.nhap);
      setLoiO({});
      setDoiSang('');
      setTab('khoi');
      setXem({ loai: 'ds' });
      guiKhung({ loai: 'lam-moi' });
      setThongBao({ loai: 'ok', chu: message });
    } catch (e) {
      setThongBao({ loai: 'loi', chu: (e as Error).message });
    } finally {
      setDangLam('');
    }
  }

  async function lam(viec: 'ap-dung' | 'bo-nhap') {
    setDangLam(viec);
    setThongBao(null);
    try {
      if (viec === 'ap-dung') await xongLuu();
      const { data, message } = await adminApi<Meta & { nhap: BanNhap }>(`/admin/giao-dien/${viec}`, { method: 'POST' });
      setMeta(data);
      if (viec === 'bo-nhap') {
        setNhap(data.nhap);
        setXem({ loai: 'ds' });
        guiKhung({ loai: 'lam-moi' });
      }
      setThongBao({ loai: 'ok', chu: message });
    } catch (e) {
      setThongBao({ loai: 'loi', chu: (e as Error).message });
    } finally {
      setDangLam('');
    }
  }

  // Đích có sẵn cho ô link: khối có neo trên trang + nhóm hàng + vài trang chung.
  const linkCoSan = useMemo<LinkChon[]>(() => {
    const ds: LinkChon[] = [];
    for (const k of nhap?.trang_chu ?? []) {
      const n = NEO[k.khoi];
      if (n && !ds.some((x) => x.gt === n[0])) ds.push({ gt: n[0], nhan: n[1], nhom: 'Trên trang chủ' });
    }
    ds.push({ gt: '/tim-kiem', nhan: 'Tất cả sản phẩm', nhom: 'Trang của web' }, { gt: '/', nhan: 'Trang chủ', nhom: 'Trang của web' });
    for (const n of nhom) ds.push({ gt: `/danh-muc/${encodeURIComponent(n.slug)}`, nhan: n.ten, nhom: 'Nhóm hàng' });
    return ds;
  }, [nhap?.trang_chu, nhom]);

  if (!nhap || !meta) {
    return (
      <div className="tc tc-cho">
        {thongBao ? <p className="form-alert">{thongBao.chu}</p> : <p className="muted"><i className="fa-solid fa-spinner fa-spin" /> Đang mở trình chỉnh…</p>}
      </div>
    );
  }

  const K = khuonCua(nhap.theme);
  const CO_DINH = { 'dau-trang': K.dau_trang, 'chan-trang': K.chan_trang } as const;
  const mauDangSua = meta.kho.find((m) => m.ma === nhap.theme);

  const doiKhoi = (id: string, sua: (k: KhoiNhap) => KhoiNhap) => doiNhap({ ...nhap, trang_chu: nhap.trang_chu.map((k) => (k.id === id ? sua(k) : k)) });
  const keoTha = (tu: number, toi: number) => {
    if (tu === toi) return;
    const ds = [...nhap.trang_chu];
    const [k] = ds.splice(tu, 1);
    ds.splice(toi, 0, k);
    doiNhap({ ...nhap, trang_chu: ds });
  };
  const themKhoi = (loai: string) => {
    const id = maKhoiMoi();
    doiNhap({ ...nhap, trang_chu: [...nhap.trang_chu, { id, khoi: loai, an: false, o: giaTriMacDinh(K.khoi[loai].o) }] });
    setXem({ loai: 'khoi', id });
  };
  const nhanDoi = (k: KhoiNhap) => {
    const id = maKhoiMoi();
    const i = nhap.trang_chu.findIndex((x) => x.id === k.id);
    const ds = [...nhap.trang_chu];
    ds.splice(i + 1, 0, { ...structuredClone(k), id });
    doiNhap({ ...nhap, trang_chu: ds });
    setXem({ loai: 'khoi', id });
  };
  const moTab = (t: Tab) => { setTab(t); setXemDt(false); if (t === 'khoi') setXem({ loai: 'ds' }); };

  const tenDangSua = idDangSua ? (idDangSua === 'dau-trang' || idDangSua === 'chan-trang' ? CO_DINH[idDangSua].ten : K.khoi[nhap.trang_chu.find((x) => x.id === idDangSua)?.khoi ?? '']?.ten) : '';

  // ---------------- Cột chỉnh ----------------
  let cot: React.ReactNode;
  if (tab === 'mau') {
    cot = <MauVaChu nhap={nhap} K={K} doiNhap={doiNhap} />;
  } else if (tab === 'kho') {
    cot = (
      <>
        <div className="tc-dau"><h2>Mẫu giao diện</h2><p>Đổi mẫu chỉ vào bản nháp; chữ, ảnh bạn đã sửa được mang sang.</p></div>
        <div className="tc-cuon">
          <div className="tc-kho">
            {meta.kho.map((m) => (
              <div key={m.ma} className={`tc-mau${m.ma === nhap.theme ? ' dang' : ''}`}>
                <div className="tc-mau-anh">
                  <img src={`/themes/${m.ma}.jpg`} alt={`Mẫu ${m.ten}`} loading="lazy" />
                  {m.ma === nhap.theme && <span className="tc-nhan-dang"><i className="fa-solid fa-check" /> Đang chỉnh</span>}
                </div>
                <div className="tc-mau-than">
                  <div><b>{m.ten}</b><small>{m.nganh}</small></div>
                  {m.ma !== nhap.theme && doiSang !== m.ma && (
                    <>
                      <a className="btn nho" href={`/?xem_truoc=${encodeURIComponent(meta.ma_xem_truoc)}&thu_mau=${m.ma}`} target="_blank" rel="noopener" title="Xem thử bằng nội dung của bạn"><i className="fa-regular fa-eye" /></a>
                      <button type="button" className="btn pri nho" onClick={() => setDoiSang(m.ma)}>Dùng mẫu</button>
                    </>
                  )}
                </div>
                {doiSang === m.ma && (
                  <div className="tc-doi-hoi">
                    <label><input type="checkbox" checked={dungMau} onChange={(e) => setDungMau(e.target.checked)} /> Dùng màu gợi ý của mẫu <i className="mau-cham" style={{ background: m.mau }} /></label>
                    <div>
                      <button type="button" className="btn pri nho" disabled={!!dangLam} onClick={() => doiMau(m.ma)}>{dangLam === 'doi-mau' ? 'Đang đổi…' : `Đổi sang ${m.ten}`}</button>
                      <button type="button" className="btn nho" onClick={() => setDoiSang('')}>Huỷ</button>
                    </div>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      </>
    );
  } else if (xem.loai === 'them') {
    cot = (
      <>
        <div className="tc-dau-form">
          <button type="button" className="ibtn nho" onClick={() => setXem({ loai: 'ds' })} aria-label="Quay lại"><i className="fa-solid fa-chevron-left" /></button>
          <span className="chu"><b>Thêm khối nội dung</b><small>Khối mới nằm cuối trang chủ, kéo để đổi chỗ</small></span>
        </div>
        <div className="tc-cuon">
          <div className="tc-ds">
            {Object.entries(K.khoi).map(([loai, k]) => (
              <button key={loai} type="button" className="tc-the" onClick={() => themKhoi(loai)}>
                <span className={`tc-ic ${MAU_IC[loai] ?? 'xam'}`}><i className={`fa-solid ${k.icon}`} /></span>
                <span className="tc-the-chu"><b>{k.ten}</b><small>{k.mo_ta}</small></span>
                <i className="fa-solid fa-plus tc-mui" />
              </button>
            ))}
          </div>
        </div>
      </>
    );
  } else if (xem.loai === 'khoi') {
    const coDinh = xem.id === 'dau-trang' || xem.id === 'chan-trang' ? CO_DINH[xem.id] : null;
    const k = coDinh ? null : nhap.trang_chu.find((x) => x.id === xem.id);
    const khai: KhoiKhai | undefined = coDinh ?? (k ? K.khoi[k.khoi] : undefined);
    const phan = xem.id === 'dau-trang' ? 'dau_trang' : 'chan_trang';
    cot = !khai ? (
      <p className="pad muted">Khối này không còn.</p>
    ) : (
      <>
        <div className="tc-dau-form">
          <button type="button" className="ibtn nho" onClick={() => setXem({ loai: 'ds' })} aria-label="Quay lại"><i className="fa-solid fa-chevron-left" /></button>
          <span className={`tc-ic ${coDinh ? 'xam' : MAU_IC[k!.khoi] ?? 'xam'}`}><i className={`fa-solid ${khai.icon}`} /></span>
          <span className="chu"><b>{khai.ten}</b><small>{coDinh ? 'Dùng chung cho mọi trang' : k!.an ? 'Đang ẩn trên trang' : 'Đang hiện trên trang'}</small></span>
          {k && <button type="button" role="switch" aria-checked={!k.an} title="Hiện / ẩn khối" className={`cong-tac${k.an ? '' : ' bat'}`} onClick={() => doiKhoi(k.id, (x) => ({ ...x, an: !x.an }))} />}
        </div>
        <div className="tc-cuon">
          {coDinh && (
            <p className="tc-ghi"><i className="fa-solid fa-circle-info" /> Logo, tên, hotline và liên hệ lấy từ <Link href="/admin/cai-dat/cua-hang">Thông tin cửa hàng</Link>.</p>
          )}
          <BoO
            khai={khai.o}
            gt={coDinh ? nhap[phan] : k!.o}
            doi={(gt: GiaTri) => (coDinh ? doiNhap({ ...nhap, [phan]: gt }) : doiKhoi(k!.id, (x) => ({ ...x, o: gt })))}
            loi={loiO}
            duong={coDinh ? phan : `trang_chu.${k!.id}`}
            nhom={nhom}
            mau={K.mau}
            chiaMuc
          />
        </div>
        {k && (
          <div className="tc-chan">
            {hoiXoa ? (
              <>
                <span className="tc-hoi">Xoá “{khai.ten}”?</span>
                <button type="button" className="btn danger nho" onClick={() => { doiNhap({ ...nhap, trang_chu: nhap.trang_chu.filter((x) => x.id !== k.id) }); setXem({ loai: 'ds' }); }}>Xoá</button>
                <button type="button" className="btn nho" onClick={() => setHoiXoa(false)}>Huỷ</button>
              </>
            ) : (
              <>
                <button type="button" className="btn ghost-danger nho" onClick={() => setHoiXoa(true)}><i className="fa-regular fa-trash-can" /> Xoá khối</button>
                <button type="button" className="btn nho" onClick={() => nhanDoi(k)}><i className="fa-regular fa-copy" /> Nhân đôi</button>
                <button type="button" className="btn pri nho" onClick={() => setXem({ loai: 'ds' })}><i className="fa-solid fa-check" /> Xong</button>
              </>
            )}
          </div>
        )}
      </>
    );
  } else {
    const soAn = nhap.trang_chu.filter((x) => x.an).length;
    cot = (
      <>
        <div className="tc-dau">
          <div className="tc-hang"><h2>Khối trang</h2><span className="tc-chon-trang"><i className="fa-regular fa-file" /> Trang chủ</span></div>
          <p>{nhap.trang_chu.length} khối{soAn ? ` · ${soAn} đang ẩn` : ''} — kéo <i className="fa-solid fa-grip-vertical" /> để đổi thứ tự</p>
        </div>
        <div className="tc-cuon">
          <div className="tc-nhom"><i className="fa-solid fa-lock" /> Dùng chung mọi trang</div>
          <div className="tc-ds">
            {(['dau-trang', 'chan-trang'] as const).map((id) => (
              <button key={id} type="button" className="tc-the" onClick={() => setXem({ loai: 'khoi', id })}>
                <span className="tc-keo" />
                <span className="tc-ic xam"><i className={`fa-solid ${CO_DINH[id].icon}`} /></span>
                <span className="tc-the-chu"><b>{CO_DINH[id].ten}</b><small>{id === 'dau-trang' ? 'Logo · tìm kiếm · menu nhóm hàng' : 'Liên hệ · danh mục · bản quyền'}</small></span>
                <i className="fa-solid fa-chevron-right tc-mui" />
              </button>
            ))}
          </div>
          <div className="tc-nhom"><i className="fa-solid fa-house" /> Nội dung trang chủ</div>
          <div className="tc-ds">
            {nhap.trang_chu.map((k, i) => {
              const khai = K.khoi[k.khoi];
              return (
                <div
                  key={k.id}
                  className={`tc-the${k.an ? ' an' : ''}${keoTu === i ? ' keo' : ''}`}
                  draggable
                  onDragStart={(e) => { setKeoTu(i); e.dataTransfer.effectAllowed = 'move'; }}
                  onDragOver={(e) => { e.preventDefault(); if (keoTu !== null && keoTu !== i) { keoTha(keoTu, i); setKeoTu(i); } }}
                  onDragEnd={() => setKeoTu(null)}
                  onClick={() => setXem({ loai: 'khoi', id: k.id })}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => e.key === 'Enter' && setXem({ loai: 'khoi', id: k.id })}
                >
                  <span className="tc-keo" title="Kéo để đổi chỗ"><i className="fa-solid fa-grip-vertical" /></span>
                  <span className={`tc-ic ${MAU_IC[k.khoi] ?? 'xam'}`}><i className={`fa-solid ${khai.icon}`} /></span>
                  <span className="tc-the-chu"><b>{khai.ten}</b><small>{k.an ? 'Đang ẩn · ' : ''}{tomTat(khai, k.o)}</small></span>
                  <span className="tc-the-nut" onClick={(e) => e.stopPropagation()}>
                    <button type="button" className="ibtn nho tc-len" aria-label="Lên" disabled={i === 0} onClick={() => keoTha(i, i - 1)}><i className="fa-solid fa-arrow-up" /></button>
                    <button type="button" className="ibtn nho" aria-label={k.an ? 'Hiện khối' : 'Ẩn khối'} title={k.an ? 'Đang ẩn — bấm để hiện' : 'Ẩn khối'} onClick={() => doiKhoi(k.id, (x) => ({ ...x, an: !x.an }))}>
                      <i className={`fa-regular ${k.an ? 'fa-eye-slash' : 'fa-eye'}`} />
                    </button>
                  </span>
                </div>
              );
            })}
          </div>
          <button type="button" className="tc-them-khoi" onClick={() => setXem({ loai: 'them' })}><i className="fa-solid fa-plus" /> Thêm khối nội dung</button>
          <p className="tc-goi-y"><i className="fa-solid fa-wand-magic-sparkles" /> Bấm thẳng vào một phần trên trang bên phải cũng mở đúng khối đó để sửa.</p>
        </div>
      </>
    );
  }

  const trangThai = luu === 'dang' ? 'Đang lưu nháp…' : luu === 'loi' ? 'Chưa lưu được — xem ô báo đỏ'
    : meta.chua_ap_dung ? `Đã lưu nháp${meta.sua_luc ? ` ${ngayGio(meta.sua_luc).slice(0, 5)}` : ''} · chưa áp dụng`
      : meta.ap_dung_luc ? `Đã áp dụng ${ngayGio(meta.ap_dung_luc)}` : 'Đang dùng giao diện mặc định';

  return (
    <ThemeDangSua.Provider value={nhap.theme}>
    <LinkCoSan.Provider value={linkCoSan}>
    <div className={`tc${xemDt ? ' xem' : ''}`}>
      <header className="tc-thanh">
        <div className="tc-trai">
          <Link className="tc-ve" href="/admin/giao-dien" title="Về Website của tôi"><i className="fa-solid fa-chevron-left" /><span className="mark">{(phien?.shop.name ?? 'S').slice(0, 1).toUpperCase()}</span></Link>
          <div className="tc-ten">
            <b>Chỉnh sửa giao diện</b>
            <small><i className="cham" style={{ background: nhap.chung.mau_chinh }} />{mauDangSua?.ten ?? K.ten} · {phien?.shop.name}</small>
          </div>
        </div>
        <div className="tc-che">
          <button type="button" className={che === 'pc' ? 'on' : ''} onClick={() => setChe('pc')} aria-label="Máy tính"><i className="fa-solid fa-desktop" /><span>Máy tính</span></button>
          <button type="button" className={che === 'mb' ? 'on' : ''} onClick={() => setChe('mb')} aria-label="Điện thoại"><i className="fa-solid fa-mobile-screen" /><span>Điện thoại</span></button>
        </div>
        <div className="tc-phai">
          <span className={`tc-luu ${luu === 'loi' ? 'loi' : meta.chua_ap_dung || luu === 'dang' ? 'cho' : 'xong'}`} title={trangThai}>
            <i className={`fa-solid ${luu === 'dang' ? 'fa-rotate fa-spin' : luu === 'loi' ? 'fa-circle-exclamation' : meta.chua_ap_dung ? 'fa-circle' : 'fa-check'}`} />
            <span>{trangThai}</span>
          </span>
          <button type="button" className="ibtn tc-an-dt" title="Bỏ thay đổi chưa áp dụng" disabled={!!dangLam || !meta.chua_ap_dung || !meta.ap_dung_luc} onClick={() => lam('bo-nhap')}><i className="fa-solid fa-rotate-left" /></button>
          <a className="btn tc-an-dt" href={`/?xem_truoc=${encodeURIComponent(meta.ma_xem_truoc)}`} target="_blank" rel="noopener" title="Mở bản nháp cỡ thật ở tab mới"><i className="fa-regular fa-eye" /> Xem trước</a>
          <button type="button" className="btn pri" disabled={!!dangLam || (!meta.chua_ap_dung && !!meta.ap_dung_luc)} onClick={() => lam('ap-dung')}>
            <i className="fa-solid fa-paper-plane" /> {dangLam === 'ap-dung' ? 'Đang áp dụng…' : 'Áp dụng'}
          </button>
        </div>
      </header>

      <div className="tc-than">
        <aside className="tc-cot">
          <div className="tc-tab" role="tablist">
            <button type="button" className={tab === 'khoi' ? 'on' : ''} onClick={() => moTab('khoi')}><i className="fa-solid fa-layer-group" />Khối trang</button>
            <button type="button" className={tab === 'mau' ? 'on' : ''} onClick={() => moTab('mau')}><i className="fa-solid fa-palette" />Màu & chữ</button>
            <button type="button" className={tab === 'kho' ? 'on' : ''} onClick={() => moTab('kho')}><i className="fa-solid fa-swatchbook" />Mẫu</button>
          </div>
          {thongBao && <p className={`tc-bao ${thongBao.loai === 'ok' ? 'form-ok' : 'form-alert'}`}>{thongBao.chu}</p>}
          {cot}
        </aside>
        <section className={`tc-vung tc-${che}`}>
          {tenDangSua && (
            <div className="tc-dang-sua"><i className="fa-solid fa-pen" /><span>Đang sửa: {tenDangSua}</span><button type="button" onClick={() => setXem({ loai: 'ds' })}>Xong</button></div>
          )}
          {srcKhung && <KhungXem che={che} src={srcKhung} khung={khung} />}
        </section>
      </div>

      <nav className="tc-tab-dt">
        <button type="button" className={!xemDt && tab === 'khoi' ? 'on' : ''} onClick={() => moTab('khoi')}><i className="fa-solid fa-layer-group" />Khối</button>
        <button type="button" className={!xemDt && tab === 'mau' ? 'on' : ''} onClick={() => moTab('mau')}><i className="fa-solid fa-palette" />Màu & chữ</button>
        <button type="button" className={!xemDt && tab === 'kho' ? 'on' : ''} onClick={() => moTab('kho')}><i className="fa-solid fa-swatchbook" />Mẫu</button>
        <button type="button" className={xemDt ? 'on' : ''} onClick={() => setXemDt(true)}><i className="fa-regular fa-eye" />Xem</button>
      </nav>
    </div>
    </LinkCoSan.Provider>
    </ThemeDangSua.Provider>
  );
}

function MauVaChu({ nhap, K, doiNhap }: { nhap: BanNhap; K: ReturnType<typeof khuonCua>; doiNhap: (b: BanNhap) => void }) {
  const doi = (chung: Partial<BanNhap['chung']>) => doiNhap({ ...nhap, chung: { ...nhap.chung, ...chung } });
  const mau = nhap.chung.mau_chinh;
  return (
    <>
      <div className="tc-dau"><h2>Màu & chữ</h2><p>Áp cho mọi trang của web — đổi mẫu giao diện vẫn giữ.</p></div>
      <div className={`tc-cuon ${FONT_LOP}`}>
        <section className="tc-muc">
          <div className="tc-muc-dau"><b>Màu chủ đạo</b><small>{mau}</small></div>
          <div className="tc-muc-than">
            <div className="tc-mau-luoi">
              {K.mau.map((m) => <button key={m} type="button" className={`tc-mau-o${m === mau ? ' on' : ''}`} style={{ background: m }} aria-label={m} onClick={() => doi({ mau_chinh: m })} />)}
            </div>
            <label className="tc-mau-rieng">
              <input type="color" value={mau} onChange={(e) => doi({ mau_chinh: e.target.value })} />
              <span>Màu riêng của bạn</span>
              <code>{mau}</code>
            </label>
          </div>
        </section>
        <section className="tc-muc">
          <div className="tc-muc-dau"><b>Phông chữ</b><small>{K.font.find((f) => f.khoa === nhap.chung.font)?.ten}</small></div>
          <div className="tc-muc-than">
            <div className="tc-font">
              {K.font.map((f) => (
                <button key={f.khoa} type="button" className={f.khoa === nhap.chung.font ? 'on' : ''} onClick={() => doi({ font: f.khoa })}>
                  <span className="aa" style={{ fontFamily: `var(--f-${f.khoa})` }}>Aa Ăă</span>
                  <small>{f.ten}</small>
                </button>
              ))}
            </div>
          </div>
        </section>
        <section className="tc-muc">
          <div className="tc-muc-dau"><b>Xem nhanh</b></div>
          <div className="tc-muc-than">
            <div className="tc-xem-nhanh" style={{ fontFamily: `var(--f-${nhap.chung.font})` }}>
              <span className="tieu" style={{ color: mau }}>Sản phẩm nổi bật</span>
              <div className="hang">
                <span className="nut" style={{ background: mau }}>Mua ngay</span>
                <span className="nut vien" style={{ color: mau, boxShadow: `inset 0 0 0 2px ${mau}` }}>Xem thêm</span>
                <span className="gia" style={{ color: mau }}>250.000 ₫</span>
              </div>
            </div>
          </div>
        </section>
      </div>
    </>
  );
}

// Máy tính: trang phủ kín vùng xem, đúng cỡ thật (không thu nhỏ). Điện thoại: dựng 390px
// trong khung máy, thu nhỏ cho vừa chiều cao.
function KhungXem({ che, src, khung }: { che: 'pc' | 'mb'; src: string; khung: React.RefObject<HTMLIFrameElement | null> }) {
  const vo = useRef<HTMLDivElement>(null);
  const [cao, setCao] = useState(0);
  useEffect(() => {
    const el = vo.current;
    if (!el) return;
    const ro = new ResizeObserver(([e]) => setCao(e.contentRect.height));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  const ti = Math.min(1, Math.max(cao - 60, 300) / 800);
  return (
    <div ref={vo} className="tc-khung">
      {che === 'pc' ? (
        <iframe ref={khung} src={src} title="Xem trước trang chủ" className="tc-iframe-pc" />
      ) : (
        cao > 0 && (
          <div className="tc-may-dt" style={{ width: 390 * ti, height: 800 * ti }}>
            <iframe ref={khung} src={src} title="Xem trước trang chủ" style={{ width: 390, height: 800, transform: `scale(${ti})` }} />
          </div>
        )
      )}
    </div>
  );
}
