-- ---------------------------------------------------------------------
--  KHUYẾN MÃI VÀ VOUCHER THEO CHI NHÁNH
--
--  Tới trước migration này, hai thứ đó không biết chi nhánh là gì: bật một
--  chương trình giảm giá là nó chạy ở MỌI kho. Nhưng mỗi chi nhánh mỗi loại
--  hình kinh doanh — kho Quận 7 xả hàng cuối mùa thì không có lý do gì kho
--  trung tâm cũng phải bán rẻ theo.
--
--  CÁCH LÀM: bảng tra, KHÔNG phải cột shop_id.
--
--  Một chương trình có thể chạy ở vài chi nhánh chứ không chỉ một, nên một
--  cột không đủ chỗ. Và quan trọng hơn, bảng tra cho phép giữ đúng QUY ƯỚC
--  đã dùng khắp hệ thống này:
--
--      KHÔNG CÓ DÒNG NÀO = ÁP DỤNG CHO MỌI CHI NHÁNH
--
--  Giống hệt product_shops (mặt hàng bán ở đâu), variant_shop_prices (thiếu
--  dòng thì lấy giá gốc), product_shop_locations (thiếu dòng thì chưa xếp
--  kệ). Nhờ vậy MỌI dữ liệu cũ giữ nguyên hành vi: không chương trình nào
--  có dòng gán, nên tất cả tiếp tục chạy toàn cửa hàng như trước, không cần
--  một câu UPDATE nào và không có ngày "chạy migration xong khuyến mãi tắt
--  hết".
--
--  Cách ngược lại — thêm cột shop_id NULL — nhìn thì gọn hơn nhưng chỉ chứa
--  được một chi nhánh, và NULL lại phải mang nghĩa "mọi chi nhánh", tức là
--  vẫn đúng quy ước ấy mà mất khả năng chọn nhiều nơi.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  1. KHUYẾN MÃI
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS promotion_shops (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    BIGINT UNSIGNED NOT NULL,

  promotion_id BIGINT UNSIGNED NOT NULL,
  shop_id      BIGINT UNSIGNED NOT NULL,

  created_at   DATETIME(3) NULL,
  updated_at   DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Một chương trình gắn với một chi nhánh đúng MỘT lần.
  UNIQUE KEY uq_promotion_shops (promotion_id, shop_id),
  -- Câu hỏi thường gặp là "kho này đang chạy chương trình nào".
  KEY idx_promotion_shops_shop (shop_id),
  CONSTRAINT fk_promotion_shops_tenant    FOREIGN KEY (tenant_id)    REFERENCES tenants (id),
  -- Xoá cứng chương trình thì phần gán đi theo; xoá mềm thì dòng ở đây nằm
  -- yên, đúng ý — khôi phục là có lại đúng những chi nhánh cũ.
  CONSTRAINT fk_promotion_shops_promotion FOREIGN KEY (promotion_id) REFERENCES promotions (id) ON DELETE CASCADE,
  CONSTRAINT fk_promotion_shops_shop      FOREIGN KEY (shop_id)      REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  2. VOUCHER
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS voucher_shops (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  voucher_id BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_voucher_shops (voucher_id, shop_id),
  KEY idx_voucher_shops_shop (shop_id),
  CONSTRAINT fk_voucher_shops_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
  CONSTRAINT fk_voucher_shops_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers (id) ON DELETE CASCADE,
  CONSTRAINT fk_voucher_shops_shop    FOREIGN KEY (shop_id)    REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
