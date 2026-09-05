-- =====================================================================
--  0051_gia-ban-theo-chi-nhanh-2026-09-04.sql
--  Ngày: 04/09/2026
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
--  GIÁ BÁN THEO CHI NHÁNH
--
--  Tới hôm nay `product_variants.price` là MỘT mức giá cho cả cửa hàng.
--  Chuỗi có hai chi nhánh khác mặt bằng, khác vùng giá, khác loại hình
--  kinh doanh thì không khai nổi hai giá — mà đó chính là thứ người ta mở
--  chi nhánh thứ hai để làm.
--
--  THIẾU DÒNG = DÙNG GIÁ GỐC. Đây là điểm mấu chốt của cách làm này, và
--  nó cùng một luật với `product_shops` (rỗng = mọi chi nhánh) và với quy
--  tắc đánh số (không có dòng thì rơi về mã mặc định):
--
--    · không phải khai trước cho mọi cặp (chi nhánh × biến thể) — một
--      tiệm 500 mặt hàng, 3 chi nhánh là 1.500 dòng phải gieo, mà 1.499
--      trong số đó chỉ chép lại đúng giá gốc;
--    · mở chi nhánh mới là bán được ngay theo giá gốc, không phải khai
--      lại bảng giá;
--    · sửa giá gốc thì mọi chi nhánh chưa khai giá riêng tự đổi theo —
--      đúng ý người sửa.
--
--  KHÔNG có cột "giá vốn theo chi nhánh": giá vốn là số TÍNH RA từ lượt
--  nhập thật của từng kho (xem sổ kho), không phải số người dùng khai.
--  Thêm một ô khai tay cho nó là dựng ra hai nguồn sự thật.
--
--  KHÔNG có lịch sử giá ở đây. Bảng này giữ GIÁ ĐANG ÁP DỤNG. Cần lịch sử
--  thì đó là một bảng khác, và phải chốt trước là ai đọc nó.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS variant_shop_prices (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,

  shop_id            BIGINT UNSIGNED NOT NULL,
  product_variant_id BIGINT UNSIGNED NOT NULL,

  -- Giá bán riêng của chi nhánh này. NOT NULL: một dòng tồn tại nghĩa là
  -- "chi nhánh này có giá riêng", nên nó phải có số. Muốn trả về giá gốc
  -- thì XOÁ dòng, đừng ghi NULL — hai cách nói cùng một điều là chỗ để
  -- code hai bên hiểu khác nhau.
  price DECIMAL(15,2) NOT NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Khoá duy nhất là CẶP: một chi nhánh chỉ có một giá cho một biến thể.
  -- Đây cũng là chỉ mục mà câu đọc giá dùng tới.
  UNIQUE KEY uq_vsp_shop_variant (shop_id, product_variant_id),
  KEY idx_vsp_variant (product_variant_id),
  KEY idx_vsp_tenant (tenant_id),
  CONSTRAINT fk_vsp_tenant  FOREIGN KEY (tenant_id)          REFERENCES tenants (id),
  CONSTRAINT fk_vsp_shop    FOREIGN KEY (shop_id)            REFERENCES shops (id) ON DELETE CASCADE,
  CONSTRAINT fk_vsp_variant FOREIGN KEY (product_variant_id) REFERENCES product_variants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  ON DELETE CASCADE ở cả hai khoá ngoại, khác hẳn các bảng chứng từ:
--  dòng ở đây không phải chứng từ mà là CẤU HÌNH. Đóng một chi nhánh hay
--  xoá một biến thể thì giá riêng của nó không còn nghĩa gì, và giữ lại
--  chỉ để bảng phình ra với những dòng không ai đọc tới.
--
--  Xoá MỀM biến thể (deleted_at) KHÔNG kéo theo dòng này — đúng ý: khôi
--  phục lại mặt hàng thì giá riêng của từng chi nhánh còn nguyên.
-- ---------------------------------------------------------------------
