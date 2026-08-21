{{--
    Vỏ trang của MODULE THU NGÂN — dùng cho cả ba trang: Bán hàng, Điều phối ca
    & sổ quỹ, Lịch sử đơn.

    KHÔNG dùng layouts.app, và đây là điểm khác biệt lớn nhất giữa hai module:
    sidebar + topbar chiếm khoảng 320px chiều ngang và 60px chiều dọc của mọi
    trang quản trị. Trên máy quầy — thường là màn hình 14 inch dựng đứng cạnh
    máy in — chỗ đó đúng bằng một cột sản phẩm nữa. Mà trong lúc bán thì không
    ai bấm sang trang Sản phẩm hay xem chuông thông báo: người bán chỉ rời màn
    hình này khi hết ca.

    Đổi lại, module phải TỰ CÓ điều hướng của mình: ba ô icon ở GIỮA thanh trên
    cùng, cộng nút đổi sang khu quản trị ở góc phải (partials.module-switch,
    dùng chung với topbar quản trị).

    Thân trang chia KHU bằng bộ class .tnk-* ở cuối khối style: mỗi khu là một
    cột có thẻ tiêu đề dính trên nóc và tấm trắng bên dưới.

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

        /* Thanh trên cùng — ba vùng như bản v2: logo bên trái, điều hướng ở
           GIỮA, các nút trạng thái bên phải. Điều hướng đứng giữa vì đó là thứ
           duy nhất trên thanh mà người bán bấm; hai vùng kia chỉ để đọc.

           Cao 68px = đệm 5px + ô điều hướng 58px + đệm 5px. Giữ khuôn v2 nhưng
           thu nhỏ lại: v2 để 80px, mà trên màn 14 inch dựng ở quầy thì mỗi chục
           pixel của thanh này là một dòng hàng bớt đi bên dưới. */
        .posbar {
            display: flex;
            align-items: center;
            gap: 16px;
            height: 68px;
            padding: 5px 16px;
            background: #001529;
            color: #fff;
        }

        /* Hai vùng bên chia đều phần thừa để vùng giữa nằm đúng giữa thanh —
           không thì điều hướng lệch theo bề ngang của logo. */
        .posbar-brand { flex: 1 1 0; display: flex; align-items: center; gap: 10px; min-width: 0; }

        /* Cùng ảnh với sidebar khu quản trị: Cài đặt → Cấu hình cửa hàng, thiếu
           thì về logo mặc định bản chữ sáng (nền thanh này là xanh đậm). Cao
           34px, ngang tự co — logo ngang của khách có thể dài bất kỳ. */
        .posbar-logo-link { display: inline-flex; align-items: center; min-width: 0; }
        .posbar-logo { height: 34px; width: auto; max-width: 150px; object-fit: contain; object-position: left; }

        .posbar-shop {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding: 3px 9px;
            border-radius: 5px;
            background: rgba(255, 255, 255, .12);
            font-size: 12px;
            color: #d9e2ec;
        }

        /* Khoảng cách 12px giữa các nút bên phải — số của v2 (header_menu > *). */
        .posbar-right { flex: 1 1 0; display: flex; align-items: center; justify-content: flex-end; gap: 12px; }

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

        /* Điều hướng TRONG module — mỗi mục là một Ô có viền, icon trên chữ
           dưới. Giữ đúng dáng v2 (ô vuông viền 2px bo 8px, cả cụm không quá
           1010px) nhưng thu nhỏ theo thanh: 104×58 thay vì 120×68.

           CHỮ LUÔN TRẮNG, chỉ VIỀN đổi màu khi đang ở mục đó — cũng là cách v2
           làm. Làm mờ chữ ở mục không active thì trên nền xanh đậm nó tụt xuống
           dưới ngưỡng đọc được ở khoảng cách đứng bán. */
        .posnav {
            flex: 0 1 auto;
            display: flex; align-items: center; justify-content: center;
            gap: 12px; max-width: 1010px; min-width: 0; overflow-x: auto;
        }
        .posnav a {
            position: relative;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px;
            width: 104px; height: 58px; flex-shrink: 0; padding: 2px 5px;
            border: 2px solid #1e3a5f; border-radius: 8px;
            color: #fff; font-size: 12px; font-weight: 500;
            text-align: center; text-decoration: none;
            transition: border-color .15s, background .15s;
        }
        .posnav a:hover { border-color: rgba(250, 173, 20, .55); }
        .posnav a.is-on { border-color: #faad14; background: rgba(255, 255, 255, .06); }
        /* Icon là ảnh MÀU (images/icon/*.png), không phải nét đơn sắc — nên để
           28px chứ không 22px như bản vẽ tay: hình nhiều chi tiết, nhỏ hơn thì
           mấy màu trong đó nhoè thành một vệt. */
        .posnav img { width: 28px; height: 28px; flex-shrink: 0; object-fit: contain; }
        /* line-height 17px chứ không 14: "Điều phối ca" có dấu chồng cả trên lẫn
           dưới, mà span này overflow:hidden (để cắt bằng dấu ba chấm khi nhãn
           dài) — dòng chật là dấu bị xén mất. */
        .posnav a span {
            max-width: 100%; line-height: 17px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        /* Số đếm nằm TRONG góc ô, không tràn ra ngoài viền — dáng của v2, co
           lại cho vừa ô nhỏ hơn. */
        .posnav a b {
            position: absolute; top: 2px; right: 2px;
            display: flex; align-items: center; justify-content: center;
            min-width: 18px; height: 18px; padding: 0 4px; border-radius: 4px;
            background: #faad14; color: #262626; font-size: 10px; font-weight: 600;
        }
        /* Màn hình hẹp: ô co lại còn mỗi icon. Ba mục có icon riêng nên vẫn
           phân biệt được, mà chữ thì đang tranh chỗ với các nút bên phải. */
        @media (max-width: 1100px) { .posnav { gap: 8px; } .posnav a { width: 58px; } .posnav a span { display: none; } }

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

        .posmain { height: calc(100% - 68px); }
        /* Thân kín: đệm 12px, con bên trong lo phần cuộn của nó. */
        body.tn-kin .posmain { padding: 12px; }
        /* Thân thường: trang tự cuộn trong khung này, đệm rộng hơn cho dễ đọc. */
        body:not(.tn-kin) .posmain { overflow-y: auto; padding: 18px; }

        /* ---------- KHU: khuôn chia thân trang, lấy bố cục của bản v2 ----------

           Mỗi khu là một cột gồm THẺ TIÊU ĐỀ dính trên nóc và TẤM TRẮNG bên
           dưới. Thẻ chỉ rộng bằng chữ nên phần còn lại của hàng dùng để đặt nút
           của khu đó — nút đứng ngoài tấm trắng thì không bị cuộn đi mất khi
           ruột khu dài ra.

           Để ở layout chứ không ở từng trang: cả ba trang của module đều chia
           khu, mà ba bản sao thì sẽ có bản lệch khi sửa. */
        /* Số đo lấy nguyên của v2: hàng thẻ cao 40px (header-order), khe giữa
           hai khu 4px (menu-area border-right), tấm trắng bo 5px, riêng góc
           trên-phải của thẻ bo 18px. */
        .tnk { display: flex; gap: 4px; height: 100%; min-height: 0; }

        .tnk-khu { display: flex; flex-direction: column; min-width: 0; height: 100%; }

        .tnk-dau { display: flex; align-items: center; gap: 8px; height: 40px; }

        .tnk-tab {
            align-self: stretch;
            display: inline-flex; align-items: center; gap: 6px;
            padding: 0 18px;
            border-radius: 5px 18px 0 0; background: #fff;
            font-size: 16px; font-weight: 700; line-height: 16px; color: #262626;
        }

        /* Góc trên-trái vuông để nối liền với thẻ, ba góc kia bo. */
        .tnk-than {
            flex: 1; min-height: 0;
            display: flex; flex-direction: column;
            background: #fff; border-radius: 0 5px 5px 5px; overflow: hidden;
        }

        /* Tỉ lệ hai cột lấy theo v2: khu chọn hàng rộng hơn khu hoá đơn. */
        .tnk-khu--chinh { width: 57%; }
        .tnk-khu--phu { width: 43%; }

        /* Màn 14 inch ở quầy: chia đôi, vì khu hoá đơn có ô nhập tiền không co được. */
        @media (max-width: 1500px) {
            .tnk-khu--chinh, .tnk-khu--phu { width: 50%; }
        }

        /* Hẹp hơn nữa thì xếp dọc và trả quyền cuộn lại cho cả trang. */
        @media (max-width: 1100px) {
            .tnk { flex-wrap: wrap; height: auto; }
            .tnk-khu--chinh, .tnk-khu--phu { width: 100%; height: auto; }
        }
    </style>
    @yield('head')
</head>
<body class="@yield('than')">
    <div class="posbar">
        @php
            $tnApi = app(\App\Services\ApiClient::class);
            $tenTiem = $tnApi->settingString('site_name', config('app.name'));
            // Cùng ảnh với sidebar khu quản trị — một cửa hàng chỉ có một logo.
            $logoTiem = $tnApi->settingString('store_logo', asset('images/logo-default-wide-light.svg'));

            // Chi nhánh đang làm việc — chính là kho sắp bị trừ hàng. Hiện ở đây vì
            // đứng nhầm quầy thì hàng đi ra khỏi kho khác, mà module này đã bỏ
            // thanh trên cùng của khu quản trị nên không còn chỗ nào khác nói điều đó.
            //
            // Tiệm một chi nhánh không có gì để chọn nên cũng không hiện gì.
            $cn = \App\Services\ChiNhanhDangLam::danhSach();
            $tenChiNhanh = collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? '';
        @endphp
        {{-- Logo bấm được, về thẳng màn bán hàng: đó là "trang chủ" của module,
             và là chỗ người bán muốn quay lại sau khi ngó ca hay tra đơn. --}}
        <div class="posbar-brand">
            <a href="{{ route('thu-ngan.ban-hang.index') }}" class="posbar-logo-link" title="{{ $tenTiem }}">
                <img src="{{ $logoTiem }}" alt="{{ $tenTiem }}" class="posbar-logo">
            </a>
            @if($tenChiNhanh !== '')
                <span class="posbar-shop" title="Kho sẽ bị trừ hàng khi bán">{{ $tenChiNhanh }}</span>
            @endif
        </div>

        {{-- Ba trang của module. Thứ tự theo số lần bấm trong một ca: bán hàng
             cả ngày, ca hai lần (mở và đóng), lịch sử đơn khi có người hỏi lại
             một hoá đơn. --}}
        <nav class="posnav">
            <a href="{{ route('thu-ngan.ban-hang.index') }}"
               class="{{ request()->routeIs('thu-ngan.ban-hang.*') ? 'is-on' : '' }}">
                {{-- alt rỗng: nhãn nằm ngay dưới, đọc màn hình mà đọc thêm lần
                     nữa thì thành lặp. --}}
                <img src="{{ asset('images/icon/payment-method.png') }}" alt="">
                {{-- Nhãn lấy từ hằng của controller: menu và tiêu đề trang không
                     bao giờ được gọi cùng một màn hình bằng hai cái tên. --}}
                <span>{{ \App\Http\Controllers\BanTaiQuayController::TITLE }}</span>
            </a>
            <a href="{{ route('thu-ngan.ca-lam-viec.index') }}"
               class="{{ request()->routeIs('thu-ngan.ca-lam-viec.*') ? 'is-on' : '' }}">
                <img src="{{ asset('images/icon/change-management.png') }}" alt="">
                <span>{{ \App\Http\Controllers\CaLamViecController::TITLE }}</span>
            </a>
            <a href="{{ route('thu-ngan.don-hang.index') }}"
               class="{{ request()->routeIs('thu-ngan.don-hang.*') ? 'is-on' : '' }}">
                <img src="{{ asset('images/icon/order-history.png') }}" alt="">
                <span>{{ \App\Http\Controllers\ThuNganController::TITLE }}</span>
            </a>
        </nav>

        {{-- Vùng phải: trạng thái của chính cái quầy này (ca, đơn treo…) rồi tới
             lối ra — đổi module và tài khoản. --}}
        <div class="posbar-right">
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
