-- =====================================================================
--  0008_thue-bao-theo-app-2026-08-12.sql
--  Ngày: 12/08/2026
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
--  MỘT THUÊ BAO THUỘC VỀ MỘT PHẦN MỀM
--
--  0002 dựng `apps` và ghi nợ ngay trong tệp: "`subscriptions` CHƯA có cột
--  app_id. Mọi thuê bao hôm nay ngầm hiểu là thuê bao của app 'order'.
--  Nối hai bảng là migration RIÊNG, và lúc đó phải điền app_id cho các
--  dòng cũ trong cùng một tệp — làm sớm thì rẻ, vì bảng subscriptions hiện
--  chưa có dòng nào." Tệp này trả nợ đó, và bảng vẫn đang 0 dòng.
--
--  VÌ SAO TRẢ BÂY GIỜ: khu điều hành quản lý theo TỪNG PHẦN MỀM — mỗi app
--  một mục doanh thu, một danh sách khách dùng thử, một danh sách khách đã
--  trả tiền, một bảng giá. Ba mục đầu đọc từ bảng này, mà bảng này chưa
--  biết một thuê bao thuộc phần mềm nào thì không mục nào tách ra được.
--
--  CÁI ĐẮT KHÔNG PHẢI CỘT, LÀ KHOÁ DUY NHẤT. `uq_subscriptions_current`
--  đang là (tenant_id, current_mark), tức "mỗi khách MỘT thuê bao còn hiệu
--  lực, chấm hết". Khách đang dùng Sellio Order mà muốn mua thêm phần mềm
--  thứ hai thì MySQL từ chối dòng đó — bán được hàng mà không ghi nổi hợp
--  đồng. Khoá mới là (tenant_id, app_id, current_mark): mỗi khách mỗi phần
--  mềm một thuê bao còn hiệu lực, và vẫn giữ nguyên điều nó bảo vệ từ đầu
--  — không ai có hai hợp đồng chồng nhau cho CÙNG một phần mềm.
--
--  `plan` ĐỔI TỪ ENUM SANG VARCHAR, và đây là phần cần đọc kỹ nhất:
--
--    · ENUM('khoi_dau','cua_hang','chuoi') là ba mã gói CỦA APP 'order'.
--      Phần mềm thứ hai sẽ có bộ gói riêng, tên riêng — nhét chúng vào
--      cùng một ENUM là trộn bảng giá của hai sản phẩm vào một cột.
--    · ENUM cũng không diễn đạt được luật thật: một mã gói hợp lệ cho app
--      này có thể vô nghĩa với app kia. Ràng buộc đúng phải là "(app_id,
--      code) có trong `plans`" — thứ ENUM không biểu diễn nổi.
--    · ĐỔI LẠI, database KHÔNG còn từ chối mã gói gõ sai. Nơi kiểm thay
--      vào đó là tầng ứng dụng, lúc lập hợp đồng: tra `plans` theo
--      (app_id, code, billing_cycle) trước khi ghi. Đây là mất mát thật,
--      nói ra để không ai tưởng nó tự được canh.
--    · VẪN KHÔNG có khoá ngoại sang `plans`, đúng như 0003 đã cấm: thuê
--      bao CHÉP giá và hạn mức ra lúc ký rồi sống độc lập. Khoá ngoại sẽ
--      biến hợp đồng đã ký thành thứ đi theo bảng giá hiện hành, và đổi
--      giá tháng sau là đổi luôn hợp đồng của người đang trả tiền.
--
--  Làm bây giờ vì bảng RỖNG. Tới lúc có khách thật thì cùng việc này phải
--  vừa đổi khoá duy nhất, vừa đổi kiểu một cột, vừa điền ngược app_id cho
--  từng hợp đồng đang chạy — giữa lúc khách đang trả tiền.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. Thêm cột, cho phép NULL trước
-- =====================================================================
--  Ba bước (thêm NULL → điền → siết NOT NULL) chứ không thêm thẳng NOT
--  NULL: thêm thẳng chỉ chạy được trên bảng rỗng. Bảng ở đây đang rỗng
--  thật, nhưng tệp migration phải chạy đúng trên MỌI môi trường, kể cả
--  môi trường mà ai đó vừa chèn tay vài dòng để thử.
ALTER TABLE subscriptions
  ADD COLUMN app_id BIGINT UNSIGNED NULL
  COMMENT 'thuê bao của phần mềm nào — 0002.apps'
  AFTER tenant_id;

-- =====================================================================
--  2. Điền cho các dòng cũ: tất cả đều là thuê bao của 'order'
-- =====================================================================
--  Đúng theo ghi chú của 0002 — trước tệp này, mọi thuê bao ngầm hiểu là
--  của app đang chạy. Tra id qua `apps.code` chứ không gõ số 1: id là số
--  tự sinh của từng database.
UPDATE subscriptions s
  JOIN apps a ON a.code = 'order'
   SET s.app_id = a.id,
       s.updated_at = NOW(3)
 WHERE s.app_id IS NULL;

-- =====================================================================
--  3. Siết NOT NULL
-- =====================================================================
--  Một thuê bao không nói được nó thuộc phần mềm nào là một hợp đồng
--  không biết mình bán cái gì.
ALTER TABLE subscriptions
  MODIFY COLUMN app_id BIGINT UNSIGNED NOT NULL
  COMMENT 'thuê bao của phần mềm nào — 0002.apps';

-- =====================================================================
--  4. Đổi khoá duy nhất + khoá ngoại sang apps
-- =====================================================================
--  Một câu ALTER cho cả bốn việc: MySQL dựng lại bảng cho mỗi lần ALTER,
--  và quan trọng hơn — tách ra thì tệp có thể chết ở giữa và để lại một
--  bảng KHÔNG còn khoá duy nhất nào, tức là lúc đó ghi được hai hợp đồng
--  chồng nhau cho cùng một khách.
--
--  `idx_subscriptions_app` để trả lời câu hỏi của khu điều hành: "phần mềm
--  này đang có bao nhiêu khách dùng thử, bao nhiêu khách đã trả tiền".
--  Khoá ngoại tự sinh chỉ mục trên app_id, nhưng chỉ mục đó đứng một mình;
--  ở đây cần (app_id, status) vì mọi màn hình đều lọc theo trạng thái.
ALTER TABLE subscriptions
  DROP INDEX uq_subscriptions_current,
  ADD UNIQUE KEY uq_subscriptions_current (tenant_id, app_id, current_mark),
  ADD KEY idx_subscriptions_app (app_id, status),
  ADD CONSTRAINT fk_subscriptions_app FOREIGN KEY (app_id) REFERENCES apps (id);

-- =====================================================================
--  5. `plan` thành VARCHAR
-- =====================================================================
--  Cùng độ dài với `plans.code` (VARCHAR(30)) vì hai cột này đối chiếu
--  nhau bằng giá trị: lệch độ dài là một mã gói ghi được ở bảng giá mà
--  không ghi nổi vào hợp đồng.
--
--  Giá trị đang có ('khoi_dau', 'cua_hang', 'chuoi') giữ nguyên từng ký
--  tự — ENUM sang VARCHAR là chuyển nhãn, không phải chuyển số thứ tự.
ALTER TABLE subscriptions
  MODIFY COLUMN plan VARCHAR(30) NOT NULL
  COMMENT 'mã gói, tra ở plans theo (app_id, code, billing_cycle). KHÔNG có FK: hợp đồng đã ký không đi theo bảng giá';

-- =====================================================================
--  KẾT THÚC — 1 cột thêm, 1 cột đổi kiểu, khoá duy nhất mở theo app.
--  Từ đây mỗi khách mua được nhiều phần mềm, mỗi phần mềm một hợp đồng.
-- =====================================================================
