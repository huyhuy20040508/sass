-- =====================================================================
--  0040_bo-ba-trang-mua-vao-2026-08-22.sql
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
--  BỎ HẲN BA TRANG MUA VÀO: ĐẶT HÀNG NHẬP, NHẬP HÀNG, TRẢ HÀNG NHẬP
--
--  Cả ba màn cùng API và bảng dữ liệu bị gỡ khỏi hệ thống theo yêu cầu
--  của chủ dự án.
--
--  KHÔNG LÙI LẠI ĐƯỢC. Tệp này xoá sáu bảng cùng toàn bộ chứng từ mua vào
--  trong đó. Muốn giữ lại thì sao lưu TRƯỚC khi chạy.
--
--  Nhà cung cấp Ở LẠI (migration 0039) — chỉ mất ba cột thống kê tổng mua
--  / đã trả / còn nợ vì chúng cộng từ phiếu đặt hàng vừa xoá.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  Sổ kho GIỮ NGUYÊN, chỉ cắt con trỏ trỏ vào chứng từ sắp biến mất.
--
--  Những bút toán này là hàng THẬT đã về kho: xoá chúng đi là tồn kho
--  hiện tại sai và số dư chạy trong sổ đứt quãng. Nên chỉ gỡ cặp
--  reference_type / reference_id — từ nay chúng là lượt nhập không kèm
--  chứng từ, đúng như một lượt cân đối kho tay.
--
--  Đây KHÔNG phải khoá ngoại (sổ kho trỏ tới nhiều loại chứng từ bằng một
--  cặp cột chung), nên phải dọn bằng tay chứ MySQL không tự lo.
-- ---------------------------------------------------------------------
UPDATE inventory_transactions
   SET reference_type = NULL, reference_id = NULL
 WHERE reference_type = 'purchase_order';

-- ---------------------------------------------------------------------
--  Quy tắc đánh số của hai loại chứng từ vừa biến mất.
--
--  Cửa hàng nào từng bật quy tắc mã phiếu đặt mua / trả hàng NCC thì còn
--  một dòng nằm lại trong code_rules và một bộ đếm trong code_counters.
--  Không dọn thì màn Quy tắc đánh số đọc lên loại chứng từ không còn màn.
-- ---------------------------------------------------------------------
DELETE FROM code_counters WHERE doc_type IN ('phieu-dat-mua', 'tra-hang-ncc');
DELETE FROM code_rules    WHERE doc_type IN ('phieu-dat-mua', 'tra-hang-ncc');

-- ---------------------------------------------------------------------
--  Quyền đã cấp riêng cho từng người và quyền nằm trong nhóm quyền.
--
--  Ba nhóm mã dat-hang-nhap.*, nhap-kho.*, tra-hang-nhap.* không còn
--  đường nào dùng tới. Để lại thì màn Phân quyền hiện những ô tích không
--  gắn với chức năng nào.
-- ---------------------------------------------------------------------
DELETE FROM user_permissions       WHERE permission LIKE 'dat-hang-nhap.%';
DELETE FROM user_permissions       WHERE permission LIKE 'nhap-kho.%';
DELETE FROM user_permissions       WHERE permission LIKE 'tra-hang-nhap.%';
DELETE FROM permission_group_items WHERE permission LIKE 'dat-hang-nhap.%';
DELETE FROM permission_group_items WHERE permission LIKE 'nhap-kho.%';
DELETE FROM permission_group_items WHERE permission LIKE 'tra-hang-nhap.%';

-- ---------------------------------------------------------------------
--  Và cuối cùng là sáu bảng. Đứng cuối để nếu tệp chạy dở ở trên thì dữ
--  liệu vẫn còn đó mà xem lại.
--
--  Thứ tự xoá đi từ bảng con lên bảng cha: mọi khoá ngoại giữa sáu bảng
--  này đều trỏ ngược lên purchase_orders / purchase_returns, bỏ bảng cha
--  trước là MySQL chặn.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS purchase_return_history;
DROP TABLE IF EXISTS purchase_return_items;
DROP TABLE IF EXISTS purchase_returns;
DROP TABLE IF EXISTS purchase_order_history;
DROP TABLE IF EXISTS purchase_order_items;
DROP TABLE IF EXISTS purchase_orders;
