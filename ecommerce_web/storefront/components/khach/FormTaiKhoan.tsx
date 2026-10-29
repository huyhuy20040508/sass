'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api, ApiError } from '@/lib/api';
import { luuToken, type Khach } from '@/lib/khachAuth';

export type CheDo = 'dang-nhap' | 'dang-ky';

/**
 * MỘT form cho cả đăng nhập lẫn đăng ký — chỉ khác số ô hiện ra.
 *
 * Dùng chung là cố ý: hai màn này có cùng ô số điện thoại, cùng cách báo lỗi
 * theo từng ô, cùng chỗ hiện câu lỗi chung. Tách đôi thì mỗi lần sửa cách báo
 * lỗi phải nhớ sửa hai nơi, và chúng lệch nhau lúc nào không biết.
 *
 * Số điện thoại là ô chính ở cả hai chế độ: nó là thứ POS dùng để nhận ra khách
 * quen, nên người đã mua tại quầy đăng ký web vẫn về đúng hồ sơ cũ.
 */
export default function FormTaiKhoan({ cheDo }: { cheDo: CheDo }) {
  const router = useRouter();
  const kyMoi = cheDo === 'dang-ky';
  const [form, setForm] = useState({ full_name: '', phone: '', email: '', password: '' });
  const [hienMatKhau, setHienMatKhau] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [loi, setLoi] = useState('');
  const [dangGui, setDangGui] = useState(false);

  const sua = (o: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => {
    setForm({ ...form, [o]: e.target.value });
    setErrors({ ...errors, [o]: '' });
  };

  async function gui(e: React.FormEvent) {
    e.preventDefault();
    setDangGui(true);
    setLoi('');
    setErrors({});
    try {
      const than = kyMoi ? form : { phone: form.phone, password: form.password };
      const { data } = await api<{ token: string; khach: Khach }>(`/khach/${cheDo}`, { method: 'POST', body: than });
      luuToken(data.token);
      // replace chứ không push: bấm Lùi sau khi đăng nhập không quay lại form.
      router.replace('/');
      router.refresh();
    } catch (err) {
      const e2 = err as ApiError;
      setErrors(e2.errors || {});
      setLoi(e2.message);
      setDangGui(false);
    }
  }

  return (
    <form className="tk-form" onSubmit={gui} noValidate>
      <h1>{kyMoi ? 'Tạo tài khoản' : 'Đăng nhập'}</h1>
      {loi && <p className="tk-loi-chung">{loi}</p>}

      {kyMoi && (
        <label className="tk-o">
          <span>Họ tên</span>
          <input value={form.full_name} onChange={sua('full_name')} autoComplete="name" maxLength={150} />
          {errors.full_name && <em>{errors.full_name}</em>}
        </label>
      )}

      <label className="tk-o">
        <span>Số điện thoại</span>
        <input
          value={form.phone}
          onChange={sua('phone')}
          inputMode="tel"
          autoComplete="tel"
          maxLength={20}
          autoFocus={!kyMoi}
        />
        {errors.phone && <em>{errors.phone}</em>}
      </label>

      {kyMoi && (
        <label className="tk-o">
          <span>
            Email <i>(không bắt buộc)</i>
          </span>
          <input value={form.email} onChange={sua('email')} type="email" autoComplete="email" maxLength={191} />
          {errors.email && <em>{errors.email}</em>}
        </label>
      )}

      <label className="tk-o">
        <span>Mật khẩu</span>
        <span className="tk-mk">
          <input
            value={form.password}
            onChange={sua('password')}
            type={hienMatKhau ? 'text' : 'password'}
            autoComplete={kyMoi ? 'new-password' : 'current-password'}
            maxLength={100}
          />
          <button type="button" onClick={() => setHienMatKhau(!hienMatKhau)} aria-label={hienMatKhau ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}>
            <i className={hienMatKhau ? 'fa fa-eye-slash' : 'fa fa-eye'} aria-hidden="true" />
          </button>
        </span>
        {errors.password && <em>{errors.password}</em>}
      </label>

      <button className="tk-gui" type="submit" disabled={dangGui}>
        {dangGui ? 'Đang gửi…' : kyMoi ? 'Tạo tài khoản' : 'Đăng nhập'}
      </button>

      <p className="tk-doi">
        {kyMoi ? (
          <>
            Đã có tài khoản? <Link href="/dang-nhap">Đăng nhập</Link>
          </>
        ) : (
          <>
            Chưa có tài khoản? <Link href="/dang-ky">Tạo tài khoản</Link>
          </>
        )}
      </p>
    </form>
  );
}
