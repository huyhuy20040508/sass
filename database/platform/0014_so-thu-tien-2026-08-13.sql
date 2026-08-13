-- =====================================================================
--  0014_so-thu-tien-2026-08-13.sql
--  Ngày: 13/08/2026
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
--  INVOICES — SỔ TỪNG LẦN THU TIỀN
--
--  Màn hình "Doanh thu theo quán" cần một con số mà hôm nay không bảng
--  nào trả lời được. `subscriptions.price` nói "MỖI CHU KỲ bao nhiêu" —
--  nó là điều khoản, không phải tiền đã vào tài khoản. Từ nó suy ra doanh
--  thu tháng thì chỉ đúng vào tháng mà mọi khách trả đúng hạn, đủ số, và
--  không ai bỏ giữa chừng: tức là không tháng nào.
--
--  Ba câu hỏi mà bảng này trả lời còn `subscriptions` thì không:
--
--    · Tháng trước thu được bao nhiêu — tiền THẬT, không phải tiền đáng
--      lẽ phải thu.
--    · Khách nào đã trả, khách nào còn nợ.
--    · Một khách đã trả mình tổng cộng bao nhiêu từ trước tới nay.
--
--  MỘT DÒNG = MỘT LẦN TIỀN VÀO, không phải một hoá đơn phát ra. Mình chưa
--  có quy trình xuất hoá đơn (chưa phát hành, chưa gửi, chưa có hạn thanh
--  toán), nên bảng này cố ý KHÔNG có `status` hay `due_date`: thêm mấy
--  cột đó bây giờ là dựng một quy trình chưa ai chạy, rồi mọi dòng sẽ
--  mang cùng một trạng thái vì không có gì đổi nó. Ngày cần công nợ thật
--  thì thêm bảng phát hành riêng, còn bảng này vẫn là sổ tiền vào.
--
--  KHÔNG LƯU tenant_id hay app_id, dù mọi màn hình đều lọc theo hai thứ
--  đó: chúng đã nằm trong `subscriptions` mà dòng này trỏ tới, và một hợp
--  đồng không bao giờ đổi chủ hay đổi phần mềm. Chép sang đây là tạo hai
--  chỗ cho cùng một sự thật, đúng thứ cả cụm này đang cố tránh — đổi lại
--  một lượt JOIN trên bảng có khoá chính.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. INVOICES
-- =====================================================================
--  `amount` KHÔNG mặc định lấy theo hợp đồng: số tiền thật có thể khác
--  giá niêm yết (trả trước hai kỳ, chiết khấu một lần, khách trả thiếu).
--  Nơi ghi phải nói rõ con số, và `cmd/thue-bao thu-tien` gợi ý sẵn giá
--  hợp đồng để người thu chỉ việc xác nhận.
--
--  `period_start`/`period_end` là CHU KỲ ĐƯỢC TRẢ CHO, khác `paid_at` là
--  NGÀY TIỀN VÀO. Hai thứ này lệch nhau là chuyện bình thường (khách trả
--  chậm nửa tháng), và trộn chúng làm một là mất khả năng trả lời "tháng
--  3 mình thu được bao nhiêu" so với "tháng 3 mình bán được bao nhiêu".
--  Báo cáo doanh thu tiền mặt đọc `paid_at`.
--
--  DATE chứ không DATETIME cho hai cột chu kỳ: chu kỳ tính theo ngày, và
--  để giờ vào đó thì so sánh khoảng thời gian sẽ lệch đúng một ngày ở hai
--  đầu, kiểu lỗi không ai nhìn ra trong báo cáo.
CREATE TABLE IF NOT EXISTS invoices (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id BIGINT UNSIGNED NOT NULL COMMENT 'hợp đồng được trả tiền — 0001.subscriptions',
  amount          DECIMAL(12,2) NOT NULL COMMENT 'số tiền THẬT đã nhận, VND. Có thể khác subscriptions.price',
  period_start    DATE NOT NULL COMMENT 'chu kỳ được trả cho, từ ngày',
  period_end      DATE NOT NULL COMMENT 'chu kỳ được trả cho, tới ngày',
  paid_at         DATETIME(3) NOT NULL COMMENT 'lúc tiền vào thật — báo cáo doanh thu đọc cột này',
  method          ENUM('chuyen_khoan','tien_mat','khac') NOT NULL DEFAULT 'chuyen_khoan',
  reference       VARCHAR(100) NULL COMMENT 'mã giao dịch ngân hàng, số phiếu thu — để đối chiếu sao kê',
  note            VARCHAR(500) NULL,
  created_at      DATETIME(3) NULL,
  updated_at      DATETIME(3) NULL,
  PRIMARY KEY (id),
  -- MỘT HỢP ĐỒNG, MỘT CHU KỲ, MỘT LẦN THU. Ghi trùng một kỳ là cách dễ
  -- nhất để doanh thu tháng đó phồng lên gấp đôi mà không ai thấy sai —
  -- người thu tiền nhìn vào sổ chỉ thấy hai dòng giống nhau, và không có
  -- gì nói dòng nào là dòng thừa.
  --
  -- Khách trả làm nhiều lần cho cùng một kỳ thì HÔM NAY chưa ghi được, và
  -- đó là đánh đổi có ý thức: chặn nhầm thì người thu tiền phát hiện ngay
  -- lúc gõ lệnh, còn để lọt thì báo cáo sai âm thầm. Ngày việc trả góp
  -- thành chuyện thường thì bỏ khoá này và thêm cột `so_lan` hoặc bảng
  -- phân bổ riêng.
  UNIQUE KEY uq_invoices_ky (subscription_id, period_start),
  -- Câu hỏi chạy hằng tháng: "từ ngày này tới ngày này thu được bao nhiêu".
  KEY idx_invoices_paid_at (paid_at),
  -- ON DELETE RESTRICT: xoá một hợp đồng đang có tiền đã thu là xoá mất
  -- dấu vết của khoản tiền đó. Hợp đồng vốn không được xoá (huỷ thì đặt
  -- status='canceled'), nên ràng buộc này chỉ chặn đúng cái lẽ ra đã
  -- không được làm.
  CONSTRAINT fk_invoices_subscription FOREIGN KEY (subscription_id)
    REFERENCES subscriptions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — sổ từng lần thu tiền. Doanh thu đọc ở đây, KHÔNG suy từ subscriptions.price.';

-- =====================================================================
--  KẾT THÚC — 1 bảng, 0 dòng.
--
--  Sổ trống nghĩa là báo cáo doanh thu ra 0 đồng, kể cả khi đã có hợp
--  đồng đang chạy. Đó là câu trả lời ĐÚNG: chưa ghi lần thu nào thì chưa
--  chứng minh được đồng nào đã vào. Ghi bằng:
--
--      go run ./cmd/thue-bao thu-tien --ma-cua-hang <mã>
-- =====================================================================
