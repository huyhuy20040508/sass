-- =====================================================================
--  0033_quan-ly-chi-nhanh-2026-08-21.sql
--  Ngày: 21/08/2026
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
--  HỒ SƠ CHI NHÁNH ĐẦY ĐỦ
--
--  Màn "Quản lý chi nhánh" dựng lại theo bản cũ v2 (Hệ thống → Quản lý chi
--  nhánh). Bản đó khai gần hai mươi ô cho một điểm bán; bảng `shops` ở đây
--  mới có năm cột (mã, tên, điện thoại, địa chỉ, trạng thái), nên vẽ đủ ô
--  ra màn hình mà không thêm cột thì người dùng gõ vào rồi bấm Lưu là mất
--  trắng. Tệp này thêm phần còn thiếu.
--
--  BA NHÓM Ô, ba lý do khác nhau:
--
--   1. HỒ SƠ PHÁP LÝ (tên giao dịch, mã số thuế, email) — thứ đi lên hoá
--      đơn và hợp đồng. Cửa hàng một chi nhánh khai ở Cấu hình cửa hàng là
--      đủ, nhưng chuỗi có nhiều pháp nhân thì mỗi điểm bán một mã số thuế.
--
--   2. VỊ TRÍ (quốc gia, thành phố, toạ độ, phạm vi hoạt động) — `location`
--      giữ nguyên khuôn "vĩ độ, kinh độ" của v2 (chuỗi, không tách hai cột
--      số): nó được dán thẳng từ Google Maps, và tách ra rồi ghép lại chỉ
--      để hiển thị là hai lần cơ hội làm sai. `area_scope` tính bằng MÉT,
--      đi theo cặp với toạ độ — có cái này phải có cái kia.
--
--   3. HOÁ ĐƠN TẠI QUẦY (logo, ba khối chữ đầu/wifi/chân hoá đơn) — phần
--      máy in nhiệt in ra. Giới hạn 255 ký tự mỗi khối, đúng bằng bản v2:
--      giấy 58/80mm không chứa nổi nhiều hơn.
--
--  `branch_type` phân biệt ĐIỂM BÁN với PHÁP NHÂN: bản v2 để hai lựa chọn
--  Chi nhánh / Công ty ngay trên form. Mặc định 1 (chi nhánh) cho mọi dòng
--  đang có — dòng 'mac-dinh' dựng cùng cửa hàng cũng là một điểm bán.
--
--  `created_by` để cột "Người tạo" của bảng danh sách có nguồn thật. Dòng
--  cũ để NULL và màn hình in "—": bịa một người tạo cho dữ liệu dựng trước
--  khi có cột này là ghi vào sổ một điều không ai kiểm lại được.
-- ---------------------------------------------------------------------

ALTER TABLE shops
  ADD COLUMN transaction_name    VARCHAR(150)     NULL COMMENT 'tên giao dịch viết tắt'         AFTER name,
  ADD COLUMN tax_code            VARCHAR(20)      NULL COMMENT 'mã số thuế của điểm bán'        AFTER transaction_name,
  ADD COLUMN email               VARCHAR(150)     NULL                                          AFTER phone,
  ADD COLUMN country             VARCHAR(100)     NULL                                          AFTER address,
  ADD COLUMN city                VARCHAR(100)     NULL                                          AFTER country,
  ADD COLUMN location            VARCHAR(50)      NULL COMMENT 'toạ độ "vĩ độ, kinh độ"'        AFTER city,
  ADD COLUMN area_scope          INT UNSIGNED     NULL COMMENT 'phạm vi hoạt động, tính bằng mét' AFTER location,
  ADD COLUMN access_link         VARCHAR(255)     NULL COMMENT 'link đặt hàng online của điểm bán' AFTER area_scope,
  ADD COLUMN branch_type         TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1 = chi nhánh, 2 = công ty' AFTER access_link,
  ADD COLUMN image               VARCHAR(255)     NULL COMMENT 'logo in trên hoá đơn tại quầy'  AFTER branch_type,
  ADD COLUMN header_invoice_info VARCHAR(255)     NULL COMMENT 'khối chữ đầu hoá đơn'           AFTER image,
  ADD COLUMN wifi_invoice_info   VARCHAR(255)     NULL COMMENT 'khối chữ wifi trên hoá đơn'     AFTER header_invoice_info,
  ADD COLUMN footer_invoice_info VARCHAR(255)     NULL COMMENT 'khối chữ chân hoá đơn'          AFTER wifi_invoice_info,
  ADD COLUMN created_by          BIGINT UNSIGNED  NULL COMMENT 'người mở chi nhánh'             AFTER footer_invoice_info,
  ADD CONSTRAINT fk_shops_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;
