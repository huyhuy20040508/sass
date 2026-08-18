{{--
    Vỏ trang của MODULE THU NGÂN — dùng cho cả ba trang: Bán tại quầy, Ca làm
    việc & sổ quỹ, Đơn quầy.

    KHÔNG dùng layouts.app, và đây là điểm khác biệt lớn nhất giữa hai module:
    sidebar + topbar chiếm khoảng 320px chiều ngang và 60px chiều dọc của mọi
    trang quản trị. Trên máy quầy — thường là màn hình 14 inch dựng đứng cạnh
    máy in — chỗ đó đúng bằng một cột sản phẩm nữa. Mà trong lúc bán thì không
    ai bấm sang trang Sản phẩm hay xem chuông thông báo: người bán chỉ rời màn
    hình này khi hết ca.

    Đổi lại, module phải TỰ CÓ điều hướng của mình: một hàng ba mục trên thanh
    trên cùng, cộng nút đổi sang khu quản trị ở góc phải (partials.module-switch,
    dùng chung với topbar quản trị).

    Cũng bỏ luôn Bootstrap: cả module không dùng component nào của nó, mà tải
    thêm ~60KB CSS chỉ để ghi đè lại là chậm đúng cái máy yếu nhất trong tiệm.

    Hai kiểu thân trang, chọn bằng section 'than':
      - để trống (mặc định): khối nội dung tự cuộn, có đệm — cho trang danh sách.
      - 'tn-kin': khoá cuộn, nội dung chiếm trọn phần còn lại — cho màn hình bán
        hàng, nơi khối thu tiền phải luôn nằm nguyên chỗ.
--}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Thu ngân') — {{ app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name')) }}</title>
    @php $favicon = app(\App\Services\ApiClient::class)->settingString('store_favicon'); @endphp
    @if($favicon !== '')
        <link rel="icon" href="{{ $favicon }}">
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    @endif
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }

        html, body {
            height: 100%;
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 14px;
            color: #262626;
            background: #eef1f6;
        }

        /* Thân KÍN (màn hình bán hàng): không cho cả trang cuộn — hai cột bên
           trong tự cuộn phần của mình. Ở quầy, khối thu tiền trôi khỏi tầm mắt
           giữa lúc đếm tiền là lúc người bán phải cuộn đi tìm lại nút. */
        body.tn-kin { overflow: hidden; }

        /* Thanh trên cùng: mỏng, chỉ giữ những gì cần lúc đang bán. */
        .posbar {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 48px;
            padding: 0 14px;
            background: #001529;
            color: #fff;
        }

        .posbar-name { font-size: 14px; font-weight: 600; }

        .posbar-shop {
            padding: 2px 8px;
            border-radius: 4px;
            background: rgba(255, 255, 255, .12);
            font-size: 12px;
            color: #d9e2ec;
        }

        .posbar-spacer { flex: 1; }

        .posbar-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            height: 32px;
            padding: 0 12px;
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 6px;
            background: transparent;
            color: #fff;
            font-size: 13px;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .posbar-btn:hover { background: rgba(255, 255, 255, .12); }

        .posbar-btn b {
            min-width: 18px;
            padding: 0 5px;
            border-radius: 9px;
            background: #faad14;
            color: #262626;
            font-size: 11px;
            line-height: 18px;
        }

        /* Điều hướng TRONG module — ba mục, nằm ngay cạnh tên cửa hàng.
           Không dùng dạng thanh trái như khu quản trị: cả module chỉ có ba
           trang, mà một cột dọc thì lấy mất đúng khoảng ngang mà màn hình bán
           hàng cần. */
        .posnav { display: flex; align-items: center; gap: 2px; margin-left: 6px; }
        .posnav a {
            display: inline-flex; align-items: center; gap: 6px;
            height: 32px; padding: 0 11px; border-radius: 6px;
            color: #b9c6d6; font-size: 13px; font-weight: 500; text-decoration: none;
            transition: background .15s, color .15s;
        }
        .posnav a:hover { background: rgba(255, 255, 255, .1); color: #fff; }
        .posnav a.is-on { background: rgba(255, 255, 255, .16); color: #fff; font-weight: 600; }
        .posnav svg { width: 15px; height: 15px; flex-shrink: 0; }
        /* Màn hình hẹp: chỉ còn icon. Ba mục có icon riêng nên vẫn phân biệt được,
           mà chữ thì đang tranh chỗ với các nút trạng thái của quầy. */
        @media (max-width: 900px) { .posnav a span { display: none; } .posnav a { padding: 0 9px; } }

        /* Phân trang — cùng bộ class .pg-* với khu quản trị (xem layouts/app).
           Chép sang đây chứ không kéo cả Bootstrap và stylesheet của khu kia về:
           hai trang danh sách của module dùng đúng chừng này. */
        .pg {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 10px 16px; padding: 14px 20px;
        }
        .pg-info { font-size: 13px; color: #8c8c8c; }
        .pg-info b { color: #262626; font-weight: 600; }
        .pg-nav { display: flex; align-items: center; gap: 4px; }
        .pg-btn {
            min-width: 32px; height: 32px; padding: 0 9px;
            display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #e5e7eb; border-radius: 6px; background: #fff;
            color: #374151; font-size: 13px; font-weight: 500;
            text-decoration: none; cursor: pointer; user-select: none;
            transition: border-color .15s, color .15s;
        }
        .pg-btn:hover:not(.is-disabled) { border-color: #1890ff; color: #1890ff; }
        .pg-btn.is-disabled { opacity: .45; pointer-events: none; cursor: default; }

        /* Menu tài khoản trên thanh quầy — cùng khuôn hộp trắng với menu đổi
           module bên cạnh, để hai popup trên một thanh không lệch nhau. */
        .postk { position: relative; }
        .postk-ten { max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (max-width: 900px) { .postk-ten { display: none; } }
        .postk-menu {
            position: absolute; right: 0; top: 100%; margin-top: 8px;
            min-width: 190px; border: 1px solid #e2e8f0; border-radius: 8px;
            background: #fff; padding: 6px; z-index: 60; display: none;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .1), 0 4px 6px -4px rgba(0, 0, 0, .1);
        }
        .postk.open .postk-menu { display: block; }
        .postk-menu form { margin: 0; }
        .postk-item {
            display: block; width: 100%; box-sizing: border-box;
            padding: 9px 10px; border: 0; border-radius: 6px;
            background: transparent; color: #1e293b; font-family: inherit; font-size: 13px;
            text-align: left; text-decoration: none; cursor: pointer;
        }
        .postk-item:hover { background: #f8fafc; }
        .postk-item--out { color: #cf1322; }

        .posmain { height: calc(100% - 48px); }
        /* Thân kín: đệm 12px, con bên trong lo phần cuộn của nó. */
        body.tn-kin .posmain { padding: 12px; }
        /* Thân thường: trang tự cuộn trong khung này, đệm rộng hơn cho dễ đọc. */
        body:not(.tn-kin) .posmain { overflow-y: auto; padding: 18px; }
    </style>
    @yield('head')
</head>
<body class="@yield('than')">
    <div class="posbar">
        <span class="posbar-name">{{ app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name')) }}</span>
        @php
            // Chi nhánh đang làm việc — chính là kho sắp bị trừ hàng. Hiện ở đây vì
            // đứng nhầm quầy thì hàng đi ra khỏi kho khác, mà module này đã bỏ
            // thanh trên cùng của khu quản trị nên không còn chỗ nào khác nói điều đó.
            //
            // Tiệm một chi nhánh không có gì để chọn nên cũng không hiện gì.
            $cn = \App\Services\ChiNhanhDangLam::danhSach();
            $tenChiNhanh = collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? '';
        @endphp
        @if($tenChiNhanh !== '')
            <span class="posbar-shop" title="Kho sẽ bị trừ hàng khi bán">{{ $tenChiNhanh }}</span>
        @endif

        {{-- Ba trang của module. Thứ tự theo số lần bấm trong một ca: bán hàng
             cả ngày, ca làm việc hai lần (mở và đóng), đơn quầy khi có người
             hỏi lại một hoá đơn. --}}
        <nav class="posnav">
            <a href="{{ route('thu-ngan.ban-hang.index') }}"
               class="{{ request()->routeIs('thu-ngan.ban-hang.*') ? 'is-on' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18l-1.4 12.2a1 1 0 0 1-1 .8H5.4a1 1 0 0 1-1-.8Z"/><path d="M8.5 10V6.5a3.5 3.5 0 0 1 7 0V10"/></svg>
                {{-- Nhãn lấy từ hằng của controller: menu và tiêu đề trang không
                     bao giờ được gọi cùng một màn hình bằng hai cái tên. --}}
                <span>{{ \App\Http\Controllers\BanTaiQuayController::TITLE }}</span>
            </a>
            <a href="{{ route('thu-ngan.ca-lam-viec.index') }}"
               class="{{ request()->routeIs('thu-ngan.ca-lam-viec.*') ? 'is-on' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M2 10h20"/><circle cx="12" cy="14" r="1.5"/></svg>
                <span>{{ \App\Http\Controllers\CaLamViecController::TITLE }}</span>
            </a>
            <a href="{{ route('thu-ngan.don-hang.index') }}"
               class="{{ request()->routeIs('thu-ngan.don-hang.*') ? 'is-on' : '' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10a1 1 0 0 1 1 1v17l-3-2-3 2-3-2-3 2V4a1 1 0 0 1 1-1Z"/><path d="M9.5 8h5M9.5 12h5"/></svg>
                <span>{{ \App\Http\Controllers\ThuNganController::TITLE }}</span>
            </a>
        </nav>

        <div class="posbar-spacer"></div>
        @yield('posbar')

        {{-- Đổi module — cùng cái nút với topbar quản trị, chỉ khác tông màu. --}}
        @include('partials.module-switch', ['tone' => 'toi'])

        {{-- Tài khoản đang trực + Đăng xuất.
             Phải có ở đây: hết ca là người trực đăng xuất ngay tại quầy, mà
             module này không có topbar quản trị để mượn menu người dùng. Thiếu
             nó thì họ buộc phải sang khu quản trị chỉ để bấm một nút — hoặc bỏ
             luôn, để máy quầy đăng nhập sẵn qua đêm. --}}
        @php
            $tnUser = session('api.user');
            $tnTen = trim((string) data_get($tnUser, 'full_name', '')) ?: (string) data_get($tnUser, 'username', 'Tài khoản');
            // Người CHỈ có cửa quầy không mở được hồ sơ: cả trang "Tài khoản của
            // tôi" nằm trong khu quản trị, và khu đó không dành cho họ. Menu ở quầy
            // vì thế chỉ còn đúng nút Đăng xuất.
            //
            // Ẩn ở đây LÀ ĐỦ để màn hình gọn, nhưng không đủ để gọi là chặn — route
            // admin.profile.* đứng sau `admin.cua:quan_ly`, nên gõ thẳng đường dẫn
            // cũng không vào được.
            $tnCoHoSo = in_array('quan_ly', \App\Http\Middleware\EnsureCuaVao::cuaCuaPhien(), true);
        @endphp
        <div class="postk" id="postk">
            <button type="button" class="posbar-btn" id="postkBtn" aria-haspopup="true" aria-expanded="false">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/><circle cx="12" cy="8" r="4"/></svg>
                <span class="postk-ten">{{ $tnTen }}</span>
            </button>

            <div class="postk-menu">
                @if($tnCoHoSo)
                    <a href="{{ route('admin.profile.edit') }}" class="postk-item">Tài khoản của tôi</a>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="postk-item postk-item--out">Đăng xuất</button>
                </form>
            </div>
        </div>
    </div>

    <div class="posmain">
        @yield('content')
    </div>

    <script>
        // Menu tài khoản: bấm để mở, bấm ra ngoài hoặc Esc để đóng.
        (function () {
            var boc = document.getElementById('postk');
            var nut = document.getElementById('postkBtn');
            if (!boc || !nut) return;

            function dong() {
                boc.classList.remove('open');
                nut.setAttribute('aria-expanded', 'false');
            }

            nut.addEventListener('click', function (e) {
                e.stopPropagation();
                var mo = !boc.classList.contains('open');
                boc.classList.toggle('open', mo);
                nut.setAttribute('aria-expanded', mo ? 'true' : 'false');
            });

            document.addEventListener('click', function (e) {
                if (!boc.contains(e.target)) dong();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') dong();
            });
        })();
    </script>
</body>
</html>
