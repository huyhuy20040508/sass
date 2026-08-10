<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập — {{ config('app.name') }}</title>

    <link rel="icon" href="data:image/svg+xml,{{ rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="7" fill="#4f46e5"/><text x="16" y="23" font-family="Segoe UI,Arial,sans-serif" font-size="20" font-weight="700" fill="#fff" text-anchor="middle">S</text></svg>') }}">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* Nền vẽ bằng CSS chứ không dùng ảnh: app này chưa có bộ nhận diện riêng,
           và một tệp ảnh nền vài MB chỉ để làm đẹp trang đăng nhập là không đáng. */
        body {
            min-height: 100vh; margin: 0;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1f2330;
            background: radial-gradient(1100px 600px at 15% 10%, #312e81 0%, transparent 55%),
                        radial-gradient(900px 500px at 85% 90%, #4f46e5 0%, transparent 50%),
                        #14122b;
        }

        .login-box {
            box-sizing: border-box; width: 100%; max-width: 400px;
            padding: 32px; background: #fff; border-radius: 12px;
            box-shadow: 0 20px 50px rgba(10, 8, 30, .45);
        }

        .brand-mark {
            width: 48px; height: 48px; border-radius: 12px;
            background: #4f46e5; color: #fff;
            display: grid; place-items: center;
            font-size: 24px; font-weight: 700; margin: 0 auto;
        }

        .login-box .form-label { font-weight: 500; margin-bottom: .35rem; }
        .login-box .btn-primary {
            background-color: #4f46e5; border-color: #4f46e5; font-weight: 500;
        }
        .login-box .btn-primary:hover, .login-box .btn-primary:focus {
            background-color: #4338ca; border-color: #4338ca;
        }
        .login-box .form-control:focus {
            border-color: #a5b4fc; box-shadow: 0 0 0 .25rem rgba(79, 70, 229, .2);
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="text-center mb-4">
            <div class="brand-mark">S</div>
            <h1 class="h5 fw-semibold mt-3 mb-1">{{ config('app.name') }}</h1>
            <p class="text-muted small mb-0">Khu điều hành nền tảng</p>
        </div>

        <form method="POST" action="{{ route('login.attempt') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" value="{{ old('email', $rememberedEmail ?? '') }}"
                       class="form-control @error('email') is-invalid @enderror"
                       required autofocus placeholder="Nhập địa chỉ email">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Mật khẩu</label>
                <input type="password" name="password"
                       class="form-control @error('password') is-invalid @enderror"
                       required placeholder="••••••••">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-check mb-3">
                <input type="checkbox" name="remember" id="remember" value="1"
                       class="form-check-input" @checked(!empty($rememberedEmail))>
                <label class="form-check-label" for="remember">Ghi nhớ đăng nhập</label>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Đăng nhập
            </button>
        </form>

        <p class="text-center text-muted small mb-0 mt-4">
            Chỉ tài khoản quản trị nền tảng mới vào được trang này.
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @include('partials.toasts')
</body>
</html>
