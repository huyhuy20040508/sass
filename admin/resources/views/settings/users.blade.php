@extends('layouts.app')

@section('title', \App\Http\Controllers\UserController::TITLE)

@section('content')
    {{--
        Trang "Người dùng & vai trò" — dựng theo đúng khuôn trang Nhà cung cấp /
        Thương hiệu: [ header ] + [ thanh lọc realtime ] + [ bảng compact ] +
        [ chân trang ] + [ modal CRUD ], thêm bảng vai trò ở cuối.

        Đây là tài khoản NỘI BỘ (quản trị + nhân viên). Khách hàng nằm ở trang riêng.

        Mọi chốt chặn thật nằm ở API. Trang này chỉ nói TRƯỚC lý do để đỡ mất một
        vòng gọi: hàng của chính bạn không có nút khoá/xoá kèm giải thích tại chỗ,
        và quản trị viên thường thấy rõ vì sao không đụng được vào super admin.
    --}}
    @php
        $STATUSES = \App\Http\Controllers\UserController::STATUSES;
        $SORTS = \App\Http\Controllers\UserController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\UserController::PAGE_SIZES;
        $TITLE = \App\Http\Controllers\UserController::TITLE;
        $EMPTY_TEXT = \App\Http\Controllers\UserController::EMPTY_TEXT;
        $SUPER_ADMIN = \App\Http\Controllers\UserController::SUPER_ADMIN_ROLE_ID;

        // Vai trò gán được cho tài khoản nội bộ (API đánh dấu sẵn internal = true).
        $assignable = collect($roles)->filter(fn ($r) => !empty($r['internal']))->values()->all();

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['role_id'] !== 0
            || $filters['status'] !== 'all'
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        $dt = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('d/m/Y H:i') : '';
    @endphp

    <div class="usr">
        {{-- Header --}}
        <div class="usr-head">
            <h1 class="usr-title">{{ $TITLE }}</h1>
            <span class="usr-sum">
                Đang hoạt động: <b>{{ number_format($stats['active'], 0, ',', '.') }}</b>/{{ number_format($stats['total'], 0, ',', '.') }} tài khoản nội bộ
                <em>(khách hàng không tính ở đây)</em>
            </span>
        </div>

        {{-- Bộ lọc: đổi select là chạy ngay, gõ tìm kiếm thì chờ 400ms — không có nút "Áp dụng" --}}
        <form method="GET" action="{{ route('admin.users.index') }}" id="usrFilter" class="usr-filter">
            <div class="usr-toolbar">
                <div class="usr-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="usr-search-input"
                           placeholder="Tìm theo họ tên, email hoặc số điện thoại" autocomplete="off">
                    <button type="submit" class="usr-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="role_id" class="usr-select" title="Lọc theo vai trò">
                    <option value="0" {{ $filters['role_id'] === 0 ? 'selected' : '' }}>Tất cả vai trò</option>
                    @foreach($assignable as $r)
                        <option value="{{ $r['id'] }}" {{ $filters['role_id'] === (int) $r['id'] ? 'selected' : '' }}>
                            {{ $r['display_name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="status" class="usr-select" title="Lọc theo trạng thái">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ number_format($stats[$value] ?? 0, 0, ',', '.') }})
                        </option>
                    @endforeach
                </select>

                @include('partials.date-range', [
                    'formId' => 'usrFilter',
                    'from' => $filters['from_date'],
                    'to' => $filters['to_date'],
                    'title' => 'Lọc theo ngày tạo tài khoản',
                ])

                <select name="sort" class="usr-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.users.index') }}" class="usr-clear">Xoá lọc</a>
                @endif

                <div class="usr-toolbar-actions">
                    <button type="button" class="usr-btn-primary" id="usrAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm người dùng
                    </button>
                </div>
            </div>

            {{-- Giữ số dòng/trang khi đổi bộ lọc --}}
            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng tài khoản --}}
        <div class="usr-table-wrap">
            <table class="usr-table">
                <thead>
                    <tr>
                        <th class="usr-c-check"><input type="checkbox" id="usrCheckAll" class="usr-check" title="Chọn tất cả trong trang"></th>
                        <th class="usr-c-stt">STT</th>
                        <th class="usr-c-name">Người dùng</th>
                        <th class="usr-c-phone">Điện thoại</th>
                        <th class="usr-c-role">Vai trò</th>
                        <th class="usr-c-status">Trạng thái</th>
                        <th class="usr-c-login">Đăng nhập gần nhất</th>
                        <th class="usr-c-date">Ngày tạo</th>
                        <th class="usr-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $i => $u)
                        @php
                            $id = (int) ($u['id'] ?? 0);
                            $name = $u['full_name'] ?? '';
                            $isMe = $id === $me['id'];
                            $isActive = ($u['status'] ?? '') === 'active';
                            $isSuper = (int) ($u['role_id'] ?? 0) === $SUPER_ADMIN;
                            // Quản trị viên thường không đụng được vào super admin — API chặn,
                            // ở đây chỉ để nói trước lý do thay vì bấm rồi mới nhận lỗi.
                            $locked = $isSuper && $me['role'] !== 'super_admin';
                        @endphp
                        <tr data-id="{{ $id }}" class="{{ $isMe ? 'is-me' : '' }}">
                            <td class="usr-c-check">
                                <input type="checkbox" class="usr-check usr-row-check" value="{{ $id }}"
                                       aria-label="Chọn tài khoản {{ $name !== '' ? $name : $id }}">
                            </td>
                            <td class="usr-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="usr-c-name" data-edit="{{ $id }}" title="Bấm để sửa tài khoản">
                                <span class="usr-name">
                                    {{ $name !== '' ? $name : '—' }}
                                    @if($isMe)<span class="usr-tag-me">Bạn</span>@endif
                                </span>
                                <span class="usr-sub">{{ $u['email'] ?? '—' }}</span>
                            </td>
                            <td class="usr-c-phone">
                                @if(!empty($u['phone']))
                                    <a class="usr-phone" href="tel:{{ preg_replace('/\s+/', '', $u['phone']) }}"
                                       title="Gọi {{ $u['phone'] }}">{{ $u['phone'] }}</a>
                                @else
                                    <span class="usr-muted">—</span>
                                @endif
                            </td>
                            <td class="usr-c-role">
                                <span class="usr-role {{ $isSuper ? 'is-super' : '' }}">
                                    {{ $u['role_display_name'] ?? ($u['role_name'] ?? '—') }}
                                </span>
                            </td>
                            <td class="usr-c-status">
                                <span class="usr-pill {{ $isActive ? 'on' : 'off' }}">
                                    {{ $isActive ? $STATUSES['active'] : $STATUSES['inactive'] }}
                                </span>
                            </td>
                            <td class="usr-c-login">
                                {{ $dt($u['last_login_at'] ?? '') ?: '—' }}
                            </td>
                            <td class="usr-c-date">
                                {{ $dt($u['created_at'] ?? '') ?: '—' }}
                            </td>
                            <td class="usr-c-act">
                                <div class="usr-rowacts">
                                    <button type="button" class="usr-rowbtn usr-edit" data-edit="{{ $id }}" title="Sửa tài khoản">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="usr-rowbtn usr-key" data-password="{{ $id }}" title="Đặt lại mật khẩu">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M17 6l2 2M14 9l2 2"/></svg>
                                    </button>
                                    {{-- Nút khoá & xoá luôn bấm được: trường hợp không làm được
                                         thì nói rõ lý do, không để nút câm. --}}
                                    <button type="button" class="usr-rowbtn usr-lock" data-toggle="{{ $id }}"
                                            title="{{ $isActive ? 'Khoá tài khoản' : 'Mở khoá tài khoản' }}">
                                        @if($isActive)
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                                        @else
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2"/></svg>
                                        @endif
                                    </button>
                                    <button type="button" class="usr-rowbtn usr-del" data-remove="{{ $id }}" title="Xoá tài khoản">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="usr-empty">
                                @if($hasFilter)
                                    Không tìm thấy tài khoản nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    {{ $EMPTY_TEXT }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'tài khoản',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])

        {{-- Bảng vai trò — 4 dòng cố định, để ngay dưới thay vì tách thành trang riêng. --}}
        <div class="usr-roles">
            <div class="usr-roles-head">
                <h3 class="usr-roles-title">Vai trò</h3>
                <p class="usr-roles-desc">
                    Bốn vai trò cố định của hệ thống. Sửa được tên hiển thị và mô tả;
                    mã vai trò thì không, vì đó là thứ hệ thống dựa vào để phân quyền.
                    Phân quyền theo từng chức năng chưa làm — hiện <b>Super Admin</b> và
                    <b>Quản trị viên</b> vào được mọi trang, <b>Nhân viên</b> không vào được
                    Cài đặt và Khách hàng.
                </p>
            </div>

            <div class="usr-table-wrap">
                <table class="usr-table">
                    <thead>
                        <tr>
                            <th class="usr-c-rname">Vai trò</th>
                            <th class="usr-c-rcode">Mã</th>
                            <th class="usr-c-rdesc">Mô tả</th>
                            <th class="usr-c-rcount">Tài khoản</th>
                            <th class="usr-c-act">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($roles as $r)
                            <tr>
                                <td class="usr-c-rname">
                                    <span class="usr-name">{{ $r['display_name'] ?? '—' }}</span>
                                    @if(empty($r['internal']))
                                        <span class="usr-sub">Vai trò của khách hàng — không gán ở trang này</span>
                                    @endif
                                </td>
                                <td class="usr-c-rcode"><span class="usr-code">{{ $r['name'] ?? '—' }}</span></td>
                                <td class="usr-c-rdesc">{{ $r['description'] ?: '—' }}</td>
                                <td class="usr-c-rcount">
                                    @if(empty($r['internal']))
                                        <a class="usr-count" href="{{ route('admin.customers.index') }}"
                                           title="Xem danh sách khách hàng">{{ number_format((int) ($r['user_count'] ?? 0), 0, ',', '.') }}</a>
                                    @else
                                        <a class="usr-count" href="{{ route('admin.users.index', ['role_id' => $r['id']]) }}"
                                           title="Lọc danh sách theo vai trò này">{{ number_format((int) ($r['user_count'] ?? 0), 0, ',', '.') }}</a>
                                    @endif
                                </td>
                                <td class="usr-c-act">
                                    <button type="button" class="usr-rowbtn usr-edit" data-role="{{ $r['id'] }}" title="Sửa vai trò">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="usrBulkMount"></div>

    {{-- Modal Thêm / Sửa người dùng --}}
    <div class="usr-overlay" id="usrFormOverlay" style="display:none;">
        <div class="usr-dialog">
            <div class="usr-modal-head">
                <h4 class="usr-modal-title" id="usrFormTitle">Thêm người dùng</h4>
                <button type="button" class="usr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="usrForm" method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <input type="hidden" name="_method" id="usrFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="usr-modal-body">
                    <div class="usr-grid2">
                        <div>
                            <label class="usr-field-label" for="usrName">Họ và tên <span class="usr-req">*</span></label>
                            <input type="text" id="usrName" name="full_name" class="usr-input" maxlength="150" required
                                   autocomplete="off" placeholder="VD: Nguyễn Văn A">
                        </div>
                        <div>
                            <label class="usr-field-label" for="usrEmail">Email đăng nhập <span class="usr-req">*</span></label>
                            <input type="email" id="usrEmail" name="email" class="usr-input" maxlength="191" required
                                   autocomplete="off" placeholder="ten@cuahang.vn">
                            <p class="usr-hint">Đây cũng là tên đăng nhập trang quản trị.</p>
                        </div>

                        <div>
                            <label class="usr-field-label" for="usrPhone">Điện thoại</label>
                            <input type="text" id="usrPhone" name="phone" class="usr-input" maxlength="20"
                                   autocomplete="off" placeholder="09xxxxxxxx">
                        </div>
                        <div>
                            <label class="usr-field-label" for="usrRole">Vai trò <span class="usr-req">*</span></label>
                            <select id="usrRole" name="role_id" class="usr-msel" required>
                                @foreach($assignable as $r)
                                    <option value="{{ $r['id'] }}"
                                            {{ (int) $r['id'] === $SUPER_ADMIN && $me['role'] !== 'super_admin' ? 'disabled' : '' }}>
                                        {{ $r['display_name'] }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="usr-hint" id="usrRoleHint"></p>
                        </div>

                        <div>
                            <label class="usr-field-label" for="usrStatus">Trạng thái <span class="usr-req">*</span></label>
                            <select id="usrStatus" name="status" class="usr-msel" required>
                                <option value="active">{{ $STATUSES['active'] }}</option>
                                <option value="inactive">{{ $STATUSES['inactive'] }}</option>
                            </select>
                            <p class="usr-hint">Tài khoản bị khoá không đăng nhập được, dữ liệu vẫn giữ nguyên.</p>
                        </div>
                        <div id="usrPasswordBox">
                            <label class="usr-field-label" for="usrPassword">Mật khẩu</label>
                            <input type="text" id="usrPassword" name="password" class="usr-input" maxlength="72"
                                   autocomplete="off" placeholder="Bỏ trống để dùng mật khẩu mặc định">
                            <p class="usr-hint">Bỏ trống thì hệ thống cấp <b>Nhanvien@123</b>; nhắc người dùng đổi sau lần đăng nhập đầu.</p>
                        </div>
                    </div>

                    <p class="usr-note" id="usrFormNote" style="display:none;"></p>
                </div>

                <div class="usr-modal-foot">
                    <button type="button" class="usr-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="usr-btn-primary" id="usrFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Đặt lại mật khẩu --}}
    <div class="usr-overlay" id="usrPwdOverlay" style="display:none;">
        <div class="usr-dialog usr-dialog-sm">
            <div class="usr-modal-head">
                <h4 class="usr-modal-title">Đặt lại mật khẩu</h4>
                <button type="button" class="usr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="usrPwdForm" method="POST">
                @csrf
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="usr-modal-body">
                    <p class="usr-note" id="usrPwdWho"></p>
                    <div>
                        <label class="usr-field-label" for="usrPwdInput">Mật khẩu mới <span class="usr-req">*</span></label>
                        <input type="text" id="usrPwdInput" name="password" class="usr-input" minlength="6" maxlength="72"
                               required autocomplete="off" placeholder="Tối thiểu 6 ký tự">
                        <p class="usr-hint">
                            Hiện dạng chữ thường để bạn chép lại chuyển cho người dùng. Hệ thống không
                            gửi mật khẩu này đi đâu — tự chuyển qua kênh riêng.
                        </p>
                    </div>
                </div>

                <div class="usr-modal-foot">
                    <button type="button" class="usr-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="usr-btn-primary">Đặt lại</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Sửa vai trò --}}
    <div class="usr-overlay" id="usrRoleOverlay" style="display:none;">
        <div class="usr-dialog usr-dialog-sm">
            <div class="usr-modal-head">
                <h4 class="usr-modal-title">Sửa vai trò</h4>
                <button type="button" class="usr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="usrRoleForm" method="POST">
                @csrf
                <input type="hidden" name="_method" value="PUT">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="usr-modal-body">
                    <div>
                        <label class="usr-field-label" for="usrRoleCode">Mã vai trò</label>
                        <input type="text" id="usrRoleCode" class="usr-input" disabled>
                        <p class="usr-hint">Không sửa được: đây là mã hệ thống dùng để kiểm tra quyền ở mọi nơi.</p>
                    </div>
                    <div>
                        <label class="usr-field-label" for="usrRoleName">Tên hiển thị <span class="usr-req">*</span></label>
                        <input type="text" id="usrRoleName" name="display_name" class="usr-input" maxlength="100" required autocomplete="off">
                    </div>
                    <div>
                        <label class="usr-field-label" for="usrRoleDesc">Mô tả</label>
                        <input type="text" id="usrRoleDesc" name="description" class="usr-input" maxlength="255" autocomplete="off"
                               placeholder="Vai trò này phụ trách việc gì">
                    </div>
                </div>

                <div class="usr-modal-foot">
                    <button type="button" class="usr-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="usr-btn-primary">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .usr {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .usr-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .usr-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .usr-sum { font-size: 13px; color: #595959; }
        .usr-sum b { color: #262626; }
        .usr-sum em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Bộ lọc */
        .usr-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .usr-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .usr-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .usr-searchbox { display: flex; border-radius: 4px; }
        .usr-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .usr-search-input {
            height: 34px; width: 300px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .usr-search-input::placeholder { color: #bfbfbf; }
        .usr-searchbox:focus-within .usr-search-input,
        .usr-searchbox:focus-within .usr-search-btn { border-color: #86b7fe; }
        .usr-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .usr-search-btn:hover { color: #1890ff; }

        .usr-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .usr-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        .usr-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .usr-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Nút */
        .usr-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .usr-btn-primary:hover { background: #40a9ff; }
        .usr-btn-primary svg { flex-shrink: 0; }
        .usr-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px;
            font-weight: 500; color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .usr-btn-ghost:hover { border-color: #bfbfbf; }

        /* Bảng — ô rộng dòng cao như các trang danh sách khác; th và td của cùng một
           cột khai CÙNG text-align để tiêu đề luôn thẳng cột với dữ liệu. */
        .usr-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .usr-table-wrap::-webkit-scrollbar { height: 11px; }
        .usr-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        .usr-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .usr-table thead th {
            text-align: left; padding: 13px 18px; border-bottom: 1px solid #f0f0f0; background: #fafafa;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
        }
        .usr-table tbody td {
            padding: 16px 18px; border-bottom: 1px solid #f5f5f5; vertical-align: middle;
            white-space: nowrap; line-height: 1.5;
        }
        .usr-table tbody tr:hover { background: #fafcff; }
        .usr-table tbody tr.is-selected, .usr-table tbody tr.is-selected:hover { background: #e6f7ff; }

        /* Mọi cột co vừa nội dung, riêng "Người dùng" hút hết khoảng dư. */
        .usr-table th.usr-c-check,  .usr-table td.usr-c-check  { width: 1%; text-align: center; }
        .usr-table th.usr-c-stt,    .usr-table td.usr-c-stt    { width: 1%; text-align: center; color: #8c8c8c; }
        .usr-table th.usr-c-name,   .usr-table td.usr-c-name   { width: 100%; max-width: 0; min-width: 220px; overflow: hidden; }
        .usr-table th.usr-c-phone,  .usr-table td.usr-c-phone  { width: 1%; }
        .usr-table th.usr-c-role,   .usr-table td.usr-c-role   { width: 1%; text-align: center; }
        .usr-table th.usr-c-status, .usr-table td.usr-c-status { width: 1%; text-align: center; }
        .usr-table th.usr-c-login,  .usr-table td.usr-c-login  { width: 1%; text-align: center; color: #595959; }
        .usr-table th.usr-c-date,   .usr-table td.usr-c-date   { width: 1%; text-align: center; color: #595959; }
        .usr-table th.usr-c-act,    .usr-table td.usr-c-act    { width: 1%; text-align: center; }

        /* Bảng vai trò — cột Mô tả hút khoảng dư. */
        .usr-table th.usr-c-rname,  .usr-table td.usr-c-rname  { width: 1%; }
        .usr-table th.usr-c-rcode,  .usr-table td.usr-c-rcode  { width: 1%; }
        .usr-table th.usr-c-rdesc,  .usr-table td.usr-c-rdesc  { width: 100%; max-width: 0; min-width: 220px; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .usr-table th.usr-c-rcount, .usr-table td.usr-c-rcount { width: 1%; text-align: center; }

        .usr-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        .usr-muted { color: #bfbfbf; }
        .usr-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; }
        .usr-sub { display: block; margin-top: 3px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .usr-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #595959; }
        .usr-phone { color: #262626; text-decoration: none; }
        .usr-phone:hover { color: #1890ff; text-decoration: underline; }

        .usr-tag-me {
            margin-left: 6px; padding: 1px 6px; border-radius: 4px;
            background: #f0f5ff; color: #2f54eb; font-size: 11px; font-weight: 500;
        }
        .usr-role {
            display: inline-block; padding: 2px 10px; border-radius: 9999px;
            background: #f5f5f5; color: #595959; font-size: 12px; font-weight: 500;
        }
        .usr-role.is-super { background: #fff7e6; color: #d46b08; }
        .usr-pill {
            display: inline-block; padding: 2px 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 500;
        }
        .usr-pill.on { background: #f6ffed; color: #389e0d; }
        .usr-pill.off { background: #fff1f0; color: #cf1322; }

        .usr-count {
            display: inline-block; min-width: 34px; padding: 2px 8px; border-radius: 9999px;
            background: #f0f5ff; color: #1890ff; font-weight: 600; text-decoration: none;
            font-variant-numeric: tabular-nums; transition: background .15s;
        }
        .usr-count:hover { background: #d6e8ff; }

        /* Tên bấm được để sửa (giống trang Nhà cung cấp / Thương hiệu) */
        .usr-c-name[data-edit] { cursor: pointer; }
        .usr-c-name[data-edit]:hover .usr-name { color: #1890ff; text-decoration: underline; }

        .usr-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .usr-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .usr-rowbtn.usr-edit { color: #1890ff; }
        .usr-rowbtn.usr-edit:hover { background: #e6f7ff; }
        .usr-rowbtn.usr-key { color: #8c8c8c; }
        .usr-rowbtn.usr-key:hover { background: #f5f5f5; color: #262626; }
        .usr-rowbtn.usr-lock { color: #fa8c16; }
        .usr-rowbtn.usr-lock:hover { background: #fff7e6; }
        .usr-rowbtn.usr-del { color: #ff4d4f; }
        .usr-rowbtn.usr-del:hover { background: #fff1f0; }

        /* Dòng trống trải hết bảng nên phải cho xuống dòng, không nowrap như ô dữ liệu. */
        .usr-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; white-space: normal; }

        /* Khối vai trò */
        .usr-roles { margin-top: 8px; border-top: 1px solid #eee; padding-top: 20px; padding-bottom: 32px; }
        .usr-roles-head { padding: 0 20px 12px; }
        .usr-roles-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .usr-roles-desc { margin: 4px 0 0; max-width: 900px; font-size: 12px; line-height: 1.6; color: #8c8c8c; }
        .usr-roles-desc b { color: #595959; }

        .usr-btn-primary:focus-visible, .usr-btn-ghost:focus-visible,
        .usr-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Thanh thao tác hàng loạt (pill nổi — đồng bộ các trang danh sách khác) */
        .usr-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 14px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .usr-bulk { left: 48px; }
        @media (max-width: 820px) { .usr-bulk { left: 0; } }
        .usr-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .usr-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .usr-bulk-clear:hover { color: #262626; }
        .usr-bulk-btn {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; border: 1px solid #d9d9d9;
            border-radius: 9999px; background: #fff; padding: 0 14px; font-size: 13px; font-weight: 500;
            color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .usr-bulk-btn:hover { border-color: #1890ff; color: #1890ff; }
        .usr-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; border: 0; border-radius: 9999px;
            background: #ff4d4f; padding: 0 16px; font-size: 13px; font-weight: 500; color: #fff;
            cursor: pointer; transition: background .15s;
        }
        .usr-bulk-del:hover { background: #ff7875; }

        /* ---- Modal ---- */
        .usr-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .usr-dialog {
            max-height: 92vh; width: 100%; max-width: 720px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2); scrollbar-width: thin;
        }
        .usr-dialog-sm { max-width: 460px; }
        .usr-dialog::-webkit-scrollbar { width: 11px; }
        .usr-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .usr-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .usr-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .usr-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .usr-modal-x:hover { color: #262626; }
        .usr-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .usr-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }

        .usr-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .usr-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .usr-req { color: #ff4d4f; }
        .usr-input, .usr-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            outline: none; transition: border-color .15s; font-family: inherit; color: #262626; background: #fff;
        }
        .usr-input { height: 36px; }
        .usr-input::placeholder { color: #bfbfbf; }
        .usr-input:disabled { background: #f5f5f5; color: #8c8c8c; cursor: not-allowed; }
        .usr-input:focus, .usr-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .usr-msel {
            height: 36px; cursor: pointer; padding-right: 32px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .usr-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; line-height: 1.5; }
        .usr-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px; background: #f5f9ff;
            font-size: 12px; line-height: 1.55; color: #595959;
        }

        @media (max-width: 560px) {
            .usr-grid2 { grid-template-columns: 1fr; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());
            const URL_BASE = @json(url('admin/users'));
            const URL_STORE = @json(route('admin.users.store'));
            const URL_ROLES = @json(url('admin/roles'));
            const URL_BULK_DEL = @json(route('admin.users.bulkDestroy'));
            const URL_BULK_STATUS = @json(route('admin.users.bulkStatus'));
            const RETURN_URL = @json(request()->getRequestUri());
            const USERS = @json($users);
            const ROLES = @json($roles);
            const ME = @json($me);
            const SUPER_ADMIN_ROLE_ID = @json(\App\Http\Controllers\UserController::SUPER_ADMIN_ROLE_ID);

            const BY_ID = new Map(USERS.map((u) => [Number(u.id), u]));
            const ROLE_BY_ID = new Map(ROLES.map((r) => [Number(r.id), r]));
            const iAmSuper = ME.role === 'super_admin';

            const $filter = document.getElementById('usrFilter');
            const $bulkMount = document.getElementById('usrBulkMount');

            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            // ---------- Bộ lọc: đổi select -> chạy ngay; gõ tìm kiếm -> chờ 400ms ----------
            $filter.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => $filter.submit());
            });
            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // ---------- Form POST động cho mọi thao tác ghi (kèm CSRF) ----------
            function postForm(action, method, fields) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = action;
                f.style.display = 'none';
                const add = (name, val) => {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = name;
                    i.value = val == null ? '' : val;
                    f.appendChild(i);
                };
                add('_token', CSRF);
                if (method && method !== 'POST') add('_method', method);
                add('return', RETURN_URL);
                for (const [k, v] of Object.entries(fields)) add(k, v);
                document.body.appendChild(f);
                f.submit();
            }

            // Toast lỗi phía client (dùng chung hạ tầng toast của layout).
            function toastErr(msg) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') { alert(msg); return; }
                const el = document.createElement('div');
                el.className = 'toast align-items-center text-bg-danger border-0';
                el.setAttribute('role', 'alert');
                el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-exclamation-circle-fill me-2"></i>${esc(msg)}</div>`
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: 7000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            /**
             * Lý do KHÔNG làm được một thao tác lên tài khoản, hoặc chuỗi rỗng nếu làm được.
             *
             * Đây chỉ là bản sao ở giao diện của chốt chặn bên API, để nói ngay tại chỗ
             * thay vì bấm rồi mới nhận lỗi. Server vẫn kiểm lại đầy đủ.
             */
            function blockedReason(u, action) {
                const id = Number(u.id);
                const isSuper = Number(u.role_id) === SUPER_ADMIN_ROLE_ID;

                if (isSuper && !iAmSuper) {
                    return 'Chỉ Super Admin mới thao tác được lên tài khoản Super Admin. '
                        + 'Nhờ một Super Admin thực hiện giúp.';
                }
                if (id === ME.id && action !== 'password') {
                    return action === 'delete'
                        ? 'Không xoá được tài khoản bạn đang đăng nhập. Nhờ người quản trị khác xoá giúp nếu bạn thực sự muốn rời hệ thống.'
                        : 'Không tự khoá tài khoản bạn đang đăng nhập — khoá xong chính bạn không đăng nhập lại được.';
                }
                return '';
            }

            // ---------- Modal thêm / sửa người dùng ----------
            const $overlay = document.getElementById('usrFormOverlay');
            const form = document.getElementById('usrForm');
            const formNote = document.getElementById('usrFormNote');
            const roleHint = document.getElementById('usrRoleHint');
            const passwordBox = document.getElementById('usrPasswordBox');
            const fields = {
                full_name: document.getElementById('usrName'),
                email: document.getElementById('usrEmail'),
                phone: document.getElementById('usrPhone'),
                role_id: document.getElementById('usrRole'),
                status: document.getElementById('usrStatus'),
                password: document.getElementById('usrPassword'),
            };

            function closeOverlays() {
                document.querySelectorAll('.usr-overlay').forEach((o) => { o.style.display = 'none'; });
            }
            document.querySelectorAll('.usr-overlay').forEach((o) => {
                o.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeOverlays));
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlays(); });

            function openForm(mode, u) {
                const isEdit = mode === 'edit';
                if (isEdit) {
                    const why = blockedReason(u, 'edit');
                    if (why) { toastErr(why); return; }
                }

                form.action = isEdit ? `${URL_BASE}/${u.id}` : URL_STORE;
                document.getElementById('usrFormMethod').value = isEdit ? 'PUT' : 'POST';
                document.getElementById('usrFormTitle').textContent = isEdit ? 'Sửa người dùng' : 'Thêm người dùng';
                document.getElementById('usrFormSubmit').textContent = isEdit ? 'Cập nhật' : 'Lưu';

                const d = isEdit ? u : { status: 'active' };
                fields.full_name.value = d.full_name || '';
                fields.email.value = d.email || '';
                fields.phone.value = d.phone || '';
                fields.status.value = d.status || 'active';
                fields.role_id.value = String(d.role_id || (ROLES.find((r) => r.internal && r.id !== SUPER_ADMIN_ROLE_ID)?.id ?? ''));

                // Mật khẩu chỉ đặt lúc tạo — sửa thì dùng nút chìa khoá riêng, có xác nhận riêng.
                passwordBox.style.display = isEdit ? 'none' : '';
                fields.password.value = '';
                fields.password.disabled = isEdit;

                // Sửa CHÍNH MÌNH: vai trò và trạng thái khoá lại, kèm lý do ngay dưới ô.
                const editingSelf = isEdit && Number(u.id) === ME.id;
                fields.role_id.disabled = editingSelf;
                fields.status.disabled = editingSelf;
                roleHint.textContent = editingSelf
                    ? 'Không tự đổi vai trò hoặc trạng thái của chính mình — đổi xong bạn sẽ mất quyền mà không có đường quay lại.'
                    : '';

                formNote.style.display = editingSelf ? '' : 'none';
                if (editingSelf) {
                    formNote.textContent = 'Đây là tài khoản bạn đang đăng nhập. Sửa được họ tên, email và số điện thoại; '
                        + 'vai trò và trạng thái phải nhờ người quản trị khác đổi giúp.';
                }

                $overlay.style.display = 'flex';
                setTimeout(() => fields.full_name.focus(), 30);
            }

            // Ô bị disable không được gửi lên -> gắn lại giá trị vào input ẩn trước khi submit,
            // nếu không server nhận thiếu role_id/status và báo lỗi bắt buộc.
            form.addEventListener('submit', () => {
                ['role_id', 'status'].forEach((key) => {
                    const el = fields[key];
                    if (!el.disabled) return;
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = key;
                    hidden.value = el.value;
                    form.appendChild(hidden);
                });
            });

            document.getElementById('usrAddBtn').addEventListener('click', () => openForm('add', null));

            // ---------- Modal đặt lại mật khẩu ----------
            const $pwdOverlay = document.getElementById('usrPwdOverlay');
            const pwdForm = document.getElementById('usrPwdForm');

            function openPassword(u) {
                const why = blockedReason(u, 'password');
                if (why) { toastErr(why); return; }

                pwdForm.action = `${URL_BASE}/${u.id}/password`;
                document.getElementById('usrPwdWho').textContent =
                    `Đặt mật khẩu mới cho ${u.full_name || ''} (${u.email || ''}).`
                    + (Number(u.id) === ME.id ? ' Đây là tài khoản của chính bạn — đổi xong hãy đăng nhập lại bằng mật khẩu mới.' : '');
                document.getElementById('usrPwdInput').value = '';
                $pwdOverlay.style.display = 'flex';
                setTimeout(() => document.getElementById('usrPwdInput').focus(), 30);
            }

            // ---------- Khoá / mở khoá & xoá ----------
            function toggleStatus(u) {
                const why = blockedReason(u, 'status');
                if (why) { toastErr(why); return; }

                const turningOff = u.status === 'active';
                sysConfirm({
                    title: turningOff ? 'Khoá tài khoản' : 'Mở khoá tài khoản',
                    message: turningOff
                        ? `Khoá tài khoản "${u.full_name}"? Người này sẽ không đăng nhập được trang quản trị nữa, dữ liệu vẫn giữ nguyên.`
                        : `Mở khoá tài khoản "${u.full_name}"? Người này đăng nhập lại được ngay.`,
                    highlightText: `${u.full_name || ''} — ${u.email || ''}`,
                    confirmText: turningOff ? 'Khoá lại' : 'Mở khoá',
                }).then((ok) => {
                    if (ok) postForm(`${URL_BASE}/${u.id}/status`, 'PUT', { status: turningOff ? 'inactive' : 'active' });
                });
            }

            function removeUser(u) {
                const why = blockedReason(u, 'delete');
                if (why) { toastErr(why); return; }

                sysDelete({
                    title: 'Xác nhận xoá tài khoản',
                    message: `Bạn có chắc chắn muốn xoá tài khoản "${u.full_name}"? Email này sẽ không dùng lại được `
                        + 'cho tài khoản mới. Nếu chỉ muốn chặn đăng nhập thì dùng "Khoá tài khoản".',
                    highlightText: `${u.full_name || ''} — ${u.email || ''}`,
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${u.id}`, 'DELETE', {}); });
            }

            // ---------- Modal sửa vai trò ----------
            const $roleOverlay = document.getElementById('usrRoleOverlay');
            const roleForm = document.getElementById('usrRoleForm');

            function openRole(r) {
                roleForm.action = `${URL_ROLES}/${r.id}`;
                document.getElementById('usrRoleCode').value = r.name || '';
                document.getElementById('usrRoleName').value = r.display_name || '';
                document.getElementById('usrRoleDesc').value = r.description || '';
                $roleOverlay.style.display = 'flex';
                setTimeout(() => document.getElementById('usrRoleName').focus(), 30);
            }

            // ---------- Sự kiện bảng ----------
            document.querySelectorAll('.usr-table tbody').forEach((tbody) => {
                tbody.addEventListener('click', (e) => {
                    if (e.target.closest('a')) return; // số điện thoại / số tài khoản là link

                    const role = e.target.closest('[data-role]');
                    if (role) { const r = ROLE_BY_ID.get(Number(role.getAttribute('data-role'))); if (r) openRole(r); return; }

                    const pwd = e.target.closest('[data-password]');
                    if (pwd) { const u = BY_ID.get(Number(pwd.getAttribute('data-password'))); if (u) openPassword(u); return; }
                    const tg = e.target.closest('[data-toggle]');
                    if (tg) { const u = BY_ID.get(Number(tg.getAttribute('data-toggle'))); if (u) toggleStatus(u); return; }
                    const rm = e.target.closest('[data-remove]');
                    if (rm) { const u = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (u) removeUser(u); return; }
                    const ed = e.target.closest('[data-edit]');
                    if (ed) { const u = BY_ID.get(Number(ed.getAttribute('data-edit'))); if (u) openForm('edit', u); return; }
                });
            });

            // ---------- Chọn dòng + thanh thao tác hàng loạt ----------
            const selected = new Set();
            const checkAll = document.getElementById('usrCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.usr-row-check'));

            function syncRow(cb) {
                const tr = cb.closest('tr');
                if (cb.checked) { selected.add(Number(cb.value)); tr.classList.add('is-selected'); }
                else { selected.delete(Number(cb.value)); tr.classList.remove('is-selected'); }
            }
            function syncHeader() {
                const all = rowChecks();
                const on = all.filter((c) => c.checked).length;
                checkAll.checked = on > 0 && on === all.length;
                checkAll.indeterminate = on > 0 && on < all.length;
            }

            // Tài khoản đang chọn mà server chắc chắn từ chối (chính mình, super admin
            // ngoài tầm) được đếm riêng và nói trước, thay vì để người dùng bấm rồi
            // nhận về một thông báo "bỏ qua N cái" khó hiểu.
            function splitSelection(action) {
                const ids = [...selected];
                const ok = [];
                let blocked = 0;
                ids.forEach((id) => {
                    const u = BY_ID.get(id);
                    if (!u) return;
                    if (blockedReason(u, action)) blocked++;
                    else ok.push(id);
                });
                return { ok, blocked, total: ids.length };
            }

            function renderBulk() {
                const n = selected.size;
                if (n === 0) { $bulkMount.innerHTML = ''; return; }

                const del = splitSelection('delete');
                const ids = [...selected];
                const activeCount = ids.filter((id) => BY_ID.get(id)?.status === 'active').length;

                $bulkMount.innerHTML = `
                    <div class="usr-bulk">
                        <span class="usr-bulk-text">Đã chọn <b>${n}</b> tài khoản${del.blocked ? ` · ${del.blocked} tài khoản bạn không thao tác được` : ''}</span>
                        <button type="button" class="usr-bulk-clear" id="usrBulkClear">Bỏ chọn</button>
                        ${activeCount > 0 ? `
                        <button type="button" class="usr-bulk-btn" id="usrBulkOff">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                            Khoá (${activeCount})
                        </button>` : `
                        <button type="button" class="usr-bulk-btn" id="usrBulkOn">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 7.5-2"/></svg>
                            Mở khoá (${n})
                        </button>`}
                        <button type="button" class="usr-bulk-del" id="usrBulkDel">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Xoá (${del.ok.length})
                        </button>
                    </div>`;
            }

            rowChecks().forEach((cb) => cb.addEventListener('change', () => { syncRow(cb); syncHeader(); renderBulk(); }));
            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((cb) => { cb.checked = checkAll.checked; syncRow(cb); });
                    syncHeader(); renderBulk();
                });
            }

            function idFields(ids) {
                const out = {};
                ids.forEach((id, i) => { out[`ids[${i}]`] = id; });
                return out;
            }

            $bulkMount.addEventListener('click', (e) => {
                if (e.target.closest('#usrBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                    return;
                }

                if (e.target.closest('#usrBulkOff') || e.target.closest('#usrBulkOn')) {
                    const on = !!e.target.closest('#usrBulkOn');
                    const part = splitSelection('status');
                    if (!part.ok.length) {
                        toastErr('Không tài khoản nào trong số đã chọn thao tác được: tài khoản của chính bạn '
                            + 'và tài khoản Super Admin (nếu bạn không phải Super Admin) đều bị chặn.');
                        return;
                    }
                    sysConfirm({
                        title: on ? 'Mở khoá hàng loạt' : 'Khoá hàng loạt',
                        message: (on
                            ? `Mở khoá ${part.ok.length} tài khoản đã chọn? Những người này đăng nhập lại được ngay.`
                            : `Khoá ${part.ok.length} tài khoản đã chọn? Những người này không đăng nhập được nữa, dữ liệu vẫn giữ nguyên.`)
                            + (part.blocked ? ` ${part.blocked} tài khoản bạn không thao tác được sẽ giữ nguyên.` : ''),
                        confirmText: on ? 'Mở khoá' : 'Khoá lại',
                    }).then((ok) => {
                        if (ok) postForm(URL_BULK_STATUS, 'POST', { status: on ? 'active' : 'inactive', ...idFields(part.ok) });
                    });
                    return;
                }

                if (e.target.closest('#usrBulkDel')) {
                    const part = splitSelection('delete');
                    if (!part.ok.length) {
                        toastErr('Không tài khoản nào trong số đã chọn xoá được: tài khoản của chính bạn '
                            + 'và tài khoản Super Admin (nếu bạn không phải Super Admin) đều bị chặn.');
                        return;
                    }
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${part.ok.length} tài khoản đã chọn?`
                            + (part.blocked ? ` ${part.blocked} tài khoản bạn không thao tác được sẽ giữ nguyên.` : '')
                            + ' Email của tài khoản đã xoá không dùng lại được cho tài khoản mới.',
                        highlightText: `Số lượng: ${part.ok.length} tài khoản`,
                    }).then((ok) => { if (ok) postForm(URL_BULK_DEL, 'POST', idFields(part.ok)); });
                }
            });
        })();
    </script>
@endsection
