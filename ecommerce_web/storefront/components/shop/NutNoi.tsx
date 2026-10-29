import { lienKetZalo, type CaiDat } from '@/lib/khung';

// Nút gọi / Zalo nổi góc màn hình — dùng chung mọi theme, bật/tắt ở khối Chân trang.
export default function NutNoi({ cd }: { cd: CaiDat }) {
  const zalo = cd.zalo || '';
  if (!cd.hotline && !zalo) return null;
  return (
    <div className="sf-noi">
      {zalo && <a className="zalo" href={lienKetZalo(zalo)} target="_blank" rel="noopener noreferrer" aria-label="Chat Zalo">Zalo</a>}
      {cd.hotline && <a className="goi" href={`tel:${cd.hotline}`} aria-label="Gọi cửa hàng"><i className="fa-solid fa-phone" /></a>}
    </div>
  );
}
