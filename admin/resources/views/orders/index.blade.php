@extends('layouts.app')

@section('title', 'Quản lý đơn hàng')

@section('content')
    {{--
    Trang "Quản lý đơn hàng" — cùng khuôn với trang Sản phẩm & Khách hàng:
    [ header ] + [ form lọc ] + [ bảng compact ] + [ chân trang ] + [ modal chi tiết ].
    Đơn do khách đặt ở storefront nên không có thêm/xoá; admin chuyển trạng thái theo
    luồng hợp lệ (backend kiểm tra), cập nhật thanh toán và ghi chú nội bộ.
    --}}
    @php
        $STATUSES = \App\Http\Controllers\OrderController::STATUSES;
        $TONES = \App\Http\Controllers\OrderController::STATUS_TONES;
        $PAY_STATUSES = \App\Http\Controllers\OrderController::PAYMENT_STATUSES;
        $PAY_METHODS = \App\Http\Controllers\OrderController::PAYMENT_METHODS;
        $SORTS = \App\Http\Controllers\OrderController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\OrderController::PAGE_SIZES;

        // Khung nhìn hiện tại. Hiện chỉ còn 'all' — trả hàng đã tách thành module
        // riêng (ReturnController), không còn là một bộ lọc của bảng đơn hàng.
        $VIEWS = \App\Http\Controllers\OrderController::VIEWS;
        $VIEW = $filters['view'] ?? 'all';
        $VIEW_CFG = $VIEWS[$VIEW];
        // Chỉ cho lọc trong phạm vi trạng thái của khung nhìn.
        $STATUS_CHOICES = empty($VIEW_CFG['statuses'])
            ? $STATUSES
            : collect($STATUSES)->only($VIEW_CFG['statuses'])->all();

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['payment_status'] !== 'all'
            || $filters['payment_method'] !== 'all'
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        // Số bộ lọc nâng cao đang bật -> tự mở hàng nâng cao + hiện badge (giống trang Sản phẩm).
        $advCount = ($filters['status'] !== 'all' ? 1 : 0)
            + ($filters['payment_status'] !== 'all' ? 1 : 0)
            + ($filters['payment_method'] !== 'all' ? 1 : 0)
            + ($filters['sort'] !== 'newest' ? 1 : 0);
        $advOpen = $advCount > 0;

        // Khoảng ngày đặt KHÔNG có giá trị mặc định: chưa chọn thì không lọc theo
        // ngày. Xem ghi chú ở ô ẩn from_date/to_date bên dưới.
    @endphp

    <div class="ord">
        {{-- Header --}}
        <div class="ord-head">
            <h1 class="ord-title">{{ $VIEW_CFG['title'] }}</h1>
            <span class="ord-revenue">
                Doanh thu: <b>{{ number_format((float) $stats['revenue'], 0, ',', '.') }}₫</b>
                <em>(không tính đơn huỷ/hoàn)</em>
            </span>
        </div>

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.orders.index') }}" id="ordFilter" class="ord-filter">
            {{-- Giữ khung nhìn đang xem khi lọc/tìm kiếm --}}
            @if($VIEW !== 'all')
                <input type="hidden" name="view" value="{{ $VIEW }}">
            @endif

            {{-- Hàng cơ bản: tìm kiếm + khoảng ngày đặt + nút Nâng cao + Tiện ích --}}
            <div class="ord-toolbar">
                <div class="ord-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="ord-search-input"
                        placeholder="Tìm theo mã đơn, tên hoặc SĐT người nhận" autocomplete="off">
                    <button type="submit" class="ord-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </button>
                </div>

                {{-- Khoảng ngày đặt: bấm mở bảng lịch 2 tháng để quét chọn từ ngày → đến ngày. Mặc định hôm qua → hôm nay.
                --}}
                {{-- Hai ô ẩn gửi ĐÚNG khoảng ngày người dùng đã chọn, rỗng khi chưa
                     chọn gì. Trước đây chúng được đổ sẵn "hôm qua → hôm nay" để bảng
                     lịch có chỗ mở ra, nhưng chúng nằm trong form lọc nên chỉ cần đổi
                     ô Sắp xếp là khoảng ngày đó bị gửi lên theo và mọi đơn cũ hơn hôm
                     qua biến mất — nguy nhất ở khung nhìn "chưa hoàn tất", nơi đơn tồn
                     lâu chính là thứ cần nhìn thấy nhất. Chưa chọn ngày thì bảng lịch
                     tự mở ở tháng hiện tại, không cần giá trị đổ sẵn. --}}
                <div class="ord-daterange" id="ordDateRange">
                    <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}" id="ordFrom">
                    <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}" id="ordTo">
                    <button type="button" class="ord-daterange-trigger" id="ordDateTrigger" aria-haspopup="dialog"
                        aria-expanded="false" title="Lọc theo ngày đặt">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <path d="M16 2v4M8 2v4M3 10h18" />
                        </svg>
                        <span class="ord-daterange-label" id="ordDateLabel">—</span>
                        <svg class="ord-daterange-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div class="ord-cal-pop" id="ordCalPop" role="dialog" aria-label="Chọn khoảng ngày" hidden>
                        <div class="ord-cal-presets" id="ordCalPresets">
                            <button type="button" class="ord-cal-preset" data-preset="today">Hôm nay</button>
                            <button type="button" class="ord-cal-preset" data-preset="yesterday">Hôm qua</button>
                            <button type="button" class="ord-cal-preset" data-preset="last7">7 ngày qua</button>
                            <button type="button" class="ord-cal-preset" data-preset="last30">30 ngày qua</button>
                            <button type="button" class="ord-cal-preset" data-preset="thismonth">Tháng này</button>
                        </div>
                        <div class="ord-cal-main">
                            <div class="ord-cal-months">
                                <div class="ord-cal" data-cal="0"></div>
                                <div class="ord-cal" data-cal="1"></div>
                            </div>
                            <div class="ord-cal-foot">
                                <span class="ord-cal-range" id="ordCalRange">Chọn ngày bắt đầu</span>
                                <div class="ord-cal-foot-btns">
                                    <button type="button" class="ord-btn-ghost ord-cal-btn" id="ordCalClear">Xoá</button>
                                    <button type="button" class="ord-btn-primary ord-cal-btn" id="ordCalApply">Áp
                                        dụng</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" id="ordAdvToggle" class="ord-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                    aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M7 12h10M11 18h2" />
                    </svg>
                    Nâng cao
                    <svg class="ord-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                    @if($advCount > 0)<span class="ord-adv-count">{{ $advCount }}</span>@endif
                </button>

                <div class="ord-toolbar-actions">
                    <button type="button" id="ordAddBtn" class="ord-btn-primary ord-add-btn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M12 5v14" />
                        </svg>
                        Thêm đơn hàng
                    </button>

                    <div class="ord-util" id="ordUtil">
                        <button type="button" class="ord-util-btn" id="ordUtilBtn" aria-haspopup="true"
                            aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1.6" />
                                <circle cx="19" cy="12" r="1.6" />
                                <circle cx="5" cy="12" r="1.6" />
                            </svg>
                            Tiện ích
                            <svg class="ord-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div class="ord-util-menu">
                            <a href="{{ route('admin.orders.export', request()->query()) }}" class="ord-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <path d="M7 10l5 5 5-5" />
                                    <path d="M12 15V3" />
                                </svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Hàng nâng cao: trạng thái + thanh toán + phương thức + sắp xếp (ẩn tới khi bấm "Nâng cao") --}}
            <div class="ord-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="ordAdvRow">
                <select name="status" class="ord-select" title="Lọc theo trạng thái đơn">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        @if($VIEW === 'all')
                            Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                        @else
                            Tất cả trạng thái trong mục
                        @endif
                    </option>
                    @foreach($STATUS_CHOICES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="payment_status" class="ord-select" title="Lọc theo thanh toán">
                    <option value="all" {{ $filters['payment_status'] === 'all' ? 'selected' : '' }}>Tất cả thanh toán
                    </option>
                    @foreach($PAY_STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['payment_status'] === $value ? 'selected' : '' }}>{{ $label }}
                        </option>
                    @endforeach
                </select>

                <select name="payment_method" class="ord-select" title="Lọc theo phương thức">
                    <option value="all" {{ $filters['payment_method'] === 'all' ? 'selected' : '' }}>Tất cả phương thức
                    </option>
                    @foreach($PAY_METHODS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['payment_method'] === $value ? 'selected' : '' }}>{{ $label }}
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="ord-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.orders.index') }}" class="ord-clear">Xoá lọc</a>
                @endif
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}" id="ordPageSizeHidden">
        </form>

        {{-- Thanh thao tác hàng loạt NỔI (hiện khi chọn ≥1 đơn) --}}
        <div class="ord-bulk" id="ordBulkBar" hidden>
            <span class="ord-bulk-count"><b id="ordBulkCount">0</b> đơn đã chọn</span>
            <button type="button" class="ord-bulk-clear" id="ordBulkClear">Bỏ chọn</button>
            <div class="ord-bulk-actions">
                <button type="button" class="ord-btn-ghost" id="ordBulkPrint">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    In đơn
                </button>
                <button type="button" class="ord-btn-ghost" id="ordBulkLabel">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"/><circle cx="7" cy="7" r="1.2"/></svg>
                    In tem
                </button>
                <button type="button" class="ord-btn-ghost" id="ordBulkExport">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Xuất CSV
                </button>
                {{-- Nút chuyển trạng thái: nhãn = trạng thái kế tiếp của các đơn đã chọn.
                     Chỉ hiện khi mọi đơn đã chọn cùng một trạng thái và trạng thái đó có bước tiếp theo. --}}
                <button type="button" class="ord-btn-primary" id="ordBulkAdvance" hidden>
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                    <span id="ordBulkAdvanceLabel">Chuyển trạng thái</span>
                </button>
            </div>
        </div>

        {{-- Form ẩn để gửi chuyển trạng thái hàng loạt --}}
        <form method="POST" action="{{ route('admin.orders.bulkStatus') }}" id="ordBulkForm" class="d-none">
            @csrf
            <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
            <input type="hidden" name="status" id="ordBulkStatus">
            <div id="ordBulkIds"></div>
        </form>

        {{-- Dải "có đơn mới" — chỉ hiện khi KHÔNG tự làm mới được (đang chọn đơn
             hoặc đang mở modal). Bấm vào là nạp lại bảng ngay tại chỗ. --}}
        <button type="button" class="ord-live" id="ordLivePill" hidden>
            <span class="ord-live-dot"></span>
            <span id="ordLiveText">Có đơn hàng mới</span>
            <span class="ord-live-cta">Tải lại bảng</span>
        </button>

        {{-- Bảng --}}
        <div class="ord-table-wrap">
            <table class="ord-table">
                <thead>
                    <tr>
                        <th class="ord-c-check">
                            <input type="checkbox" id="ordCheckAll" class="ord-check" title="Chọn tất cả trong trang">
                        </th>
                        <th class="ord-c-stt">STT</th>
                        <th class="ord-c-code">Mã đơn</th>
                        <th class="ord-c-cus">Người nhận</th>
                        <th class="ord-c-addr">Địa chỉ giao</th>
                        <th class="ord-c-qty">SL</th>
                        <th class="ord-c-total">Tổng tiền</th>
                        <th class="ord-c-pay">Thanh toán</th>
                        <th class="ord-c-status">Trạng thái</th>
                        <th class="ord-c-date">Ngày đặt</th>
                        <th class="ord-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $i => $o)
                        @php
                            $id = $o['id'] ?? 0;
                            $status = $o['status'] ?? 'pending';
                            $payStatus = $o['payment_status'] ?? 'pending';
                            $qty = collect($o['items'] ?? [])->sum('quantity');
                            $addr = \App\Http\Controllers\OrderController::shippingAddress($o);
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="ord-c-check">
                                <input type="checkbox" class="ord-check ord-row-check" value="{{ $id }}"
                                       data-status="{{ $status }}" aria-label="Chọn đơn {{ $o['order_code'] ?? $id }}">
                            </td>
                            <td class="ord-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="ord-c-code" data-view="{{ $id }}" title="Xem chi tiết đơn hàng">
                                <span class="ord-code">{{ $o['order_code'] ?? '—' }}</span>
                            </td>
                            <td class="ord-c-cus" data-view="{{ $id }}" title="Xem chi tiết đơn hàng">
                                <span class="ord-name">{{ $o['recipient_name'] ?? '—' }}</span>
                                <span class="ord-sub">{{ $o['recipient_phone'] ?? '' }}</span>
                            </td>
                            <td class="ord-c-addr" title="{{ $addr }}">{{ $addr !== '' ? $addr : '—' }}</td>
                            <td class="ord-c-qty">{{ number_format($qty, 0, ',', '.') }}</td>
                            <td class="ord-c-total">
                                <span
                                    class="ord-total">{{ number_format((float) ($o['total_amount'] ?? 0), 0, ',', '.') }}₫</span>
                                <span class="ord-sub">{{ $PAY_METHODS[$o['payment_method'] ?? ''] ?? '—' }}</span>
                            </td>
                            <td class="ord-c-pay">
                                <span class="ord-pay is-{{ $payStatus }}">{{ $PAY_STATUSES[$payStatus] ?? '—' }}</span>
                            </td>
                            <td class="ord-c-status">
                                <span
                                    class="ord-badge tone-{{ $TONES[$status] ?? 'info' }}">{{ $STATUSES[$status] ?? '—' }}</span>
                            </td>
                            <td class="ord-c-date">
                                {{ !empty($o['created_at']) ? \Illuminate\Support\Carbon::parse($o['created_at'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="ord-c-act">
                                <button type="button" class="ord-rowbtn ord-view" data-view="{{ $id }}" title="Xem & xử lý đơn">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                    </svg>
                                </button>
                                <a class="ord-rowbtn" href="{{ route('admin.orders.print', $id) }}" target="_blank"
                                    rel="noopener" title="In đơn hàng">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M6 9V3h12v6" />
                                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                                        <path d="M6 14h12v8H6z" />
                                    </svg>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="ord-empty">
                                @if($hasFilter)
                                    Không tìm thấy đơn hàng nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    {{ $VIEW_CFG['empty'] }}
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
            'noun' => 'đơn hàng',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- Modal tạo đơn hàng thủ công (chỉ chọn khách hàng có sẵn) --}}
    <div class="ord-overlay" id="ordCreateOverlay" style="display:none;">
        <div class="ord-dialog">
            <div class="ord-modal-head">
                <h4 class="ord-modal-title" id="ncModalTitle">Tạo đơn hàng thủ công</h4>
                <button type="button" class="ord-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST" action="{{ route('admin.orders.store') }}" id="ordCreateForm">
                @csrf
                {{-- Method spoofing: POST khi tạo đơn, PUT khi sửa đơn --}}
                <input type="hidden" name="_method" id="ncMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="user_id" id="ncUserId">
                {{-- Địa chỉ tỉnh/huyện/xã: giữ nguyên khi sửa đơn để không bị xoá (form chỉ hiện ô địa chỉ chi tiết) --}}
                <input type="hidden" name="shipping_province" id="ncProvince">
                <input type="hidden" name="shipping_district" id="ncDistrict">
                <input type="hidden" name="shipping_ward" id="ncWard">

                <div class="ord-modal-body">
                    {{-- Thông tin đơn: chia đều 3 cột --}}
                    <div class="ord-view-sec">
                        <p class="ord-sec-title">Thông tin đơn</p>
                        <div class="ord-grid-3">
                            {{-- Khách hàng: dropdown, chỉ chọn khách có sẵn (ẩn khi sửa đơn) --}}
                            <div class="ord-field" id="ncCustomerField">
                                <span class="ord-lb">Khách hàng <span class="ord-req">*</span></span>
                                <div class="ord-dd" id="ncCustomerDD">
                                    <button type="button" class="ord-dd-control" id="ncCustomerToggle">
                                        <span class="ord-dd-value is-empty" id="ncCustomerValue">Chọn khách hàng có sẵn</span>
                                        <svg class="ord-dd-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <div class="ord-dd-panel" id="ncCustomerPanel" hidden>
                                        <div class="ord-dd-search">
                                            <input type="text" id="ncCustomerInput" class="ord-input ord-input-sm" autocomplete="off" placeholder="Tìm tên / SĐT / email">
                                        </div>
                                        <div class="ord-dd-list" id="ncCustomerMenu"></div>
                                    </div>
                                </div>
                            </div>

                            <label class="ord-field"><span class="ord-lb">Họ tên người nhận <span class="ord-req">*</span></span>
                                <input type="text" name="recipient_name" id="ncName" class="ord-input" maxlength="100" required></label>
                            <label class="ord-field"><span class="ord-lb">Số điện thoại <span class="ord-req">*</span></span>
                                <input type="text" name="recipient_phone" id="ncPhone" class="ord-input" maxlength="20" required></label>

                            <label class="ord-field"><span class="ord-lb">Email</span>
                                <input type="email" name="recipient_email" id="ncEmail" class="ord-input" maxlength="100"></label>
                            <label class="ord-field"><span class="ord-lb">Đơn vị vận chuyển</span>
                                <input type="text" name="shipping_method" class="ord-input" maxlength="100" list="ordShipList" placeholder="VD: GHN, GHTK"></label>
                            <label class="ord-field"><span class="ord-lb">Phương thức thanh toán <span class="ord-req">*</span></span>
                                <select name="payment_method" id="ncPayMethod" class="ord-input">
                                    @foreach($PAY_METHODS as $val => $label)
                                        <option value="{{ $val }}">{{ $label }}</option>
                                    @endforeach
                                </select></label>

                            <label class="ord-field"><span class="ord-lb">Phí vận chuyển</span>
                                <div class="ord-money">
                                    <input type="text" inputmode="numeric" id="ncShipFee" class="ord-input ord-money-input" value="0" autocomplete="off">
                                    <span class="ord-money-suffix">₫</span>
                                </div>
                                <input type="hidden" name="shipping_fee" id="ncShipFeeRaw" value="0"></label>
                            <label class="ord-field"><span class="ord-lb">Giảm giá</span>
                                <div class="ord-money">
                                    <input type="text" inputmode="numeric" id="ncDiscount" class="ord-input ord-money-input" value="0" autocomplete="off">
                                    <span class="ord-money-suffix">₫</span>
                                </div>
                                <input type="hidden" name="discount_amount" id="ncDiscountRaw" value="0"></label>
                            <label class="ord-field"><span class="ord-lb">Ghi chú</span>
                                <input type="text" name="note" class="ord-input" maxlength="500" placeholder="Tuỳ chọn"></label>

                            <label class="ord-field is-full-3"><span class="ord-lb">Địa chỉ giao hàng <span class="ord-req">*</span></span>
                                <input type="text" name="shipping_address" id="ncAddress" class="ord-input" maxlength="255" required
                                       placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/thành"></label>

                            <datalist id="ordShipList">
                                <option value="GHN"></option>
                                <option value="GHTK"></option>
                                <option value="Viettel Post"></option>
                                <option value="J&amp;T Express"></option>
                                <option value="SPX (Shopee Express)"></option>
                                <option value="Ninja Van"></option>
                            </datalist>
                        </div>
                        <p class="ord-hint" id="ncCustomerHint">Chỉ được chọn khách hàng đã có trong hệ thống.</p>
                    </div>

                    {{-- Sản phẩm: dropdown chọn để thêm --}}
                    <div class="ord-view-sec">
                        <p class="ord-sec-title">Sản phẩm <span class="ord-req">*</span></p>
                        <div class="ord-dd ord-dd-block" id="ncProductDD">
                            <button type="button" class="ord-dd-control" id="ncProductToggle">
                                <span class="ord-dd-value is-empty">Chọn sản phẩm để thêm vào đơn</span>
                                <svg class="ord-dd-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <div class="ord-dd-panel" id="ncProductPanel" hidden>
                                <div class="ord-dd-search">
                                    <input type="text" id="ncProductInput" class="ord-input ord-input-sm" autocomplete="off" placeholder="Tìm sản phẩm theo tên / SKU">
                                </div>
                                <div class="ord-dd-list" id="ncProductMenu"></div>
                            </div>
                        </div>

                        <table class="ord-items ord-nc-items">
                            <thead>
                                <tr>
                                    <th>Sản phẩm</th>
                                    <th class="ord-i-price">Đơn giá</th>
                                    <th class="ord-i-qty">SL</th>
                                    <th class="ord-i-total">Thành tiền</th>
                                    <th class="ord-i-del"></th>
                                </tr>
                            </thead>
                            <tbody id="ncItems"></tbody>
                        </table>
                        <p class="ord-nc-empty" id="ncItemsEmpty">Chưa có sản phẩm nào — chọn ở ô phía trên.</p>

                        <div class="ord-sum ord-nc-sum">
                            <div><span>Tiền hàng</span><b id="ncSubtotal">0₫</b></div>
                            <div><span>Giảm giá</span><b id="ncSumDiscount">0₫</b></div>
                            <div><span>Phí vận chuyển</span><b id="ncSumShip">0₫</b></div>
                            <div class="is-total"><span>Khách phải trả</span><b id="ncTotal">0₫</b></div>
                        </div>
                    </div>
                </div>

                <div class="ord-modal-foot ord-foot-center">
                    <button type="button" class="ord-btn-ghost" data-close>Huỷ</button>
                    <button type="submit" class="ord-btn-primary" id="ncSubmit">Tạo đơn hàng</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal chi tiết & xử lý đơn --}}
    <div class="ord-overlay" id="ordViewOverlay" style="display:none;">
        <div class="ord-dialog">
            <div class="ord-modal-head">
                <h4 class="ord-modal-title">Chi tiết đơn hàng</h4>
                <button type="button" class="ord-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <div class="ord-modal-body">
                {{-- Cảnh báo đơn huỷ / trả hàng (chỉ hiện khi ở trạng thái cuối tiêu cực) --}}
                <div class="ord-alert" id="vAlert" style="display:none;">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 8v4M12 16h.01" />
                    </svg>
                    <div class="ord-alert-body">
                        <b id="vAlertTitle">—</b>
                        <span id="vAlertDesc"></span>
                    </div>
                </div>

                {{-- Đầu: mã đơn + thời điểm đặt + trạng thái + thanh toán --}}
                <div class="ord-view-head">
                    <div class="ord-view-ident">
                        <span class="ord-view-code" id="vCode">—</span>
                        <span class="ord-view-date" id="vPlaced">—</span>
                    </div>
                    <span class="ord-badge" id="vStatus">—</span>
                    <span class="ord-pay" id="vPay">—</span>
                </div>

                {{-- Tiến trình xử lý đơn (đúng luồng backend) --}}
                <ol class="ord-steps" id="vSteps"></ol>

                {{-- Hai cột: người nhận / thanh toán & vận chuyển --}}
                <div class="ord-view-cols">
                    <div class="ord-view-sec">
                        <p class="ord-sec-title">Người nhận &amp; giao hàng</p>
                        <div class="ord-view-grid">
                            <div class="ord-cell"><span class="ord-lb">Họ và tên</span><span class="ord-vl"
                                    id="vName">—</span></div>
                            <div class="ord-cell"><span class="ord-lb">Số điện thoại</span><span class="ord-vl"
                                    id="vPhone">—</span></div>
                            <div class="ord-cell is-full"><span class="ord-lb">Email</span><span class="ord-vl"
                                    id="vEmail">—</span></div>
                            <div class="ord-cell is-full"><span class="ord-lb">Địa chỉ giao hàng</span><span class="ord-vl"
                                    id="vAddress">—</span></div>
                        </div>
                    </div>
                    <div class="ord-view-sec">
                        <p class="ord-sec-title">Thanh toán &amp; vận chuyển</p>
                        <div class="ord-view-grid">
                            <div class="ord-cell"><span class="ord-lb">Phương thức</span><span class="ord-vl"
                                    id="vMethod">—</span></div>
                            <div class="ord-cell"><span class="ord-lb">Tình trạng</span><span class="ord-vl"
                                    id="vPayText">—</span></div>
                            <div class="ord-cell"><span class="ord-lb">Đơn vị vận chuyển</span><span class="ord-vl"
                                    id="vShipMethod">—</span></div>
                            <div class="ord-cell"><span class="ord-lb">Mã vận đơn</span><span class="ord-vl"
                                    id="vTracking">—</span></div>
                            <div class="ord-cell is-full" id="vVoucherWrap"><span class="ord-lb">Mã giảm giá</span><span
                                    class="ord-vl" id="vVoucher">—</span></div>
                        </div>
                    </div>
                </div>

                {{-- Ghi chú của khách (chỉ hiện khi có) --}}
                <div class="ord-view-sec" id="vNoteWrap">
                    <p class="ord-sec-title">Ghi chú của khách</p>
                    <p class="ord-note-box" id="vNote">—</p>
                </div>

                {{-- Sản phẩm --}}
                <div class="ord-view-sec">
                    <p class="ord-sec-title">Sản phẩm</p>
                    <table class="ord-items">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th class="ord-i-price">Đơn giá</th>
                                <th class="ord-i-qty">SL</th>
                                <th class="ord-i-total">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody id="vItems"></tbody>
                    </table>

                    <div class="ord-sum">
                        <div><span>Tiền hàng</span><b id="vSubtotal">—</b></div>
                        <div><span>Giảm giá</span><b id="vDiscount">—</b></div>
                        <div><span>Phí vận chuyển</span><b id="vShip">—</b></div>
                        <div class="is-total"><span>Khách phải trả</span><b id="vTotal">—</b></div>
                    </div>
                </div>

                {{-- Lịch sử trạng thái --}}
                <div class="ord-view-sec">
                    <p class="ord-sec-title">Lịch sử xử lý</p>
                    <ol class="ord-timeline" id="vHistory"></ol>
                </div>

                {{-- Ghi chú nội bộ --}}
                <div class="ord-view-sec">
                    <p class="ord-sec-title">Ghi chú nội bộ</p>
                    <form method="POST" id="ordNoteForm" class="ord-note-form">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                        <textarea name="admin_note" id="vAdminNote" class="ord-textarea" rows="2"
                            placeholder="Ghi chú cho nhân viên xử lý (khách hàng không thấy)"></textarea>
                        <button type="submit" class="ord-btn-ghost">Lưu ghi chú</button>
                    </form>
                </div>
            </div>

            <div class="ord-modal-foot">
                <div class="ord-actions" id="vActions"></div>
                <div class="ord-foot-right">
                    <button type="button" class="ord-btn-ghost" id="vEditBtn" hidden>
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        Sửa đơn
                    </button>
                    <a class="ord-btn-ghost" id="vLabelBtn" href="#" target="_blank" rel="noopener">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41 13.42 20.6a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"/><circle cx="7" cy="7" r="1.2"/></svg>
                        In tem
                    </a>
                    <a class="ord-btn-ghost" id="vPrintBtn" href="#" target="_blank" rel="noopener">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        In đơn
                    </a>
                    <button type="button" class="ord-btn-ghost" data-close>Đóng</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal nhập lý do (dùng chung cho Huỷ đơn & Trả hàng) --}}
    <div class="ord-overlay" id="ordReasonOverlay" style="display:none;">
        <div class="ord-dialog ord-dialog-sm">
            <div class="ord-modal-head">
                <h4 class="ord-modal-title" id="vReasonTitle">Huỷ đơn hàng</h4>
                <button type="button" class="ord-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <form method="POST" id="ordReasonForm">
                @csrf
                @method('PUT')
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="status" id="vReasonStatus" value="cancelled">
                <div class="ord-modal-body">
                    <p class="ord-note-box" id="vReasonDesc">Đơn <b id="vReasonCode">—</b> sẽ chuyển sang <b>Đã huỷ</b> và
                        không thể quay lại trạng thái trước.</p>
                    <div>
                        <label class="ord-lb" for="vReasonInput" id="vReasonLabel">Lý do huỷ <span
                                class="ord-req">*</span></label>
                        <textarea name="note" id="vReasonInput" class="ord-textarea" rows="3" maxlength="255"
                            placeholder="VD: Khách yêu cầu huỷ / Hết hàng / Không liên hệ được"></textarea>
                    </div>
                </div>
                <div class="ord-modal-foot">
                    <div class="ord-foot-right">
                        <button type="button" class="ord-btn-ghost" data-close>Đóng</button>
                        <button type="submit" class="ord-btn-danger" id="vReasonSubmit">Xác nhận huỷ</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Form ẩn cho các thao tác chuyển trạng thái / thanh toán --}}
    <form id="ordActionForm" method="POST" class="d-none">
        @csrf
        @method('PUT')
        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
        <input type="hidden" name="status" id="ordActionStatus">
        <input type="hidden" name="payment_status" id="ordActionPayment">
    </form>

    <style>
        .ord {
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626;
            background: #fff;
        }

        /* Header */
        .ord-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 20px;
        }

        .ord-title {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #262626;
            line-height: 34px;
        }

        .ord-revenue {
            font-size: 13px;
            color: #595959;
        }

        .ord-revenue b {
            color: #262626;
        }

        .ord-revenue em {
            font-style: normal;
            font-size: 11px;
            color: #bfbfbf;
        }

        /* Bộ lọc */
        .ord-filter {
            padding: 0 20px 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid #eee;
        }

        .ord-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }

        .ord-toolbar-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ord-searchbox {
            display: flex;
            border-radius: 4px;
        }

        .ord-searchbox:focus-within {
            box-shadow: 0 0 0 4px rgba(13, 110, 253, .25);
        }

        .ord-search-input {
            height: 34px;
            width: 280px;
            border: 1px solid #d9d9d9;
            border-right: 0;
            border-radius: 4px 0 0 4px;
            background: #fff;
            padding: 0 12px;
            font-size: 13px;
            outline: none;
            transition: border-color .15s;
        }

        .ord-search-input::placeholder {
            color: #bfbfbf;
        }

        .ord-searchbox:focus-within .ord-search-input,
        .ord-searchbox:focus-within .ord-search-btn {
            border-color: #86b7fe;
        }

        .ord-search-btn {
            height: 34px;
            width: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d9d9;
            border-radius: 0 4px 4px 0;
            background: #fafafa;
            color: #595959;
            cursor: pointer;
            transition: color .15s;
        }

        .ord-search-btn:hover {
            color: #1890ff;
        }

        .ord-select {
            height: 34px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background-color: #fff;
            padding: 0 30px 0 12px;
            font-size: 13px;
            color: #262626;
            cursor: pointer;
            outline: none;
            transition: border-color .15s;
            max-width: 220px;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat;
            background-position: right 8px center;
        }

        /* Khoảng ngày đặt: nút mở + bảng lịch 2 tháng */
        .ord-daterange {
            position: relative;
            display: inline-flex;
        }

        .ord-daterange-trigger {
            height: 34px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #fff;
            font-size: 13px;
            color: #262626;
            cursor: pointer;
            transition: border-color .15s, color .15s;
        }

        .ord-daterange-trigger:hover,
        .ord-daterange.open .ord-daterange-trigger {
            border-color: #1890ff;
            color: #1890ff;
        }

        .ord-daterange-trigger>svg:first-child {
            color: #8c8c8c;
            flex-shrink: 0;
        }

        .ord-daterange-trigger:hover>svg:first-child,
        .ord-daterange.open .ord-daterange-trigger>svg:first-child {
            color: #1890ff;
        }

        .ord-daterange-label {
            white-space: nowrap;
        }

        .ord-daterange-caret {
            color: #8c8c8c;
            transition: transform .2s;
        }

        .ord-daterange.open .ord-daterange-caret {
            transform: rotate(180deg);
        }

        .ord-cal-pop {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            z-index: 1060;
            display: flex;
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .14);
            overflow: hidden;
            transform-origin: top left;
            animation: ordCalIn .14s ease;
        }

        @keyframes ordCalIn {
            from {
                opacity: 0;
                transform: translateY(-4px) scale(.98);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .ord-cal-pop[hidden] {
            display: none;
        }

        .ord-cal-presets {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 10px;
            border-right: 1px solid #f0f0f0;
            background: #fafafa;
            min-width: 132px;
        }

        .ord-cal-preset {
            text-align: left;
            border: 0;
            background: none;
            border-radius: 4px;
            padding: 8px 10px;
            font-size: 13px;
            color: #595959;
            cursor: pointer;
            transition: background .15s, color .15s;
        }

        .ord-cal-preset:hover,
        .ord-cal-preset.is-active {
            background: #e6f7ff;
            color: #1890ff;
        }

        .ord-cal-main {
            display: flex;
            flex-direction: column;
        }

        .ord-cal-months {
            display: flex;
            gap: 8px;
            padding: 12px;
        }

        .ord-cal {
            width: 244px;
        }

        .ord-cal-hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .ord-cal-title {
            font-size: 13px;
            font-weight: 700;
            color: #262626;
        }

        .ord-cal-nav {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #fff;
            color: #595959;
            cursor: pointer;
            transition: all .15s;
        }

        .ord-cal-nav:hover {
            border-color: #1890ff;
            color: #1890ff;
        }

        .ord-cal-nav[disabled] {
            visibility: hidden;
        }

        .ord-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
        }

        .ord-cal-dow {
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: #bfbfbf;
            padding: 4px 0;
        }

        .ord-cal-day {
            height: 32px;
            border: 0;
            background: none;
            border-radius: 4px;
            font-size: 12px;
            color: #262626;
            cursor: pointer;
            transition: background .12s, color .12s;
            position: relative;
        }

        .ord-cal-day.is-out {
            color: #d9d9d9;
        }

        .ord-cal-day:hover:not(.is-empty) {
            background: #e6f7ff;
        }

        .ord-cal-day.is-empty {
            cursor: default;
        }

        .ord-cal-day.in-range {
            background: #e6f7ff;
            border-radius: 0;
        }

        .ord-cal-day.is-start,
        .ord-cal-day.is-end {
            background: #1890ff;
            color: #fff;
        }

        .ord-cal-day.is-start {
            border-radius: 4px 0 0 4px;
        }

        .ord-cal-day.is-end {
            border-radius: 0 4px 4px 0;
        }

        .ord-cal-day.is-start.is-end {
            border-radius: 4px;
        }

        .ord-cal-day.is-today::after {
            content: '';
            position: absolute;
            left: 50%;
            bottom: 4px;
            transform: translateX(-50%);
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: #1890ff;
        }

        .ord-cal-day.is-start.is-today::after,
        .ord-cal-day.is-end.is-today::after {
            background: #fff;
        }

        .ord-cal-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border-top: 1px solid #f0f0f0;
        }

        .ord-cal-range {
            font-size: 13px;
            color: #595959;
        }

        .ord-cal-foot-btns {
            display: flex;
            gap: 8px;
        }

        .ord-cal-btn {
            height: 30px;
            padding: 0 14px;
        }

        @media (max-width: 720px) {
            .ord-cal-pop {
                flex-direction: column;
            }

            .ord-cal-presets {
                flex-direction: row;
                flex-wrap: wrap;
                border-right: 0;
                border-bottom: 1px solid #f0f0f0;
                min-width: 0;
            }

            .ord-cal-months {
                flex-direction: column;
            }
        }

        .ord-clear {
            display: inline-flex;
            align-items: center;
            height: 34px;
            padding: 0 10px;
            border-radius: 4px;
            font-size: 13px;
            color: #8c8c8c;
            text-decoration: none;
            transition: background .15s, color .15s;
        }

        .ord-clear:hover {
            background: #fff1f0;
            color: #ff4d4f;
        }

        /* Hàng bộ lọc nâng cao (ẩn tới khi bấm "Nâng cao") */
        .ord-toolbar-adv {
            display: none;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
        }

        .ord-toolbar-adv.is-open {
            display: flex;
        }

        /* Nút "Nâng cao" */
        .ord-adv-btn {
            height: 34px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #fff;
            padding: 0 12px;
            font-size: 13px;
            color: #595959;
            cursor: pointer;
            transition: border-color .15s, color .15s;
        }

        .ord-adv-btn:hover,
        .ord-adv-btn.is-open {
            border-color: #1890ff;
            color: #1890ff;
        }

        .ord-adv-caret {
            transition: transform .2s;
        }

        .ord-adv-btn.is-open .ord-adv-caret {
            transform: rotate(180deg);
        }

        .ord-adv-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 9999px;
            background: #1890ff;
            color: #fff;
            font-size: 11px;
            font-weight: 600;
        }

        /* Dropdown Tiện ích */
        .ord-util {
            position: relative;
        }

        .ord-util-btn {
            height: 34px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            background: #fff;
            padding: 0 12px;
            font-size: 13px;
            color: #595959;
            cursor: pointer;
            transition: border-color .15s, color .15s;
        }

        .ord-util-btn:hover,
        .ord-util.open .ord-util-btn {
            border-color: #1890ff;
            color: #1890ff;
        }

        .ord-util-caret {
            transition: transform .2s;
        }

        .ord-util.open .ord-util-caret {
            transform: rotate(180deg);
        }

        .ord-util-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 4px);
            min-width: 190px;
            z-index: 1050;
            background: #fff;
            border: 1px solid #e6e6e6;
            border-radius: 6px;
            padding: 4px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, .12);
            display: none;
        }

        .ord-util.open .ord-util-menu {
            display: block;
        }

        .ord-util-item {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 8px 10px;
            border: 0;
            background: none;
            border-radius: 4px;
            font-size: 13px;
            color: #262626;
            text-decoration: none;
            cursor: pointer;
        }

        .ord-util-item:hover {
            background: #f5f7fa;
            color: #1890ff;
        }

        .ord-util-item svg {
            color: #8c8c8c;
            flex-shrink: 0;
        }

        .ord-util-item:hover svg {
            color: #1890ff;
        }

        /* Bảng */
        .ord-table-wrap {
            width: 100%;
            overflow-x: auto;
            padding: 0 20px;
            scrollbar-width: thin;
        }

        .ord-table-wrap::-webkit-scrollbar {
            height: 11px;
        }

        .ord-table-wrap::-webkit-scrollbar-thumb {
            background-color: #dcdcdc;
            border-radius: 8px;
            border: 3px solid #fff;
        }

        .ord-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ord-table thead tr {
            background: #f0f0f0;
            color: #262626;
        }

        .ord-table th,
        .ord-table td {
            padding: 13px 20px;
            vertical-align: middle;
            white-space: nowrap;
        }

        .ord-table th {
            font-weight: 700;
            text-align: left;
        }

        .ord-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
        }

        .ord-table tbody tr:hover {
            background: #fafafa;
        }

        .ord-table th.ord-c-stt,
        .ord-table td.ord-c-stt {
            width: 1%;
            text-align: center;
            color: #8c8c8c;
        }

        .ord-table th.ord-c-code,
        .ord-table td.ord-c-code {
            width: 1%;
        }

        .ord-table th.ord-c-cus,
        .ord-table td.ord-c-cus {
            width: 1%;
            min-width: 170px;
        }

        /* Cột "Địa chỉ" co giãn hút toàn bộ khoảng dư -> bảng phủ đều hết chiều ngang */
        .ord-table th.ord-c-addr,
        .ord-table td.ord-c-addr {
            width: 100%;
            max-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #595959;
        }

        .ord-table th.ord-c-qty,
        .ord-table td.ord-c-qty {
            width: 1%;
            text-align: center;
        }

        .ord-table th.ord-c-total,
        .ord-table td.ord-c-total {
            width: 1%;
        }

        .ord-table th.ord-c-pay,
        .ord-table td.ord-c-pay {
            width: 1%;
            text-align: center;
        }

        .ord-table th.ord-c-status,
        .ord-table td.ord-c-status {
            width: 1%;
            text-align: center;
        }

        .ord-table th.ord-c-date,
        .ord-table td.ord-c-date {
            width: 1%;
            color: #8c8c8c;
        }

        .ord-table th.ord-c-act,
        .ord-table td.ord-c-act {
            width: 1%;
            text-align: center;
        }

        .ord-c-code[data-view],
        .ord-c-cus[data-view] {
            cursor: pointer;
        }

        .ord-c-code[data-view]:hover .ord-code,
        .ord-c-cus[data-view]:hover .ord-name {
            color: #1890ff;
            text-decoration: underline;
        }

        .ord-code {
            font-weight: 600;
            color: #262626;
            letter-spacing: .2px;
        }

        .ord-name {
            display: block;
            font-weight: 500;
            color: #262626;
        }

        .ord-sub {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            color: #8c8c8c;
        }

        .ord-total {
            display: block;
            font-weight: 600;
            color: #262626;
        }

        /* Nhãn trạng thái & thanh toán */
        .ord-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid #d9d9d9;
            color: #595959;
            background: #fafafa;
            white-space: nowrap;
        }

        .ord-badge.tone-wait {
            border-color: #ffd591;
            color: #d46b08;
            background: #fff7e6;
        }

        .ord-badge.tone-info {
            border-color: #91d5ff;
            color: #096dd9;
            background: #e6f7ff;
        }

        .ord-badge.tone-move {
            border-color: #b37feb;
            color: #531dab;
            background: #f9f0ff;
        }

        .ord-badge.tone-done {
            border-color: #b7eb8f;
            color: #389e0d;
            background: #f6ffed;
        }

        .ord-badge.tone-stop {
            border-color: #ffa39e;
            color: #cf1322;
            background: #fff1f0;
        }

        .ord-pay {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            border: 1px solid #d9d9d9;
            color: #595959;
            background: #fafafa;
        }

        .ord-pay.is-paid {
            border-color: #b7eb8f;
            color: #389e0d;
            background: #f6ffed;
        }

        .ord-pay.is-pending {
            border-color: #ffd591;
            color: #d46b08;
            background: #fff7e6;
        }

        .ord-pay.is-failed {
            border-color: #ffa39e;
            color: #cf1322;
            background: #fff1f0;
        }

        .ord-pay.is-refunded {
            border-color: #91d5ff;
            color: #096dd9;
            background: #e6f7ff;
        }

        .ord-rowbtn {
            width: 30px;
            height: 30px;
            border: 0;
            background: none;
            border-radius: 4px;
            padding: 0;
            cursor: pointer;
            color: #1890ff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: background .15s;
        }

        .ord-rowbtn:hover {
            background: #e6f7ff;
        }

        .ord-empty {
            padding: 48px 12px;
            text-align: center;
            color: #8c8c8c;
        }

        /* Chân trang */
        /* Modal */
        .ord-overlay {
            position: fixed;
            inset: 0;
            z-index: 1080;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: rgba(0, 0, 0, .4);
        }

        .ord-dialog {
            max-height: 92vh;
            width: 100%;
            max-width: 720px;
            overflow-y: auto;
            border-radius: 6px;
            background: #fff;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .2);
            scrollbar-width: thin;
        }

        .ord-dialog::-webkit-scrollbar {
            width: 11px;
        }

        .ord-dialog::-webkit-scrollbar-thumb {
            background-color: #dcdcdc;
            border-radius: 8px;
            border: 3px solid #fff;
        }

        .ord-dialog-sm {
            max-width: 440px;
        }

        .ord-modal-head {
            position: sticky;
            top: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
            padding: 12px 20px;
        }

        .ord-modal-title {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: #262626;
        }

        .ord-modal-x {
            border: 0;
            background: none;
            padding: 0;
            color: #8c8c8c;
            cursor: pointer;
            display: inline-flex;
        }

        .ord-modal-x:hover {
            color: #262626;
        }

        .ord-modal-body {
            padding: 16px 20px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .ord-modal-foot {
            position: sticky;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            border-top: 1px solid #f0f0f0;
            background: #fff;
            padding: 12px 20px;
            flex-wrap: wrap;
        }

        .ord-foot-right {
            margin-left: auto;
            display: flex;
            gap: 8px;
        }

        /* Footer canh giữa (modal tạo đơn) — giống modal các trang khác */
        .ord-modal-foot.ord-foot-center {
            justify-content: center;
            gap: 8px;
        }

        .ord-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .ord-actions-note {
            font-size: 12px;
            color: #bfbfbf;
        }

        .ord-opt {
            font-weight: 400;
            text-transform: none;
            letter-spacing: 0;
            color: #bfbfbf;
        }

        .ord-btn-ghost {
            height: 34px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            padding: 0 16px;
            font-size: 13px;
            font-weight: 500;
            color: #595959;
            background: #fff;
            cursor: pointer;
            transition: border-color .15s;
        }

        /* Nút dạng link (In đơn) — canh giữa như button, bỏ gạch chân */
        a.ord-btn-ghost,
        a.ord-rowbtn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        /* ----- Chọn hàng loạt ----- */
        .ord-c-check { width: 38px; text-align: center; }
        .ord-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; margin: 0; }

        /* Thanh bulk NỔI — đồng bộ với trang Sản phẩm/Khách hàng (.prd-bulk/.cst-bulk):
           pill trắng cố định giữa vùng nội dung, bù chiều rộng sidebar. */
        /* Dải "có đơn mới" — nằm ngay trên bảng, cùng ngôn ngữ pill với thanh
           thao tác hàng loạt bên dưới nhưng ở trong luồng trang (không nổi), vì
           nó nói về chính bảng ngay dưới nó. */
        .ord-live {
            display: flex; align-items: center; gap: 8px; width: 100%;
            margin-bottom: 10px; padding: 9px 14px;
            border: 1px solid #bae0ff; border-radius: 9999px; background: #f0f8ff;
            font-size: 13px; color: #0958d9; cursor: pointer; text-align: left;
            transition: background .15s, border-color .15s;
        }
        .ord-live:hover { background: #e6f4ff; border-color: #91caff; }
        .ord-live-dot {
            width: 8px; height: 8px; border-radius: 9999px; background: #1890ff; flex-shrink: 0;
            animation: ordLivePulse 1.6s ease-in-out infinite;
        }
        @keyframes ordLivePulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
        @media (prefers-reduced-motion: reduce) { .ord-live-dot { animation: none; } }
        .ord-live-cta { margin-left: auto; font-weight: 600; text-decoration: underline; white-space: nowrap; }

        .ord-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .ord-bulk { left: 48px; }
        @media (max-width: 820px) { .ord-bulk { left: 0; } }
        .ord-bulk-count { font-size: 13px; font-weight: 500; color: #262626; white-space: nowrap; }
        .ord-bulk-count b { color: #1890ff; }
        .ord-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .ord-bulk-clear:hover { color: #262626; }
        .ord-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        /* Nút có icon + chữ: canh giữa, khoảng cách nhỏ, icon không co lại */
        .ord-foot-right .ord-btn-ghost,
        .ord-bulk-actions .ord-btn-ghost,
        .ord-bulk-actions .ord-btn-primary { display: inline-flex; align-items: center; gap: 6px; }
        .ord-foot-right svg,
        .ord-bulk-actions svg { flex-shrink: 0; }

        .ord-btn-ghost:hover {
            border-color: #bfbfbf;
        }

        .ord-btn-primary {
            height: 34px;
            border: 0;
            border-radius: 4px;
            padding: 0 16px;
            font-size: 13px;
            font-weight: 500;
            color: #fff;
            background: #1890ff;
            cursor: pointer;
            transition: background .15s;
        }

        .ord-btn-primary:hover {
            background: #40a9ff;
        }

        .ord-btn-danger {
            height: 34px;
            border: 0;
            border-radius: 4px;
            padding: 0 16px;
            font-size: 13px;
            font-weight: 500;
            color: #fff;
            background: #ff4d4f;
            cursor: pointer;
            transition: background .15s;
        }

        .ord-btn-danger:hover {
            background: #ff7875;
        }

        /* Nội dung modal chi tiết */
        .ord-view-head {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .ord-view-ident {
            display: flex;
            flex-direction: column;
            gap: 2px;
            margin-right: auto;
        }

        .ord-view-code {
            font-size: 15px;
            font-weight: 700;
            color: #262626;
        }

        .ord-view-date {
            font-size: 12px;
            color: #8c8c8c;
        }

        /* Cảnh báo huỷ / trả hàng */
        .ord-alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 6px;
            border: 1px solid #ffccc7;
            background: #fff2f0;
            color: #cf1322;
        }

        .ord-alert svg {
            flex-shrink: 0;
            margin-top: 1px;
        }

        .ord-alert-body {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .ord-alert-body b {
            font-size: 13px;
        }

        .ord-alert-body span {
            font-size: 12px;
            color: #a8181a;
        }

        /* Stepper tiến trình xử lý đơn */
        .ord-steps {
            list-style: none;
            margin: 0;
            padding: 4px 0 2px;
            display: flex;
        }

        .ord-step {
            position: relative;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            text-align: center;
            min-width: 0;
        }

        .ord-step::before,
        .ord-step::after {
            content: '';
            position: absolute;
            top: 13px;
            height: 2px;
            background: #e8e8e8;
        }

        .ord-step::before {
            left: 0;
            right: 50%;
        }

        .ord-step::after {
            left: 50%;
            right: 0;
        }

        .ord-step:first-child::before,
        .ord-step:last-child::after {
            display: none;
        }

        .ord-step-dot {
            position: relative;
            z-index: 1;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            border: 2px solid #e8e8e8;
            color: #bfbfbf;
            font-size: 12px;
            font-weight: 700;
        }

        .ord-step-label {
            font-size: 11px;
            line-height: 1.3;
            color: #8c8c8c;
        }

        .ord-step-time {
            font-size: 10px;
            color: #bfbfbf;
        }

        /* đã qua */
        .ord-step.is-done .ord-step-dot {
            background: #52c41a;
            border-color: #52c41a;
            color: #fff;
        }

        .ord-step.is-done::before,
        .ord-step.is-done::after {
            background: #52c41a;
        }

        .ord-step.is-done .ord-step-label {
            color: #595959;
        }

        /* hiện tại */
        .ord-step.is-current .ord-step-dot {
            background: #1890ff;
            border-color: #1890ff;
            color: #fff;
            box-shadow: 0 0 0 4px #e6f7ff;
        }

        .ord-step.is-current::before {
            background: #52c41a;
        }

        .ord-step.is-current .ord-step-label {
            color: #1890ff;
            font-weight: 600;
        }

        /* nhánh kết thúc tiêu cực (huỷ/trả) */
        .ord-step.is-stop .ord-step-dot {
            background: #ff4d4f;
            border-color: #ff4d4f;
            color: #fff;
            box-shadow: 0 0 0 4px #fff1f0;
        }

        .ord-step.is-stop::before {
            background: #ff4d4f;
        }

        .ord-step.is-stop .ord-step-label {
            color: #cf1322;
            font-weight: 600;
        }

        .ord-steps.is-stopped .ord-step:not(.is-done):not(.is-stop) {
            opacity: .5;
        }

        /* Hai cột thông tin */
        .ord-view-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .ord-view-cols .ord-view-grid {
            grid-template-columns: 1fr 1fr;
        }

        @media (max-width: 640px) {
            .ord-view-cols {
                grid-template-columns: 1fr;
            }
        }

        .ord-view-sec {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .ord-sec-title {
            margin: 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #8c8c8c;
        }

        .ord-view-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
        }

        .ord-cell {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }

        .ord-cell.is-full {
            grid-column: span 2;
        }

        .ord-lb {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #8c8c8c;
        }

        .ord-vl {
            font-size: 13px;
            color: #262626;
            word-break: break-word;
        }

        .ord-req {
            color: #ff4d4f;
        }

        /* Bảng sản phẩm trong đơn */
        .ord-items {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .ord-items thead tr {
            background: #fafafa;
        }

        .ord-items th,
        .ord-items td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .ord-items th {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #8c8c8c;
        }

        .ord-items th.ord-i-price,
        .ord-items td.ord-i-price,
        .ord-items th.ord-i-total,
        .ord-items td.ord-i-total {
            text-align: right;
            white-space: nowrap;
        }

        .ord-items th.ord-i-qty,
        .ord-items td.ord-i-qty {
            text-align: center;
            width: 1%;
        }

        .ord-item-name {
            display: block;
            font-weight: 500;
            color: #262626;
        }

        .ord-item-sub {
            display: block;
            margin-top: 2px;
            font-size: 11px;
            color: #8c8c8c;
        }

        .ord-sum {
            display: flex;
            flex-direction: column;
            gap: 6px;
            align-items: flex-end;
            font-size: 13px;
        }

        .ord-sum>div {
            display: flex;
            gap: 24px;
            justify-content: space-between;
            min-width: 240px;
        }

        .ord-sum span {
            color: #8c8c8c;
        }

        .ord-sum b {
            color: #262626;
        }

        .ord-sum .is-total {
            padding-top: 6px;
            border-top: 1px solid #f0f0f0;
            font-size: 14px;
        }

        .ord-sum .is-total b {
            color: #cf1322;
        }

        /* Lịch sử trạng thái */
        .ord-timeline {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .ord-timeline li {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            font-size: 13px;
        }

        .ord-tl-dot {
            width: 8px;
            height: 8px;
            margin-top: 5px;
            border-radius: 50%;
            background: #1890ff;
            flex-shrink: 0;
        }

        .ord-tl-body {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .ord-tl-time {
            font-size: 11px;
            color: #bfbfbf;
        }

        .ord-tl-note {
            font-size: 12px;
            color: #8c8c8c;
        }

        .ord-timeline-empty {
            font-size: 12px;
            color: #bfbfbf;
        }

        .ord-note-form {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .ord-textarea {
            flex: 1;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 13px;
            font-family: inherit;
            color: #262626;
            outline: none;
            resize: vertical;
            line-height: 1.5;
        }

        .ord-textarea::placeholder {
            color: #bfbfbf;
        }

        /* ===== Nút "Thêm đơn hàng" + Modal tạo đơn ===== */
        .ord-add-btn { display: inline-flex; align-items: center; gap: 6px; }
        .ord-add-btn svg { flex-shrink: 0; }

        .ord-input {
            width: 100%; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px;
            font-size: 13px; font-family: inherit; color: #262626; background: #fff; outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .ord-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 3px rgba(13, 110, 253, .15); }
        .ord-input::placeholder { color: #bfbfbf; }
        .ord-input-sm { height: 30px; padding: 0 8px; font-size: 12px; }

        .ord-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .ord-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .ord-field.is-full { grid-column: span 2; }

        .ord-ac-item { display: flex; flex-direction: column; gap: 3px; padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f5f5f5; }
        .ord-ac-item:last-child { border-bottom: 0; }
        .ord-ac-item:hover { background: #f5f7fa; }
        .ord-ac-item b { font-size: 13px; color: #262626; font-weight: 600; }
        .ord-ac-item > span { font-size: 12px; color: #8c8c8c; }
        .ord-ac-empty, .ord-ac-loading { padding: 10px 12px; font-size: 12px; color: #bfbfbf; }
        .ord-ac-price { color: #cf1322; font-weight: 600; }
        .ord-ac-variants { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 2px; }
        .ord-ac-variant {
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 3px 8px; font-size: 12px; color: #262626;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .ord-ac-variant:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .ord-ac-variant[disabled] { opacity: .5; cursor: not-allowed; }

        .ord-hint { margin: 8px 0 0; font-size: 12px; color: #bfbfbf; }

        .ord-nc-items { margin-top: 12px; }
        .ord-nc-items th.ord-i-del, .ord-nc-items td.ord-i-del { width: 1%; text-align: center; }
        .ord-nc-custom { display: flex; gap: 6px; margin-top: 6px; }
        .ord-nc-custom .ord-input-sm { width: 120px; }
        .ord-qty { width: 64px; text-align: center; }
        .ord-nc-empty { margin: 10px 0 0; font-size: 13px; color: #bfbfbf; text-align: center; }
        .ord-nc-del { color: #ff4d4f; }
        .ord-nc-del:hover { background: #fff1f0; }
        .ord-nc-sum { margin-top: 14px; }

        /* Lưới 3 cột đều nhau */
        .ord-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px 16px; align-items: start; }
        .ord-grid-3 .is-full-3 { grid-column: 1 / -1; }

        /* Dropdown chọn (khách hàng / sản phẩm) — kiểu combobox */
        .ord-dd { position: relative; }
        .ord-dd.ord-dd-block { max-width: 420px; }
        .ord-dd-control {
            width: 100%; height: 34px; display: inline-flex; align-items: center; justify-content: space-between; gap: 8px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 0 10px 0 12px;
            font-size: 13px; color: #262626; cursor: pointer; transition: border-color .15s;
        }
        .ord-dd-control:hover, .ord-dd.open .ord-dd-control { border-color: #1890ff; }
        .ord-dd-value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left; }
        .ord-dd-value.is-empty { color: #bfbfbf; }
        .ord-dd-caret { color: #8c8c8c; flex-shrink: 0; transition: transform .2s; }
        .ord-dd.open .ord-dd-caret { transform: rotate(180deg); }
        .ord-dd-panel {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30; background: #fff;
            border: 1px solid #e6e6e6; border-radius: 6px; box-shadow: 0 8px 24px rgba(0, 0, 0, .12); overflow: hidden;
        }
        .ord-dd-panel[hidden] { display: none; }
        .ord-dd-search { padding: 8px; border-bottom: 1px solid #f0f0f0; }
        .ord-dd-list { max-height: 240px; overflow-y: auto; }

        /* Ô nhập tiền tệ VN */
        .ord-money { position: relative; display: flex; align-items: center; }
        .ord-money-input { padding-right: 24px; text-align: right; }
        .ord-money-suffix { position: absolute; right: 10px; color: #8c8c8c; font-size: 13px; pointer-events: none; }

        @media (max-width: 860px) { .ord-grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 560px) { .ord-grid-3 { grid-template-columns: 1fr; } }

        @media (max-width: 640px) {
            .ord-form-grid { grid-template-columns: 1fr; }
            .ord-field.is-full { grid-column: span 1; }
        }

        .ord-note-box {
            margin: 0;
            padding: 10px 12px;
            border: 1px solid #ffe58f;
            border-radius: 4px;
            background: #fffbe6;
            font-size: 12px;
            line-height: 1.55;
            color: #595959;
        }

        @media (max-width: 640px) {
            .ord-view-grid {
                grid-template-columns: 1fr;
            }

            .ord-cell.is-full {
                grid-column: span 1;
            }

            .ord-search-input {
                width: 100%;
            }

            .ord-searchbox {
                flex: 1;
            }
        }
    </style>

    <script>
        (function () {
            const URL_BASE = @json(url('admin/orders'));
            const STATUSES = @json($STATUSES);
            const TONES = @json($TONES);
            const PAY_STATUSES = @json($PAY_STATUSES);
            const PAY_METHODS = @json($PAY_METHODS);

            const $filter = document.getElementById('ordFilter');
            const $viewOverlay = document.getElementById('ordViewOverlay');
            const $reasonOverlay = document.getElementById('ordReasonOverlay');
            const actionForm = document.getElementById('ordActionForm');

            // Luồng xử lý đơn (đúng thứ tự backend) — dùng dựng stepper tiến trình.
            const PIPELINE = ['pending', 'confirmed', 'processing', 'shipping', 'delivered', 'completed'];
            const STOP_STATUSES = { cancelled: 'Đã huỷ', returned: 'Trả hàng' };

            const money = (n) => new Intl.NumberFormat('vi-VN').format(Number(n) || 0) + '₫';
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));
            const fmtDateTime = (s) => {
                if (!s) return '—';
                const d = new Date(s);
                return isNaN(d) ? s : d.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
            };

            // ---------- Bộ lọc ----------
            $filter.querySelectorAll('select').forEach((sel) => sel.addEventListener('change', () => $filter.submit()));

            // Khoảng ngày: bấm vào ô là mở lịch; chọn xong tự áp dụng.
            // ---------- Bảng lịch chọn khoảng ngày (2 tháng) ----------
            (function () {
                const wrap = document.getElementById('ordDateRange');
                const trigger = document.getElementById('ordDateTrigger');
                const pop = document.getElementById('ordCalPop');
                const label = document.getElementById('ordDateLabel');
                const rangeText = document.getElementById('ordCalRange');
                const $from = document.getElementById('ordFrom');
                const $to = document.getElementById('ordTo');
                if (!wrap || !trigger || !pop) return;

                const DOW = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
                const parse = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
                const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                const disp = (d) => d ? `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}` : '';
                const sameDay = (a, b) => a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
                const startOfMonth = (d) => new Date(d.getFullYear(), d.getMonth(), 1);
                const addMonths = (d, n) => new Date(d.getFullYear(), d.getMonth() + n, 1);
                const today = (() => { const t = new Date(); return new Date(t.getFullYear(), t.getMonth(), t.getDate()); })();

                let start = parse($from.value);
                let end = parse($to.value);
                let hover = null;                       // ngày đang di chuột khi đã chọn bắt đầu
                let lastHover = '';                      // ô vừa hover (tránh tô lại thừa)
                let view = startOfMonth(start || today); // tháng hiển thị ở lịch bên trái

                const syncLabel = () => {
                    label.textContent = (start || end)
                        ? `${disp(start) || '…'} → ${disp(end) || '…'}`
                        : 'Chọn ngày';
                };
                syncLabel();

                // Dựng khung lịch (chỉ chạy khi mở popup / đổi tháng) — không kèm trạng thái range.
                function buildCalendars() {
                    [0, 1].forEach((i) => {
                        const base = addMonths(view, i);
                        const y = base.getFullYear(), mo = base.getMonth();
                        const first = new Date(y, mo, 1);
                        const offset = (first.getDay() + 6) % 7;
                        const days = new Date(y, mo + 1, 0).getDate();
                        let cells = '';
                        for (let k = 0; k < offset; k++) cells += `<button type="button" class="ord-cal-day is-empty" disabled></button>`;
                        for (let d = 1; d <= days; d++) {
                            const date = new Date(y, mo, d);
                            const todayCls = sameDay(date, today) ? ' is-today' : '';
                            cells += `<button type="button" class="ord-cal-day${todayCls}" data-day="${ymd(date)}">${d}</button>`;
                        }
                        const cal = pop.querySelector(`.ord-cal[data-cal="${i}"]`);
                        cal.innerHTML =
                            `<div class="ord-cal-hd">
                                <button type="button" class="ord-cal-nav" data-nav="-1" ${i === 1 ? 'disabled' : ''}>‹</button>
                                <span class="ord-cal-title">Tháng ${mo + 1}/${y}</span>
                                <button type="button" class="ord-cal-nav" data-nav="1" ${i === 0 ? 'disabled' : ''}>›</button>
                            </div>` +
                            `<div class="ord-cal-grid ord-cal-dow-row">${DOW.map((w) => `<span class="ord-cal-dow">${w}</span>`).join('')}</div>` +
                            `<div class="ord-cal-grid ord-cal-body">${cells}</div>`;
                    });
                    paint();
                }

                // Tô lại trạng thái range trên các ô sẵn có — chạy khi hover/chọn (mượt, không dựng lại DOM).
                function paint() {
                    const hi = end || hover;
                    pop.querySelectorAll('.ord-cal-day[data-day]').forEach((btn) => {
                        const d = parse(btn.dataset.day);
                        btn.classList.remove('in-range', 'is-start', 'is-end');
                        if (start && hi) {
                            const lo = start < hi ? start : hi;
                            const up = start < hi ? hi : start;
                            if (d > lo && d < up) btn.classList.add('in-range');
                        }
                        if (sameDay(d, start)) btn.classList.add('is-start');
                        if (sameDay(d, end) || (!end && hover && start && sameDay(d, hover))) btn.classList.add('is-end');
                    });
                    rangeText.textContent = start && end
                        ? `${disp(start)} → ${disp(end)}`
                        : (start ? 'Chọn ngày kết thúc' : 'Chọn ngày bắt đầu');
                }

                const openPop = () => {
                    pop.hidden = false; wrap.classList.add('open'); trigger.setAttribute('aria-expanded', 'true');
                    view = startOfMonth(start || today);
                    buildCalendars();
                };
                const closePop = () => {
                    pop.hidden = true; wrap.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false');
                    hover = null; lastHover = '';
                };

                trigger.addEventListener('click', (e) => { e.stopPropagation(); pop.hidden ? openPop() : closePop(); });
                // Chỉ đóng khi bấm ra NGOÀI vùng lịch, và chỉ khi đang mở.
                document.addEventListener('click', (e) => { if (!pop.hidden && !wrap.contains(e.target)) closePop(); });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !pop.hidden) closePop(); });

                // Điều hướng tháng + chọn ngày. Chặn nổi bọt để không kích hoạt đóng-ngoài (tránh tắt popup).
                pop.addEventListener('click', (e) => {
                    e.stopPropagation();

                    const nav = e.target.closest('[data-nav]');
                    if (nav) { view = addMonths(view, +nav.getAttribute('data-nav')); buildCalendars(); return; }

                    const day = e.target.closest('[data-day]');
                    if (day && !day.classList.contains('is-empty')) {
                        const d = parse(day.dataset.day);
                        if (!start || (start && end)) { start = d; end = null; }
                        else if (d < start) { start = d; }
                        else { end = d; }
                        hover = null; lastHover = '';
                        paint();
                    }
                });

                // Xem trước khoảng khi mới chọn ngày bắt đầu — chỉ tô lại khi đổi ô (mượt, không giật).
                pop.addEventListener('mousemove', (e) => {
                    if (!start || end) return;
                    const day = e.target.closest('[data-day]');
                    const key = day ? day.dataset.day : '';
                    if (key === lastHover) return;
                    lastHover = key;
                    hover = day ? parse(day.dataset.day) : null;
                    paint();
                });
                pop.addEventListener('mouseleave', () => {
                    if (start && !end && hover) { hover = null; lastHover = ''; paint(); }
                });

                // Presets nhanh — chọn xong áp dụng luôn.
                pop.querySelectorAll('.ord-cal-preset').forEach((btn) => {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const p = btn.getAttribute('data-preset');
                        const d0 = new Date(today);
                        if (p === 'today') { start = new Date(today); end = new Date(today); }
                        else if (p === 'yesterday') { const y = new Date(today); y.setDate(y.getDate() - 1); start = new Date(y); end = new Date(y); }
                        else if (p === 'last7') { const s = new Date(today); s.setDate(s.getDate() - 6); start = s; end = new Date(today); }
                        else if (p === 'last30') { const s = new Date(today); s.setDate(s.getDate() - 29); start = s; end = new Date(today); }
                        else if (p === 'thismonth') { start = new Date(d0.getFullYear(), d0.getMonth(), 1); end = new Date(today); }
                        apply();
                    });
                });

                function apply() {
                    if (start && !end) end = new Date(start);
                    $from.value = start ? ymd(start) : '';
                    $to.value = end ? ymd(end) : '';
                    syncLabel();
                    $filter.submit();
                }

                document.getElementById('ordCalApply').addEventListener('click', (e) => { e.stopPropagation(); apply(); });
                // Xoá lọc ngày và tải lại danh sách (hiện tất cả) để danh sách hiển thị lại đúng.
                document.getElementById('ordCalClear').addEventListener('click', (e) => {
                    e.stopPropagation();
                    start = null; end = null; hover = null; lastHover = '';
                    $from.value = ''; $to.value = '';
                    $filter.submit();
                });
            })();

            // Nút "Nâng cao": ẩn/hiện hàng bộ lọc phụ (nhớ trạng thái qua localStorage).
            (function () {
                const btn = document.getElementById('ordAdvToggle');
                const row = document.getElementById('ordAdvRow');
                if (!btn || !row) return;
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('ord-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('ord-adv-open', open ? '1' : '0');
                });
            })();

            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // ---------- Modal ----------
            const openOverlay = (el) => { el.style.display = 'flex'; };
            const closeOverlay = (el) => { el.style.display = 'none'; };
            [$viewOverlay, $reasonOverlay].forEach((ov) => {
                ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeOverlay(ov)));
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') [$viewOverlay, $reasonOverlay].forEach(closeOverlay);
            });

            // ---------- Chi tiết đơn ----------
            let current = null;

            function renderItems(items) {
                const tbody = document.getElementById('vItems');
                if (!items || !items.length) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#bfbfbf;padding:16px">Đơn chưa có sản phẩm nào.</td></tr>';
                    return;
                }
                tbody.innerHTML = items.map((it) => {
                    const meta = [it.variant_sku, it.size, it.color].filter(Boolean).join(' · ');
                    const custom = [it.custom_player_name, it.custom_player_number].filter(Boolean).join(' ');
                    return `<tr>
                            <td>
                                <span class="ord-item-name">${esc(it.product_name || '—')}</span>
                                ${meta ? `<span class="ord-item-sub">${esc(meta)}</span>` : ''}
                                ${custom ? `<span class="ord-item-sub">In áo: ${esc(custom)}</span>` : ''}
                            </td>
                            <td class="ord-i-price">${money(it.unit_price)}</td>
                            <td class="ord-i-qty">${Number(it.quantity) || 0}</td>
                            <td class="ord-i-total">${money(it.total_price)}</td>
                        </tr>`;
                }).join('');
            }

            function renderHistory(list) {
                const el = document.getElementById('vHistory');
                if (!list || !list.length) {
                    el.innerHTML = '<p class="ord-timeline-empty">Chưa có thao tác nào được ghi nhận.</p>';
                    return;
                }
                el.innerHTML = list.map((h) => `
                        <li>
                            <span class="ord-tl-dot"></span>
                            <span class="ord-tl-body">
                                <span>${esc(STATUSES[h.from_status] || 'Khởi tạo')} → <b>${esc(STATUSES[h.to_status] || h.to_status)}</b></span>
                                <span class="ord-tl-time">${fmtDateTime(h.created_at)}</span>
                                ${h.note ? `<span class="ord-tl-note">${esc(h.note)}</span>` : ''}
                            </span>
                        </li>`).join('');
            }

            // Thời điểm đơn bước vào một trạng thái (lấy từ mốc thời gian hoặc lịch sử).
            function stepTime(o, step) {
                if (step === 'pending') return o.placed_at || o.created_at || null;
                const map = { confirmed: o.confirmed_at, shipping: o.shipped_at, delivered: o.delivered_at, cancelled: o.cancelled_at };
                if (map[step]) return map[step];
                const h = (o.histories || []).find((x) => x.to_status === step);
                return h ? h.created_at : null;
            }

            // Stepper tiến trình đúng luồng backend.
            function renderSteps(o) {
                const el = document.getElementById('vSteps');
                const status = o.status || 'pending';
                const stopped = Object.prototype.hasOwnProperty.call(STOP_STATUSES, status);
                const reached = new Set(['pending', ...(o.histories || []).map((h) => h.to_status)]);
                const curIdx = PIPELINE.indexOf(status);

                const shortTime = (t) => {
                    if (!t) return '';
                    const d = new Date(t);
                    return isNaN(d) ? '' : d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' }) + ' ' + d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
                };

                let html = PIPELINE.map((step, i) => {
                    let cls = '', mark = i + 1;
                    if (stopped) {
                        if (reached.has(step)) { cls = 'is-done'; mark = '✓'; }
                    } else if (i < curIdx) { cls = 'is-done'; mark = '✓'; }
                    else if (i === curIdx) { cls = (status === 'completed') ? 'is-done' : 'is-current'; if (status === 'completed') mark = '✓'; }
                    const t = (cls === 'is-done' || cls === 'is-current') ? shortTime(stepTime(o, step)) : '';
                    return `<li class="ord-step ${cls}">
                            <span class="ord-step-dot">${mark}</span>
                            <span class="ord-step-label">${esc(STATUSES[step] || step)}</span>
                            ${t ? `<span class="ord-step-time">${t}</span>` : ''}
                        </li>`;
                }).join('');

                if (stopped) {
                    const t = shortTime(stepTime(o, status));
                    html += `<li class="ord-step is-stop">
                            <span class="ord-step-dot">✕</span>
                            <span class="ord-step-label">${esc(STOP_STATUSES[status])}</span>
                            ${t ? `<span class="ord-step-time">${t}</span>` : ''}
                        </li>`;
                }
                el.className = 'ord-steps' + (stopped ? ' is-stopped' : '');
                el.innerHTML = html;
            }

            function renderActions(o) {
                const box = document.getElementById('vActions');
                const next = o.next_statuses || [];
                const terminal = Object.prototype.hasOwnProperty.call(STOP_STATUSES, o.status);

                // Nút chuyển trạng thái theo đúng luồng cho phép.
                let html = next.map((st) => {
                    if (st === 'cancelled') return `<button type="button" class="ord-btn-danger" data-reason="cancelled">Huỷ đơn</button>`;
                    if (st === 'returned') return `<button type="button" class="ord-btn-danger" data-reason="returned">Trả hàng</button>`;
                    return `<button type="button" class="ord-btn-primary" data-status="${st}">${esc(STATUSES[st] || st)}</button>`;
                }).join('');

                // Thao tác thanh toán theo bối cảnh.
                if (o.payment_status !== 'paid' && !terminal) {
                    html += `<button type="button" class="ord-btn-ghost" data-payment="paid">Đánh dấu đã thanh toán</button>`;
                } else if (o.payment_status === 'paid') {
                    html += `<button type="button" class="ord-btn-ghost" data-payment="refunded">Đánh dấu hoàn tiền</button>`;
                }

                if (!next.length) {
                    html = `<span class="ord-actions-note">Đơn đã ở trạng thái cuối, không đổi tiếp được.</span>` + html;
                }
                box.innerHTML = html;
            }

            function fillView(o) {
                current = o;
                const set = (id, v) => { document.getElementById(id).textContent = v; };

                set('vCode', o.order_code || '—');
                set('vPlaced', `Đặt lúc ${fmtDateTime(o.placed_at || o.created_at)}`);

                const badge = document.getElementById('vStatus');
                badge.textContent = STATUSES[o.status] || o.status || '—';
                badge.className = 'ord-badge tone-' + (TONES[o.status] || 'info');

                const pay = document.getElementById('vPay');
                pay.textContent = PAY_STATUSES[o.payment_status] || '—';
                pay.className = 'ord-pay is-' + (o.payment_status || 'pending');

                // Cảnh báo huỷ / trả hàng.
                const alert = document.getElementById('vAlert');
                if (o.status === 'cancelled' || o.status === 'returned') {
                    alert.style.display = '';
                    set('vAlertTitle', o.status === 'cancelled' ? 'Đơn đã huỷ' : 'Đơn đã trả hàng');
                    const when = fmtDateTime(stepTime(o, o.status));
                    const reason = o.cancel_reason || (o.histories || []).slice().reverse().find((h) => h.to_status === o.status)?.note || '';
                    set('vAlertDesc', (when !== '—' ? `Lúc ${when}` : '') + (reason ? ` · Lý do: ${reason}` : ''));
                } else {
                    alert.style.display = 'none';
                }

                renderSteps(o);

                set('vName', o.recipient_name || '—');
                set('vPhone', o.recipient_phone || '—');
                set('vEmail', o.recipient_email || '—');
                set('vAddress', [o.shipping_address, o.shipping_ward, o.shipping_district, o.shipping_province].filter(Boolean).join(', ') || '—');

                set('vMethod', PAY_METHODS[o.payment_method] || o.payment_method || '—');
                set('vPayText', PAY_STATUSES[o.payment_status] || '—');
                set('vShipMethod', o.shipping_method || '—');
                set('vTracking', o.tracking_number || '—');
                const voucher = o.voucher_code || '';
                document.getElementById('vVoucherWrap').style.display = voucher ? '' : 'none';
                set('vVoucher', voucher || '—');

                document.getElementById('vNoteWrap').style.display = o.note ? '' : 'none';
                set('vNote', o.note || '—');

                renderItems(o.items);
                set('vSubtotal', money(o.subtotal_amount));
                set('vDiscount', o.discount_amount > 0 ? '-' + money(o.discount_amount) : money(0));
                set('vShip', money(o.shipping_fee));
                set('vTotal', money(o.total_amount));

                renderHistory(o.histories);
                renderActions(o);

                document.getElementById('vAdminNote').value = o.admin_note || '';
                document.getElementById('ordNoteForm').action = `${URL_BASE}/${o.id}/note`;
                document.getElementById('vPrintBtn').href = `${URL_BASE}/${o.id}/print`;
                document.getElementById('vLabelBtn').href = `${URL_BASE}/${o.id}/label`;

                // Nút "Sửa đơn": chỉ hiện khi đơn còn ở giai đoạn đầu (khớp orderEditable phía API).
                const editBtn = document.getElementById('vEditBtn');
                const editable = ['pending', 'confirmed', 'processing'].includes(o.status);
                editBtn.hidden = !editable;
                editBtn.onclick = editable ? () => {
                    closeOverlay($viewOverlay);
                    if (typeof window.__ordOpenEdit === 'function') window.__ordOpenEdit(o);
                } : null;
            }

            function openView(id) {
                fetch(`${URL_BASE}/${id}/detail`, { headers: { 'Accept': 'application/json' } })
                    .then((r) => {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then((d) => {
                        fillView(d.data);
                        openOverlay($viewOverlay);
                    })
                    .catch(() => { alert('Không tải được chi tiết đơn hàng. Vui lòng thử lại.'); });
            }

            document.querySelector('.ord-table tbody').addEventListener('click', (e) => {
                const el = e.target.closest('[data-view]');
                if (el) openView(Number(el.getAttribute('data-view')));
            });

            // Mở modal nhập lý do (dùng chung Huỷ đơn / Trả hàng).
            function openReason(status) {
                const isCancel = status === 'cancelled';
                const code = current.order_code || '';
                const label = isCancel ? 'Đã huỷ' : 'Trả hàng';
                document.getElementById('vReasonStatus').value = status;
                document.getElementById('vReasonCode').textContent = code;
                document.getElementById('vReasonTitle').textContent = isCancel ? 'Huỷ đơn hàng' : 'Trả hàng';
                document.getElementById('vReasonDesc').innerHTML = `Đơn <b>${esc(code)}</b> sẽ chuyển sang <b>${label}</b> và không thể quay lại trạng thái trước.`;
                document.getElementById('vReasonLabel').innerHTML = isCancel
                    ? 'Lý do huỷ <span class="ord-req">*</span>'
                    : 'Lý do trả hàng <span class="ord-opt">(không bắt buộc)</span>';
                const input = document.getElementById('vReasonInput');
                input.value = '';
                input.required = isCancel;
                input.placeholder = isCancel
                    ? 'VD: Khách yêu cầu huỷ / Hết hàng / Không liên hệ được'
                    : 'VD: Sản phẩm lỗi / Giao sai / Khách đổi ý';
                document.getElementById('vReasonSubmit').textContent = isCancel ? 'Xác nhận huỷ' : 'Xác nhận trả hàng';
                document.getElementById('ordReasonForm').action = `${URL_BASE}/${current.id}/status`;
                closeOverlay($viewOverlay);
                openOverlay($reasonOverlay);
                setTimeout(() => input.focus(), 30);
            }

            // ---------- Thao tác trên đơn ----------
            document.getElementById('vActions').addEventListener('click', (e) => {
                if (!current) return;

                const reason = e.target.closest('[data-reason]');
                if (reason) { openReason(reason.getAttribute('data-reason')); return; }

                const st = e.target.closest('[data-status]');
                if (st) {
                    const to = st.getAttribute('data-status');
                    Promise.resolve(sysConfirm({
                        title: 'Xác nhận chuyển trạng thái',
                        message: `Chuyển đơn ${current.order_code} sang "${STATUSES[to] || to}"?`,
                        confirmText: 'Chuyển',
                    })).then((ok) => {
                        if (!ok) return;
                        actionForm.action = `${URL_BASE}/${current.id}/status`;
                        document.getElementById('ordActionStatus').value = to;
                        document.getElementById('ordActionPayment').value = '';
                        actionForm.submit();
                    });
                    return;
                }

                const pay = e.target.closest('[data-payment]');
                if (pay) {
                    const to = pay.getAttribute('data-payment');
                    Promise.resolve(sysConfirm({
                        title: 'Xác nhận thanh toán',
                        message: `Đánh dấu đơn ${current.order_code} là "${PAY_STATUSES[to] || to}"?`,
                        confirmText: 'Cập nhật',
                    })).then((ok) => {
                        if (!ok) return;
                        actionForm.action = `${URL_BASE}/${current.id}/payment`;
                        document.getElementById('ordActionPayment').value = to;
                        document.getElementById('ordActionStatus').value = '';
                        actionForm.submit();
                    });
                }
            });

            // ---------- Dropdown Tiện ích ----------
            const util = document.getElementById('ordUtil');
            document.getElementById('ordUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                util.classList.toggle('open');
            });
            document.addEventListener('click', () => util.classList.remove('open'));

            // ---------- Tạo đơn hàng thủ công ----------
            (function () {
                const overlay = document.getElementById('ordCreateOverlay');
                const addBtn = document.getElementById('ordAddBtn');
                if (!overlay || !addBtn) return;

                const form = document.getElementById('ordCreateForm');
                const ROUTES = {
                    customers: @json(route('admin.orders.searchCustomers')),
                    products: @json(route('admin.orders.searchProducts')),
                    store: @json(route('admin.orders.store')),
                    updateBase: @json(url('admin/orders')),
                };
                const $ = (id) => document.getElementById(id);
                const items = [];
                let mode = 'create';
                // Giá trị phương thức thanh toán mặc định (option đầu tiên) để reset khi tạo đơn.
                const defaultPay = $('ncPayMethod').options.length ? $('ncPayMethod').options[0].value : 'cod';

                const open = () => { overlay.style.display = 'flex'; };
                const close = () => { overlay.style.display = 'none'; };

                // Đổ tiền vào ô hiển thị (phân nhóm nghìn) + hidden số thô.
                const setMoney = (inputId, hiddenId, val) => {
                    const n = Math.max(0, Number(val) || 0);
                    $(hiddenId).value = n;
                    $(inputId).value = n.toLocaleString('vi-VN');
                };

                // Chế độ TẠO đơn: khôi phục form về trạng thái trắng.
                function applyCreateMode() {
                    mode = 'create';
                    form.action = ROUTES.store;
                    $('ncMethod').value = 'POST';
                    $('ncModalTitle').textContent = 'Tạo đơn hàng thủ công';
                    $('ncSubmit').textContent = 'Tạo đơn hàng';
                    $('ncCustomerField').style.display = '';
                    $('ncCustomerHint').style.display = '';
                    $('ncUserId').value = '';
                    $('ncName').value = ''; $('ncPhone').value = ''; $('ncEmail').value = '';
                    $('ncAddress').value = '';
                    $('ncProvince').value = ''; $('ncDistrict').value = ''; $('ncWard').value = '';
                    form.querySelector('[name=shipping_method]').value = '';
                    form.querySelector('[name=note]').value = '';
                    $('ncPayMethod').value = defaultPay;
                    const cval = $('ncCustomerValue');
                    cval.textContent = 'Chọn khách hàng có sẵn';
                    cval.classList.add('is-empty');
                    items.length = 0;
                    setMoney('ncShipFee', 'ncShipFeeRaw', 0);
                    setMoney('ncDiscount', 'ncDiscountRaw', 0);
                    renderItems();
                }

                // Chế độ SỬA đơn: đổ dữ liệu đơn hiện có vào form, đổi đích submit sang PUT.
                function applyEditMode(o) {
                    mode = 'edit';
                    form.action = `${ROUTES.updateBase}/${o.id}`;
                    $('ncMethod').value = 'PUT';
                    $('ncModalTitle').textContent = 'Sửa đơn ' + (o.order_code || '');
                    $('ncSubmit').textContent = 'Lưu thay đổi';
                    $('ncCustomerField').style.display = 'none';
                    $('ncCustomerHint').style.display = 'none';
                    $('ncUserId').value = o.user_id || '';
                    $('ncName').value = o.recipient_name || '';
                    $('ncPhone').value = o.recipient_phone || '';
                    $('ncEmail').value = o.recipient_email || '';
                    $('ncAddress').value = o.shipping_address || '';
                    $('ncProvince').value = o.shipping_province || '';
                    $('ncDistrict').value = o.shipping_district || '';
                    $('ncWard').value = o.shipping_ward || '';
                    form.querySelector('[name=shipping_method]').value = o.shipping_method || '';
                    form.querySelector('[name=note]').value = o.note || '';
                    $('ncPayMethod').value = o.payment_method || defaultPay;
                    setMoney('ncShipFee', 'ncShipFeeRaw', o.shipping_fee || 0);
                    setMoney('ncDiscount', 'ncDiscountRaw', o.discount_amount || 0);
                    items.length = 0;
                    (o.items || []).forEach((it) => items.push({
                        variantId: it.product_variant_id, productId: it.product_id, name: it.product_name || '',
                        sku: it.variant_sku || '', size: it.size || '', color: it.color || '',
                        thumbnail: it.thumbnail || '',
                        unitPrice: Number(it.unit_price) || 0, qty: Number(it.quantity) || 1,
                        customName: it.custom_player_name || '', customNumber: it.custom_player_number || '',
                    }));
                    renderItems();
                }

                addBtn.addEventListener('click', () => { applyCreateMode(); open(); });
                // Cho phép mở modal ở chế độ sửa từ modal chi tiết đơn.
                window.__ordOpenEdit = (o) => { applyEditMode(o); open(); };
                overlay.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', close));
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && overlay.style.display === 'flex') close(); });

                // ----- Tổng tiền -----
                function recalc() {
                    const subtotal = items.reduce((s, it) => s + it.unitPrice * it.qty, 0);
                    const discount = Math.max(0, Number($('ncDiscountRaw').value) || 0);
                    const ship = Math.max(0, Number($('ncShipFeeRaw').value) || 0);
                    const total = Math.max(0, subtotal - discount + ship);
                    $('ncSubtotal').textContent = money(subtotal);
                    $('ncSumDiscount').textContent = discount > 0 ? '-' + money(discount) : money(0);
                    $('ncSumShip').textContent = money(ship);
                    $('ncTotal').textContent = money(total);
                }

                // ----- Ô nhập tiền VN: hiển thị có phân nhóm nghìn (1.500.000), lưu số thô vào hidden -----
                function bindMoney(inputId, hiddenId) {
                    const el = $(inputId), hid = $(hiddenId);
                    const format = () => {
                        const digits = el.value.replace(/\D/g, '');
                        const num = digits ? parseInt(digits, 10) : 0;
                        el.value = digits ? num.toLocaleString('vi-VN') : '';
                        hid.value = num;
                        recalc();
                    };
                    el.addEventListener('input', format);
                    el.addEventListener('blur', () => { if (!el.value.trim()) { el.value = '0'; hid.value = 0; recalc(); } });
                    const init = Number(hid.value) || 0;
                    el.value = init.toLocaleString('vi-VN');
                }
                bindMoney('ncShipFee', 'ncShipFeeRaw');
                bindMoney('ncDiscount', 'ncDiscountRaw');

                // ----- Bảng sản phẩm -----
                function renderItems() {
                    const tb = $('ncItems');
                    $('ncItemsEmpty').style.display = items.length ? 'none' : '';
                    tb.innerHTML = items.map((it, i) => {
                        const meta = [it.sku, it.size, it.color].filter(Boolean).join(' · ');
                        return `<tr>
                            <td>
                                <span class="ord-item-name">${esc(it.name)}</span>
                                ${meta ? `<span class="ord-item-sub">${esc(meta)}</span>` : ''}
                                <div class="ord-nc-custom">
                                    <input type="text" class="ord-input ord-input-sm" placeholder="Tên in áo" value="${esc(it.customName)}" data-i="${i}" data-f="name">
                                    <input type="text" class="ord-input ord-input-sm" placeholder="Số" maxlength="10" value="${esc(it.customNumber)}" data-i="${i}" data-f="number">
                                </div>
                                <input type="hidden" name="items[${i}][product_variant_id]" value="${it.variantId}">
                                <input type="hidden" name="items[${i}][product_id]" value="${it.productId || ''}">
                                <input type="hidden" name="items[${i}][product_name]" value="${esc(it.name)}">
                                <input type="hidden" name="items[${i}][variant_sku]" value="${esc(it.sku)}">
                                <input type="hidden" name="items[${i}][size]" value="${esc(it.size)}">
                                <input type="hidden" name="items[${i}][color]" value="${esc(it.color)}">
                                <input type="hidden" name="items[${i}][thumbnail]" value="${esc(it.thumbnail)}">
                                <input type="hidden" name="items[${i}][unit_price]" value="${it.unitPrice}">
                                <input type="hidden" name="items[${i}][custom_player_name]" value="${esc(it.customName)}" data-hid="name-${i}">
                                <input type="hidden" name="items[${i}][custom_player_number]" value="${esc(it.customNumber)}" data-hid="number-${i}">
                            </td>
                            <td class="ord-i-price">${money(it.unitPrice)}</td>
                            <td class="ord-i-qty">
                                <input type="number" class="ord-input ord-input-sm ord-qty" min="1" value="${it.qty}" data-i="${i}">
                                <input type="hidden" name="items[${i}][quantity]" value="${it.qty}" data-hid="qty-${i}">
                            </td>
                            <td class="ord-i-total" data-total="${i}">${money(it.unitPrice * it.qty)}</td>
                            <td class="ord-i-del">
                                <button type="button" class="ord-rowbtn ord-nc-del" data-del="${i}" title="Xoá dòng">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                </button>
                            </td>
                        </tr>`;
                    }).join('');
                    recalc();
                }

                // Sửa SL / tên-số in áo mà không dựng lại DOM (giữ focus).
                $('ncItems').addEventListener('input', (e) => {
                    const t = e.target;
                    const i = Number(t.dataset.i);
                    if (Number.isNaN(i) || !items[i]) return;
                    if (t.classList.contains('ord-qty')) {
                        let q = parseInt(t.value, 10); if (!q || q < 1) q = 1;
                        items[i].qty = q;
                        $('ncItems').querySelector(`[data-hid="qty-${i}"]`).value = q;
                        $('ncItems').querySelector(`[data-total="${i}"]`).textContent = money(items[i].unitPrice * q);
                        recalc();
                    } else if (t.dataset.f === 'name') {
                        items[i].customName = t.value;
                        $('ncItems').querySelector(`[data-hid="name-${i}"]`).value = t.value;
                    } else if (t.dataset.f === 'number') {
                        items[i].customNumber = t.value;
                        $('ncItems').querySelector(`[data-hid="number-${i}"]`).value = t.value;
                    }
                });
                $('ncItems').addEventListener('click', (e) => {
                    const del = e.target.closest('[data-del]');
                    if (del) { items.splice(Number(del.dataset.del), 1); renderItems(); }
                });

                function addItem(p, v) {
                    const price = (v.price !== null && v.price !== '' && v.price !== undefined)
                        ? Number(v.price)
                        : (p.sale_price > 0 ? p.sale_price : p.base_price);
                    const exist = items.find((it) => it.variantId === v.id);
                    if (exist) { exist.qty += 1; }
                    else items.push({
                        variantId: v.id, productId: p.id, name: p.name,
                        sku: v.sku || '', size: v.size || '', color: v.color || '',
                        thumbnail: p.thumbnail || v.image || '',
                        unitPrice: Number(price) || 0, qty: 1, customName: '', customNumber: '',
                    });
                    renderItems();
                }

                // ----- Dropdown combobox chung (nút mở + panel tìm + danh sách) -----
                function setupDD(cfg) {
                    const dd = $(cfg.dd), toggle = $(cfg.toggle), panel = $(cfg.panel), input = $(cfg.input), menu = $(cfg.menu);
                    let timer = null, loaded = false;
                    const runSearch = (q) => {
                        menu.innerHTML = '<div class="ord-ac-loading">Đang tìm…</div>';
                        fetch(`${cfg.url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                            .then((r) => r.json())
                            .then((d) => {
                                const list = d.data || [];
                                menu.innerHTML = list.length ? list.map(cfg.renderRow).join('') : '<div class="ord-ac-empty">Không tìm thấy.</div>';
                            })
                            .catch(() => { menu.innerHTML = '<div class="ord-ac-empty">Lỗi tải dữ liệu.</div>'; });
                    };
                    const open = () => {
                        panel.hidden = false; dd.classList.add('open');
                        setTimeout(() => input.focus(), 20);
                        if (!loaded) { runSearch(''); loaded = true; }
                    };
                    const close = () => { panel.hidden = true; dd.classList.remove('open'); };
                    toggle.addEventListener('click', (e) => { e.stopPropagation(); panel.hidden ? open() : close(); });
                    input.addEventListener('click', (e) => e.stopPropagation());
                    input.addEventListener('input', () => { clearTimeout(timer); const q = input.value.trim(); timer = setTimeout(() => runSearch(q), 300); });
                    document.addEventListener('click', (e) => { if (!dd.contains(e.target)) close(); });
                    return { open, close };
                }

                // Khách hàng (dropdown)
                const custDD = setupDD({
                    dd: 'ncCustomerDD', toggle: 'ncCustomerToggle', panel: 'ncCustomerPanel',
                    input: 'ncCustomerInput', menu: 'ncCustomerMenu', url: ROUTES.customers,
                    renderRow: (c) => `<div class="ord-ac-item" data-cust="${encodeURIComponent(JSON.stringify(c))}">
                        <b>${esc(c.name || '(không tên)')}</b>
                        <span>${esc([c.phone, c.email].filter(Boolean).join(' · '))}</span>
                    </div>`,
                });
                $('ncCustomerMenu').addEventListener('click', (e) => {
                    const el = e.target.closest('[data-cust]');
                    if (!el) return;
                    const c = JSON.parse(decodeURIComponent(el.dataset.cust));
                    $('ncUserId').value = c.id;
                    $('ncName').value = c.name || '';
                    $('ncPhone').value = c.phone || '';
                    $('ncEmail').value = c.email || '';
                    if (c.address) $('ncAddress').value = c.address;
                    const val = $('ncCustomerValue');
                    val.textContent = (c.name || '(không tên)') + (c.phone ? ' · ' + c.phone : '');
                    val.classList.remove('is-empty');
                    custDD.close();
                });

                // Sản phẩm (dropdown, kèm biến thể) — chọn xong giữ panel để thêm tiếp
                setupDD({
                    dd: 'ncProductDD', toggle: 'ncProductToggle', panel: 'ncProductPanel',
                    input: 'ncProductInput', menu: 'ncProductMenu', url: ROUTES.products,
                    renderRow: (p) => {
                        const price = p.sale_price > 0 ? p.sale_price : p.base_price;
                        const variants = (p.variants || []).filter((v) => v.id);
                        const vhtml = variants.length
                            ? `<div class="ord-ac-variants">${variants.map((v) => {
                                const vlabel = [v.size, v.color].filter(Boolean).join(' / ') || v.sku || 'Mặc định';
                                const out = (Number(v.stock) <= 0);
                                const payload = encodeURIComponent(JSON.stringify({
                                    p: { id: p.id, name: p.name, base_price: p.base_price, sale_price: p.sale_price, thumbnail: p.thumbnail },
                                    v: v,
                                }));
                                return `<button type="button" class="ord-ac-variant" ${out ? 'disabled' : ''} data-pv="${payload}">${esc(vlabel)}${out ? ' (hết)' : ''}</button>`;
                            }).join('')}</div>`
                            : '<span class="ord-ac-empty">Chưa có biến thể để bán</span>';
                        return `<div class="ord-ac-item">
                            <b>${esc(p.name)} <span class="ord-ac-price">${money(price)}</span></b>
                            ${vhtml}
                        </div>`;
                    },
                });
                $('ncProductMenu').addEventListener('click', (e) => {
                    const btn = e.target.closest('[data-pv]');
                    if (!btn || btn.disabled) return;
                    const { p, v } = JSON.parse(decodeURIComponent(btn.dataset.pv));
                    addItem(p, v);
                });

                // ----- Kiểm tra trước khi gửi -----
                form.addEventListener('submit', (e) => {
                    // Khách hàng chỉ bắt buộc khi TẠO đơn; sửa đơn giữ nguyên khách của đơn.
                    if (mode === 'create' && !$('ncUserId').value) { e.preventDefault(); alert('Vui lòng chọn khách hàng có sẵn.'); return; }
                    if (!items.length) { e.preventDefault(); alert('Vui lòng thêm ít nhất một sản phẩm.'); return; }
                });
            })();

            // ===================== THAO TÁC HÀNG LOẠT =====================
            (function () {
                const bar = document.getElementById('ordBulkBar');
                const checkAll = document.getElementById('ordCheckAll');
                const tbody = document.querySelector('.ord-table tbody');
                if (!bar || !tbody) return;

                const EXPORT_URL = @json(route('admin.orders.export'));
                // Bước tiến hợp lệ kế tiếp của mỗi trạng thái — khớp orderFlow phía Go
                // (bỏ nhánh huỷ/trả hàng vì đó là thao tác cần lý do, làm ở từng đơn).
                const NEXT_STEP = {
                    pending: 'confirmed',
                    confirmed: 'processing',
                    processing: 'shipping',
                    shipping: 'delivered',
                    delivered: 'completed',
                };
                const rowChecks = () => Array.from(tbody.querySelectorAll('.ord-row-check'));
                const selected = () => rowChecks().filter((c) => c.checked);

                function refresh() {
                    const sel = selected();
                    document.getElementById('ordBulkCount').textContent = sel.length;
                    bar.hidden = sel.length === 0;
                    // Trạng thái ô "chọn tất cả": rỗng / một phần / đủ.
                    const all = rowChecks();
                    checkAll.checked = all.length > 0 && sel.length === all.length;
                    checkAll.indeterminate = sel.length > 0 && sel.length < all.length;

                    // Nút chuyển trạng thái: chỉ khi các đơn đã chọn CÙNG một trạng thái
                    // và trạng thái đó còn bước kế tiếp. Nhãn hiện đúng trạng thái sẽ chuyển tới.
                    const advBtn = document.getElementById('ordBulkAdvance');
                    const statuses = [...new Set(sel.map((c) => c.dataset.status))];
                    const next = statuses.length === 1 ? NEXT_STEP[statuses[0]] : null;
                    if (next) {
                        advBtn.hidden = false;
                        advBtn.dataset.next = next;
                        document.getElementById('ordBulkAdvanceLabel').textContent = 'Chuyển sang "' + (STATUSES[next] || next) + '"';
                    } else {
                        advBtn.hidden = true;
                        delete advBtn.dataset.next;
                    }
                }

                const selectedIds = () => selected().map((c) => c.value);

                // Mở tab in với danh sách id đã chọn.
                function openWith(base) {
                    const ids = selectedIds();
                    if (!ids.length) return;
                    window.open(`${base}?ids=${ids.join(',')}`, '_blank', 'noopener');
                }

                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((c) => { c.checked = checkAll.checked; });
                    refresh();
                });
                tbody.addEventListener('change', (e) => { if (e.target.classList.contains('ord-row-check')) refresh(); });
                document.getElementById('ordBulkClear').addEventListener('click', () => {
                    rowChecks().forEach((c) => { c.checked = false; });
                    refresh();
                });

                document.getElementById('ordBulkPrint').addEventListener('click', () => openWith(`${URL_BASE}/print`));
                document.getElementById('ordBulkLabel').addEventListener('click', () => openWith(`${URL_BASE}/label`));
                document.getElementById('ordBulkExport').addEventListener('click', () => {
                    const ids = selectedIds();
                    if (ids.length) window.location.href = `${EXPORT_URL}?ids=${ids.join(',')}`;
                });

                document.getElementById('ordBulkAdvance').addEventListener('click', (e) => {
                    const ids = selectedIds();
                    const next = e.currentTarget.dataset.next;
                    if (!ids.length || !next) return;
                    const label = STATUSES[next] || next;
                    if (!confirm(`Chuyển ${ids.length} đơn đã chọn sang "${label}"?`)) return;
                    document.getElementById('ordBulkStatus').value = next;
                    document.getElementById('ordBulkIds').innerHTML =
                        ids.map((id) => `<input type="hidden" name="ids[]" value="${id}">`).join('');
                    document.getElementById('ordBulkForm').submit();
                });

                refresh();
                // Bảng có thể bị thay ruột khi có đơn mới về (phần realtime bên
                // dưới) — lúc đó thanh thao tác hàng loạt phải tính lại theo các
                // dòng mới, nếu không nó vẫn đếm những đơn không còn trên màn hình.
                window.ordSyncBulk = refresh;
            })();

            // ---------- Realtime: đơn mới về thì bảng tự cập nhật ----------
            //
            // Bảng vẫn do server dựng: khi có tín hiệu, trang tải LẠI CHÍNH URL
            // hiện tại (giữ nguyên bộ lọc, trang, sắp xếp) rồi chỉ thay phần ruột
            // bảng + doanh thu + phân trang. Không dựng lại hàng bằng JS để hai
            // nơi không trôi ra hai kiểu hiển thị khác nhau theo thời gian.
            (function () {
                const pill = document.getElementById('ordLivePill');
                const pillText = document.getElementById('ordLiveText');
                const tbody = document.querySelector('.ord-table tbody');
                if (!pill || !tbody) return;

                let pending = 0;      // số sự kiện đã nhận mà chưa kịp phản ánh lên bảng
                let timer = null;
                let loading = false;

                /** Đang bận thì KHÔNG được tự thay bảng dưới tay người dùng. */
                function busy() {
                    // Đang chọn đơn để thao tác hàng loạt — thay bảng là mất hết lựa chọn.
                    if (tbody.querySelector('.ord-row-check:checked')) return true;
                    // Đang mở một modal (xem đơn, tạo đơn, nhập lý do huỷ).
                    return Array.from(document.querySelectorAll('.ord-overlay'))
                        .some((ov) => ov.style.display === 'flex');
                }

                function showPill() {
                    pillText.textContent = pending > 1
                        ? `Có ${pending} cập nhật đơn hàng mới`
                        : 'Có đơn hàng mới';
                    pill.hidden = false;
                }

                async function reload() {
                    if (loading) return;
                    loading = true;
                    try {
                        const res = await fetch(window.location.href, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            cache: 'no-store',
                        });
                        if (!res.ok) return;
                        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');

                        const freshBody = doc.querySelector('.ord-table tbody');
                        if (!freshBody) return;
                        // Thay RUỘT chứ không thay chính thẻ <tbody>: mọi handler
                        // của trang đều gắn trên tbody theo kiểu uỷ quyền, đổi thẻ
                        // là mất sạch.
                        tbody.innerHTML = freshBody.innerHTML;

                        const revenue = document.querySelector('.ord-revenue');
                        const freshRevenue = doc.querySelector('.ord-revenue');
                        if (revenue && freshRevenue) revenue.innerHTML = freshRevenue.innerHTML;

                        // Phân trang chỉ được dựng khi có ít nhất một đơn, nên phải
                        // xử lý cả hai chiều: từ không có thành có (đơn đầu tiên
                        // vừa về) và ngược lại.
                        const pg = document.querySelector('.ord > .pg');
                        const freshPg = doc.querySelector('.ord > .pg');
                        if (pg && freshPg) pg.replaceWith(freshPg);
                        else if (pg) pg.remove();
                        else if (freshPg) document.querySelector('.ord').appendChild(freshPg);

                        document.getElementById('ordCheckAll').checked = false;
                        if (window.ordSyncBulk) window.ordSyncBulk();

                        pending = 0;
                        pill.hidden = true;
                    } catch (e) {
                        // Mạng chập: giữ nguyên bảng cũ và để dải "có đơn mới" đó
                        // cho người dùng bấm lại.
                        showPill();
                    } finally {
                        loading = false;
                    }
                }

                pill.addEventListener('click', reload);

                // realtime.js gọi hàm này mỗi khi API báo có đơn tạo/đổi trạng thái.
                window.onOrderRealtime = function () {
                    pending += 1;
                    if (busy()) { showPill(); return; }
                    // Gộp một chùm sự kiện (đặt nhiều đơn liền nhau, chuyển trạng
                    // thái hàng loạt) thành đúng một lần tải lại.
                    clearTimeout(timer);
                    timer = setTimeout(() => { busy() ? showPill() : reload(); }, 400);
                };
            })();
        })();
    </script>
@endsection