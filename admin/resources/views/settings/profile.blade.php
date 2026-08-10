@extends('layouts.app')

@section('title', \App\Http\Controllers\ProfileController::TITLE)

@section('content')
    {{--
        Tài khoản của tôi — hồ sơ và mật khẩu của CHÍNH người đang đăng nhập.

        Hai form tách rời trên cùng một trang: đổi tên và đổi mật khẩu là hai việc
        khác nhau, gộp chung một nút Lưu thì mỗi lần sửa số điện thoại lại phải gõ
        cả mật khẩu. Nút "Lưu thay đổi" trên header thuộc form hồ sơ (dùng thuộc
        tính form=), form mật khẩu có nút riêng ở cuối khối.

        Dùng lại đúng hệ màu, cỡ chữ và khuôn nút của các trang cấu hình.
    --}}
    @php
        $roleLabel = (string) ($profile['role_display_name'] ?? '');
        if ($roleLabel === '') {
            $roleLabel = (string) ($profile['role_name'] ?? '—');
        }

        $lastLogin = '';
        if (! empty($profile['last_login_at'])) {
            try {
                $lastLogin = \Illuminate\Support\Carbon::parse($profile['last_login_at'])->format('H:i d/m/Y');
            } catch (\Throwable $e) {
                $lastLogin = '';
            }
        }

        $name = trim((string) ($profile['full_name'] ?? ''));
        $email = (string) ($profile['email'] ?? '');
        $initials = mb_strtoupper(mb_substr($name !== '' ? $name : ($email !== '' ? $email : 'A'), 0, 2), 'UTF-8');
    @endphp

    <div class="prf">
        {{-- Header --}}
        <div class="prf-head">
            <div class="prf-head-text">
                <h1 class="prf-title">{{ \App\Http\Controllers\ProfileController::TITLE }}</h1>
                <p class="prf-sub">Tên hiển thị và mật khẩu đăng nhập của riêng bạn. Vai trò và quyền truy cập do quản trị viên đặt.</p>
            </div>

            <div class="prf-head-actions">
                <button type="button" class="prf-btn-ghost" id="prfResetBtn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                    Hoàn tác
                </button>
                <button type="submit" form="prfForm" class="prf-btn-primary" id="prfSaveBtn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    Lưu thay đổi
                </button>
            </div>
        </div>

        {{-- Danh tính — nói ngay bạn đang sửa tài khoản nào, tránh trường hợp máy
             dùng chung mà người ngồi vào tưởng đang là tài khoản của mình. --}}
        <div class="prf-identity">
            <span class="prf-avatar">{{ $initials }}</span>
            <div class="prf-identity-text">
                <p class="prf-identity-name">{{ $name !== '' ? $name : 'Chưa đặt họ tên' }}</p>
                <p class="prf-identity-meta">
                    <span>{{ $email !== '' ? $email : '—' }}</span>
                    <span class="prf-dot"></span>
                    <span>{{ $roleLabel }}</span>
                    @if($lastLogin !== '')
                        <span class="prf-dot"></span>
                        <span>Đăng nhập gần nhất {{ $lastLogin }}</span>
                    @endif
                </p>
            </div>
        </div>

        {{-- ----- Hồ sơ ----- --}}
        <form method="POST" action="{{ route('admin.profile.update') }}" id="prfForm" class="prf-form">
            @csrf
            @method('PUT')
            {{-- Ảnh đại diện chưa có ô nhập trên trang này. API ghi đè trường avatar
                 theo đúng những gì nhận được, nên phải gửi lại giá trị đang có —
                 nếu không, mỗi lần đổi tên là mất ảnh. --}}
            <input type="hidden" name="avatar" value="{{ old('avatar', $profile['avatar'] ?? '') }}">

            <h3 class="prf-section">Hồ sơ</h3>
            <div class="prf-grid">
                <div class="prf-field">
                    <label class="prf-label" for="prfFullName">
                        Họ tên <span class="prf-req" title="Bắt buộc">*</span>
                    </label>
                    <div class="prf-control">
                        <input type="text" id="prfFullName" name="full_name" maxlength="150" autocomplete="name"
                               class="prf-input {{ $errors->has('full_name') ? 'is-invalid' : '' }}"
                               value="{{ old('full_name', $profile['full_name'] ?? '') }}">
                        @error('full_name')
                            <p class="prf-error">{{ $message }}</p>
                        @else
                            <p class="prf-hint">Tên hiện trên thanh trên cùng và trong danh sách người dùng.</p>
                        @enderror
                    </div>
                </div>

                <div class="prf-field">
                    <label class="prf-label" for="prfPhone">Số điện thoại</label>
                    <div class="prf-control">
                        <input type="tel" id="prfPhone" name="phone" maxlength="20" autocomplete="tel"
                               class="prf-input {{ $errors->has('phone') ? 'is-invalid' : '' }}"
                               value="{{ old('phone', $profile['phone'] ?? '') }}">
                        @error('phone')
                            <p class="prf-error">{{ $message }}</p>
                        @else
                            <p class="prf-hint">Để đồng nghiệp liên hệ khi cần xác nhận đơn hoặc phiếu kho. Khách không thấy số này.</p>
                        @enderror
                    </div>
                </div>

                {{-- Hai ô dưới đây chỉ để xem. Cho sửa được thì người dùng tự đổi
                     được lối đăng nhập và quyền hạn của chính mình — API chặn cả
                     hai, hiện ô sửa rồi trả lỗi chỉ tổ gây hiểu lầm. --}}
                <div class="prf-field">
                    <label class="prf-label" for="prfEmail">
                        Email đăng nhập
                        <span class="prf-tag" title="Chỉ xem, không sửa được ở trang này">Chỉ xem</span>
                    </label>
                    <div class="prf-control">
                        <input type="email" id="prfEmail" class="prf-input" value="{{ $email }}" readonly>
                        <p class="prf-hint">Đây cũng là tên đăng nhập. Cần đổi thì nhờ quản trị viên đổi giúp ở trang Người dùng &amp; vai trò.</p>
                    </div>
                </div>

                <div class="prf-field">
                    <label class="prf-label" for="prfRole">
                        Vai trò
                        <span class="prf-tag" title="Chỉ xem, không sửa được ở trang này">Chỉ xem</span>
                    </label>
                    <div class="prf-control">
                        <input type="text" id="prfRole" class="prf-input" value="{{ $roleLabel }}" readonly>
                        <p class="prf-hint">Vai trò quyết định những trang bạn mở được. Chỉ quản trị viên đổi được.</p>
                    </div>
                </div>
            </div>
        </form>

        {{-- ----- Đổi mật khẩu ----- --}}
        <form method="POST" action="{{ route('admin.profile.password') }}" id="prfPwdForm" class="prf-form">
            @csrf
            @method('PUT')

            <h3 class="prf-section">Đổi mật khẩu</h3>
            <div class="prf-grid">
                <div class="prf-field starts-row">
                    <label class="prf-label" for="prfCurrentPwd">
                        Mật khẩu hiện tại <span class="prf-req" title="Bắt buộc">*</span>
                    </label>
                    <div class="prf-control">
                        <div class="prf-input-wrap">
                            <input type="password" id="prfCurrentPwd" name="current_password" autocomplete="current-password"
                                   class="prf-input has-eye {{ $errors->has('current_password') ? 'is-invalid' : '' }}">
                            @include('settings.partials.password-eye', ['target' => 'prfCurrentPwd'])
                        </div>
                        @error('current_password')
                            <p class="prf-error">{{ $message }}</p>
                        @else
                            <p class="prf-hint">Nhập để xác nhận đúng bạn là chủ tài khoản. Quên mật khẩu thì nhờ quản trị viên đặt lại.</p>
                        @enderror
                    </div>
                </div>

                <div class="prf-field starts-row">
                    <label class="prf-label" for="prfNewPwd">
                        Mật khẩu mới <span class="prf-req" title="Bắt buộc">*</span>
                    </label>
                    <div class="prf-control">
                        <div class="prf-input-wrap">
                            <input type="password" id="prfNewPwd" name="new_password" minlength="6" maxlength="72"
                                   autocomplete="new-password" placeholder="Tối thiểu 6 ký tự"
                                   class="prf-input has-eye {{ $errors->has('new_password') ? 'is-invalid' : '' }}">
                            @include('settings.partials.password-eye', ['target' => 'prfNewPwd'])
                        </div>
                        @error('new_password')
                            <p class="prf-error">{{ $message }}</p>
                        @else
                            <p class="prf-hint">Tối thiểu 6 ký tự, tối đa 72.</p>
                        @enderror
                    </div>
                </div>

                <div class="prf-field">
                    <label class="prf-label" for="prfConfirmPwd">
                        Nhập lại mật khẩu mới <span class="prf-req" title="Bắt buộc">*</span>
                    </label>
                    <div class="prf-control">
                        <div class="prf-input-wrap">
                            <input type="password" id="prfConfirmPwd" name="new_password_confirmation"
                                   minlength="6" maxlength="72" autocomplete="new-password"
                                   class="prf-input has-eye">
                            @include('settings.partials.password-eye', ['target' => 'prfConfirmPwd'])
                        </div>
                        <p class="prf-hint">Gõ lại đúng mật khẩu mới để chắc chắn không bấm nhầm phím.</p>
                    </div>
                </div>
            </div>

            <div class="prf-foot">
                {{-- Phiên đang mở KHÔNG bị đăng xuất sau khi đổi: nói trước để không
                     ai tưởng thao tác chưa ăn thua vì vẫn còn ở trong trang. --}}
                <p class="prf-foot-note">Phiên đang mở vẫn dùng được; mật khẩu mới có hiệu lực từ lần đăng nhập sau.</p>
                <button type="submit" class="prf-btn-primary" id="prfPwdBtn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></svg>
                    Đổi mật khẩu
                </button>
            </div>
        </form>
    </div>

    <style>
        .prf {
            /* Phá padding p-4 của <main> để tràn viền như mọi trang quản trị khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header — cùng chiều cao, cỡ chữ và khoảng cách với các trang cấu hình */
        .prf-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
            padding: 14px 20px; border-bottom: 1px solid #eee;
        }
        .prf-head-text { min-width: 0; }
        .prf-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 24px; }
        .prf-sub { margin: 2px 0 0; font-size: 13px; color: #8c8c8c; }
        .prf-head-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .prf-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .prf-btn-primary:hover { background: #40a9ff; }
        .prf-btn-primary svg { flex-shrink: 0; }
        .prf-btn-ghost {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .prf-btn-ghost:hover { border-color: #bfbfbf; }
        .prf-btn-primary:focus-visible, .prf-btn-ghost:focus-visible {
            outline: none; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
        }

        /* Dải danh tính */
        .prf-identity {
            display: flex; align-items: center; gap: 14px;
            padding: 16px 20px; border-bottom: 1px solid #f0f0f0; background: #fafafa;
        }
        .prf-avatar {
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
            height: 44px; width: 44px; border-radius: 9999px;
            background: #6366f1; color: #fff; font-size: 15px; font-weight: 600; letter-spacing: .3px;
        }
        .prf-identity-text { min-width: 0; }
        .prf-identity-name { margin: 0; font-size: 14px; font-weight: 600; color: #262626; }
        .prf-identity-meta {
            margin: 2px 0 0; font-size: 12px; color: #8c8c8c;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .prf-dot { width: 3px; height: 3px; border-radius: 9999px; background: #d9d9d9; }

        /* Tiêu đề khối — khối thứ hai trở đi có đường kẻ phía trên */
        .prf-section {
            margin: 0; padding: 20px 20px 0;
            font-size: 12px; font-weight: 600; color: #8c8c8c;
            text-transform: uppercase; letter-spacing: .04em;
        }
        .prf-form + .prf-form .prf-section {
            padding-top: 24px; border-top: 1px solid #f0f0f0;
        }
        .prf-section + .prf-grid { padding-top: 14px; }

        /* ĐÚNG 2 cột như các trang cấu hình khác */
        .prf-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: start;
            gap: 20px 24px; padding: 18px 20px 8px;
        }
        @media (max-width: 820px) { .prf-grid { grid-template-columns: 1fr; } }

        .prf-field { min-width: 0; }
        /* Mật khẩu hiện tại đứng riêng một hàng, hai ô mật khẩu mới xếp hàng dưới:
           ba ô trên cùng một hàng thì mắt dễ nhầm ô cũ với ô mới. */
        .prf-field.starts-row { grid-column-start: 1; }
        .prf-label {
            margin: 0 0 4px; font-size: 13px; font-weight: 500; color: #262626;
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        .prf-req { color: #ff4d4f; }
        .prf-tag {
            padding: 1px 6px; border-radius: 4px;
            background: #f5f5f5; color: #8c8c8c;
            font-size: 11px; font-weight: 500; cursor: help;
        }
        .prf-control { min-width: 0; }

        .prf-input {
            width: 100%; height: 36px; box-sizing: border-box;
            padding: 0 12px; border: 1px solid #d9d9d9; border-radius: 4px;
            font-size: 13px; color: #262626; background: #fff; outline: none;
            transition: border-color .15s;
        }
        .prf-input::placeholder { color: #bfbfbf; }
        .prf-input:focus { border-color: #40a9ff; }
        .prf-input.is-invalid { border-color: #ff4d4f; }
        /* Ô chỉ xem: nền xám để phân biệt ngay với ô sửa được, không phải bấm thử
           vào mới biết. */
        .prf-input[readonly] { background: #f5f5f5; color: #595959; cursor: default; }
        .prf-input-wrap { position: relative; }
        .prf-input.has-eye { padding-right: 40px; }
        .prf-eye {
            position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
            height: 28px; width: 28px; display: flex; align-items: center; justify-content: center;
            border: 0; background: transparent; color: #8c8c8c; cursor: pointer; border-radius: 4px;
        }
        .prf-eye:hover { color: #262626; background: #f5f5f5; }
        .prf-eye .prf-eye-off { display: none; }
        .prf-eye.is-on .prf-eye-on { display: none; }
        .prf-eye.is-on .prf-eye-off { display: block; }

        .prf-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; line-height: 1.5; }
        .prf-error { margin: 4px 0 0; font-size: 11px; color: #ff4d4f; line-height: 1.5; }

        .prf-foot {
            display: flex; align-items: center; justify-content: flex-end; gap: 12px; flex-wrap: wrap;
            padding: 8px 20px 28px;
        }
        .prf-foot-note { margin: 0; font-size: 12px; color: #8c8c8c; }
    </style>

    <script>
        (function () {
            // ----- Hiện/ẩn mật khẩu -----
            document.querySelectorAll('[data-eye]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var input = document.getElementById(btn.getAttribute('data-eye'));
                    if (!input) return;
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    btn.classList.toggle('is-on', show);
                    btn.setAttribute('aria-label', show ? 'Ẩn mật khẩu' : 'Hiện mật khẩu');
                });
            });

            // ----- Form hồ sơ: theo dõi thay đổi -----
            var form = document.getElementById('prfForm');
            var saveBtn = document.getElementById('prfSaveBtn');
            var resetBtn = document.getElementById('prfResetBtn');

            function snapshot() {
                return new URLSearchParams(new FormData(form)).toString();
            }
            var initial = snapshot();
            var dirty = false;
            // saved = true khi form đang thực sự được gửi đi, để cảnh báo rời trang
            // không nổ ngay lúc bấm Lưu.
            var saved = false;

            function refresh() { dirty = snapshot() !== initial; }
            form.addEventListener('input', refresh);
            form.addEventListener('change', refresh);

            // Nút Lưu không bao giờ bị disable: chưa đổi gì thì nói ra lý do.
            form.addEventListener('submit', function (e) {
                if (!dirty) {
                    e.preventDefault();
                    window.sysConfirm({
                        type: 'info',
                        title: 'Chưa có thay đổi',
                        message: 'Họ tên và số điện thoại đang giữ nguyên giá trị đã lưu, không có gì để ghi lại.',
                        confirmText: 'Đã hiểu',
                        cancelText: 'Đóng'
                    });
                    return;
                }
                saved = true;
                saveBtn.disabled = true;
                saveBtn.style.opacity = '.7';
            });

            resetBtn.addEventListener('click', function () {
                if (!dirty) {
                    window.sysConfirm({
                        type: 'info',
                        title: 'Chưa có thay đổi',
                        message: 'Không có thay đổi nào để hoàn tác.',
                        confirmText: 'Đã hiểu',
                        cancelText: 'Đóng'
                    });
                    return;
                }
                window.sysConfirm({
                    title: 'Hoàn tác thay đổi',
                    message: 'Mọi chỉnh sửa chưa lưu trên trang này sẽ bị bỏ. Tiếp tục?',
                    confirmText: 'Hoàn tác'
                }).then(function (ok) {
                    if (ok) window.location.reload();
                });
            });

            window.addEventListener('beforeunload', function (e) {
                if (dirty && !saved) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // ----- Form mật khẩu -----
            // Kiểm ngay trên trình duyệt những gì server cũng kiểm, để người dùng
            // không phải nạp lại trang mới biết mình gõ lệch hai ô.
            var pwdForm = document.getElementById('prfPwdForm');
            var current = document.getElementById('prfCurrentPwd');
            var next = document.getElementById('prfNewPwd');
            var confirmPwd = document.getElementById('prfConfirmPwd');

            function warn(message) {
                window.sysConfirm({
                    type: 'info',
                    title: 'Chưa đổi được mật khẩu',
                    message: message,
                    confirmText: 'Đã hiểu',
                    cancelText: 'Đóng'
                });
            }

            pwdForm.addEventListener('submit', function (e) {
                if (current.value === '' || next.value === '' || confirmPwd.value === '') {
                    e.preventDefault();
                    warn('Nhập đủ mật khẩu hiện tại, mật khẩu mới và ô nhập lại.');
                    return;
                }
                if (next.value.length < 6) {
                    e.preventDefault();
                    warn('Mật khẩu mới phải từ 6 ký tự trở lên.');
                    return;
                }
                if (next.value !== confirmPwd.value) {
                    e.preventDefault();
                    warn('Hai lần nhập mật khẩu mới không khớp. Gõ lại ô "Nhập lại mật khẩu mới".');
                    return;
                }
                if (next.value === current.value) {
                    e.preventDefault();
                    warn('Mật khẩu mới trùng với mật khẩu đang dùng — hãy đặt một mật khẩu khác.');
                    return;
                }
                // Form mật khẩu không nằm trong theo dõi thay đổi của form hồ sơ,
                // nhưng vẫn phải tắt cảnh báo rời trang khi nó gửi đi.
                saved = true;
            });
        })();
    </script>
@endsection
