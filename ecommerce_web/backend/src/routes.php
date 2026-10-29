<?php

declare(strict_types=1);

use App\Controllers\AdminAuthController;
use App\Controllers\AdminCaiDatController;
use App\Controllers\AdminGiaoDienController;
use App\Controllers\AdminPosController;
use App\Controllers\HealthController;
use App\Controllers\ShopController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/api/health', [HealthController::class, 'show']);

    // Route của một shop khai `shop: true`: Router tìm shop theo tên miền đang mở trước khi vào controller.
    $r->get('/api/shop', [ShopController::class, 'show'], shop: true);
    $r->get('/api/khung', [ShopController::class, 'khung'], shop: true);
    $r->get('/api/anh/{ten}', [ShopController::class, 'anh'], shop: true);
    $r->get('/api/trang-chu', [ShopController::class, 'trangChu'], shop: true);
    $r->get('/api/hang', [ShopController::class, 'hang'], shop: true);
    $r->post('/api/dem', [ShopController::class, 'dem'], shop: true);

    // Route quản trị khai `admin: true`: phải có token đăng nhập của đúng shop đó.
    $r->post('/api/admin/login', [AdminAuthController::class, 'login'], shop: true);
    $r->get('/api/admin/me', [AdminAuthController::class, 'me'], admin: true);
    $r->post('/api/admin/logout', [AdminAuthController::class, 'logout'], admin: true);

    // Kết nối POS: kết nối/ngắt bằng phiên POS của người bấm, đọc hàng bằng khoá tích hợp.
    $r->get('/api/admin/pos', [AdminPosController::class, 'show'], admin: true);
    $r->post('/api/admin/pos/ket-noi', [AdminPosController::class, 'connect'], admin: true);
    $r->delete('/api/admin/pos', [AdminPosController::class, 'disconnect'], admin: true);
    $r->get('/api/admin/pos/hang', [AdminPosController::class, 'products'], admin: true);

    // Thông tin cửa hàng: đầu trang, chân trang, màu/font của web.
    $r->get('/api/admin/cai-dat/cua-hang', [AdminCaiDatController::class, 'show'], admin: true);
    $r->put('/api/admin/cai-dat/cua-hang', [AdminCaiDatController::class, 'update'], admin: true);
    $r->post('/api/admin/anh', [AdminCaiDatController::class, 'uploadAnh'], admin: true);

    // Chỉnh sửa giao diện: sửa ghi bản nháp, Áp dụng mới tới khách.
    $r->get('/api/admin/giao-dien', [AdminGiaoDienController::class, 'show'], admin: true);
    $r->put('/api/admin/giao-dien/nhap', [AdminGiaoDienController::class, 'saveDraft'], admin: true);
    $r->post('/api/admin/giao-dien/doi-mau', [AdminGiaoDienController::class, 'switchTheme'], admin: true);
    $r->post('/api/admin/giao-dien/ap-dung', [AdminGiaoDienController::class, 'publish'], admin: true);
    $r->post('/api/admin/giao-dien/bo-nhap', [AdminGiaoDienController::class, 'discard'], admin: true);
    $r->get('/api/admin/truy-cap', [AdminGiaoDienController::class, 'traffic'], admin: true);
};
