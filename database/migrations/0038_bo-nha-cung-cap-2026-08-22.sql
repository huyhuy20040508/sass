-- =====================================================================
--  0038_bo-nha-cung-cap-2026-08-22.sql
--  Ngày: 22/08/2026
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
--  BỎ HẲN DANH MỤC NHÀ CUNG CẤP
--
--  Cả module nhà cung cấp bị gỡ khỏi hệ thống theo yêu cầu của chủ dự án:
--  màn quản lý, API, và bảng dữ liệu.
--
--  KHÔNG LÙI LẠI ĐƯỢC. Tệp này xoá bảng `suppliers` cùng toàn bộ dữ liệu
--  trong đó. Muốn giữ lại thì sao lưu TRƯỚC khi chạy.
--
--  Chiều mua vào vẫn chạy bình thường: phiếu đặt hàng và phiếu trả hàng
--  đều đã có sẵn cột `supplier_name` — bản chụp TÊN bên bán tại thời điểm
--  lập phiếu. Từ nay người lập phiếu gõ thẳng tên vào ô đó thay vì chọn từ
--  danh mục, nên chứng từ cũ đọc lại vẫn đúng nguyên trạng.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Cắt hai đường trỏ tới bảng trước, rồi mới xoá bảng.
--
--  Phải gỡ khoá ngoại trước khi gỡ cột: MySQL không cho bỏ một cột đang
--  bị ràng buộc, và cũng không tự dọn ràng buộc hộ. Chỉ mất `supplier_id`
--  (con trỏ tới danh mục vừa xoá); `supplier_name` giữ nguyên vì đó mới là
--  thứ in ra chứng từ.
--
--  Chỉ mục idx_* trên cột cũng biến mất theo cột — không cần DROP riêng.
-- ---------------------------------------------------------------------
ALTER TABLE purchase_orders
  DROP FOREIGN KEY fk_purchase_orders_supplier;

ALTER TABLE purchase_orders
  DROP COLUMN supplier_id;

ALTER TABLE purchase_returns
  DROP FOREIGN KEY fk_pr_supplier;

ALTER TABLE purchase_returns
  DROP COLUMN supplier_id;

-- ---------------------------------------------------------------------
--  Quy tắc đánh số cho loại chứng từ vừa biến mất.
--
--  Cửa hàng nào từng bật quy tắc mã NCC thì còn một dòng nằm lại trong
--  code_rules và một bộ đếm trong code_counters. Không dọn thì màn Quy tắc
--  đánh số đọc lên một loại chứng từ mà danh mục của nó đã bị gỡ.
-- ---------------------------------------------------------------------
DELETE FROM code_counters WHERE doc_type = 'nha-cung-cap';
DELETE FROM code_rules    WHERE doc_type = 'nha-cung-cap';

-- ---------------------------------------------------------------------
--  Quyền đã cấp riêng cho từng người và quyền nằm trong nhóm quyền.
--
--  Bốn mã nha-cung-cap.xem/them/sua/xoa không còn đường nào dùng tới. Để
--  lại thì màn Phân quyền hiện những ô tích không gắn với chức năng nào.
-- ---------------------------------------------------------------------
DELETE FROM user_permissions       WHERE permission LIKE 'nha-cung-cap.%';
DELETE FROM permission_group_items WHERE permission LIKE 'nha-cung-cap.%';

-- ---------------------------------------------------------------------
--  Và cuối cùng là bảng. Đứng cuối để nếu tệp chạy dở ở trên thì bảng vẫn
--  còn đó mà xem lại.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS suppliers;
