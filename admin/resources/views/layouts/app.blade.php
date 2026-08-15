<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Token cho các request POST gọi bằng fetch (chuông thông báo, realtime). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Quản trị') — {{ app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name')) }}</title>
    {{-- Favicon lấy từ Cài đặt → Cấu hình cửa hàng; gỡ ảnh đi thì quay về bộ mặc
         định Sellio (dùng chung với trang giới thiệu selliotech.store): .svg cho
         trình duyệt mới, .ico dự phòng, apple-touch-icon cho màn hình chính iOS.
         Nền tím đặc sẵn trong hình nên đọc được cả trên tab sáng lẫn tối. --}}
    @php $adminFavicon = app(\App\Services\ApiClient::class)->settingString('store_favicon'); @endphp
    @if($adminFavicon !== '')
        <link rel="icon" href="{{ $adminFavicon }}">
        <link rel="apple-touch-icon" href="{{ $adminFavicon }}">
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=3">
    @endif
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .content { flex: 1; min-width: 0; }

        /* ===== Focus đồng bộ TOÀN HỆ THỐNG ===== */
        /* Mọi ô nhập / select / textarea khi focus đều dùng cùng hiệu ứng với ô
           tìm kiếm ở header (chuẩn Bootstrap: viền #86b7fe + vòng xanh .25rem).
           Dùng !important để thống nhất, đè các quy tắc focus riêng của từng module. */
        input:not([type=checkbox]):not([type=radio]):not([type=range]):focus,
        select:focus,
        textarea:focus,
        .form-control:focus,
        .form-select:focus {
            border-color: #86b7fe !important;
            box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25) !important;
            outline: none !important;
        }

        /* ===== Placeholder cho <select> — QUY TẮC CHUNG ===== */
        /* KHÔNG dùng "—" làm mục trống. Select tùy chọn đánh dấu [data-ph], mục trống
           là placeholder ("Chọn …"); khi chưa chọn (value rỗng) chữ hiển thị xám nhạt
           giống placeholder của input, chọn rồi thì về màu chữ bình thường. */
        select[data-ph].is-empty { color: #9ca3af; }
        select[data-ph] option { color: #212529; }
        select[data-ph] option[value=""] { color: #9ca3af; }

        /* ===== Phân trang đồng bộ TOÀN HỆ THỐNG (dùng class .pg-*) ===== */
        /* Một component phân trang chuẩn cho mọi trang danh sách: trái = chọn số dòng
           + thông tin "X–Y / Z"; phải = nút ‹ 1 2 … › (trang hiện tại nền xanh đặc). */
        .pg {
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between;
            gap: 10px 16px; padding: 14px 20px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .pg-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .pg-size {
            height: 32px; border: 1px solid #d9d9d9; border-radius: 6px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .pg-info { font-size: 13px; color: #8c8c8c; }
        .pg-info b { color: #262626; font-weight: 600; }
        .pg-nav { display: flex; align-items: center; gap: 4px; }
        .pg-btn {
            min-width: 32px; height: 32px; padding: 0 9px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; color: #374151; font-size: 13px; font-weight: 500;
            text-decoration: none; cursor: pointer; user-select: none; transition: border-color .15s, color .15s, background .15s;
        }
        .pg-btn:hover:not(.is-active):not(.is-disabled):not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .pg-btn.is-active { background: #1890ff; border-color: #1890ff; color: #fff; pointer-events: none; }
        .pg-btn.is-disabled, .pg-btn[disabled] { opacity: .45; pointer-events: none; cursor: default; }
        .pg-gap { min-width: 24px; height: 32px; display: inline-flex; align-items: center; justify-content: center; color: #bfbfbf; font-size: 13px; }
        @media (max-width: 640px) {
            .pg { justify-content: center; }
            .pg-info { width: 100%; text-align: center; }
            .pg-right { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<div class="d-flex">
    {{-- Sidebar --}}
    @include('partials.sidebar')

    {{-- Main --}}
    <div class="content d-flex flex-column">
        @include('partials.topbar')

        <main class="p-4">
            @yield('content')
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Quy tắc placeholder cho <select data-ph>: xám khi chưa chọn, đổi màu khi chọn.
    // Áp dụng cả select tạo động (modal) qua MutationObserver.
    (function () {
        function syncPh(sel) {
            if (sel && sel.tagName === 'SELECT' && sel.hasAttribute('data-ph')) {
                sel.classList.toggle('is-empty', sel.value === '');
            }
        }
        function initAll(root) {
            if (root && root.querySelectorAll) root.querySelectorAll('select[data-ph]').forEach(syncPh);
        }
        document.addEventListener('change', function (e) { syncPh(e.target); }, true);
        document.addEventListener('DOMContentLoaded', function () { initAll(document); });
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) return;
                    if (n.matches && n.matches('select[data-ph]')) syncPh(n);
                    initAll(n);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    })();
</script>

{{-- Hộp thoại "phiên đã kết thúc".
     Hiện khi API từ chối danh tính đang dùng: hợp đồng hết hạn nên cửa hàng bị
     khoá, tài khoản bị khoá, hoặc phiên đã chết hẳn. Trước đây những lượt gọi
     nền ấy chỉ hỏng lặng lẽ và người dùng ngồi lại trên một trang đã mất quyền,
     bấm gì cũng báo lỗi vu vơ — nên phải nói thẳng ra và đưa họ về đăng nhập.

     KHÔNG dùng modal Bootstrap: nó đóng được bằng phím Esc và bằng cú bấm ra
     ngoài, mà đây là thứ không nên đóng — quyền đã mất rồi, ở lại trang chỉ là
     ở lại với một màn hình không làm gì được nữa. --}}
<div id="phienHetHieuLuc"
     style="display:none; position:fixed; inset:0; z-index:2000; background:rgba(15,23,42,.55);
            align-items:center; justify-content:center; padding:16px;">
    <div style="max-width:420px; width:100%; background:#fff; border-radius:12px; padding:24px;
                box-shadow:0 20px 45px rgba(15,23,42,.25); font-family:'Inter',sans-serif;">
        <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
            <i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b; font-size:20px;"></i>
            <b style="font-size:16px; color:#111827;">Phiên làm việc đã kết thúc</b>
        </div>
        <p id="phienHetHieuLucLyDo" style="margin:0 0 18px; color:#4b5563; font-size:14px; line-height:1.6;"></p>
        <button type="button" id="phienHetHieuLucNut" class="btn btn-primary w-100">Đăng nhập lại</button>
    </div>
</div>

<script>
    // Bắt 401 của MỌI lượt fetch trong khu quản trị.
    //
    // Bọc window.fetch thay vì sửa từng chỗ gọi: các trang danh sách gọi fetch ở
    // hàng chục chỗ, và chỗ thứ mười một sẽ là chỗ quên. Chỉ ĐỌC mã trạng thái
    // rồi trả nguyên response về — phần xử lý lỗi sẵn có của từng trang vẫn chạy
    // y như cũ.
    (function () {
        var goc = window.fetch;
        if (typeof goc !== 'function') return;

        var dangHien = false;
        var $hop = document.getElementById('phienHetHieuLuc');
        var $lyDo = document.getElementById('phienHetHieuLucLyDo');
        var duongDangNhap = @json(route('login'));

        function hien(lyDo) {
            if (dangHien) return;
            dangHien = true;
            $lyDo.textContent = lyDo || 'Phiên đăng nhập không còn hiệu lực, vui lòng đăng nhập lại.';
            $hop.style.display = 'flex';
        }

        document.getElementById('phienHetHieuLucNut').addEventListener('click', function () {
            window.location.href = duongDangNhap;
        });

        window.fetch = function () {
            return goc.apply(this, arguments).then(function (res) {
                if (res && res.status === 401) {
                    // clone(): thân response chỉ đọc được MỘT lần, và bên gọi vẫn
                    // đang cần nó để hiện lỗi của riêng họ.
                    res.clone().json().then(function (data) {
                        hien(data && data.message);
                    }).catch(function () {
                        hien('');
                    });
                }

                return res;
            });
        };
    })();
</script>

@include('partials.toasts')
@include('partials.modals')

{{-- Chuông thông báo + luồng realtime. Nạp SAU partials.toasts vì nó dùng
     window.adminToast được định nghĩa trong đó.

     Cửa hàng hết hạn thì KHÔNG nạp: mọi đường thông báo lúc đó trả 403, nên
     script chỉ ngồi thử lại và bắn lỗi vào console. Người đang đọc trang gia hạn
     không cần thêm một cái chuông hỏng. --}}
@unless(\App\Services\HanSuDung::daKhoa())
    <script src="{{ asset('js/realtime.js') }}?v=5"></script>
@endunless

{{-- HẸN GIỜ HẾT HẠN — trên MỌI trang quản trị.
     Hợp đồng chết trong lúc người dùng đang mở dở một trang thì chính trang đó
     phải nói ra, chứ không đợi họ bấm đi đâu đó mới biết. Tới giây hết hạn thì
     đưa thẳng sang trang Các gói dịch vụ, nơi có hộp thoại và bảng giá.

     Số giây do MÁY CHỦ tính (xem HanSuDung::giayConLai) nên đồng hồ máy khách
     lệch giờ cũng không ảnh hưởng. Chỉ đặt hẹn khi còn dưới 24 giờ: setTimeout
     không đáng tin với khoảng dài, và tab nào cũng đóng trước đó. --}}
@php $gdvGiayConLai = \App\Services\HanSuDung::giayConLai(); @endphp
@if(! \App\Services\HanSuDung::daKhoa() && $gdvGiayConLai !== null && $gdvGiayConLai > 0 && $gdvGiayConLai < 86400)
    <script>
        setTimeout(function () {
            window.location.href = @json(route('admin.goi-dich-vu.index'));
        }, {{ $gdvGiayConLai * 1000 }} + 1500);
    </script>
@endif
</body>
</html>
