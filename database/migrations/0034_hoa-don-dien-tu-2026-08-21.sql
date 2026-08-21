-- =====================================================================
--  0034_hoa-don-dien-tu-2026-08-21.sql
--  Ngày: 21/08/2026
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
--  KẾT NỐI HOÁ ĐƠN ĐIỆN TỬ
--
--  Cột "Sử dụng HĐĐT" của màn Quản lý chi nhánh. Mỗi chi nhánh nối tới
--  MỘT tài khoản của nhà cung cấp hoá đơn điện tử; hôm nay mới làm
--  M-Invoice, các nhà cung cấp khác thêm sau nên cột `provider` để chuỗi
--  chứ không phải cờ.
--
--  VÌ SAO THEO CHI NHÁNH chứ không phải một dòng cho cả cửa hàng: chuỗi
--  nhiều pháp nhân thì mỗi điểm bán một mã số thuế, và hoá đơn phải phát
--  hành đúng pháp nhân đã bán hàng. Cửa hàng một chi nhánh thì chỉ có
--  một dòng — không mất gì.
--
--  MẬT KHẨU KHÔNG NẰM NGUYÊN VĂN. `password_enc` là chuỗi đã mã hoá bằng
--  pkg/bimat với khoá ETAX_SECRET_KEY trong .env. Chưa khai khoá thì API
--  TỪ CHỐI lưu kèm lý do, chứ không lặng lẽ ghi plaintext: ai cầm được
--  mật khẩu này là phát hành được hoá đơn đứng tên cửa hàng.
--
--  `token` thì ngược lại, để nguyên văn: nó là vé ra vào ngắn hạn do nhà
--  cung cấp cấp, hết hạn thì đăng nhập lại là có cái mới. Mã hoá một thứ
--  tự hết hạn chỉ thêm một bước giải mã ở mọi lượt gọi.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS etax_connections (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NOT NULL,
  shop_id    BIGINT UNSIGNED NOT NULL COMMENT 'chi nhánh dùng tài khoản này',

  provider   VARCHAR(20)  NOT NULL DEFAULT 'minvoice' COMMENT 'minvoice | (thêm sau)',
  tax_code   VARCHAR(30)  NOT NULL COMMENT 'mã số thuế đăng nhập cổng HĐĐT',
  username   VARCHAR(150) NOT NULL,
  password_enc VARCHAR(500) NOT NULL COMMENT 'mật khẩu ĐÃ mã hoá, xem pkg/bimat',
  -- ma_dvcs là mã đơn vị cơ sở bên M-Invoice; tài khoản một chi nhánh dùng
  -- "VP" (văn phòng), chuỗi nhiều đơn vị thì mỗi nơi một mã.
  ma_dvcs    VARCHAR(20)  NOT NULL DEFAULT 'VP',

  token           TEXT     NULL COMMENT 'vé ra vào ngắn hạn do nhà cung cấp cấp',
  token_synced_at DATETIME(3) NULL COMMENT 'lần đăng nhập gần nhất lấy được token',

  -- Ký hiệu hoá đơn dùng để phát hành, chọn từ bảng mẫu kéo về bên dưới.
  template_symbol VARCHAR(20) NULL,
  auto_release    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'tự phát hành khi thanh toán xong',
  auto_print      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'tự in sau khi phát hành',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  -- Một chi nhánh nối tới ĐÚNG một tài khoản. Đổi nhà cung cấp là sửa dòng
  -- này, không phải thêm dòng thứ hai rồi đoán xem dòng nào đang dùng.
  UNIQUE KEY uq_etax_connections_shop (tenant_id, shop_id),
  CONSTRAINT fk_etax_connections_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_etax_connections_shop FOREIGN KEY (shop_id) REFERENCES shops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  MẪU HOÁ ĐƠN KÉO VỀ TỪ NHÀ CUNG CẤP
--
--  Cửa hàng KHÔNG tự khai bảng này: nó là bản chép của danh sách ký hiệu
--  đã đăng ký với cơ quan thuế, kéo về ngay sau lượt kết nối và mỗi lần
--  bấm đồng bộ. Người dùng chỉ chọn một dòng làm ký hiệu phát hành.
--
--  Chép về thay vì gọi thẳng mỗi lần mở hộp thoại: cổng HĐĐT chậm và có
--  lúc sập, mà lúc đó màn hình vẫn phải bày ra được thứ đang chọn.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS etax_templates (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     BIGINT UNSIGNED NOT NULL,
  connection_id BIGINT UNSIGNED NOT NULL,

  symbol    VARCHAR(20)  NOT NULL COMMENT 'ký hiệu hoá đơn (khhdon)',
  form_no   VARCHAR(20)  NULL COMMENT 'mẫu số (invoiceForm)',
  type_name VARCHAR(255) NULL COMMENT 'tên loại hoá đơn (invoiceTypeName)',

  created_at DATETIME(3) NULL,
  updated_at DATETIME(3) NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_etax_templates_symbol (connection_id, symbol),
  CONSTRAINT fk_etax_templates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
  CONSTRAINT fk_etax_templates_conn FOREIGN KEY (connection_id) REFERENCES etax_connections (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
