-- =====================================================================
--  0032_chi-nhanh-va-the-hang-hoa-2026-08-20.sql
--  Ngày: 20/08/2026
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
--  CHI NHÁNH QUẢN LÝ HÀNG HÓA + THẺ HÀNG HÓA
--
--  Ba ô trong hộp thoại khai mặt hàng đổi chỗ cho nhau:
--
--    "Định mức tồn"  ->  "Chi nhánh"      (product_shops)
--    "Giá bán sỉ"    ->  "Thẻ hàng hóa"   (product_tags + product_tag_links)
--    "Giá khuyến mãi" -> bỏ khỏi hộp thoại, CỘT VẪN CÒN (xem cuối tệp)
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
--  1. BỎ ĐỊNH MỨC TỒN VÀ GIÁ BÁN SỈ
--
--  Ba cột này dựng ở 0027, và tới giờ KHÔNG nơi nào đọc: không báo cáo,
--  không cảnh báo nhập thêm hàng, không bảng giá sỉ. Chúng chỉ đi từ ô
--  nhập xuống database rồi nằm im. Bỏ hẳn thay vì để lại đó — một cột
--  không ai đọc là một cột người sau phải đoán xem có được tin hay không.
--
--  Cửa hàng nào đã lỡ khai định mức thì mất số ấy. Chấp nhận: chưa có màn
--  hình nào bày nó ra để mà tiếc, và giữ lại thì phải giữ luôn cả ô nhập.
-- ---------------------------------------------------------------------
ALTER TABLE products
  DROP COLUMN min_stock,
  DROP COLUMN max_stock,
  DROP COLUMN wholesale_price;

-- ---------------------------------------------------------------------
--  2. CHI NHÁNH QUẢN LÝ MẶT HÀNG
--
--  Mặt hàng của cửa hàng ba chi nhánh không nhất thiết bán ở cả ba: quán
--  ở sân bay không bán nồi lẩu. Bảng này ghi mặt hàng nào thuộc chi nhánh
--  nào, và admin tick được nhiều chi nhánh trong một lượt khai.
--
--  KHÔNG có dòng nào = MỌI CHI NHÁNH. Đây là quy ước cố ý, không phải dữ
--  liệu thiếu:
--
--    * mọi mặt hàng đang có đều rơi vào đây sau lượt chạy này, và chúng
--      đang bán ở khắp nơi thật — nhồi sẵn một dòng cho mỗi cặp
--      (mặt hàng × chi nhánh) là bịa ra một quyết định người dùng chưa hề
--      đưa ra, rồi mở thêm chi nhánh thứ tư là sai hết;
--
--    * cửa hàng một chi nhánh — số đông — không bao giờ phải đụng tới ô
--      này.
--
--  Đổi lại, mọi câu hỏi "chi nhánh X bán những gì" phải viết là
--  "chưa gán chi nhánh nào, HOẶC có gán X".
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_shops (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  product_id BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Một mặt hàng gắn với một chi nhánh đúng MỘT lần.
  UNIQUE KEY uq_product_shops (product_id, shop_id),
  -- Câu hỏi thường gặp là "chi nhánh này quản những mặt hàng nào".
  KEY idx_product_shops_shop (shop_id),
  CONSTRAINT fk_product_shops_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
  -- Xoá cứng mặt hàng thì phần gán đi theo. Xoá mềm thì dòng ở đây nằm
  -- yên, đúng ý — khôi phục mặt hàng là có lại đúng những chi nhánh cũ.
  CONSTRAINT fk_product_shops_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_product_shops_shop    FOREIGN KEY (shop_id)    REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  3. THẺ HÀNG HÓA
--
--  Nhãn người bán tự dán lên mặt hàng: "Bán chạy nhất", "Món mới", "Hàng
--  order"… Thu ngân sẽ bày chúng thành dãy phím lọc để bấm một cái ra
--  ngay nhóm hàng quen bán.
--
--  Là BẢNG TRA chứ không phải một cột JSON chứa danh sách chữ, vì thẻ
--  được gõ tay ở mỗi lượt khai mặt hàng: để tự do thì "Bán chạy",
--  "bán chạy nhất", "Ban chay" thành ba thẻ khác nhau, và dãy phím lọc
--  ngoài quầy đầy thẻ rác. Có bảng tra thì lượt gõ thứ hai TRÚNG LẠI dòng
--  cũ (tầng Go so tên không phân biệt hoa thường), và sau này đổi tên một
--  thẻ là mọi mặt hàng đổi theo.
--
--  KHÔNG seed dòng nào: "Bán chạy nhất" và "Món mới" chỉ là GỢI Ý bày sẵn
--  ở hộp thoại — bấm vào mới sinh ra dòng thật. Cửa hàng không dùng thẻ
--  thì bảng này rỗng mãi mãi, thay vì luôn có hai dòng phải xoá đi.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_tags (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  name       VARCHAR(50) NOT NULL COMMENT 'chữ hiện trên thẻ: Bán chạy nhất, Món mới…',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Tên duy nhất TRONG MỘT cửa hàng, không phải toàn hệ thống. Collation
  -- của cột là utf8mb4_unicode_ci nên khoá này tự nó đã không phân biệt
  -- hoa thường — "Món mới" và "món mới" đụng nhau ngay tại database.
  UNIQUE KEY uq_product_tags_name (tenant_id, name),
  CONSTRAINT fk_product_tags_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_tag_links (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,

  product_id BIGINT UNSIGNED NOT NULL,
  tag_id     BIGINT UNSIGNED NOT NULL,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_product_tag_links (product_id, tag_id),
  -- "Thẻ Món mới đang dán lên những mặt hàng nào" — câu hỏi của thu ngân.
  KEY idx_product_tag_links_tag (tag_id),
  CONSTRAINT fk_product_tag_links_tenant  FOREIGN KEY (tenant_id)  REFERENCES tenants (id),
  CONSTRAINT fk_product_tag_links_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  -- KHÔNG cascade từ thẻ: xoá một thẻ không được phép kéo theo mặt hàng.
  CONSTRAINT fk_product_tag_links_tag     FOREIGN KEY (tag_id)     REFERENCES product_tags (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  4. GIÁ KHUYẾN MÃI — CỘT Ở LẠI
--
--  Ô "Giá khuyến mãi" biến khỏi hộp thoại khai mặt hàng (giảm giá là việc
--  của màn Khuyến mãi), nhưng cột products.sale_price KHÔNG bỏ: nó là giá
--  nền để tính khuyến mãi, là giá bán ra ở luồng đặt đơn, và là cơ sở
--  tính giá trị tồn kho. Bỏ cột là phải viết lại cả ba chỗ đó.
--
--  Không có câu lệnh nào ở mục này. Nó ở đây để người đọc sau không đi
--  tìm xem cột ấy bị bỏ ở tệp nào.
-- ---------------------------------------------------------------------
