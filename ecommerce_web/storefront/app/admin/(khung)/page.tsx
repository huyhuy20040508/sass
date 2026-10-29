'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useAdmin } from '@/components/admin/AdminShell';
import { adminApi } from '@/lib/adminAuth';
import { ketNoiTot, type TrangThaiPos } from '@/lib/pos';

// Năm việc để mở bán, theo thứ tự nên làm. Bước nào có màn thật thì tự biết đã
// xong chưa (hôm nay: Kết nối POS); các bước khác có trạng thái khi màn của nó làm xong.
const BUOC = [
  { ten: 'Kết nối POS', moTa: 'Hàng, giá, tồn tự lấy từ phần mềm bán hàng.', nut: 'Kết nối', nutXong: 'Xem kết nối', href: '/admin/cai-dat/ket-noi-pos' },
  { ten: 'Chọn giao diện', moTa: 'Chọn theme, sắp khối và banner trang chủ.', nut: 'Chọn giao diện', href: '/admin/giao-dien' },
  { ten: 'Chọn sản phẩm', moTa: 'Mặc định hiện mọi món đang bán — ẩn bớt món không bán online.', nut: 'Chọn sản phẩm', href: '/admin/san-pham' },
  { ten: 'Giao hàng & thanh toán', moTa: 'Phí ship, nhận tại cửa hàng, chuyển khoản QR.', nut: 'Cài đặt', href: '/admin/cai-dat/giao-hang' },
  { ten: 'Tên miền riêng', moTa: 'Không bắt buộc — gắn tên miền riêng nếu đã có.', nut: 'Gắn tên miền', href: '/admin/cai-dat/ten-mien' },
];

export default function TongQuan() {
  const { admin, shop } = useAdmin();
  const [diaChi, setDiaChi] = useState('');
  const [daChep, setDaChep] = useState(false);
  const [xong, setXong] = useState<boolean[]>([]);

  useEffect(() => setDiaChi(window.location.host), []);
  useEffect(() => {
    adminApi<TrangThaiPos>('/admin/pos')
      .then(({ data }) => setXong([ketNoiTot(data)]))
      .catch(() => setXong([]));
  }, []);
  const buocToi = BUOC.findIndex((_, i) => !xong[i]);

  async function chep() {
    try {
      await navigator.clipboard.writeText(`https://${diaChi}`);
      setDaChep(true);
      setTimeout(() => setDaChep(false), 1500);
    } catch {
      // Trình duyệt không cho chép (http, quyền): địa chỉ vẫn hiện để chép tay.
    }
  }

  return (
    <>
      <section className="card hello">
        <div className="t">
          <b>Chào {admin.full_name || admin.username}</b>
          <span>Đây là trang quản trị web bán hàng của {shop.name}.</span>
        </div>
        <div className="site">
          <i className="fa-solid fa-globe" />
          <code>{diaChi}</code>
          <button className="ibtn" type="button" title="Chép link" onClick={chep}>
            <i className={daChep ? 'fa-solid fa-check' : 'fa-regular fa-copy'} />
          </button>
        </div>
      </section>

      <section className="card setup">
        <div className="card-h">
          <h2>Bắt đầu bán hàng online</h2>
        </div>
        <div className="steps">
          {BUOC.map((b, i) => (
            <div key={b.ten} className={`step${xong[i] ? ' done' : i === buocToi ? ' next' : ''}`}>
              <div className="n">{xong[i] ? <i className="fa-solid fa-check" /> : i + 1}</div>
              <b>{b.ten}</b>
              <p>{b.moTa}</p>
              <Link className={`btn${i === buocToi ? ' pri' : ''}`} href={b.href}>
                {xong[i] && 'nutXong' in b ? b.nutXong : b.nut}
              </Link>
            </div>
          ))}
        </div>
      </section>

      <section className="card orders">
        <div className="card-h">
          <h2>Đơn hàng web</h2>
        </div>
        <div className="ob">
          <p>
            Đơn khách đặt trên web về thẳng màn <b>Bán online</b> ở Thu ngân (POS) — xác nhận, đóng gói, giao hàng đều làm ở đó.
            Số đơn và doanh thu web sẽ hiện ở đây khi web bắt đầu nhận đơn.
          </p>
        </div>
      </section>
    </>
  );
}
