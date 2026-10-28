import { useEffect, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { api } from '../../lib/api';
import { clearLogoutNotice, getAdminToken, readLogoutNotice, saveAdminToken } from '../../lib/adminAuth';

// Ba ô như màn đăng nhập POS, dùng chung tài khoản POS. Mã cửa hàng điền sẵn theo
// địa chỉ web đang mở và không sửa được: web của tiệm nào chỉ đăng nhập tiệm đó.
export default function Login() {
  const navigate = useNavigate();
  const [shop, setShop] = useState({ status: 'loading' });
  const [form, setForm] = useState({ username: '', password: '' });
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(readLogoutNotice);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    document.title = 'Đăng nhập quản trị';
    clearLogoutNotice();
    api('/shop')
      .then(({ data }) => setShop({ status: 'ready', ...data }))
      .catch((e) => setShop({ status: 'error', message: e.message }));
  }, []);

  if (getAdminToken()) return <Navigate to="/admin" replace />;
  if (shop.status === 'loading') return <main className="page notice">Đang tải…</main>;
  if (shop.status === 'error') {
    return (
      <main className="page notice">
        <h1>{shop.message}</h1>
        <p>Mở trang quản trị từ địa chỉ web của cửa hàng, vd tencuahang.{window.location.hostname}/admin.</p>
      </main>
    );
  }

  const set = (key) => (e) => {
    setForm({ ...form, [key]: e.target.value });
    setErrors({ ...errors, [key]: '' });
  };

  async function submit(e) {
    e.preventDefault();
    setBusy(true);
    setMessage('');
    try {
      const { data } = await api('/admin/login', { method: 'POST', body: { store_code: shop.code, ...form } });
      saveAdminToken(data.token);
      navigate('/admin', { replace: true });
    } catch (err) {
      setErrors(err.errors);
      setMessage(err.message);
      setBusy(false);
    }
  }

  return (
    <main className="login">
      <form className="login-box" onSubmit={submit} noValidate>
        <h1>Đăng nhập quản trị</h1>
        <p className="login-hint">{shop.name} · dùng tài khoản phần mềm bán hàng (POS)</p>
        {message && <p className="form-alert">{message}</p>}

        <label className="field">
          <span>Mã cửa hàng</span>
          <input value={shop.code} readOnly tabIndex={-1} />
          {errors.store_code && <small>{errors.store_code}</small>}
        </label>

        <label className="field">
          <span>Tên đăng nhập</span>
          <input
            value={form.username}
            onChange={set('username')}
            autoComplete="username"
            autoCapitalize="none"
            spellCheck={false}
            placeholder="Nhập tên đăng nhập"
            autoFocus
          />
          {errors.username && <small>{errors.username}</small>}
        </label>

        <label className="field">
          <span>Mật khẩu</span>
          <div className="field__password">
            <input
              type={showPassword ? 'text' : 'password'}
              value={form.password}
              onChange={set('password')}
              autoComplete="current-password"
              placeholder="••••••••"
            />
            <button type="button" className="btn-link" onClick={() => setShowPassword(!showPassword)} tabIndex={-1}>
              {showPassword ? 'Ẩn' : 'Hiện'}
            </button>
          </div>
          {errors.password && <small>{errors.password}</small>}
        </label>

        <button type="submit" className="btn-primary" disabled={busy}>
          {busy ? 'Đang đăng nhập…' : 'Đăng nhập'}
        </button>
      </form>
    </main>
  );
}
