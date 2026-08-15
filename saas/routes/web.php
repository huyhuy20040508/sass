<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KhachHangOrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SaaS Admin — khu điều hành nền tảng
|--------------------------------------------------------------------------
|
| App này chạy trên host/cổng RIÊNG với Shop Admin, nên không cần prefix /saas:
| đường dẫn để ở gốc cho gọn.
|
| Hiện có đăng nhập, tổng quan và khu "QLTK khách hàng order" (5 màn hình đọc từ
| nhóm /platform/* của Go API). Hoá đơn và nhật ký thêm vào nhóm `platform.auth`
| bên dưới khi có màn hình thật — đừng khai route trỏ tới controller chưa viết.
|
*/

Route::get('/', fn () => redirect()->route('platform.dashboard'));

// --- Khách (chưa đăng nhập) ---
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

// --- Đăng xuất (KHÔNG nằm trong platform.auth để không mất request khi session hết hạn) ---
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// --- Khu điều hành (yêu cầu tài khoản trong sổ platform_users của nền tảng) ---
Route::middleware('platform.auth')->name('platform.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    /*
     * QLTK khách hàng order — sổ tài khoản khách của phần mềm Sellio Order.
     *
     * Prefix `khach-hang-order` chứ không phải `order`: đường /order đọc như
     * khu quản lý ĐƠN HÀNG, mà đây là khu quản lý KHÁCH của phần mềm tên Order.
     *
     * Mã app nằm trong controller (KhachHangOrderController::APP), không đưa
     * lên thành tham số URL: khi bán thêm phần mềm thứ hai thì mỗi phần mềm cần
     * bộ màn hình riêng có tên riêng ở thanh trái, chứ không phải cùng một trang
     * đổi tham số — thanh trái không nói được người ta đang xem phần mềm nào.
     */
    Route::prefix('khach-hang-order')->name('khach-hang-order.')->group(function () {
        Route::get('/nguoi-dung-thu', [KhachHangOrderController::class, 'nguoiDungThu'])->name('nguoi-dung-thu');
        Route::get('/nguoi-chinh-thuc', [KhachHangOrderController::class, 'nguoiChinhThuc'])->name('nguoi-chinh-thuc');
        Route::get('/goi-dich-vu', [KhachHangOrderController::class, 'goiDichVu'])->name('goi-dich-vu');
        Route::get('/tinh-nang-goi', [KhachHangOrderController::class, 'tinhNangGoi'])->name('tinh-nang-goi');
        // POST chứ không PUT: đây là form HTML thường, trình duyệt chỉ gửi được
        // GET/POST. Việc đổi nó thành PUT là chuyện của ApiClient khi gọi sang Go.
        Route::post('/tinh-nang-goi/{plan}', [KhachHangOrderController::class, 'luuTinhNangGoi'])
            ->whereNumber('plan')->name('tinh-nang-goi.luu');
        // Sửa MỘT mức giá (tên, giá, dùng thử, còn bán hay không) — khác
        // `tinh-nang-goi.luu` là ghi ĐIỀU KHOẢN của gói. POST vì form HTML
        // thường; ApiClient đổi sang PUT khi đi tiếp sang Go.
        Route::post('/goi-dich-vu/{plan}', [KhachHangOrderController::class, 'luuGoi'])
            ->whereNumber('plan')->name('goi-dich-vu.luu');
        Route::get('/database', [KhachHangOrderController::class, 'database'])->name('database');

        /*
         * Ba đường GHI trên vòng đời hợp đồng.
         *
         * Đều là POST vì đây là form HTML thường — trình duyệt chỉ gửi được
         * GET/POST. Việc chúng đi tiếp sang Go bằng phương thức nào là chuyện
         * của ApiClient.
         *
         * `gia-han` phục vụ CẢ hai nút "Chuyển sang chính thức" (màn hình dùng
         * thử) lẫn "Gia hạn" (màn hình chính thức): bên Go đó là một hành động,
         * và khai hai route trỏ vào hai hàm khác nhau ở đây chỉ tạo ra hai chỗ
         * để lệch nhau.
         */
        Route::post('/dung-thu', [KhachHangOrderController::class, 'taoDungThu'])->name('dung-thu.tao');

        /*
         * Xuất danh sách ra tệp Excel đọc được (.csv).
         *
         * POST chứ không GET: màn hình gửi kèm danh sách id đang hiện để tệp khớp
         * đúng thứ đang thấy sau khi lọc, và danh sách đó dài hơn giới hạn an
         * toàn của một địa chỉ URL khi bảng có vài trăm dòng.
         */
        Route::post('/xuat-hop-dong', [KhachHangOrderController::class, 'xuatHopDong'])->name('hop-dong.xuat');

        /*
         * Chi tiết một hợp đồng — TRANG RIÊNG, không phải hộp thoại.
         *
         * Có URL nghĩa là gửi được cho đồng nghiệp, mở được ở tab mới, và nút
         * Back của trình duyệt đưa về đúng danh sách vừa đứng.
         */
        Route::get('/hop-dong/{hopDong}', [KhachHangOrderController::class, 'chiTiet'])
            ->whereNumber('hopDong')->name('hop-dong.chi-tiet');
        Route::post('/hop-dong/{hopDong}', [KhachHangOrderController::class, 'luuChiTiet'])
            ->whereNumber('hopDong')->name('hop-dong.luu');
        Route::post('/hop-dong/{hopDong}/gia-han', [KhachHangOrderController::class, 'giaHan'])
            ->whereNumber('hopDong')->name('hop-dong.gia-han');
        Route::post('/hop-dong/{hopDong}/huy', [KhachHangOrderController::class, 'huy'])
            ->whereNumber('hopDong')->name('hop-dong.huy');
        Route::post('/hop-dong/{hopDong}/doi-mat-khau', [KhachHangOrderController::class, 'doiMatKhauKhach'])
            ->whereNumber('hopDong')->name('hop-dong.doi-mat-khau');

        /*
         * Hai đường của khách TRẢ TIỀN.
         *
         * `ky` khác `dung-thu.tao`: đường kia dựng cả một khách hàng mới (cửa
         * hàng + tài khoản đăng nhập), còn đây chỉ ghi hợp đồng cho cửa hàng đã
         * tồn tại.
         *
         * `thu-tien` khác `gia-han`: một bên ghi nhận TIỀN, một bên đẩy HẠN. Gộp
         * lại thì mỗi lần gia hạn báo một khoản doanh thu chưa ai trả.
         */
        // Thêm khách MỚI kèm hợp đồng chính thức — đối ứng của `dung-thu.tao`.
        Route::post('/chinh-thuc', [KhachHangOrderController::class, 'taoChinhThuc'])->name('chinh-thuc.tao');
        // Ký hợp đồng cho cửa hàng ĐÃ CÓ. Đường duy nhất dùng được cho khách cũ
        // quay lại: mã cửa hàng của họ đã bị chiếm nên đường trên sẽ từ chối.
        Route::post('/hop-dong/ky', [KhachHangOrderController::class, 'kyHopDong'])->name('hop-dong.ky');
        Route::post('/hop-dong/{hopDong}/thu-tien', [KhachHangOrderController::class, 'thuTien'])
            ->whereNumber('hopDong')->name('hop-dong.thu-tien');
    });
});
