@php
    $me = session('api.user');

    // Vai trò khu điều hành là một CHUỖI (owner|operator|support) — sổ nền tảng
    // không có bảng vai trò riêng để lấy tên hiển thị như bên cửa hàng.
    $vaiTro = [
        'owner' => 'Chủ nền tảng',
        'operator' => 'Điều hành',
        'support' => 'Hỗ trợ (chỉ xem)',
    ][data_get($me, 'role')] ?? data_get($me, 'role');

    $ten = data_get($me, 'full_name') ?: data_get($me, 'email') ?: 'Tài khoản';

    // Chữ đầu của hai từ cuối trong tên: người Việt gọi nhau bằng tên, nên
    // "Nguyễn Quốc Huy" phải ra QH chứ không phải NQ. Tên chỉ một từ thì lấy
    // một chữ; địa chỉ email thì lấy chữ đầu.
    $tuTen = preg_split('/\s+/u', trim($ten), -1, PREG_SPLIT_NO_EMPTY);
    $chuDau = mb_strtoupper(implode('', array_map(
        fn ($t) => mb_substr($t, 0, 1),
        array_slice($tuTen, -2)
    )));
@endphp
<header class="topbar">
    {{-- Chỉ hiện dưới 860px, lúc thanh trái đã thành ngăn kéo. Script đóng/mở
         nằm cuối layouts/app.blade.php. --}}
    <button type="button" class="rail-toggle" id="railToggle"
            aria-controls="rail" aria-expanded="false" aria-label="Mở menu">
        <span aria-hidden="true"></span>
    </button>

    <h1>@yield('title', 'Tổng quan')</h1>

    <div class="ms-auto">
        <div class="dropdown">
            <button class="account dropdown-toggle" data-bs-toggle="dropdown" type="button"
                    aria-label="Tài khoản đang đăng nhập: {{ $ten }}">
                <span class="account-mark" aria-hidden="true">{{ $chuDau ?: '—' }}</span>
                <span class="d-none d-sm-inline">{{ $ten }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li class="dropdown-head">
                    <div style="font-weight:600">{{ data_get($me, 'full_name') ?: '—' }}</div>
                    <div class="muted mono" style="margin-top:2px">{{ data_get($me, 'email') }}</div>
                    <div class="state state-wait" style="margin-top:7px">{{ $vaiTro ?: '—' }}</div>
                </li>
                <li>
                    {{-- Đăng xuất phải là POST: để GET thì chỉ cần dụ mở một link
                         là đá được người khác ra khỏi phiên làm việc. --}}
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item" style="color:var(--bad)">Đăng xuất</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
