{{--
    Topbar port 1:1 từ topbar.tsx (Next.js/Tailwind) sang Blade + CSS thuần.
    - Ô tìm kiếm (Ctrl + K), nút Trợ giúp, Thông báo (badge 2).
    - Menu người dùng: avatar chữ cái đầu, tên, vai trò, đăng xuất.
--}}
@php
    $u = session('api.user');
    $email = (string) data_get($u, 'email', '');
    // Ưu tiên họ tên: từ khi có trang "Tài khoản của tôi" thì đây là tên người dùng
    // tự đặt cho mình, hiện phần đầu email thay vào chỗ đó là phủi đi lần sửa đó.
    $name = trim((string) data_get($u, 'full_name', ''));
    if ($name === '') {
        // Chưa đặt họ tên thì lấy tên đăng nhập — đó là thứ người này vừa gõ để
        // vào đây. Phần đầu email chỉ còn là đường lui cuối cùng.
        $name = (string) data_get($u, 'username', '');
    }
    if ($name === '') {
        $name = $email !== '' ? explode('@', $email)[0] : 'Admin';
    }
    $role = (string) data_get($u, 'role.display_name', data_get($u, 'role.name', ''));
    // Cửa hàng lấy từ lúc đăng nhập 3 ô (session api.tenant). Phiên cũ mở từ trước
    // khi đổi luồng thì chưa có — để trống, không hiện gì thêm.
    $shop = trim((string) data_get(session('api.tenant'), 'name', ''));
    $initials = mb_strtoupper(mb_substr($name, 0, 2), 'UTF-8');
@endphp

<header class="jh-topbar">
    {{-- Search --}}
    <div class="jh-tb-search">
        <svg class="jh-tb-search__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
        </svg>
        <input id="jhTbSearch" type="text" placeholder="Tìm kiếm (Ctrl + K)">
    </div>

    {{-- Chi nhánh ĐANG LÀM VIỆC.
         Chỉ hiện khi cửa hàng có từ HAI chi nhánh trở lên: tiệm một chi nhánh
         không có gì để chọn, và một ô select chỉ có đúng một dòng là thứ chiếm
         chỗ mà không trả lời câu hỏi nào.
         Mọi thao tác kho/đơn từ đây trở đi ăn theo chi nhánh đang chọn — xem
         ApiClient::KHOA_CHI_NHANH. --}}
    @php($cnDangLam = \App\Services\ChiNhanhDangLam::danhSach())
    @if(count($cnDangLam['ds']) > 1)
        <form method="POST" action="{{ route('admin.chi-nhanh.dangLam') }}" class="jh-tb-chinhanh" id="jhCnForm">
            @csrf
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9.5 21v-6h5v6"/></svg>
            <select name="id" onchange="document.getElementById('jhCnForm').submit()" aria-label="Chi nhánh đang làm việc">
                {{-- 0 = xem gộp cả cửa hàng: đúng thứ chủ tiệm cần khi muốn nhìn
                     tổng kho, và cũng là trạng thái mặc định. --}}
                <option value="0" {{ $cnDangLam['dangChon'] ? '' : 'selected' }}>Tất cả chi nhánh</option>
                @foreach($cnDangLam['ds'] as $cn)
                    <option value="{{ $cn['id'] }}" {{ $cnDangLam['dangChon'] === (int) $cn['id'] ? 'selected' : '' }}>
                        {{ $cn['name'] }}
                    </option>
                @endforeach
            </select>
        </form>
    @endif

    {{-- Right actions --}}
    <div class="jh-tb-actions">
        {{-- Đổi module (Quản trị ↔ Thu ngân).
             Chỗ này trước là nút dấu hỏi "Trợ giúp" — bấm vào không xảy ra gì
             cả, mà lại chiếm đúng vị trí cạnh chuông thông báo. --}}
        @include('partials.module-switch')

        {{-- Chuông thông báo — danh sách nạp bằng fetch, cập nhật tức thì qua SSE
             (xem public/js/realtime.js). Các URL truyền qua data-* để file JS tĩnh
             không phải đoán đường dẫn route. --}}
        <div class="jh-tb-notif" id="jhTbNotif"
             data-list-url="{{ route('admin.notifications.index') }}"
             data-read-url="{{ url('/admin/notifications') }}"
             data-read-all-url="{{ route('admin.notifications.readAll') }}"
             data-stream-token-url="{{ route('admin.notifications.streamToken') }}"
             data-orders-url="{{ route('admin.orders.index') }}"
             data-returns-url="{{ route('admin.returns.index') }}"
             data-inventory-url="{{ route('admin.ton-kho-chi-nhanh.index') }}">
            <button type="button" class="jh-tb-iconbtn" id="jhTbNotifBtn" aria-label="Thông báo"
                    aria-haspopup="true" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>
                </svg>
                <span class="jh-tb-badge" id="jhTbNotifBadge" hidden>0</span>
            </button>

            <div class="jh-tb-notifmenu">
                <div class="jh-tb-notifmenu__head">
                    <span class="jh-tb-notifmenu__title">Thông báo</span>
                    {{-- Chấm trạng thái kết nối: xanh = nhận tức thì (SSE),
                         cam = đang hỏi định kỳ (SSE bị chặn), xám = mất kết nối. --}}
                    <span class="jh-tb-notifdot is-off" id="jhTbNotifDot" title="Chưa kết nối"></span>

                    {{-- Bật/tắt tiếng chuông — lựa chọn nhớ trong localStorage. --}}
                    <button type="button" class="jh-tb-notifsound" id="jhTbNotifSound"
                            aria-pressed="true" title="Tắt tiếng chuông">
                        <svg class="jh-tb-notifsound__on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>
                        </svg>
                        <svg class="jh-tb-notifsound__off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="m17 9 4 6"/><path d="m21 9-4 6"/>
                        </svg>
                    </button>

                    <button type="button" class="jh-tb-notifmenu__readall" id="jhTbNotifReadAll">Đánh dấu đã đọc</button>
                </div>

                <div class="jh-tb-notiflist" id="jhTbNotifList">
                    <p class="jh-tb-notifempty">Đang tải…</p>
                </div>

                <div class="jh-tb-notifmenu__foot">
                    <a href="{{ route('admin.orders.index') }}">Xem tất cả đơn hàng</a>
                </div>
            </div>
        </div>

        <span class="jh-tb-divider"></span>

        {{-- User --}}
        <div class="jh-tb-user" id="jhTbUser">
            <button type="button" class="jh-tb-userbtn" id="jhTbUserBtn">
                <span class="jh-tb-avatar">{{ $initials }}</span>
                <span class="jh-tb-name">{{ $name }}</span>
                <svg class="jh-tb-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="m6 9 6 6 6-6"/>
                </svg>
            </button>

            <div class="jh-tb-menu">
                <div class="jh-tb-menu__head">
                    <p class="jh-tb-menu__name">{{ $name }}</p>
                    @if($role !== '')
                        {{-- Kèm cửa hàng vừa đăng nhập: một người có thể có tài khoản
                             ở nhiều cửa hàng, mà mọi màn hình trông y hệt nhau — nhìn
                             nhầm chỗ rồi sửa giá hay xoá đơn là hỏng dữ liệu thật. --}}
                        <p class="jh-tb-menu__role">{{ $role }}@if($shop !== '') · {{ $shop }}@endif</p>
                    @endif
                </div>
                {{-- Hồ sơ & mật khẩu của chính mình. Đặt ở menu này chứ không phải
                     sidebar: sidebar là điều hướng giữa các module dữ liệu, còn đây
                     là tài khoản đang đăng nhập.

                     CHỈ hiện cho người có cửa quản trị. Người chỉ đứng quầy còn đúng
                     nút Đăng xuất — cùng một menu, cùng một luật với thanh trên cùng
                     của quầy, và route cũng đóng lại nên đây không phải là giấu nút. --}}
                @if(in_array('quan_ly', \App\Http\Middleware\EnsureCuaVao::cuaCuaPhien(), true))
                    <a href="{{ route('admin.profile.edit') }}" class="jh-tb-menu__item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4.5 20a7.5 7.5 0 0 1 15 0"/><circle cx="12" cy="8" r="4"/>
                        </svg>
                        Tài khoản của tôi
                    </a>
                @endif

                <form method="POST" action="{{ route('logout') }}" class="jh-tb-logout-form">
                    @csrf
                    <button type="submit" class="jh-tb-menu__item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>
                        </svg>
                        Đăng xuất
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>

<style>
    .jh-tb-chinhanh {
        display: inline-flex; align-items: center; gap: 6px; margin-left: 12px;
        height: 34px; padding: 0 10px; border: 1px solid #e5e7eb; border-radius: 6px;
        background: #fff; color: #374151;
    }
    .jh-tb-chinhanh select {
        border: 0; outline: none; background: none; font-size: 13px; font-weight: 600;
        color: #111827; max-width: 180px; cursor: pointer;
    }

    .jh-topbar {
        position: sticky; top: 0; z-index: 20;
        display: flex; align-items: center; gap: 16px;
        height: 56px; padding: 0 16px;
        background: #fff; border-bottom: 1px solid #e2e8f0;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
    }

    /* Search */
    .jh-tb-search { position: relative; flex: 1; max-width: 576px; }
    .jh-tb-search__icon {
        position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
        height: 16px; width: 16px; color: #94a3b8; pointer-events: none;
    }
    .jh-tb-search input {
        width: 100%; box-sizing: border-box;
        border: 1px solid #e2e8f0; border-radius: 6px; background: #f8fafc;
        padding: 8px 12px 8px 36px; font-size: 14px; color: #334155; outline: none;
    }
    .jh-tb-search input::placeholder { color: #94a3b8; }
    /* Đồng bộ với focus mặc định của .form-control (Bootstrap) ở các form khác */
    .jh-tb-search input:focus {
        border-color: #86b7fe; background: #fff;
        box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
    }
    .jh-tb-search:focus-within .jh-tb-search__icon { color: #0d6efd; }

    /* Right */
    .jh-tb-actions { margin-left: auto; display: flex; align-items: center; gap: 2px; }
    /* Nút đổi module là một viên có viền, còn chuông là icon trần — để sát nhau
       thì viền chạm vào vùng bấm của chuông. */
    .jh-tb-actions .mdsw { margin-right: 8px; }
    .jh-tb-iconbtn {
        position: relative; display: inline-flex; align-items: center; justify-content: center;
        height: 38px; width: 38px;
        border: 0; background: transparent; cursor: pointer;
        border-radius: 9999px; color: #64748b;
        transition: background .15s, color .15s;
    }
    .jh-tb-iconbtn:hover { background: #f1f5f9; color: #334155; }
    .jh-tb-iconbtn svg { height: 20px; width: 20px; }
    .jh-tb-badge {
        position: absolute; right: 5px; top: 5px;
        display: flex; align-items: center; justify-content: center;
        height: 16px; min-width: 16px; padding: 0 4px;
        border-radius: 9999px; background: #ef4444;
        font-size: 10px; font-weight: 600; line-height: 1; color: #fff;
        box-shadow: 0 0 0 2px #fff;
    }

    /* ===== Chuông thông báo ===== */
    /* Dùng lại đúng khuôn dropdown của menu người dùng bên dưới (nền trắng, viền
       #e2e8f0, bo 8px, đổ bóng) để hai popup trên cùng một topbar không lệch nhau. */
    .jh-tb-notif { position: relative; }
    .jh-tb-notifmenu {
        position: absolute; right: 0; top: 100%; margin-top: 8px;
        width: 360px; max-width: calc(100vw - 24px);
        border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
        z-index: 30; display: none; overflow: hidden;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .1), 0 4px 6px -4px rgba(0, 0, 0, .1);
    }
    .jh-tb-notif.open .jh-tb-notifmenu { display: block; }

    .jh-tb-notifmenu__head {
        display: flex; align-items: center; gap: 8px;
        padding: 10px 12px; border-bottom: 1px solid #f1f5f9;
    }
    .jh-tb-notifmenu__title { font-size: 14px; font-weight: 600; color: #1e293b; }
    .jh-tb-notifmenu__readall {
        margin-left: auto; border: 0; background: transparent; cursor: pointer;
        font-size: 12px; color: #2563eb; padding: 2px 4px; border-radius: 4px;
    }
    .jh-tb-notifmenu__readall:hover { background: #eff6ff; }
    .jh-tb-notifmenu__readall[disabled] { color: #94a3b8; cursor: default; background: transparent; }

    /* Chấm trạng thái kết nối realtime */
    .jh-tb-notifdot { width: 7px; height: 7px; border-radius: 9999px; background: #22c55e; flex-shrink: 0; }
    .jh-tb-notifdot.is-poll { background: #f59e0b; }
    .jh-tb-notifdot.is-off { background: #cbd5e1; }

    /* Nút bật/tắt tiếng chuông — hiện đúng một trong hai icon theo trạng thái */
    .jh-tb-notifsound {
        margin-left: auto; display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px; padding: 0; border: 0; border-radius: 6px;
        background: transparent; color: #64748b; cursor: pointer; transition: background .15s, color .15s;
    }
    .jh-tb-notifsound:hover { background: #f1f5f9; color: #334155; }
    .jh-tb-notifsound svg { width: 15px; height: 15px; }
    .jh-tb-notifsound.is-off { color: #cbd5e1; }
    .jh-tb-notifsound .jh-tb-notifsound__off { display: none; }
    .jh-tb-notifsound.is-off .jh-tb-notifsound__on { display: none; }
    .jh-tb-notifsound.is-off .jh-tb-notifsound__off { display: block; }

    /* Nút tiếng chuông đã đẩy sang phải rồi, "đánh dấu đã đọc" bám ngay sau nó */
    .jh-tb-notifmenu__head .jh-tb-notifmenu__readall { margin-left: 0; }

    .jh-tb-notiflist { max-height: 380px; overflow-y: auto; }
    .jh-tb-notifempty { margin: 0; padding: 24px 12px; text-align: center; font-size: 13px; color: #94a3b8; }

    .jh-tb-notifitem {
        display: block; width: 100%; text-align: left;
        border: 0; border-bottom: 1px solid #f8fafc; background: #fff;
        padding: 10px 12px; cursor: pointer; text-decoration: none;
        transition: background .15s;
    }
    .jh-tb-notifitem:hover { background: #f8fafc; }
    /* Chưa đọc: nền xanh rất nhạt + vạch xanh bên trái, đọc rồi thì về nền trắng. */
    .jh-tb-notifitem.is-unread { background: #f5f9ff; box-shadow: inset 3px 0 0 #2563eb; }
    .jh-tb-notifitem.is-unread:hover { background: #eef5ff; }

    .jh-tb-notifitem__title {
        display: block; font-size: 13px; font-weight: 600; color: #1e293b;
        margin-bottom: 2px; line-height: 1.35;
    }
    .jh-tb-notifitem__body { display: block; font-size: 12px; color: #475569; line-height: 1.4; }
    .jh-tb-notifitem__time { display: block; margin-top: 4px; font-size: 11px; color: #94a3b8; }

    .jh-tb-notifmenu__foot { border-top: 1px solid #f1f5f9; padding: 8px 12px; text-align: center; }
    .jh-tb-notifmenu__foot a { font-size: 13px; color: #2563eb; text-decoration: none; }
    .jh-tb-notifmenu__foot a:hover { text-decoration: underline; }

    /* Divider giữa nhóm icon và user */
    .jh-tb-divider {
        width: 1px; height: 24px; margin: 0 8px;
        background: #e2e8f0; flex-shrink: 0;
    }

    /* User */
    .jh-tb-user { position: relative; }
    .jh-tb-userbtn {
        display: flex; align-items: center; gap: 8px;
        border: 0; background: transparent; cursor: pointer;
        border-radius: 9999px; padding: 3px 10px 3px 3px;
        transition: background .15s;
    }
    .jh-tb-userbtn:hover { background: #f1f5f9; }
    .jh-tb-user.open .jh-tb-userbtn { background: #f1f5f9; }
    .jh-tb-avatar {
        display: flex; align-items: center; justify-content: center;
        height: 32px; width: 32px; border-radius: 9999px;
        background: #6366f1; color: #fff; font-size: 12px; font-weight: 600;
        letter-spacing: .3px; flex-shrink: 0;
    }
    .jh-tb-name {
        font-size: 14px; font-weight: 500; color: #334155;
        max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    @media (max-width: 640px) { .jh-tb-name { display: none; } }
    .jh-tb-caret { height: 16px; width: 16px; color: #94a3b8; transition: transform .2s; flex-shrink: 0; }
    .jh-tb-user.open .jh-tb-caret { transform: rotate(180deg); }

    .jh-tb-menu {
        position: absolute; right: 0; top: 100%; margin-top: 8px;
        width: 208px; border: 1px solid #e2e8f0; border-radius: 8px;
        background: #fff; padding: 4px 0; z-index: 20; display: none;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .1), 0 4px 6px -4px rgba(0, 0, 0, .1);
    }
    .jh-tb-user.open .jh-tb-menu { display: block; }
    .jh-tb-menu__head { border-bottom: 1px solid #f1f5f9; padding: 8px 12px; }
    .jh-tb-menu__name {
        margin: 0; font-size: 14px; font-weight: 500; color: #1e293b;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .jh-tb-menu__role { margin: 0; font-size: 12px; color: #64748b; }
    .jh-tb-logout-form { margin: 0; }
    .jh-tb-menu__item {
        display: flex; width: 100%; align-items: center; gap: 8px; box-sizing: border-box;
        padding: 8px 12px; font-size: 14px; color: #475569; text-decoration: none;
        background: transparent; border: 0; cursor: pointer; text-align: left;
    }
    .jh-tb-menu__item:hover { background: #f8fafc; }
    .jh-tb-menu__item svg { height: 16px; width: 16px; }
</style>

<script>
    (function () {
        var user = document.getElementById('jhTbUser');
        var btn = document.getElementById('jhTbUserBtn');
        if (user && btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                user.classList.toggle('open');
            });
            document.addEventListener('click', function (e) {
                if (!user.contains(e.target)) user.classList.remove('open');
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') user.classList.remove('open');
            });
        }

        // Ctrl/Cmd + K → focus ô tìm kiếm.
        var search = document.getElementById('jhTbSearch');
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                if (search) search.focus();
            }
        });
    })();
</script>
