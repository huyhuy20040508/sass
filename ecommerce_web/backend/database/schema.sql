-- Một database cho MỌI shop; dữ liệu của shop nào mang shop_id của shop đó.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shops (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Trùng mã cửa hàng bên POS (store_id trên link, vd "kimhana") để nhìn link là biết đơn về tiệm nào.
    code       VARCHAR(30)  NOT NULL UNIQUE,
    name       VARCHAR(150) NOT NULL,
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shop_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Unique theo shop chứ không toàn bảng: một người quản lý hai tiệm dùng chung email được.
    UNIQUE KEY uq_admins_shop_email (shop_id, email),
    CONSTRAINT fk_admins_shop FOREIGN KEY (shop_id) REFERENCES shops (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
