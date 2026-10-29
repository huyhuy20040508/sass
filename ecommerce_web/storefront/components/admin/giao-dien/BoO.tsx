'use client';

import { createContext, useContext, useRef, useState } from 'react';
import { adminApi } from '@/lib/adminAuth';
import { giaTriMacDinh, type GiaTri, type OKhai } from './khuon';

export type NhomChon = { id: number; ten: string; slug: string };
export type LinkChon = { gt: string; nhan: string; nhom: string };

// Theme đang sửa — ô ảnh cần nó để hiện ảnh mẫu đi kèm theme ("mau:<tệp>" → /themes/<theme>/<tệp>).
export const ThemeDangSua = createContext('mac-dinh');
// Những chỗ một nút/link có thể dẫn tới (khối trên trang, nhóm hàng…) — ô link chọn từ đây
// thay vì bắt chủ tiệm gõ đường dẫn.
export const LinkCoSan = createContext<LinkChon[]>([]);
export const linkAnh = (v: string, theme: string) => (v.startsWith('mau:') ? `/themes/${theme}/${v.slice(4)}` : `/api/anh/${v}`);

type Props = {
  khai: OKhai[];
  gt: GiaTri;
  doi: (gt: GiaTri) => void;
  loi: Record<string, string>;
  // Đường của bộ ô trong lỗi PHP trả về, vd "trang_chu.k2" → lỗi ô tiêu đề ở "trang_chu.k2.tieu_de".
  duong: string;
  nhom: NhomChon[];
  // Bảng màu gợi ý của theme đang sửa (ô loại "mau").
  mau: string[];
  // Cấp ngoài cùng của form khối: gom ô thường vào mục "Nội dung", mỗi danh sách một mục riêng.
  chiaMuc?: boolean;
};

const hien = (o: OKhai, gt: GiaTri) => !o.hien_khi || Object.entries(o.hien_khi).every(([k, v]) => gt[k] === v);

/** Form tự sinh từ khai báo ô của khối (theme.json): thêm khối mới không phải viết form. */
export default function BoO({ khai, gt, doi, loi, duong, nhom, mau, chiaMuc }: Props) {
  const o1 = (o: OKhai) => (
    <MotO key={o.khoa} o={o} v={gt[o.khoa]} doi={(v) => doi({ ...gt, [o.khoa]: v })} loi={loi} duong={`${duong}.${o.khoa}`} nhom={nhom} mau={mau} />
  );
  const ds = khai.filter((o) => hien(o, gt));
  if (!chiaMuc) return <div className="bo-o">{ds.map(o1)}</div>;

  const thuong = ds.filter((o) => o.loai !== 'danh_sach');
  return (
    <div className="bo-o">
      {ds.filter((o) => o.loai === 'danh_sach').map((o) => (
        <Muc key={o.khoa} ten={o.nhan} phu={`${(gt[o.khoa] ?? []).length}/${o.toi_da}`}>{o1(o)}</Muc>
      ))}
      {thuong.length > 0 && <Muc ten="Nội dung & tuỳ chọn">{thuong.map(o1)}</Muc>}
    </div>
  );
}

function Muc({ ten, phu, children }: { ten: string; phu?: string; children: React.ReactNode }) {
  const [dong, setDong] = useState(false);
  return (
    <section className={`tc-muc${dong ? ' dong' : ''}`}>
      <button type="button" className="tc-muc-dau" onClick={() => setDong(!dong)} aria-expanded={!dong}>
        <b>{ten}</b>
        {phu && <small>{phu}</small>}
        <i className="fa-solid fa-chevron-down" />
      </button>
      {!dong && <div className="tc-muc-than">{children}</div>}
    </section>
  );
}

function MotO({ o, v, doi, loi, duong, nhom, mau }: { o: OKhai; v: any; doi: (v: unknown) => void; loi: Record<string, string>; duong: string; nhom: NhomChon[]; mau: string[] }) { // eslint-disable-line @typescript-eslint/no-explicit-any
  const sai = loi[duong];
  const nhan = (
    <span className="o-nhan">
      {o.nhan}
      {(o.loai === 'chu' || o.loai === 'doan') && o.max && <small>{String(v ?? '').length}/{o.max}</small>}
    </span>
  );
  const duoi = sai ? <small className="o-loi">{sai}</small> : o.goi_y && o.loai !== 'chu' && o.loai !== 'link' ? <em>{o.goi_y}</em> : null;

  switch (o.loai) {
    case 'bat_tat':
      return (
        <label className="o o-bat">
          <span className="o-nhan">{o.nhan}</span>
          <button type="button" role="switch" aria-checked={!!v} className={`cong-tac${v ? ' bat' : ''}`} onClick={() => doi(!v)} />
        </label>
      );
    case 'doan':
      return (
        <label className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <textarea rows={3} maxLength={o.max} value={v ?? ''} onChange={(e) => doi(e.target.value)} />
          {duoi}
        </label>
      );
    case 'chu':
      return (
        <label className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <input value={v ?? ''} maxLength={o.max} placeholder={o.goi_y} onChange={(e) => doi(e.target.value)} />
          {sai && <small className="o-loi">{sai}</small>}
        </label>
      );
    case 'link':
      return <OLink o={o} v={v ?? ''} doi={doi} sai={sai} />;
    case 'so':
      return (
        <label className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <span className="o-so">
            <input type="range" min={o.min} max={o.max} value={v ?? 0} onChange={(e) => doi(Number(e.target.value))} />
            <b>{v}</b>
          </span>
          {duoi}
        </label>
      );
    case 'chon':
      return (o.lua_chon?.length ?? 0) <= 3 ? (
        <div className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <div className="o-doan">
            {o.lua_chon!.map((c) => (
              <button key={c.gt} type="button" className={v === c.gt ? 'on' : ''} onClick={() => doi(c.gt)}>{c.nhan}</button>
            ))}
          </div>
          {duoi}
        </div>
      ) : (
        <label className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <select value={v} onChange={(e) => doi(e.target.value)}>
            {o.lua_chon!.map((c) => <option key={c.gt} value={c.gt}>{c.nhan}</option>)}
          </select>
          {duoi}
        </label>
      );
    case 'nhom_hang':
      return (
        <label className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <select value={v || ''} onChange={(e) => doi(Number(e.target.value) || 0)}>
            <option value="">— Chọn nhóm hàng —</option>
            {nhom.map((n) => <option key={n.id} value={n.id}>{n.ten}</option>)}
          </select>
          {sai ? duoi : !nhom.length && <em>Chưa có nhóm hàng — kết nối POS trước.</em>}
        </label>
      );
    case 'mau':
      return (
        <div className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <div className="o-mau">
            <button type="button" className={`mau-o tu-dong${!v ? ' on' : ''}`} title="Màu chủ đạo" onClick={() => doi('')}>
              <i className="fa-solid fa-wand-magic-sparkles" />
            </button>
            {mau.slice(0, 7).map((m) => (
              <button key={m} type="button" className={`mau-o${v === m ? ' on' : ''}`} style={{ background: m }} aria-label={m} onClick={() => doi(m)} />
            ))}
            <input type="color" value={v || '#000000'} onChange={(e) => doi(e.target.value)} aria-label="Màu khác" />
          </div>
          {duoi}
        </div>
      );
    case 'ngay_gio':
      return (
        <label className={`o${sai ? ' sai' : ''}`}>
          {nhan}
          <span className="o-ngay">
            <input type="datetime-local" value={v ?? ''} onChange={(e) => doi(e.target.value)} />
            {v && <button type="button" className="btn" onClick={() => doi('')}>Bỏ</button>}
          </span>
          {duoi}
        </label>
      );
    case 'anh':
      return <OAnh o={o} v={v ?? ''} doi={doi} sai={sai} duoi={duoi} />;
    case 'danh_sach':
      return <ODanhSach o={o} v={v ?? []} doi={doi} loi={loi} duong={duong} nhom={nhom} mau={mau} />;
  }
  return null;
}

/** Ô link: chọn khối trên trang / nhóm hàng có sẵn; chỉ "Link khác" mới phải gõ. */
function OLink({ o, v, doi, sai }: { o: OKhai; v: string; doi: (v: unknown) => void; sai?: string }) {
  const coSan = useContext(LinkCoSan);
  const khop = coSan.some((l) => l.gt === v);
  const [tuGo, setTuGo] = useState(!!v && !khop);
  const nhomDs = [...new Set(coSan.map((l) => l.nhom))];
  return (
    <div className={`o${sai ? ' sai' : ''}`}>
      <span className="o-nhan">{o.nhan}</span>
      <div className="o-link">
        <select
          value={tuGo ? '__khac' : khop ? v : ''}
          onChange={(e) => {
            if (e.target.value === '__khac') { setTuGo(true); return; }
            setTuGo(false);
            doi(e.target.value);
          }}
        >
          <option value="">— Không dẫn đi đâu —</option>
          {nhomDs.map((n) => (
            <optgroup key={n} label={n}>
              {coSan.filter((l) => l.nhom === n).map((l) => <option key={l.gt} value={l.gt}>{l.nhan}</option>)}
            </optgroup>
          ))}
          <option value="__khac">Link khác (tự gõ)…</option>
        </select>
        {tuGo && <input value={v} maxLength={255} placeholder="https://… hoặc /trang" onChange={(e) => doi(e.target.value)} autoFocus />}
      </div>
      {sai && <small className="o-loi">{sai}</small>}
    </div>
  );
}

function OAnh({ o, v, doi, sai, duoi }: { o: OKhai; v: string; doi: (v: unknown) => void; sai?: string; duoi: React.ReactNode }) {
  const chon = useRef<HTMLInputElement>(null);
  const theme = useContext(ThemeDangSua);
  const [dang, setDang] = useState(false);
  const [loiTai, setLoiTai] = useState('');

  async function tai(tep: File) {
    setDang(true);
    setLoiTai('');
    try {
      const fd = new FormData();
      fd.append('anh', tep);
      doi((await adminApi<{ ten: string }>('/admin/anh', { method: 'POST', body: fd })).data.ten);
    } catch (e) {
      setLoiTai((e as Error).message);
    } finally {
      setDang(false);
      if (chon.current) chon.current.value = '';
    }
  }

  return (
    <div className={`o${sai || loiTai ? ' sai' : ''}`}>
      <span className="o-nhan">{o.nhan}</span>
      <div className={`o-anh${v ? ' co' : ''}`}>
        {v ? (
          <span className="o-anh-xem"><img src={linkAnh(v, theme)} alt="" /></span>
        ) : (
          <button type="button" className="o-anh-xem trong" onClick={() => chon.current?.click()} disabled={dang}>
            <i className="fa-regular fa-image" /><span>{dang ? 'Đang tải…' : 'Bấm để chọn ảnh'}</span>
          </button>
        )}
        {v.startsWith('mau:') && <small className="o-anh-mau">Ảnh mẫu của theme</small>}
        {v && (
          <span className="o-anh-nut">
            <button className="btn" type="button" disabled={dang} onClick={() => chon.current?.click()}>
              <i className="fa-solid fa-upload" />{dang ? 'Đang tải…' : 'Đổi ảnh'}
            </button>
            <button className="btn ghost-danger" type="button" onClick={() => doi('')}>Bỏ</button>
          </span>
        )}
        <input ref={chon} type="file" hidden accept="image/png,image/jpeg,image/webp,image/gif" onChange={(e) => e.target.files?.[0] && tai(e.target.files[0])} />
      </div>
      {loiTai ? <small className="o-loi">{loiTai}</small> : duoi}
    </div>
  );
}

// Dòng tên + dòng phụ của một mục trong danh sách: lấy từ các ô chữ đầu tiên có giá trị.
const CHU_CHINH = ['tieu_de', 'noi_dung', 'chu', 'so', 'ten'];
const CHU_PHU = ['nhan', 'nho', 'mo_ta', 'gia'];

function ODanhSach({ o, v, doi, loi, duong, nhom, mau }: { o: OKhai; v: GiaTri[]; doi: (v: unknown) => void; loi: Record<string, string>; duong: string; nhom: NhomChon[]; mau: string[] }) {
  const [mo, setMo] = useState(0);
  const theme = useContext(ThemeDangSua);
  const oAnh = o.o!.find((x) => x.loai === 'anh')?.khoa;
  const doiCho = (i: number, j: number) => {
    const ds = [...v];
    [ds[i], ds[j]] = [ds[j], ds[i]];
    doi(ds);
    setMo(j);
  };
  return (
    <div className="o o-ds-vung">
      {loi[duong] && <small className="o-loi">{loi[duong]}</small>}
      <div className="o-ds">
        {v.map((muc, i) => {
          const chinh = CHU_CHINH.map((k) => muc[k]).find(Boolean);
          const phu = CHU_PHU.map((k) => muc[k]).find((x) => x && x !== chinh);
          return (
            <div key={i} className={`o-ds-muc${mo === i ? ' mo' : ''}`}>
              <div className="o-ds-dau" onClick={() => setMo(mo === i ? -1 : i)}>
                {oAnh && (muc[oAnh] ? <img src={linkAnh(muc[oAnh], theme)} alt="" /> : <span className="o-ds-anh-trong"><i className="fa-regular fa-image" /></span>)}
                {!oAnh && <span className="o-ds-so">{i + 1}</span>}
                <span className="o-ds-ten">
                  <b>{chinh ? String(chinh) : `${o.ten_muc} ${i + 1}`}</b>
                  {phu && <small>{String(phu)}</small>}
                </span>
                <span className="o-ds-nut" onClick={(e) => e.stopPropagation()}>
                  <button type="button" className="ibtn nho" title="Lên" disabled={i === 0} onClick={() => doiCho(i, i - 1)}><i className="fa-solid fa-arrow-up" /></button>
                  <button type="button" className="ibtn nho" title="Xuống" disabled={i === v.length - 1} onClick={() => doiCho(i, i + 1)}><i className="fa-solid fa-arrow-down" /></button>
                  <button type="button" className="ibtn nho xoa" title="Xoá mục" onClick={() => doi(v.filter((_, j) => j !== i))}><i className="fa-regular fa-trash-can" /></button>
                </span>
              </div>
              {mo === i && (
                <div className="o-ds-than">
                  <BoO khai={o.o!} gt={muc} doi={(m) => doi(v.map((x, j) => (j === i ? m : x)))} loi={loi} duong={`${duong}.${i}`} nhom={nhom} mau={mau} />
                </div>
              )}
            </div>
          );
        })}
      </div>
      {v.length < (o.toi_da ?? 0) && (
        <button type="button" className="them-muc" onClick={() => { doi([...v, giaTriMacDinh(o.o!)]); setMo(v.length); }}>
          <i className="fa-solid fa-plus" /> Thêm {o.ten_muc?.toLowerCase()}
        </button>
      )}
    </div>
  );
}
