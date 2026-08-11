<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập — {{ config('app.name') }}</title>
    {{-- Favicon Sellio (dùng chung với trang giới thiệu selliotech.store): .svg cho
         trình duyệt mới, .ico dự phòng, apple-touch-icon cho màn hình chính iOS. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=3">

    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Roboto, sans-serif;
            font-size: 14.4px;
            color: #212529;
            background: linear-gradient(rgba(15, 23, 42, .55), rgba(15, 23, 42, .55)),
                        url('{{ asset('images/backround-login.jpg') }}') center / cover no-repeat fixed;
        }

        /* Hộp đăng nhập dạng modal */
        .login-box {
            box-sizing: border-box;
            width: 100%;
            max-width: 400px;
            padding: 30px;
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .3);
        }

        .login-box .form-label { font-weight: 500; margin-bottom: .35rem; }
        .login-box .btn-primary { font-weight: 500; }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="text-center mb-4">
            <img src="{{ asset('images/logo-default.svg') }}" alt="{{ config('app.name') }}"
                style="max-width: 220px; height: auto;">
            <p class="text-muted small mb-0 mt-3">Đăng nhập để quản trị hệ thống</p>
        </div>

        <form method="POST" action="{{ route('login.attempt') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" value="{{ old('email', $rememberedEmail ?? '') }}"
                    class="form-control @error('email') is-invalid @enderror"
                       required autofocus placeholder="Nhập địa chỉ email">
            </div>
            <div class="mb-3">
                <label class="form-label">Mật khẩu</label>
                <input type="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       required placeholder="••••••••">
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check mb-0">
                    <input type="checkbox" name="remember" id="remember" value="1"
                           class="form-check-input" @checked(!empty($rememberedEmail))>
                    <label class="form-check-label" for="remember">Ghi nhớ đăng nhập</label>
                </div>
                <a href="{{ route('password.forgot') }}" class="small text-decoration-none">Quên mật khẩu?</a>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Đăng nhập
            </button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @include('partials.toasts')
</body>
</html>
