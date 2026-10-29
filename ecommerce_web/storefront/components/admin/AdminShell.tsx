'use client';

import { createContext, useContext, useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { LOGIN_PATH, adminApi, clearAdminToken, getAdminToken, type AdminSession } from '@/lib/adminAuth';
import { MUC_DON, NHOM_MENU, trangDangXem } from '@/lib/menu';

const AdminContext = createContext<AdminSession | null>(null);

/** Người đang đăng nhập + shop — chỉ dùng được bên trong khung admin. */
export function useAdmin(): AdminSession {
  const s = useContext(AdminContext);
  if (!s) throw new Error('useAdmin chỉ dùng trong AdminShell');
  return s;
}

const duongDanCua = (pathname: string) => pathname.replace(/^\/admin\/?/, '').replace(/\/$/, '');
const href = (duongDan: string) => (duongDan ? `/admin/${duongDan}` : '/admin');

export default function AdminShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const dangXem = duongDanCua(usePathname());
  // Trang con (vd giao-dien/chinh-sua) sáng mục cha trên menu.
  const trang = trangDangXem(dangXem);
  const mucSang = trang.muc?.duongDan ?? dangXem;
  const [session, setSession] = useState<AdminSession | null>(null);
  const [loi, setLoi] = useState('');
  const [drawer, setDrawer] = useState(false);
  const [menuTk, setMenuTk] = useState(false);
  // Chỉ một nhóm bung: nhóm chứa màn đang xem, hoặc nhóm vừa bấm.
  const nhomCuaTrang = NHOM_MENU.findIndex((n) => n.muc.some((m) => m.duongDan === mucSang));
  const [nhomMo, setNhomMo] = useState(nhomCuaTrang);

  useEffect(() => setNhomMo(nhomCuaTrang), [nhomCuaTrang]);
  useEffect(() => setDrawer(false), [dangXem]);

  useEffect(() => {
    if (!getAdminToken()) {
      router.replace(LOGIN_PATH);
      return;
    }
    adminApi<AdminSession>('/admin/me')
      .then(({ data }) => setSession(data))
      // 401 thì adminApi đã chuyển về trang đăng nhập; còn lại là lỗi mạng/máy chủ.
      .catch((e) => e.status !== 401 && setLoi(e.message));
  }, [router]);

  useEffect(() => {
    if (session) document.title = `${trang.ten} — ${session.shop.name}`;
  }, [trang.ten, session]);

  useEffect(() => {
    if (!menuTk) return;
    const dong = (e: MouseEvent) => !(e.target as Element).closest('.user') && setMenuTk(false);
    document.addEventListener('click', dong);
    return () => document.removeEventListener('click', dong);
  }, [menuTk]);

  async function dangXuat() {
    try {
      await adminApi('/admin/logout', { method: 'POST' });
    } catch {
      // Gọi hỏng vẫn quên token ở máy này; token trên máy chủ tự hết hạn.
    }
    clearAdminToken();
    router.replace(LOGIN_PATH);
  }

  if (loi) {
    return (
      <main className="adm-notice">
        <h1>{loi}</h1>
        <p>Tải lại trang để thử lại.</p>
      </main>
    );
  }
  if (!session) return <main className="adm-notice">Đang tải…</main>;

  const ten = session.admin.full_name || session.admin.username;
  const chuDau = ten.split(/\s+/).filter(Boolean).slice(-2).map((w) => w[0]).join('').toUpperCase();

  return (
    <AdminContext.Provider value={session}>
      <div className={drawer ? 'shell drawer' : 'shell'}>
        <aside className="side">
          <div className="brand">
            <div className="mark">{session.shop.name.trim()[0]?.toUpperCase() || 'S'}</div>
            <div>
              <b>{session.shop.name}</b>
              <small>Web bán hàng</small>
            </div>
          </div>
          <nav className="nav">
            {MUC_DON.map((m) => (
              <Link key={m.duongDan} href={href(m.duongDan)} className={`item${m.duongDan === mucSang ? ' on' : ''}`}>
                <i className={`fa-solid ${m.icon}`} />
                <span>{m.ten}</span>
              </Link>
            ))}
            {NHOM_MENU.map((n, i) => (
              <div key={n.ten} className={`grp${nhomMo === i ? ' open' : ''}${nhomCuaTrang === i ? ' active' : ''}`}>
                <button type="button" className="grp-h" onClick={() => setNhomMo(nhomMo === i ? -1 : i)} aria-expanded={nhomMo === i}>
                  <i className={`fa-solid ${n.icon}`} />
                  <span>{n.ten}</span>
                  <i className="fa-solid fa-chevron-down chev" />
                </button>
                <div className="grp-b">
                  <div>
                    {n.muc.map((m) => (
                      <Link key={m.duongDan} href={href(m.duongDan)} className={`item child${m.duongDan === mucSang ? ' on' : ''}`}>
                        <span>{m.ten}</span>
                      </Link>
                    ))}
                  </div>
                </div>
              </div>
            ))}
          </nav>
        </aside>
        <div className="scrim" onClick={() => setDrawer(false)} />

        <div className="main">
          <header className="top">
            <button className="burger" onClick={() => setDrawer(true)} aria-label="Mở menu">
              <i className="fa-solid fa-bars" />
            </button>
            <h1>{trang.ten}</h1>
            <div className="sp" />
            <a className="btn" href="/" target="_blank" rel="noopener">
              <i className="fa-solid fa-arrow-up-right-from-square" />
              <span className="txt">Xem website</span>
            </a>
            <div className="user" onClick={() => setMenuTk(!menuTk)}>
              <span className="ava">{chuDau || 'QT'}</span>
              <span className="nm">{ten}</span>
              <i className="fa-solid fa-chevron-down" />
              <div className={`menu${menuTk ? ' open' : ''}`}>
                <div className="who">
                  <b>{ten}</b>
                  <small>{session.admin.username} · tài khoản POS</small>
                </div>
                <button type="button" onClick={dangXuat}>
                  <i className="fa-solid fa-right-from-bracket" />
                  Đăng xuất
                </button>
              </div>
            </div>
          </header>
          <main className="page">{children}</main>
        </div>
      </div>
    </AdminContext.Provider>
  );
}
