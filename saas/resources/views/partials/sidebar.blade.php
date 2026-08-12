{{--
    Sidebar của khu điều hành nền tảng.

    Các mục gắn class `soon` là bản đồ sản phẩm, chưa có trang đằng sau: chúng
    hiện ra để nhìn một cái là biết SaaS Admin sẽ gồm những gì, nhưng không bấm
    được. Khi làm xong trang nào thì đổi mục đó thành <a href> bình thường.
--}}
<aside class="sidebar">
    {{-- Logo Sellio bản chữ sáng vì nền sidebar là tím đậm. Sidebar thu về 64px
         ở màn hình hẹp, lúc đó logo chữ dài không còn đọc được nên đổi sang ô
         vuông favicon. Khu điều hành nền tảng là của mình, không phải của khách,
         nên logo gắn cứng chứ không lấy từ cấu hình như bên Shop Admin. --}}
    <a href="{{ route('platform.dashboard') }}" class="brand">
        <img src="{{ asset('images/logo-default-wide-light.svg') }}" alt="{{ config('app.name') }}" class="brand-logo">
        <img src="{{ asset('favicon.svg') }}" alt="{{ config('app.name') }}" class="brand-mark">
        <span class="brand-text">
            <div class="brand-sub">Điều hành nền tảng</div>
        </span>
    </a>

    <nav class="py-2 flex-grow-1">
        <div class="nav-section">Tổng quan</div>
        <a href="{{ route('platform.dashboard') }}"
           class="nav-link-x {{ request()->routeIs('platform.dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2"></i><span class="nav-label">Tổng quan</span>
        </a>

        <div class="nav-section">Khách hàng</div>
        <span class="nav-link-x soon">
            <i class="bi bi-shop"></i><span class="nav-label">Cửa hàng</span>
            <span class="badge-soon">Sắp có</span>
        </span>
        <span class="nav-link-x soon">
            <i class="bi bi-box-seam"></i><span class="nav-label">Gói dịch vụ</span>
            <span class="badge-soon">Sắp có</span>
        </span>
        <span class="nav-link-x soon">
            <i class="bi bi-receipt"></i><span class="nav-label">Hoá đơn</span>
            <span class="badge-soon">Sắp có</span>
        </span>

        <div class="nav-section">Hệ thống</div>
        <span class="nav-link-x soon">
            <i class="bi bi-people"></i><span class="nav-label">Người vận hành</span>
            <span class="badge-soon">Sắp có</span>
        </span>
        <span class="nav-link-x soon">
            <i class="bi bi-journal-text"></i><span class="nav-label">Nhật ký</span>
            <span class="badge-soon">Sắp có</span>
        </span>
        <span class="nav-link-x soon">
            <i class="bi bi-gear"></i><span class="nav-label">Cấu hình</span>
            <span class="badge-soon">Sắp có</span>
        </span>
    </nav>
</aside>
