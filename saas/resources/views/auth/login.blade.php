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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Cùng bảng màu và cùng bộ chữ với trang trong (xem layouts/app.blade.php):
           đăng nhập là màn hình đầu tiên người ta thấy, nó phải trông như cùng
           một phần mềm với thứ đứng sau nó. */
        :root {
            --ink:       #1B1027;
            --ink-2:     #5B5266;
            --ink-3:     #8B8394;
            --plum:      #3A1266;
            --plum-deep: #2A0D4B;
            --plum-lift: #4C1D82;
            --gold:      #FFC20F;
            --paper:     #F6F4F1;
            --rule:      #E4E0DB;
            --rule-soft: #EFECE8;
            --bad:       #B42318;

            --font-display: 'Archivo', 'Segoe UI', sans-serif;
            --font-body:    'IBM Plex Sans', 'Segoe UI', sans-serif;
            --font-data:    'IBM Plex Mono', ui-monospace, monospace;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0; min-height: 100vh;
            display: grid; grid-template-columns: 1fr 1fr;
            font-family: var(--font-body); font-size: 14px; color: var(--ink);
            background: #fff;
            -webkit-font-smoothing: antialiased;
        }

        /* ===== Nửa trái: bảng thương hiệu =====
           Nền tím đặc, cùng một dải chuyển màu với thanh trái bên trong khu điều
           hành: đăng nhập xong là thấy đúng vật liệu đó nằm dọc bên trái, hai
           màn hình nối liền nhau chứ không phải hai trang rời.

           Trước đây chỗ này là ảnh chụp màn hình phần mềm làm nền, làm mờ đi.
           Bỏ hẳn: ảnh mờ vẫn đọc được số và chữ nên nó tranh chỗ với tiêu đề,
           mà ảnh sản phẩm mờ sau thẻ đăng nhập cũng đúng là kiểu trang mà bản
           mẫu nào cũng dựng. Ảnh vẫn nằm trong public/images nếu cần lấy lại. */
        .brand-side {
            background: linear-gradient(180deg, var(--plum) 0%, var(--plum) 52%, var(--plum-deep) 100%);
            display: flex; flex-direction: column; justify-content: space-between;
            padding: 46px 50px;
            color: #fff;
        }

        /* align-self bắt buộc: mặc định flex kéo hộp của ảnh rộng bằng cả cột,
           và SVG thì tự căn giữa trong hộp của nó — thành ra logo nằm giữa cột
           chứ không nằm góc trái như trông đợi. */
        .brand-side img { height: 30px; width: auto; align-self: flex-start; }

        .brand-copy { max-width: 24em; }
        /* Gạch vàng trơ, không kèm chữ: dấu hiệu thương hiệu duy nhất trên trang,
           giống gạch đầu trang bên trong khu điều hành. Trước có kèm chữ "SELLIO"
           nhưng logo Sellio đứng ngay phía trên — đọc tên hãng hai lần cách nhau
           một dòng thì lần thứ hai chỉ là chữ thừa. */
        .brand-copy .mark {
            display: block; width: 44px; height: 2px;
            background: var(--gold); margin-bottom: 20px;
        }
        .brand-copy h1 {
            margin: 0;
            font-family: var(--font-display);
            font-size: 32px; font-weight: 600; line-height: 1.18; letter-spacing: -.02em;
        }
        .brand-copy p {
            margin: 14px 0 0; font-size: 14.5px; line-height: 1.6;
            color: rgba(255, 255, 255, .70);
        }

        .brand-foot {
            font-family: var(--font-data); font-size: 11.5px;
            color: rgba(255, 255, 255, .42);
        }

        /* ===== Nửa phải: biểu mẫu ===== */
        .form-side {
            display: flex; align-items: center; justify-content: center;
            padding: 46px 50px; background: #fff;
        }
        .form-wrap { width: 100%; max-width: 372px; }

        .form-wrap h2 {
            margin: 0 0 4px;
            font-family: var(--font-display);
            font-size: 22px; font-weight: 600; letter-spacing: -.01em;
        }
        .form-wrap .lede { margin: 0 0 28px; font-size: 13.5px; color: var(--ink-2); }

        .form-label {
            font-family: var(--font-display);
            font-size: 11px; font-weight: 600; letter-spacing: .08em;
            text-transform: uppercase; color: var(--ink-2); margin-bottom: 6px;
        }
        .form-control {
            border: 1px solid var(--rule); border-radius: 3px;
            padding: 10px 12px; font-size: 14px; color: var(--ink);
        }
        .form-control:focus {
            border-color: var(--plum-lift);
            box-shadow: 0 0 0 3px rgba(76, 29, 130, .14);
        }
        .form-control::placeholder { color: #B6AFBF; }
        .form-control.is-invalid { border-color: var(--bad); }
        .invalid-feedback { font-size: 12.5px; color: var(--bad); }

        /* Nút xem mật khẩu viết bằng CHỮ chứ không phải hình con mắt: hai trạng
           thái "Hiện"/"Ẩn" nói thẳng nó sẽ làm gì, con mắt gạch chéo thì phải
           đoán. Cùng lý do bỏ biểu tượng ở thanh trái bên trong. */
        .peek {
            position: absolute; top: 50%; right: 10px; transform: translateY(-50%);
            border: 0; background: none; padding: 2px 4px;
            font-family: var(--font-display); font-size: 11px; font-weight: 600;
            letter-spacing: .06em; text-transform: uppercase; color: var(--ink-3);
            cursor: pointer;
        }
        .peek:hover { color: var(--plum-lift); }
        .field-pw { position: relative; }
        .field-pw .form-control { padding-right: 56px; }

        .form-check-input:checked { background-color: var(--plum); border-color: var(--plum); }
        .form-check-input:focus { border-color: var(--plum-lift); box-shadow: 0 0 0 3px rgba(76, 29, 130, .14); }
        .form-check-label { font-size: 13.5px; color: var(--ink-2); }

        .btn-plum {
            width: 100%; margin-top: 6px;
            background: var(--plum); border: 1px solid var(--plum); color: #fff;
            border-radius: 3px; padding: 11px 18px;
            font-family: var(--font-display); font-size: 13px; font-weight: 600;
            letter-spacing: .02em;
            transition: background-color .12s ease;
        }
        .btn-plum:hover, .btn-plum:focus { background: var(--plum-deep); border-color: var(--plum-deep); color: #fff; }

        .form-foot {
            margin-top: 26px; padding-top: 16px; border-top: 1px solid var(--rule-soft);
            font-size: 12.5px; color: var(--ink-3);
        }

        :focus-visible { outline: 2px solid var(--plum-lift); outline-offset: 2px; }
        .brand-side :focus-visible { outline-color: var(--gold); }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }

        /* Màn hình hẹp: bảng thương hiệu rút thành dải đầu trang, biểu mẫu xuống
           dưới. Bỏ câu giới thiệu dài, giữ logo và một dòng nói đây là chỗ nào. */
        @media (max-width: 880px) {
            body { grid-template-columns: 1fr; }
            /* Bỏ space-between: ở đây chỉ còn logo và tiêu đề, dàn hai đầu thì
               hở một khoảng tím rỗng giữa hai thứ đáng lẽ đứng liền nhau. */
            .brand-side { padding: 26px 24px 30px; gap: 20px; justify-content: flex-start; }
            .brand-copy h1 { font-size: 21px; }
            .brand-copy p, .brand-foot { display: none; }
            .form-side { padding: 34px 24px 46px; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <section class="brand-side">
        <img src="{{ asset('images/logo-default-wide-light.svg') }}" alt="{{ config('app.name') }}">

        {{-- Chữ ở đây nói thẳng đây là chỗ nào và làm được gì, không phải câu
             quảng cáo. Đây là công cụ nội bộ, người đọc nó là người trong nhà mở
             ra mỗi sáng — bán hàng cho họ là lạc chỗ. --}}
        <div class="brand-copy">
            <span class="mark" aria-hidden="true"></span>
            <h1>Khu điều hành nền tảng</h1>
            <p>Cửa hàng, gói dịch vụ, hạn dùng và hoá đơn của toàn bộ khách hàng đang chạy trên nền tảng.</p>
        </div>

        <div class="brand-foot">{{ config('api.base_url') }}</div>
    </section>

    <section class="form-side">
        <div class="form-wrap">
            <h2>Đăng nhập</h2>
            <p class="lede">Dùng tài khoản quản trị nền tảng.</p>

            <form method="POST" action="{{ route('login.attempt') }}" novalidate>
                @csrf

                <div class="mb-3">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" name="email" id="email"
                           value="{{ old('email', $rememberedEmail ?? '') }}"
                           class="form-control @error('email') is-invalid @enderror"
                           required autofocus
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           autocomplete="username" placeholder="ten@selliotech.store">
                    @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Mật khẩu</label>
                    <div class="field-pw">
                        <input type="password" name="password" id="password"
                               class="form-control @error('password') is-invalid @enderror"
                               required autocomplete="current-password" placeholder="••••••••">
                        <button type="button" class="peek" id="peek" aria-pressed="false" tabindex="-1">Hiện</button>
                    </div>
                    @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="form-check mb-4">
                    <input type="checkbox" name="remember" id="remember" value="1"
                           class="form-check-input" @checked(!empty($rememberedEmail))>
                    <label class="form-check-label" for="remember">Ghi nhớ đăng nhập</label>
                </div>

                <button type="submit" class="btn-plum">Đăng nhập</button>
            </form>

            <p class="form-foot mb-0">Tài khoản cửa hàng không đăng nhập được ở đây.</p>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @include('partials.toasts')
    <script>
        // Xem lại mật khẩu vừa gõ. Không đụng tới ô nào khác nên để rời ở đây.
        (function () {
            var btn = document.getElementById('peek');
            var input = document.getElementById('password');
            if (!btn || !input) return;

            btn.addEventListener('click', function () {
                var dangHien = input.type === 'text';
                input.type = dangHien ? 'password' : 'text';
                btn.textContent = dangHien ? 'Hiện' : 'Ẩn';
                btn.setAttribute('aria-pressed', dangHien ? 'false' : 'true');
                input.focus();
            });
        })();
    </script>
</body>
</html>
