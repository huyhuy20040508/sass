-- =====================================================================
--  0066_thu-tien-nhieu-lan-2026-09-08.sql
--  Ngày: 08/09/2026
-- =====================================================================
--  KHÔNG viết CREATE DATABASE hay USE ở đây: công cụ đã kết nối sẵn đúng
--  database của môi trường đang chạy (cục bộ / thử / thật đều khác tên).
--
--  MySQL không cho DDL nằm trong transaction, nên tệp chạy dở là dở thật.
--
--  Tệp này đã chạy ở đâu đó rồi thì TUYỆT ĐỐI không sửa nội dung nữa —
--  công cụ giữ vân tay và sẽ báo lệch. Cần thêm gì thì viết tệp mới.
-- =====================================================================
--
--  SỔ THU TIỀN CỦA MỘT ĐƠN — thanh toán một phần và công nợ
--
--  Tới giờ một đơn chỉ có `orders.payment_status`: bốn giá trị, và không
--  giá trị nào nói được "khách đặt cọc 300 nghìn, còn nợ 700". Cả hệ thống
--  vì thế chỉ biết hai đầu — thu đủ hoặc chưa thu đồng nào — nên màn Quản
--  lý đơn hàng phải suy cột "Công nợ" từ `payment_status = pending`, tức
--  là nói SAI: "chưa thu tiền" khác "khách nợ".
--
--  Bản v2 giải quyết bằng HAI bảng: `odr_payments` giữ hai lượt thanh toán
--  cố định (tiền mặt + chuyển khoản), `cab_debts` giữ số đã trả và hạn nợ.
--  Bảng này gộp cả hai lại và bỏ giới hạn hai lượt: một đơn thu bao nhiêu
--  lần cũng được, mỗi lần một dòng. Khách trả góp ba lần thì sổ có ba dòng,
--  không phải nhét lượt thứ ba vào đâu đó.
--
--  QUAN HỆ VỚI `orders.payment_status` — đọc kỹ, đây là chỗ dễ hiểu sai:
--
--    `payment_status = 'paid'` VẪN LÀ NGUỒN SỰ THẬT cho câu hỏi "đơn này
--    đã thu đủ chưa". Bảng này KHÔNG thay nó, và cố ý không gieo ngược dữ
--    liệu cho đơn cũ.
--
--    Lý do: tiền vào két đi qua bốn đường — bán tại quầy, cổng thanh toán
--    trực tuyến gọi lại, đánh dấu tay, và bước chuyển trạng thái tự đánh
--    dấu. Bắt cả bốn cùng ghi sổ ngay trong đợt này là sửa bốn luồng tiền
--    một lúc; sót một đường thì màn hình báo "đã thu 0đ" cho một đơn đã
--    thu đủ, và đó là kiểu sai tệ nhất — nó trông như một khoản nợ.
--
--    Nên luật đọc là: đơn `paid` thì coi như đã thu đủ dù sổ trống; đơn
--    CHƯA `paid` mà sổ có dòng thì là THANH TOÁN MỘT PHẦN, phần còn thiếu
--    chính là công nợ. Bảng này vì vậy chỉ cần ghi những lượt thu LẺ —
--    đúng thứ trước nay không có chỗ nào ghi.
--
--  KHÔNG khai khoá ngoại sang `users` cho `created_by`: nhân viên nghỉ
--  việc bị xoá không được phép kéo theo lỗi trên lượt thu họ đã ghi, và
--  cũng không được phép xoá lịch sử ấy đi. Cùng lối với `orders.created_by`
--  ở migration 0065.
--
--  `amount` để DECIMAL(15,2) chứ không 12,2 như `orders.total_amount`:
--  cột này còn cộng dồn ở báo cáo, mà tổng thì rộng hơn từng số hạng.
-- =====================================================================

CREATE TABLE IF NOT EXISTS order_payments (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  order_id BIGINT UNSIGNED NOT NULL,

  amount DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'so tien thu duoc o luot nay',

  -- Cùng bộ giá trị với `orders.payment_method`. Để VARCHAR chứ không ENUM:
  -- thêm một cổng thanh toán mới thì chỉ phải sửa một chỗ (bảng orders),
  -- không phải nhớ ra còn bảng thứ hai cũng khai cùng danh sách.
  payment_method VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'cash | bank_transfer | vnpay | ...',

  -- Thời điểm TIỀN VÀO KÉT, không phải lúc gõ phiếu. Hai mốc lệch nhau khi
  -- ghi bù cho lượt thu hôm trước, và sổ quỹ tính theo mốc này.
  paid_at DATETIME(3) NOT NULL,

  created_by BIGINT UNSIGNED NULL COMMENT 'users.id nguoi ghi luot thu',
  note       VARCHAR(255) NOT NULL DEFAULT '',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,

  PRIMARY KEY (id),

  -- Sổ luôn được đọc theo ĐƠN (cộng tổng đã thu của một đơn), nên tenant +
  -- order_id là chỉ mục chính. Chỉ mục theo ngày để báo cáo dòng tiền cắt
  -- được theo khoảng.
  KEY idx_op_don (tenant_id, order_id),
  KEY idx_op_ngay (tenant_id, paid_at),
  KEY idx_op_deleted (deleted_at),

  CONSTRAINT fk_op_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_op_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
