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
        :root {
            --au-primary: #1890ff;   /* xanh chủ đạo, dùng chung toàn Shop Admin */
            --au-primary-dark: #0e7ce0;
            --au-brand: #3a1266;     /* tím Sellio, lấy từ logo */
        }

        /* Ảnh nền để nguyên: không làm mờ, không phủ màu. Thẻ đăng nhập đặt giữa
           màn hình cho dễ nhìn. */
        body {
            min-height: 100vh;
            margin: 0;
            padding: 32px;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Roboto, sans-serif;
            font-size: 14.4px;
            color: #212529;
            background: #2b2f36 url('{{ asset('images/login-bg.jpg') }}') center / cover no-repeat fixed;
        }

        /* ===== Thẻ đăng nhập ===== */
        /* Thẻ để rộng và xếp mã cửa hàng · tên đăng nhập thành một hàng, cho khối
           đăng nhập gần vuông thay vì kéo dài tuột xuống. */
        .login-box {
            box-sizing: border-box;
            width: 100%;
            max-width: 520px;
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

        .login-box .form-label {
            font-weight: 500;
            margin-bottom: .35rem;
            color: #374151;
        }

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

        /* Focus tô riêng từng ô bằng đúng hiệu ứng chuẩn của toàn hệ thống (viền
           #86b7fe + vòng xanh .25rem, xem layouts/app.blade.php), để trang đăng
           nhập không lạc kiểu so với các màn hình bên trong. */
        .login-box .form-control:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
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
        .btn-eye:hover { color: var(--au-primary); }

        .login-box .btn-primary {
            font-weight: 500;
            background: var(--au-primary);
            border-color: var(--au-primary);
        }
        .login-box .btn-primary:hover,
        .login-box .btn-primary:focus {
            background: var(--au-primary-dark);
            border-color: var(--au-primary-dark);
        }

        .login-box a { color: var(--au-primary); }

        .login-foot {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #eef0f3;
            text-align: center;
            font-size: 12px;
            color: #9aa3af;
        }
        .login-foot a { color: #6b7280; text-decoration: none; }
        .login-foot a:hover { color: var(--au-primary); }

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
            <p>Đăng nhập để quản trị hệ thống</p>
        </div>

        <form method="POST" action="{{ route('login.attempt') }}" novalidate>
            @csrf
            {{-- Ba ô: mã cửa hàng · tên đăng nhập · mật khẩu.
                 Mã cửa hàng là chuỗi được cấp lúc bàn giao phần mềm (bảng tenants),
                 không phải mã chi nhánh. Tên đăng nhập chỉ cần duy nhất trong một
                 cửa hàng, nên mỗi shop đều có thể có tài khoản 'admin' của riêng mình. --}}
            {{-- Hai ô ngắn xếp cùng một hàng cho thẻ đỡ dài; màn hình hẹp hơn
                 576px thì Bootstrap tự tách chúng thành hai dòng. --}}
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <label class="form-label" for="shopCode">Mã cửa hàng</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-shop"></i></span>
                        <input type="text" name="shop_code" id="shopCode"
                               value="{{ old('shop_code', $rememberedShopCode ?? '') }}"
                               class="form-control @error('shop_code') is-invalid @enderror"
                               maxlength="30" required
                               {{-- Con trỏ vào ô đầu còn trống: người đã ghi nhớ mã cửa hàng thì
                                    nhảy thẳng xuống ô tên đăng nhập, khỏi phải bấm chuột. --}}
                               @if (empty(old('shop_code', $rememberedShopCode ?? ''))) autofocus @endif
                               autocapitalize="none" autocorrect="off" spellcheck="false"
                               autocomplete="organization" placeholder="Mã cửa hàng được cấp">
                        @error('shop_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="username">Tên đăng nhập</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" id="username"
                               value="{{ old('username', $rememberedUsername ?? '') }}"
                               class="form-control @error('username') is-invalid @enderror"
                               maxlength="50" required
                               @if (filled(old('shop_code', $rememberedShopCode ?? '')) && empty(old('username', $rememberedUsername ?? ''))) autofocus @endif
                               autocapitalize="none" autocorrect="off" spellcheck="false"
                               autocomplete="username" placeholder="Nhập tên đăng nhập">
                        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Mật khẩu</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" name="password" id="password"
                           class="form-control @error('password') is-invalid @enderror"
                           required autocomplete="current-password"
                           @if (filled(old('shop_code', $rememberedShopCode ?? '')) && filled(old('username', $rememberedUsername ?? ''))) autofocus @endif
                           placeholder="••••••••">
                    <button type="button" class="btn-eye" id="togglePassword"
                            aria-label="Hiện mật khẩu" aria-pressed="false" tabindex="-1">
                        <i class="bi bi-eye" id="togglePasswordIcon"></i>
                    </button>
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check mb-0">
                    <input type="checkbox" name="remember" id="remember" value="1"
                           class="form-check-input" @checked(!empty($rememberedShopCode) || !empty($rememberedUsername))>
                    <label class="form-check-label" for="remember">Ghi nhớ đăng nhập</label>
                </div>
                <a href="{{ route('password.forgot') }}" class="small text-decoration-none">Quên mật khẩu?</a>
            </div>
            <button type="submit" class="btn btn-primary w-100 py-2">
                <i class="bi bi-box-arrow-in-right me-1"></i> Đăng nhập
            </button>
        </form>

        <div class="login-foot">
            Hỗ trợ: <a href="mailto:hello@selliotech.store">hello@selliotech.store</a>
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
