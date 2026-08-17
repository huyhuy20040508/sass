{{--
    Layout riêng cho màn hình thu ngân.

    KHÔNG dùng layouts.app, và đây là điểm khác biệt lớn nhất của giai đoạn 2:
    sidebar + topbar chiếm khoảng 320px chiều ngang và 60px chiều dọc của mọi
    trang quản trị. Trên máy quầy — thường là màn hình 14 inch dựng đứng cạnh
    máy in — chỗ đó đúng bằng một cột sản phẩm nữa. Mà trong lúc bán thì không
    ai bấm sang trang Sản phẩm hay xem chuông thông báo: người bán chỉ rời màn
    hình này khi hết ca.

    Đổi lại, trang phải TỰ CÓ một lối ra rõ ràng (nút Thoát quầy ở góc), vì
    không còn sidebar để bấm về.

    Cũng bỏ luôn Bootstrap: trang quầy không dùng component nào của nó, mà tải
    thêm ~60KB CSS chỉ để ghi đè lại là chậm đúng cái máy yếu nhất trong tiệm.
--}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Bán tại quầy') — {{ app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name')) }}</title>
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
            /* Không cho cả trang cuộn: hai cột bên trong tự cuộn phần của mình.
               Ở quầy, khối thu tiền phải luôn nằm nguyên chỗ — trôi khỏi tầm mắt
               giữa lúc đếm tiền là lúc người bán phải cuộn tìm lại nút. */
            overflow: hidden;
        }

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

        .posmain { height: calc(100% - 48px); padding: 12px; }
    </style>
    @yield('head')
</head>
<body>
    <div class="posbar">
        <span class="posbar-name">{{ app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name')) }}</span>
        @php
            // Chi nhánh đang làm việc — chính là kho sắp bị trừ hàng. Hiện ở đây vì
            // đứng nhầm quầy thì hàng đi ra khỏi kho khác, mà màn hình này đã bỏ
            // thanh trên cùng của khu quản trị nên không còn chỗ nào khác nói điều đó.
            //
            // Tiệm một chi nhánh không có gì để chọn nên cũng không hiện gì.
            $cn = \App\Services\ChiNhanhDangLam::danhSach();
            $tenChiNhanh = collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? '';
        @endphp
        @if($tenChiNhanh !== '')
            <span class="posbar-shop" title="Kho sẽ bị trừ hàng khi bán">{{ $tenChiNhanh }}</span>
        @endif

        <div class="posbar-spacer"></div>
        @yield('posbar')

        <a href="{{ route('admin.orders.index') }}" class="posbar-btn" title="Về khu quản trị">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg>
            Thoát quầy
        </a>
    </div>

    <div class="posmain">
        @yield('content')
    </div>
</body>
</html>
