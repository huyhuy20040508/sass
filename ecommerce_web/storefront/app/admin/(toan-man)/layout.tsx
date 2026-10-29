'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { adminApi, getAdminToken, LOGIN_PATH, type AdminSession } from '@/lib/adminAuth';
import { PhienToanMan } from '@/components/admin/PhienToanMan';

// Màn toàn màn hình (vd Chỉnh sửa giao diện): không sidebar, không thanh trên của khu quản trị,
// nhưng vẫn phải đăng nhập đúng shop như mọi màn quản trị khác.
export default function ToanMan({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [phien, setPhien] = useState<AdminSession | null>(null);
  const [loi, setLoi] = useState('');

  useEffect(() => {
    if (!getAdminToken()) {
      router.replace(LOGIN_PATH);
      return;
    }
    adminApi<AdminSession>('/admin/me')
      .then(({ data }) => setPhien(data))
      .catch((e) => e.status !== 401 && setLoi(e.message));
  }, [router]);

  if (loi) return <main className="adm-notice"><h1>{loi}</h1></main>;
  if (!phien) return <main className="adm-notice"><p>Đang mở…</p></main>;
  return <PhienToanMan.Provider value={phien}>{children}</PhienToanMan.Provider>;
}
