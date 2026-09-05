<?php

use App\Http\Middleware\ChiHienGiaoDienV2;
use App\Http\Middleware\ChiNhanhTheoTab;
use App\Http\Middleware\EnsureAdminAuthenticated;
use App\Http\Middleware\EnsureCuaVao;
use App\Http\Middleware\EnsureManagerRole;
use App\Http\Middleware\GiuLoiNhanKhiGoiNen;
use App\Http\Middleware\KhoaKhiHetHan;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sau khi deploy, app nằm sau Nginx nên mọi request tới PHP đều là HTTP
        // từ 127.0.0.1. Không tin proxy thì asset()/url() sinh link http:// trên
        // trang https:// và trình duyệt chặn hết CSS/JS vì mixed content — kể cả
        // URL ảnh sản phẩm mà trang này ghi vào API lúc tải ảnh lên.
        // Nginx đứng cùng máy nên tin toàn bộ header X-Forwarded-* là an toàn.
        // CHỈ tin proxy nội bộ, không tin '*'.
        //
        // nginx đưa thẳng REMOTE_ADDR qua fastcgi (unix socket), nên Laravel vốn
        // đã thấy đúng IP trình duyệt. Trust '*' bảo Symfony ưu tiên header
        // X-Forwarded-For do CHÍNH CLIENT gửi lên — tức là ai cũng tự khai được
        // mình là ai, và mọi hạn mức tính theo IP thành vô nghĩa. Con số này giờ
        // còn được chuyển tiếp sang API (ApiClient::ipNguoiDung) nên nó phải đúng.
        //
        // Đặt CDN hay cân bằng tải trước nginx thì thêm dải của chúng vào đây,
        // không quay lại '*'.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        // Lượt gọi nền (chuông thông báo) không được ăn mất lời nhắn dành cho
        // trang đang tải — xem GiuLoiNhanKhiGoiNen. Gắn vào cả nhóm `web` chứ
        // không vài route: nó phải phủ luôn những lượt gọi nền viết sau này.
        $middleware->web(append: [
            GiuLoiNhanKhiGoiNen::class,
            // Chi nhánh đang làm việc là chuyện của TỪNG TAB, không phải của cả
            // trình duyệt — xem ChiNhanhTheoTab. Gắn vào cả nhóm `web` vì nó phải
            // phủ mọi đường: trang, lượt gọi ngầm, và mọi lượt ghi viết sau này.
            ChiNhanhTheoTab::class,
        ]);

        $middleware->alias([
            'admin.auth' => EnsureAdminAuthenticated::class,
            // Gắn thêm cho các trang quản lý người & cấu hình — nhân viên không vào.
            'admin.manage' => EnsureManagerRole::class,
            // Cửa hàng hết hạn hợp đồng: mọi trang dồn về trang Các gói dịch vụ.
            'admin.khoa' => KhoaKhiHetHan::class,
            // Chặn theo CỬA đã tích trong mục Nhân sự: admin.cua:thu_ngan | :quan_ly.
            'admin.cua' => EnsureCuaVao::class,
            // Đợt chuyển sang giao diện v2: màn chưa dựng lại thì không mở bản cũ.
            'chi.v2' => ChiHienGiaoDienV2::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Màn hình "419 PAGE EXPIRED" mặc định là một ngõ cụt: trắng trơn, không
        // có nút nào, mà bấm Back thì trình duyệt gửi lại đúng cái token đã chết
        // nên lại 419 lần nữa. Người dùng chỉ thoát ra được nếu tự biết bấm F5.
        //
        // Mà token chết là chuyện thường ngày, không phải dấu hiệu bị tấn công:
        // để tab đăng nhập mở qua đêm là quá SESSION_LIFETIME (120 phút), gõ mật
        // khẩu xong bấm Đăng nhập là gặp. Nên đưa họ về lại chính trang vừa đứng
        // với một token mới, kèm câu giải thích, thay vì bỏ mặc ở trang lỗi.
        //
        // Bắt theo MÃ 419 chứ không theo TokenMismatchException, và đây là chỗ
        // dễ mất cả buổi nếu làm theo trí nhớ: Laravel 12 chạy prepareException()
        // TRƯỚC các callback, mà bước đó đã đổi TokenMismatchException thành
        // HttpException(419) rồi — đăng ký render(TokenMismatchException) thì
        // callback không bao giờ chạy, và triệu chứng là trang 419 y như cũ.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 419) {
                return $response;
            }

            $message = 'Phiên làm việc đã hết hạn, vui lòng thử lại.';

            // AJAX tự xử: các trang quản trị đã bắt lỗi JSON sẵn, đá về HTML
            // đăng nhập chỉ khiến chúng hiện một đống thẻ vào chỗ báo lỗi.
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json(['message' => $message], 419);
            }

            // Người đang ở trong khu quản trị thì phiên API vẫn còn — trả về đúng
            // trang họ vừa gửi biểu mẫu, chứ đừng đá ra màn hình đăng nhập làm họ
            // tưởng bị đăng xuất.
            if (session('api.access_token')) {
                return back()->withInput($request->except('_token', 'password'))
                    ->with('error', $message);
            }

            // Chưa đăng nhập: về lại /login, giữ lại hai ô đã gõ (KHÔNG giữ mật khẩu).
            return redirect()->route('login')
                ->withInput($request->only('shop_code', 'username'))
                ->with('error', 'Phiên đăng nhập đã hết hạn, vui lòng nhập lại mật khẩu.');
        });
    })->create();
