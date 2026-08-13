-- =====================================================================
--  0012_han-muc-san-pham-trong-hop-dong-2026-08-13.sql
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
--  HẠN MỨC SẢN PHẨM VÀO HỢP ĐỒNG
--
--  0011 chép `max_users` từ bảng giá xuống hợp đồng và cố ý để lại
--  `max_products`, với lý do: chưa chỗ nào ép hạn mức sản phẩm, mà chép
--  một con số không ai đọc thì chỉ tạo thêm một chỗ để sai.
--
--  Nay chép nốt, theo quyết định của người bán hàng. Nói thẳng cái được và
--  cái mất để người đọc sau không phải đoán:
--
--    ĐƯỢC: hợp đồng ghi ĐỦ ba hạn mức đang bán trên landing (chi nhánh ·
--    tài khoản · sản phẩm). Ngày viết chỗ ép hạn mức, con số đã nằm sẵn
--    trong hợp đồng của từng khách — không phải đi điền ngược cho những
--    người đã ký, giữa lúc họ đang dùng.
--
--    MẤT: hôm nay chưa dòng code nào đọc cột này. Một con số không ai đọc
--    là một con số không ai kiểm, nên nó sẽ lệch với bảng giá ở đâu đó và
--    không ai biết cho tới ngày có người đọc nó. Đây là cái giá đã cân
--    nhắc, không phải chỗ bị bỏ quên.
--
--  MỘT ĐIỀU CẦN NHÌN TRƯỚC: đây là cột hạn mức THỨ BA chép từ bảng giá
--  xuống, và mỗi cái tốn một migration. Bên bảng giá thì hạn mức là khoá ·
--  giá trị (`plan_features`, xem 0005) nên thêm bao nhiêu cũng không phải
--  đụng lược đồ. Tới hạn mức thứ tư hoặc thứ năm, cân nhắc làm bên hợp
--  đồng cùng một kiểu — một bảng `subscription_features` — thay vì thêm
--  cột mãi. CHƯA làm bây giờ: ba cột thì cột vẫn hơn (database còn giữ
--  hộ kiểu dữ liệu, và ba cột chưa đủ nặng để đánh đổi điều đó).
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. max_products — số sản phẩm đã chốt với khách
-- =====================================================================
--  Cùng quy ước với `max_users` của 0011, và quy ước đó phải giữ nguyên
--  cho cả ba cột hạn mức, nếu không mỗi cột một nghĩa là chỗ chắc chắn có
--  người đọc nhầm:
--
--    · 0 = KHÔNG GIỚI HẠN — bản dịch của 'vo_han' bên `plan_features`.
--      Phải gõ tay đúng số 0 mới ra nghĩa đó.
--    · Mặc định 1: quên điền thì khách chỉ thêm được MỘT sản phẩm, và
--      điện thoại reo trong ngày đầu. Con số 1 vô lý với mọi hợp đồng
--      thật, và nó vô lý CÓ CHỦ Ý — mặc định phải là thứ hỏng nhìn thấy
--      được, không phải thứ im lặng cho hết.
--
--  INT UNSIGNED chứ không SMALLINT như hai cột kia: SMALLINT trần 65.535,
--  mà một chuỗi bán lẻ có biến thể thì vượt qua con số đó là chuyện bình
--  thường. Hạn mức chi nhánh và tài khoản thì không bao giờ tới đó.
ALTER TABLE subscriptions
  ADD COLUMN max_products INT UNSIGNED NOT NULL DEFAULT 1
  COMMENT 'số sản phẩm đã chốt với khách; 0 = không giới hạn. CHÉP từ bảng giá lúc ký, không tra ngược'
  AFTER max_users;

-- =====================================================================
--  2. Điền cho các hợp đồng cũ
-- =====================================================================
--  Y hệt bước 3b của 0011: chép từ tính năng của chính dòng bảng giá đã
--  ký (`plan_id`), 'vo_han' thành 0, và CHỈ đụng những dòng còn ở mặc
--  định — hợp đồng nào đã có số riêng do người ký điền tay thì migration
--  không có quyền ghi đè.
--
--  Bảng giá không quy định `max_products` thì giữ nguyên mặc định 1. Bảng
--  giá im lặng không có nghĩa là cho hết; người lập hợp đồng phải tự điền
--  con số đã thoả thuận.
UPDATE subscriptions s
  JOIN plan_features f ON f.plan_id = s.plan_id
                      AND f.feature_key = 'max_products'
   SET s.max_products = IF(f.value = 'vo_han', 0, CAST(f.value AS UNSIGNED)),
       s.updated_at = NOW(3)
 WHERE s.plan_id IS NOT NULL
   AND s.max_products = 1;

-- =====================================================================
--  KẾT THÚC — 1 cột. Hợp đồng giờ ghi đủ ba hạn mức đang bán:
--  max_shops · max_users · max_products.
--
--  CHƯA CÓ AI ÉP BA CON SỐ NÀY. Chỗ ép đúng là data plane, lúc tạo chi
--  nhánh / tạo tài khoản / tạo sản phẩm — mà bên đó không đọc được sổ
--  nền tảng. Ngày làm việc đó, đọc lại đoạn này trước: nó là lý do ba cột
--  đã có sẵn số để đọc.
-- =====================================================================
