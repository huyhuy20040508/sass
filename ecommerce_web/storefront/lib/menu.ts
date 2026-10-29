// Menu khu quản trị — một chỗ khai, sidebar và trang giữ chỗ cùng đọc.
// Màn chưa làm (`giaiDoan`) hiện trang giữ chỗ ghi rõ sẽ có gì; làm xong màn nào thì
// tạo trang thật ở app/admin/(khung)/<duong-dan> và bỏ `giaiDoan` của nó.

export type MucMenu = {
  duongDan: string; // sau /admin, '' = Tổng quan
  ten: string;
  icon: string; // lớp Font Awesome
  moTa?: string;
  seCo?: string[];
  giaiDoan?: number;
};

export type NhomMenu = { ten: string; icon: string; muc: MucMenu[] };

export const MUC_DON: MucMenu[] = [{ duongDan: '', ten: 'Tổng quan', icon: 'fa-gauge-high' }];

export const NHOM_MENU: NhomMenu[] = [
  {
    ten: 'Sản phẩm',
    icon: 'fa-box',
    muc: [
      {
        duongDan: 'san-pham', ten: 'Sản phẩm trên web', icon: 'fa-box-open', giaiDoan: 5,
        moTa: 'Hàng, giá, tồn lấy từ POS — ở đây chỉ chọn món nào hiện lên web và làm đẹp cho web.',
        seCo: ['Hiện/ẩn từng món hoặc cả nhóm hàng', 'Sắp thứ tự, gắn nhãn nổi bật / mới / bán chạy', 'Mô tả dài, ảnh thêm, tiêu đề & mô tả SEO riêng cho web', 'Giá và tồn chỉ xem — sửa ở POS'],
      },
      {
        duongDan: 'nhom-hang', ten: 'Nhóm hàng trên web', icon: 'fa-layer-group', giaiDoan: 5,
        moTa: 'Nhóm hàng của POS dùng làm danh mục trên web.',
        seCo: ['Đổi tên hiển thị, ảnh đại diện nhóm', 'Thứ tự nhóm trên menu và trang chủ', 'Đường dẫn đẹp: /danh-muc/ao-thun'],
      },
    ],
  },
  {
    ten: 'Website',
    icon: 'fa-display',
    muc: [
      {
        duongDan: 'giao-dien', ten: 'Website của tôi', icon: 'fa-globe',
        moTa: 'Giao diện đang chạy, số khách vào web, kho giao diện; từ đây vào màn Chỉnh sửa giao diện.',
      },
      {
        duongDan: 'menu', ten: 'Menu điều hướng', icon: 'fa-bars-staggered', giaiDoan: 3,
        moTa: 'Menu đầu trang và chân trang của website.',
        seCo: ['Kéo thả mục menu, menu con', 'Trỏ tới nhóm hàng, sản phẩm, trang nội dung hoặc link ngoài'],
      },
      {
        duongDan: 'trang', ten: 'Trang nội dung', icon: 'fa-file-lines', giaiDoan: 5,
        moTa: 'Các trang chữ: Giới thiệu, Chính sách đổi trả, Giao hàng, Liên hệ.',
        seCo: ['Soạn thảo có ảnh, bảng', 'Mẫu sẵn cho các chính sách thường gặp', 'SEO riêng từng trang'],
      },
    ],
  },
  {
    ten: 'Cài đặt',
    icon: 'fa-gear',
    muc: [
      {
        duongDan: 'cai-dat/cua-hang', ten: 'Thông tin cửa hàng', icon: 'fa-store',
        moTa: 'Logo, liên hệ, mạng xã hội — hiện ở đầu trang và chân trang của web.',
      },
      {
        duongDan: 'cai-dat/giao-hang', ten: 'Giao hàng', icon: 'fa-truck-fast', giaiDoan: 5,
        moTa: 'Cách tính phí ship và khu vực giao.',
        seCo: ['Phí cố định / theo khu vực / miễn phí từ mức đơn', 'Cho nhận tại cửa hàng', 'Chi nhánh nhận đơn mặc định'],
      },
      {
        duongDan: 'cai-dat/thanh-toan', ten: 'Thanh toán', icon: 'fa-credit-card', giaiDoan: 4,
        moTa: 'Cách khách trả tiền khi đặt trên web.',
        seCo: ['Tiền mặt khi nhận hàng', 'Chuyển khoản QR qua cổng SePay/PayOS đã nối ở POS', 'Ghi chú hiện ở bước thanh toán'],
      },
      {
        duongDan: 'cai-dat/ten-mien', ten: 'Tên miền', icon: 'fa-globe', giaiDoan: 6,
        moTa: 'Địa chỉ web của cửa hàng.',
        seCo: ['Địa chỉ có sẵn của shop', 'Gắn tên miền riêng (vd tencuahang.vn) — hướng dẫn trỏ DNS, HTTPS tự cấp', 'Chọn địa chỉ chính in lên QR, hoá đơn', 'QR riêng từng chi nhánh'],
      },
      {
        duongDan: 'cai-dat/seo', ten: 'SEO & mã theo dõi', icon: 'fa-magnifying-glass-chart', giaiDoan: 5,
        moTa: 'Hiện tốt trên Google, Zalo, Facebook và đo lượt khách.',
        seCo: ['Tiêu đề, mô tả, ảnh chia sẻ mặc định', 'Facebook Pixel, Google Analytics, Google Search Console', 'Nút chat Zalo / Messenger'],
      },
      { duongDan: 'cai-dat/ket-noi-pos', ten: 'Kết nối POS', icon: 'fa-plug' },
    ],
  },
];

export const MOI_MUC: MucMenu[] = [...MUC_DON, ...NHOM_MENU.flatMap((n) => n.muc)];

export const timMuc = (duongDan: string) => MOI_MUC.find((m) => m.duongDan === duongDan);

// Trang con không nằm trên menu: mở từ một màn trong menu, menu vẫn sáng mục cha.
const TRANG_CON: Record<string, string> = { 'giao-dien/chinh-sua': 'Chỉnh sửa giao diện' };

/** Mục menu cần sáng + tiêu đề cho đường dẫn đang xem (kể cả trang con). */
export function trangDangXem(duongDan: string): { muc?: MucMenu; ten: string } {
  const muc = timMuc(duongDan) ?? MOI_MUC.find((m) => m.duongDan && duongDan.startsWith(`${m.duongDan}/`));
  return { muc, ten: TRANG_CON[duongDan] ?? muc?.ten ?? 'Quản trị' };
}
