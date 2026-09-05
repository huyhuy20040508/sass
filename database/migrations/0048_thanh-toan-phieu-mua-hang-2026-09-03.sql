-- =====================================================================
--  0048_thanh-toan-phieu-mua-hang-2026-09-03.sql
--  Ngày: 03/09/2026
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
--  THANH TOÁN PHIẾU MUA HÀNG — DỰNG THEO BẢN ORDER V2
--
--  Trước tệp này, trả tiền nhà cung cấp chỉ ghi được ĐÚNG MỘT con số:
--  `paid_amount`. Bản v2 ghi nhiều hơn thế — xem
--  PurchaseOrderController::payment bên đó:
--
--      • hình thức trả (tiền mặt / chuyển khoản)
--      • có ghi nợ hay không, và nếu có thì HẠN NỢ là ngày nào
--      • người đại diện bên bán và số điện thoại — chỗ để đòi nợ
--      • ảnh chứng từ của lượt trả (uỷ nhiệm chi, biên lai)
--
--  Thiếu mấy thứ ấy thì "còn nợ 3.000.000" là một con số chết: không biết
--  hẹn trả hôm nào, không biết gọi cho ai.
--
--  VÌ SAO ĐẶT NGAY TRÊN `purchase_orders` CHỨ KHÔNG DỰNG BẢNG CÔNG NỢ
--  RIÊNG — đọc kỹ, đây là quyết định gốc:
--
--  Bên v2 mấy trường này nằm ở bảng `cab_debt` dùng chung cho mọi loại
--  công nợ, và phiếu mua trỏ sang bằng `pch_order.id_cab_debt`. Nhưng
--  quan hệ ấy là MỘT–MỘT: mỗi phiếu tối đa một bản ghi nợ, tạo ra ngay
--  trong lượt trả tiền của chính phiếu đó và không ai dùng lại.
--
--  Bên mình CHƯA có module công nợ, cũng chưa có sổ thu chi. Dựng một
--  bảng `cab_debt` rỗng chỉ để phiếu mua trỏ sang là đẻ ra nửa cái module
--  mà không ai gọi tới — rồi khi làm công nợ thật lại phải gỡ đi. Đặt
--  thẳng lên phiếu thì dữ liệu vẫn đủ, và hôm nào dựng module công nợ
--  thật thì mấy cột này là nguồn để chuyển sang, không phải rác.
--
--  KHÔNG có ở tệp này, và đó là CỐ Ý:
--
--      • bảng sổ thu chi (`cab_income_expense` của v2) — v2 ghi một dòng
--        thu chi cho mỗi lượt trả. Bên mình chưa có sổ ấy; ghi vào đâu đó
--        cho có thì con số không khớp với bất kỳ báo cáo nào.
--      • lịch sử từng lượt trả (`cab_debt_detail`) — `paid_amount` là số
--        LUỸ KẾ, và mỗi lượt sửa đã có một dòng trong `purchase_order_history`
--        ghi rõ "từ X → Y". Đủ để tra, chưa đủ để lên báo cáo dòng tiền.
--
--  Cả hai chờ đúng module của nó.
-- =====================================================================

-- ---------------------------------------------------------------------
--  Sáu cột, MỘT lệnh ALTER
-- ---------------------------------------------------------------------
--  MySQL 8 KHÔNG nhận `ADD COLUMN IF NOT EXISTS` — chỉ MariaDB có, xem
--  lại chú thích ở migration 0002. Nên tệp này không chạy lại được, và
--  gộp cả sáu cột vào một lệnh là cố ý: MySQL dựng lại bảng một lần thay
--  vì sáu lần, và chạy dở giữa chừng thì không để lại bảng nửa vời.
ALTER TABLE purchase_orders
  --  Hình thức trả. Để chuỗi chứ không phải số như v2 (bên đó 1 = tiền
  --  mặt, 4 = chuyển khoản — hai con số rời rạc, đọc câu lệnh SQL không
  --  đoán ra nghĩa). Rỗng = chưa ghi nhận lượt trả nào.
  ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT ''
    COMMENT 'cash | transfer; rỗng = chưa ghi nhận lượt trả nào'
    AFTER payment_status,

  --  Ghi nợ. KHÔNG suy ra từ `paid_amount < total_amount`: trả thiếu vì
  --  mới trả một phần khác hẳn với trả thiếu vì hai bên ĐÃ THOẢ THUẬN
  --  cho nợ tới hạn. Chỉ cái thứ hai mới lên danh sách phải đòi.
  ADD COLUMN is_debt TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'hai bên có thoả thuận cho nợ hay không'
    AFTER payment_method,

  ADD COLUMN debt_due_date DATE NULL
    COMMENT 'hạn trả nốt phần còn nợ'
    AFTER is_debt,

  ADD COLUMN debt_contact_name VARCHAR(150) NOT NULL DEFAULT ''
    COMMENT 'người đại diện bên bán đứng ra nhận nợ'
    AFTER debt_due_date,

  ADD COLUMN debt_contact_phone VARCHAR(30) NOT NULL DEFAULT ''
    COMMENT 'số gọi khi tới hạn'
    AFTER debt_contact_name,

  --  Ảnh chứng từ TRẢ TIỀN. Tách hẳn khỏi cột `attachment` sẵn có: cột
  --  kia là chứng từ BÊN BÁN GIAO HÀNG, gộp một chỗ thì lượt trả tiền ghi
  --  đè mất ảnh phiếu giao hàng.
  ADD COLUMN payment_attachment VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT 'ảnh uỷ nhiệm chi / biên lai của lượt trả'
    AFTER debt_contact_phone;

--  KHÔNG đánh chỉ mục cho (is_debt, debt_due_date) ở đây. Câu hỏi "phiếu
--  nào sắp tới hạn" chưa có màn nào hỏi; dựng sẵn chỉ mục cho một truy
--  vấn chưa tồn tại là bắt mọi lượt ghi phải trả phí mà chẳng ai đọc.
--  Làm màn công nợ thì thêm, lúc ấy còn biết nó lọc theo đúng cái gì.
