<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quên mật khẩu — {{ config('app.name') }}</title>
    {{-- Favicon Sellio (dùng chung với trang giới thiệu selliotech.store): .svg cho
         trình duyệt mới, .ico dự phòng, apple-touch-icon cho màn hình chính iOS. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=3">

    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* Nền và thẻ giữ y hệt trang đăng nhập để hai trang nhìn là một bộ —
           xem chú thích đầy đủ ở auth/login.blade.php. */
        body {
            min-height: 100vh;
            margin: 0;
            padding: 32px;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            font-family: Roboto, sans-serif;
            font-size: 14.4px;
            color: #212529;
            background: #2b2f36 url('{{ asset('images/login-bg.jpg') }}') center / cover no-repeat fixed;
        }

        .login-box {
            box-sizing: border-box;
            width: 100%;
            max-width: 520px;
            margin-left: 6vw;
            padding: 30px 34px 24px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 0 24px rgba(16, 24, 40, .10),
                        0 8px 32px rgba(16, 24, 40, .18);
        }

        .login-box a { color: #1890ff; }

        @media (max-width: 900px) {
            body { justify-content: center; padding: 20px; }
            .login-box { margin-left: 0; }
        }

        @media (max-width: 480px) {
            body { padding: 16px; }
            .login-box { padding: 26px 20px 20px; }
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="text-center mb-4">
            <img src="{{ asset('images/logo-default.svg') }}" alt="{{ config('app.name') }}" style="max-width: 200px; height: auto;">
        </div>

        <h1 class="h5 text-center mb-3">Quên mật khẩu</h1>

        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>
            Vì lý do bảo mật, việc đặt lại mật khẩu tài khoản quản trị được thực hiện thủ công.
            Vui lòng liên hệ <strong>quản trị viên hệ thống (Super Admin)</strong> để được cấp lại mật khẩu.
        </div>

        {{-- Mã cửa hàng là ô đầu tiên của màn hình đăng nhập nên quên nó cũng là
             đứng ngoài cửa y như quên mật khẩu — phải chỉ chỗ hỏi. --}}
        <div class="alert alert-light border small">
            <i class="bi bi-shop me-1"></i>
            Quên <strong>mã cửa hàng</strong>? Đó là mã được cấp khi bàn giao phần mềm —
            hỏi lại quản lý cửa hàng, hoặc liên hệ hỗ trợ bên dưới.
        </div>

        <div class="small text-muted mb-3">
            <i class="bi bi-envelope me-1"></i> Hỗ trợ: <a href="mailto:nhuy08052004@gmail.com">nhuy08052004@gmail.com</a>
        </div>

        <a href="{{ route('login') }}" class="btn btn-outline-secondary w-100">
            <i class="bi bi-arrow-left me-1"></i> Quay lại đăng nhập
        </a>
    </div>
</body>
</html>
