@extends('layouts.app')

@section('title', 'Danh sách khách hàng')

@section('content')
    {{--
        Trang "Danh sách khách hàng" — dựng theo đúng khuôn trang Sản phẩm:
        [ header tiêu đề ] + [ form lọc: tìm kiếm + select + hành động ] + [ bảng compact ] + [ chân trang ].
        Lọc/tìm/phân trang chạy phía server (Go API). Đổi select -> submit ngay, gõ tìm kiếm -> debounce 400ms.
        Trạng thái tài khoản dùng công tắc bật/tắt giống trang Sản phẩm; xoá/xoá hàng loạt dựng form POST động (CSRF).
    --}}
    @php
        $STATUSES = \App\Http\Controllers\CustomerController::STATUSES;
        $GENDERS = \App\Http\Controllers\CustomerController::GENDERS;
        $SORTS = \App\Http\Controllers\CustomerController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\CustomerController::PAGE_SIZES;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['gender'] !== 'all'
            || $filters['sort'] !== 'newest';
    @endphp

    <div class="cst">
        {{-- Header --}}
        <div class="cst-head">
            <h1 class="cst-title">Danh sách khách hàng</h1>
        </div>

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.customers.index') }}" id="cstFilter" class="cst-filter">
            <div class="cst-toolbar">
                <div class="cst-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="cst-search-input"
                           placeholder="Tìm theo tên, email hoặc SĐT" autocomplete="off">
                    <button type="submit" class="cst-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                {{-- Số lượng lấy từ API /admin/customers/stats, hiện ngay trên từng lựa chọn --}}
                <select name="status" class="cst-select" title="Lọc theo trạng thái">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ number_format($stats[$value] ?? 0, 0, ',', '.') }})
                        </option>
                    @endforeach
                </select>

                <select name="gender" class="cst-select" title="Lọc theo giới tính">
                    <option value="all" {{ $filters['gender'] === 'all' ? 'selected' : '' }}>Tất cả giới tính</option>
                    @foreach($GENDERS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['gender'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="sort" class="cst-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                {{-- Hành động: Thêm khách hàng (dropdown) + Tiện ích (đẩy sang phải toolbar) --}}
                <div class="cst-toolbar-actions">
                    <div class="cst-add" id="cstAdd">
                        <button type="button" id="cstAddBtn" class="cst-btn-primary" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                            Thêm khách hàng
                            <svg class="cst-add-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="cst-add-menu">
                            <button type="button" class="cst-menu-item" id="cstAddFull">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/></svg>
                                <span class="cst-menu-text">
                                    Thêm khách hàng
                                    <small>Nhập đầy đủ hồ sơ khách hàng</small>
                                </span>
                            </button>
                            <button type="button" class="cst-menu-item" id="cstAddQuick">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
                                <span class="cst-menu-text">
                                    Cấp tài khoản đăng nhập
                                    <small>Chọn khách hàng có sẵn rồi đặt mật khẩu</small>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="cst-util" id="cstUtil">
                        <button type="button" class="cst-util-btn" id="cstUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="cst-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="cst-util-menu">
                            <a href="{{ route('admin.customers.export', request()->query()) }}" class="cst-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                            <button type="button" class="cst-util-item" id="cstImportBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 8l5-5 5 5"/><path d="M12 3v12"/></svg>
                                Nhập file (CSV)
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Giữ số dòng/trang khi đổi bộ lọc --}}
            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}" id="cstPageSizeHidden">
        </form>

        {{-- Bảng --}}
        <div class="cst-table-wrap">
            <table class="cst-table">
                <thead>
                    <tr>
                        <th class="cst-c-check"><input type="checkbox" id="cstCheckAll" class="cst-check" title="Chọn tất cả"></th>
                        <th class="cst-c-stt">STT</th>
                        <th class="cst-c-code">Mã KH</th>
                        <th class="cst-c-name">Khách hàng</th>
                        <th class="cst-c-phone">Số điện thoại</th>
                        <th class="cst-c-gender">Giới tính</th>
                        <th class="cst-c-address">Địa chỉ</th>
                        <th class="cst-c-orders">Đơn hàng</th>
                        <th class="cst-c-spent">Chi tiêu</th>
                        <th class="cst-c-status">Trạng thái</th>
                        <th class="cst-c-date">Ngày đăng ký</th>
                        <th class="cst-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($customers as $i => $c)
                        @php
                            $id = $c['id'] ?? 0;
                            $name = $c['full_name'] ?? '';
                            $status = $c['status'] ?? 'active';
                            $isOn = $status === 'active';
                            $switchTitle = match ($status) {
                                'active' => 'Đang hoạt động — bấm để tạm ngưng',
                                default => 'Đang tạm ngưng — bấm để kích hoạt',
                            };
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="cst-c-check"><input type="checkbox" class="cst-check cst-row-check" value="{{ $id }}"></td>
                            <td class="cst-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="cst-c-code" data-view="{{ $id }}" title="Xem chi tiết khách hàng">
                                <span class="cst-code">{{ 'KH' . str_pad((string) $id, 6, '0', STR_PAD_LEFT) }}</span>
                            </td>
                            <td class="cst-c-name" data-view="{{ $id }}" title="Xem chi tiết khách hàng">
                                <div class="cst-user">
                                    @if(!empty($c['avatar']))
                                        <img class="cst-avatar" src="{{ $c['avatar'] }}" alt="" loading="lazy">
                                    @else
                                        <span class="cst-avatar cst-avatar-empty">{{ mb_strtoupper(mb_substr($name !== '' ? $name : 'K', 0, 1)) }}</span>
                                    @endif
                                    <span class="cst-user-meta">
                                        <span class="cst-name">{{ $name !== '' ? $name : '—' }}</span>
                                        <span class="cst-sub">{{ !empty($c['email']) ? $c['email'] : 'Chưa có email' }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="cst-c-phone">{{ !empty($c['phone']) ? $c['phone'] : '—' }}</td>
                            <td class="cst-c-gender">
                                @if(isset($GENDERS[$c['gender'] ?? '']))
                                    {{ $GENDERS[$c['gender']] }}
                                @else
                                    <span class="cst-muted">—</span>
                                @endif
                            </td>
                            <td class="cst-c-address" title="{{ $c['address'] ?? '' }}">
                                @if(!empty($c['address']))
                                    {{ $c['address'] }}
                                @else
                                    <span class="cst-muted">—</span>
                                @endif
                            </td>
                            <td class="cst-c-orders">{{ number_format((int) ($c['total_orders'] ?? 0), 0, ',', '.') }}</td>
                            <td class="cst-c-spent">
                                <span class="cst-spent">{{ number_format((float) ($c['total_spent'] ?? 0), 0, ',', '.') }}₫</span>
                            </td>
                            <td class="cst-c-status">
                                <button type="button" class="cst-switch {{ $isOn ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $isOn ? 1 : 0 }}"
                                        title="{{ $switchTitle }}">
                                    <span class="cst-switch-knob"></span>
                                </button>
                            </td>
                            <td class="cst-c-date">
                                {{-- Carbon::parse giữ nguyên offset +07:00 API trả về (date()/strtotime đổi về UTC làm lệch ngày) --}}
                                {{ !empty($c['created_at']) ? \Illuminate\Support\Carbon::parse($c['created_at'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="cst-c-act">
                                <div class="cst-rowacts">
                                    <button type="button" class="cst-rowbtn cst-edit" data-edit="{{ $id }}" title="Sửa khách hàng">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="cst-rowbtn cst-del" data-remove="{{ $id }}" title="Xoá khách hàng">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="cst-empty">
                                @if($hasFilter)
                                    Không tìm thấy khách hàng nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    Chưa có khách hàng nào. Bấm “Thêm khách hàng” để tạo mới.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Chân trang: phân trang dùng chung toàn hệ thống --}}
        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'khách hàng',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    <div id="cstBulkMount"></div>

    {{-- Modal Thêm / Sửa khách hàng --}}
    <div class="cst-overlay" id="cstFormOverlay" style="display:none;">
        <div class="cst-dialog">
            <div class="cst-modal-head">
                <h4 class="cst-modal-title" id="cstFormTitle">Thêm khách hàng</h4>
                <button type="button" class="cst-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="cstForm" method="POST" action="{{ route('admin.customers.store') }}">
                @csrf
                <input type="hidden" name="_method" id="cstFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="cst-modal-body">
                    <div class="cst-grid2">
                        <div>
                            <label class="cst-field-label" for="cstFullName">Họ và tên <span class="cst-req">*</span></label>
                            <input type="text" id="cstFullName" name="full_name" class="cst-input" placeholder="VD: Nguyễn Văn An" required autocomplete="off">
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstEmail">Email đăng nhập <span class="cst-req">*</span></label>
                            <input type="email" id="cstEmail" name="email" class="cst-input" placeholder="example@domain.com" required autocomplete="off">
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstPhone">Số điện thoại <span class="cst-req">*</span></label>
                            <input type="text" id="cstPhone" name="phone" class="cst-input" placeholder="0901234567" required autocomplete="off">
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstGender">Giới tính</label>
                            <select id="cstGender" name="gender" class="cst-msel" data-ph>
                                <option value="">Chọn giới tính</option>
                                @foreach($GENDERS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstDob">Ngày sinh</label>
                            <input type="date" id="cstDob" name="date_of_birth" class="cst-input">
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstStatus">Trạng thái tài khoản <span class="cst-req">*</span></label>
                            <select id="cstStatus" name="status" class="cst-msel" required>
                                @foreach($STATUSES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="cst-col-2">
                            <label class="cst-field-label">Ảnh đại diện</label>
                            <div class="cst-img-field">
                                <div class="cst-img-preview" id="cstImgPreview"></div>
                                <div class="cst-img-actions">
                                    <div class="cst-img-btns">
                                        <button type="button" class="cst-btn-ghost" id="cstImgPick">Chọn ảnh</button>
                                        <button type="button" class="cst-btn-ghost cst-img-remove" id="cstImgRemove">Xoá ảnh</button>
                                    </div>
                                    <p class="cst-hint">JPG, PNG, WEBP, GIF hoặc AVIF — tối đa 10MB, ảnh lớn sẽ được tự thu nhỏ.</p>
                                </div>
                                <input type="file" id="cstImgInput" accept="image/*" hidden>
                                <input type="hidden" name="avatar" id="cstAvatar">
                            </div>
                        </div>

                        <div class="cst-col-2">
                            <label class="cst-field-label" for="cstAddress">Địa chỉ mặc định</label>
                            <textarea id="cstAddress" name="address" class="cst-textarea" placeholder="Số nhà, tên đường, Phường/Xã, Quận/Huyện, Tỉnh/Thành phố"></textarea>
                            <p class="cst-hint">Được lưu thành địa chỉ giao hàng mặc định của khách hàng.</p>
                        </div>
                    </div>
                </div>

                <div class="cst-modal-foot">
                    <button type="button" class="cst-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="cst-btn-primary" id="cstFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Nhập khách hàng từ file CSV --}}
    <div class="cst-overlay" id="cstImportOverlay" style="display:none;">
        <div class="cst-dialog cst-dialog-sm">
            <div class="cst-modal-head">
                <h4 class="cst-modal-title">Nhập khách hàng từ file</h4>
                <button type="button" class="cst-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.customers.import') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="cst-modal-body">
                    <p class="cst-note">
                        Chọn file <b>CSV</b> theo mẫu, mỗi dòng một khách hàng. Cột bắt buộc: <b>full_name</b> và <b>email</b>
                        (email là tên đăng nhập nên không được trùng). Bỏ trống cột <b>password</b> thì hệ thống cấp mật khẩu mặc định.
                        <br><a href="{{ route('admin.customers.importTemplate') }}">⬇ Tải file mẫu</a>
                    </p>

                    <div>
                        <label class="cst-field-label" for="cstImportFile">File CSV <span class="cst-req">*</span></label>
                        <input type="file" id="cstImportFile" name="file" accept=".csv,text/csv" required class="cst-input cst-input-file">
                    </div>
                </div>

                <div class="cst-modal-foot">
                    <button type="button" class="cst-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="cst-btn-primary">Nhập</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Cấp tài khoản đăng nhập cho khách hàng đang có --}}
    <div class="cst-overlay" id="cstQuickOverlay" style="display:none;">
        <div class="cst-dialog">
            <div class="cst-modal-head">
                <h4 class="cst-modal-title">Cấp tài khoản đăng nhập</h4>
                <button type="button" class="cst-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.customers.loginAccount') }}" id="cstQuickForm">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="customer_id" id="cstComboValue">

                <div class="cst-modal-body">
                    <p class="cst-note">
                        Chọn khách hàng đang hoạt động rồi đặt mật khẩu — <b>email của khách chính là tên đăng nhập</b> trên website.
                        Thông tin bên dưới tự điền theo khách hàng đã chọn; nếu cần sửa thì dùng nút sửa ở bảng.
                    </p>

                    <div class="cst-grid2">
                        {{-- Ô chọn khách hàng (có tìm kiếm) --}}
                        <div class="cst-col-2">
                            <label class="cst-field-label">Khách hàng <span class="cst-req">*</span></label>
                            <div class="cst-combo" id="cstCombo">
                                <button type="button" class="cst-combo-btn" id="cstComboBtn" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="cst-combo-label is-empty" id="cstComboLabel">Chọn khách hàng…</span>
                                    <svg class="cst-combo-caret" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                                <div class="cst-combo-panel">
                                    <input type="text" id="cstComboSearch" class="cst-combo-search" placeholder="Tìm theo tên, email hoặc số điện thoại" autocomplete="off">
                                    <div class="cst-combo-list" id="cstComboList" role="listbox"></div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="cst-field-label" for="cstQCode">Mã khách hàng</label>
                            <input type="text" id="cstQCode" class="cst-input" placeholder="—" readonly>
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstQEmail">Email đăng nhập</label>
                            <input type="text" id="cstQEmail" class="cst-input" placeholder="—" readonly>
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstQPhone">Số điện thoại</label>
                            <input type="text" id="cstQPhone" class="cst-input" placeholder="—" readonly>
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstQLastLogin">Đăng nhập gần nhất</label>
                            <input type="text" id="cstQLastLogin" class="cst-input" placeholder="—" readonly>
                        </div>

                        <div>
                            <label class="cst-field-label" for="cstQPass">Mật khẩu <span class="cst-req">*</span></label>
                            <div class="cst-pass">
                                <input type="password" id="cstQPass" name="password" class="cst-input" placeholder="Tối thiểu 6 ký tự" minlength="6" required autocomplete="new-password">
                                <button type="button" class="cst-pass-btn" id="cstQPassToggle" title="Hiện/ẩn mật khẩu">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="cst-field-label" for="cstQPass2">Xác nhận mật khẩu <span class="cst-req">*</span></label>
                            <input type="password" id="cstQPass2" name="password_confirmation" class="cst-input" placeholder="Nhập lại mật khẩu" minlength="6" required autocomplete="new-password">
                        </div>

                        <div class="cst-col-2">
                            <p class="cst-hint">Gửi mật khẩu này cho khách và nhắc khách đổi lại sau lần đăng nhập đầu tiên.</p>
                        </div>
                    </div>
                </div>

                <div class="cst-modal-foot">
                    <button type="button" class="cst-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="cst-btn-primary">Cấp tài khoản</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Chi tiết khách hàng --}}
    <div class="cst-overlay" id="cstViewOverlay" style="display:none;">
        <div class="cst-dialog cst-dialog-sm">
            <div class="cst-modal-head">
                <h4 class="cst-modal-title">Chi tiết khách hàng</h4>
                <button type="button" class="cst-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="cst-modal-body cst-view-body">
                {{-- Khối đầu: ảnh đại diện thật + tên + mã KH · ngày tham gia + trạng thái --}}
                <div class="cst-view-head">
                    <span class="cst-view-ava" id="vAvatar"></span>
                    <div class="cst-view-ident">
                        <span class="cst-view-name" id="vName">—</span>
                        <span class="cst-view-code" id="vCode">—</span>
                    </div>
                    <span class="cst-state" id="vState">—</span>
                </div>

                {{-- Nhóm 1: tài khoản đăng nhập storefront --}}
                <div class="cst-view-sec">
                    <p class="cst-sec-title">Tài khoản đăng nhập</p>
                    <div class="cst-view-grid">
                        <div class="cst-view-cell is-full">
                            <span class="cst-view-lb">Email đăng nhập</span>
                            <span class="cst-view-vl">
                                <span id="vLoginEmail">—</span>
                                <em class="cst-chip" id="vVerified">—</em>
                            </span>
                        </div>
                        <div class="cst-view-cell">
                            <span class="cst-view-lb">Mật khẩu</span>
                            <span class="cst-view-vl cst-view-mask">••••••••<em class="cst-view-note">đã mã hoá</em></span>
                        </div>
                        <div class="cst-view-cell">
                            <span class="cst-view-lb">Đăng nhập gần nhất</span>
                            <span class="cst-view-vl" id="vLastLogin">—</span>
                        </div>
                    </div>
                </div>

                {{-- Nhóm 2: thông tin cá nhân --}}
                <div class="cst-view-sec">
                    <p class="cst-sec-title">Thông tin cá nhân</p>
                    <div class="cst-view-grid">
                        <div class="cst-view-cell">
                            <span class="cst-view-lb">Số điện thoại</span>
                            <span class="cst-view-vl" id="vPhone">—</span>
                        </div>
                        <div class="cst-view-cell">
                            <span class="cst-view-lb">Giới tính</span>
                            <span class="cst-view-vl" id="vGender">—</span>
                        </div>
                        <div class="cst-view-cell">
                            <span class="cst-view-lb">Ngày sinh</span>
                            <span class="cst-view-vl" id="vDob">—</span>
                        </div>
                        <div class="cst-view-cell">
                            <span class="cst-view-lb">Ngày đăng ký</span>
                            <span class="cst-view-vl" id="vCreated">—</span>
                        </div>
                        <div class="cst-view-cell is-full">
                            <span class="cst-view-lb">Địa chỉ mặc định</span>
                            <span class="cst-view-vl" id="vAddress">—</span>
                        </div>
                    </div>
                </div>

                {{-- Nhóm 3: số liệu mua hàng --}}
                <div class="cst-view-sec">
                    <p class="cst-sec-title">Mua hàng</p>
                    <div class="cst-view-stats">
                        <div><span class="cst-view-num" id="vOrders">—</span><span class="cst-view-cap">Đơn hàng</span></div>
                        <div><span class="cst-view-num" id="vSpent">—</span><span class="cst-view-cap">Tổng chi tiêu</span></div>
                        <div><span class="cst-view-num" id="vLastOrder">—</span><span class="cst-view-cap">Đơn gần nhất</span></div>
                    </div>
                </div>
            </div>

            <div class="cst-modal-foot">
                <button type="button" class="cst-btn-ghost" data-close>Đóng</button>
                <button type="button" class="cst-btn-primary" id="cstViewEdit">Sửa</button>
            </div>
        </div>
    </div>

    <style>
        .cst {
            /* Phá padding p-4 của <main> để tràn viền như trang Sản phẩm */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .cst-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 20px;
        }
        .cst-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .cst-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .cst-btn-primary:hover { background: #40a9ff; }
        .cst-btn-primary svg { flex-shrink: 0; }

        /* Bộ lọc */
        .cst-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .cst-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .cst-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .cst-searchbox { display: flex; border-radius: 4px; }
        .cst-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .cst-search-input {
            height: 34px; width: 240px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .cst-search-input::placeholder { color: #bfbfbf; }
        .cst-searchbox:focus-within .cst-search-input,
        .cst-searchbox:focus-within .cst-search-btn { border-color: #86b7fe; }
        .cst-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer; transition: color .15s;
        }
        .cst-search-btn:hover { color: #1890ff; }

        .cst-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .cst-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Dropdown "Thêm khách hàng" */
        .cst-add { position: relative; }
        .cst-add-caret { transition: transform .2s; }
        .cst-add.open .cst-add-caret { transform: rotate(180deg); }
        .cst-add-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 290px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .cst-add.open .cst-add-menu { display: block; }
        .cst-menu-item {
            display: flex; align-items: flex-start; gap: 10px; width: 100%; padding: 9px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-align: left;
            cursor: pointer; transition: background .15s, color .15s;
        }
        .cst-menu-item:hover { background: #f5f7fa; color: #1890ff; }
        .cst-menu-item svg { color: #8c8c8c; flex-shrink: 0; margin-top: 2px; }
        .cst-menu-item:hover svg { color: #1890ff; }
        .cst-menu-text { display: flex; flex-direction: column; gap: 2px; }
        .cst-menu-text small { font-size: 11px; color: #8c8c8c; }

        /* Dropdown Tiện ích */
        .cst-util { position: relative; }
        .cst-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .cst-util-btn:hover, .cst-util.open .cst-util-btn { border-color: #1890ff; color: #1890ff; }
        .cst-util-caret { transition: transform .2s; }
        .cst-util.open .cst-util-caret { transform: rotate(180deg); }
        .cst-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 190px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .cst-util.open .cst-util-menu { display: block; }
        .cst-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .cst-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .cst-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .cst-util-item:hover svg { color: #1890ff; }

        /* Bảng — cột co theo nội dung (compact) như trang Sản phẩm */
        .cst-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent; }
        .cst-table-wrap::-webkit-scrollbar { height: 11px; }
        .cst-table-wrap::-webkit-scrollbar-track { background: transparent; }
        .cst-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .cst-table-wrap::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }

        .cst-table { width: auto; border-collapse: collapse; font-size: 13px; }
        .cst-table thead tr { background: #f0f0f0; color: #262626; }
        .cst-table th, .cst-table td { padding: 14px 22px; vertical-align: middle; white-space: nowrap; }
        .cst-table th { font-weight: 700; text-align: left; }
        .cst-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .cst-table tbody tr:hover { background: #fafafa; }
        .cst-table tbody tr.is-selected, .cst-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .cst-table th.cst-c-check,   .cst-table td.cst-c-check   { width: 1%; padding-right: 8px; }
        .cst-table th.cst-c-stt,     .cst-table td.cst-c-stt     { width: 1%; text-align: center; }
        .cst-table th.cst-c-code,    .cst-table td.cst-c-code    { width: 1%; }
        .cst-table th.cst-c-name,    .cst-table td.cst-c-name    { min-width: 220px; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
        .cst-table th.cst-c-phone,   .cst-table td.cst-c-phone   { width: 1%; }
        .cst-table th.cst-c-gender,  .cst-table td.cst-c-gender  { width: 1%; text-align: center; }
        .cst-table th.cst-c-address, .cst-table td.cst-c-address { min-width: 200px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .cst-table th.cst-c-orders,  .cst-table td.cst-c-orders  { width: 1%; text-align: center; }
        .cst-table th.cst-c-spent,   .cst-table td.cst-c-spent   { width: 1%; }
        .cst-table th.cst-c-status,  .cst-table td.cst-c-status  { width: 1%; text-align: center; }
        .cst-table th.cst-c-date,    .cst-table td.cst-c-date    { width: 1%; }
        .cst-table th.cst-c-act,     .cst-table td.cst-c-act     { width: 1%; text-align: center; }

        .cst-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .cst-muted { color: #bfbfbf; }
        .cst-code { font-variant-numeric: tabular-nums; letter-spacing: .2px; color: #595959; }

        .cst-user { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .cst-avatar {
            width: 34px; height: 34px; flex-shrink: 0; border-radius: 50%; object-fit: cover;
            border: 1px solid #f0f0f0;
        }
        .cst-avatar-empty {
            display: inline-flex; align-items: center; justify-content: center;
            background: #f5f5f5; color: #595959; font-size: 13px; font-weight: 600;
        }
        .cst-user-meta { display: flex; flex-direction: column; min-width: 0; }
        .cst-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cst-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cst-spent { font-weight: 600; color: #262626; }

        /* Công tắc trạng thái — cùng cấu trúc & màu với trang Sản phẩm */
        .cst-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .cst-switch.on { background: #7083b6; }
        .cst-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .cst-switch.on .cst-switch-knob { transform: translateX(23px); }

        .cst-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .cst-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .cst-rowbtn.cst-edit { color: #1890ff; }
        .cst-rowbtn.cst-edit:hover { background: #e6f7ff; }
        .cst-rowbtn.cst-del { color: #ff4d4f; }
        .cst-rowbtn.cst-del:hover { background: #fff1f0; }

        .cst-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Mã KH & tên click được để xem chi tiết (giống trang Sản phẩm) */
        .cst-c-code[data-view], .cst-c-name[data-view] { cursor: pointer; }
        .cst-c-code[data-view]:hover .cst-code,
        .cst-c-name[data-view]:hover .cst-name { color: #1890ff; text-decoration: underline; }

        .cst-btn-primary:focus-visible, .cst-btn-ghost:focus-visible,
        .cst-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Thanh bulk nổi */
        .cst-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .cst-bulk { left: 48px; }
        @media (max-width: 820px) { .cst-bulk { left: 0; } }
        .cst-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .cst-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .cst-bulk-clear:hover { color: #262626; }
        .cst-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 9999px; background: #ff4d4f;
            padding: 6px 16px; font-size: 13px; font-weight: 500; color: #fff; cursor: pointer; transition: background .15s;
        }
        .cst-bulk-del:hover { background: #ff7875; }

        /* ---- Modal ---- */
        .cst-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .cst-dialog {
            max-height: 92vh; width: 100%; max-width: 640px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2);
            scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent;
        }
        .cst-dialog::-webkit-scrollbar { width: 11px; }
        .cst-dialog::-webkit-scrollbar-track { background: transparent; }
        .cst-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .cst-dialog::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }
        .cst-dialog-sm { max-width: 440px; }

        .cst-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .cst-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .cst-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .cst-modal-x:hover { color: #262626; }
        .cst-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .cst-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .cst-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .cst-btn-ghost:hover { border-color: #bfbfbf; }

        .cst-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .cst-col-2 { grid-column: span 2; }
        .cst-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .cst-req { color: #ff4d4f; }
        .cst-input, .cst-textarea, .cst-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px; outline: none;
            transition: border-color .15s; font-family: inherit; color: #262626; background: #fff;
        }
        .cst-input { height: 36px; }
        .cst-textarea { padding: 8px 12px; min-height: 64px; resize: vertical; line-height: 1.5; }
        .cst-input::placeholder, .cst-textarea::placeholder { color: #bfbfbf; }
        .cst-input:focus, .cst-textarea:focus, .cst-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .cst-msel {
            height: 36px; cursor: pointer; padding-right: 32px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .cst-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; }

        /* Ô chọn khách hàng có tìm kiếm */
        .cst-combo { position: relative; }
        .cst-combo-btn {
            display: flex; align-items: center; gap: 8px; width: 100%; height: 36px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 0 12px;
            font-size: 13px; color: #262626; text-align: left; cursor: pointer; transition: border-color .15s;
        }
        .cst-combo.open .cst-combo-btn { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .cst-combo-label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cst-combo-label.is-empty { color: #bfbfbf; }
        .cst-combo-caret { flex-shrink: 0; color: #595959; transition: transform .2s; }
        .cst-combo.open .cst-combo-caret { transform: rotate(180deg); }

        .cst-combo-panel {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 1090; display: none;
            border: 1px solid #e6e6e6; border-radius: 6px; background: #fff; padding: 6px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12);
        }
        .cst-combo.open .cst-combo-panel { display: block; }
        .cst-combo-search {
            width: 100%; height: 32px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 10px;
            font-size: 13px; outline: none; margin-bottom: 6px;
        }
        .cst-combo-search::placeholder { color: #bfbfbf; }
        .cst-combo-list { max-height: 220px; overflow-y: auto; }
        .cst-combo-item {
            display: flex; flex-direction: column; gap: 2px; width: 100%; padding: 7px 10px; border: 0;
            background: none; border-radius: 4px; text-align: left; cursor: pointer; transition: background .15s;
        }
        .cst-combo-item:hover { background: #f5f7fa; }
        .cst-combo-item.is-selected { background: #e6f7ff; }
        .cst-combo-item b { font-size: 13px; font-weight: 500; color: #262626; }
        .cst-combo-item small { font-size: 11px; color: #8c8c8c; }
        .cst-combo-empty { padding: 14px 10px; text-align: center; font-size: 12px; color: #bfbfbf; }

        /* Ô ảnh đại diện trong modal (đồng bộ trang Danh mục & Sản phẩm) */
        .cst-img-field { display: flex; align-items: center; gap: 14px; }
        .cst-img-preview {
            width: 84px; height: 84px; flex-shrink: 0; border: 1px solid #d9d9d9; border-radius: 50%;
            overflow: hidden; display: flex; align-items: center; justify-content: center;
            background: #fafafa; color: #bfbfbf; position: relative; transition: opacity .15s;
        }
        .cst-img-preview img { width: 100%; height: 100%; object-fit: cover; }
        .cst-img-preview.is-loading { opacity: .5; }
        .cst-img-preview.is-loading::after {
            content: ''; position: absolute; width: 22px; height: 22px; border-radius: 50%;
            border: 2px solid #d9d9d9; border-top-color: #1890ff; animation: cstspin .7s linear infinite;
        }
        @keyframes cstspin { to { transform: rotate(360deg); } }
        .cst-img-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .cst-img-btns { display: flex; gap: 8px; }
        .cst-img-btns .cst-btn-ghost { height: 32px; padding: 0 12px; }
        .cst-img-remove { color: #ff4d4f; }
        .cst-img-remove:hover { border-color: #ffa39e; }
        .cst-img-actions .cst-hint { margin: 0; }

        .cst-input-file { height: auto; padding: 7px 10px; }

        /* Ô mật khẩu kèm nút hiện/ẩn */
        .cst-pass { position: relative; }
        .cst-pass .cst-input { padding-right: 38px; }
        .cst-pass-btn {
            position: absolute; right: 1px; top: 1px; height: 34px; width: 34px; border: 0; background: none;
            color: #8c8c8c; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
            transition: color .15s;
        }
        .cst-pass-btn:hover { color: #1890ff; }
        .cst-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px; background: #f5f9ff;
            font-size: 12px; line-height: 1.55; color: #595959;
        }

        /* ---- Modal chi tiết (gọn: khối đầu + dải số liệu + lưới 2 cột) ---- */
        .cst-view-body { gap: 16px; }
        .cst-view-head { display: flex; align-items: center; gap: 12px; }
        .cst-view-ava {
            width: 52px; height: 52px; flex-shrink: 0; border-radius: 50%; overflow: hidden;
            display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #f0f0f0; background: #f5f5f5; color: #595959; font-size: 19px; font-weight: 600;
        }
        .cst-view-ava img { width: 100%; height: 100%; object-fit: cover; }
        .cst-view-ident { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .cst-view-name { font-size: 15px; font-weight: 700; color: #262626; }
        .cst-view-code { font-size: 12px; color: #8c8c8c; }
        .cst-state {
            margin-left: auto; flex-shrink: 0; padding: 3px 10px; border-radius: 4px;
            font-size: 12px; font-weight: 500; border: 1px solid #d9d9d9; color: #595959; background: #fafafa;
        }
        .cst-state.is-on { border-color: #b7eb8f; color: #389e0d; background: #f6ffed; }

        .cst-view-stats {
            display: grid; grid-template-columns: repeat(3, 1fr);
            border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa;
        }
        .cst-view-stats > div {
            display: flex; flex-direction: column; gap: 2px; align-items: center; padding: 10px 8px;
            border-left: 1px solid #f0f0f0; min-width: 0;
        }
        .cst-view-stats > div:first-child { border-left: 0; }
        .cst-view-num { font-size: 14px; font-weight: 700; color: #262626; white-space: nowrap; }
        .cst-view-cap { font-size: 11px; color: #8c8c8c; }

        /* Nhóm thông tin: tiêu đề + lưới trường bên dưới */
        .cst-view-sec { display: flex; flex-direction: column; gap: 10px; }
        .cst-sec-title {
            margin: 0; padding-bottom: 6px; border-bottom: 1px solid #f0f0f0;
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #8c8c8c;
        }
        .cst-view-mask { letter-spacing: 2px; }
        .cst-view-note {
            margin-left: 8px; font-size: 11px; font-style: normal; letter-spacing: normal; color: #bfbfbf;
        }

        .cst-view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .cst-view-cell { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .cst-view-cell.is-full { grid-column: span 2; }
        .cst-view-lb { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c; }
        .cst-view-vl { font-size: 13px; color: #262626; word-break: break-word; }
        .cst-chip {
            display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 4px;
            font-size: 11px; font-style: normal; border: 1px solid #d9d9d9; color: #8c8c8c; background: #fafafa;
        }
        .cst-chip.is-ok { border-color: #b7eb8f; color: #389e0d; background: #f6ffed; }

        @media (max-width: 560px) { .cst-view-grid { grid-template-columns: 1fr; } .cst-view-cell.is-full { grid-column: span 1; } }

        @media (max-width: 560px) {
            .cst-grid2 { grid-template-columns: 1fr; }
            .cst-col-2 { grid-column: span 1; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());

        // Trần phía trình duyệt, khớp với ImageStore::MAX_UPLOAD_KB bên PHP. Chặn ở
        // đây chỉ để khỏi tải một file khổng lồ lên rồi mới bị từ chối; ảnh vẫn được
        // máy chủ thu nhỏ lại sau khi nhận.
        const MAX_IMG_BYTES = 10 * 1024 * 1024;
            const URL_BASE = @json(url('admin/customers'));
            const URL_STORE = @json(route('admin.customers.store'));
            const URL_BULK = @json(route('admin.customers.bulkDestroy'));
            const URL_UPLOAD_AVATAR = @json(route('admin.customers.uploadAvatar'));
            const RETURN_URL = @json(request()->getRequestUri());
            const CUSTOMERS = @json($customers);
            const BY_ID = new Map(CUSTOMERS.map((c) => [c.id, c]));
            // Khách hàng đang hoạt động — nguồn cho ô chọn ở modal "Cấp tài khoản đăng nhập".
            const ACTIVE_CUSTOMERS = @json($activeCustomers);
            const GENDERS = @json($GENDERS);
            const STATUSES = @json($STATUSES);

            const $filter = document.getElementById('cstFilter');
            const $bulkMount = document.getElementById('cstBulkMount');

            const fmtInt = (n) => new Intl.NumberFormat('vi-VN').format(Number(n) || 0);
            const fmtDate = (s) => {
                if (!s) return '—';
                const d = new Date(s);
                return isNaN(d) ? s : d.toLocaleDateString('vi-VN');
            };
            const fmtDateTime = (s) => {
                if (!s) return '—';
                const d = new Date(s);
                return isNaN(d) ? s : d.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
            };
            const code = (id) => 'KH' + String(id).padStart(6, '0');
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            // ---------- Bộ lọc: đổi select -> submit ngay; gõ tìm kiếm -> debounce 400ms ----------
            $filter.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => $filter.submit());
            });
            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // ---------- Form POST động (mutation) ----------
            function postForm(action, method, fields) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = action;
                f.style.display = 'none';
                const add = (name, val) => {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = name;
                    i.value = typeof val === 'boolean' ? (val ? 1 : 0) : (val == null ? '' : val);
                    f.appendChild(i);
                };
                add('_token', CSRF);
                if (method && method !== 'POST') add('_method', method);
                add('return', RETURN_URL);
                for (const [k, v] of Object.entries(fields)) add(k, v);
                document.body.appendChild(f);
                f.submit();
            }

            // Bật/tắt tài khoản: bật = đang hoạt động, tắt = tạm ngưng.
            function toggleStatus(btn, c) {
                postForm(`${URL_BASE}/${c.id}/toggle-status`, 'PUT', {
                    status: btn.dataset.on === '1' ? 'inactive' : 'active',
                });
            }

            function removeCustomer(c) {
                sysDelete({
                    title: 'Xác nhận xoá khách hàng',
                    message: `Bạn có chắc chắn muốn xoá khách hàng "${c.full_name}"? Hành động này không thể hoàn tác.`,
                    highlightText: c.full_name
                }).then((confirmed) => {
                    if (confirmed) postForm(`${URL_BASE}/${c.id}`, 'DELETE', {});
                });
            }

            // ---------- Modal ----------
            const $formOverlay = document.getElementById('cstFormOverlay');
            const $quickOverlay = document.getElementById('cstQuickOverlay');
            const $viewOverlay = document.getElementById('cstViewOverlay');
            const $importOverlay = document.getElementById('cstImportOverlay');
            const overlays = [$formOverlay, $quickOverlay, $viewOverlay, $importOverlay];
            const openOverlay = (el) => { el.style.display = 'flex'; };
            const closeOverlay = (el) => { el.style.display = 'none'; };

            overlays.forEach((ov) => {
                ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeOverlay(ov)));
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') overlays.forEach(closeOverlay);
            });

            const form = document.getElementById('cstForm');
            const fields = {
                full_name: document.getElementById('cstFullName'),
                email: document.getElementById('cstEmail'),
                phone: document.getElementById('cstPhone'),
                gender: document.getElementById('cstGender'),
                date_of_birth: document.getElementById('cstDob'),
                status: document.getElementById('cstStatus'),
                address: document.getElementById('cstAddress'),
            };

            function openForm(mode, c) {
                const isEdit = mode === 'edit';
                form.action = isEdit ? `${URL_BASE}/${c.id}` : URL_STORE;
                document.getElementById('cstFormMethod').value = isEdit ? 'PUT' : 'POST';
                document.getElementById('cstFormTitle').textContent = isEdit ? 'Sửa khách hàng' : 'Thêm khách hàng';
                document.getElementById('cstFormSubmit').textContent = isEdit ? 'Cập nhật' : 'Lưu';

                const d = isEdit ? c : { status: 'active' };
                fields.full_name.value = d.full_name || '';
                fields.email.value = d.email || '';
                fields.phone.value = d.phone || '';
                fields.gender.value = d.gender || '';
                fields.date_of_birth.value = (d.date_of_birth || '').slice(0, 10);
                fields.status.value = d.status || 'active';
                fields.address.value = d.address || '';
                fields.gender.dispatchEvent(new Event('change', { bubbles: true }));
                renderAvatar(d.avatar || '');

                openOverlay($formOverlay);
                setTimeout(() => fields.full_name.focus(), 30);
            }

            let viewing = null;

            function fillView(c) {
                viewing = c;
                const set = (id, val) => { document.getElementById(id).textContent = val; };

                // Ảnh đại diện thật nếu có; ảnh hỏng hoặc không có thì lùi về chữ cái đầu.
                const ava = document.getElementById('vAvatar');
                const initial = (c.full_name || 'K').charAt(0).toUpperCase();
                ava.textContent = initial;
                if (c.avatar) {
                    const img = document.createElement('img');
                    img.alt = '';
                    img.addEventListener('error', () => { ava.textContent = initial; });
                    img.addEventListener('load', () => { ava.textContent = ''; ava.appendChild(img); });
                    img.src = c.avatar;
                }

                set('vName', c.full_name || '—');
                set('vCode', code(c.id));

                const state = document.getElementById('vState');
                state.textContent = STATUSES[c.status] || '—';
                state.classList.toggle('is-on', c.status === 'active');

                set('vOrders', fmtInt(c.total_orders));
                set('vSpent', `${fmtInt(c.total_spent)}₫`);
                set('vLastOrder', c.last_order_at ? fmtDate(c.last_order_at) : '—');

                set('vLoginEmail', c.login_email || c.email || 'Chưa có email');
                const chip = document.getElementById('vVerified');
                chip.textContent = c.email_verified ? 'Đã xác thực' : 'Chưa xác thực';
                chip.classList.toggle('is-ok', !!c.email_verified);

                set('vLastLogin', c.last_login_at ? fmtDateTime(c.last_login_at) : 'Chưa đăng nhập');

                set('vPhone', c.phone || '—');
                set('vGender', GENDERS[c.gender] || '—');
                set('vDob', c.date_of_birth ? fmtDate(c.date_of_birth) : '—');
                set('vCreated', fmtDate(c.created_at));
                set('vAddress', c.address || '—');
            }

            function openView(c) {
                // Hiện ngay dữ liệu đang có để modal không trống, rồi lấy bản mới nhất từ API.
                fillView(c);
                openOverlay($viewOverlay);

                fetch(`${URL_BASE}/${c.id}/detail`, { headers: { 'Accept': 'application/json' } })
                    .then((r) => {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then((d) => {
                        // Chỉ vẽ lại nếu người dùng vẫn đang xem đúng khách hàng đó.
                        if (d && d.data && viewing && viewing.id === c.id) fillView(d.data);
                    })
                    .catch(() => { /* giữ nguyên dữ liệu đã hiển thị nếu API lỗi */ });
            }

            // ---------- Dropdown "Thêm khách hàng" ----------
            const add = document.getElementById('cstAdd');
            document.getElementById('cstAddBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const open = !add.classList.contains('open');
                add.classList.toggle('open', open);
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.getElementById('cstAddFull').addEventListener('click', () => {
                add.classList.remove('open');
                openForm('add', null);
            });
            document.getElementById('cstAddQuick').addEventListener('click', () => {
                add.classList.remove('open');
                resetAccountForm();
                openOverlay($quickOverlay);
                setTimeout(() => comboBtn.focus(), 30);
            });

            // Hiện/ẩn mật khẩu
            document.getElementById('cstQPassToggle').addEventListener('click', () => {
                const input = document.getElementById('cstQPass');
                input.type = input.type === 'password' ? 'text' : 'password';
                input.focus();
            });

            // ---------- Ảnh đại diện: chọn -> upload -> preview, lưu URL vào input ẩn ----------
            const avatarInput = document.getElementById('cstAvatar');
            const imgPreview = document.getElementById('cstImgPreview');
            const imgInput = document.getElementById('cstImgInput');
            const imgRemove = document.getElementById('cstImgRemove');
            const PLACEHOLDER = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';

            function renderAvatar(url) {
                avatarInput.value = url || '';
                imgPreview.innerHTML = url ? `<img src="${esc(url)}" alt="">` : PLACEHOLDER;
                imgRemove.style.display = url ? '' : 'none';
            }

            document.getElementById('cstImgPick').addEventListener('click', () => imgInput.click());
            imgRemove.addEventListener('click', () => { renderAvatar(''); imgInput.value = ''; });
            imgInput.addEventListener('change', async () => {
                const file = imgInput.files && imgInput.files[0];
                if (!file) return;
                if (!file.type.startsWith('image/')) { toastErr('File tải lên không phải ảnh.'); imgInput.value = ''; return; }
                if (file.size > MAX_IMG_BYTES) { toastErr('Ảnh vượt quá 10MB.'); imgInput.value = ''; return; }

                imgPreview.classList.add('is-loading');
                try {
                    const fd = new FormData();
                    fd.append('image', file);
                    const res = await fetch(URL_UPLOAD_AVATAR, {
                        method: 'POST', body: fd,
                        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { toastErr(data.message || 'Tải ảnh thất bại, vui lòng thử lại.'); return; }
                    renderAvatar(data.url);
                } catch (err) {
                    toastErr('Không kết nối được máy chủ để tải ảnh.');
                } finally {
                    imgPreview.classList.remove('is-loading');
                    imgInput.value = '';
                }
            });

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
                const t = new bootstrap.Toast(el, { delay: 4000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            // ---------- Modal nhập file CSV ----------
            document.getElementById('cstImportBtn').addEventListener('click', () => {
                util.classList.remove('open');
                openOverlay($importOverlay);
            });

            // ---------- Ô chọn khách hàng (có tìm kiếm) cho modal cấp tài khoản ----------
            const combo = document.getElementById('cstCombo');
            const comboBtn = document.getElementById('cstComboBtn');
            const comboLabel = document.getElementById('cstComboLabel');
            const comboValue = document.getElementById('cstComboValue');
            const comboSearch = document.getElementById('cstComboSearch');
            const comboList = document.getElementById('cstComboList');
            const accountFields = {
                code: document.getElementById('cstQCode'),
                email: document.getElementById('cstQEmail'),
                phone: document.getElementById('cstQPhone'),
                lastLogin: document.getElementById('cstQLastLogin'),
            };

            function renderComboList(keyword = '') {
                const kw = keyword.trim().toLowerCase();
                const list = ACTIVE_CUSTOMERS.filter((c) => !kw
                    || (c.full_name || '').toLowerCase().includes(kw)
                    || (c.email || '').toLowerCase().includes(kw)
                    || (c.phone || '').toLowerCase().includes(kw));

                if (!list.length) {
                    comboList.innerHTML = `<p class="cst-combo-empty">${ACTIVE_CUSTOMERS.length
                        ? 'Không có khách hàng nào khớp từ khoá.'
                        : 'Chưa có khách hàng nào đang hoạt động.'}</p>`;
                    return;
                }

                const current = comboValue.value;
                comboList.innerHTML = list.map((c) => `
                    <button type="button" class="cst-combo-item ${String(c.id) === current ? 'is-selected' : ''}" data-pick="${c.id}" role="option">
                        <b>${esc(c.full_name || '—')}</b>
                        <small>${esc(code(c.id))} · ${esc(c.email || 'chưa có email')}${c.phone ? ' · ' + esc(c.phone) : ''}</small>
                    </button>`).join('');
            }

            function pickCustomer(c) {
                comboValue.value = c.id;
                comboLabel.textContent = c.full_name || code(c.id);
                comboLabel.classList.remove('is-empty');

                accountFields.code.value = code(c.id);
                accountFields.email.value = c.email || '';
                accountFields.phone.value = c.phone || '';
                accountFields.lastLogin.value = c.last_login_at ? fmtDateTime(c.last_login_at) : 'Chưa đăng nhập lần nào';

                // Khách chưa có email thì không thể đăng nhập -> báo ngay tại chỗ.
                accountFields.email.placeholder = c.email ? '—' : 'Chưa có email — cần bổ sung trước';

                combo.classList.remove('open');
                comboBtn.setAttribute('aria-expanded', 'false');
                document.getElementById('cstQPass').focus();
            }

            function resetAccountForm() {
                document.getElementById('cstQuickForm').reset();
                comboValue.value = '';
                comboLabel.textContent = 'Chọn khách hàng…';
                comboLabel.classList.add('is-empty');
                Object.values(accountFields).forEach((el) => { el.value = ''; });
                accountFields.email.placeholder = '—';
                comboSearch.value = '';
                combo.classList.remove('open');
                renderComboList();
            }

            comboBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = !combo.classList.contains('open');
                combo.classList.toggle('open', open);
                comboBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (open) {
                    renderComboList(comboSearch.value);
                    setTimeout(() => comboSearch.focus(), 20);
                }
            });
            combo.addEventListener('click', (e) => e.stopPropagation());
            comboSearch.addEventListener('input', () => renderComboList(comboSearch.value));
            // Enter trong ô tìm kiếm không được submit form.
            comboSearch.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
            comboList.addEventListener('click', (e) => {
                const item = e.target.closest('[data-pick]');
                if (!item) return;
                const c = ACTIVE_CUSTOMERS.find((x) => String(x.id) === item.getAttribute('data-pick'));
                if (c) pickCustomer(c);
            });

            // Chặn submit khi chưa chọn khách hàng (input ẩn nên trình duyệt không tự báo).
            document.getElementById('cstQuickForm').addEventListener('submit', (e) => {
                if (!comboValue.value) {
                    e.preventDefault();
                    combo.classList.add('open');
                    renderComboList();
                    setTimeout(() => comboSearch.focus(), 20);
                }
            });

            renderComboList();

            document.getElementById('cstViewEdit').addEventListener('click', () => {
                if (!viewing) return;
                closeOverlay($viewOverlay);
                openForm('edit', viewing);
            });

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.cst-table tbody');
            tbody.addEventListener('click', (e) => {
                const tg = e.target.closest('[data-toggle]');
                if (tg) { const c = BY_ID.get(Number(tg.getAttribute('data-toggle'))); if (c) toggleStatus(tg, c); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const c = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (c) removeCustomer(c); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { const c = BY_ID.get(Number(ed.getAttribute('data-edit'))); if (c) openForm('edit', c); return; }
                const vw = e.target.closest('[data-view]');
                if (vw) { const c = BY_ID.get(Number(vw.getAttribute('data-view'))); if (c) openView(c); return; }
            });

            // ---------- Chọn dòng + bulk ----------
            const selected = new Set();
            const checkAll = document.getElementById('cstCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.cst-row-check'));

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
            function renderBulk() {
                const n = selected.size;
                if (n === 0) { $bulkMount.innerHTML = ''; return; }
                $bulkMount.innerHTML = `
                    <div class="cst-bulk">
                        <span class="cst-bulk-text">Đã chọn <b>${n}</b> khách hàng</span>
                        <button type="button" class="cst-bulk-clear" id="cstBulkClear">Bỏ chọn</button>
                        <button type="button" class="cst-bulk-del" id="cstBulkDel">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Xoá (${n})
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

            $bulkMount.addEventListener('click', (e) => {
                if (e.target.closest('#cstBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                } else if (e.target.closest('#cstBulkDel')) {
                    const ids = [...selected];
                    if (!ids.length) return;
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${ids.length} khách hàng đã chọn? Hành động này không thể hoàn tác.`,
                        highlightText: `Số lượng: ${ids.length} khách hàng`
                    }).then((confirmed) => {
                        if (confirmed) {
                            const fields = {};
                            ids.forEach((id, i) => { fields[`ids[${i}]`] = id; });
                            postForm(URL_BULK, 'POST', fields);
                        }
                    });
                }
            });

            // ---------- Dropdown Tiện ích ----------
            const util = document.getElementById('cstUtil');
            document.getElementById('cstUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                util.classList.toggle('open');
            });
            // Bấm ra ngoài -> đóng cả 2 dropdown của toolbar
            document.addEventListener('click', () => {
                util.classList.remove('open');
                add.classList.remove('open');
                document.getElementById('cstAddBtn').setAttribute('aria-expanded', 'false');
            });
        })();
    </script>
@endsection
