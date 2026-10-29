import { shopApi } from '@/lib/shop-api';
import { tien } from '@/lib/dinh-dang';
import type { Hang, Khoi, NguCanh, O } from '../kieu';

// Theme "Mặc định": mỗi khối trang chủ một component. Khuôn ô của từng khối ở theme.json
// (PHP kiểm dữ liệu theo đó trước khi lưu, nên ở đây tin được kiểu dữ liệu).


const linkNhom = (slug: string) => `/danh-muc/${encodeURIComponent(slug)}`;

export default function TrangChu({ khoi, nc }: { khoi: Khoi[]; nc: NguCanh }) {
  const hangDau = khoi.find((k) => k.khoi === 'hang-hoa')?.id;
  return (
    <>
      {khoi.map((k) => (
        <section key={k.id} id={k.id === hangDau ? 'san-pham' : undefined} className={`tk tk-${k.khoi}`} data-khoi={k.id}>
          <MotKhoi k={k} nc={nc} />
        </section>
      ))}
      {khoi.length === 0 && nc.xemTruoc && <p className="tk-trong">Trang chủ chưa có khối nào — bấm “Thêm nội dung”.</p>}
    </>
  );
}

function MotKhoi({ k, nc }: { k: Khoi; nc: NguCanh }) {
  const o = k.o;
  switch (k.khoi) {
    case 'banner-top': {
      const chu = <span className={o.chay ? 'chay' : undefined}>{o.noi_dung}</span>;
      const style = o.mau_nen ? { background: o.mau_nen } : undefined;
      if (!o.noi_dung) return nc.xemTruoc ? <div className="tk-bar mo">Banner top chưa có nội dung</div> : null;
      return o.link ? <a className="tk-bar" style={style} href={o.link}>{chu}</a> : <div className="tk-bar" style={style}>{chu}</div>;
    }
    case 'banner-chinh':
      return (
        <div
          className={`tk-hero canh-${o.canh}${o.anh_nen ? ' co-anh' : ''}`}
          style={o.anh_nen ? { backgroundImage: `linear-gradient(rgba(0,0,0,${o.do_phu / 100}), rgba(0,0,0,${o.do_phu / 100})), url(${o.anh_nen})` } : undefined}
        >
          <div className="sf-wrap">
            {o.tieu_de && <h1>{o.tieu_de}</h1>}
            {o.mo_ta && <p>{o.mo_ta}</p>}
            {o.nut_chu && o.nut_link && <a className="tk-nut" href={o.nut_link}>{o.nut_chu}</a>}
          </div>
        </div>
      );
    case 'dich-vu':
      return (
        <div className={`sf-wrap tk-dv nen-${o.nen}`}>
          {(o.muc as O[]).filter((m) => m.tieu_de || m.mo_ta).map((m, i) => (
            <div key={i} className="tk-dv-muc">
              <i className={`fa-solid ${m.icon}`} />
              <div>
                <b>{m.tieu_de}</b>
                {m.mo_ta && <span>{m.mo_ta}</span>}
              </div>
            </div>
          ))}
        </div>
      );
    case 'hang-hoa':
      return <LuoiHang o={o} nc={nc} />;
    case 'nhom-hang':
      if (!nc.nhom.length) return nc.xemTruoc ? <p className="sf-wrap tk-trong">Chưa có nhóm hàng — kết nối POS để hiện.</p> : null;
      return (
        <div className="sf-wrap">
          {o.tieu_de && <h2 className="tk-h2">{o.tieu_de}</h2>}
          <div className="tk-nhom">
            {nc.nhom.slice(0, 8).map((n) => (
              <a key={n.id} href={linkNhom(n.slug)}>
                <span>{n.ten.slice(0, 1).toUpperCase()}</span>
                <b>{n.ten}</b>
                {n.con.length > 0 && <small>{n.con.length} nhóm con</small>}
              </a>
            ))}
          </div>
        </div>
      );
    case 'gioi-thieu':
      return (
        <div className={`sf-wrap tk-gt${o.anh ? '' : ' khong-anh'}`}>
          {o.anh && <img src={o.anh} alt={o.tieu_de || ''} loading="lazy" />}
          <div>
            {o.tieu_de && <h2 className="tk-h2">{o.tieu_de}</h2>}
            {String(o.noi_dung || '').split(/\n+/).map((d: string, i: number) => <p key={i}>{d}</p>)}
            {o.nut_chu && o.nut_link && <a className="tk-nut" href={o.nut_link}>{o.nut_chu}</a>}
          </div>
        </div>
      );
    case 'banner-phu': {
      const anh = [1, 2].map((i) => ({ anh: o[`anh_${i}`] as string, link: o[`link_${i}`] as string })).filter((a) => a.anh || nc.xemTruoc);
      if (!anh.length) return null;
      return (
        <div className="sf-wrap tk-phu">
          {anh.map((a, i) => {
            const the = a.anh ? <img src={a.anh} alt="" loading="lazy" /> : <span className="tk-cho-anh"><i className="fa-regular fa-image" /> Chưa chọn ảnh {i + 1}</span>;
            return a.link ? <a key={i} href={a.link}>{the}</a> : <div key={i}>{the}</div>;
          })}
        </div>
      );
    }
  }
  return null;
}

async function LuoiHang({ o, nc }: { o: O; nc: NguCanh }) {
  const q = new URLSearchParams({ nguon: o.nguon, so: o.so_luong, ...(o.nguon === 'nhom' ? { nhom: String(o.nhom) } : {}) });
  const r = await shopApi<Hang[]>(`/hang?${q}`, nc.host).catch(() => null);
  const ds = r?.data ?? [];
  if (!ds.length && !nc.xemTruoc) return null;
  return (
    <div className="sf-wrap">
      {o.tieu_de && <h2 className="tk-h2">{o.tieu_de}</h2>}
      {ds.length === 0 ? (
        <p className="tk-trong">Chưa có hàng để hiện{o.nguon === 'nhom' ? ' trong nhóm này' : ''}.</p>
      ) : (
        <div className="tk-luoi">
          {ds.map((h) => (
            <a key={h.id} className="tk-sp" href={`/san-pham/${encodeURIComponent(h.slug)}`}>
              <span className="tk-sp-anh">
                {h.anh ? <img src={h.anh} alt={h.ten} loading="lazy" /> : <i className="fa-regular fa-image" />}
                {h.het_hang && <em>Hết hàng</em>}
              </span>
              <span className="tk-sp-ten">{h.ten}</span>
              <b>{h.nhieu_gia ? 'Từ ' : ''}{tien(h.gia)}</b>
            </a>
          ))}
        </div>
      )}
    </div>
  );
}
