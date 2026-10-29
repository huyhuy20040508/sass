import type { CaiDat, GiaoDien, NhomHang } from '@/lib/khung';
import GioHangNut from './GioHangNut';
import MenuDiDong from './MenuDiDong';

const linkNhom = (n: NhomHang) => `/danh-muc/${encodeURIComponent(n.slug)}`;

// Đầu trang dùng chung mọi theme: shop chỉ đổi nội dung (logo, hotline, thông báo) và màu.
// Tuỳ chọn hiện/ẩn menu, hotline chỉnh ở khối Đầu trang của màn Chỉnh sửa giao diện.
export default function ShopHeader({ cd, gd, nhom, chiNhanh }: { cd: CaiDat; gd: GiaoDien['dau_trang']; nhom: NhomHang[]; chiNhanh: string }) {
  return (
    <>
    {gd.dai_tren && (
      <div className="sf-dai-tren" data-khoi="dau-trang">
        <div className="sf-wrap">
          <span>{gd.dai_tren}</span>
          {gd.dai_tren_lh && (cd.hotline || cd.gio_mo_cua) && (
            <span className="phai">{cd.hotline && <>Hotline <b>{cd.hotline}</b></>}{cd.hotline && cd.gio_mo_cua && ' · '}{cd.gio_mo_cua}</span>
          )}
        </div>
      </div>
    )}
    <header className="sf-head" data-khoi="dau-trang">

      <div className="sf-wrap sf-head__main">
        <MenuDiDong cd={cd} nhom={nhom} />
        <a href="/" className="sf-logo" aria-label={cd.ten_hien_thi}>
          {cd.logo ? <img src={cd.logo} alt={cd.ten_hien_thi} /> : <span>{cd.ten_hien_thi}</span>}
        </a>

        <form className="sf-search" action="/tim-kiem" role="search">
          <input name="q" type="search" placeholder="Tìm sản phẩm…" aria-label="Tìm sản phẩm" maxLength={100} />
          <button type="submit" aria-label="Tìm">
            <i className="fa-solid fa-magnifying-glass" />
          </button>
        </form>

        {chiNhanh && (
          <span className="sf-cn">
            <i className="fa-solid fa-store" />
            Chi nhánh {chiNhanh}
          </span>
        )}
        {gd.hien_hotline && cd.hotline && (
          <a className="sf-hotline" href={`tel:${cd.hotline}`}>
            <i className="fa-solid fa-phone-volume" />
            <span>
              <small>Hotline</small>
              <b>{cd.hotline}</b>
            </span>
          </a>
        )}
        <GioHangNut />
      </div>

      {gd.hien_menu && nhom.length > 0 && (
        <nav className="sf-nav" aria-label="Danh mục">
          <ul className="sf-wrap">
            <li>
              <a href="/">Trang chủ</a>
            </li>
            {nhom.map((n) => (
              <li key={n.id} className={n.con.length ? 'co-con' : undefined}>
                <a href={linkNhom(n)}>
                  {n.ten}
                  {n.con.length > 0 && <i className="fa-solid fa-chevron-down" />}
                </a>
                {n.con.length > 0 && (
                  <div className="sf-drop">
                    {n.con.map((c) => (
                      <div key={c.id}>
                        <a href={linkNhom(c)} className="cap2">{c.ten}</a>
                        {c.con.map((g) => (
                          <a key={g.id} href={linkNhom(g)} className="cap3">{g.ten}</a>
                        ))}
                      </div>
                    ))}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </nav>
      )}
    </header>
    </>
  );
}
