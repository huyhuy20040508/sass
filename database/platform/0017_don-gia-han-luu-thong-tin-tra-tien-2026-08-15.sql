-- =====================================================================
--  0017_don-gia-han-luu-thong-tin-tra-tien-2026-08-15.sql
--  Ngày: 15/08/2026
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
--  ĐƠN GIA HẠN LƯU LUÔN THÔNG TIN ĐỂ TRẢ TIỀN
--
--  0016 chỉ giữ `checkout_url` — địa chỉ trang trả tiền của cổng. Đủ để
--  chạy, nhưng nó buộc khách RỜI phần mềm sang một trang khác, và trang
--  thanh toán của mình chẳng hiện được gì ngoài một cái nút.
--
--  Cổng trả về đủ thứ cần để tự vẽ màn hình trả tiền ngay tại chỗ: chuỗi
--  VietQR, số tài khoản trung gian, tên chủ tài khoản, và nội dung chuyển
--  khoản. Năm cột dưới đây cất chúng lại.
--
--  VÌ SAO PHẢI LƯU, không hỏi lại cổng mỗi lần mở trang:
--
--    · Đường tra trạng thái của PayOS (GET /v2/payment-requests/{code})
--      KHÔNG trả lại chuỗi QR — nó chỉ có lúc TẠO link. Không lưu thì
--      không có cách nào dựng lại, và khách tải lại trang là mất QR.
--    · Mỗi lần mở trang mà gọi sang cổng là một lượt phụ thuộc mạng cho
--      một thứ không bao giờ đổi: QR của một đơn đã chốt số tiền thì cố
--      định tới lúc đơn chết.
--
--  KHÔNG có gì bí mật trong năm cột này: số tài khoản trung gian của cổng
--  và nội dung chuyển khoản đều là thứ in ra cho khách nhìn. Khác hẳn
--  khoá API của cổng — thứ đó nằm ở platform_settings và được mã hoá.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE renewal_orders
  -- Chuỗi VietQR nguyên văn do cổng trả về. Trang thanh toán vẽ mã QR từ
  -- chính chuỗi này, nên khách quét bằng app ngân hàng nào cũng ra đúng
  -- số tiền và đúng nội dung — không phải tự gõ.
  ADD COLUMN qr_code TEXT NULL COMMENT 'chuỗi VietQR do cổng trả về, để tự vẽ QR'
    AFTER checkout_url,
  -- Bốn ô dưới đây là bản CHỮ của chính mã QR: khách nào không quét được
  -- (máy tính để bàn, app ngân hàng cũ) vẫn chuyển tay được.
  ADD COLUMN ngan_hang_bin VARCHAR(20) NULL COMMENT 'mã BIN ngân hàng nhận tiền'
    AFTER qr_code,
  ADD COLUMN so_tai_khoan VARCHAR(32) NULL COMMENT 'số tài khoản nhận tiền (của cổng)'
    AFTER ngan_hang_bin,
  ADD COLUMN chu_tai_khoan VARCHAR(150) NULL COMMENT 'tên chủ tài khoản nhận tiền'
    AFTER so_tai_khoan,
  -- Nội dung chuyển khoản: thứ DUY NHẤT nối một lần tiền vào với một đơn.
  -- Khách gõ sai nó thì tiền vào tài khoản mà không đơn nào được chốt.
  ADD COLUMN noi_dung VARCHAR(100) NULL COMMENT 'nội dung chuyển khoản của đơn'
    AFTER chu_tai_khoan;
