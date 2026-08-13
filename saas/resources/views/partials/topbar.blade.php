@php
    $me = session('api.user');
    // Vai trò khu điều hành là một CHUỖI (owner|operator|support) — sổ nền tảng
    // không có bảng vai trò riêng để lấy tên hiển thị như bên cửa hàng.
    $vaiTro = [
        'owner' => 'Chủ nền tảng',
        'operator' => 'Điều hành',
        'support' => 'Hỗ trợ (chỉ xem)',
    ][data_get($me, 'role')] ?? data_get($me, 'role');
@endphp
<header class="topbar">
    <h1 class="page-title">@yield('title', 'Tổng quan')</h1>

    <div class="ms-auto d-flex align-items-center gap-3">
        <div class="dropdown">
            <button class="btn btn-sm btn-light border d-flex align-items-center gap-2 dropdown-toggle"
                    data-bs-toggle="dropdown" type="button">
                <i class="bi bi-person-circle"></i>
                <span class="d-none d-sm-inline">{{ data_get($me, 'full_name') ?: data_get($me, 'email') ?: 'Tài khoản' }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li class="px-3 py-2">
                    <div class="fw-semibold small">{{ data_get($me, 'full_name') ?: '—' }}</div>
                    <div class="text-muted-x small">{{ data_get($me, 'email') }}</div>
                    <span class="badge text-bg-secondary mt-1">{{ $vaiTro ?: '—' }}</span>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    {{-- Đăng xuất phải là POST: để GET thì chỉ cần dụ mở một link
                         là đá được người khác ra khỏi phiên làm việc. --}}
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right me-2"></i>Đăng xuất
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
