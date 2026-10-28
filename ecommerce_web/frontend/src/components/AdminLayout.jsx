import { createContext, useContext, useEffect, useState } from 'react';
import { Navigate, Outlet, useNavigate } from 'react-router-dom';
import { LOGIN_PATH, adminApi, clearAdminToken, getAdminToken } from '../lib/adminAuth';

const AdminContext = createContext(null);

/** {admin: {username, full_name}, shop: {code, name}} của người đang đăng nhập (tài khoản POS). */
export const useAdmin = () => useContext(AdminContext);

// Khung chung của mọi trang quản trị: chỉ hiện nội dung khi backend xác nhận phiên còn hạn.
export default function AdminLayout() {
  const navigate = useNavigate();
  const [session, setSession] = useState(null);
  const [error, setError] = useState('');
  const hasToken = Boolean(getAdminToken());

  useEffect(() => {
    if (!hasToken) return;
    adminApi('/admin/me')
      .then(({ data }) => {
        document.title = `Quản trị — ${data.shop.name}`;
        setSession(data);
      })
      // 401 thì adminApi đã chuyển về trang đăng nhập; còn lại là lỗi mạng/máy chủ.
      .catch((e) => e.status !== 401 && setError(e.message));
  }, [hasToken]);

  async function logout() {
    try {
      await adminApi('/admin/logout', { method: 'POST' });
    } catch {
      // Gọi hỏng vẫn quên token ở máy này; token trên máy chủ tự hết hạn.
    }
    clearAdminToken();
    navigate(LOGIN_PATH, { replace: true });
  }

  if (!hasToken) return <Navigate to={LOGIN_PATH} replace />;
  if (error) {
    return (
      <main className="page notice">
        <h1>{error}</h1>
        <p>Tải lại trang để thử lại.</p>
      </main>
    );
  }
  if (!session) return <main className="page notice">Đang tải…</main>;

  return (
    <AdminContext.Provider value={session}>
      <header className="shop-header">
        <div className="page shop-header__inner">
          <span className="shop-header__name">{session.shop.name}</span>
          <div className="admin-user">
            <span className="admin-user__name">{session.admin.full_name || session.admin.username}</span>
            <button type="button" className="btn-link" onClick={logout}>
              Đăng xuất
            </button>
          </div>
        </div>
      </header>
      <Outlet />
    </AdminContext.Provider>
  );
}
