-- =====================================================================
--  0013_hop-dong-ghi-ca-tinh-nang-2026-08-13.sql
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
--  BỊT CHỖ CUỐI CÙNG ĐỌC NGƯỢC VỀ BẢNG GIÁ
--
--  Ranh giới của cả cụm này, viết lại cho gọn:
--
--      plans + plan_features = BẢNG GIÁ HIỆN HÀNH, được phép đổi.
--      subscriptions         = BẢN ĐÃ CHỐT VỚI KHÁCH, không đổi theo.
--      Lúc ký thì CHÉP sang, sau đó KHÔNG đọc ngược nữa.
--
--  0011/0012 đã chép ba hạn mức số (chi nhánh · tài khoản · sản phẩm).
--  Nhưng còn một quyền lợi nữa mà hợp đồng KHÔNG ghi, và nó vẫn đang
--  được tra ngược về bảng giá ở mỗi lần dùng: `own_domain` — gói này có
--  kèm tên miền riêng không.
--
--  Câu truy vấn đang chạy trong `cmd/ten-mien` (trước tệp này):
--
--      subscriptions → plans (theo app + mã gói + chu kỳ)
--                    → plan_features (own_domain)
--
--  Nghĩa là quyền lợi của một khách ĐANG TRẢ TIỀN được quyết bởi bảng giá
--  của HÔM NAY. Ngày mình đổi chính sách — bỏ tên miền riêng khỏi gói
--  Chuỗi, hoặc đổi tên mã gói — thì khách đã ký gói Chuỗi lập tức không
--  được cấp tên miền nữa, dù họ đã mua đúng thứ đó. Không có lỗi nào nổi
--  lên; chỉ là một lệnh từ chối với câu chữ nghe rất hợp lý.
--
--  Đây đúng là loại hỏng mà cả ranh giới trên sinh ra để chặn, và nó đang
--  sống trong code. Tệp này chép nốt quyền lợi đó vào hợp đồng.
--
--  VÌ SAO LÀ CỘT CHỨ KHÔNG PHẢI BẢNG KHOÁ · GIÁ TRỊ: 0012 đã ghi lại lo
--  ngại "mỗi hạn mức một migration", và đây là cột thứ tư. Vẫn chọn cột,
--  vì `own_domain` không phải một con số cấu hình được — nó là MỘT ĐIỀU
--  KHOẢN CÓ HOẶC KHÔNG mà `cmd/ten-mien` đọc ở mỗi lần cấp, tức là có
--  người tiêu thụ thật và cần database giữ hộ kiểu dữ liệu. Ngày điều
--  khoản thứ năm xuất hiện mà KHÔNG ai đọc nó ngay, thì đó là lúc dựng
--  `subscription_features` thay vì thêm cột.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. own_domain — hợp đồng này có kèm tên miền riêng không
-- =====================================================================
--  Mặc định 0, giống hệt `plans.own_domain` của 0004 và vì cùng một lý
--  do: mỗi tên miền cấp ra là một bản ghi DNS, một server block nginx và
--  một chứng chỉ phải gia hạn — thứ tốn việc thật. Quên điền thì khách
--  gọi điện hỏi ngay hôm sau; bật nhầm thì tốn tiền và tốn người mà không
--  ai biết.
ALTER TABLE subscriptions
  ADD COLUMN own_domain TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'hợp đồng này có kèm tên miền riêng không. CHÉP từ bảng giá lúc ký; nơi cấp tên miền đọc CỘT NÀY, không tra ngược plan_features'
  AFTER max_products;

-- =====================================================================
--  2. Điền cho các hợp đồng cũ
-- =====================================================================
--  Chép từ tính năng của chính DÒNG BẢNG GIÁ ĐÃ KÝ (`plan_id` của 0011),
--  không phải từ dòng đang mang cùng mã gói — hai thứ đó khác nhau ngay
--  sau lần sửa bảng giá đầu tiên, và đây là lần duy nhất được phép đọc
--  sang bên kia: lúc chép.
--
--  Chỉ đụng dòng còn ở mặc định 0; hợp đồng nào đã được bật tay thì
--  migration không có quyền tắt đi.
UPDATE subscriptions s
  JOIN plan_features f ON f.plan_id = s.plan_id
                      AND f.feature_key = 'own_domain'
   SET s.own_domain = IF(f.value = '1', 1, 0),
       s.updated_at = NOW(3)
 WHERE s.plan_id IS NOT NULL
   AND s.own_domain = 0;

-- =====================================================================
--  KẾT THÚC — 1 cột.
--
--  Sau tệp này, `cmd/ten-mien` KHÔNG còn chạm `plans` hay `plan_features`
--  nữa: nó đọc `subscriptions.own_domain`. Đó cũng là cách kiểm ranh giới
--  rẻ nhất — mở tệp lệnh đó ra, nếu thấy tên hai bảng bảng giá xuất hiện
--  trở lại thì có người vừa đọc ngược.
--
--  CÒN LẠI, và phải làm trước khi bán hợp đồng đầu tiên: chưa có đường
--  nào TẠO thuê bao, nên bước "chép hạn mức sang" chưa có nhà. Hôm nay ai
--  ký hợp đồng thì gõ SQL tay và phải tự nhớ chép đủ bốn thứ (price,
--  max_shops, max_users, max_products) cộng own_domain — đúng chỗ dễ quên
--  nhất trong cả cụm.
-- =====================================================================
