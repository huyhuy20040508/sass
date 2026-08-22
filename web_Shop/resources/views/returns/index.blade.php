@extends('layouts.app')

@section('title', \App\Http\Controllers\ReturnController::TITLE)

@section('content')
    {{--
    Trang "Trả hàng" — cùng khuôn với trang Đơn hàng:
    [ header ] + [ form lọc ] + [ bảng compact ] + [ chân trang ] + [ modal chi tiết ].

    Phiếu trả hàng là sổ RIÊNG chứ không phải một trạng thái của đơn: một đơn có
    thể có nhiều phiếu (khách trả dần từng món). Toàn bộ luật (trả được bao nhiêu,
    nhập kho lúc nào, hoàn tiền ra sao) do Go API giữ — trang này chỉ hiển thị
    `next_statuses` API trả về, không tự đoán bước kế tiếp.
    --}}
    @php
        $STATUSES = \App\Http\Controllers\ReturnController::STATUSES;
        $TONES = \App\Http\Controllers\ReturnController::STATUS_TONES;
        $ACTIONS = \App\Http\Controllers\ReturnController::STATUS_ACTIONS;
        $REASONS = \App\Http\Controllers\ReturnController::REASONS;
        $REFUND_METHODS = \App\Http\Controllers\ReturnController::REFUND_METHODS;
        $SORTS = \App\Http\Controllers\ReturnController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\ReturnController::PAGE_SIZES;

        $TITLE = \App\Http\Controllers\ReturnController::TITLE;
        $EMPTY_TEXT = \App\Http\Controllers\ReturnController::EMPTY_TEXT;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['reason'] !== 'all'
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        // Số bộ lọc nâng cao đang bật -> tự mở hàng nâng cao + hiện badge.
        $advCount = ($filters['status'] !== 'all' ? 1 : 0)
            + ($filters['reason'] !== 'all' ? 1 : 0)
            + ($filters['sort'] !== 'newest' ? 1 : 0);
        $advOpen = $advCount > 0;
    @endphp

    <div class="rt">
        {{-- Header --}}
        <div class="rt-head">
            <h1 class="rt-title">{{ $TITLE }}</h1>
            <span class="rt-refund">
                Chờ duyệt: <b>{{ number_format($stats['pending'], 0, ',', '.') }}</b> ·
                Chờ nhận hàng: <b>{{ number_format($stats['approved'], 0, ',', '.') }}</b> ·
                Chờ hoàn tiền: <b>{{ number_format($stats['received'], 0, ',', '.') }}</b> ·
                Đã hoàn: <b>{{ number_format((float) $stats['refunded_amount'], 0, ',', '.') }}₫</b>
                <em>(chỉ tính phiếu đã hoàn tiền xong)</em>
            </span>
        </div>

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.returns.index') }}" id="rtFilter" class="rt-filter">
            <div class="rt-toolbar">
                <div class="rt-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="rt-search-input"
                        placeholder="Tìm theo mã phiếu, mã đơn, tên hoặc SĐT người nhận" autocomplete="off">
                    <button type="submit" class="rt-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </button>
                </div>

                {{-- Khoảng ngày tạo phiếu: bấm mở bảng lịch 2 tháng để quét chọn từ ngày → đến ngày.
                     Hai ô ẩn gửi ĐÚNG khoảng người dùng đã chọn, rỗng khi chưa chọn gì — không đổ
                     sẵn giá trị mặc định vì chúng nằm trong form lọc, chỉ cần đổi ô Sắp xếp là
                     khoảng ngày đó bị gửi lên theo và phiếu cũ biến mất khỏi bảng. --}}
                <div class="rt-daterange" id="rtDateRange">
                    <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}" id="rtFrom">
                    <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}" id="rtTo">
                    <button type="button" class="rt-daterange-trigger" id="rtDateTrigger" aria-haspopup="dialog"
                        aria-expanded="false" title="Lọc theo ngày tạo phiếu">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <path d="M16 2v4M8 2v4M3 10h18" />
                        </svg>
                        <span class="rt-daterange-label" id="rtDateLabel">—</span>
                        <svg class="rt-daterange-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div class="rt-cal-pop" id="rtCalPop" role="dialog" aria-label="Chọn khoảng ngày" hidden>
                        <div class="rt-cal-presets">
                            <button type="button" class="rt-cal-preset" data-preset="today">Hôm nay</button>
                            <button type="button" class="rt-cal-preset" data-preset="yesterday">Hôm qua</button>
                            <button type="button" class="rt-cal-preset" data-preset="last7">7 ngày qua</button>
                            <button type="button" class="rt-cal-preset" data-preset="last30">30 ngày qua</button>
                            <button type="button" class="rt-cal-preset" data-preset="thismonth">Tháng này</button>
                        </div>
                        <div class="rt-cal-main">
                            <div class="rt-cal-months">
                                <div class="rt-cal" data-cal="0"></div>
                                <div class="rt-cal" data-cal="1"></div>
                            </div>
                            <div class="rt-cal-foot">
                                <span class="rt-cal-range" id="rtCalRange">Chọn ngày bắt đầu</span>
                                <div class="rt-cal-foot-btns">
                                    <button type="button" class="rt-btn-ghost rt-cal-btn" id="rtCalClear">Xoá</button>
                                    <button type="button" class="rt-btn-primary rt-cal-btn" id="rtCalApply">Áp dụng</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" id="rtAdvToggle" class="rt-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                    aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M7 12h10M11 18h2" />
                    </svg>
                    Nâng cao
                    <svg class="rt-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                    @if($advCount > 0)<span class="rt-adv-count">{{ $advCount }}</span>@endif
                </button>

                <div class="rt-toolbar-actions">
                    <button type="button" id="rtAddBtn" class="rt-btn-primary rt-add-btn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M12 5v14" />
                        </svg>
                        Lập phiếu trả
                    </button>

                    <div class="rt-util" id="rtUtil">
                        <button type="button" class="rt-util-btn" id="rtUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1.6" />
                                <circle cx="19" cy="12" r="1.6" />
                                <circle cx="5" cy="12" r="1.6" />
                            </svg>
                            Tiện ích
                            <svg class="rt-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div class="rt-util-menu">
                            <a href="{{ route('admin.returns.export', request()->query()) }}" class="rt-util-item">
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

            {{-- Hàng nâng cao: trạng thái + lý do + sắp xếp --}}
            <div class="rt-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="rtAdvRow">
                <select name="status" class="rt-select" title="Lọc theo trạng thái phiếu">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="reason" class="rt-select" title="Lọc theo lý do trả">
                    <option value="all" {{ $filters['reason'] === 'all' ? 'selected' : '' }}>Tất cả lý do</option>
                    @foreach($REASONS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['reason'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="sort" class="rt-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.returns.index') }}" class="rt-clear">Xoá lọc</a>
                @endif
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}" id="rtPageSizeHidden">
        </form>

        {{-- Thanh thao tác hàng loạt NỔI (hiện khi chọn ≥1 phiếu) --}}
        <div class="rt-bulk" id="rtBulkBar" hidden>
            <span class="rt-bulk-count"><b id="rtBulkCount">0</b> phiếu đã chọn</span>
            <button type="button" class="rt-bulk-clear" id="rtBulkClear">Bỏ chọn</button>
            <div class="rt-bulk-actions">
                <button type="button" class="rt-btn-ghost" id="rtBulkExport">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Xuất CSV
                </button>
                {{-- Nút chuyển trạng thái: nhãn = bước kế tiếp của các phiếu đã chọn. Chỉ hiện
                     khi mọi phiếu đã chọn cùng một trạng thái và trạng thái đó có bước tiếp theo. --}}
                <button type="button" class="rt-btn-primary" id="rtBulkAdvance" hidden>
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                    <span id="rtBulkAdvanceLabel">Chuyển trạng thái</span>
                </button>
            </div>
        </div>

        {{-- Form ẩn để gửi chuyển trạng thái hàng loạt --}}
        <form method="POST" action="{{ route('admin.returns.bulkStatus') }}" id="rtBulkForm" class="d-none">
            @csrf
            <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
            <input type="hidden" name="status" id="rtBulkStatus">
            <div id="rtBulkIds"></div>
        </form>

        {{-- Dải "có phiếu mới" — chỉ hiện khi KHÔNG tự làm mới được (đang mở modal).
             Bấm vào là nạp lại trang ngay. --}}
        <button type="button" class="rt-live" id="rtLivePill" hidden>
            <span class="rt-live-dot"></span>
            <span id="rtLiveText">Có phiếu trả hàng mới</span>
            <span class="rt-live-cta">Tải lại bảng</span>
        </button>

        {{-- Bảng --}}
        <div class="rt-table-wrap">
            <table class="rt-table">
                <thead>
                    <tr>
                        <th class="rt-c-check">
                            <input type="checkbox" id="rtCheckAll" class="rt-check" title="Chọn tất cả trong trang">
                        </th>
                        <th class="rt-c-stt">STT</th>
                        <th class="rt-c-code">Mã phiếu</th>
                        <th class="rt-c-order">Đơn hàng</th>
                        <th class="rt-c-reason">Lý do</th>
                        <th class="rt-c-qty">SL</th>
                        <th class="rt-c-amount">Tiền hoàn</th>
                        <th class="rt-c-status">Trạng thái</th>
                        <th class="rt-c-date">Ngày tạo</th>
                        <th class="rt-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($returns as $i => $r)
                        @php
                            $id = $r['id'] ?? 0;
                            $status = $r['status'] ?? 'pending';
                            $qty = collect($r['items'] ?? [])->sum('quantity');
                            $orderCode = data_get($r, 'order.order_code', '—');
                            $orderName = data_get($r, 'order.recipient_name', '');
                            $orderPhone = data_get($r, 'order.recipient_phone', '');
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="rt-c-check">
                                <input type="checkbox" class="rt-check rt-row-check" value="{{ $id }}"
                                       data-status="{{ $status }}" aria-label="Chọn phiếu {{ $r['return_code'] ?? $id }}">
                            </td>
                            <td class="rt-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="rt-c-code" data-view="{{ $id }}" title="Xem chi tiết phiếu trả hàng">
                                <span class="rt-code">{{ $r['return_code'] ?? '—' }}</span>
                                @if(($r['requested_by'] ?? '') === 'customer')
                                    <span class="rt-sub">Khách gửi</span>
                                @else
                                    <span class="rt-sub">Nhân viên lập</span>
                                @endif
                            </td>
                            <td class="rt-c-order" data-view="{{ $id }}" title="Xem chi tiết phiếu trả hàng">
                                <span class="rt-name">{{ $orderCode }}</span>
                                <span class="rt-sub">{{ trim($orderName . ' · ' . $orderPhone, ' ·') }}</span>
                            </td>
                            <td class="rt-c-reason" title="{{ $r['reason_note'] ?? '' }}">
                                {{ $REASONS[$r['reason'] ?? ''] ?? '—' }}
                                @if(!empty($r['reason_note']))
                                    <span class="rt-sub">{{ \Illuminate\Support\Str::limit($r['reason_note'], 60) }}</span>
                                @endif
                            </td>
                            <td class="rt-c-qty">{{ number_format($qty, 0, ',', '.') }}</td>
                            <td class="rt-c-amount">
                                <span class="rt-total">{{ number_format((float) ($r['refund_amount'] ?? 0), 0, ',', '.') }}₫</span>
                                <span class="rt-sub">{{ $REFUND_METHODS[$r['refund_method'] ?? ''] ?? '—' }}</span>
                            </td>
                            <td class="rt-c-status">
                                <span class="rt-badge tone-{{ $TONES[$status] ?? 'info' }}">{{ $STATUSES[$status] ?? '—' }}</span>
                            </td>
                            <td class="rt-c-date">
                                {{ !empty($r['created_at']) ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="rt-c-act">
                                <button type="button" class="rt-rowbtn" data-view="{{ $id }}" title="Xem & xử lý phiếu">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                    </svg>
                                </button>
                                @if(!empty($r['order_id']))
                                    <a class="rt-rowbtn" href="{{ route('admin.orders.index', ['keyword' => $orderCode]) }}"
                                        title="Mở đơn hàng gốc">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M9 2h6a1 1 0 0 1 1 1v1h2a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h2V3a1 1 0 0 1 1-1Z" />
                                            <path d="M8 10h8M8 14h5" />
                                        </svg>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="rt-empty">
                                @if($hasFilter)
                                    Không tìm thấy phiếu trả hàng nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'phiếu trả hàng',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- ===== Modal chi tiết & xử lý phiếu ===== --}}
    <div class="rt-overlay" id="rtViewOverlay" style="display:none;">
        <div class="rt-dialog">
            <div class="rt-modal-head">
                <h4 class="rt-modal-title">Chi tiết phiếu trả hàng</h4>
                <button type="button" class="rt-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="rt-modal-body">
                <div class="rt-view-head">
                    <div class="rt-view-ident">
                        <span class="rt-view-code" id="vCode">—</span>
                        <span class="rt-view-date" id="vCreated">—</span>
                    </div>
                    <span class="rt-badge" id="vStatus">—</span>
                </div>

                {{-- Tiến trình xử lý phiếu --}}
                <ol class="rt-steps" id="vSteps"></ol>

                {{-- Cảnh báo nhánh kết thúc tiêu cực (từ chối / khách rút) --}}
                <div class="rt-alert" id="vAlert" hidden>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" /><path d="M12 8v5M12 16h.01" />
                    </svg>
                    <div class="rt-alert-body">
                        <b id="vAlertTitle">—</b>
                        <span id="vAlertDesc">—</span>
                    </div>
                </div>

                <div class="rt-view-cols">
                    <div class="rt-view-sec">
                        <p class="rt-sec-title">Đơn hàng gốc</p>
                        <div class="rt-view-grid">
                            <div class="rt-cell"><span class="rt-lb">Mã đơn</span><span class="rt-vl" id="vOrderCode">—</span></div>
                            <div class="rt-cell"><span class="rt-lb">Người nhận</span><span class="rt-vl" id="vOrderName">—</span></div>
                            <div class="rt-cell"><span class="rt-lb">Điện thoại</span><span class="rt-vl" id="vOrderPhone">—</span></div>
                            <div class="rt-cell"><span class="rt-lb">Giá trị đơn</span><span class="rt-vl" id="vOrderTotal">—</span></div>
                        </div>
                    </div>

                    <div class="rt-view-sec">
                        <p class="rt-sec-title">Lý do & người gửi</p>
                        <div class="rt-view-grid">
                            <div class="rt-cell"><span class="rt-lb">Lý do</span><span class="rt-vl" id="vReason">—</span></div>
                            <div class="rt-cell"><span class="rt-lb">Người gửi</span><span class="rt-vl" id="vRequestedBy">—</span></div>
                            <div class="rt-cell is-full"><span class="rt-lb">Mô tả của khách</span><span class="rt-vl" id="vReasonNote">—</span></div>
                        </div>
                    </div>
                </div>

                <div class="rt-view-sec">
                    <p class="rt-sec-title">Sản phẩm trả lại</p>
                    <table class="rt-items">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th class="rt-i-price">Đơn giá</th>
                                <th class="rt-i-qty">SL</th>
                                <th class="rt-i-total">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody id="vItems"></tbody>
                    </table>
                </div>

                {{-- Chốt tiền hoàn: chỉ mở khi phiếu chưa kết thúc --}}
                <div class="rt-view-sec" id="vSettleSec">
                    <p class="rt-sec-title">Tiền hoàn</p>
                    <form method="POST" id="rtSettleForm" class="rt-settle">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                        {{-- Ô tiền hiển thị có phân nhóm nghìn; số thô gửi lên nằm ở input ẩn,
                             nên không phải gỡ dấu chấm lúc submit. --}}
                        <input type="hidden" name="shipping_fee" id="vShipFeeRaw" value="0">
                        <input type="hidden" name="deduction" id="vDeductionRaw" value="0">

                        <div class="rt-form-grid">
                            <div class="rt-field">
                                <label class="rt-lb">Tiền hàng trả lại</label>
                                <div class="rt-money">
                                    <input type="text" class="rt-input rt-money-input" id="vItemsAmount" readonly>
                                    <span class="rt-money-suffix">₫</span>
                                </div>
                                <p class="rt-hint">Server tính từ đơn gốc, không sửa được.</p>
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="vShipFee">Phí ship hoàn lại</label>
                                <div class="rt-money">
                                    <input type="text" id="vShipFee" class="rt-input rt-money-input" inputmode="numeric" value="0">
                                    <span class="rt-money-suffix">₫</span>
                                </div>
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="vDeduction">Khấu trừ</label>
                                <div class="rt-money">
                                    <input type="text" id="vDeduction" class="rt-input rt-money-input" inputmode="numeric" value="0">
                                    <span class="rt-money-suffix">₫</span>
                                </div>
                                <p class="rt-hint">Hàng đã dùng, thiếu phụ kiện, phí xử lý…</p>
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="vRefundMethod">Hình thức hoàn</label>
                                <select name="refund_method" id="vRefundMethod" class="rt-select is-block">
                                    @foreach($REFUND_METHODS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="rt-form-grid rt-bank" id="vBankRow">
                            <div class="rt-field">
                                <label class="rt-lb" for="vBankAccount">Số tài khoản</label>
                                <input type="text" name="bank_account" id="vBankAccount" class="rt-input" maxlength="50">
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="vBankHolder">Chủ tài khoản</label>
                                <input type="text" name="bank_holder" id="vBankHolder" class="rt-input" maxlength="150">
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="vBankName">Ngân hàng</label>
                                <input type="text" name="bank_name" id="vBankName" class="rt-input" maxlength="150">
                            </div>
                        </div>

                        <label class="rt-checkline">
                            <input type="hidden" name="restock" value="0">
                            <input type="checkbox" name="restock" value="1" id="vRestock" class="rt-check" checked>
                            <span>Nhập lại kho khi nhận hàng <em>— bỏ chọn nếu hàng lỗi, không bán lại được</em></span>
                        </label>

                        <div class="rt-settle-foot">
                            <div class="rt-sum">
                                <div class="is-total"><span>Khách được hoàn</span><b id="vRefundAmount">0₫</b></div>
                            </div>
                            <button type="submit" class="rt-btn-ghost">Lưu số tiền hoàn</button>
                        </div>
                    </form>
                </div>

                {{-- Bản chốt chỉ đọc khi phiếu đã kết thúc --}}
                <div class="rt-view-sec" id="vSettleRO" hidden>
                    <p class="rt-sec-title">Tiền hoàn</p>
                    <div class="rt-sum">
                        <div><span>Tiền hàng trả lại</span><b id="roItems">0₫</b></div>
                        <div><span>Phí ship hoàn lại</span><b id="roShip">0₫</b></div>
                        <div><span>Khấu trừ</span><b id="roDeduct">0₫</b></div>
                        <div class="is-total"><span>Đã hoàn cho khách</span><b id="roTotal">0₫</b></div>
                    </div>
                </div>

                <div class="rt-view-cols">
                    <div class="rt-view-sec">
                        <p class="rt-sec-title">Lịch sử xử lý</p>
                        <ul class="rt-timeline" id="vHistory"></ul>
                    </div>

                    <div class="rt-view-sec">
                        <p class="rt-sec-title">Ghi chú nội bộ</p>
                        <form method="POST" id="rtNoteForm" class="rt-note-form">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                            <textarea name="admin_note" id="vAdminNote" class="rt-textarea" rows="3"
                                placeholder="Ghi chú cho nhân viên xử lý (khách hàng không thấy)"></textarea>
                            <button type="submit" class="rt-btn-ghost">Lưu ghi chú</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="rt-modal-foot">
                <div class="rt-actions" id="vActions"></div>
                <div class="rt-foot-right">
                    <a class="rt-btn-ghost" id="vOrderBtn" href="#">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 2h6a1 1 0 0 1 1 1v1h2a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h2V3a1 1 0 0 1 1-1Z"/><path d="M8 10h8M8 14h5"/></svg>
                        Mở đơn gốc
                    </a>
                    <button type="button" class="rt-btn-ghost" data-close>Đóng</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Modal nhập lý do (từ chối / huỷ phiếu) ===== --}}
    <div class="rt-overlay" id="rtReasonOverlay" style="display:none;">
        <div class="rt-dialog rt-dialog-sm">
            <div class="rt-modal-head">
                <h4 class="rt-modal-title" id="vReasonTitle">Từ chối yêu cầu trả hàng</h4>
                <button type="button" class="rt-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>
            <form method="POST" id="rtReasonForm">
                @csrf
                @method('PUT')
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="status" id="vReasonStatus" value="rejected">
                <div class="rt-modal-body">
                    <p class="rt-note-box" id="vReasonDesc">—</p>
                    <div class="rt-field">
                        <label class="rt-lb" for="vReasonInput" id="vReasonLabel">Lý do từ chối <span class="rt-req">*</span></label>
                        <textarea name="note" id="vReasonInput" class="rt-textarea" rows="3" maxlength="255"></textarea>
                    </div>
                </div>
                <div class="rt-modal-foot">
                    <div class="rt-foot-right">
                        <button type="button" class="rt-btn-ghost" data-close>Đóng</button>
                        <button type="submit" class="rt-btn-danger" id="vReasonSubmit">Xác nhận từ chối</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal lập phiếu trả hàng ===== --}}
    <div class="rt-overlay" id="rtCreateOverlay" style="display:none;">
        <div class="rt-dialog">
            <div class="rt-modal-head">
                <h4 class="rt-modal-title">Lập phiếu trả hàng</h4>
                <button type="button" class="rt-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.returns.store') }}" id="rtCreateForm">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="order_id" id="ncOrderId">
                <input type="hidden" name="shipping_fee" id="ncShipFeeRaw" value="0">
                <input type="hidden" name="deduction" id="ncDeductionRaw" value="0">

                <div class="rt-modal-body">
                    {{-- Nhắc bản nháp còn dở của lần lập phiếu trước (lưu ở máy này) --}}
                    <div class="rt-draft" id="ncDraft" hidden>
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 8v4l3 2" /><circle cx="12" cy="12" r="9" />
                        </svg>
                        <span class="rt-draft-text" id="ncDraftText">—</span>
                        <div class="rt-draft-actions">
                            <button type="button" class="rt-btn-primary rt-btn-xs" id="ncDraftRestore">Khôi phục</button>
                            <button type="button" class="rt-btn-ghost rt-btn-xs" id="ncDraftDiscard">Bỏ</button>
                        </div>
                    </div>

                    {{-- Bước 1: chọn đơn --}}
                    <div class="rt-view-sec">
                        <p class="rt-sec-title">1. Đơn hàng cần trả</p>
                        {{-- Dropdown chọn đơn: bấm là xổ sẵn các đơn đã giao gần nhất, gõ để
                             lọc tiếp. Panel định vị theo VIEWPORT (position: fixed, toạ độ do JS
                             tính từ nút) chứ không absolute trong modal — lúc chưa chọn đơn thì
                             modal rất thấp, panel absolute sẽ bị khung cuộn của modal cắt mất. --}}
                        <div class="rt-dd" id="ncOrderDD">
                            <button type="button" class="rt-dd-control" id="ncOrderBtn"
                                aria-haspopup="listbox" aria-expanded="false">
                                <span class="rt-dd-value is-empty" id="ncOrderLabel">Chọn đơn hàng đã giao…</span>
                                <svg class="rt-dd-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <div class="rt-dd-panel" id="ncOrderPanel" role="listbox" hidden>
                                <div class="rt-dd-search">
                                    <input type="text" class="rt-input rt-input-sm" id="ncOrderSearch" autocomplete="off"
                                        placeholder="Lọc theo mã đơn, tên hoặc số điện thoại">
                                </div>
                                <div class="rt-dd-list" id="ncOrderList"></div>
                            </div>
                        </div>
                        {{-- Chỉ chọn được đơn ĐÃ GIAO / HOÀN TẤT: các trạng thái khác chưa tới tay
                             khách nên phải huỷ đơn chứ không phải trả hàng. --}}
                        <p class="rt-hint">Danh sách chỉ gồm đơn <b>đã giao</b> hoặc <b>hoàn tất</b>. Đơn chưa tới tay
                            khách thì dùng chức năng huỷ đơn bên trang Đơn hàng.</p>

                        {{-- Thông tin đơn đã chọn — nhân viên xác nhận đúng đơn trước khi lập phiếu --}}
                        <div class="rt-picked" id="ncPicked" hidden>
                            <div class="rt-view-grid">
                                <div class="rt-cell"><span class="rt-lb">Người nhận</span><span class="rt-vl" id="ncOrderName">—</span></div>
                                <div class="rt-cell"><span class="rt-lb">Điện thoại</span><span class="rt-vl" id="ncOrderPhone">—</span></div>
                                <div class="rt-cell"><span class="rt-lb">Giá trị đơn</span><span class="rt-vl" id="ncOrderTotal">—</span></div>
                                <div class="rt-cell"><span class="rt-lb">Trạng thái đơn</span><span class="rt-vl" id="ncOrderStatus">—</span></div>
                            </div>
                        </div>
                        <p class="rt-note-box" id="ncOrderNote" hidden>—</p>
                    </div>

                    {{-- Bước 2: chọn món --}}
                    <div class="rt-view-sec" id="ncItemsSec" hidden>
                        <p class="rt-sec-title">2. Sản phẩm khách trả</p>
                        <table class="rt-items">
                            <thead>
                                <tr>
                                    <th class="rt-i-qty">
                                        <input type="checkbox" id="ncCheckAll" class="rt-check" title="Chọn trả toàn bộ">
                                    </th>
                                    <th>Sản phẩm</th>
                                    <th class="rt-i-price">Đơn giá</th>
                                    <th class="rt-i-qty">Còn trả được</th>
                                    <th class="rt-i-qty">SL trả</th>
                                    <th class="rt-i-total">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody id="ncItems"></tbody>
                        </table>
                        <p class="rt-hint">Cột "còn trả được" đã trừ số đang nằm trong phiếu trả khác của cùng đơn.</p>
                    </div>

                    {{-- Bước 3: lý do & tiền --}}
                    <div class="rt-view-sec" id="ncFormSec" hidden>
                        <p class="rt-sec-title">3. Lý do & hoàn tiền</p>
                        {{-- Bước này có đúng 4 ô: lưới 2 cột chia chẵn thành 2 hàng, không để
                             ô nào đứng lẻ giữa hàng trống. --}}
                        <div class="rt-form-grid">
                            <div class="rt-field">
                                <label class="rt-lb" for="ncReason">Lý do trả <span class="rt-req">*</span></label>
                                <select name="reason" id="ncReason" class="rt-select is-block" required>
                                    @foreach($REASONS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="ncRefundMethod">Hình thức hoàn</label>
                                <select name="refund_method" id="ncRefundMethod" class="rt-select is-block">
                                    @foreach($REFUND_METHODS as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="ncShipFee">Phí ship hoàn lại</label>
                                <div class="rt-money">
                                    <input type="text" id="ncShipFee" class="rt-input rt-money-input" inputmode="numeric" value="0">
                                    <span class="rt-money-suffix">₫</span>
                                </div>
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="ncDeduction">Khấu trừ</label>
                                <div class="rt-money">
                                    <input type="text" id="ncDeduction" class="rt-input rt-money-input" inputmode="numeric" value="0">
                                    <span class="rt-money-suffix">₫</span>
                                </div>
                            </div>
                            <div class="rt-field is-full">
                                <label class="rt-lb" for="ncReasonNote">Mô tả thêm</label>
                                <textarea name="reason_note" id="ncReasonNote" class="rt-textarea" rows="2" maxlength="500"
                                    placeholder="VD: Áo bị bung chỉ ở tay phải, khách mang tới cửa hàng ngày {{ date('d/m') }}"></textarea>
                            </div>
                        </div>

                        <div class="rt-grid-3 rt-bank" id="ncBankRow">
                            <div class="rt-field">
                                <label class="rt-lb" for="ncBankAccount">Số tài khoản</label>
                                <input type="text" name="bank_account" id="ncBankAccount" class="rt-input" maxlength="50">
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="ncBankHolder">Chủ tài khoản</label>
                                <input type="text" name="bank_holder" id="ncBankHolder" class="rt-input" maxlength="150">
                            </div>
                            <div class="rt-field">
                                <label class="rt-lb" for="ncBankName">Ngân hàng</label>
                                <input type="text" name="bank_name" id="ncBankName" class="rt-input" maxlength="150">
                            </div>
                        </div>

                        <div class="rt-field">
                            <label class="rt-lb" for="ncAdminNote">Ghi chú nội bộ</label>
                            <textarea name="admin_note" id="ncAdminNote" class="rt-textarea" rows="2" maxlength="500"
                                placeholder="Khách hàng không nhìn thấy"></textarea>
                        </div>

                        <label class="rt-checkline">
                            <input type="hidden" name="restock" value="0">
                            <input type="checkbox" name="restock" value="1" id="ncRestock" class="rt-check" checked>
                            <span>Nhập lại kho khi nhận hàng <em>— bỏ chọn nếu hàng lỗi, không bán lại được</em></span>
                        </label>

                        <div class="rt-sum rt-nc-sum">
                            <div><span>Tiền hàng trả lại</span><b id="ncItemsAmount">0₫</b></div>
                            <div><span>Phí ship hoàn lại</span><b id="ncSumShip">0₫</b></div>
                            <div><span>Khấu trừ</span><b id="ncSumDeduct">0₫</b></div>
                            <div class="is-total"><span>Khách được hoàn</span><b id="ncRefundAmount">0₫</b></div>
                        </div>

                        <p class="rt-note-box">Phiếu lập tại quầy vào thẳng trạng thái <b>Đã duyệt</b>. Kho chỉ được
                            nhập lại khi bạn bấm <b>Đã nhận hàng</b> trên phiếu.</p>
                    </div>
                </div>

                <div class="rt-modal-foot rt-foot-center">
                    <button type="button" class="rt-btn-ghost" data-close>Huỷ</button>
                    {{-- Lưu tạm: giữ nội dung đang nhập dở ở MÁY NÀY, chưa tạo phiếu nào
                         trong hệ thống — dùng khi đang lập dở thì phải rời máy. --}}
                    <button type="button" class="rt-btn-ghost" id="ncSaveDraft" disabled>
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                            <path d="M17 21v-8H7v8M7 3v5h8" />
                        </svg>
                        Lưu tạm
                    </button>
                    <button type="submit" class="rt-btn-primary" id="ncSubmit" disabled>Lập phiếu trả hàng</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Form ẩn cho các thao tác chuyển trạng thái không cần lý do --}}
    <form id="rtActionForm" method="POST" class="d-none">
        @csrf
        @method('PUT')
        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
        <input type="hidden" name="status" id="rtActionStatus">
    </form>

    <style>
        .rt {
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626;
            background: #fff;
        }

        /* Header */
        .rt-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .rt-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .rt-refund { font-size: 13px; color: #595959; }
        .rt-refund b { color: #262626; }
        .rt-refund em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Bộ lọc */
        .rt-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .rt-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .rt-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .rt-searchbox { display: flex; border-radius: 4px; }
        .rt-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13, 110, 253, .25); }
        .rt-search-input {
            height: 34px; width: 300px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .rt-search-input::placeholder { color: #bfbfbf; }
        .rt-searchbox:focus-within .rt-search-input, .rt-searchbox:focus-within .rt-search-btn { border-color: #86b7fe; }
        .rt-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .rt-search-btn:hover { color: #1890ff; }

        /* Khoảng ngày: nút mở + bảng lịch 2 tháng (đồng bộ với trang Đơn hàng) */
        .rt-daterange { position: relative; display: inline-flex; }
        .rt-daterange-trigger {
            height: 34px; display: inline-flex; align-items: center; gap: 8px; padding: 0 12px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; font-size: 13px; color: #262626;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .rt-daterange-trigger:hover, .rt-daterange.open .rt-daterange-trigger { border-color: #1890ff; color: #1890ff; }
        .rt-daterange-trigger > svg:first-child { color: #8c8c8c; flex-shrink: 0; }
        .rt-daterange-trigger:hover > svg:first-child,
        .rt-daterange.open .rt-daterange-trigger > svg:first-child { color: #1890ff; }
        .rt-daterange-label { white-space: nowrap; }
        .rt-daterange-caret { color: #8c8c8c; transition: transform .2s; }
        .rt-daterange.open .rt-daterange-caret { transform: rotate(180deg); }

        .rt-cal-pop {
            position: absolute; top: calc(100% + 6px); left: 0; z-index: 1060; display: flex; background: #fff;
            border: 1px solid #e6e6e6; border-radius: 8px; box-shadow: 0 8px 30px rgba(0, 0, 0, .14);
            overflow: hidden; transform-origin: top left; animation: rtCalIn .14s ease;
        }
        @keyframes rtCalIn { from { opacity: 0; transform: translateY(-4px) scale(.98); } to { opacity: 1; transform: none; } }
        .rt-cal-pop[hidden] { display: none; }
        .rt-cal-presets {
            display: flex; flex-direction: column; gap: 2px; padding: 10px; border-right: 1px solid #f0f0f0;
            background: #fafafa; min-width: 132px;
        }
        .rt-cal-preset {
            text-align: left; border: 0; background: none; border-radius: 4px; padding: 8px 10px; font-size: 13px;
            color: #595959; cursor: pointer; transition: background .15s, color .15s;
        }
        .rt-cal-preset:hover { background: #e6f7ff; color: #1890ff; }
        .rt-cal-main { display: flex; flex-direction: column; }
        .rt-cal-months { display: flex; gap: 8px; padding: 12px; }
        .rt-cal { width: 244px; }
        .rt-cal-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .rt-cal-title { font-size: 13px; font-weight: 700; color: #262626; }
        .rt-cal-nav {
            width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; color: #595959; cursor: pointer;
            transition: all .15s;
        }
        .rt-cal-nav:hover { border-color: #1890ff; color: #1890ff; }
        .rt-cal-nav[disabled] { visibility: hidden; }
        .rt-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
        .rt-cal-dow { text-align: center; font-size: 11px; font-weight: 600; color: #bfbfbf; padding: 4px 0; }
        .rt-cal-day {
            height: 32px; border: 0; background: none; border-radius: 4px; font-size: 12px; color: #262626;
            cursor: pointer; transition: background .12s, color .12s; position: relative;
        }
        .rt-cal-day:hover:not(.is-empty) { background: #e6f7ff; }
        .rt-cal-day.is-empty { cursor: default; }
        .rt-cal-day.in-range { background: #e6f7ff; border-radius: 0; }
        .rt-cal-day.is-start, .rt-cal-day.is-end { background: #1890ff; color: #fff; }
        .rt-cal-day.is-start { border-radius: 4px 0 0 4px; }
        .rt-cal-day.is-end { border-radius: 0 4px 4px 0; }
        .rt-cal-day.is-start.is-end { border-radius: 4px; }
        .rt-cal-day.is-today::after {
            content: ''; position: absolute; left: 50%; bottom: 4px; transform: translateX(-50%);
            width: 4px; height: 4px; border-radius: 50%; background: #1890ff;
        }
        .rt-cal-day.is-start.is-today::after, .rt-cal-day.is-end.is-today::after { background: #fff; }
        .rt-cal-foot {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px;
            border-top: 1px solid #f0f0f0;
        }
        .rt-cal-range { font-size: 13px; color: #595959; }
        .rt-cal-foot-btns { display: flex; gap: 8px; }
        .rt-cal-btn { height: 30px; padding: 0 14px; }
        @media (max-width: 720px) {
            .rt-cal-pop { flex-direction: column; }
            .rt-cal-presets { flex-direction: row; flex-wrap: wrap; border-right: 0; border-bottom: 1px solid #f0f0f0; min-width: 0; }
            .rt-cal-months { flex-direction: column; }
        }

        .rt-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .rt-select.is-block { max-width: none; width: 100%; }

        .rt-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .rt-clear:hover { background: #fff1f0; color: #ff4d4f; }

        .rt-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .rt-toolbar-adv.is-open { display: flex; }

        .rt-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .rt-adv-btn:hover, .rt-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .rt-adv-caret { transition: transform .2s; }
        .rt-adv-btn.is-open .rt-adv-caret { transform: rotate(180deg); }
        .rt-adv-count {
            display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff; font-size: 11px; font-weight: 600;
        }

        .rt-util { position: relative; }
        .rt-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .rt-util-btn:hover, .rt-util.open .rt-util-btn { border-color: #1890ff; color: #1890ff; }
        .rt-util-caret { transition: transform .2s; }
        .rt-util.open .rt-util-caret { transform: rotate(180deg); }
        .rt-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 190px; z-index: 1050; background: #fff;
            border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px; box-shadow: 0 6px 24px rgba(0, 0, 0, .12); display: none;
        }
        .rt-util.open .rt-util-menu { display: block; }
        .rt-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; background: none;
            border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none; cursor: pointer;
        }
        .rt-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .rt-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .rt-util-item:hover svg { color: #1890ff; }

        .rt-add-btn { display: inline-flex; align-items: center; gap: 6px; }
        .rt-add-btn svg { flex-shrink: 0; }

        /* Dải "có phiếu mới" */
        .rt-live {
            display: flex; align-items: center; gap: 8px; width: calc(100% - 40px); margin: 0 20px 10px;
            padding: 9px 14px; border: 1px solid #bae0ff; border-radius: 9999px; background: #f0f8ff;
            font-size: 13px; color: #0958d9; cursor: pointer; text-align: left; transition: background .15s, border-color .15s;
        }
        .rt-live:hover { background: #e6f4ff; border-color: #91caff; }
        .rt-live-dot { width: 8px; height: 8px; border-radius: 9999px; background: #1890ff; flex-shrink: 0; animation: rtLivePulse 1.6s ease-in-out infinite; }
        @keyframes rtLivePulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
        @media (prefers-reduced-motion: reduce) { .rt-live-dot { animation: none; } }
        .rt-live-cta { margin-left: auto; font-weight: 600; text-decoration: underline; white-space: nowrap; }

        /* Thanh bulk NỔI — đồng bộ với trang Đơn hàng/Sản phẩm/Khách hàng:
           pill trắng cố định giữa vùng nội dung, bù chiều rộng sidebar. */
        .rt-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0, 0, 0, .15);
        }
        body:has(.jh-sidebar.collapsed) .rt-bulk { left: 48px; }
        @media (max-width: 820px) { .rt-bulk { left: 0; } }
        .rt-bulk-count { font-size: 13px; font-weight: 500; color: #262626; white-space: nowrap; }
        .rt-bulk-count b { color: #1890ff; }
        .rt-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .rt-bulk-clear:hover { color: #262626; }
        .rt-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .rt-bulk-actions .rt-btn-ghost, .rt-bulk-actions .rt-btn-primary { display: inline-flex; align-items: center; gap: 6px; }
        .rt-bulk-actions svg { flex-shrink: 0; }

        /* Bảng */
        .rt-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; scrollbar-width: thin; }
        .rt-table-wrap::-webkit-scrollbar { height: 11px; }
        .rt-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        .rt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rt-table thead tr { background: #f0f0f0; color: #262626; }
        .rt-table th, .rt-table td { padding: 13px 20px; vertical-align: middle; white-space: nowrap; }
        .rt-table th { font-weight: 700; text-align: left; }
        .rt-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .rt-table tbody tr:hover { background: #fafafa; }

        .rt-c-check { width: 38px; text-align: center; }
        .rt-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        .rt-table th.rt-c-stt, .rt-table td.rt-c-stt { width: 1%; text-align: center; color: #8c8c8c; }
        .rt-table th.rt-c-code, .rt-table td.rt-c-code { width: 1%; }
        .rt-table th.rt-c-order, .rt-table td.rt-c-order { width: 1%; min-width: 190px; }
        /* Cột "Lý do" co giãn hút hết khoảng dư -> bảng phủ đều chiều ngang */
        .rt-table th.rt-c-reason, .rt-table td.rt-c-reason { width: 100%; max-width: 0; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .rt-table th.rt-c-qty, .rt-table td.rt-c-qty { width: 1%; text-align: center; }
        .rt-table th.rt-c-amount, .rt-table td.rt-c-amount { width: 1%; }
        .rt-table th.rt-c-status, .rt-table td.rt-c-status { width: 1%; text-align: center; }
        .rt-table th.rt-c-date, .rt-table td.rt-c-date { width: 1%; color: #8c8c8c; }
        .rt-table th.rt-c-act, .rt-table td.rt-c-act { width: 1%; text-align: center; }

        .rt-c-code[data-view], .rt-c-order[data-view] { cursor: pointer; }
        .rt-c-code[data-view]:hover .rt-code, .rt-c-order[data-view]:hover .rt-name { color: #1890ff; text-decoration: underline; }

        .rt-code { display: block; font-weight: 600; color: #262626; letter-spacing: .2px; }
        .rt-name { display: block; font-weight: 500; color: #262626; }
        .rt-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; font-weight: 400; }
        .rt-total { display: block; font-weight: 600; color: #262626; }

        .rt-badge {
            display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500;
            border: 1px solid #d9d9d9; color: #595959; background: #fafafa; white-space: nowrap;
        }
        .rt-badge.tone-wait { border-color: #ffd591; color: #d46b08; background: #fff7e6; }
        .rt-badge.tone-info { border-color: #91d5ff; color: #096dd9; background: #e6f7ff; }
        .rt-badge.tone-move { border-color: #b37feb; color: #531dab; background: #f9f0ff; }
        .rt-badge.tone-done { border-color: #b7eb8f; color: #389e0d; background: #f6ffed; }
        .rt-badge.tone-stop { border-color: #ffa39e; color: #cf1322; background: #fff1f0; }

        .rt-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0; cursor: pointer;
            color: #1890ff; display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
            transition: background .15s;
        }
        .rt-rowbtn:hover { background: #e6f7ff; }
        .rt-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Modal */
        .rt-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0, 0, 0, .4); }
        .rt-dialog {
            max-height: 92vh; width: 100%; max-width: 780px; overflow-y: auto; border-radius: 6px; background: #fff;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .2); scrollbar-width: thin;
        }
        .rt-dialog::-webkit-scrollbar { width: 11px; }
        .rt-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .rt-dialog-sm { max-width: 460px; }

        .rt-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .rt-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .rt-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; }
        .rt-modal-x:hover { color: #262626; }
        .rt-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 18px; }
        .rt-modal-foot {
            position: sticky; bottom: 0; display: flex; align-items: center; justify-content: center; gap: 12px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px; flex-wrap: wrap;
        }
        .rt-modal-foot.rt-foot-center { justify-content: center; gap: 8px; }
        .rt-foot-right { display: flex; gap: 8px; }
        .rt-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }

        .rt-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px;
            font-weight: 500; color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none;
        }
        .rt-btn-ghost:hover { border-color: #bfbfbf; }
        .rt-btn-primary {
            height: 34px; border: 0; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #fff; background: #1890ff; cursor: pointer; transition: background .15s;
        }
        .rt-btn-primary:hover:not([disabled]) { background: #40a9ff; }
        .rt-btn-primary[disabled] { opacity: .5; cursor: not-allowed; }
        .rt-btn-danger {
            height: 34px; border: 0; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #fff; background: #ff4d4f; cursor: pointer; transition: background .15s;
        }
        .rt-btn-danger:hover { background: #ff7875; }

        /* Nội dung modal chi tiết */
        .rt-view-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .rt-view-ident { display: flex; flex-direction: column; gap: 2px; margin-right: auto; }
        .rt-view-code { font-size: 15px; font-weight: 700; color: #262626; }
        .rt-view-date { font-size: 12px; color: #8c8c8c; }

        .rt-alert {
            display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 6px;
            border: 1px solid #ffccc7; background: #fff2f0; color: #cf1322;
        }
        .rt-alert[hidden] { display: none; }
        .rt-alert svg { flex-shrink: 0; margin-top: 1px; }
        .rt-alert-body { display: flex; flex-direction: column; gap: 2px; }
        .rt-alert-body b { font-size: 13px; }
        .rt-alert-body span { font-size: 12px; color: #a8181a; }

        /* Stepper tiến trình xử lý phiếu */
        .rt-steps { list-style: none; margin: 0; padding: 4px 0 2px; display: flex; }
        .rt-step { position: relative; flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; text-align: center; min-width: 0; }
        .rt-step::before, .rt-step::after { content: ''; position: absolute; top: 13px; height: 2px; background: #e8e8e8; }
        .rt-step::before { left: 0; right: 50%; }
        .rt-step::after { left: 50%; right: 0; }
        .rt-step:first-child::before, .rt-step:last-child::after { display: none; }
        .rt-step-dot {
            position: relative; z-index: 1; width: 28px; height: 28px; border-radius: 50%; display: inline-flex;
            align-items: center; justify-content: center; background: #fff; border: 2px solid #e8e8e8; color: #bfbfbf;
            font-size: 12px; font-weight: 700;
        }
        .rt-step-label { font-size: 11px; line-height: 1.3; color: #8c8c8c; }
        .rt-step-time { font-size: 10px; color: #bfbfbf; }
        .rt-step.is-done .rt-step-dot { background: #52c41a; border-color: #52c41a; color: #fff; }
        .rt-step.is-done::before, .rt-step.is-done::after { background: #52c41a; }
        .rt-step.is-done .rt-step-label { color: #595959; }
        .rt-step.is-current .rt-step-dot { background: #1890ff; border-color: #1890ff; color: #fff; box-shadow: 0 0 0 4px #e6f7ff; }
        .rt-step.is-current::before { background: #52c41a; }
        .rt-step.is-current .rt-step-label { color: #1890ff; font-weight: 600; }
        .rt-step.is-stop .rt-step-dot { background: #ff4d4f; border-color: #ff4d4f; color: #fff; box-shadow: 0 0 0 4px #fff1f0; }
        .rt-step.is-stop::before { background: #ff4d4f; }
        .rt-step.is-stop .rt-step-label { color: #cf1322; font-weight: 600; }
        .rt-steps.is-stopped .rt-step:not(.is-done):not(.is-stop) { opacity: .5; }

        .rt-view-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .rt-view-sec { display: flex; flex-direction: column; gap: 10px; }
        .rt-view-sec[hidden] { display: none; }
        .rt-sec-title {
            margin: 0; padding-bottom: 6px; border-bottom: 1px solid #f0f0f0; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .05em; color: #8c8c8c;
        }
        .rt-view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .rt-cell { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .rt-cell.is-full { grid-column: span 2; }
        .rt-lb { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c; }
        .rt-vl { font-size: 13px; color: #262626; word-break: break-word; }
        .rt-req { color: #ff4d4f; }
        .rt-hint { margin: 0; font-size: 11px; color: #bfbfbf; }

        /* Bảng sản phẩm */
        .rt-items { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rt-items thead tr { background: #fafafa; }
        .rt-items th, .rt-items td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        .rt-items th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c; }
        .rt-items th.rt-i-price, .rt-items td.rt-i-price, .rt-items th.rt-i-total, .rt-items td.rt-i-total { text-align: right; white-space: nowrap; }
        .rt-items th.rt-i-qty, .rt-items td.rt-i-qty { text-align: center; width: 1%; white-space: nowrap; }
        .rt-item-name { display: block; font-weight: 500; color: #262626; }
        .rt-item-sub { display: block; margin-top: 2px; font-size: 11px; color: #8c8c8c; }
        .rt-qty { width: 70px; text-align: center; }

        .rt-sum { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; font-size: 13px; }
        .rt-sum > div { display: flex; gap: 24px; justify-content: space-between; min-width: 260px; }
        .rt-sum span { color: #8c8c8c; }
        .rt-sum b { color: #262626; }
        .rt-sum .is-total { padding-top: 6px; border-top: 1px solid #f0f0f0; font-size: 14px; }
        .rt-sum .is-total b { color: #cf1322; }
        .rt-nc-sum { margin-top: 14px; }

        /* Lịch sử */
        .rt-timeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
        .rt-timeline li { display: flex; gap: 10px; align-items: flex-start; font-size: 13px; }
        .rt-tl-dot { width: 8px; height: 8px; margin-top: 5px; border-radius: 50%; background: #1890ff; flex-shrink: 0; }
        .rt-tl-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .rt-tl-time { font-size: 11px; color: #bfbfbf; }
        .rt-tl-note { font-size: 12px; color: #8c8c8c; }
        .rt-timeline-empty { font-size: 12px; color: #bfbfbf; }

        /* Form */
        .rt-note-form { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .rt-note-form .rt-btn-ghost { align-self: flex-start; }
        .rt-textarea {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 8px 12px; font-size: 13px;
            font-family: inherit; color: #262626; outline: none; resize: vertical; line-height: 1.5;
        }
        .rt-textarea::placeholder { color: #bfbfbf; }
        .rt-input {
            width: 100%; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            font-family: inherit; color: #262626; background: #fff; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .rt-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 3px rgba(13, 110, 253, .15); }
        .rt-input::placeholder { color: #bfbfbf; }
        .rt-input[readonly] { background: #fafafa; color: #8c8c8c; }
        .rt-input-sm { height: 30px; padding: 0 8px; font-size: 12px; }

        .rt-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .rt-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .rt-field.is-full { grid-column: span 2; }
        /* Lưới 3 cột đều nhau — cùng khuôn với modal tạo đơn hàng */
        .rt-grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px 16px; align-items: start; }
        .rt-grid-3 .is-full-3 { grid-column: 1 / -1; }
        .rt-bank { grid-template-columns: repeat(3, 1fr); }
        .rt-bank[hidden] { display: none; }
        .rt-opt { font-weight: 400; text-transform: none; letter-spacing: 0; color: #bfbfbf; }
        .rt-view-grid[hidden] { display: none; }
        .rt-items tbody tr.is-off { opacity: .55; }

        .rt-settle { display: flex; flex-direction: column; gap: 12px; }
        .rt-settle-foot { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; flex-wrap: wrap; }

        .rt-checkline { display: flex; align-items: center; gap: 8px; font-size: 13px; color: #262626; cursor: pointer; margin: 0; }
        .rt-checkline em { font-style: normal; color: #bfbfbf; font-size: 12px; }

        /* Ô nhập tiền tệ VN */
        .rt-money { position: relative; display: flex; align-items: center; }
        .rt-money-input { padding-right: 24px; text-align: right; }
        .rt-money-suffix { position: absolute; right: 10px; color: #8c8c8c; font-size: 13px; pointer-events: none; }

        .rt-note-box {
            margin: 0; padding: 10px 12px; border: 1px solid #ffe58f; border-radius: 4px; background: #fffbe6;
            font-size: 12px; line-height: 1.55; color: #595959;
        }
        .rt-note-box[hidden] { display: none; }
        .rt-note-box.is-error { border-color: #ffccc7; background: #fff2f0; color: #cf1322; }

        /* Dropdown chọn đơn hàng ở modal lập phiếu.
           Panel dùng position: fixed + toạ độ do JS tính từ nút, KHÔNG absolute:
           modal là khung có overflow riêng, panel absolute sẽ bị nó cắt mất khi
           modal còn thấp (lúc chưa chọn đơn). */
        .rt-dd { position: relative; max-width: 460px; }
        .rt-dd-control {
            width: 100%; height: 34px; display: inline-flex; align-items: center; justify-content: space-between;
            gap: 8px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 0 10px 0 12px;
            font-size: 13px; color: #262626; cursor: pointer; transition: border-color .15s, box-shadow .15s;
        }
        .rt-dd-control:hover, .rt-dd.open .rt-dd-control { border-color: #1890ff; }
        .rt-dd.open .rt-dd-control { box-shadow: 0 0 0 3px rgba(24, 144, 255, .15); }
        .rt-dd-value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: left; }
        .rt-dd-value.is-empty { color: #bfbfbf; }
        .rt-dd-caret { color: #8c8c8c; flex-shrink: 0; transition: transform .2s; }
        .rt-dd.open .rt-dd-caret { transform: rotate(180deg); }

        .rt-dd-panel {
            position: fixed; z-index: 1090; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px;
            box-shadow: 0 8px 28px rgba(0, 0, 0, .16); overflow: hidden;
        }
        .rt-dd-panel[hidden] { display: none; }
        .rt-dd-search { padding: 8px; border-bottom: 1px solid #f0f0f0; }
        .rt-dd-list { max-height: 232px; overflow-y: auto; scrollbar-width: thin; }
        .rt-dd-list::-webkit-scrollbar { width: 10px; }
        .rt-dd-list::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .rt-ac-item {
            display: flex; flex-direction: column; gap: 3px; padding: 9px 12px; cursor: pointer;
            border-bottom: 1px solid #f5f5f5; transition: background .12s;
        }
        .rt-ac-item:last-child { border-bottom: 0; }
        .rt-ac-item:hover { background: #f5f7fa; }
        .rt-ac-item b { font-size: 13px; color: #262626; font-weight: 600; }
        .rt-ac-item > span { font-size: 12px; color: #8c8c8c; }
        .rt-ac-empty, .rt-ac-loading { padding: 10px 12px; font-size: 12px; color: #bfbfbf; margin: 0; }

        /* Dải nhắc bản nháp */
        .rt-draft {
            display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 6px;
            border: 1px solid #ffe58f; background: #fffbe6; color: #614700; font-size: 13px;
        }
        .rt-draft[hidden] { display: none; }
        .rt-draft svg { flex-shrink: 0; color: #d48806; }
        .rt-draft-text { flex: 1; min-width: 0; }
        .rt-draft-actions { display: flex; gap: 8px; flex-shrink: 0; }
        .rt-btn-xs { height: 28px; padding: 0 12px; font-size: 12px; }
        #ncSaveDraft { display: inline-flex; align-items: center; gap: 6px; }
        #ncSaveDraft[disabled] { opacity: .5; cursor: not-allowed; }

        /* Thẻ thông tin đơn đã chọn */
        .rt-picked { padding: 12px 14px; border: 1px solid #bae0ff; border-radius: 6px; background: #f7fbff; }
        .rt-picked[hidden] { display: none; }

        @media (max-width: 860px) { .rt-bank, .rt-grid-3 { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 640px) {
            .rt-view-cols, .rt-view-grid, .rt-form-grid, .rt-bank, .rt-grid-3 { grid-template-columns: 1fr; }
            .rt-cell.is-full, .rt-field.is-full { grid-column: span 1; }
            .rt-search-input { width: 100%; }
            .rt-searchbox { flex: 1; }
        }
    </style>

    <script>
        (function () {
            const URL_BASE = @json(url('admin/returns'));
            const EXPORT_URL = @json(route('admin.returns.export'));
            const SEARCH_ORDERS_URL = @json(route('admin.returns.searchOrders'));
            const ORDER_LIST_URL = @json(route('admin.orders.index'));
            const STATUSES = @json($STATUSES);
            const TONES = @json($TONES);
            const ACTIONS = @json($ACTIONS);
            const REASONS = @json($REASONS);
            const ORDER_STATUSES = @json(\App\Http\Controllers\OrderController::STATUSES);

            // Luồng xử lý phiếu (đúng thứ tự backend) — dùng dựng stepper tiến trình.
            const PIPELINE = ['pending', 'approved', 'received', 'refunded'];
            const STOP_STATUSES = { rejected: 'Từ chối', cancelled: 'Khách rút' };

            const $ = (id) => document.getElementById(id);
            const $filter = $('rtFilter');
            const $viewOverlay = $('rtViewOverlay');
            const $reasonOverlay = $('rtReasonOverlay');
            const $createOverlay = $('rtCreateOverlay');
            const actionForm = $('rtActionForm');

            const money = (n) => new Intl.NumberFormat('vi-VN').format(Number(n) || 0) + '₫';
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));
            const fmtDateTime = (s) => {
                if (!s) return '—';
                const d = new Date(s);
                return isNaN(d) ? s : d.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
            };
            // adminToast nhận object { title, body, tone } — bọc lại cho gọn chỗ gọi.
            const toast = (title, tone) => {
                if (window.adminToast) window.adminToast({ title, tone: tone || 'info' });
                else alert(title);
            };

            // ---------- Bộ lọc ----------
            $filter.querySelectorAll('select').forEach((sel) => sel.addEventListener('change', () => $filter.submit()));

            // ---------- Bảng lịch chọn khoảng ngày (2 tháng) ----------
            (function () {
                const wrap = $('rtDateRange');
                const trigger = $('rtDateTrigger');
                const pop = $('rtCalPop');
                const label = $('rtDateLabel');
                const rangeText = $('rtCalRange');
                const $from = $('rtFrom');
                const $to = $('rtTo');
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
                let hover = null;                        // ngày đang di chuột khi đã chọn bắt đầu
                let lastHover = '';                      // ô vừa hover (tránh tô lại thừa)
                let view = startOfMonth(start || today); // tháng hiển thị ở lịch bên trái

                const syncLabel = () => {
                    label.textContent = (start || end) ? `${disp(start) || '…'} → ${disp(end) || '…'}` : 'Chọn ngày';
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
                        for (let k = 0; k < offset; k++) cells += `<button type="button" class="rt-cal-day is-empty" disabled></button>`;
                        for (let d = 1; d <= days; d++) {
                            const date = new Date(y, mo, d);
                            const todayCls = sameDay(date, today) ? ' is-today' : '';
                            cells += `<button type="button" class="rt-cal-day${todayCls}" data-day="${ymd(date)}">${d}</button>`;
                        }
                        pop.querySelector(`.rt-cal[data-cal="${i}"]`).innerHTML =
                            `<div class="rt-cal-hd">
                                <button type="button" class="rt-cal-nav" data-nav="-1" ${i === 1 ? 'disabled' : ''}>&lsaquo;</button>
                                <span class="rt-cal-title">Tháng ${mo + 1}/${y}</span>
                                <button type="button" class="rt-cal-nav" data-nav="1" ${i === 0 ? 'disabled' : ''}>&rsaquo;</button>
                            </div>` +
                            `<div class="rt-cal-grid">${DOW.map((w) => `<span class="rt-cal-dow">${w}</span>`).join('')}</div>` +
                            `<div class="rt-cal-grid">${cells}</div>`;
                    });
                    paint();
                }

                // Tô lại trạng thái range trên các ô sẵn có — chạy khi hover/chọn (mượt, không dựng lại DOM).
                function paint() {
                    const hi = end || hover;
                    pop.querySelectorAll('.rt-cal-day[data-day]').forEach((btn) => {
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

                // Điều hướng tháng + chọn ngày. Chặn nổi bọt để không kích hoạt đóng-ngoài.
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

                // Xem trước khoảng khi mới chọn ngày bắt đầu — chỉ tô lại khi đổi ô.
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
                pop.querySelectorAll('.rt-cal-preset').forEach((btn) => {
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

                $('rtCalApply').addEventListener('click', (e) => { e.stopPropagation(); apply(); });
                // Xoá lọc ngày rồi tải lại danh sách (hiện tất cả).
                $('rtCalClear').addEventListener('click', (e) => {
                    e.stopPropagation();
                    start = null; end = null; hover = null; lastHover = '';
                    $from.value = ''; $to.value = '';
                    $filter.submit();
                });
            })();

            // Nút "Nâng cao": ẩn/hiện hàng bộ lọc phụ (nhớ trạng thái qua localStorage).
            (function () {
                const btn = $('rtAdvToggle');
                const row = $('rtAdvRow');
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('rt-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('rt-adv-open', open ? '1' : '0');
                });
            })();

            // Gõ tới đâu lọc tới đó, chờ ngưng gõ 400ms (giống trang Đơn hàng).
            (function () {
                const search = $filter.querySelector('input[name="keyword"]');
                let t = null;
                search.addEventListener('input', () => {
                    clearTimeout(t);
                    t = setTimeout(() => $filter.submit(), 400);
                });
            })();

            // ---------- Dropdown Tiện ích ----------
            const util = $('rtUtil');
            $('rtUtilBtn').addEventListener('click', (e) => { e.stopPropagation(); util.classList.toggle('open'); });
            document.addEventListener('click', () => util.classList.remove('open'));

            // ---------- Modal dùng chung ----------
            const openOverlay = (el) => { el.style.display = 'flex'; };
            const closeOverlay = (el) => { el.style.display = 'none'; };
            [$viewOverlay, $reasonOverlay, $createOverlay].forEach((ov) => {
                ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeOverlay(ov)));
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') [$viewOverlay, $reasonOverlay, $createOverlay].forEach(closeOverlay);
            });
            const anyModalOpen = () => Array.from(document.querySelectorAll('.rt-overlay'))
                .some((ov) => ov.style.display === 'flex');

            /* ---------- Ô nhập tiền VN ----------
               Hiển thị có phân nhóm nghìn (1.500.000) cho người đọc, số thô nằm ở
               input ẩn để gửi lên server — không phải gỡ dấu chấm lúc submit. */
            function bindMoney(inputId, hiddenId, onChange) {
                const el = $(inputId), hid = $(hiddenId);
                const format = () => {
                    const digits = el.value.replace(/\D/g, '');
                    const num = digits ? parseInt(digits, 10) : 0;
                    el.value = digits ? num.toLocaleString('vi-VN') : '';
                    hid.value = num;
                    if (onChange) onChange();
                };
                el.addEventListener('input', format);
                el.addEventListener('blur', () => {
                    if (!el.value.trim()) { el.value = '0'; hid.value = 0; if (onChange) onChange(); }
                });
            }
            const setMoney = (inputId, hiddenId, val) => {
                const n = Math.max(0, Number(val) || 0);
                $(hiddenId).value = n;
                $(inputId).value = n.toLocaleString('vi-VN');
            };
            const rawOf = (hiddenId) => Math.max(0, Number($(hiddenId).value) || 0);

            // Số tài khoản chỉ có nghĩa khi hoàn bằng chuyển khoản.
            function syncBank(select, row) { row.hidden = select.value !== 'bank_transfer'; }

            // ---------- Chi tiết phiếu ----------
            let current = null;

            function renderItems(items) {
                const tbody = $('vItems');
                if (!items || !items.length) {
                    tbody.innerHTML = '<tr><td colspan="4" class="rt-empty">Phiếu không có sản phẩm nào.</td></tr>';
                    return;
                }
                tbody.innerHTML = items.map((it) => {
                    const meta = [it.variant_sku, it.variant_name].filter(Boolean).join(' · ');
                    return `<tr>
                            <td>
                                <span class="rt-item-name">${esc(it.product_name || '—')}</span>
                                ${meta ? `<span class="rt-item-sub">${esc(meta)}</span>` : ''}
                            </td>
                            <td class="rt-i-price">${money(it.unit_price)}</td>
                            <td class="rt-i-qty">${Number(it.quantity) || 0}</td>
                            <td class="rt-i-total">${money(it.total_price)}</td>
                        </tr>`;
                }).join('');
            }

            function renderHistory(list) {
                const el = $('vHistory');
                if (!list || !list.length) {
                    el.innerHTML = '<li class="rt-timeline-empty">Chưa có thao tác nào được ghi nhận.</li>';
                    return;
                }
                el.innerHTML = list.map((h) => `
                        <li>
                            <span class="rt-tl-dot"></span>
                            <span class="rt-tl-body">
                                <span>${esc(STATUSES[h.from_status] || 'Khởi tạo')} &rarr; <b>${esc(STATUSES[h.to_status] || h.to_status)}</b></span>
                                <span class="rt-tl-time">${fmtDateTime(h.created_at)}</span>
                                ${h.note ? `<span class="rt-tl-note">${esc(h.note)}</span>` : ''}
                            </span>
                        </li>`).join('');
            }

            // Thời điểm phiếu bước vào một trạng thái (mốc thời gian hoặc lịch sử).
            function stepTime(r, step) {
                if (step === 'pending') return r.created_at || null;
                const map = {
                    approved: r.approved_at, received: r.received_at, refunded: r.refunded_at,
                    rejected: r.closed_at, cancelled: r.closed_at,
                };
                if (map[step]) return map[step];
                const h = (r.histories || []).find((x) => x.to_status === step);
                return h ? h.created_at : null;
            }

            // Stepper tiến trình đúng luồng backend.
            function renderSteps(r) {
                const el = $('vSteps');
                const status = r.status || 'pending';
                const stopped = Object.prototype.hasOwnProperty.call(STOP_STATUSES, status);
                const reached = new Set(['pending', ...(r.histories || []).map((h) => h.to_status)]);
                const curIdx = PIPELINE.indexOf(status);

                const shortTime = (t) => {
                    if (!t) return '';
                    const d = new Date(t);
                    return isNaN(d) ? '' : d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' }) + ' ' + d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
                };

                let html = PIPELINE.map((step, i) => {
                    let cls = '', mark = i + 1;
                    if (stopped) {
                        if (reached.has(step)) { cls = 'is-done'; mark = '&#10003;'; }
                    } else if (i < curIdx) { cls = 'is-done'; mark = '&#10003;'; }
                    else if (i === curIdx) { cls = (status === 'refunded') ? 'is-done' : 'is-current'; if (status === 'refunded') mark = '&#10003;'; }
                    const t = (cls === 'is-done' || cls === 'is-current') ? shortTime(stepTime(r, step)) : '';
                    return `<li class="rt-step ${cls}">
                            <span class="rt-step-dot">${mark}</span>
                            <span class="rt-step-label">${esc(STATUSES[step] || step)}</span>
                            ${t ? `<span class="rt-step-time">${t}</span>` : ''}
                        </li>`;
                }).join('');

                if (stopped) {
                    const t = shortTime(stepTime(r, status));
                    html += `<li class="rt-step is-stop">
                            <span class="rt-step-dot">&#10007;</span>
                            <span class="rt-step-label">${esc(STOP_STATUSES[status])}</span>
                            ${t ? `<span class="rt-step-time">${t}</span>` : ''}
                        </li>`;
                }
                el.className = 'rt-steps' + (stopped ? ' is-stopped' : '');
                el.innerHTML = html;
            }

            /* Nút thao tác dựng từ next_statuses API trả về — không tự đoán bước kế
               tiếp ở đây, nếu không giao diện sẽ lệch với luật của server. */
            function renderActions(r) {
                const box = $('vActions');
                const next = r.next_statuses || [];
                if (!next.length) {
                    box.innerHTML = '<span class="rt-hint">Phiếu đã ở trạng thái cuối, không đổi tiếp được.</span>';
                    return;
                }
                box.innerHTML = next.map((st) => {
                    const label = ACTIONS[st] || STATUSES[st] || st;
                    // Từ chối / huỷ đều cần lý do -> mở modal nhập lý do thay vì gửi thẳng.
                    if (st === 'rejected' || st === 'cancelled') {
                        return `<button type="button" class="rt-btn-danger" data-reason="${st}">${esc(label)}</button>`;
                    }
                    return `<button type="button" class="rt-btn-primary" data-status="${st}">${esc(label)}</button>`;
                }).join('');
            }

            function fillView(r) {
                current = r;
                const set = (id, v) => { $(id).textContent = v; };

                set('vCode', r.return_code || '—');
                set('vCreated', `Tạo lúc ${fmtDateTime(r.created_at)}`);

                const badge = $('vStatus');
                badge.textContent = STATUSES[r.status] || r.status || '—';
                badge.className = 'rt-badge tone-' + (TONES[r.status] || 'info');

                renderSteps(r);

                // Cảnh báo nhánh kết thúc tiêu cực.
                const alert = $('vAlert');
                if (r.status === 'rejected' || r.status === 'cancelled') {
                    alert.hidden = false;
                    set('vAlertTitle', r.status === 'rejected' ? 'Yêu cầu đã bị từ chối' : 'Khách đã rút yêu cầu');
                    const when = fmtDateTime(r.closed_at);
                    const last = (r.histories || []).slice().reverse().find((h) => h.to_status === r.status);
                    const reason = r.reject_reason || (last ? last.note : '') || '';
                    set('vAlertDesc', (when !== '—' ? `Lúc ${when}` : '') + (reason ? ` · ${reason}` : ''));
                } else {
                    alert.hidden = true;
                }

                const o = r.order || {};
                set('vOrderCode', o.order_code || '—');
                set('vOrderName', o.recipient_name || '—');
                set('vOrderPhone', o.recipient_phone || '—');
                set('vOrderTotal', o.total_amount != null ? money(o.total_amount) : '—');
                $('vOrderBtn').href = ORDER_LIST_URL + '?keyword=' + encodeURIComponent(o.order_code || '');

                set('vReason', REASONS[r.reason] || r.reason || '—');
                set('vRequestedBy', r.requested_by === 'admin' ? 'Nhân viên cửa hàng' : 'Khách hàng');
                set('vReasonNote', r.reason_note || '—');

                renderItems(r.items);
                renderHistory(r.histories);
                renderActions(r);

                // Sổ đã chốt (đã hoàn tiền / từ chối / khách rút) thì chỉ xem, không sửa số.
                const editable = ['pending', 'approved', 'received'].includes(r.status);
                $('vSettleSec').hidden = !editable;
                $('vSettleRO').hidden = editable;

                if (editable) {
                    $('rtSettleForm').action = `${URL_BASE}/${r.id}/settle`;
                    $('vItemsAmount').value = (Number(r.items_amount) || 0).toLocaleString('vi-VN');
                    setMoney('vShipFee', 'vShipFeeRaw', r.shipping_fee);
                    setMoney('vDeduction', 'vDeductionRaw', r.deduction);
                    $('vRefundMethod').value = r.refund_method || 'bank_transfer';
                    $('vBankAccount').value = r.bank_account || '';
                    $('vBankHolder').value = r.bank_holder || '';
                    $('vBankName').value = r.bank_name || '';
                    $('vRestock').checked = r.restock !== false;
                    // Hàng đã nhập kho rồi thì cờ này không còn gì để đổi.
                    $('vRestock').disabled = r.status === 'received';
                    syncBank($('vRefundMethod'), $('vBankRow'));
                    recalcSettle();
                } else {
                    set('roItems', money(r.items_amount));
                    set('roShip', money(r.shipping_fee));
                    set('roDeduct', money(r.deduction));
                    set('roTotal', money(r.refund_amount));
                }

                $('rtNoteForm').action = `${URL_BASE}/${r.id}/note`;
                $('vAdminNote').value = r.admin_note || '';
            }

            function openView(id) {
                fetch(`${URL_BASE}/${id}/detail`, { headers: { Accept: 'application/json' } })
                    .then((res) => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
                    .then((d) => { fillView(d.data); openOverlay($viewOverlay); })
                    .catch(() => {
                        toast('Không tải được chi tiết phiếu trả hàng.', 'danger');
                    });
            }

            document.querySelector('.rt-table tbody').addEventListener('click', (e) => {
                const el = e.target.closest('[data-view]');
                if (el) openView(Number(el.getAttribute('data-view')));
            });

            /* Tiền hoàn = tiền hàng + phí ship hoàn − khấu trừ, không âm.
               Tính lại y hệt server để con số trên màn hình không khác con số được lưu. */
            function recalcSettle() {
                const items = Number(String($('vItemsAmount').value).replace(/\D/g, '')) || 0;
                $('vRefundAmount').textContent = money(Math.max(0, items + rawOf('vShipFeeRaw') - rawOf('vDeductionRaw')));
            }
            bindMoney('vShipFee', 'vShipFeeRaw', recalcSettle);
            bindMoney('vDeduction', 'vDeductionRaw', recalcSettle);
            $('vRefundMethod').addEventListener('change', function () { syncBank(this, $('vBankRow')); });

            // ---------- Thao tác trên phiếu ----------
            $('vActions').addEventListener('click', (e) => {
                if (!current) return;

                const reason = e.target.closest('[data-reason]');
                if (reason) { openReason(reason.getAttribute('data-reason')); return; }

                const st = e.target.closest('[data-status]');
                if (!st) return;
                const to = st.getAttribute('data-status');

                // Hai bước có hệ quả thật (nhập kho / chi tiền) nên nói rõ hệ quả khi hỏi lại.
                let message;
                if (to === 'received') {
                    message = `Xác nhận đã nhận đủ hàng của phiếu ${current.return_code}? `
                        + (current.restock === false
                            ? 'Phiếu đánh dấu KHÔNG nhập lại kho nên tồn kho giữ nguyên.'
                            : 'Hàng sẽ được nhập lại kho ngay sau thao tác này.');
                } else if (to === 'refunded') {
                    message = `Xác nhận đã hoàn ${money(current.refund_amount)} cho khách? Thao tác này không thể hoàn tác.`;
                } else {
                    message = `Chuyển phiếu ${current.return_code} sang "${STATUSES[to] || to}"?`;
                }

                Promise.resolve(window.sysConfirm({
                    title: 'Xác nhận thao tác',
                    message,
                    confirmText: ACTIONS[to] || 'Xác nhận',
                })).then((ok) => {
                    if (!ok) return;
                    actionForm.action = `${URL_BASE}/${current.id}/status`;
                    $('rtActionStatus').value = to;
                    actionForm.submit();
                });
            });

            // Mở modal nhập lý do (dùng chung Từ chối / Huỷ phiếu).
            function openReason(st) {
                const reject = st === 'rejected';
                const input = $('vReasonInput');
                $('vReasonStatus').value = st;
                $('vReasonTitle').textContent = reject ? 'Từ chối yêu cầu trả hàng' : 'Huỷ phiếu trả hàng';
                $('vReasonLabel').innerHTML = reject
                    ? 'Lý do từ chối <span class="rt-req">*</span>'
                    : 'Lý do huỷ <span class="rt-opt">(không bắt buộc)</span>';
                $('vReasonDesc').innerHTML = `Phiếu <b>${esc(current.return_code)}</b> sẽ chuyển sang <b>${esc(STATUSES[st])}</b> và không thể quay lại trạng thái trước.`;
                $('vReasonSubmit').textContent = reject ? 'Xác nhận từ chối' : 'Xác nhận huỷ';
                input.value = '';
                input.required = reject;
                input.placeholder = reject
                    ? 'VD: Sản phẩm đã qua sử dụng / Quá hạn đổi trả / Không phải hàng của cửa hàng'
                    : 'VD: Khách đổi ý qua điện thoại';
                $('rtReasonForm').action = `${URL_BASE}/${current.id}/status`;
                closeOverlay($viewOverlay);
                openOverlay($reasonOverlay);
                setTimeout(() => input.focus(), 30);
            }

            // ---------- Lập phiếu trả hàng ----------
            (function () {
                const form = $('rtCreateForm');
                let picked = null;   // đơn đang chọn
                let rows = [];       // các dòng còn trả được của đơn đó

                /* ---------- Bản nháp "lưu tạm" ----------
                   Chỉ nằm ở localStorage của máy đang dùng, KHÔNG tạo phiếu nào trong
                   hệ thống: một phiếu nửa vời nằm trong sổ trả hàng sẽ lẫn vào số liệu
                   và làm nhân viên khác tưởng có việc phải xử lý.
                   Số lượng còn trả được luôn tra lại từ server lúc khôi phục, nên bản
                   nháp cũ không bao giờ khiến phiếu vượt quá số thật. */
                const DRAFT_KEY = 'rt-return-draft';

                const readDraft = () => {
                    try { return JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); }
                    catch (e) { return null; }
                };
                const clearDraft = () => {
                    try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* trình duyệt chặn storage */ }
                    $('ncDraft').hidden = true;
                };

                function saveDraft() {
                    if (!picked) return;
                    const items = [];
                    $('ncItems').querySelectorAll('[data-row]').forEach((tr) => {
                        const i = Number(tr.getAttribute('data-row'));
                        const q = Number(tr.querySelector('.rt-qty').value) || 0;
                        if (q > 0) items.push({ id: rows[i].order_item_id, qty: q });
                    });
                    const draft = {
                        at: new Date().toISOString(),
                        order: picked,
                        items,
                        reason: $('ncReason').value,
                        reason_note: $('ncReasonNote').value,
                        refund_method: $('ncRefundMethod').value,
                        bank_account: $('ncBankAccount').value,
                        bank_holder: $('ncBankHolder').value,
                        bank_name: $('ncBankName').value,
                        shipping_fee: rawOf('ncShipFeeRaw'),
                        deduction: rawOf('ncDeductionRaw'),
                        admin_note: $('ncAdminNote').value,
                        restock: $('ncRestock').checked,
                    };
                    try {
                        localStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
                    } catch (e) {
                        toast('Trình duyệt không cho lưu tạm trên máy này.', 'danger');
                        return;
                    }
                    closeOverlay($createOverlay);
                    toast('Đã lưu tạm phiếu của đơn ' + picked.code + '. Mở lại "Lập phiếu trả" để tiếp tục.', 'success');
                }

                // Đổ bản nháp vào form SAU khi danh sách món của đơn đã tải xong.
                function applyDraft(d) {
                    (d.items || []).forEach((x) => {
                        const i = rows.findIndex((r) => r.order_item_id === x.id);
                        if (i >= 0) setRow(i, x.qty);   // setRow tự kẹp theo số còn trả được hiện tại
                    });
                    $('ncReason').value = d.reason || 'defective';
                    $('ncReasonNote').value = d.reason_note || '';
                    $('ncRefundMethod').value = d.refund_method || 'bank_transfer';
                    $('ncBankAccount').value = d.bank_account || '';
                    $('ncBankHolder').value = d.bank_holder || '';
                    $('ncBankName').value = d.bank_name || '';
                    setMoney('ncShipFee', 'ncShipFeeRaw', d.shipping_fee);
                    setMoney('ncDeduction', 'ncDeductionRaw', d.deduction);
                    $('ncAdminNote').value = d.admin_note || '';
                    $('ncRestock').checked = d.restock !== false;
                    syncBank($('ncRefundMethod'), $('ncBankRow'));
                    recalcCreate();
                }

                $('ncSaveDraft').addEventListener('click', saveDraft);
                $('ncDraftDiscard').addEventListener('click', clearDraft);
                $('ncDraftRestore').addEventListener('click', () => {
                    const d = readDraft();
                    if (!d || !d.order) { clearDraft(); return; }
                    $('ncDraft').hidden = true;
                    picked = d.order;
                    $('ncOrderId').value = picked.id;
                    $('ncOrderLabel').textContent = picked.code;
                    $('ncOrderLabel').classList.remove('is-empty');
                    closeDD();
                    loadReturnable(picked.id, d);
                });

                function resetForm() {
                    form.reset();
                    picked = null; rows = [];
                    $('ncOrderId').value = '';
                    $('ncOrderLabel').textContent = 'Chọn đơn hàng đã giao…';
                    $('ncOrderLabel').classList.add('is-empty');
                    $('ncPicked').hidden = true;
                    $('ncOrderNote').hidden = true;
                    $('ncItemsSec').hidden = true;
                    $('ncFormSec').hidden = true;
                    $('ncSubmit').disabled = true;
                    $('ncSubmit').textContent = 'Lập phiếu trả hàng';
                    $('ncSaveDraft').disabled = true;
                    $('ncItems').innerHTML = '';
                    $('ncOrderSearch').value = '';
                    loaded = false;
                    closeDD();
                    setMoney('ncShipFee', 'ncShipFeeRaw', 0);
                    setMoney('ncDeduction', 'ncDeductionRaw', 0);
                    // form.reset() đưa ô "hình thức hoàn" về mặc định nhưng không tự
                    // hiện lại hàng ngân hàng — phải đồng bộ tay, nếu không nhân viên
                    // chọn chuyển khoản mà không thấy chỗ nhập số tài khoản.
                    syncBank($('ncRefundMethod'), $('ncBankRow'));
                    recalcCreate();
                }

                $('rtAddBtn').addEventListener('click', () => {
                    resetForm();
                    openOverlay($createOverlay);

                    // Còn phiếu lập dở ở máy này thì mời khôi phục trước, đừng mở
                    // dropdown đè lên dải nhắc.
                    const draft = readDraft();
                    if (draft && draft.order) {
                        $('ncDraftText').textContent =
                            'Còn một phiếu lập dở cho đơn ' + draft.order.code + ' (lưu lúc ' + fmtDateTime(draft.at) + ').';
                        $('ncDraft').hidden = false;
                        return;
                    }
                    // Mở sẵn danh sách đơn: thao tác thường gặp là lập phiếu cho một
                    // trong vài đơn vừa giao, khỏi bắt nhân viên bấm thêm một nhịp.
                    setTimeout(openDD, 30);
                });

                // ----- Bước 1: dropdown chọn đơn -----
                const dd = $('ncOrderDD');
                const panel = $('ncOrderPanel');
                let loaded = false;      // đã nạp danh sách lần nào chưa
                let searchTimer = null;

                /* Panel là position: fixed nên phải tự đặt toạ độ theo nút. Đổi lại nó
                   thoát khỏi mọi khung overflow (modal, bảng) — không bao giờ bị cắt. */
                function placePanel() {
                    const r = $('ncOrderBtn').getBoundingClientRect();
                    panel.style.width = r.width + 'px';
                    panel.style.left = r.left + 'px';
                    // Thiếu chỗ phía dưới thì lật lên trên nút.
                    const h = panel.offsetHeight || 280;
                    const below = window.innerHeight - r.bottom - 8;
                    panel.style.top = (below >= h || below >= r.top ? r.bottom + 4 : r.top - h - 4) + 'px';
                }

                function openDD() {
                    if (!dd.classList.contains('open')) {
                        dd.classList.add('open');
                        panel.hidden = false;
                        $('ncOrderBtn').setAttribute('aria-expanded', 'true');
                    }
                    placePanel();
                    if (!loaded) loadOrders('');
                    $('ncOrderSearch').focus();
                }

                function closeDD() {
                    dd.classList.remove('open');
                    panel.hidden = true;
                    $('ncOrderBtn').setAttribute('aria-expanded', 'false');
                }

                $('ncOrderBtn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    dd.classList.contains('open') ? closeDD() : openDD();
                });
                // Bấm ra ngoài thì đóng; panel nằm ngoài .rt-dd trong cây hiển thị nên
                // phải kiểm tra cả hai.
                document.addEventListener('click', (e) => {
                    if (panel.hidden || dd.contains(e.target) || panel.contains(e.target)) return;
                    closeDD();
                });
                // Cuộn/đổi cỡ cửa sổ: bám lại theo nút (dùng capture để bắt cả cuộn
                // bên trong modal).
                window.addEventListener('scroll', () => { if (!panel.hidden) placePanel(); }, true);
                window.addEventListener('resize', () => { if (!panel.hidden) placePanel(); });
                // Esc chỉ đóng dropdown, không đóng luôn cả modal đang nhập dở.
                panel.addEventListener('keydown', (e) => {
                    if (e.key !== 'Escape') return;
                    e.stopPropagation();
                    closeDD();
                    $('ncOrderBtn').focus();
                });

                $('ncOrderSearch').addEventListener('input', function () {
                    const q = this.value.trim();
                    clearTimeout(searchTimer);
                    // Gõ tới đâu lọc tới đó nhưng chờ ngưng gõ 300ms, tránh bắn request từng phím.
                    searchTimer = setTimeout(() => loadOrders(q), 300);
                });
                // Ô lọc nằm TRONG form lập phiếu: Enter mặc định sẽ gửi form khi phiếu
                // còn dở dang, nên chặn lại và cho Enter nghĩa là "lọc ngay".
                $('ncOrderSearch').addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    clearTimeout(searchTimer);
                    loadOrders(this.value.trim());
                });

                function renderOrders(html) {
                    $('ncOrderList').innerHTML = html;
                    placePanel();   // chiều cao panel đổi -> đặt lại toạ độ
                }

                function loadOrders(q) {
                    renderOrders('<p class="rt-ac-loading">Đang tải…</p>');
                    fetch(`${SEARCH_ORDERS_URL}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                        .then((r) => r.ok ? r.json() : Promise.reject(r))
                        .then((res) => {
                            loaded = true;
                            const data = res.data || [];
                            renderOrders(data.length
                                ? data.map((o) => `<div class="rt-ac-item" role="option" data-order="${encodeURIComponent(JSON.stringify(o))}">
                                        <b>${esc(o.code)}</b>
                                        <span>${esc(o.name)} · ${esc(o.phone)} · ${money(o.total)}</span>
                                    </div>`).join('')
                                : `<p class="rt-ac-empty">${q ? 'Không có đơn đã giao nào khớp từ khoá.' : 'Chưa có đơn nào đã giao.'}</p>`);
                        })
                        .catch(() => renderOrders('<p class="rt-ac-empty">Không tải được danh sách đơn.</p>'));
                }

                $('ncOrderList').addEventListener('click', (e) => {
                    const item = e.target.closest('[data-order]');
                    if (!item) return;
                    picked = JSON.parse(decodeURIComponent(item.dataset.order));
                    $('ncOrderId').value = picked.id;
                    $('ncOrderLabel').textContent = picked.code;
                    $('ncOrderLabel').classList.remove('is-empty');
                    closeDD();
                    loadReturnable(picked.id);
                });

                function loadReturnable(orderId, draft) {
                    const note = $('ncOrderNote');
                    note.hidden = false;
                    note.className = 'rt-note-box';
                    note.textContent = 'Đang tải sản phẩm của đơn…';
                    $('ncItemsSec').hidden = true;
                    $('ncFormSec').hidden = true;
                    $('ncSubmit').disabled = true;

                    fetch(`${URL_BASE}/new/orders/${orderId}/items`, { headers: { Accept: 'application/json' } })
                        .then((r) => r.json().then((j) => r.ok ? j : Promise.reject(j)))
                        .then((res) => {
                            const d = res.data || {};
                            const o = d.order || {};
                            $('ncOrderName').textContent = o.recipient_name || '—';
                            $('ncOrderPhone').textContent = o.recipient_phone || '—';
                            $('ncOrderTotal').textContent = money(o.total_amount);
                            $('ncOrderStatus').textContent = ORDER_STATUSES[o.status] || o.status || '—';
                            // Dropdown chỉ hiện mã đơn; thẻ này để nhân viên soát lại đúng
                            // khách trước khi lập phiếu.
                            $('ncPicked').hidden = false;
                            // Điền sẵn chủ tài khoản theo tên người nhận cho nhân viên đỡ gõ.
                            $('ncBankHolder').value = o.recipient_name || '';

                            if (!d.returnable) {
                                note.className = 'rt-note-box is-error';
                                note.textContent = d.reason || 'Đơn này không nằm trong diện trả hàng.';
                                return;
                            }
                            note.hidden = true;
                            // Chỉ hiện dòng còn trả được: dòng đã nằm trọn trong phiếu khác
                            // để lại chỉ làm rối bảng.
                            rows = (d.items || []).filter((it) => (it.remain_quantity || 0) > 0);
                            renderRows();
                            $('ncItemsSec').hidden = false;
                            $('ncFormSec').hidden = false;
                            $('ncSaveDraft').disabled = false;
                            syncBank($('ncRefundMethod'), $('ncBankRow'));
                            recalcCreate();
                            if (draft) applyDraft(draft);
                        })
                        .catch((j) => {
                            note.className = 'rt-note-box is-error';
                            note.textContent = (j && j.message) || 'Không tải được sản phẩm của đơn.';
                        });
                }

                function renderRows() {
                    $('ncItems').innerHTML = rows.map((it, i) => {
                        const meta = [it.variant_sku, it.variant_name].filter(Boolean).join(' · ');
                        return `<tr data-row="${i}" class="is-off">
                            <td class="rt-i-qty"><input type="checkbox" class="rt-check rt-pick" data-i="${i}"></td>
                            <td>
                                <span class="rt-item-name">${esc(it.product_name)}</span>
                                ${meta ? `<span class="rt-item-sub">${esc(meta)}</span>` : ''}
                                <input type="hidden" name="items[${i}][order_item_id]" value="${it.order_item_id}">
                            </td>
                            <td class="rt-i-price">${money(it.unit_price)}</td>
                            <td class="rt-i-qty">${it.remain_quantity} / ${it.quantity}</td>
                            <td class="rt-i-qty">
                                <input type="number" class="rt-input rt-input-sm rt-qty" name="items[${i}][quantity]"
                                       value="0" min="0" max="${it.remain_quantity}" data-i="${i}">
                            </td>
                            <td class="rt-i-total" data-line="${i}">${money(0)}</td>
                        </tr>`;
                    }).join('');
                }

                /* Tick chọn = trả TOÀN BỘ số còn lại của dòng đó; muốn trả một phần thì
                   sửa ô số lượng, ô tick tự bỏ khi số về 0. */
                function setRow(i, qty) {
                    const tr = $('ncItems').querySelector(`[data-row="${i}"]`);
                    if (!tr) return;
                    const q = Math.min(Math.max(0, qty), rows[i].remain_quantity);
                    tr.querySelector('.rt-qty').value = q;
                    tr.querySelector('.rt-pick').checked = q > 0;
                    tr.classList.toggle('is-off', q === 0);
                    tr.querySelector('[data-line]').textContent = money(rows[i].unit_price * q);
                }

                $('ncItems').addEventListener('change', (e) => {
                    const pick = e.target.closest('.rt-pick');
                    if (!pick) return;
                    const i = Number(pick.dataset.i);
                    setRow(i, pick.checked ? rows[i].remain_quantity : 0);
                    recalcCreate();
                });
                $('ncItems').addEventListener('input', (e) => {
                    const qty = e.target.closest('.rt-qty');
                    if (!qty) return;
                    setRow(Number(qty.dataset.i), Number(qty.value) || 0);
                    recalcCreate();
                });
                $('ncCheckAll').addEventListener('change', function () {
                    rows.forEach((it, i) => setRow(i, this.checked ? it.remain_quantity : 0));
                    recalcCreate();
                });

                function recalcCreate() {
                    let amount = 0;
                    let picks = 0;
                    $('ncItems').querySelectorAll('[data-row]').forEach((tr) => {
                        const i = Number(tr.getAttribute('data-row'));
                        const q = Number(tr.querySelector('.rt-qty').value) || 0;
                        if (q > 0) picks++;
                        amount += (rows[i].unit_price || 0) * q;
                    });

                    const ship = rawOf('ncShipFeeRaw');
                    const deduct = rawOf('ncDeductionRaw');
                    $('ncItemsAmount').textContent = money(amount);
                    $('ncSumShip').textContent = money(ship);
                    $('ncSumDeduct').textContent = deduct > 0 ? '-' + money(deduct) : money(0);
                    $('ncRefundAmount').textContent = money(Math.max(0, amount + ship - deduct));

                    // Ô "chọn tất cả" của bảng: rỗng / một phần / đủ.
                    const all = $('ncCheckAll');
                    all.checked = rows.length > 0 && picks === rows.length;
                    all.indeterminate = picks > 0 && picks < rows.length;

                    $('ncSubmit').disabled = picks === 0;
                }
                bindMoney('ncShipFee', 'ncShipFeeRaw', recalcCreate);
                bindMoney('ncDeduction', 'ncDeductionRaw', recalcCreate);
                $('ncRefundMethod').addEventListener('change', function () { syncBank(this, $('ncBankRow')); });

                form.addEventListener('submit', (e) => {
                    if (!$('ncOrderId').value) {
                        e.preventDefault();
                        toast('Vui lòng chọn đơn hàng cần trả.', 'danger');
                        return;
                    }
                    const any = Array.from($('ncItems').querySelectorAll('.rt-qty')).some((el) => (Number(el.value) || 0) > 0);
                    if (!any) {
                        e.preventDefault();
                        toast('Vui lòng chọn ít nhất một sản phẩm để trả.', 'danger');
                        return;
                    }
                    // Phiếu đã đi vào hệ thống, bản nháp không còn ý nghĩa.
                    clearDraft();
                    $('ncSubmit').disabled = true;          // chặn bấm hai lần khi mạng chậm
                    $('ncSubmit').textContent = 'Đang lưu…';
                });
            })();

            // ===================== THAO TÁC HÀNG LOẠT =====================
            (function () {
                const bar = $('rtBulkBar');
                const checkAll = $('rtCheckAll');
                const tbody = document.querySelector('.rt-table tbody');
                if (!bar || !tbody) return;

                // Bước tiến hợp lệ kế tiếp của mỗi trạng thái — khớp returnFlow phía Go
                // (bỏ nhánh từ chối/huỷ vì đó là thao tác cần lý do, làm ở từng phiếu).
                const NEXT_STEP = { pending: 'approved', approved: 'received', received: 'refunded' };

                const rowChecks = () => Array.from(tbody.querySelectorAll('.rt-row-check'));
                const selected = () => rowChecks().filter((c) => c.checked);
                const selectedIds = () => selected().map((c) => c.value);

                function refresh() {
                    const sel = selected();
                    $('rtBulkCount').textContent = sel.length;
                    bar.hidden = sel.length === 0;

                    // Trạng thái ô "chọn tất cả": rỗng / một phần / đủ.
                    const all = rowChecks();
                    checkAll.checked = all.length > 0 && sel.length === all.length;
                    checkAll.indeterminate = sel.length > 0 && sel.length < all.length;

                    // Nút chuyển trạng thái: chỉ khi các phiếu đã chọn CÙNG một trạng thái
                    // và trạng thái đó còn bước kế tiếp. Nhãn hiện đúng việc sắp làm.
                    const advBtn = $('rtBulkAdvance');
                    const statuses = [...new Set(sel.map((c) => c.dataset.status))];
                    const next = statuses.length === 1 ? NEXT_STEP[statuses[0]] : null;
                    if (next) {
                        advBtn.hidden = false;
                        advBtn.dataset.next = next;
                        $('rtBulkAdvanceLabel').textContent = ACTIONS[next] || ('Chuyển sang "' + (STATUSES[next] || next) + '"');
                    } else {
                        advBtn.hidden = true;
                        delete advBtn.dataset.next;
                    }
                }

                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((c) => { c.checked = checkAll.checked; });
                    refresh();
                });
                tbody.addEventListener('change', (e) => { if (e.target.classList.contains('rt-row-check')) refresh(); });
                $('rtBulkClear').addEventListener('click', () => {
                    rowChecks().forEach((c) => { c.checked = false; });
                    refresh();
                });

                $('rtBulkExport').addEventListener('click', () => {
                    const ids = selectedIds();
                    if (ids.length) window.location.href = `${EXPORT_URL}?ids=${ids.join(',')}`;
                });

                $('rtBulkAdvance').addEventListener('click', (e) => {
                    const ids = selectedIds();
                    const next = e.currentTarget.dataset.next;
                    if (!ids.length || !next) return;
                    const extra = next === 'received'
                        ? ' Hàng của các phiếu có bật "nhập lại kho" sẽ được cộng vào tồn kho ngay.'
                        : (next === 'refunded' ? ' Thao tác này ghi nhận đã chi tiền và không thể hoàn tác.' : '');
                    Promise.resolve(window.sysConfirm({
                        title: 'Xác nhận thao tác hàng loạt',
                        message: `Chuyển ${ids.length} phiếu đã chọn sang "${STATUSES[next] || next}"?${extra}`,
                        confirmText: ACTIONS[next] || 'Chuyển',
                    })).then((ok) => {
                        if (!ok) return;
                        $('rtBulkStatus').value = next;
                        $('rtBulkIds').innerHTML = ids.map((id) => `<input type="hidden" name="ids[]" value="${id}">`).join('');
                        $('rtBulkForm').submit();
                    });
                });

                refresh();
                // Bảng có thể bị thay ruột khi có phiếu mới (phần realtime bên dưới) —
                // lúc đó thanh thao tác hàng loạt phải tính lại theo các dòng mới.
                window.rtSyncBulk = refresh;
            })();

            // ---------- Realtime: phiếu mới về thì bảng tự cập nhật ----------
            //
            // Bảng vẫn do server dựng: khi có tín hiệu, trang tải LẠI CHÍNH URL hiện
            // tại (giữ nguyên bộ lọc, trang, sắp xếp) rồi chỉ thay phần ruột bảng +
            // số liệu đầu trang + phân trang. Không dựng lại hàng bằng JS để hai nơi
            // không trôi ra hai kiểu hiển thị khác nhau theo thời gian.
            (function () {
                const pill = $('rtLivePill');
                const pillText = $('rtLiveText');
                const tbody = document.querySelector('.rt-table tbody');
                if (!pill || !tbody) return;

                let pending = 0;      // số sự kiện đã nhận mà chưa kịp phản ánh lên bảng
                let timer = null;
                let loading = false;

                /** Đang bận thì KHÔNG được tự thay bảng dưới tay người dùng. */
                function busy() {
                    // Đang chọn phiếu để thao tác hàng loạt — thay bảng là mất hết lựa chọn.
                    if (tbody.querySelector('.rt-row-check:checked')) return true;
                    return anyModalOpen();
                }

                function showPill() {
                    pillText.textContent = pending > 1
                        ? `Có ${pending} cập nhật trả hàng mới`
                        : 'Có phiếu trả hàng mới';
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

                        const freshBody = doc.querySelector('.rt-table tbody');
                        if (!freshBody) return;
                        // Thay RUỘT chứ không thay chính thẻ <tbody>: handler của trang
                        // gắn trên tbody theo kiểu uỷ quyền, đổi thẻ là mất sạch.
                        tbody.innerHTML = freshBody.innerHTML;

                        const refund = document.querySelector('.rt-refund');
                        const freshRefund = doc.querySelector('.rt-refund');
                        if (refund && freshRefund) refund.innerHTML = freshRefund.innerHTML;

                        // Phân trang chỉ được dựng khi có ít nhất một phiếu, nên phải xử
                        // lý cả hai chiều (từ không có thành có và ngược lại).
                        const pg = document.querySelector('.rt > .pg');
                        const freshPg = doc.querySelector('.rt > .pg');
                        if (pg && freshPg) pg.replaceWith(freshPg);
                        else if (pg) pg.remove();
                        else if (freshPg) document.querySelector('.rt').appendChild(freshPg);

                        $('rtCheckAll').checked = false;
                        if (window.rtSyncBulk) window.rtSyncBulk();

                        pending = 0;
                        pill.hidden = true;
                    } catch (e) {
                        // Mạng chập: giữ nguyên bảng cũ và để dải nhắc cho người dùng bấm lại.
                        showPill();
                    } finally {
                        loading = false;
                    }
                }

                pill.addEventListener('click', reload);

                // realtime.js gọi hàm này mỗi khi API báo có phiếu tạo/đổi trạng thái.
                window.onReturnRealtime = function () {
                    pending += 1;
                    if (busy()) { showPill(); return; }
                    // Gộp một chùm sự kiện thành đúng một lần tải lại.
                    clearTimeout(timer);
                    timer = setTimeout(() => { busy() ? showPill() : reload(); }, 400);
                };
            })();
        })();
    </script>
@endsection
