'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { clearLogoutNotice, getAdminToken, readLogoutNotice, saveAdminToken } from '@/lib/adminAuth';

type Shop = { code: string; name: string };
type TrangThai = { status: 'loading' } | { status: 'error'; message: string } | ({ status: 'ready' } & Shop);

// Ba ô như màn đăng nhập POS, dùng chung tài khoản POS. Mã cửa hàng điền sẵn theo
// địa chỉ web đang mở và không sửa được: web của tiệm nào chỉ đăng nhập tiệm đó.
export default function DangNhap() {
  const router = useRouter();
  const [shop, setShop] = useState<TrangThai>({ status: 'loading' });
  const [form, setForm] = useState({ username: '', password: '' });
  const [showPassword, setShowPassword] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    document.title = 'Đăng nhập quản trị';
    if (getAdminToken()) {
      router.replace('/admin');
      return;
    }
    setMessage(readLogoutNotice());
    clearLogoutNotice();
    api<Shop>('/shop')
      .then(({ data }) => setShop({ status: 'ready', ...data }))
      .catch((e: Error) => setShop({ status: 'error', message: e.message }));
  }, [router]);

  if (shop.status === 'loading') return <main className="adm-notice">Đang tải…</main>;
  if (shop.status === 'error') {
    return (
      <main className="adm-notice">
        <h1>{shop.message}</h1>
        <p>Mở trang quản trị từ địa chỉ web của cửa hàng, vd tencuahang.{window.location.hostname}/admin.</p>
      </main>
    );
  }

  const set = (key: 'username' | 'password') => (e: React.ChangeEvent<HTMLInputElement>) => {
    setForm({ ...form, [key]: e.target.value });
    setErrors({ ...errors, [key]: '' });
  };

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (shop.status !== 'ready') return;
    setBusy(true);
    setMessage('');
    try {
      const { data } = await api<{ token: string }>('/admin/login', { method: 'POST', body: { store_code: shop.code, ...form } });
      saveAdminToken(data.token);
      router.replace('/admin');
    } catch (err) {
      const e2 = err as ApiError;
      setErrors(e2.errors || {});
      setMessage(e2.message);
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
