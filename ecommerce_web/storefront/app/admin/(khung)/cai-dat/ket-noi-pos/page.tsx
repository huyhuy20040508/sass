'use client';

import { useCallback, useEffect, useState } from 'react';
import { adminApi } from '@/lib/adminAuth';
import { ngayGio, tien } from '@/lib/dinh-dang';
import { ketNoiTot, type HangPos, type TrangThaiPos } from '@/lib/pos';

const NHAN: Record<string, { chu: string; lop: string }> = {
  tot: { chu: 'Đang kết nối tốt', lop: 'ok' },
  khoa_bi_thu_hoi: { chu: 'Khoá đã bị thu hồi', lop: 'err' },
  cua_hang_khoa: { chu: 'Cửa hàng tạm khoá', lop: 'err' },
  pos_khong_tra_loi: { chu: 'POS không trả lời', lop: 'warn' },
  loi: { chu: 'Có lỗi', lop: 'err' },
};

export default function KetNoiPos() {
  const [tt, setTt] = useState<TrangThaiPos | null>(null);
  const [dangLam, setDangLam] = useState('');
  const [loi, setLoi] = useState('');
  const [hoiNgat, setHoiNgat] = useState(false);

  const tai = useCallback(async () => {
    setLoi('');
    try {
      setTt((await adminApi<TrangThaiPos>('/admin/pos')).data);
    } catch (e) {
      setLoi((e as Error).message);
    }
  }, []);

  useEffect(() => {
    tai();
  }, [tai]);

  async function lam(viec: string, goi: () => Promise<void>) {
    setDangLam(viec);
    setLoi('');
    try {
      await goi();
    } catch (e) {
      setLoi((e as Error).message);
    } finally {
      setDangLam('');
      setHoiNgat(false);
    }
  }
  const ketNoi = () => lam('ket-noi', async () => setTt((await adminApi<TrangThaiPos>('/admin/pos/ket-noi', { method: 'POST' })).data));
  const ngat = () => lam('ngat', async () => setTt((await adminApi<TrangThaiPos>('/admin/pos', { method: 'DELETE' })).data));
  const kiemTra = () => lam('kiem-tra', tai);

  if (!tt) {
    return loi ? <p className="form-alert">{loi}</p> : <section className="card pad muted">Đang kiểm tra kết nối…</section>;
  }

  if (!tt.ket_noi) {
    return (
      <section className="card">
        <div className="ph">
          <div className="ico">
            <i className="fa-solid fa-plug" />
          </div>
          <h2>Kết nối web với phần mềm bán hàng (POS)</h2>
          <p>Một lần bấm: POS cấp cho web một khoá riêng của cửa hàng. Từ đó web tự lấy hàng, giá, tồn kho và gửi đơn về POS — không cần ai đăng nhập.</p>
          <ul>
            <li>Không nhập lại hàng hoá — mọi thứ đọc thẳng từ POS</li>
            <li>Ngắt kết nối bất cứ lúc nào, khoá cũ hết hiệu lực ngay</li>
          </ul>
          {loi && <p className="form-alert">{loi}</p>}
          <div>
            <button className="btn pri lg" type="button" onClick={ketNoi} disabled={!!dangLam}>
              <i className="fa-solid fa-link" />
              {dangLam === 'ket-noi' ? 'Đang kết nối…' : 'Kết nối với POS'}
            </button>
          </div>
        </div>
      </section>
    );
  }

  const nhan = NHAN[tt.tinh_trang ?? 'loi'] ?? NHAN.loi;
  return (
    <>
      <section className="card">
        <div className="card-h">
          <h2>Kết nối POS</h2>
          <span className={`chip ${nhan.lop}`}>
            <i className={`fa-solid ${nhan.lop === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation'}`} />
            {nhan.chu}
          </span>
          <div className="sp" />
          <button className="btn" type="button" onClick={kiemTra} disabled={!!dangLam}>
            <i className={`fa-solid fa-rotate${dangLam === 'kiem-tra' ? ' fa-spin' : ''}`} />
            Kiểm tra lại
          </button>
        </div>
        <div className="pad">
          {tt.thong_bao && <p className="form-alert">{tt.thong_bao}</p>}
          {loi && <p className="form-alert">{loi}</p>}
          <div className="kv">
            <div><span>Khoá đang dùng</span><b className="mono">{tt.key_prefix}…</b></div>
            <div><span>Kết nối bởi</span><b>{tt.ket_noi_boi}</b></div>
            <div><span>Lúc</span><b>{ngayGio(tt.ket_noi_luc)}</b></div>
            {ketNoiTot(tt) && (
              <div><span>Đọc được từ POS</span><b>{tt.so_nhom} nhóm hàng · {tt.so_hang} mặt hàng</b></div>
            )}
          </div>
          <div className="actions">
            {!ketNoiTot(tt) && (
              <button className="btn pri" type="button" onClick={ketNoi} disabled={!!dangLam}>
                <i className="fa-solid fa-link" />
                {dangLam === 'ket-noi' ? 'Đang kết nối…' : 'Kết nối lại'}
              </button>
            )}
            {hoiNgat ? (
              <>
                <span className="muted">Web sẽ ngừng đọc hàng và nhận đơn từ POS.</span>
                <button className="btn danger" type="button" onClick={ngat} disabled={!!dangLam}>
                  {dangLam === 'ngat' ? 'Đang ngắt…' : 'Xác nhận ngắt'}
                </button>
                <button className="btn" type="button" onClick={() => setHoiNgat(false)} disabled={!!dangLam}>
                  Huỷ
                </button>
              </>
            ) : (
              <button className="btn ghost-danger" type="button" onClick={() => setHoiNgat(true)} disabled={!!dangLam}>
                <i className="fa-solid fa-link-slash" />
                Ngắt kết nối
              </button>
            )}
          </div>
        </div>
      </section>

      {ketNoiTot(tt) && <HangTuPos />}
    </>
  );
}

function HangTuPos() {
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [tim, setTim] = useState('');
  const [data, setData] = useState<{ items: HangPos[]; tongTrang: number; tong: number } | null>(null);
  const [loi, setLoi] = useState('');

  useEffect(() => {
    let huy = false;
    const qs = new URLSearchParams({ page: String(page), ...(tim ? { q: tim } : {}) });
    adminApi<HangPos[]>(`/admin/pos/hang?${qs}`)
      .then(({ data, meta }) => {
        if (huy) return;
        setLoi('');
        setData({ items: data, tongTrang: Number(meta.total_pages || 1), tong: Number(meta.total || 0) });
      })
      .catch((e: Error) => !huy && setLoi(e.message));
    return () => {
      huy = true;
    };
  }, [page, tim]);

  const gia = (h: HangPos) => (h.gia_thap === h.gia_cao ? tien(h.gia_thap) : `${tien(h.gia_thap)} – ${tien(h.gia_cao)}`);

  return (
    <section className="card">
      <div className="card-h">
        <h2>Hàng đọc từ POS</h2>
        {data && <span className="muted">{data.tong} mặt hàng</span>}
        <div className="sp" />
        <form
          className="search"
          onSubmit={(e) => {
            e.preventDefault();
            setPage(1);
            setTim(q.trim());
          }}
        >
          <i className="fa-solid fa-magnifying-glass" />
          <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tìm tên, mã hàng" />
        </form>
      </div>
      {loi && <p className="form-alert pad">{loi}</p>}
      <div className="tbl-wrap">
        <table className="tbl">
          <thead>
            <tr>
              <th>Hàng hoá</th>
              <th>Nhóm</th>
              <th className="r">Giá bán</th>
              <th className="r">Tồn</th>
              <th className="r">Biến thể</th>
              <th>Trạng thái</th>
            </tr>
          </thead>
          <tbody>
            {data?.items.map((h) => (
              <tr key={h.id}>
                <td>
                  <div className="sp-cell">
                    {h.anh ? <img src={h.anh} alt="" /> : <span className="no-img"><i className="fa-regular fa-image" /></span>}
                    <div>
                      <b>{h.ten}</b>
                      <small>{h.sku}</small>
                    </div>
                  </div>
                </td>
                <td>{h.nhom}</td>
                <td className="r">{gia(h)}</td>
                <td className="r">{h.ton.toLocaleString('vi-VN')}</td>
                <td className="r">{h.so_bien_the}</td>
                <td>
                  <span className={`chip ${h.dang_ban ? 'ok' : 'mute'}`}>{h.dang_ban ? 'Đang bán' : 'Ngừng bán'}</span>
                </td>
              </tr>
            ))}
            {data && data.items.length === 0 && (
              <tr>
                <td colSpan={6} className="empty">Không có hàng nào{tim ? ` khớp “${tim}”` : ''}.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      {data && data.tongTrang > 1 && (
        <div className="pager">
          <button className="btn" type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            <i className="fa-solid fa-chevron-left" />
          </button>
          <span>Trang {page} / {data.tongTrang}</span>
          <button className="btn" type="button" disabled={page >= data.tongTrang} onClick={() => setPage(page + 1)}>
            <i className="fa-solid fa-chevron-right" />
          </button>
        </div>
      )}
    </section>
  );
}
