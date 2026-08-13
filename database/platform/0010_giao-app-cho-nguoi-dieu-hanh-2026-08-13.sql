-- =====================================================================
--  0010_giao-app-cho-nguoi-dieu-hanh-2026-08-13.sql
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
--  NGƯỜI ĐIỀU HÀNH PHỤ TRÁCH PHẦN MỀM NÀO
--
--  0007 trả lời "ai được vào khu điều hành". Từ 0008/0009, nền tảng bán
--  nhiều phần mềm, và câu hỏi tách làm hai: vào được khu điều hành là một
--  chuyện, ĐƯỢC ĐỤNG VÀO PHẦN MỀM NÀO là chuyện khác. Người phụ trách bida
--  không có việc gì với bảng giá của Sellio Order — sửa nhầm một ô ở đó là
--  đổi giá bán của một sản phẩm họ không theo dõi.
--
--  BẢNG GÁN RIÊNG, KHÔNG PHẢI CỘT app_id TRONG platform_users:
--
--    · Một người phụ trách được NHIỀU phần mềm. Nhét vào một cột thì
--      người thứ hai phải tạo tài khoản thứ hai, và cùng một con người có
--      hai mật khẩu, hai dòng nhật ký đăng nhập.
--    · Cột app_id NULL sẽ phải mang nghĩa "phụ trách tất cả", tức là một ô
--      trống quyết định quyền cao nhất. Ô trống là thứ dễ xảy ra nhất khi
--      ai đó thêm dòng bằng tay.
--
--  `owner` KHÔNG CẦN GÁN, và cố ý không có dòng nào trong bảng này: chủ
--  nền tảng nhìn mọi phần mềm, kể cả phần mềm vừa thêm vào danh mục sáng
--  nay. Bắt owner tự gán từng app nghĩa là mở một sản phẩm mới xong thì
--  chính chủ không vào xem được cho tới khi nhớ ra phải gán.
--
--  CHƯA GÁN GÌ = KHÔNG VÀO ĐƯỢC PHẦN MỀM NÀO, với operator và support.
--  Họ vẫn đăng nhập được (họ có tên trong sổ), chỉ là mọi danh sách rỗng.
--  Mặc định phải là "chưa được giao" chứ không phải "được hết": người vừa
--  thêm vào sổ mà đã sửa được bảng giá của mọi sản phẩm là đúng thứ tệp
--  này sinh ra để chặn.
-- =====================================================================

SET NAMES utf8mb4;

-- =====================================================================
--  1. PLATFORM_USER_APPS — ai phụ trách phần mềm nào
-- =====================================================================
--  Khoá chính là chính cặp (người, app): không có id tự tăng vì dòng ở đây
--  không phải một thực thể có đời sống riêng — nó là một mối quan hệ, và
--  cùng một cặp lặp lại hai lần là vô nghĩa.
--
--  ON DELETE CASCADE cả hai chiều:
--    · xoá hẳn một người điều hành thì các dòng giao việc của họ đi theo;
--    · gỡ một phần mềm khỏi danh mục thì không còn ai "phụ trách" nó.
--  Không có nhánh nào để lại dòng mồ côi, và dòng mồ côi ở bảng phân quyền
--  là thứ sẽ được diễn giải nhầm thành quyền vào một ngày nào đó.
CREATE TABLE IF NOT EXISTS platform_user_apps (
  platform_user_id BIGINT UNSIGNED NOT NULL COMMENT 'người điều hành — 0001.platform_users',
  app_id           BIGINT UNSIGNED NOT NULL COMMENT 'phần mềm được giao — 0002.apps',
  created_at       DATETIME(3) NULL,
  PRIMARY KEY (platform_user_id, app_id),
  KEY idx_platform_user_apps_app (app_id),
  CONSTRAINT fk_platform_user_apps_user FOREIGN KEY (platform_user_id)
    REFERENCES platform_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_platform_user_apps_app FOREIGN KEY (app_id)
    REFERENCES apps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Control plane — người điều hành nào phụ trách phần mềm nào. owner KHÔNG cần dòng ở đây: họ nhìn mọi phần mềm.';

-- =====================================================================
--  2. Giao MỌI phần mềm đang có cho các operator/support hiện tại
-- =====================================================================
--  Chỉ chạy cho những người ĐANG CÓ trong sổ lúc tệp này chạy, và đó là
--  chủ ý: trước tệp này họ vốn đã nhìn thấy mọi phần mềm (chưa có luật nào
--  chặn), nên giữ nguyên quyền của họ là KHÔNG đổi hành vi lúc triển khai.
--
--  Người thêm vào sổ SAU tệp này thì bắt đầu từ số không, đúng như phần
--  đầu tệp nói. Cách duy nhất để vừa không cắt quyền của người đang làm
--  việc, vừa để mặc định của người mới là an toàn.
--
--  ON DUPLICATE KEY UPDATE ... = chính nó: chạy lại không sinh dòng thứ
--  hai và không đụng created_at.
INSERT INTO platform_user_apps (platform_user_id, app_id, created_at)
SELECT u.id, a.id, NOW(3)
  FROM platform_users u
  CROSS JOIN apps a
 WHERE u.deleted_at IS NULL
   AND u.role <> 'owner'
ON DUPLICATE KEY UPDATE platform_user_apps.app_id = platform_user_apps.app_id;

-- =====================================================================
--  KẾT THÚC — 1 bảng.
--
--  Đi kèm ở tầng mã nguồn (cùng đợt triển khai):
--    · middleware nạp tập phần mềm được giao vào mỗi request;
--    · /platform/plans chỉ trả bảng giá của phần mềm được giao, và sửa gói
--      của phần mềm không được giao thì 403;
--    · `cmd/nguoi-dieu-hanh giao-app` / `thu-app` để đổi phân công.
-- =====================================================================
