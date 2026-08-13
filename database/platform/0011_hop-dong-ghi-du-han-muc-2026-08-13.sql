-- =====================================================================
--  0011_hop-dong-ghi-du-han-muc-2026-08-13.sql
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
--  HỢP ĐỒNG GHI ĐỦ HẠN MỨC, VÀ NHỚ NÓ ĐƯỢC KÝ TỪ DÒNG BẢNG GIÁ NÀO
--
--  Hai cột, hai mục đích khác hẳn nhau — đọc kỹ chỗ này trước khi dùng
--  chúng, vì trộn lẫn là hỏng đúng cái mà 0003 đã cố tránh.
--
--  1) `max_users` — HẠN MỨC ĐÃ CHỐT, cùng loại với `max_shops` đang có.
--     Bảng giá nói gói Cửa hàng cho 10 tài khoản; người lập hợp đồng CHÉP
--     con số đó vào đây, và từ giây phút ấy nó là con số của khách này.
--     Tháng sau hạ bảng giá xuống 8 thì khách đã ký vẫn giữ 10 — đó là
--     toàn bộ lý do hợp đồng chép giá trị ra thay vì tra bảng giá.
--
--  2) `plan_id` — CHỈ ĐỂ TRUY VẾT: hợp đồng này ký từ DÒNG bảng giá nào.
--
--     0003 viết hoa nguyên câu "TUYỆT ĐỐI KHÔNG thêm subscriptions.plan_id
--     trỏ sang đây". Tệp này thêm, nên phải nói rõ vì sao lệnh cấm đó
--     được nới — và nới tới đâu.
--
--     Điều 0003 sợ là ĐỌC GIÁ VÀ HẠN MỨC QUA KHOÁ NGOẠI. Có plan_id thì
--     `JOIN plans` là việc dễ nhất trên đời, và ngày ai đó viết
--     `SELECT p.price FROM subscriptions s JOIN plans p ...` thì mọi hợp
--     đồng cũ tự đổi giá theo bảng giá hiện hành — tăng giá người đang
--     trả tiền, hoặc cắt mất chi nhánh của họ, mà không dòng nào trong
--     code trông có vẻ sai.
--
--     Điều cấm đó VẪN NGUYÊN VẸN. Cột này không được dùng để đọc giá,
--     hạn mức, hay tính năng. Nó trả lời đúng một câu mà hôm nay không ai
--     trả lời được: "hợp đồng này ký từ dòng bảng giá nào" — tra bằng
--     (app_id, code, billing_cycle) chỉ ra dòng ĐANG mang mã đó, không
--     phải dòng lúc ký, và hai thứ đó khác nhau ngay sau lần sửa giá đầu
--     tiên.
--
--     SỰ THẬT CỦA HỢP ĐỒNG NẰM Ở CÁC CỘT CHÉP RA: price, max_shops,
--     max_users. plan_id chỉ là dấu vết.
--
--     NULL được: hợp đồng thoả thuận riêng (gói Chuỗi báo giá từng khách)
--     có thể không khớp dòng bảng giá nào. NULL nghĩa là "không sinh ra
--     từ một dòng bảng giá", không phải "chưa điền".
--
--     ON DELETE RESTRICT: xoá một dòng bảng giá đang có hợp đồng trỏ tới
--     là xoá mất dấu vết của những hợp đồng đó. Bảng giá vốn không được
--     xoá (ngừng bán thì đặt status='retired' — xem 0003), nên ràng buộc
--     này chỉ chặn đúng cái lẽ ra đã không được làm.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. max_users — số tài khoản đã chốt với khách
-- =====================================================================
--  NOT NULL DEFAULT 1, giống hệt `max_shops`, và mặc định 1 là có chủ ý:
--  quên điền thì khách nhận ĐÚNG MỘT tài khoản. Hỏng kiểu đó lộ ra trong
--  ngày đầu tiên bằng một cuộc điện thoại; hỏng theo chiều ngược lại —
--  quên điền thành không giới hạn — thì không ai phát hiện ra bao giờ.
--
--  0 = KHÔNG GIỚI HẠN, và phải gõ tay đúng số 0 mới ra nghĩa đó. Đây là
--  bản dịch của giá trị 'vo_han' bên `plan_features` (0005) sang một cột
--  số. Không dùng NULL cho nghĩa này: NULL là thứ xuất hiện khi người ta
--  quên, mà quên thì không được thành "cho hết".
ALTER TABLE subscriptions
  ADD COLUMN max_users SMALLINT UNSIGNED NOT NULL DEFAULT 1
  COMMENT 'số tài khoản đã chốt với khách; 0 = không giới hạn. CHÉP từ bảng giá lúc ký, không tra ngược'
  AFTER max_shops;

-- =====================================================================
--  2. plan_id — dấu vết, không phải nguồn dữ liệu
-- =====================================================================
ALTER TABLE subscriptions
  ADD COLUMN plan_id BIGINT UNSIGNED NULL
  COMMENT 'DÒNG bảng giá đã ký (0003.plans) — CHỈ để truy vết. TUYỆT ĐỐI không đọc giá/hạn mức qua cột này: giá của hợp đồng là subscriptions.price'
  AFTER plan;

-- =====================================================================
--  3. Điền cho các dòng cũ
-- =====================================================================
--  Bảng đang 0 dòng ở mọi môi trường, nhưng tệp migration phải đúng cả
--  khi ai đó vừa chèn tay vài hợp đồng để thử — và quan trọng hơn, phần
--  này là chỗ ghi lại CÁCH ĐIỀN ĐÚNG cho người đọc sau.
--
--  3a. plan_id: nối bằng đúng bộ ba mà 0003 quy định là quan hệ tra cứu
--      hợp lệ — (app_id, mã gói, chu kỳ). Không khớp dòng nào thì để NULL
--      chứ không đoán bừa một dòng gần đúng.
UPDATE subscriptions s
  JOIN plans p ON p.app_id = s.app_id
              AND p.code = s.plan
              AND p.billing_cycle = s.billing_cycle
   SET s.plan_id = p.id,
       s.updated_at = NOW(3)
 WHERE s.plan_id IS NULL;

--  3b. max_users: chép từ tính năng của chính dòng bảng giá vừa nối được.
--      'vo_han' thành 0 (xem phần 1); bảng giá không quy định thì giữ
--      nguyên mặc định 1 — bảng giá im lặng không có nghĩa là cho hết.
--
--      Chỉ đụng những dòng còn ở mặc định: hợp đồng nào đã có số riêng do
--      người ký điền tay thì migration không có quyền ghi đè.
UPDATE subscriptions s
  JOIN plan_features f ON f.plan_id = s.plan_id
                      AND f.feature_key = 'max_users'
   SET s.max_users = IF(f.value = 'vo_han', 0, CAST(f.value AS UNSIGNED)),
       s.updated_at = NOW(3)
 WHERE s.plan_id IS NOT NULL
   AND s.max_users = 1;

-- =====================================================================
--  4. Khoá ngoại cho plan_id
-- =====================================================================
--  Đặt SAU bước điền: thêm khoá ngoại trước rồi mới UPDATE thì mọi giá
--  trị điền vào đều bị kiểm hai lần, và tệp chết giữa chừng để lại một
--  ràng buộc trên cột toàn NULL.
--
--  Chỉ mục đi kèm để trả lời "dòng bảng giá này đã bán được bao nhiêu hợp
--  đồng" — câu hỏi của mục Doanh thu trong khu điều hành.
ALTER TABLE subscriptions
  ADD KEY idx_subscriptions_plan (plan_id),
  ADD CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id)
    REFERENCES plans (id) ON DELETE RESTRICT;

-- =====================================================================
--  KẾT THÚC — 2 cột thêm. Hợp đồng giờ ghi đủ hạn mức (chi nhánh + tài
--  khoản) và nhớ được nó sinh ra từ dòng bảng giá nào, mà vẫn KHÔNG đi
--  theo bảng giá.
--
--  CÒN NỢ, cố ý: `max_products`. Hôm nay chưa chỗ nào ép hạn mức sản
--  phẩm, và chép một con số không ai đọc xuống hợp đồng chỉ tạo thêm một
--  chỗ để sai — đúng lý do 0003 chưa chép nó. Thêm vào ngày có người ép.
-- =====================================================================
