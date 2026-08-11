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

@include('partials.toasts')
@include('partials.modals')

{{-- Chuông thông báo + luồng realtime. Nạp SAU partials.toasts vì nó dùng
     window.adminToast được định nghĩa trong đó. --}}
<script src="{{ asset('js/realtime.js') }}?v=4"></script>
</body>
</html>
