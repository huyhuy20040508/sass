import { lienKetZalo } from '@/lib/khung';
import { tien } from '@/lib/dinh-dang';
import { shopApi } from '@/lib/shop-api';
import type { Hang, Khoi, NguCanh, O } from '../kieu';
import { TabHang } from '../moc/TuongTac';
import { SlideAnh } from './TuongTac';

// Theme "Mochi Pet" (thú cưng, cửa hàng dịch vụ) — chuyển từ gói mẫu pet-shop của ordersysvn
// (xanh cổ vịt + vàng bơ, 24/10/2026). Khuôn ô ở theme.json; PHP kiểm dữ liệu theo đó.

const linkNhom = (slug: string) => `/danh-muc/${encodeURIComponent(slug)}`;
const layHang = async (nc: NguCanh, q: Record<string, string>) =>
  ((await shopApi<Hang[]>(`/hang?${new URLSearchParams(q)}`, nc.host).catch(() => null))?.data ?? []);

export default function TrangChu({ khoi, nc }: { khoi: Khoi[]; nc: NguCanh }) {
  const dau: Record<string, string> = {};
  for (const [loai, id] of [['hang-hoa', 'san-pham'], ['dich-vu-gia', 'dich-vu'], ['gioi-thieu', 'gioi-thieu']]) {
    const k = khoi.find((x) => x.khoi === loai);
    if (k) dau[k.id] = id;
  }
  return (
    <>
      {khoi.map((k) => (
        <section key={k.id} id={dau[k.id]} className={`mpk mpk-${k.khoi}`} data-khoi={k.id}>
          <MotKhoi k={k} nc={nc} />
        </section>
      ))}
      {khoi.length === 0 && nc.xemTruoc && <p className="mpk-trong">Trang chủ chưa có khối nào — bấm “Thêm nội dung”.</p>}
    </>
  );
}

function TieuDe({ tieuDe, moTa, xem, link, giua }: { tieuDe?: string; moTa?: string; xem?: string; link?: string; giua?: boolean }) {
  if (!tieuDe && !moTa) return null;
  return (
    <div className={`mpk-tieu-de${giua ? ' giua' : ''}`}>
      <div>
        {tieuDe && <h2>{tieuDe}</h2>}
        {moTa && <p>{moTa}</p>}
      </div>
      {xem && link && <a className="xem" href={link}>{xem} <i className="fa-solid fa-arrow-right" /></a>}
    </div>
  );
}

async function MotKhoi({ k, nc }: { k: Khoi; nc: NguCanh }) {
  const o = k.o;
  // "{ten_shop}" trong chữ shop nhập → tên cửa hàng.
  const t = (s: string) => String(s ?? '').replaceAll('{ten_shop}', nc.cd.ten_hien_thi);

  switch (k.khoi) {
    case 'banner-chinh': {
      const slide = (o.slide as O[]).filter((s) => s.anh || s.tieu_de).map((s) => ({ ...s, tieu_de: t(s.tieu_de), mo_ta: t(s.mo_ta) }));
      const nho = (o.nho as O[]).filter((n) => n.anh || n.tieu_de);
      if (!slide.length && !nho.length) return nc.xemTruoc ? <div className="mpk-wrap"><p className="mpk-trong">Banner chính chưa có ảnh nào.</p></div> : null;
      return (
        <div className="mpk-vung-banner">
          <div className={`mpk-wrap mpk-luoi-banner${nho.length ? '' : ' mot'}`}>
            {slide.length > 0 && <SlideAnh ds={slide} />}
            {nho.length > 0 && (
              <div className="mpk-banner-nho">
                {nho.map((n, i) => {
                  const ben = (
                    <>
                      {n.anh && <img src={n.anh} alt="" />}
                      <div>{n.nho && <small>{t(n.nho)}</small>}<b>{t(n.tieu_de)}</b></div>
                    </>
                  );
                  return n.link ? <a key={i} className="mpk-bn" href={n.link}>{ben}</a> : <div key={i} className="mpk-bn">{ben}</div>;
                })}
              </div>
            )}
          </div>
        </div>
      );
    }

    case 'nhom-hang': {
      const phang = nc.nhom.flatMap(function di(n): typeof nc.nhom { return [n, ...n.con.flatMap(di)]; });
      const chon = (o.muc as O[]).map((m) => ({ n: phang.find((x) => x.id === m.nhom), anh: m.anh as string })).filter((m) => m.n);
      const ds = chon.length ? chon : nc.nhom.slice(0, 12).map((n) => ({ n, anh: '' }));
      if (!ds.length) return nc.xemTruoc ? <div className="mpk-wrap"><p className="mpk-trong">Chưa có nhóm hàng — kết nối POS để hiện.</p></div> : null;
      return (
        <div className="mpk-wrap mpk-khoi">
          <TieuDe tieuDe={t(o.tieu_de)} moTa={t(o.mo_ta)} xem={o.xem_chu} link={o.xem_link} />
          <div className="mpk-the-nhom">
            {ds.map(({ n, anh }) => {
              const a = anh || n!.anh;
              return (
                <a key={n!.id} className="mpk-the" href={linkNhom(n!.slug)}>
                  <span className="anh">{a ? <img src={a} alt="" loading="lazy" /> : <i className="fa-solid fa-paw" />}</span>
                  <b>{n!.ten}</b>
                  {n!.con.length > 0 && <small>{n!.con.length} nhóm con</small>}
                </a>
              );
            })}
          </div>
        </div>
      );
    }

    case 'hang-hoa': {
      const so = String(o.so_luong);
      const tab = o.hien_tab
        ? [
            { ma: 'ban_chay', ten: 'Nổi bật', ds: await layHang(nc, { nguon: 'ban_chay', so }) },
            { ma: 'moi', ten: 'Mới về', ds: await layHang(nc, { nguon: 'moi', so }) },
            { ma: 'giam', ten: 'Giảm giá', ds: await layHang(nc, { nguon: 'giam', so }) },
          ].filter((x, i) => i === 0 || x.ds.length)
        : [{ ma: o.nguon, ten: '', ds: await layHang(nc, { nguon: o.nguon, so, ...(o.nguon === 'nhom' ? { nhom: String(o.nhom) } : {}) }) }];
      if (!tab.some((x) => x.ds.length) && !nc.xemTruoc) return null;
      return (
        <div className="mpk-kem">
          <div className="mpk-wrap mpk-khoi">
            <TabHang tieuDe={<TieuDe tieuDe={t(o.tieu_de)} moTa={t(o.mo_ta)} />} tab={tab.map((x) => ({ ma: x.ma, ten: x.ten, luoi: <LuoiHang ds={x.ds} /> }))} />
          </div>
        </div>
      );
    }

    case 'dai-chay': {
      const ds = (o.muc as O[]).filter((m) => m.noi_dung);
      if (!ds.length) return nc.xemTruoc ? <div className="mpk-dai-km mo">Dải chữ chạy chưa có nội dung</div> : null;
      const lap = Array.from({ length: Math.max(2, Math.ceil(6 / ds.length)) }, () => ds).flat();
      return (
        <div className="mpk-dai-km" style={o.mau_nen ? { background: o.mau_nen } : undefined}>
          <div className="mpk-chay">{[...lap, ...lap].map((m, i) => <span key={i}><i className={`fa-solid ${m.icon}`} />{t(m.noi_dung)}</span>)}</div>
        </div>
      );
    }

    case 'banner-phu': {
      const the = [1, 2]
        .map((i) => ({ i, anh: o[`anh_${i}`] as string, tieuDe: t(o[`tieu_de_${i}`]), moTa: t(o[`mo_ta_${i}`]), nut: o[`nut_${i}`] as string, link: o[`link_${i}`] as string }))
        .filter((x) => x.anh || x.tieuDe);
      if (!the.length) return null;
      return (
        <div className={`mpk-wrap mpk-khoi mpk-luoi-phu${the.length === 1 ? ' mot' : ''}`}>
          {the.map((x) => (
            <div key={x.i} className={`mpk-phu ${x.i === 1 ? 'nen-bo' : 'nen-chinh'}`}>
              {x.anh && <img src={x.anh} alt="" loading="lazy" />}
              <div>
                {x.tieuDe && <h3>{x.tieuDe}</h3>}
                {x.moTa && <p>{x.moTa}</p>}
                {x.nut && x.link && <a className={`mpk-nut mpk-nut-nho ${x.i === 1 ? 'mpk-nut-chinh' : 'mpk-nut-bo'}`} href={x.link}>{x.nut}</a>}
              </div>
            </div>
          ))}
        </div>
      );
    }

    case 'dich-vu-gia': {
      const ds = (o.muc as O[]).filter((m) => m.tieu_de);
      if (!ds.length) return null;
      return (
        <div className="mpk-kem">
          <div className="mpk-wrap mpk-khoi">
            <TieuDe tieuDe={t(o.tieu_de)} moTa={t(o.mo_ta)} xem={o.xem_chu} link={o.xem_link} />
            <div className="mpk-luoi-dv">
              {ds.map((m, i) => (
                <article key={i} className="mpk-dv">
                  <div className="anh">{m.anh ? <img src={m.anh} alt={m.tieu_de} loading="lazy" /> : <i className="fa-solid fa-paw" />}</div>
                  <div className="than">
                    <h3>{t(m.tieu_de)}</h3>
                    {m.mo_ta && <p>{t(m.mo_ta)}</p>}
                    <div className="chan">
                      {m.gia ? <span className="tu">từ <b>{m.gia}</b>{m.don_vi}</span> : <span />}
                      {m.nut_chu && m.link && <a className="mpk-nut mpk-nut-vien mpk-nut-nho" href={m.link}>{m.nut_chu}</a>}
                    </div>
                  </div>
                </article>
              ))}
            </div>
          </div>
        </div>
      );
    }

    case 'dich-vu': {
      const ds = (o.muc as O[]).filter((m) => m.tieu_de || m.mo_ta);
      if (!ds.length) return null;
      return (
        <div className="mpk-wrap mpk-khoi">
          <TieuDe tieuDe={t(o.tieu_de)} giua />
          <div className="mpk-luoi-ck">
            {ds.map((m, i) => <div key={i} className="mpk-ck"><i className={`fa-solid ${m.icon}`} /><b>{t(m.tieu_de)}</b>{m.mo_ta && <p>{t(m.mo_ta)}</p>}</div>)}
          </div>
        </div>
      );
    }

    case 'loi-moi': {
      const zalo = o.hien_zalo && nc.cd.zalo;
      const goi = o.hien_goi && nc.cd.hotline;
      return (
        <div className="mpk-wrap mpk-khoi mpk-khoi-sat">
          <div className="mpk-dang-ky">
            <div>
              {o.tieu_de && <h3>{t(o.tieu_de)}</h3>}
              {o.mo_ta && <p>{t(o.mo_ta)}</p>}
            </div>
            {(zalo || goi) && (
              <div className="mpk-nut-lh">
                {zalo && <a className="mpk-nut mpk-nut-chinh" href={lienKetZalo(nc.cd.zalo)} target="_blank" rel="noopener noreferrer"><i className="fa-solid fa-comment-dots" /> Nhắn Zalo</a>}
                {goi && <a className="mpk-nut mpk-nut-vien" href={`tel:${nc.cd.hotline}`}><i className="fa-solid fa-phone" /> {nc.cd.hotline}</a>}
              </div>
            )}
          </div>
        </div>
      );
    }

    case 'gioi-thieu':
      return (
        <div className={`mpk-wrap mpk-khoi mpk-hai-cot${o.anh ? '' : ' mot'}`}>
          {o.anh && <div className="mpk-anh-bo"><img src={o.anh} alt="" loading="lazy" /></div>}
          <div className="mpk-van">
            {o.tieu_de && <h2>{t(o.tieu_de)}</h2>}
            {String(o.noi_dung || '').split(/\n+/).filter(Boolean).map((d: string, i: number) => <p key={i}>{t(d)}</p>)}
            {o.nut_chu && o.nut_link && <a className="mpk-nut mpk-nut-bo" href={o.nut_link}>{o.nut_chu}</a>}
          </div>
        </div>
      );
  }
  return null;
}

function LuoiHang({ ds }: { ds: Hang[] }) {
  if (!ds.length) return <p className="mpk-trong">Chưa có hàng để hiện.</p>;
  return (
    <div className="mpk-luoi-sp">
      {ds.map((h) => (
        <a key={h.id} className="mpk-sp" href={`/san-pham/${encodeURIComponent(h.slug)}`}>
          <span className="anh">
            {h.anh ? <img src={h.anh} alt={h.ten} loading="lazy" /> : <span className="chua-anh"><i className="fa-regular fa-image" /><small>Chưa có ảnh</small></span>}
            {h.gia_cu > h.gia ? <em className="giam">-{Math.round((1 - h.gia / h.gia_cu) * 100)}%</em> : h.het_hang ? <em className="het">Hết hàng</em> : null}
          </span>
          <span className="than">
            <span className="ten">{h.ten}</span>
            <span className="gia"><b>{h.nhieu_gia ? 'Từ ' : ''}{tien(h.gia)}</b>{h.gia_cu > h.gia && <s>{tien(h.gia_cu)}</s>}</span>
            <span className="mpk-nut mpk-nut-bo mpk-nut-nho"><i className="fa-solid fa-bag-shopping" /> Chọn mua</span>
          </span>
        </a>
      ))}
    </div>
  );
}
