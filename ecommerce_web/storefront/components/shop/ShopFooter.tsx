import { lienKetZalo, type CaiDat, type GiaoDien, type NhomHang } from '@/lib/khung';

const MANG_XA_HOI = [
  { khoa: 'facebook', icon: 'fa-facebook-f', ten: 'Facebook' },
  { khoa: 'instagram', icon: 'fa-instagram', ten: 'Instagram' },
  { khoa: 'tiktok', icon: 'fa-tiktok', ten: 'TikTok' },
  { khoa: 'youtube', icon: 'fa-youtube', ten: 'YouTube' },
] as const;

// Chân trang dùng chung mọi theme. Cột nào shop chưa nhập gì thì ẩn, không để khung trống.
export default function ShopFooter({ cd, gd, nhom }: { cd: CaiDat; gd: GiaoDien['chan_trang']; nhom: NhomHang[] }) {
  const mxh = MANG_XA_HOI.filter((m) => cd[m.khoa]);
  const coLienHe = cd.dia_chi || cd.hotline || cd.zalo || cd.email || cd.gio_mo_cua;

  return (
    <footer className="sf-foot" data-khoi="chan-trang">
      <div className="sf-wrap sf-foot__cot">
        <section>
          <a href="/" className="sf-foot__ten">
            {cd.logo ? <img src={cd.logo} alt={cd.ten_hien_thi} /> : cd.ten_hien_thi}
          </a>
          {cd.gioi_thieu_ngan && <p>{cd.gioi_thieu_ngan}</p>}
          {mxh.length > 0 && (
            <div className="sf-mxh">
              {mxh.map((m) => (
                <a key={m.khoa} href={cd[m.khoa]} target="_blank" rel="noopener noreferrer nofollow" aria-label={m.ten}>
                  <i className={`fa-brands ${m.icon}`} />
                </a>
              ))}
            </div>
          )}
        </section>

        {coLienHe && (
          <section>
            <h3>Liên hệ</h3>
            <ul className="sf-foot__lh">
              {cd.dia_chi && <li><i className="fa-solid fa-location-dot" /><span>{cd.dia_chi}</span></li>}
              {cd.hotline && <li><i className="fa-solid fa-phone" /><a href={`tel:${cd.hotline}`}>{cd.hotline}</a></li>}
              {cd.zalo && (
                <li><i className="fa-solid fa-comment-dots" /><a href={lienKetZalo(cd.zalo)} target="_blank" rel="noopener noreferrer">Zalo {cd.zalo}</a></li>
              )}
              {cd.email && <li><i className="fa-solid fa-envelope" /><a href={`mailto:${cd.email}`}>{cd.email}</a></li>}
              {cd.gio_mo_cua && <li><i className="fa-solid fa-clock" /><span>{cd.gio_mo_cua}</span></li>}
            </ul>
          </section>
        )}

        {gd.hien_danh_muc && nhom.length > 0 && (
          <section>
            <h3>Danh mục</h3>
            <ul>
              {nhom.slice(0, 8).map((n) => (
                <li key={n.id}><a href={`/danh-muc/${encodeURIComponent(n.slug)}`}>{n.ten}</a></li>
              ))}
            </ul>
          </section>
        )}
      </div>
      <div className="sf-foot__cuoi">
        <div className="sf-wrap">{gd.ban_quyen || `© ${new Date().getFullYear()} ${cd.ten_hien_thi}`}</div>
      </div>
    </footer>
  );
}
