import CauNoiXemTruoc from '@/components/shop/CauNoiXemTruoc';
import { layKhung, nguCanh } from '@/lib/khung';
import { shopApi } from '@/lib/shop-api';
import type { Khoi } from '@/themes/kieu';
import { trangChuCua } from '@/themes/registry';

// Trang chủ dựng theo giao diện của shop (màn Chỉnh sửa giao diện). Đầu/chân trang ở layout.
export default async function TrangChuShop() {
  const { host, xemTruoc, them } = await nguCanh();
  const [k, r] = await Promise.all([layKhung(), shopApi<Khoi[]>(`/trang-chu${them ? `?${them}` : ''}`, host)]);

  const TrangChu = trangChuCua(k.data?.giao_dien.theme ?? '');
  return (
    <>
      <TrangChu khoi={r.data ?? []} nc={{ host, xemTruoc: !!xemTruoc, nhom: k.data?.nhom_hang ?? [], cd: k.data!.cai_dat }} />
      {xemTruoc && <CauNoiXemTruoc />}
    </>
  );
}
