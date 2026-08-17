-- =====================================================================
--  0009_thu-ngan-2026-08-17.sql
--  Ngày: 17/08/2026
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
--  VAI TRÒ 3 ĐỔI TÊN: "Nhân viên" -> "Thu ngân"
--
--  Đi kèm lượt siết quyền vừa rồi: vai trò `staff` chỉ còn mở được cụm
--  quầy — bán tại quầy, đơn hàng, ca làm việc & sổ quỹ, hồ sơ của chính
--  mình. Hàng hoá, kho, mua vào, trả hàng, khách hàng, báo cáo và cấu
--  hình đều đã chuyển sang tầng quyền `manage` (xem nhóm route cùng tên
--  trong api/internal/router/router.go).
--
--  "Nhân viên" là một cái tên KHÔNG NÓI GÌ về phạm vi đó: chủ tiệm đọc
--  nó rồi giao cả việc nhập hàng cho người mang vai trò này, và tới lúc
--  họ bấm vào Đặt hàng nhập mới phát hiện là không vào được. Cái tên phải
--  tự nói ra giới hạn của nó, nếu không thì màn hình phân quyền chỉ đúng
--  ở tầng máy chủ còn người dùng vẫn hiểu sai.
--
--  Mô tả cũng đổi theo, và đây mới là chỗ sai thật sự chứ không chỉ khó
--  hiểu: "Xử lý đơn hàng, kho" hôm nay là MÔ TẢ SAI — vai trò này không
--  còn mở được trang kho nào.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. Bộ mặc định của nhà máy.
--
--  Từ migration 0004, `roles` là bảng DÙNG CHUNG cho mọi khách hàng và
--  chỉ còn được ĐỌC trong luồng phục vụ request — tên riêng của từng cửa
--  hàng nằm ở `role_labels`. Nên câu UPDATE này an toàn cho mọi tenant:
--  nó đổi bộ mặc định, không đè lên lựa chọn của ai.
--
--  Ai thấy ngay: cửa hàng dựng SAU 0004 và chưa từng bấm sửa tên vai trò
--  (không có dòng role_labels nào) — họ rơi về đúng bảng này.
--
--  Giới hạn WHERE theo tên cũ để chạy lại tệp không ghi đè một lượt sửa
--  tay nào về sau. Chạy lần hai là 0 dòng, đúng như mong đợi.
-- ---------------------------------------------------------------------
UPDATE roles
   SET display_name = 'Thu ngân',
       description  = 'Bán tại quầy, đơn hàng, ca làm việc',
       updated_at   = NOW(3)
 WHERE id = 3
   AND name = 'staff'
   AND display_name = 'Nhân viên';

-- ---------------------------------------------------------------------
--  2. Cửa hàng CŨ — phần thật sự làm cái tên mới hiện ra.
--
--  Không có khối này thì migration trên gần như không đổi gì trên máy
--  chạy thật: 0004 đã CHỤP LẠI display_name của lúc đó thành một dòng
--  role_labels cho MỌI cửa hàng đang có, và nhãn riêng luôn thắng bộ mặc
--  định. Tức là mọi khách hàng cũ sẽ mãi mãi đọc "Nhân viên", còn khách
--  mới đọc "Thu ngân" — cùng một phần mềm, hai cái tên, và chẳng ai hiểu
--  vì sao.
--
--  CHỈ ĐỘNG VÀO NHÃN CÒN NGUYÊN BẢN MẶC ĐỊNH CŨ. Cửa hàng đã tự đặt tên
--  khác ("Bán hàng", "Nhân viên quầy"...) giữ nguyên tên của họ: đổi nó
--  là sửa dữ liệu người dùng đã cố ý nhập, thứ một migration không được
--  phép làm.
--
--  ĐÁNH ĐỔI, nói rõ ở đây: một cửa hàng từng vào sửa rồi gõ lại đúng chữ
--  "Nhân viên" thì không phân biệt được với bản chụp của 0004, và họ sẽ
--  bị đổi theo. Không có cột nào ghi lại "đã sửa hay chưa" để tra, và
--  chọn chiều ngược lại — bỏ qua tất cả — thì cái tên mới không bao giờ
--  tới được người đang dùng thật.
--
--  Mô tả chỉ đổi cho những dòng còn mang đúng mô tả cũ, xét RIÊNG với
--  tên: hai ô này sửa độc lập trên màn hình Vai trò, nên có cửa hàng đổi
--  tên mà giữ mô tả, và ngược lại.
-- ---------------------------------------------------------------------
UPDATE role_labels
   SET display_name = 'Thu ngân',
       updated_at   = NOW(3)
 WHERE role_id = 3
   AND display_name = 'Nhân viên';

UPDATE role_labels
   SET description = 'Bán tại quầy, đơn hàng, ca làm việc',
       updated_at  = NOW(3)
 WHERE role_id = 3
   AND description = 'Xử lý đơn hàng, kho';

-- =====================================================================
--  KẾT THÚC — không đổi lược đồ, chỉ đổi tên hiển thị của vai trò 3.
--
--  Bộ mặc định trong mã nguồn phải khớp tệp này, nếu không cửa hàng dựng
--  bằng công cụ sẽ mang lại cái tên cũ:
--    - api/cmd/tao-admin/main.go      (vaiTroChuan)
--    - api/internal/apitest/app_test.go (napVaiTroChuan)
-- =====================================================================
