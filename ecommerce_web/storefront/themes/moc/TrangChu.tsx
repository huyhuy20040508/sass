import { tien } from '@/lib/dinh-dang';
import { shopApi } from '@/lib/shop-api';
import type { Hang, Khoi, NguCanh, O } from '../kieu';
import { DemNguoc, SlideChu, TabHang } from './TuongTac';

// Theme "Mộc" (thời trang, mỹ phẩm, quà tặng) — bản thiết kế đã duyệt 28/10.
// Khối nào cũng có ở theme.json; PHP kiểm dữ liệu theo đó nên ở đây tin được kiểu.

const linkNhom = (slug: string) => `/danh-muc/${encodeURIComponent(slug)}`;
const layHang = async (nc: NguCanh, q: Record<string, string>) =>
  ((await shopApi<Hang[]>(`/hang?${new URLSearchParams(q)}`, nc.host).catch(() => null))?.data ?? []);

export default function TrangChu({ khoi, nc }: { khoi: Khoi[]; nc: NguCanh }) {
  const hangDau = khoi.find((k) => k.khoi === 'hang-hoa')?.id;
  const gioiThieu = khoi.find((k) => k.khoi === 'gioi-thieu')?.id;
  return (
    <>
      {khoi.map((k) => (
        <section
          key={k.id}
          id={k.id === hangDau ? 'san-pham' : k.id === gioiThieu ? 'gioi-thieu' : undefined}
          className={`mc mc-${k.khoi}`}
          data-khoi={k.id}
        >
          <MotKhoi k={k} nc={nc} />
        </section>
      ))}
      {khoi.length === 0 && nc.xemTruoc && <p className="mc-trong">Trang chủ chưa có khối nào — bấm “Thêm nội dung”.</p>}
    </>
  );
}

function TieuDeMuc({ nho, tieuDe, phai }: { nho?: string; tieuDe?: string; phai?: React.ReactNode }) {
  if (!nho && !tieuDe && !phai) return null;
  return (
    <div className="mc-dau-muc">
      <div>
        {nho && <small>{nho}</small>}
        {tieuDe && <h2>{tieuDe}</h2>}
      </div>
      {phai}
    </div>
  );
}

async function MotKhoi({ k, nc }: { k: Khoi; nc: NguCanh }) {
  const o = k.o;
  switch (k.khoi) {
    case 'banner-top': {
      const ds = (o.thong_bao as O[]).filter((t) => t.noi_dung);
      if (!ds.length) return nc.xemTruoc ? <div className="mc-bar mo">Banner top chưa có nội dung</div> : null;
      // Nhân đủ dài để dải chữ chạy liền, không hở ở màn rộng.
      const lap = Array.from({ length: Math.max(2, Math.ceil(8 / ds.length)) }, () => ds).flat();
      return (
        <div className="mc-bar" style={o.mau_nen ? { background: o.mau_nen } : undefined}>
          <div className="mc-chay">
            {[...lap, ...lap].map((t, i) => <span key={i}><i className={`fa-solid ${t.icon}`} />{t.noi_dung}</span>)}
          </div>
        </div>
      );
    }

    case 'banner-chinh': {
      // Ảnh nào để trống thì lấy ảnh hàng mới nhất của POS — mở web là có ảnh đẹp ngay.
      const thieu = [1, 2, 3].some((i) => !o[`anh_${i}`]);
      const moi = thieu ? (await layHang(nc, { nguon: 'moi', so: '6' })).filter((h) => h.anh) : [];
      const anh = [1, 2, 3].map((i, j) => {
        const h = moi[j];
        return { src: (o[`anh_${i}`] as string) || h?.anh || '', chu: (o[`chu_anh_${i}`] as string) || h?.ten || '', gia: !o[`anh_${i}`] && h ? tien(h.gia) : '' };
      });
      return (
        <div className="mc-hero">
          <div className="mc-wrap">
            <div className="mc-hero-chu">
              <SlideChu ds={(o.slide as O[]).filter((s) => s.tieu_de)} />
              <div className="mc-hang-nut">
                {o.nut_chu && o.nut_link && <a className="mc-nut" href={o.nut_link}>{o.nut_chu} <i className="fa-solid fa-arrow-right" /></a>}
                {o.nut2_chu && o.nut2_link && <a className="mc-nut vien" href={o.nut2_link}>{o.nut2_chu}</a>}
              </div>
              {(o.so_lieu as O[]).some((s) => s.so) && (
                <div className="mc-so">
                  {(o.so_lieu as O[]).filter((s) => s.so).map((s, i) => <div key={i}><b>{s.so}</b><span>{s.nhan}</span></div>)}
                </div>
              )}
            </div>
            <div className="mc-hero-anh">
              {[1, 0, 2].filter((j) => anh[j].src || nc.xemTruoc).map((j) => (
                <figure key={j} className={`a${j + 1}`}>
                  {anh[j].src ? <img src={anh[j].src} alt={anh[j].chu} /> : <span className="mc-cho-anh"><i className="fa-regular fa-image" /></span>}
                  {(anh[j].chu || anh[j].gia) && <figcaption><span>{anh[j].chu}</span>{anh[j].gia && <b>{anh[j].gia}</b>}</figcaption>}
                </figure>
              ))}
              {o.noi_tieu_de && (
                <div className="mc-nhan-noi"><i className="fa-solid fa-truck-fast" /><div><b>{o.noi_tieu_de}</b>{o.noi_mo_ta && <small>{o.noi_mo_ta}</small>}</div></div>
              )}
            </div>
          </div>
          <svg className="mc-mep" viewBox="0 0 1440 60" preserveAspectRatio="none" aria-hidden="true">
            <path fill="currentColor" d="M0 34 C 120 8, 240 52, 360 30 S 600 6, 720 30 S 960 56, 1080 28 S 1320 4, 1440 30 V60 H0Z" />
          </svg>
        </div>
      );
    }

    case 'dich-vu': {
      const ds = (o.muc as O[]).filter((m) => m.tieu_de || m.mo_ta);
      if (!ds.length) return null;
      return (
        <div className="mc-wrap mc-dv">
          {ds.map((m, i) => (
            <div key={i}><i className={`fa-solid ${m.icon}`} /><div><b>{m.tieu_de}</b>{m.mo_ta && <span>{m.mo_ta}</span>}</div></div>
          ))}
        </div>
      );
    }

    case 'hang-hoa': {
      const so = String(o.so_luong);
      const tab = o.hien_tab
        ? [
            { ma: 'moi', ten: 'Mới về', ds: await layHang(nc, { nguon: 'moi', so }) },
            { ma: 'ban_chay', ten: 'Bán chạy', ds: await layHang(nc, { nguon: 'ban_chay', so }) },
            { ma: 'giam', ten: 'Đang giảm giá', ds: await layHang(nc, { nguon: 'giam', so }) },
          ].filter((t, i) => i === 0 || t.ds.length)
        : [{ ma: o.nguon, ten: '', ds: await layHang(nc, { nguon: o.nguon, so, ...(o.nguon === 'nhom' ? { nhom: String(o.nhom) } : {}) }) }];
      if (!tab.some((t) => t.ds.length) && !nc.xemTruoc) return null;
      return (
        <div className="mc-wrap">
          <TabHang tieuDe={<TieuDeMuc nho={o.nho} tieuDe={o.tieu_de} />} tab={tab.map((t) => ({ ma: t.ma, ten: t.ten, luoi: <LuoiHang ds={t.ds} /> }))} />
          {o.nut_chu && o.nut_link && <div className="mc-giua"><a className="mc-nut vien" href={o.nut_link}>{o.nut_chu} <i className="fa-solid fa-arrow-right" /></a></div>}
        </div>
      );
    }

    case 'nhom-hang': {
      const phang = nc.nhom.flatMap(function di(n): typeof nc.nhom { return [n, ...n.con.flatMap(di)]; });
      const chon = (o.muc as O[]).map((m) => ({ n: phang.find((x) => x.id === m.nhom), anh: m.anh as string })).filter((m) => m.n);
      const ds = chon.length ? chon : nc.nhom.slice(0, 6).map((n) => ({ n, anh: '' }));
      if (!ds.length) return nc.xemTruoc ? <p className="mc-wrap mc-trong">Chưa có nhóm hàng — kết nối POS để hiện.</p> : null;
      return (
        <div className="mc-wrap">
          <TieuDeMuc nho={o.nho} tieuDe={o.tieu_de} />
          <div className="mc-nhom">
            {ds.map(({ n, anh }) => (
              <a key={n!.id} href={linkNhom(n!.slug)}>
                <span className="tron">{anh ? <img src={anh} alt={n!.ten} loading="lazy" /> : <em>{n!.ten.slice(0, 1).toUpperCase()}</em>}</span>
                <b>{n!.ten}</b>
                {n!.con.length > 0 && <small>{n!.con.length} nhóm con</small>}
              </a>
            ))}
          </div>
        </div>
      );
    }

    case 'banner-phu': {
      const the = [1, 2]
        .map((i) => ({ i, anh: o[`anh_${i}`] as string, nhan: o[`the_${i}`] as string, tieuDe: o[`tieu_de_${i}`] as string, moTa: o[`mo_ta_${i}`] as string, nut: o[`nut_${i}`] as string, link: o[`link_${i}`] as string }))
        .filter((t) => t.anh || nc.xemTruoc);
      if (!the.length) return null;
      return (
        <div className={`mc-wrap mc-phu${the.length === 1 ? ' mot' : ''}`}>
          {the.map((t) => {
            const ben = (
              <>
                {t.anh ? <img src={t.anh} alt="" loading="lazy" /> : <span className="mc-cho-anh"><i className="fa-regular fa-image" /> Chưa chọn ảnh {t.i}</span>}
                {t.nhan && <span className={`the${t.i === 2 ? ' phai' : ''}`}>{t.nhan}</span>}
                <div className="noi-dung">
                  {t.i === 1 && o.het_han_1 && <DemNguoc toi={o.het_han_1} />}
                  {t.tieuDe && <h3>{t.tieuDe}</h3>}
                  {t.moTa && <p>{t.moTa}</p>}
                  {t.nut && <span className="mc-nut trang">{t.nut} <i className="fa-solid fa-arrow-right" /></span>}
                </div>
              </>
            );
            return t.link ? <a key={t.i} href={t.link}>{ben}</a> : <div key={t.i}>{ben}</div>;
          })}
        </div>
      );
    }

    case 'gioi-thieu':
      return (
        <div className={`mc-wrap mc-gt${o.anh ? '' : ' khong-anh'}`}>
          {o.anh && (
            <div className="mc-gt-anh">
              <img src={o.anh} alt="" loading="lazy" />
              {o.anh_nho && <img className="nho" src={o.anh_nho} alt="" loading="lazy" />}
              {o.so && <div className="huy"><div><b>{o.so}</b>{o.so_nhan}</div></div>}
            </div>
          )}
          <div>
            <TieuDeMuc nho={o.nho} tieuDe={o.tieu_de} />
            {String(o.noi_dung || '').split(/\n+/).filter(Boolean).map((d: string, i: number) => <p key={i}>{d}</p>)}
            {(o.diem as O[]).some((d) => d.chu) && (
              <ul>{(o.diem as O[]).filter((d) => d.chu).map((d, i) => <li key={i}><i className="fa-solid fa-check" />{d.chu}</li>)}</ul>
            )}
            {o.nut_chu && o.nut_link && <a className="mc-nut" href={o.nut_link}>{o.nut_chu} <i className="fa-solid fa-arrow-right" /></a>}
          </div>
        </div>
      );
  }
  return null;
}

function LuoiHang({ ds }: { ds: Hang[] }) {
  if (!ds.length) return <p className="mc-trong">Chưa có hàng để hiện.</p>;
  return (
    <div className="mc-luoi">
      {ds.map((h, i) => (
        <a key={h.id} className="mc-sp" href={`/san-pham/${encodeURIComponent(h.slug)}`} style={{ animationDelay: `${i * 0.05}s` }}>
          <span className="anh">
            {h.anh ? <img src={h.anh} alt={h.ten} loading="lazy" /> : <i className="fa-regular fa-image" />}
            <span className="nhan">
              {h.gia_cu > h.gia && <em className="giam">-{Math.round((1 - h.gia / h.gia_cu) * 100)}%</em>}
              {h.het_hang && <em>Hết hàng</em>}
            </span>
            <span className="mua"><i className="fa-solid fa-bag-shopping" /> Chọn mua</span>
          </span>
          <span className="ten">{h.ten}</span>
          <span className="gia"><b>{h.nhieu_gia ? 'Từ ' : ''}{tien(h.gia)}</b>{h.gia_cu > h.gia && <s>{tien(h.gia_cu)}</s>}</span>
        </a>
      ))}
    </div>
  );
}
