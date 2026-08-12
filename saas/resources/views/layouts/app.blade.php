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

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        /* Bảng màu cố ý KHÁC Shop Admin (xanh #1890ff). Hai app hay mở cùng lúc
           trên hai tab; nhìn nhầm khu điều hành nền tảng thành khu bán hàng là
           kiểu nhầm dẫn tới thao tác sai trên dữ liệu của khách. */
        :root {
            --sa-bg: #f6f7fb;
            --sa-side: #1e1b3a;
            --sa-side-hover: #2c2752;
            --sa-accent: #4f46e5;
            --sa-muted: #6b7280;
            --sa-border: #e6e8f0;
        }

        body {
            background: var(--sa-bg);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #1f2330;
        }

        .layout { display: flex; min-height: 100vh; }
        .content { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        /* ===== Sidebar ===== */
        .sidebar {
            width: 248px; flex: 0 0 248px;
            background: var(--sa-side); color: #cdd0e0;
            display: flex; flex-direction: column;
        }
        .sidebar .brand {
            display: flex; flex-direction: column; align-items: flex-start; gap: 6px;
            padding: 18px 20px; color: #fff; text-decoration: none;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }
        /* max-width chặn bề ngang: logo chữ dài hơn chỗ trống thì tự co lại thay
           vì tràn ra khỏi sidebar. */
        .sidebar .brand-logo { height: 30px; width: auto; max-width: 100%; object-fit: contain; object-position: left; }
        /* Ô vuông chỉ dùng khi sidebar thu gọn còn 64px. */
        .sidebar .brand-mark { display: none; width: 32px; height: 32px; border-radius: 8px; }
        .sidebar .brand-sub { font-size: 11px; color: #9ca0bb; letter-spacing: .04em; text-transform: uppercase; }

        .nav-section {
            padding: 18px 20px 6px; font-size: 11px; font-weight: 600;
            letter-spacing: .06em; text-transform: uppercase; color: #7b7fa0;
        }
        .nav-link-x {
            display: flex; align-items: center; gap: 11px;
            padding: 9px 20px; color: #cdd0e0; text-decoration: none; font-size: 14px;
            border-left: 3px solid transparent;
        }
        .nav-link-x:hover { background: var(--sa-side-hover); color: #fff; }
        .nav-link-x.active { background: var(--sa-side-hover); color: #fff; border-left-color: var(--sa-accent); font-weight: 500; }
        .nav-link-x i { font-size: 16px; width: 18px; text-align: center; }

        /* Mục chưa làm: hiện ra để thấy đường đi của sản phẩm, nhưng bấm không
           được — thà xám và ghi "Sắp có" còn hơn bấm vào rồi ăn trang 404. */
        .nav-link-x.soon { color: #6a6e8c; cursor: not-allowed; }
        .nav-link-x.soon:hover { background: transparent; color: #6a6e8c; }
        .badge-soon {
            margin-left: auto; font-size: 10px; font-weight: 500;
            padding: 2px 7px; border-radius: 20px;
            background: rgba(255, 255, 255, .07); color: #8b8fae;
        }

        /* ===== Topbar ===== */
        .topbar {
            background: #fff; border-bottom: 1px solid var(--sa-border);
            padding: 12px 24px; display: flex; align-items: center; gap: 16px;
        }
        .topbar .page-title { font-size: 16px; font-weight: 600; margin: 0; }

        /* ===== Chung ===== */
        .card-x {
            background: #fff; border: 1px solid var(--sa-border);
            border-radius: 10px; box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        }
        .text-muted-x { color: var(--sa-muted); }
        .btn-primary { background-color: var(--sa-accent); border-color: var(--sa-accent); }
        .btn-primary:hover, .btn-primary:focus { background-color: #4338ca; border-color: #4338ca; }

        input:focus, select:focus, textarea:focus, .form-control:focus, .form-select:focus {
            border-color: #a5b4fc !important;
            box-shadow: 0 0 0 .25rem rgba(79, 70, 229, .2) !important;
            outline: none !important;
        }

        @media (max-width: 768px) {
            .sidebar { width: 64px; flex: 0 0 64px; }
            .sidebar .brand { align-items: center; padding: 18px 0; }
            .sidebar .brand-logo, .sidebar .brand-text,
            .sidebar .nav-section, .sidebar .nav-label, .badge-soon { display: none; }
            .sidebar .brand-mark { display: block; }
            .nav-link-x { justify-content: center; padding: 11px 0; }
        }
    </style>
    @stack('styles')
</head>
<body>
<div class="layout">
    @include('partials.sidebar')

    <div class="content">
        @include('partials.topbar')

        <main class="p-4 flex-grow-1">
            @yield('content')
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@include('partials.toasts')
@stack('scripts')
</body>
</html>
