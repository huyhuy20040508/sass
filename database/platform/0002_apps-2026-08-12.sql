-- =====================================================================
--  0002_apps-2026-08-12.sql
--  Ngày: 12/08/2026
--  LƯỢC ĐỒ: selliotech_platform (KHÔNG phải selliotech)
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--  Viết sao cho chạy lại được nếu có thể: IF NOT EXISTS, IF EXISTS...
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  APPS — DANH MỤC PHẦN MỀM MÌNH BÁN
--
--  Bốn bảng của 0001 trả lời "khách nào, gói nào, tên miền nào". Bảng này
--  trả lời câu còn thiếu: BÁN CÁI GÌ. Hôm nay câu trả lời chỉ có một dòng
--  — phần mềm quản trị bán hàng đang chạy ở order.selliotech.store — và
--  chính vì chỉ có một mà chỗ nào cũng đang ngầm hiểu nó: `subscriptions`
--  ghi gói mà không ghi gói CỦA phần mềm nào, tên miền order.* thì nằm
--  trong cấu hình nginx chứ không nằm trong sổ nào cả.
--
--  Viết bảng ra lúc còn MỘT dòng là rẻ nhất. Tới lúc có sản phẩm thứ hai
--  mới dựng thì phải vừa dựng bảng vừa đi điền ngược cho toàn bộ thuê bao
--  đang chạy, giữa lúc khách đang trả tiền.
--
--  VÌ SAO NẰM Ở CONTROL PLANE: đây là sổ của nền tảng, không thuộc khách
--  nào — cùng lý do với `tenants`/`subscriptions`. Data plane (selliotech)
--  là dữ liệu bán hàng của một cửa hàng, danh mục sản phẩm của MÌNH không
--  có chỗ trong đó.
--
--  ĐỌC KỸ — TỆP NÀY CỐ Ý CHƯA LÀM:
--    · `subscriptions` CHƯA có cột app_id. Mọi thuê bao hôm nay ngầm hiểu
--      là thuê bao của app 'order'. Nối hai bảng là migration RIÊNG, và
--      lúc đó phải điền app_id cho các dòng cũ trong cùng một tệp — làm
--      sớm thì rẻ, vì bảng subscriptions hiện chưa có dòng nào.
--    · Không có bảng giá theo app. `subscriptions.price`/`max_shops` vẫn
--      là giá đã chốt với từng khách (xem 0001), không tra ngược từ đây.
--    · Chưa có cột nào nói app này chạy ở tên miền nào. Ánh xạ đó hôm nay
--      nằm trong nginx + deploy/env, và chép nó xuống database mà không ai
--      đọc là tạo ra một chỗ thứ hai để sai.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. APPS — mỗi dòng là một phần mềm bán ra
-- =====================================================================
--  `code` là cái tên được GÕ Ở NƠI KHÁC: tiền tố tên miền
--  (order.selliotech.store), khoá trong cấu hình, và về sau là giá trị đi
--  trong JWT/URL để biết người ta đang dùng app nào. Vì vậy nó phải ngắn,
--  chữ thường, không dấu — và phải UNIQUE. Không dùng `id` cho mấy chỗ đó:
--  id là số tự sinh của MỘT database, chép cấu hình sang máy khác là lệch.
--
--  Khác `tenants.id` của 0001, `apps.id` AUTO_INCREMENT bình thường: danh
--  mục này do control plane cấp và không có bảng nào bên data plane phải
--  khớp số với nó.
--
--  status mặc định 'planned' chứ không phải 'active': dòng thêm vào lúc mới
--  lên kế hoạch mà tự nhiên bán được là cách nhanh nhất để ký hợp đồng cho
--  một phần mềm chưa tồn tại. Đổi sang 'active' là một hành động có ý thức.
--    planned — đã đặt tên, chưa chạy, chưa bán
--    active  — đang chạy, bán được
--    retired — ngừng bán; khách CŨ vẫn dùng tiếp, chỉ là không ký mới nữa
CREATE TABLE IF NOT EXISTS apps (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(30)  NOT NULL COMMENT 'tên ngắn chữ thường, trùng tiền tố tên miền: order → order.selliotech.store',
  name       VARCHAR(100) NOT NULL COMMENT 'tên hiển thị cho khách: Sellio Order',
  tagline    VARCHAR(255) NULL COMMENT 'một dòng mô tả, hiện trong khu điều hành và bảng giá',
  status     ENUM('planned','active','retired') NOT NULL DEFAULT 'planned'
             COMMENT 'planned=chưa chạy, active=đang chạy và bán được, retired=ngừng bán (khách cũ vẫn dùng)',
  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_apps_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — danh mục phần mềm nền tảng bán ra. KHÔNG có FK sang database selliotech.';

-- =====================================================================
--  2. DÒNG ĐẦU TIÊN — phần mềm ĐANG CHẠY
-- =====================================================================
--  0001 cố ý không nạp dòng nào, và điều đó vẫn đúng cho các bảng bên đó:
--  tenants/subscriptions/tenant_domains là DỮ LIỆU CỦA KHÁCH, mỗi môi
--  trường một khác, nạp sẵn là bịa ra khách không có thật.
--
--  Bảng này ngược lại: nó là danh mục CỦA MÌNH, giống hệt nhau ở máy cục
--  bộ, máy thử và máy thật. Bắt mỗi môi trường tự gõ tay một dòng y như
--  nhau chỉ tạo ra ba bản chép sai khác nhau.
--
--  ON DUPLICATE KEY UPDATE code = code: chạy lại tệp không tạo dòng thứ
--  hai, và cũng KHÔNG ghi đè tên/mô tả nếu sau này có sửa tay trong khu
--  điều hành — câu lệnh này chỉ đảm bảo dòng có mặt, không đòi nội dung
--  phải đúng như hôm nay.
INSERT INTO apps (code, name, tagline, status, created_at, updated_at)
VALUES (
  'order',
  'Sellio Order',
  'Quản trị bán hàng cho cửa hàng: đơn hàng, kho, khách hàng, báo cáo.',
  'active',
  NOW(3), NOW(3)
)
ON DUPLICATE KEY UPDATE code = code;

-- =====================================================================
--  KẾT THÚC — 1 bảng, 1 dòng, 0 khoá ngoại bắc sang selliotech.
-- =====================================================================
