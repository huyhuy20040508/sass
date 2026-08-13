<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tổng quan') — {{ config('app.name') }}</title>

    {{-- Favicon Sellio, dùng chung bộ ảnh với Shop Admin và trang giới thiệu
         selliotech.store: .svg cho trình duyệt mới, .ico dự phòng,
         apple-touch-icon cho màn hình chính iOS. Khác Shop Admin ở chỗ khu điều
         hành nền tảng không cho khách đổi thương hiệu, nên gắn cứng. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=3">

    {{-- Ba vai chữ, cả ba đều có bộ dấu tiếng Việt (đã đối chiếu subset
         `vietnamese` của Google Fonts — thiếu là chữ có dấu rơi sang phông dự
         phòng, mỗi chữ một kiểu):
           Archivo       — tiêu đề, nhãn, menu. Chữ vuông vức, đọc chắc ở cỡ nhỏ.
           IBM Plex Sans — chữ chạy trong câu.
           IBM Plex Mono — CON SỐ và giá trị của máy (địa chỉ API, mã vai trò).
         Cùng họ Plex nên sans và mono khớp nhau, Archivo đứng riêng làm giọng
         tiêu đề. Cố tình không dùng Inter: nó là phông mặc định của mọi bảng
         điều khiển dựng nhanh, nhìn là biết chưa ai chọn phông cả. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">

    {{-- Bootstrap ở đây chỉ để lấy lưới, dropdown và toast. Phần nhìn (nút, thẻ,
         huy hiệu) viết đè bên dưới: để nguyên kiểu mặc định thì trang nào cũng
         giống trang nào. Bootstrap Icons đã bỏ hẳn — biểu tượng rắc khắp nơi là
         thứ làm một trang quản trị nội bộ trông như bản mẫu. --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ==========================================================
           Bảng màu lấy thẳng từ logo Sellio: tím cà #3A1266 và vàng
           #FFC20F. Bản trước dùng #4f46e5 — indigo mặc định của
           Tailwind, không liên quan gì tới thương hiệu.

           Vàng KHÔNG bao giờ làm chữ trên nền sáng (tương phản 1.7:1,
           không đọc được). Nó chỉ xuất hiện đúng hai chỗ mỗi trang:
           vạch đánh dấu mục đang mở ở thanh trái, và gạch đầu trang.
           Hiếm thì mới còn là dấu hiệu.
           ========================================================== */
        :root {
            --ink:        #1B1027;
            --ink-2:      #5B5266;
            --ink-3:      #8B8394;

            --plum:       #3A1266;
            --plum-deep:  #2A0D4B;
            --plum-lift:  #4C1D82;
            --gold:       #FFC20F;

            --paper:      #F6F4F1;   /* trắng ngà ấm: vàng đặt cạnh xám lạnh bị xỉn */
            --surface:    #FFFFFF;
            --rule:       #E4E0DB;
            --rule-soft:  #EFECE8;

            /* Ba màu trạng thái đậm và trầm hơn bản mặc định của Bootstrap:
               đây là màn hình nhìn cả ngày, đỏ tươi xanh tươi làm mỏi mắt. */
            --good:       #1F7A5C;
            --warn:       #A96A0B;
            --bad:        #B42318;

            --font-display: 'Archivo', 'Segoe UI', sans-serif;
            --font-body:    'IBM Plex Sans', 'Segoe UI', sans-serif;
            --font-data:    'IBM Plex Mono', ui-monospace, 'Cascadia Mono', monospace;
        }

        * { box-sizing: border-box; }

        /* Cỡ chữ nền lấy đúng bên Shop Admin: 14px / 20px / -0.2px. Hai app hay
           mở cạnh nhau, chữ lệch cỡ là nhận ra ngay. */
        body {
            margin: 0;
            background: var(--paper);
            font-family: var(--font-body);
            font-size: 14px;
            line-height: 20px;
            letter-spacing: -.2px;
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }

        .layout { display: flex; min-height: 100vh; }
        .content { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        /* ===== Thanh trái =====
           Không biểu tượng. Menu chỉ là chữ, nên chữ phải tự làm được việc của
           biểu tượng: giãn khoảng cách chữ cái, cỡ nhỏ, chữ hoa — đọc ra ngay
           đây là mục điều hướng chứ không phải câu văn. */
        /* Đứng yên khi cuộn trang, cùng cách làm với Shop Admin
           (partials/sidebar.blade.php bên admin).

           height:100vh không thừa: nó vừa cho thanh một chiều cao cố định để
           `sticky` có chỗ bám, vừa chặn `align-items:stretch` của flex kéo thanh
           cao bằng cả trang — bị kéo cao rồi thì không còn gì để dính, thanh sẽ
           cuộn đi mất như thường. */
        .rail {
            position: sticky; top: 0; height: 100vh;
            width: 230px; flex: 0 0 230px;    /* 230px — đúng số đo bên Shop Admin */
            overflow-x: hidden;
            /* Chuyển màu rất nhẹ xuống đáy. Menu chỉ có một mục nên phần dưới
               thanh là một mảng tím dài; phẳng lì thì đọc như trang chưa dựng
               xong, tối dần xuống thì thành chiều sâu. */
            background: linear-gradient(180deg, var(--plum) 0%, var(--plum) 52%, var(--plum-deep) 100%);
            display: flex; flex-direction: column;
        }

        /* Hàng logo cao đúng 56px bằng thanh trên, nên vạch kẻ dưới logo và vạch
           kẻ dưới thanh trên nằm trên cùng một đường ngang — cùng cách bố trí
           với Shop Admin. Dòng "Điều hành nền tảng" trước nằm ở đây, giờ chuyển
           xuống làm tiêu đề nhóm trong menu để giữ được chiều cao 56px. */
        .rail-brand {
            display: flex; align-items: center; height: 56px; flex: 0 0 56px;
            padding: 0 12px; text-decoration: none;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }
        /* max-width chặn bề ngang: logo chữ dài hơn chỗ trống thì tự co lại thay
           vì tràn ra khỏi thanh. */
        .rail-brand img { height: 42px; width: auto; max-width: 100%; object-fit: contain; object-position: left; }

        /* min-height:0 để phần menu tự cuộn được khi sau này nhiều mục hơn chiều
           cao màn hình; thiếu nó thì ô flex không co xuống dưới min-content và
           dải tình trạng ở đáy bị đẩy khỏi tầm nhìn. */
        .rail-nav {
            flex: 1; min-height: 0; overflow-y: auto;
            display: flex; flex-direction: column; gap: 4px;
            padding: 8px 5px;
        }
        .rail-group {
            margin: 0; padding: 12px 12px 2px;
            font-family: var(--font-display);
            font-size: 11px; font-weight: 600; line-height: 16px;
            letter-spacing: .05em; text-transform: uppercase;
            color: rgba(255, 255, 255, .42);
        }
        /* padding 8px 12px + line-height 20px = hàng cao 36px, khớp .jh-item bên
           Shop Admin. Chữ hoa cỡ 13 thay vì 14 thường: chữ hoa nhìn to hơn cùng
           cỡ, để 14 là hàng menu lấn át nội dung trang. */
        .rail-link {
            display: block;
            padding: 8px 12px; border-radius: 6px;
            font-family: var(--font-display);
            font-size: 13px; font-weight: 600; line-height: 20px; letter-spacing: .06em;
            text-transform: uppercase; text-decoration: none;
            color: rgba(255, 255, 255, .62);
            transition: color .12s ease, background-color .12s ease;
        }
        .rail-link:hover { color: #fff; background: rgba(255, 255, 255, .06); }
        /* Vạch vàng đánh dấu mục đang mở, vẽ bằng inset box-shadow để nó ăn theo
           góc bo 6px của mục — một vạch vuông góc nằm trong khung bo tròn thì lộ
           ra là hai thứ dán vào nhau. */
        .rail-link.is-current {
            color: #fff; background: var(--plum-lift);
            box-shadow: inset 3px 0 0 var(--gold);
        }

        /* Dải tình trạng đáy thanh trái: sức khoẻ của máy thuộc về khung phần
           mềm, luôn nhìn thấy ở mọi trang — chứ không nằm trong một cái thẻ chỉ
           trang Tổng quan mới có. Số đo theo .jh-footer bên Shop Admin. */
        .rail-status {
            padding: 8px; flex: 0 0 auto;
            border-top: 1px solid rgba(255, 255, 255, .08);
        }
        .rail-status-line {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 12px;
            font-size: 13px; line-height: 20px; color: rgba(255, 255, 255, .78);
        }
        .dot { width: 7px; height: 7px; border-radius: 50%; flex: 0 0 7px; }
        .dot-good { background: #46C08D; }
        .dot-bad  { background: #F2705F; }

        /* ===== Thanh trên =====
           Cũng dính đỉnh như thanh trái: tên trang và nút tài khoản luôn trong
           tầm với, không phải cuộn ngược lên đầu mới thấy. */
        .topbar {
            position: sticky; top: 0; z-index: 20;
            background: var(--surface);
            border-bottom: 1px solid var(--rule);
            height: 56px; flex: 0 0 56px; padding: 0 16px;   /* 56px / 16px — theo .jh-topbar */
            display: flex; align-items: center; gap: 16px;
        }
        .topbar h1 {
            margin: 0;
            font-family: var(--font-display);
            font-size: 15px; font-weight: 600; line-height: 22px; letter-spacing: -.005em;
        }

        /* Nút mở ngăn kéo — chỉ hiện dưới 860px, xem media query cuối tệp.
           Ba vạch vẽ bằng CSS chứ không mượn phông biểu tượng. Đây là nút điều
           khiển chứ không phải trang trí cho menu, nên nó không phạm vào quy tắc
           "menu chỉ có chữ": ba vạch là ký hiệu mọi người đã biết sẵn, còn để
           chữ "Menu" thì tốn chỗ đúng cái chỗ đang thiếu. */
        .rail-toggle {
            display: none; align-items: center; justify-content: center;
            width: 34px; height: 34px; flex: 0 0 34px; margin-left: -4px;
            border: 1px solid var(--rule); border-radius: 4px;
            background: none; cursor: pointer; color: var(--ink);
            transition: background-color .12s ease;
        }
        .rail-toggle:hover { background: var(--paper); }
        .rail-toggle span, .rail-toggle span::before, .rail-toggle span::after {
            display: block; width: 16px; height: 1.5px;
            background: currentColor; border-radius: 1px;
        }
        .rail-toggle span { position: relative; }
        .rail-toggle span::before, .rail-toggle span::after {
            content: ''; position: absolute; left: 0;
        }
        .rail-toggle span::before { top: -5px; }
        .rail-toggle span::after  { top: 5px; }

        /* Lớp phủ nền khi ngăn kéo mở. Ẩn hẳn ở màn hình rộng. */
        .rail-backdrop { display: none; }
        @media (max-width: 860px) { .rail-backdrop { display: block; } }

        /* Nút tài khoản: ô vuông chữ đầu tên thay cho biểu tượng người chung
           chung — nó nói được đang đăng nhập bằng ai, biểu tượng thì không. */
        .account {
            display: flex; align-items: center; gap: 10px;
            padding: 5px 10px 5px 5px;
            background: none; border: 1px solid transparent; border-radius: 3px;
            font-family: var(--font-body); font-size: 13px; color: var(--ink);
            cursor: pointer; transition: border-color .12s ease, background-color .12s ease;
        }
        .account:hover { background: var(--paper); border-color: var(--rule); }
        .account-mark {
            width: 28px; height: 28px; flex: 0 0 28px; border-radius: 3px;
            background: var(--plum); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--font-display); font-size: 11px; font-weight: 600;
            letter-spacing: .04em;
        }

        .dropdown-menu {
            border: 1px solid var(--rule); border-radius: 4px; padding: 0;
            box-shadow: 0 8px 28px rgba(27, 16, 39, .12);
            font-size: 13px; min-width: 240px;
        }
        .dropdown-head { padding: 13px 15px; border-bottom: 1px solid var(--rule-soft); }
        .dropdown-item { padding: 11px 15px; font-size: 13px; }
        .dropdown-item:active { background: var(--paper); color: var(--ink); }

        /* ===== Vùng nội dung =====
           padding 24px = đúng `main.p-4` bên Shop Admin. Bỏ max-width: bên đó
           nội dung chạy hết bề ngang, chặn lại thì màn hình rộng sẽ thấy hai app
           bố cục khác nhau. */
        .page { padding: 24px; flex: 1; }

        /* Gạch vàng đầu trang — chỗ thứ hai và cũng là cuối cùng màu vàng xuất
           hiện. Nó nối tiêu đề trang với thương hiệu ở thanh trái. */
        .page-head { margin-bottom: 26px; }
        .eyebrow {
            display: inline-block; padding-top: 9px;
            border-top: 2px solid var(--gold);
            font-family: var(--font-display);
            font-size: 10px; font-weight: 600; letter-spacing: .16em;
            text-transform: uppercase; color: var(--ink-3);
        }
        .page-head h2 {
            margin: 12px 0 0;
            font-family: var(--font-display);
            font-size: 27px; font-weight: 600; letter-spacing: -.02em; line-height: 1.2;
        }
        .page-head p { margin: 6px 0 0; color: var(--ink-2); font-size: 13.5px; }

        /* ===== Vật liệu chung ===== */
        .panel {
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 4px;   /* bo rất nhẹ: đây là bảng đồng hồ, không phải thẻ bài */
        }
        .panel-head {
            padding: 15px 20px; border-bottom: 1px solid var(--rule-soft);
            font-family: var(--font-display);
            font-size: 11px; font-weight: 600; letter-spacing: .12em;
            text-transform: uppercase; color: var(--ink-3);
        }
        .panel-body { padding: 20px; }

        .muted { color: var(--ink-2); }
        /* overflow-wrap:anywhere chứ không phải break-word: chỉ `anywhere` mới hạ
           được min-content, tức là mới ngăn được một chuỗi không có chỗ ngắt
           (đường dẫn API, tên bảng) banh cột grid chứa nó ra. */
        /* letter-spacing: 0 — body đang để -0.2px cho chữ thường, nhưng phông
           mono vốn đã rộng đều, bóp thêm là chữ dính vào nhau. */
        .mono  { font-family: var(--font-data); font-size: .9em; letter-spacing: 0; overflow-wrap: anywhere; }

        .state {
            font-family: var(--font-data); font-size: 11px; font-weight: 500;
            letter-spacing: .04em; text-transform: uppercase; white-space: nowrap;
        }
        .state-good { color: var(--good); }
        .state-wait { color: var(--ink-3); }
        .state-bad  { color: var(--bad); }

        /* ===== Nút ===== */
        .btn-plum {
            background: var(--plum); border: 1px solid var(--plum); color: #fff;
            border-radius: 3px; padding: 9px 18px;
            font-family: var(--font-display); font-size: 13px; font-weight: 600;
            transition: background-color .12s ease;
        }
        .btn-plum:hover, .btn-plum:focus { background: var(--plum-deep); border-color: var(--plum-deep); color: #fff; }

        /* ===== Ô nhập ===== */
        .form-label {
            font-family: var(--font-display);
            font-size: 11px; font-weight: 600; letter-spacing: .08em;
            text-transform: uppercase; color: var(--ink-2); margin-bottom: 6px;
        }
        .form-control, .form-select {
            border: 1px solid var(--rule); border-radius: 3px;
            padding: 9px 12px; font-size: 14px; color: var(--ink);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--plum-lift);
            box-shadow: 0 0 0 3px rgba(76, 29, 130, .14);
        }
        .form-control::placeholder { color: #B6AFBF; }

        /* ===== Bàn phím =====
           Viền tím trên nền sáng, viền vàng trên nền tím: người dùng bàn phím
           phải thấy mình đang đứng ở đâu trên cả hai nền. */
        :focus-visible { outline: 2px solid var(--plum-lift); outline-offset: 2px; }
        .rail :focus-visible { outline-color: var(--gold); }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }

        /* ==========================================================
           Co giãn theo bề ngang.

           Thanh trái giữ nguyên 230px cho tới lúc không còn chỗ — giống Shop
           Admin, bên đó cũng không tự bóp theo màn hình. Khác một điểm: bên đó
           thu gọn còn 48px chỉ chừa biểu tượng, ở đây không có biểu tượng nên
           thu như thế là mất luôn nghĩa. Thay vào đó dưới 860px thanh nằm ngang
           thành dải đầu trang.
           ========================================================== */

        /* Màn hình rất rộng: nới đệm ra một nấc cho nội dung không dính mép. */
        @media (min-width: 1600px) {
            .page { padding: 28px 32px; }
        }

        @media (max-width: 860px) {
            /* Thanh trái thành ngăn kéo trượt từ mép trái, mặc định đóng.

               position:fixed nên nó ra khỏi dòng chảy — .content tự chiếm hết bề
               ngang, không cần đổi hướng của .layout. Giữ nguyên bố cục dọc bên
               trong (logo trên, menu giữa, tình trạng dưới) để mở ra là thấy đúng
               thanh quen thuộc, chỉ khác là nó nằm đè lên trang.

               visibility (không phải chỉ transform): thanh đóng mà vẫn "nhìn thấy"
               được thì phím Tab vẫn nhảy vào mấy đường dẫn nằm ngoài màn hình.
               Trễ 0s sau khi trượt xong để lúc đóng vẫn kịp nhìn thấy nó trượt. */
            .rail {
                position: fixed; top: 0; left: 0; bottom: 0; height: auto;
                width: 268px; max-width: 84vw; flex: none;
                z-index: 60;
                transform: translateX(-100%); visibility: hidden;
                transition: transform .22s ease, visibility 0s linear .22s;
                box-shadow: 0 0 40px rgba(27, 16, 39, .35);
            }
            .rail.is-open {
                transform: none; visibility: visible;
                transition: transform .22s ease;
            }

            .rail-backdrop {
                position: fixed; inset: 0; z-index: 50;
                background: rgba(27, 16, 39, .45);
                opacity: 0; visibility: hidden;
                transition: opacity .22s ease, visibility 0s linear .22s;
            }
            .rail-backdrop.is-open {
                opacity: 1; visibility: visible;
                transition: opacity .22s ease;
            }

            .rail-toggle { display: inline-flex; }
        }

        @media (max-width: 560px) {
            .rail-brand { height: 52px; flex-basis: 52px; }
            .rail-brand img { height: 34px; }
            .topbar { padding: 0 12px; gap: 10px; }
            .page { padding: 16px 12px 28px; }
            .page-head { margin-bottom: 18px; }
            .page-head h2 { font-size: 21px; }
            .panel-head { padding: 12px 14px; }
            .panel-body { padding: 14px; }
        }
    </style>
    @stack('styles')
</head>
<body>
<div class="layout">
    @include('partials.sidebar')

    {{-- Nền mờ sau ngăn kéo. Bấm vào là đóng — ở màn hình nhỏ đây là cách đóng
         mà người ta thử trước tiên, trước cả khi đi tìm nút. --}}
    <div class="rail-backdrop" id="railBackdrop"></div>

    <div class="content">
        @include('partials.topbar')
        <main class="page">
            @yield('content')
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Ngăn kéo thanh trái ở màn hình nhỏ. Trên 860px thanh luôn hiện, script này
    // không đụng tới gì cả.
    (function () {
        var rail = document.getElementById('rail');
        var backdrop = document.getElementById('railBackdrop');
        var toggle = document.getElementById('railToggle');
        if (!rail || !backdrop || !toggle) return;

        var HEP = window.matchMedia('(max-width: 860px)');

        function dat(mo) {
            // Trên 860px thanh trái luôn hiện, không có gì để mở — chặn ở đây để
            // dù có ai gọi nhầm thì cũng không khoá cuộn trang của màn hình rộng.
            if (!HEP.matches) mo = false;

            rail.classList.toggle('is-open', mo);
            backdrop.classList.toggle('is-open', mo);
            toggle.setAttribute('aria-expanded', mo ? 'true' : 'false');
            toggle.setAttribute('aria-label', mo ? 'Đóng menu' : 'Mở menu');
            // Khoá cuộn trang phía sau: mở ngăn kéo rồi mà quệt tay vẫn cuộn được
            // trang bên dưới thì đọc như ngăn kéo bị rời ra khỏi trang.
            document.body.style.overflow = mo ? 'hidden' : '';
        }

        toggle.addEventListener('click', function () {
            dat(!rail.classList.contains('is-open'));
        });

        backdrop.addEventListener('click', function () { dat(false); });

        // Bấm vào một mục menu là chuyển trang — đóng luôn để lúc trang mới hiện
        // ra không còn ngăn kéo che mặt.
        rail.querySelectorAll('a[href]').forEach(function (a) {
            a.addEventListener('click', function () { dat(false); });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && rail.classList.contains('is-open')) {
                dat(false);
                toggle.focus();
            }
        });

        // Xoay ngang máy / kéo rộng cửa sổ qua mốc 860px: trả về trạng thái đóng,
        // nếu không thì body vẫn bị khoá cuộn trong khi thanh đã hiện sẵn.
        HEP.addEventListener('change', function (e) { if (!e.matches) dat(false); });
    })();
</script>

@include('partials.toasts')
@stack('scripts')
</body>
</html>
