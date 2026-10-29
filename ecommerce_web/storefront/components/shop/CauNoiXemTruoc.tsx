'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';

/**
 * Chỉ có khi trang khách mở trong khung xem trước của màn Chỉnh sửa giao diện
 * (?xem_truoc=). Nói chuyện với màn chỉnh qua postMessage (cùng tên miền):
 *  - màn chỉnh lưu nháp xong → 'lam-moi': dựng lại trang, giữ nguyên chỗ đang cuộn;
 *  - màn chỉnh chọn khối → 'chon': cuộn tới + viền khối đó;
 *  - khách bấm vào một khối ở đây → báo 'chon-khoi' để màn chỉnh mở form khối đó.
 * Link trong khung bị chặn: khung xem trước chỉ để xem trang chủ đang sửa.
 */
export default function CauNoiXemTruoc() {
  const router = useRouter();

  useEffect(() => {
    document.documentElement.classList.add('xem-truoc');
    const gui = (data: object) => window.parent !== window && window.parent.postMessage({ nguon: 'ecw-xem-truoc', ...data }, window.location.origin);

    const vien = (id: string | null, cuon: boolean) => {
      document.querySelectorAll('.tk-dang-chon').forEach((e) => e.classList.remove('tk-dang-chon'));
      const el = id ? document.querySelector<HTMLElement>(`[data-khoi="${CSS.escape(id)}"]`) : null;
      if (!el) return;
      el.classList.add('tk-dang-chon');
      if (cuon) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    let dangChon: string | null = null;
    const nhan = (e: MessageEvent) => {
      if (e.origin !== window.location.origin || e.data?.nguon !== 'ecw-man-chinh') return;
      if (e.data.loai === 'lam-moi') router.refresh();
      if (e.data.loai === 'chon') {
        dangChon = e.data.id ?? null;
        vien(dangChon, true);
      }
    };
    const bam = (e: MouseEvent) => {
      const t = e.target as HTMLElement;
      if (t.closest('a, button, form')) e.preventDefault();
      const khoi = t.closest<HTMLElement>('[data-khoi]');
      if (khoi) gui({ loai: 'chon-khoi', id: khoi.dataset.khoi });
    };
    const chanGui = (e: Event) => e.preventDefault();

    window.addEventListener('message', nhan);
    document.addEventListener('click', bam, true);
    document.addEventListener('submit', chanGui, true);
    // Dựng lại xong (router.refresh) thì khối đang chọn là phần tử mới — viền lại.
    const quan = new MutationObserver(() => dangChon && !document.querySelector('.tk-dang-chon') && vien(dangChon, false));
    quan.observe(document.body, { childList: true, subtree: true });
    gui({ loai: 'san-sang' });
    return () => {
      window.removeEventListener('message', nhan);
      document.removeEventListener('click', bam, true);
      document.removeEventListener('submit', chanGui, true);
      quan.disconnect();
    };
  }, [router]);

  return null;
}
