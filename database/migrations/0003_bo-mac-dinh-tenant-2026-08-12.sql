-- =====================================================================
--  0003_bo-mac-dinh-tenant-2026-08-12.sql
--  Ngày: 12/08/2026
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
--  RÚT GIÀN GIÁO: BỎ `DEFAULT 1` CỦA tenant_id
--
--  0002 đặt DEFAULT 1 lên mọi cột tenant_id để hệ thống chạy tiếp được
--  trong lúc tầng Go chưa biết gì về khách hàng. Giàn giáo đó đã xong việc:
--  từ đợt này, plugin GORM tự chèn tenant_id vào mọi câu INSERT và tự thêm
--  `WHERE tenant_id = ?` vào mọi câu đọc/ghi (api/internal/repository/tenant_scope.go).
--
--  VÌ SAO PHẢI BỎ, chứ không để đó cho lành: còn DEFAULT thì một câu INSERT
--  quên cột tenant_id vẫn chạy trót lọt và dòng dữ liệu rơi vào cửa hàng số
--  1. Không có lỗi, không có cảnh báo — chỉ có dữ liệu của khách này nằm
--  trong cửa hàng của khách kia, phát hiện ra thì đã lẫn từ lâu. Bỏ DEFAULT
--  đi thì đúng câu INSERT ấy hỏng ngay lần chạy đầu tiên:
--
--    · MySQL 8 (máy thật, bật STRICT_TRANS_TABLES): lỗi 1364 "Field
--      'tenant_id' doesn't have a default value".
--    · MariaDB (máy dev XAMPP, không strict): ghi 0 rồi vỡ khoá ngoại
--      fk_*_tenant — lỗi 1452, cũng dừng ngay tại đó.
--
--  Hai đường khác nhau nhưng cùng một kết quả: hỏng to, hỏng sớm, hỏng ở
--  đúng chỗ sai. Đó là điều duy nhất mong muốn ở loại lỗi này.
--
--  shop_id GIỮ NGUYÊN DEFAULT 1. Tầng Go chưa ghi cột đó (chi nhánh là đợt
--  sau), bỏ default bây giờ là làm sập mọi lệnh tạo đơn ngay hôm nay để đổi
--  lấy một sự chặt chẽ chưa dùng tới. shop_id cũng KHÔNG phải ranh giới bảo
--  mật — nó chia việc trong nội bộ MỘT khách hàng.
--
--  THỨ TỰ TRIỂN KHAI — ĐỌC TRƯỚC KHI CHẠY TRÊN MÁY THẬT:
--
--  Tệp này KHÔNG tương thích ngược với bản API cũ. Bản cũ không ghi cột
--  tenant_id (nó trông vào DEFAULT), nên trong khoảng thời gian giữa lúc
--  migration chạy xong và lúc binary mới khởi động lại, MỌI LỆNH GHI của bản
--  cũ đều hỏng — đặt hàng, sửa sản phẩm, nhập kho.
--
--  Script triển khai (deploy/scripts/02-trien-khai.sh) chạy migration ở bước 4
--  còn đổi binary ở bước sau, nên khoảng đó dài vài chục giây. Với một cửa hàng
--  thì chấp nhận được; chọn giờ vắng khách là xong. Đọc chỉ bị ảnh hưởng ở chỗ
--  ghi, không có dữ liệu nào sai.
--
--  CÙNG ĐỢT NÀY, mọi người đang đăng nhập sẽ bị đăng xuất: token cũ không mang
--  mã cửa hàng nên bị từ chối, và đó là chủ ý (xem pkg/jwt/jwt.go). Đăng nhập
--  lại một lần là xong.
--
--  CHẠY LẠI ĐƯỢC: có. `ALTER COLUMN ... DROP DEFAULT` trên cột vốn đã không
--  có mặc định là lệnh không làm gì, không báo lỗi.
--
--  KHÔNG có dữ liệu nào bị đụng tới: mặc định chỉ áp dụng cho dòng ghi mới,
--  bỏ nó đi không sửa một dòng nào đang có.
-- =====================================================================

SET NAMES utf8mb4;

-- Xác thực & người dùng
ALTER TABLE users              ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE user_addresses     ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE email_verifications ALTER COLUMN tenant_id DROP DEFAULT;

-- Danh mục sản phẩm
ALTER TABLE categories         ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE brands             ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE products           ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE product_variants   ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE product_images     ALTER COLUMN tenant_id DROP DEFAULT;

-- Giỏ hàng
ALTER TABLE carts              ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE cart_items         ALTER COLUMN tenant_id DROP DEFAULT;

-- Voucher / khuyến mãi
ALTER TABLE vouchers           ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE voucher_usages     ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE promotions         ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE promotion_targets  ALTER COLUMN tenant_id DROP DEFAULT;

-- Đơn hàng & thanh toán
ALTER TABLE orders               ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE order_items          ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE order_status_history ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE payments             ALTER COLUMN tenant_id DROP DEFAULT;

-- Trả hàng (khách trả cho shop)
ALTER TABLE order_returns        ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE order_return_items   ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE order_return_history ALTER COLUMN tenant_id DROP DEFAULT;

-- Kho
ALTER TABLE inventory_transactions ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE variant_stocks         ALTER COLUMN tenant_id DROP DEFAULT;

-- Nhà cung cấp & đặt hàng nhập
ALTER TABLE suppliers               ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE purchase_orders         ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE purchase_order_items    ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE purchase_order_history  ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE purchase_returns        ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE purchase_return_items   ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE purchase_return_history ALTER COLUMN tenant_id DROP DEFAULT;

-- Tương tác người dùng
ALTER TABLE product_reviews    ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE wishlists          ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE notifications      ALTER COLUMN tenant_id DROP DEFAULT;

-- Marketing, cấu hình & nhật ký
ALTER TABLE banners            ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE settings           ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE activity_logs      ALTER COLUMN tenant_id DROP DEFAULT;

-- Yêu cầu từ storefront & danh sách nhận tin
ALTER TABLE contact_requests      ALTER COLUMN tenant_id DROP DEFAULT;
ALTER TABLE newsletter_subscribers ALTER COLUMN tenant_id DROP DEFAULT;

-- =====================================================================
--  KẾT THÚC — 38 cột tenant_id không còn giá trị mặc định.
--  Từ đây, quên tenant_id là lỗi ngay lập tức chứ không còn là rơi âm
--  thầm vào cửa hàng số 1.
-- =====================================================================
