-- =====================================================================
--  0045_bo-mo-ta-ngan-va-seo-2026-08-31.sql
--  Ngày: 31/08/2026
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
--  BỎ MÔ TẢ NGẮN VÀ HAI Ô SEO KHỎI MẶT HÀNG
--
--  Ba cột `products.short_description`, `products.meta_title`,
--  `products.meta_description` có từ migration nền 0001, dựng theo hình
--  dung ban đầu là sẽ có một storefront đọc chúng.
--
--  Thực tế đến giờ: không màn nào khai chúng. Hộp thoại mặt hàng của
--  khu v2 chỉ có MỘT ô mô tả (`description`) — đúng như bản v2 cũ. Ba
--  cột này chỉ tồn tại trong lớp validate và payload của Shop Admin,
--  nhận vào rồi ghi xuống, không ai đọc lên.
--
--  Nguy hơn: `ShortDescription`/`MetaTitle`/`MetaDescription` bên Go là
--  string thường chứ không phải con trỏ, nên hộp thoại chỉ cần không
--  gửi khoá là API ghi đè chuỗi rỗng lên dữ liệu cũ — mất âm thầm y hệt
--  ca `raw_material`. Bỏ hẳn ba cột thì không còn chỗ cho lỗi đó.
--
--  Khi nào làm storefront thật, SEO là việc của module storefront: khai
--  ở bảng riêng của nó, kèm slug / ảnh chia sẻ / og:*, chứ không phải ba
--  cột chữ treo trên bảng mặt hàng.
-- =====================================================================

ALTER TABLE products
    DROP COLUMN short_description,
    DROP COLUMN meta_title,
    DROP COLUMN meta_description;
