-- =====================================================================
--  0018_nhac-han-hop-dong-2026-08-15.sql
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
--  RENEWAL_REMINDERS — ĐÃ NHẮC KHÁCH NGÀY NÀO
--
--  Lượt quét nền chạy 5 phút một lần. Không có bảng này thì hợp đồng còn
--  ba ngày nữa hết hạn sẽ sinh ra 288 thông báo mỗi ngày trong chuông của
--  khách — và cái chuông đó lập tức thành thứ không ai bấm nữa, kể cả khi
--  có việc thật.
--
--  MỘT DÒNG = MỘT LẦN ĐÃ NHẮC, cho một hợp đồng trong một NGÀY. Khoá
--  chính là chính cặp đó, nên "đã nhắc chưa" là một câu INSERT: chèn được
--  nghĩa là chưa nhắc, đụng khoá nghĩa là nhắc rồi. Không có lượt đọc nào
--  đứng trước để hai tiến trình cùng đọc thấy "chưa nhắc" rồi cùng gửi.
--
--  VÌ SAO THEO NGÀY chứ không phải một cột `da_nhac` bật/tắt: khách cần
--  được nhắc LẠI mỗi ngày khi hạn tới gần, không phải một lần rồi thôi.
--  Một cột bật/tắt thì lần nhắc duy nhất rơi vào ngày thứ năm trước hạn —
--  đúng lúc còn xa nhất và dễ quên nhất.
--
--  KHÔNG có khoá ngoại sang `subscriptions`: dòng ở đây là dấu vết của
--  một việc đã xảy ra, và nó không mất nghĩa khi hợp đồng bị xoá. Dọn
--  bảng thì xoá theo ngày, không theo hợp đồng.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS renewal_reminders (
  subscription_id BIGINT UNSIGNED NOT NULL COMMENT 'hợp đồng đã nhắc — 0001.subscriptions',
  ngay            DATE            NOT NULL COMMENT 'ngày đã nhắc (theo giờ máy chủ)',
  con_lai_ngay    SMALLINT        NOT NULL COMMENT 'lúc nhắc thì còn mấy ngày — để tra lại về sau',
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (subscription_id, ngay)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Đã nhắc hạn hợp đồng ngày nào (control plane)';
