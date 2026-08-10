<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
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
use App\Http\Controllers\UserController;
use App\Http\Controllers\VoucherController;
use Illuminate\Support\Facades\Route;

// --- Route gốc: redirect vào /admin ---
Route::get('/', fn () => redirect('/admin'));

// --- Khách (chưa đăng nhập) ---
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

// --- Đăng xuất (KHÔNG nằm trong admin.auth để tránh mất request khi session hết hạn) ---
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/quen-mat-khau', [AuthController::class, 'forgotPassword'])->name('password.forgot');

// --- Khu vực quản trị (yêu cầu đăng nhập + quyền admin) ---
Route::middleware('admin.auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => redirect()->route('admin.dashboard'));
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Tài khoản của tôi — hồ sơ + mật khẩu của chính người đang đăng nhập.
    // Cố tình nằm NGOÀI nhóm `admin.manage`: nhân viên không vào được trang Người
    // dùng, đóng nốt đường này thì họ không có cách nào tự đổi mật khẩu.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');

    // Thông báo & realtime (JSON cho chuông trên topbar)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/stream-token', [NotificationController::class, 'streamToken'])->name('notifications.streamToken');
    // Trang tự chẩn đoán khi realtime không chạy mà console không báo lỗi gì.
    Route::get('/notifications/check', [NotificationController::class, 'check'])->name('notifications.check');
    Route::post('/notifications/test', [NotificationController::class, 'test'])->name('notifications.test');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id')->name('notifications.read');

    // Danh mục sản phẩm
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories/upload-image', [CategoryController::class, 'uploadImage'])->name('categories.uploadImage');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::post('/categories/bulk-destroy', [CategoryController::class, 'bulkDestroy'])->name('categories.bulkDestroy');
    Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');

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
    Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

    // Thương hiệu — trang quản lý riêng; store() dùng chung cho cả modal "thêm
    // nhanh" bên trang Sản phẩm (gửi AJAX, nhận JSON).
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('/brands/export', [BrandController::class, 'export'])->name('brands.export');
    Route::post('/brands/upload-logo', [BrandController::class, 'uploadLogo'])->name('brands.uploadLogo');
    Route::post('/brands/bulk-destroy', [BrandController::class, 'bulkDestroy'])->name('brands.bulkDestroy');
    Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
    Route::put('/brands/{id}/toggle-status', [BrandController::class, 'toggleStatus'])->whereNumber('id')->name('brands.toggleStatus');
    Route::put('/brands/{id}', [BrandController::class, 'update'])->whereNumber('id')->name('brands.update');
    Route::delete('/brands/{id}', [BrandController::class, 'destroy'])->whereNumber('id')->name('brands.destroy');

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
    Route::get('/khuyen-mai', [PromotionController::class, 'index'])->name('promotions.index');
    Route::get('/khuyen-mai/export', [PromotionController::class, 'export'])->name('promotions.export');
    Route::post('/khuyen-mai/bulk-destroy', [PromotionController::class, 'bulkDestroy'])->name('promotions.bulkDestroy');
    Route::post('/khuyen-mai', [PromotionController::class, 'store'])->name('promotions.store');
    Route::put('/khuyen-mai/{id}/toggle-status', [PromotionController::class, 'toggleStatus'])->whereNumber('id')->name('promotions.toggleStatus');
    Route::put('/khuyen-mai/{id}', [PromotionController::class, 'update'])->whereNumber('id')->name('promotions.update');
    Route::delete('/khuyen-mai/{id}', [PromotionController::class, 'destroy'])->whereNumber('id')->name('promotions.destroy');

    // Voucher — mã khách tự nhập lúc thanh toán, giảm trên tổng đơn.
    Route::get('/voucher', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::get('/voucher/export', [VoucherController::class, 'export'])->name('vouchers.export');
    Route::post('/voucher/bulk-destroy', [VoucherController::class, 'bulkDestroy'])->name('vouchers.bulkDestroy');
    Route::post('/voucher', [VoucherController::class, 'store'])->name('vouchers.store');
    Route::put('/voucher/{id}/toggle-status', [VoucherController::class, 'toggleStatus'])->whereNumber('id')->name('vouchers.toggleStatus');
    Route::put('/voucher/{id}', [VoucherController::class, 'update'])->whereNumber('id')->name('vouchers.update');
    Route::delete('/voucher/{id}', [VoucherController::class, 'destroy'])->whereNumber('id')->name('vouchers.destroy');

    // Đơn hàng
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/export', [OrderController::class, 'export'])->name('orders.export');
    // In hàng loạt (?ids=1,2,3) + xác nhận hàng loạt — đặt trước route {id} để không bị nuốt.
    Route::get('/orders/print', [OrderController::class, 'print'])->name('orders.printBatch');
    Route::get('/orders/label', [OrderController::class, 'label'])->name('orders.labelBatch');
    Route::post('/orders/bulk-status', [OrderController::class, 'bulkStatus'])->name('orders.bulkStatus');
    Route::get('/orders/new/customers', [OrderController::class, 'searchCustomers'])->name('orders.searchCustomers');
    Route::get('/orders/new/products', [OrderController::class, 'searchProducts'])->name('orders.searchProducts');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
    Route::get('/orders/{id}/detail', [OrderController::class, 'detail'])->name('orders.detail');
    Route::get('/orders/{id}/print', [OrderController::class, 'print'])->name('orders.print');
    Route::get('/orders/{id}/label', [OrderController::class, 'label'])->name('orders.label');
    Route::put('/orders/{id}/status', [OrderController::class, 'updateStatus'])->name('orders.updateStatus');
    Route::put('/orders/{id}/payment', [OrderController::class, 'updatePayment'])->name('orders.updatePayment');
    Route::put('/orders/{id}/shipping', [OrderController::class, 'updateShipping'])->name('orders.updateShipping');
    Route::put('/orders/{id}/note', [OrderController::class, 'updateNote'])->name('orders.updateNote');

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
    Route::put('/contacts/{id}/trang-thai', [ContactController::class, 'updateStatus'])
        ->whereNumber('id')->name('contacts.updateStatus');
    Route::delete('/contacts/{id}', [ContactController::class, 'destroy'])
        ->whereNumber('id')->name('contacts.destroy');

    // Đăng ký nhận tin — danh sách email khách để lại ở chân trang storefront.
    Route::get('/dang-ky-nhan-tin', [ContactController::class, 'newsletter'])->name('newsletter.index');
    Route::put('/dang-ky-nhan-tin/{id}/go', [ContactController::class, 'unsubscribe'])
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

    // --- Quản lý người & cấu hình: nhân viên (staff) KHÔNG vào ---
    //
    // Nhân viên đăng nhập được trang quản trị để làm đơn hàng và kho, nhưng hồ sơ
    // khách hàng, tài khoản người dùng và cấu hình cửa hàng thì không. API cũng
    // chặn đúng các nhóm endpoint này, middleware ở đây chỉ để báo sớm và ẩn menu.
    Route::middleware('admin.manage')->group(function () {
        // Cấu hình hệ thống — key-value do API giữ. MỖI nhóm là một trang riêng
        // (cửa hàng / vận chuyển / kho), xem SettingController::GROUPS.
        // upload-logo phải đứng TRƯỚC /settings/{group} để không bị hiểu là tên nhóm.
        Route::get('/settings', fn () => redirect()->route('admin.settings.page', 'general'))->name('settings.index');
        Route::post('/settings/upload-logo', [SettingController::class, 'uploadLogo'])->name('settings.uploadLogo');
        Route::get('/settings/{group}', [SettingController::class, 'page'])->name('settings.page');
        Route::put('/settings/{group}', [SettingController::class, 'update'])->name('settings.update');

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
