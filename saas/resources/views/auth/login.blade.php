<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng nhập — {{ config('app.name') }}</title>

    {{-- Cùng bộ favicon với trang trong, xem chú thích ở layouts/app.blade.php. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=3">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --sa-accent: #4f46e5;       /* tím của khu điều hành nền tảng */
            --sa-accent-dark: #4338ca;
        }

        /* Thẻ đăng nhập nằm giữa màn hình, giống trang đăng nhập của Shop Admin. */
        body {
            position: relative;
            min-height: 100vh;
            margin: 0;
            padding: 32px;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1f2330;
            background: #14122b;
        }

        /* Ảnh nền tách ra lớp riêng để làm mờ được mà không mờ luôn thẻ đăng nhập.
           Mờ rất nhẹ (2px) cho ảnh lùi ra sau, vẫn nhìn rõ là màn hình phần mềm;
           không phủ màu gì thêm. Phóng 1.03 để mép ảnh mờ không hở viền. */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            z-index: -1;
            background: url('{{ asset('images/login-bg.jpg') }}') center / cover no-repeat;
            filter: blur(2px);
            transform: scale(1.03);
        }

        .login-box {
            box-sizing: border-box;
            width: 100%;
            max-width: 440px;
            padding: 30px 34px 24px;
            background: #fff;
            border-radius: 10px;
            /* Bóng toả đều bốn phía chứ không chỉ đổ xuống dưới: thẻ trắng rơi
               trúng vùng sáng của ảnh nền vẫn thấy rõ mép. Lớp 1 là quầng mỏng
               ôm sát viền, lớp 2 tạo độ nổi. */
            box-shadow: 0 0 24px rgba(16, 24, 40, .10),
                        0 8px 32px rgba(16, 24, 40, .18);
        }

        .login-head { text-align: center; margin-bottom: 22px; }
        .login-head img { max-width: 160px; height: auto; }
        .login-head p { margin: 12px 0 0; color: #6b7280; font-size: 13px; }

        .login-box .form-label { font-weight: 500; margin-bottom: .35rem; color: #374151; }

        /* Ô nhập có biểu tượng bên trái. Dùng input-group của Bootstrap để phần
           báo lỗi phía dưới vẫn chạy đúng như cũ. */
        .login-box .input-group-text {
            background: #fff;
            border-right: 0;
            color: #adb5bd;
            width: 40px;
            justify-content: center;
        }
        .login-box .input-group .form-control { border-left: 0; padding-left: 2px; }
        .login-box .form-control { padding-top: .55rem; padding-bottom: .55rem; }
        .login-box .form-control::placeholder { color: #b8bfc9; }

        /* Focus tô riêng từng ô, giữ nguyên vòng tím như bản cũ của app này. */
        .login-box .form-control:focus {
            border-color: #a5b4fc;
            box-shadow: 0 0 0 .25rem rgba(79, 70, 229, .2);
        }

        /* Ô sai thì đỏ cả biểu tượng bên trái, nếu không nhìn như viền đỏ bị hụt
           mất một góc. Trình duyệt cũ không hiểu :has() thì chỉ mất phần tô đỏ ở
           biểu tượng, ô nhập và dòng báo lỗi vẫn đỏ như thường. */
        .login-box .input-group:has(.form-control.is-invalid) .input-group-text {
            border-color: #dc3545;
        }

        /* Nút xem mật khẩu nằm trong ô, không phải nút bấm riêng bên cạnh. */
        .btn-eye {
            border: 1px solid #dee2e6;
            border-left: 0;
            background: #fff;
            color: #9aa3af;
            border-radius: 0 .375rem .375rem 0;
            padding: 0 12px;
        }
        .btn-eye:hover { color: var(--sa-accent); }

        .login-box .btn-primary {
            font-weight: 500;
            background-color: var(--sa-accent);
            border-color: var(--sa-accent);
        }
        .login-box .btn-primary:hover,
        .login-box .btn-primary:focus {
            background-color: var(--sa-accent-dark);
            border-color: var(--sa-accent-dark);
        }

        .login-foot {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #eef0f3;
            text-align: center;
            font-size: 12px;
            color: #9aa3af;
        }

        @media (max-width: 900px) {
            body { padding: 20px; }
        }

        @media (max-width: 480px) {
            body { padding: 16px; }
            .login-box { padding: 26px 20px 20px; }
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-head">
            <img src="{{ asset('images/logo-default.svg') }}" alt="{{ config('app.name') }}">
            <p>Khu điều hành nền tảng</p>
        </div>

        <form method="POST" action="{{ route('login.attempt') }}" novalidate>
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" name="email" id="email"
                           value="{{ old('email', $rememberedEmail ?? '') }}"
                           class="form-control @error('email') is-invalid @enderror"
                           required autofocus
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           autocomplete="username" placeholder="Nhập địa chỉ email">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password"
                           class="form-control @error('password') is-invalid @enderror"
                           required autocomplete="current-password" placeholder="••••••••">
                    <button type="button" class="btn-eye" id="togglePassword"
                            aria-label="Hiện mật khẩu" aria-pressed="false" tabindex="-1">
                        <i class="bi bi-eye" id="togglePasswordIcon"></i>
                    </button>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
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

        <div class="login-foot">
            Chỉ tài khoản quản trị nền tảng mới vào được trang này.
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @include('partials.toasts')
    <script>
        // Xem lại mật khẩu vừa gõ. Không đụng tới ô nào khác nên để rời ở đây.
        (function () {
            var btn = document.getElementById('togglePassword');
            var input = document.getElementById('password');
            var icon = document.getElementById('togglePasswordIcon');
            if (!btn || !input) return;

            btn.addEventListener('click', function () {
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                icon.className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
                btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
                btn.setAttribute('aria-label', showing ? 'Hiện mật khẩu' : 'Ẩn mật khẩu');
                input.focus();
            });
        })();
    </script>
</body>
</html>
