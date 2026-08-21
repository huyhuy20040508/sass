<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\BanTaiQuayController;
use App\Http\Controllers\CaLamViecController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChiNhanhController;
use App\Http\Controllers\ChonCuaVaoController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DonViTinhController;
use App\Http\Controllers\GoiDichVuController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\TonKhoChiNhanhController;
use App\Http\Controllers\NhanSuController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PhanQuyenController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\ThongSoChungController;
use App\Http\Controllers\ThueController;
use App\Http\Controllers\ThuNganController;
use App\Http\Controllers\ThuocTinhController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ViTriController;
use App\Http\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

// --- Route gốc: về module của người đang đăng nhập ---
//
// Nhân viên (thu ngân) về thẳng quầy, chủ tiệm về khu quản trị — xem
// ModuleLamViec. Chưa đăng nhập thì cứ đi vào /admin, chốt chặn ở đó sẽ đưa
// sang trang đăng nhập kèm lý do.
Route::get('/', fn () => session('api.access_token')
    ? redirect()->to(\App\Services\ModuleLamViec::trangChuCuaPhien())
    : redirect('/admin'));

// --- Khách (chưa đăng nhập) ---
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

// --- Đăng xuất (KHÔNG nằm trong admin.auth để tránh mất request khi session hết hạn) ---
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.forgot');

// --- MÀN CHỌN CỬA VÀO — ngã ba giữa trang đăng nhập và chỗ làm việc ---
//
// Nằm NGOÀI cả hai nhóm module, và bắt buộc phải vậy: tới đây người dùng chưa
// chọn khu nào, nên gắn `admin.cua` vào đây là một chốt chặn hỏi đúng câu mà màn
// hình này sinh ra để hỏi. Chỉ đòi đã đăng nhập.
//
// `admin.khoa` thì vẫn giữ: cửa hàng hết hạn hợp đồng chẳng có khu nào mở, và
// mời họ chọn giữa hai ô rồi đá cả hai về trang gói dịch vụ là bắt bấm một lần
// vô nghĩa ngay lúc họ đang cần biết phải gia hạn.
Route::middleware(['admin.auth', 'admin.khoa'])->group(function () {
    Route::get('/choose-workspace', [ChonCuaVaoController::class, 'index'])->name('chon-cua');
    Route::post('/choose-workspace', [ChonCuaVaoController::class, 'vao'])->name('chon-cua.vao');
});

// HAI ĐƯỜNG TRA CỨU DÙNG CHUNG cho cả hai module — nằm NGOÀI cửa `quan_ly`.
//
// Màn hình bán tại quầy gọi đúng hai đường này để tìm hàng và tìm khách (xem
// thu-ngan/ban-hang.blade.php). Chúng mang tiền tố /admin vì trang tạo đơn của
// khu quản trị dựng ra chúng trước, nhưng chúng là lượt TRA CỨU trả JSON, không
// phải một trang của khu quản trị — đóng lại theo cửa `quan_ly` là người trực
// quầy gõ tên hàng mà không ra gì, và không có gì trên màn hình nói vì sao.
Route::middleware(['admin.auth', 'admin.khoa'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/orders/new/customers', [OrderController::class, 'searchCustomers'])->name('orders.searchCustomers');
    Route::get('/orders/new/products', [OrderController::class, 'searchProducts'])->name('orders.searchProducts');

    // NGHIỆP VỤ THU NGÂN ĐÃ TÁCH SANG MODULE RIÊNG (/cashier, xem nhóm route ở
    // cuối tệp). Bốn đường dưới đây giữ lại ĐÚNG để chuyển hướng: máy quầy hay
    // được đặt sẵn trang chủ trình duyệt là /admin/ban-tai-quay, và những đường
    // dẫn ấy nằm rải rác trong ghi chú của cửa hàng. Bỏ hẳn thì một sáng nào đó
    // người trực quầy mở máy lên và gặp trang 404.
    //
    // Chúng nằm NGOÀI cửa `quan_ly` cùng lý do với hai đường tra cứu trên: người
    // trực quầy mới là người gõ đúng mấy đường này. Để trong cửa thì họ bị đá về
    // trang bán hàng — đúng module, nhưng SAI TRANG, và cái phiếu họ định in lại
    // hay ca họ định mở ra xem thì mất dấu.
    Route::get('/ban-tai-quay', fn () => redirect()->route('thu-ngan.ban-hang.index'));
    Route::get('/ban-tai-quay/{id}/phieu', fn (int $id) => redirect()->route(
        'thu-ngan.ban-hang.phieu', ['id' => $id, 'kho' => request()->query('kho')]
    ))->whereNumber('id');
    Route::get('/ca-lam-viec', fn () => redirect()->route('thu-ngan.ca-lam-viec.index'));
    Route::get('/ca-lam-viec/{id}', fn (int $id) => redirect()->route('thu-ngan.ca-lam-viec.show', $id))
        ->whereNumber('id');
});

// --- KHU QUẢN TRỊ (đăng nhập + cửa `quan_ly`) ---
//
// `admin.khoa` chạy ngay sau `admin.auth`: cửa hàng hết hạn hợp đồng thì mọi
// đường trong nhóm này dồn về trang Các gói dịch vụ. Chốt chặn thật nằm ở Go API
// (403 kèm mã CUA_HANG_KHOA); middleware ở đây chỉ để người dùng nhìn thấy một
// trang nói rõ phải làm gì, thay vì lỗi rải rác ở từng mục.
//
// Người chỉ đứng quầy không mở được BẤT KỲ trang nào ở đây, kể cả Tổng quan:
// trước đây họ vào được và gặp một thanh trái gần như trống rỗng, còn nút đổi
// module thì vẫn mời họ sang. Cửa đặt ở đúng một chỗ này thay vì rải `admin.cua`
// lên từng nhóm con — thêm một trang mới là nó nằm trong cửa sẵn, không phải nhớ.
Route::middleware(['admin.auth', 'admin.khoa', 'admin.cua:quan_ly'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => redirect()->route('admin.dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Tài khoản của tôi — hồ sơ + mật khẩu của chính người đang đăng nhập.
    //
    // Đòi cửa `quan_ly`: người CHỈ đứng quầy không xem hồ sơ của mình ở đây, menu
    // của họ chỉ còn nút Đăng xuất. Cả trang này thuộc khu quản trị, và khu đó
    // không phải chỗ của họ — để hở một đường vào thì thanh trái, chuông thông
    // báo và mọi thứ khác của khu ấy cũng hiện ra theo.
    //
    // ĐÁNH ĐỔI, ghi ra để lần sau khỏi phải đoán: họ mất luôn đường TỰ ĐỔI MẬT
    // KHẨU. Từ nay việc đó do chủ tiệm đặt lại hộ trong mục Nhân sự. Muốn trả
    // lại thì kéo riêng `profile/password` ra nhóm dùng chung phía trên, đừng
    // kéo cả ba đường.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

    // Thông báo & realtime (JSON cho chuông trên topbar)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/stream-token', [NotificationController::class, 'streamToken'])->name('notifications.streamToken');
    // Trang tự chẩn đoán khi realtime không chạy mà console không báo lỗi gì.
    //
    // Nhóm `admin.manage`: nút "bắn thử" ghi một thông báo THẬT vào sổ, và API
    // chỉ mở đường đó cho quản trị viên. Để ngoài thì nhân viên mở được trang
    // chẩn đoán rồi bấm nút và nhận 403 — một màn hình chuyên để chỉ ra chỗ hỏng
    // mà lại tự bày ra một chỗ hỏng giả.
    Route::middleware('admin.manage')->group(function () {
        Route::get('/notifications/check', [NotificationController::class, 'check'])->name('notifications.check');
        Route::post('/notifications/test', [NotificationController::class, 'test'])->name('notifications.test');
    });
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id')->name('notifications.read');

    // --- Hàng hoá & tiếp thị: nhân viên (staff) KHÔNG vào ---
    //
    // Sản phẩm, danh mục, thương hiệu, banner, khuyến mãi, voucher. Đây là nơi
    // đặt ra GIÁ BÁN và mức giảm giá của cả cửa hàng, và trang sản phẩm còn phơi
    // cả giá vốn — việc của chủ tiệm, không phải của người đứng quầy. Go API chặn
    // đúng nhóm endpoint tương ứng (nhóm `manage` trong internal/router/router.go);
    // middleware ở đây chỉ để báo sớm và ẩn menu.
    Route::middleware('admin.manage')->group(function () {
        // Danh mục sản phẩm
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/categories/bulk-destroy', [CategoryController::class, 'bulkDestroy'])->name('categories.bulkDestroy');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{id}/children', [CategoryController::class, 'destroyChildren'])->name('categories.destroyChildren');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        // Thuộc tính — bảng tra hai tầng: thuộc tính (Kích cỡ, Mức đá) và các giá
        // trị của nó (S/M/L). Đứng ngay sau Nhóm hàng hóa, đúng thứ tự bản cũ v2.
        Route::get('/attributes', [ThuocTinhController::class, 'index'])->name('thuoc-tinh.index');
        Route::post('/attributes', [ThuocTinhController::class, 'store'])->name('thuoc-tinh.store');
        Route::post('/attributes/bulk-destroy', [ThuocTinhController::class, 'bulkDestroy'])->name('thuoc-tinh.bulkDestroy');
        Route::put('/attributes/{id}/status', [ThuocTinhController::class, 'toggleStatus'])->whereNumber('id')->name('thuoc-tinh.toggleStatus');
        Route::put('/attributes/{id}', [ThuocTinhController::class, 'update'])->whereNumber('id')->name('thuoc-tinh.update');
        Route::delete('/attributes/{id}', [ThuocTinhController::class, 'destroy'])->whereNumber('id')->name('thuoc-tinh.destroy');

        // Đơn vị tính — bảng tra gắn cho mặt hàng. Đứng giữa Nhóm hàng hóa và
        // Thuế, đúng thứ tự của bản cũ v2 (Menu QR: hàng hoá → nhóm → đơn vị → thuế).
        Route::get('/units', [DonViTinhController::class, 'index'])->name('don-vi-tinh.index');
        Route::post('/units', [DonViTinhController::class, 'store'])->name('don-vi-tinh.store');
        Route::post('/units/bulk-destroy', [DonViTinhController::class, 'bulkDestroy'])->name('don-vi-tinh.bulkDestroy');
        Route::put('/units/{id}/status', [DonViTinhController::class, 'toggleStatus'])->whereNumber('id')->name('don-vi-tinh.toggleStatus');
        Route::put('/units/{id}', [DonViTinhController::class, 'update'])->whereNumber('id')->name('don-vi-tinh.update');
        Route::delete('/units/{id}', [DonViTinhController::class, 'destroy'])->whereNumber('id')->name('don-vi-tinh.destroy');

        // Vị trí — chỗ để hàng ("Kệ A - Tầng 1", "Kho lạnh"). Bản cũ v2 không có
        // màn này; dựng theo đúng khuôn Đơn vị tính ngay trên.
        Route::get('/locations', [ViTriController::class, 'index'])->name('vi-tri.index');
        Route::post('/locations', [ViTriController::class, 'store'])->name('vi-tri.store');
        Route::post('/locations/bulk-destroy', [ViTriController::class, 'bulkDestroy'])->name('vi-tri.bulkDestroy');
        Route::put('/locations/{id}/status', [ViTriController::class, 'toggleStatus'])->whereNumber('id')->name('vi-tri.toggleStatus');
        Route::put('/locations/{id}', [ViTriController::class, 'update'])->whereNumber('id')->name('vi-tri.update');
        Route::delete('/locations/{id}', [ViTriController::class, 'destroy'])->whereNumber('id')->name('vi-tri.destroy');

        // Thuế suất — bốn loại cố định, chỉ sửa bộ mức và bật/tắt (không thêm/xoá).
        Route::get('/taxes', [ThueController::class, 'index'])->name('thue.index');
        Route::put('/taxes/{id}', [ThueController::class, 'update'])->name('thue.update');
        Route::put('/taxes/{id}/status', [ThueController::class, 'toggleStatus'])->name('thue.toggleStatus');

        // Sản phẩm
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/export', [ProductController::class, 'export'])->name('products.export');
        Route::get('/products/import-template', [ProductController::class, 'importTemplate'])->name('products.importTemplate');
        Route::post('/products/import', [ProductController::class, 'import'])->name('products.import');
        Route::post('/products/upload-image', [ProductController::class, 'uploadImage'])->name('products.uploadImage');
        // JSON chi tiết một sản phẩm — modal sửa nạp lại dữ liệu mới nhất trước khi
        // cho sửa, thay vì dùng bản đã nhúng sẵn trong trang (có thể đã cũ).
        Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id')->name('products.show');
        Route::post('/products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulkDestroy');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/{id}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
        Route::put('/products/{id}/toggle-status', [ProductController::class, 'toggleStatus'])->name('products.toggleStatus');
        Route::put('/products/{id}/sort', [ProductController::class, 'moveSort'])->name('products.moveSort');
        Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');


        // Banner trang chủ — nội dung tiếp thị hiện trên storefront.
        Route::get('/banners', [BannerController::class, 'index'])->name('banners.index');
        Route::get('/banners/export', [BannerController::class, 'export'])->name('banners.export');
        Route::post('/banners/upload-image', [BannerController::class, 'uploadImage'])->name('banners.uploadImage');
        Route::post('/banners/bulk-destroy', [BannerController::class, 'bulkDestroy'])->name('banners.bulkDestroy');
        Route::post('/banners', [BannerController::class, 'store'])->name('banners.store');
        Route::put('/banners/{id}/toggle-status', [BannerController::class, 'toggleStatus'])->whereNumber('id')->name('banners.toggleStatus');
        Route::put('/banners/{id}/move', [BannerController::class, 'move'])->whereNumber('id')->name('banners.move');
        Route::put('/banners/{id}', [BannerController::class, 'update'])->whereNumber('id')->name('banners.update');
        Route::delete('/banners/{id}', [BannerController::class, 'destroy'])->whereNumber('id')->name('banners.destroy');

        // Chương trình khuyến mãi — quyết định giá bán ngoài cửa hàng.
        Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::get('/promotions/export', [PromotionController::class, 'export'])->name('promotions.export');
        Route::post('/promotions/bulk-destroy', [PromotionController::class, 'bulkDestroy'])->name('promotions.bulkDestroy');
        Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::put('/promotions/{id}/toggle-status', [PromotionController::class, 'toggleStatus'])->whereNumber('id')->name('promotions.toggleStatus');
        Route::put('/promotions/{id}', [PromotionController::class, 'update'])->whereNumber('id')->name('promotions.update');
        Route::delete('/promotions/{id}', [PromotionController::class, 'destroy'])->whereNumber('id')->name('promotions.destroy');

        // Voucher — mã khách tự nhập lúc thanh toán, giảm trên tổng đơn.
        Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
        Route::get('/vouchers/export', [VoucherController::class, 'export'])->name('vouchers.export');
        Route::post('/vouchers/bulk-destroy', [VoucherController::class, 'bulkDestroy'])->name('vouchers.bulkDestroy');
        Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
        Route::put('/vouchers/{id}/toggle-status', [VoucherController::class, 'toggleStatus'])->whereNumber('id')->name('vouchers.toggleStatus');
        Route::put('/vouchers/{id}', [VoucherController::class, 'update'])->whereNumber('id')->name('vouchers.update');
        Route::delete('/vouchers/{id}', [VoucherController::class, 'destroy'])->whereNumber('id')->name('vouchers.destroy');
    });


    // Đơn hàng
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/export', [OrderController::class, 'export'])->name('orders.export');
    // In hàng loạt (?ids=1,2,3) + xác nhận hàng loạt — đặt trước route {id} để không bị nuốt.
    Route::get('/orders/print', [OrderController::class, 'print'])->name('orders.printBatch');
    Route::get('/orders/label', [OrderController::class, 'label'])->name('orders.labelBatch');
    Route::post('/orders/bulk-status', [OrderController::class, 'bulkStatus'])->name('orders.bulkStatus');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('/orders/{id}/detail', [OrderController::class, 'detail'])->name('orders.detail');
    // Phát hành hoá đơn điện tử cho một đơn — trả JSON, nút nằm trong hộp chi tiết.
    Route::post('/orders/{id}/etax', [OrderController::class, 'phatHanhHoaDon'])->name('orders.phatHanhHoaDon');
    Route::get('/orders/{id}/print', [OrderController::class, 'print'])->name('orders.print');
    Route::get('/orders/{id}/label', [OrderController::class, 'label'])->name('orders.label');
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');
    Route::put('/orders/{id}/payment', [OrderController::class, 'updatePayment'])->name('orders.updatePayment');
    Route::put('/orders/{id}/shipping', [OrderController::class, 'updateShipping'])->name('orders.updateShipping');
    Route::put('/orders/{id}/note', [OrderController::class, 'updateNote'])->name('orders.updateNote');

    // --- Trả hàng, kho và mua vào: nhân viên (staff) KHÔNG vào ---
    //
    // Trả hàng là TIỀN RA khỏi két cho một đơn đã thu; Tồn kho là chỗ sửa thẳng
    // số hàng và khai giá vốn; cụm mua vào là chuyện hợp đồng với nhà cung cấp.
    // Cả ba đều quyết định tiền chứ không phải thao tác bán hàng. Go API chặn
    // đúng nhóm endpoint tương ứng.
    Route::middleware('admin.manage')->group(function () {
        // Trả hàng
        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/export', [ReturnController::class, 'export'])->name('returns.export');
        // Tra cứu cho modal lập phiếu — đặt trước route {id} để không bị nuốt.
        Route::post('/returns/bulk-status', [ReturnController::class, 'bulkStatus'])->name('returns.bulkStatus');
        Route::get('/returns/new/orders', [ReturnController::class, 'searchOrders'])->name('returns.searchOrders');
        Route::get('/returns/new/orders/{orderId}/items', [ReturnController::class, 'returnable'])
            ->whereNumber('orderId')->name('returns.returnable');
        Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
        Route::get('/returns/{id}/detail', [ReturnController::class, 'detail'])->whereNumber('id')->name('returns.detail');
        Route::put('/returns/{id}/status', [ReturnController::class, 'updateStatus'])->whereNumber('id')->name('returns.updateStatus');
        Route::put('/returns/{id}/settle', [ReturnController::class, 'settle'])->whereNumber('id')->name('returns.settle');
        Route::put('/returns/{id}/note', [ReturnController::class, 'updateNote'])->whereNumber('id')->name('returns.updateNote');

        // Tồn kho — đơn vị quản lý là biến thể sản phẩm (size/màu/phiên bản).
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');

        // Tồn kho chi nhánh — cùng số hàng của trang trên nhưng tách ra theo từng
        // điểm bán. Hai đường này đặt TRƯỚC /inventory/{id} cho khỏi bị nuốt.
        Route::get('/inventory/branches', [TonKhoChiNhanhController::class, 'index'])->name('ton-kho-chi-nhanh.index');
        Route::get('/inventory/branches/export', [TonKhoChiNhanhController::class, 'export'])->name('ton-kho-chi-nhanh.export');
        // Sổ kho của một biến thể tại MỘT chi nhánh — hộp thoại trên trang gọi vào đây.
        Route::get('/inventory/branches/{id}/history', [TonKhoChiNhanhController::class, 'history'])
            ->whereNumber('id')->name('ton-kho-chi-nhanh.history');
        Route::get('/inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
        Route::get('/inventory/import-template', [InventoryController::class, 'importTemplate'])->name('inventory.importTemplate');
        Route::post('/inventory/import', [InventoryController::class, 'import'])->name('inventory.import');
        // Khai giá vốn hàng loạt — cùng khuôn nhập file với số kiểm kê.
        Route::get('/inventory/cost-template', [InventoryController::class, 'importCostTemplate'])->name('inventory.importCostTemplate');
        Route::post('/inventory/import-cost', [InventoryController::class, 'importCost'])->name('inventory.importCost');
        // Phiếu kiểm kê để in (?ids=1,2,3 hoặc theo bộ lọc) — đặt trước route {id}.
        Route::get('/inventory/stocktake', [InventoryController::class, 'stocktake'])->name('inventory.stocktake');
        // Chỉnh hàng loạt đặt trước route {id} để không bị nuốt.
        Route::post('/inventory/bulk-adjust', [InventoryController::class, 'bulkAdjust'])->name('inventory.bulkAdjust');
        Route::get('/inventory/{id}/detail', [InventoryController::class, 'detail'])->whereNumber('id')->name('inventory.detail');
        // Sổ kho phân trang — modal xem nhanh chỉ nạp sẵn trang đầu, "Xem thêm" gọi vào đây.
        Route::get('/inventory/{id}/history', [InventoryController::class, 'history'])->whereNumber('id')->name('inventory.history');
        Route::put('/inventory/{id}', [InventoryController::class, 'adjust'])->whereNumber('id')->name('inventory.adjust');

        // Đặt hàng nhập — chiều mua vào của kho (đặt hàng nhà cung cấp → nhận hàng).
        Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('/purchases/export', [PurchaseController::class, 'export'])->name('purchases.export');
        // Tra cứu cho modal lập phiếu + khối nhà cung cấp — đặt trước route {id} để không bị nuốt.
        Route::get('/purchases/new/variants', [PurchaseController::class, 'searchVariants'])->name('purchases.searchVariants');
        Route::get('/purchases/suppliers', [PurchaseController::class, 'suppliers'])->name('purchases.suppliers');
        Route::post('/purchases/suppliers', [PurchaseController::class, 'storeSupplier'])->name('purchases.storeSupplier');
        Route::put('/purchases/suppliers/{id}', [PurchaseController::class, 'updateSupplier'])->whereNumber('id')->name('purchases.updateSupplier');
        Route::delete('/purchases/suppliers/{id}', [PurchaseController::class, 'destroySupplier'])->whereNumber('id')->name('purchases.destroySupplier');
        Route::post('/purchases/bulk-status', [PurchaseController::class, 'bulkStatus'])->name('purchases.bulkStatus');
        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::get('/purchases/{id}/detail', [PurchaseController::class, 'detail'])->whereNumber('id')->name('purchases.detail');
        Route::put('/purchases/{id}', [PurchaseController::class, 'update'])->whereNumber('id')->name('purchases.update');
        Route::put('/purchases/{id}/status', [PurchaseController::class, 'updateStatus'])->whereNumber('id')->name('purchases.updateStatus');
        Route::post('/purchases/{id}/receive', [PurchaseController::class, 'receive'])->whereNumber('id')->name('purchases.receive');
        Route::put('/purchases/{id}/payment', [PurchaseController::class, 'payment'])->whereNumber('id')->name('purchases.payment');
        Route::delete('/purchases/{id}', [PurchaseController::class, 'destroy'])->whereNumber('id')->name('purchases.destroy');

        // Trả hàng nhập — trả hàng lại nhà cung cấp (chiều ngược của nhập hàng).
        // Hai đường tĩnh đặt trước route {id} để không bị nuốt.
        Route::get('/purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');
        Route::get('/purchase-returns/export', [PurchaseReturnController::class, 'export'])->name('purchase-returns.export');
        Route::get('/purchase-returns/returnable/{purchaseId}', [PurchaseReturnController::class, 'returnable'])
            ->whereNumber('purchaseId')->name('purchase-returns.returnable');
        Route::post('/purchase-returns', [PurchaseReturnController::class, 'store'])->name('purchase-returns.store');
        Route::get('/purchase-returns/{id}/detail', [PurchaseReturnController::class, 'detail'])
            ->whereNumber('id')->name('purchase-returns.detail');
        Route::put('/purchase-returns/{id}', [PurchaseReturnController::class, 'update'])
            ->whereNumber('id')->name('purchase-returns.update');
        Route::put('/purchase-returns/{id}/status', [PurchaseReturnController::class, 'updateStatus'])
            ->whereNumber('id')->name('purchase-returns.updateStatus');
        Route::put('/purchase-returns/{id}/refund', [PurchaseReturnController::class, 'refund'])
            ->whereNumber('id')->name('purchase-returns.refund');
        Route::delete('/purchase-returns/{id}', [PurchaseReturnController::class, 'destroy'])
            ->whereNumber('id')->name('purchase-returns.destroy');

        // Yêu cầu của khách — hộp thư đến từ form Liên hệ và form Thu mua trên
        // storefront. Trước đây hai form đó chỉ hiện hộp thoại "cảm ơn" rồi vứt sạch
        // dữ liệu, nên đây là chỗ đầu tiên trong cửa hàng nhìn thấy khách đã nhắn gì.
        Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
        // "export" đặt TRƯỚC route {id} để không bị nuốt.
        Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');
        Route::put('/contacts/{id}/status', [ContactController::class, 'updateStatus'])
            ->whereNumber('id')->name('contacts.updateStatus');
        Route::delete('/contacts/{id}', [ContactController::class, 'destroy'])
            ->whereNumber('id')->name('contacts.destroy');

        // Đăng ký nhận tin — danh sách email khách để lại ở chân trang storefront.
        Route::get('/newsletter', [ContactController::class, 'newsletter'])->name('newsletter.index');
        Route::put('/newsletter/{id}/unsubscribe', [ContactController::class, 'unsubscribe'])
            ->whereNumber('id')->name('newsletter.unsubscribe');

        // Nhập hàng — sổ hàng về kho. Việc ghi kho vẫn dùng chung route
        // purchases.receive, trang này chỉ là một lối vào khác của cùng luồng đó.
        Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::get('/receipts/export', [ReceiptController::class, 'export'])->name('receipts.export');
        // Mã đợt có dạng PO202607300001-N1 nên không dùng whereNumber được.
        Route::get('/receipts/{code}/detail', [ReceiptController::class, 'detail'])
            ->where('code', '[A-Za-z0-9\-]+')->name('receipts.detail');

        // Nhà cung cấp — danh mục đầu mối mua vào của trang Đặt hàng nhập.
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('/suppliers/export', [SupplierController::class, 'export'])->name('suppliers.export');
        // Hai route hàng loạt đặt TRƯỚC route {id} để không bị nuốt.
        Route::post('/suppliers/bulk-destroy', [SupplierController::class, 'bulkDestroy'])->name('suppliers.bulkDestroy');
        Route::post('/suppliers/bulk-status', [SupplierController::class, 'bulkStatus'])->name('suppliers.bulkStatus');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::put('/suppliers/{id}/toggle-status', [SupplierController::class, 'toggleStatus'])->whereNumber('id')->name('suppliers.toggleStatus');
        Route::put('/suppliers/{id}', [SupplierController::class, 'update'])->whereNumber('id')->name('suppliers.update');
        Route::delete('/suppliers/{id}', [SupplierController::class, 'destroy'])->whereNumber('id')->name('suppliers.destroy');
    });

    // --- Quản lý người & cấu hình: nhân viên (staff) KHÔNG vào ---
    //
    // Nhân viên đăng nhập được trang quản trị để đứng quầy — bán tại quầy, tra
    // lại đơn, mở/đóng ca và ghi sổ quỹ. Hồ sơ khách hàng, tài khoản người dùng
    // và cấu hình cửa hàng thì không, cùng với hàng hoá và kho ở hai nhóm trên.
    // API cũng chặn đúng các nhóm endpoint này, middleware ở đây chỉ để báo sớm
    // và ẩn menu.
    // Đổi chi nhánh đang làm việc — CỐ Ý nằm ngoài nhóm `admin.manage`: nhân
    // viên bán hàng và thủ kho là những người đứng ở một kho cụ thể và phải đổi
    // được, dù họ không được quản lý danh sách chi nhánh. Đường này chỉ ghi vào
    // phiên; API vẫn tra sổ và từ chối chi nhánh của cửa hàng khác.
    Route::post('/branches/current', [ChiNhanhController::class, 'dangLam'])->name('chi-nhanh.dangLam');

    Route::middleware('admin.manage')->group(function () {
        // Cấu hình hệ thống — key-value do API giữ. MỖI nhóm là một trang riêng
        // (cửa hàng / vận chuyển / kho), xem SettingController::GROUPS.
        // upload-logo phải đứng TRƯỚC /settings/{group} để không bị hiểu là tên nhóm.
        Route::get('/settings', fn () => redirect()->route('admin.settings.page', 'general'))->name('settings.index');
        Route::post('/settings/upload-logo', [SettingController::class, 'uploadLogo'])->name('settings.uploadLogo');
        Route::get('/settings/{group}', [SettingController::class, 'page'])->name('settings.page');
        Route::put('/settings/{group}', [SettingController::class, 'update'])->name('settings.update');

        // Thông số chung — bộ khung của tiệm, dựng lại theo bản ERP cũ.
        // Mới có trang Quy tắc đánh số chứng từ; xem ThongSoChungController::PAGES.
        Route::get('/parameters', [ThongSoChungController::class, 'index'])->name('thong-so-chung.index');
        Route::get('/parameters/numbering-rules', [ThongSoChungController::class, 'quyTacDanhSo'])
            ->name('thong-so-chung.quy-tac-danh-so');
        Route::put('/parameters/numbering-rules', [ThongSoChungController::class, 'luuQuyTacDanhSo'])
            ->name('thong-so-chung.luuQuyTacDanhSo');

        // Phân quyền theo chức năng — định nghĩa NHÓM QUYỀN của cửa hàng.
        // Gán nhóm cho từng người thì làm ở hồ sơ nhân sự, không phải ở đây.
        // Phân quyền theo chức năng — chi nhánh → nhân viên → tick từng việc.
        // Bộ quyền mẫu (nhóm quyền) CHƯA LÀM: API còn đủ đường, trang chưa mở lối vào.
        Route::get('/permissions', [PhanQuyenController::class, 'index'])->name('phan-quyen.index');
        // {id} ở đây là id TÀI KHOẢN (users), không phải id hồ sơ nhân sự.
        Route::put('/permissions/users/{id}', [PhanQuyenController::class, 'datQuyenNhanVien'])
            ->whereNumber('id')->name('phan-quyen.datQuyenNhanVien');

        // Người dùng & vai trò — tài khoản NỘI BỘ (quản trị + nhân viên).
        // Khách hàng nằm ở /admin/customers, không đi qua nhóm route này.
        // Hai đường bulk-* đặt trước route {id} để không bị nuốt.
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users/bulk-destroy', [UserController::class, 'bulkDestroy'])->name('users.bulkDestroy');
        Route::post('/users/bulk-status', [UserController::class, 'bulkStatus'])->name('users.bulkStatus');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{id}/status', [UserController::class, 'updateStatus'])->whereNumber('id')->name('users.updateStatus');
        Route::put('/users/{id}/password', [UserController::class, 'setPassword'])->whereNumber('id')->name('users.setPassword');
        Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id')->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereNumber('id')->name('users.destroy');

        Route::put('/roles/{id}', [UserController::class, 'updateRole'])->whereNumber('id')->name('roles.update');

        // Nhân sự — HỒ SƠ NHÂN VIÊN, khác hẳn /admin/users (tài khoản đăng nhập).
        //
        // Cùng nhóm quyền với Người dùng: hồ sơ nhân sự có lương và số căn cước,
        // thu ngân không đọc được. Bốn đường đã có màn hình nhưng phần lưu trữ
        // (bảng + API) chưa dựng — xem NhanSuController.
        Route::get('/staff', [NhanSuController::class, 'index'])->name('nhan-su.index');
        // Xuất trước /staff/{id} sẽ không đụng nhau vì đường kia không tồn tại,
        // nhưng cứ để cạnh index cho dễ đọc: cùng một thứ, hai định dạng.
        Route::get('/staff/export', [NhanSuController::class, 'export'])->name('nhan-su.export');
        Route::post('/staff', [NhanSuController::class, 'store'])->name('nhan-su.store');
        // Tải ảnh TRƯỚC khi gửi hồ sơ: form chỉ mang theo đường dẫn ảnh trả về,
        // nên bấm Lưu mà hỏng thì ảnh vẫn còn đó, không phải chọn lại.
        Route::post('/staff/photo', [NhanSuController::class, 'uploadAnh'])->name('nhan-su.anh');
        // Hàng loạt — đặt TRƯỚC /staff/{id} để "bulk-*" không bị hiểu là một id.
        Route::post('/staff/bulk-status', [NhanSuController::class, 'bulkTrangThai'])
            ->name('nhan-su.bulkTrangThai');
        Route::post('/staff/bulk-destroy', [NhanSuController::class, 'bulkDestroy'])
            ->name('nhan-su.bulkDestroy');
        Route::put('/staff/{id}', [NhanSuController::class, 'update'])->whereNumber('id')->name('nhan-su.update');
        // Công tắc trạng thái trên bảng danh sách — chỉ đổi một cột.
        Route::put('/staff/{id}/status', [NhanSuController::class, 'updateStatus'])
            ->whereNumber('id')->name('nhan-su.updateStatus');
        Route::delete('/staff/{id}', [NhanSuController::class, 'destroy'])->whereNumber('id')->name('nhan-su.destroy');

        // Chi nhánh — các ĐIỂM BÁN của chính cửa hàng này (bảng `shops` bên API),
        // không phải khách hàng của nhà cung cấp.
        //
        // Cùng nhóm quyền với Người dùng: mở thêm một điểm bán ăn thẳng vào hạn
        // mức `max_shops` của hợp đồng, tức là quyết định của chủ tiệm chứ không
        // phải việc hằng ngày của nhân viên. API chặn đúng như vậy.
        Route::get('/branches', [ChiNhanhController::class, 'index'])->name('chi-nhanh.index');
        Route::post('/branches', [ChiNhanhController::class, 'store'])->name('chi-nhanh.store');
        // Tải logo TRƯỚC khi gửi form: form chỉ mang theo đường dẫn ảnh trả về,
        // nên bấm Lưu mà hỏng thì ảnh vẫn còn đó, không phải chọn lại.
        Route::post('/branches/logo', [ChiNhanhController::class, 'uploadAnh'])->name('chi-nhanh.anh');
        Route::put('/branches/{id}', [ChiNhanhController::class, 'update'])->whereNumber('id')->name('chi-nhanh.update');
        // Công tắc mở/đóng trên bảng danh sách — chỉ đổi một cột.
        Route::put('/branches/{id}/status', [ChiNhanhController::class, 'toggleStatus'])
            ->whereNumber('id')->name('chi-nhanh.toggleStatus');
        // Hoá đơn điện tử của chi nhánh. `etax` trả JSON cho hộp thoại (mở ngay
        // trên bảng, không tải lại trang); bốn đường còn lại quay về danh sách
        // kèm toast như mọi thao tác khác.
        Route::get('/branches/{id}/etax', [ChiNhanhController::class, 'etax'])
            ->whereNumber('id')->name('chi-nhanh.etax');
        Route::post('/branches/{id}/etax', [ChiNhanhController::class, 'ketNoiEtax'])
            ->whereNumber('id')->name('chi-nhanh.ketNoiEtax');
        Route::put('/branches/{id}/etax', [ChiNhanhController::class, 'luuCaiDatEtax'])
            ->whereNumber('id')->name('chi-nhanh.luuCaiDatEtax');
        Route::post('/branches/{id}/etax/sync', [ChiNhanhController::class, 'dongBoMauEtax'])
            ->whereNumber('id')->name('chi-nhanh.dongBoMauEtax');
        Route::delete('/branches/{id}/etax', [ChiNhanhController::class, 'ngatEtax'])
            ->whereNumber('id')->name('chi-nhanh.ngatEtax');
        Route::delete('/branches/{id}', [ChiNhanhController::class, 'destroy'])->whereNumber('id')->name('chi-nhanh.destroy');

        // Khách hàng
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
        Route::get('/customers/import-template', [CustomerController::class, 'importTemplate'])->name('customers.importTemplate');
        Route::get('/customers/{id}/detail', [CustomerController::class, 'detail'])->name('customers.detail');
        Route::post('/customers/import', [CustomerController::class, 'import'])->name('customers.import');
        Route::post('/customers/upload-avatar', [CustomerController::class, 'uploadAvatar'])->name('customers.uploadAvatar');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::post('/customers/login-account', [CustomerController::class, 'loginAccount'])->name('customers.loginAccount');
        Route::post('/customers/bulk-destroy', [CustomerController::class, 'bulkDestroy'])->name('customers.bulkDestroy');
        Route::put('/customers/{id}', [CustomerController::class, 'update'])->name('customers.update');
        Route::put('/customers/{id}/toggle-status', [CustomerController::class, 'toggleStatus'])->name('customers.toggleStatus');
        Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->name('customers.destroy');

        // Gói dịch vụ — hợp đồng phần mềm của CHÍNH cửa hàng này.
        //
        // Nằm trong nhóm `admin.manage` cùng Khách hàng và Cài đặt: đây là
        // chuyện hợp đồng và tiền giữa chủ tiệm với nhà cung cấp phần mềm, nhân
        // viên bán hàng không cần và không nên đọc. API chặn đúng như vậy.
        Route::get('/subscription', [GoiDichVuController::class, 'index'])->name('goi-dich-vu.index');
        // Khách tự gia hạn: đặt đơn → trang thanh toán → hỏi trạng thái.
        //
        // Cả ba nằm trong danh sách route CÒN ĐI ĐƯỢC khi cửa hàng đã hết hạn (xem
        // KhoaKhiHetHan): người hết hạn chính là người cần trả tiền nhất.
        Route::post('/subscription/renew', [GoiDichVuController::class, 'datGiaHan'])->name('goi-dich-vu.gia-han');
        Route::get('/subscription/payment/{id}', [GoiDichVuController::class, 'thanhToan'])
            ->whereNumber('id')->name('goi-dich-vu.thanh-toan');
        Route::get('/subscription/order/{id}', [GoiDichVuController::class, 'trangThaiDon'])
            ->whereNumber('id')->name('goi-dich-vu.don');

        // Báo cáo — bốn trang CHỈ ĐỌC, gộp lại dữ liệu đã có theo khoảng ngày.
        //
        // Nằm trong nhóm `admin.manage` cùng Khách hàng và Cài đặt: báo cáo phơi
        // ra giá vốn / lợi nhuận từng mặt hàng và mức chi tiêu kèm thông tin liên
        // hệ của từng khách. Nhân viên đã không mở được trang Khách hàng thì cũng
        // không nên đọc được cùng dữ liệu đó qua đường vòng là báo cáo — API cũng
        // chặn đúng như vậy.
        Route::get('/reports', fn () => redirect()->route('admin.reports.revenue'))->name('reports.index');
        Route::get('/reports/revenue', [ReportController::class, 'revenue'])->name('reports.revenue');
        Route::get('/reports/orders', [ReportController::class, 'orders'])->name('reports.orders');
        Route::get('/reports/products', [ReportController::class, 'products'])->name('reports.products');
        Route::get('/reports/customers', [ReportController::class, 'customers'])->name('reports.customers');
    });
});

// --- MODULE THU NGÂN (yêu cầu đăng nhập; nhân viên vào được) ---
//
// Khu riêng chứ không phải vài trang lẻ trong /admin, vì đây là một CHỖ ĐỨNG
// khác: người trực quầy mở máy ra là ở sẵn đây cả ca, không đi qua thanh điều
// hướng của khu quản trị (thanh đó chứa mười thứ họ không được phép mở). Đổi
// qua lại giữa hai module bằng nút ở góc phải thanh trên cùng — cùng một nút ở
// cả hai bên, xem partials/module-switch.blade.php.
//
// CỐ Ý không có `admin.manage`: người đứng quầy là nhân viên, cả module vô
// nghĩa nếu chỉ chủ tiệm bấm được nút thu tiền. Vẫn có `admin.khoa`: cửa hàng
// hết hạn hợp đồng thì quầy cũng dừng, và Go API từ chối y như vậy.
//
// `admin.cua:thu_ngan` — TÍCH GÌ VÀO ĐƯỢC NẤY. Người chỉ được tích "Quản lý"
// trong mục Nhân sự KHÔNG mở được module này, dù vai trò của họ là admin. Trước
// migration 0015 thì vai admin đi qua cả hai khu, nên cả cụm này là một cửa mở
// mà chủ tiệm không có cách nào đóng lại.
Route::middleware(['admin.auth', 'admin.khoa', 'admin.cua:thu_ngan'])->prefix('cashier')->name('thu-ngan.')->group(function () {
    Route::get('/', fn () => redirect()->route('thu-ngan.ban-hang.index'));

    // Bán hàng — trang mặc định của module.
    //
    // Dùng lại thẳng đường tìm sản phẩm của trang tạo đơn (admin.orders.searchProducts)
    // thay vì đẻ thêm một endpoint song song: hai màn hình hỏi cùng một câu, và
    // hai bản sao thì sẽ có bản bị bỏ quên khi dữ liệu sản phẩm đổi hình dạng.
    Route::get('/sales', [BanTaiQuayController::class, 'index'])->name('ban-hang.index');
    Route::post('/sales', [BanTaiQuayController::class, 'store'])->name('ban-hang.store');
    // /scan đứng TRƯỚC /{id}/receipt để không bị hiểu là một id.
    Route::get('/sales/scan', [BanTaiQuayController::class, 'scan'])->name('ban-hang.scan');
    Route::get('/sales/{id}/receipt', [BanTaiQuayController::class, 'phieu'])
        ->whereNumber('id')->name('ban-hang.phieu');

    // Điều phối ca & sổ quỹ — nơi đối chiếu tiền trong két với sổ.
    Route::get('/shifts', [CaLamViecController::class, 'index'])->name('ca-lam-viec.index');
    // /current, /open, /close, /cash-log đứng TRƯỚC /{id} để không bị hiểu là một id.
    Route::get('/shifts/current', [CaLamViecController::class, 'hienTai'])->name('ca-lam-viec.hienTai');
    Route::post('/shifts/open', [CaLamViecController::class, 'moCa'])->name('ca-lam-viec.mo');
    Route::post('/shifts/close', [CaLamViecController::class, 'dongCa'])->name('ca-lam-viec.dong');
    Route::post('/shifts/cash-log', [CaLamViecController::class, 'ghiSoQuy'])->name('ca-lam-viec.soQuy');
    Route::get('/shifts/{id}', [CaLamViecController::class, 'show'])->whereNumber('id')->name('ca-lam-viec.show');

    // Lịch sử đơn — chỉ những đơn bán ra từ chính module này, để tra lại và in lại
    // phiếu. Khác trang Đơn hàng bên quản trị: ở đó là đơn giao hàng cần xử lý,
    // còn đơn quầy thì xong ngay lúc tạo.
    Route::get('/orders', [ThuNganController::class, 'donHang'])->name('don-hang.index');
});
