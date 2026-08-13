-- =====================================================================
--  0006_ai-vao-khu-dieu-hanh-2026-08-12.sql
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
--  AI ĐƯỢC VÀO KHU ĐIỀU HÀNH — VÀ VÌ SAO "super_admin" KHÔNG PHẢI CÂU
--  TRẢ LỜI
--
--  0005 mở bảng giá cho khu điều hành SỬA qua API. Trước khi có đường ghi
--  đó, câu hỏi "ai vào được khu điều hành" chưa tốn kém gì: trang tổng
--  quan chỉ hiện dấu gạch ngang. Từ đợt này nó quyết định ai được đổi giá
--  bán và ai được bật tên miền riêng cho gói của mình.
--
--  ĐIỀU PHẢI NHÌN THẲNG: `/auth/platform-login` hôm nay nhận MỌI tài khoản
--  có vai trò `super_admin` trong data plane — mà mỗi cửa hàng đều có một
--  super_admin của riêng họ (chủ shop, do `cmd/tao-admin` tạo). Nghĩa là
--  nếu nhóm /platform/… chỉ xét vai trò, thì chủ của một cửa hàng bất kỳ
--  đăng nhập được vào khu điều hành và sửa BẢNG GIÁ CỦA CẢ NỀN TẢNG —
--  gồm cả việc tự bật own_domain cho gói mình đang dùng. Đó không phải
--  suy diễn xa: hôm nay database này đã có hai super_admin ở hai cửa hàng.
--
--  Chú thích trong service/auth_service.go (LoginPlatform) đã hẹn trước
--  đúng chỗ này: "Ngày mở nhóm /platform/…, quyền xem xuyên cửa hàng phải
--  do NHÓM ĐÓ tự xét theo vai trò, chứ không phải do token này rộng sẵn."
--  Tệp này dựng cái sổ để nhóm đó xét.
--
--  CÁCH LÀM: `platform_users` (0001) đã là bảng "người của nền tảng". Nó
--  còn thiếu đúng một thứ để dùng được hôm nay — biết dòng của mình ứng
--  với TÀI KHOẢN ĐĂNG NHẬP nào. Thêm hai cột đó vào là xong; không dựng
--  bảng mới, vì bảng mới sẽ phải bỏ đi vào ngày đăng nhập chuyển hẳn sang
--  đây (0001 đã ghi việc đó là "đợt sau").
--
--  VÌ SAO NỐI BẰNG (tenant_id, user_id) CHỨ KHÔNG BẰNG EMAIL: email của
--  data plane chỉ duy nhất TRONG MỘT cửa hàng, và chủ shop tự tạo tài
--  khoản trong cửa hàng của họ với email nào cũng được. Nối bằng email thì
--  chỉ cần đoán trúng email người điều hành, tạo một tài khoản trùng email
--  với vai trò super_admin trong cửa hàng của mình, là vào được khu điều
--  hành — tức là cái cổng này tự mở bằng đúng thứ nó định chặn. Cặp
--  (tenant_id, user_id) thì khách không tự khai được: nó nằm trong token
--  đã ký, và dòng ở bảng này chỉ người điều hành mới ghi được
--  (`cmd/nguoi-dieu-hanh`, chạy trên máy chủ).
--
--  ĐÂY LÀ CÁI CẦU TẠM, và nó được viết ra để tháo: ngày
--  `/auth/platform-login` xác thực THẲNG vào bảng này (email + mật khẩu
--  của chính nó), hai cột dưới đây thành thừa và bị bỏ đi. Chừng đó thì
--  `password_hash` mới có việc để làm.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. password_hash được phép NULL
-- =====================================================================
--  0001 khai nó NOT NULL vì bảng này sinh ra để tự đăng nhập. Hôm nay thì
--  chưa: người điều hành vẫn gõ mật khẩu của tài khoản data plane ở màn
--  hình đăng nhập, và dòng bên này chỉ trả lời "người đó có được vào
--  không, với vai trò gì".
--
--  Bắt `cmd/nguoi-dieu-hanh` đặt một mật khẩu ngay bây giờ là tạo ra một ô
--  mật khẩu KHÔNG DÙNG ĐỂ ĐĂNG NHẬP — thứ người ta sẽ đặt, sẽ tin là đã
--  đổi, và sẽ ngạc nhiên khi mật khẩu cũ vẫn vào được. NULL nói đúng sự
--  thật: tài khoản này chưa có mật khẩu riêng.
ALTER TABLE platform_users
  MODIFY COLUMN password_hash VARCHAR(255) NULL
  COMMENT 'bcrypt, cùng thuật toán với selliotech.users. NULL = chưa có mật khẩu riêng, đăng nhập vẫn đi qua tài khoản data plane nối ở dưới';

-- =====================================================================
--  2. Hai cột nối sang tài khoản data plane
-- =====================================================================
--  Cả hai NULL được: dòng chưa nối là người sẽ chỉ đăng nhập được vào ngày
--  bảng này tự xác thực. Nối rồi thì cặp (tenant_id, user_id) là thứ
--  middleware so với token — xem middleware.KhuDieuHanh.
--
--  KHÔNG có khoá ngoại, và không thể có: hai cột này trỏ sang database
--  KHÁC (selliotech.tenants, selliotech.users). Đây cũng chính là cách
--  `tenants.id` bên control plane đang sống — số chung, không ràng buộc,
--  xem 0001.
--
--  KHÔNG dùng IF NOT EXISTS: MariaDB (máy cục bộ) cho phép, MySQL 8 (máy
--  thật) thì không.
ALTER TABLE platform_users
  ADD COLUMN tenant_id BIGINT UNSIGNED NULL
    COMMENT 'cửa hàng của tài khoản data plane dùng để đăng nhập; NULL = chưa nối'
    AFTER role,
  ADD COLUMN user_id BIGINT UNSIGNED NULL
    COMMENT 'selliotech.users.id của tài khoản đó; cặp với tenant_id ở trên'
    AFTER tenant_id;

-- =====================================================================
--  3. Một tài khoản đăng nhập nối tối đa MỘT người điều hành
-- =====================================================================
--  Không có khoá này thì hai dòng cùng trỏ vào một tài khoản là hợp lệ, và
--  câu "người này vai trò gì" có hai câu trả lời — middleware sẽ lấy dòng
--  nào MySQL trả trước, tức là quyền của một người phụ thuộc vào thứ tự
--  đọc đĩa.
--
--  Kèm `deleted_mark` giống hệt khoá email của 0001: người nghỉ việc (xoá
--  mềm) rồi quay lại vẫn nối lại được đúng tài khoản cũ.
--
--  MySQL coi mỗi NULL là một giá trị khác nhau trong khoá UNIQUE, nên các
--  dòng CHƯA NỐI không đụng nhau.
ALTER TABLE platform_users
  ADD UNIQUE KEY uq_platform_users_tai_khoan (tenant_id, user_id, deleted_mark);

-- =====================================================================
--  KẾT THÚC — 2 cột thêm, 1 cột nới lỏng, 1 khoá duy nhất. Bảng vẫn RỖNG:
--  người điều hành đầu tiên do `go run ./cmd/nguoi-dieu-hanh them` tạo, và
--  chừng nào chưa có dòng nào thì KHÔNG AI vào được nhóm /platform/… —
--  đúng ý, cổng đóng là mặc định an toàn.
-- =====================================================================
