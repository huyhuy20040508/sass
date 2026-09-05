-- =====================================================================
--  0046_xoa-mem-banner-va-nhom-quyen-2026-08-31.sql
--  Ngày: 31/08/2026
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
--  XOÁ MỀM CHO BANNER VÀ NHÓM QUYỀN
--
--  Luật chung của hệ thống: xoá bất cứ thứ gì cũng chỉ là ẩn khỏi phần
--  mềm, dòng vẫn nằm nguyên trong sổ để còn phục hồi được. Mọi bảng danh
--  mục khác đã có `deleted_at` từ trước (GORM tự lọc dòng đã xoá ra khỏi
--  mọi câu đọc), riêng hai bảng này còn sót.
--
--  `banners` xoá thật thì mất luôn ảnh đã treo và lịch chạy của nó;
--  `permission_groups` xoá thật thì mất dấu vết ai từng được giao bộ
--  quyền nào — đúng thứ cần tra lại nhất khi soát an toàn.
-- =====================================================================

ALTER TABLE banners
    ADD COLUMN deleted_at DATETIME(3) NULL DEFAULT NULL,
    ADD INDEX idx_banners_deleted_at (deleted_at);

ALTER TABLE permission_groups
    ADD COLUMN deleted_at DATETIME(3) NULL DEFAULT NULL,
    ADD INDEX idx_permission_groups_deleted_at (deleted_at);
