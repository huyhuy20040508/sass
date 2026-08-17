-- =====================================================================
--  0006_ban-tai-quay-2026-08-16.sql
--  Ngày: 16/08/2026
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
--  BÁN HÀNG TẠI QUẦY (GIAI ĐOẠN 1)
--
--  Tới giờ mọi đơn trong bảng `orders` đều là đơn CÓ GIAO HÀNG: khách tự
--  đặt trên storefront, hoặc nhân viên đặt hộ khi khách gọi điện. Cả hai
--  đều bắt buộc có địa chỉ nhận, đi qua chuỗi chờ xác nhận → đang giao →
--  đã giao, và thu tiền ở một thời điểm khác lúc đặt.
--
--  Bán tại quầy khác ở cả ba điểm: không có địa chỉ giao, hàng trao tay
--  ngay nên đơn sinh ra đã hoàn tất, và tiền thu xong trước khi khách rời
--  quầy. Tệp này thêm đúng những cột để ghi được ba điều đó, chứ không
--  dựng bảng đơn riêng: doanh thu, tồn kho, lượt bán, trả hàng và báo cáo
--  đều đang đọc `orders` — tách bảng là phải sửa hết những chỗ ấy để đổi
--  lấy một thứ mà một cột phân biệt là đủ.
-- =====================================================================

-- ---------------------------------------------------------------------
--  1. `channel` — đơn này phát sinh Ở ĐÂU.
--
--  'web'  = đơn có giao hàng: khách tự đặt trên storefront, hoặc nhân viên
--           đặt hộ qua điện thoại (POST /admin/orders). Hai đường này khác
--           nhau ở NGƯỜI bấm nút, còn bản chất đơn thì giống hệt: có địa
--           chỉ, có phí ship, thu tiền sau.
--  'pos'  = bán tại quầy: khách đứng trước mặt, trả tiền và cầm hàng đi.
--
--  Mọi dòng đang có nhận 'web' theo DEFAULT — đúng, vì tới trước lượt
--  triển khai này chưa có đường nào tạo được đơn tại quầy.
--
--  Có chỉ mục vì đây sẽ là bộ lọc thường trực của trang đơn hàng và của
--  báo cáo doanh thu ("hôm nay quầy bán được bao nhiêu"), chứ không phải
--  cột chỉ để đọc khi đã mở đúng một đơn.
-- ---------------------------------------------------------------------
ALTER TABLE orders
  ADD COLUMN channel ENUM('web','pos') NOT NULL DEFAULT 'web'
    COMMENT 'nơi phát sinh đơn: web = có giao hàng, pos = bán tại quầy'
    AFTER order_code,
  ADD KEY idx_orders_channel (channel);

-- ---------------------------------------------------------------------
--  2. `cash` — tiền mặt, hình thức thanh toán của quầy.
--
--  Danh sách cũ dựng cho đơn giao hàng nên không có nó: 'cod' là thu hộ
--  qua shipper, tức là tiền về sau và có thể không về. Tiền mặt tại quầy
--  là tiền đã nằm trong két trước khi khách bước ra cửa — gộp hai thứ này
--  vào một mã nghĩa là sổ thu chi không phân biệt được khoản đã có với
--  khoản đang chờ.
--
--  MODIFY giữ nguyên thứ tự các giá trị cũ và chỉ thêm vào cuối: ENUM lưu
--  theo chỉ số, đảo thứ tự là mọi dòng cũ đọc ra sai giá trị.
-- ---------------------------------------------------------------------
ALTER TABLE orders
  MODIFY COLUMN payment_method
    ENUM('cod','vnpay','momo','bank_transfer','payos','sepay','cash')
    NOT NULL DEFAULT 'cod';

-- ---------------------------------------------------------------------
--  3. Tiền khách đưa và tiền thối lại.
--
--  NULL chứ không phải 0, và đó là khác biệt có nghĩa: NULL = "không áp
--  dụng" (đơn giao hàng, hoặc khách quẹt thẻ / chuyển khoản đúng số), 0 =
--  "có nhận tiền mặt và khách đưa vừa đủ". Để DEFAULT 0 thì hai trường hợp
--  ấy trông giống nhau, và ca cuối ngày không đối chiếu được két với sổ.
--
--  Cột `change_amount` được lưu chứ không tính lại từ (đưa − tổng): tổng
--  tiền của đơn còn sửa được sau đó (trả hàng một phần), mà số tiền đã
--  thối cho khách hôm ấy thì không đổi nữa. Đây là con số của một lần giao
--  dịch tại quầy, không phải một phép tính trên trạng thái hiện tại.
--
--  Địa chỉ giao (shipping_address và ba cột tỉnh/quận/phường) CỐ Ý giữ
--  nguyên NOT NULL: tầng ứng dụng ghi chuỗi rỗng cho đơn quầy, nên nới ra
--  NULL không thay đổi được gì mà lại tạo hai cách biểu diễn cho cùng một
--  ý "không có địa chỉ" — mọi truy vấn về sau phải nhớ kiểm tra cả hai.
--  Chỗ thật sự phải nới là ràng buộc bắt buộc nhập của API, nằm trong DTO.
-- ---------------------------------------------------------------------
ALTER TABLE orders
  ADD COLUMN amount_tendered DECIMAL(12,2) NULL
    COMMENT 'tiền mặt khách đưa tại quầy; NULL = không thu bằng tiền mặt'
    AFTER total_amount,
  ADD COLUMN change_amount DECIMAL(12,2) NULL
    COMMENT 'tiền thối lại cho khách; NULL = không thu bằng tiền mặt'
    AFTER amount_tendered;
