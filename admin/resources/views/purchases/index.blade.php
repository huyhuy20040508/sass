@extends('layouts.app')

@section('title', \App\Http\Controllers\PurchaseController::TITLE)

@section('content')
    {{--
    Trang "Đặt hàng nhập" — cùng khuôn với trang Đơn hàng / Trả hàng:
    [ header ] + [ form lọc ] + [ bảng compact ] + [ chân trang ] + [ các modal ].

    Đây là chiều MUA VÀO của kho. Phiếu đặt hàng KHÔNG cộng tồn kho: hàng chỉ vào
    kho khi bấm "Nhận hàng", và nhận được nhiều đợt (nhà cung cấp giao dần). Toàn
    bộ luật (sửa tới lúc nào, nhận bao nhiêu là vượt) do Go API giữ — trang này chỉ
    hiển thị `next_statuses` / `can_edit` / `can_receive` API trả về, không tự đoán.
    --}}
    @php
        $STATUSES = \App\Http\Controllers\PurchaseController::STATUSES;
        $TONES = \App\Http\Controllers\PurchaseController::STATUS_TONES;
        $ACTIONS = \App\Http\Controllers\PurchaseController::STATUS_ACTIONS;
        $PAYMENTS = \App\Http\Controllers\PurchaseController::PAYMENT_STATUSES;
        $PAYMENT_TONES = \App\Http\Controllers\PurchaseController::PAYMENT_TONES;
        $SORTS = \App\Http\Controllers\PurchaseController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\PurchaseController::PAGE_SIZES;

        $TITLE = \App\Http\Controllers\PurchaseController::TITLE;
        $EMPTY_TEXT = \App\Http\Controllers\PurchaseController::EMPTY_TEXT;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['payment_status'] !== 'all'
            || $filters['supplier_id'] !== ''
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        // Số bộ lọc nâng cao đang bật -> tự mở hàng nâng cao + hiện badge.
        // Khoảng ngày nằm ngoài hàng nâng cao (luôn thấy trên thanh công cụ) nên không đếm ở đây.
        $advCount = ($filters['status'] !== 'all' ? 1 : 0)
            + ($filters['payment_status'] !== 'all' ? 1 : 0)
            + ($filters['supplier_id'] !== '' ? 1 : 0)
            + ($filters['sort'] !== 'newest' ? 1 : 0);
        $advOpen = $advCount > 0;
    @endphp

    <div class="po">
        {{-- Header --}}
        <div class="po-head">
            <h1 class="po-title">{{ $TITLE }}</h1>
            <span class="po-sum">
                Nháp: <b>{{ number_format($stats['draft'], 0, ',', '.') }}</b> ·
                Đang chờ hàng: <b>{{ number_format($stats['ordered'] + $stats['partial'], 0, ',', '.') }}</b> ·
                Hàng đang về: <b>{{ number_format($stats['incoming_quantity'], 0, ',', '.') }}</b> sản phẩm
                ({{ number_format((float) $stats['incoming_value'], 0, ',', '.') }}₫) ·
                Còn nợ NCC: <b>{{ number_format((float) $stats['debt_amount'], 0, ',', '.') }}₫</b>
                <em>(chưa tính phiếu nháp và phiếu đã huỷ)</em>
            </span>
        </div>

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.purchases.index') }}" id="poFilter" class="po-filter">
            <div class="po-toolbar">
                <div class="po-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="po-search-input"
                        placeholder="Tìm theo mã phiếu, nhà cung cấp hoặc ghi chú" autocomplete="off">
                    <button type="submit" class="po-search-btn" title="Tìm kiếm">
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
                <div class="po-daterange" id="poDateRange">
                    <input type="hidden" name="from_date" value="{{ $filters['from_date'] }}" id="poFrom">
                    <input type="hidden" name="to_date" value="{{ $filters['to_date'] }}" id="poTo">
                    <button type="button" class="po-daterange-trigger" id="poDateTrigger" aria-haspopup="dialog"
                        aria-expanded="false" title="Lọc theo ngày tạo phiếu">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="4" width="18" height="18" rx="2" />
                            <path d="M16 2v4M8 2v4M3 10h18" />
                        </svg>
                        <span class="po-daterange-label" id="poDateLabel">—</span>
                        <svg class="po-daterange-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </button>

                    <div class="po-cal-pop" id="poCalPop" role="dialog" aria-label="Chọn khoảng ngày" hidden>
                        <div class="po-cal-presets">
                            <button type="button" class="po-cal-preset" data-preset="today">Hôm nay</button>
                            <button type="button" class="po-cal-preset" data-preset="yesterday">Hôm qua</button>
                            <button type="button" class="po-cal-preset" data-preset="last7">7 ngày qua</button>
                            <button type="button" class="po-cal-preset" data-preset="last30">30 ngày qua</button>
                            <button type="button" class="po-cal-preset" data-preset="thismonth">Tháng này</button>
                        </div>
                        <div class="po-cal-main">
                            <div class="po-cal-months">
                                <div class="po-cal" data-cal="0"></div>
                                <div class="po-cal" data-cal="1"></div>
                            </div>
                            <div class="po-cal-foot">
                                <span class="po-cal-range" id="poCalRange">Chọn ngày bắt đầu</span>
                                <div class="po-cal-foot-btns">
                                    <button type="button" class="po-btn-ghost po-cal-btn" id="poCalClear">Xoá</button>
                                    <button type="button" class="po-btn-primary po-cal-btn" id="poCalApply">Áp dụng</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" id="poAdvToggle" class="po-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                    aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M7 12h10M11 18h2" />
                    </svg>
                    Nâng cao
                    <svg class="po-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                    @if($advCount > 0)<span class="po-adv-count">{{ $advCount }}</span>@endif
                </button>

                <div class="po-toolbar-actions">
                    <button type="button" id="poAddBtn" class="po-btn-primary">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 12h14M12 5v14" />
                        </svg>
                        Lập phiếu đặt hàng
                    </button>

                    <div class="po-util" id="poUtil">
                        <button type="button" class="po-util-btn" id="poUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1.6" />
                                <circle cx="19" cy="12" r="1.6" />
                                <circle cx="5" cy="12" r="1.6" />
                            </svg>
                            Tiện ích
                            <svg class="po-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div class="po-util-menu">
                            <a href="{{ route('admin.purchases.export', request()->query()) }}" class="po-util-item">
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

            {{-- Hàng nâng cao: trạng thái + thanh toán + nhà cung cấp + khoảng ngày + sắp xếp --}}
            <div class="po-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="poAdvRow">
                <select name="status" class="po-select" title="Lọc theo trạng thái phiếu">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="payment_status" class="po-select" title="Lọc theo tình trạng thanh toán">
                    <option value="all" {{ $filters['payment_status'] === 'all' ? 'selected' : '' }}>Tất cả thanh toán</option>
                    @foreach($PAYMENTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['payment_status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="supplier_id" class="po-select" title="Lọc theo nhà cung cấp">
                    <option value="">Tất cả nhà cung cấp</option>
                    @foreach($suppliers as $sup)
                        <option value="{{ $sup['id'] }}" {{ (string) $filters['supplier_id'] === (string) $sup['id'] ? 'selected' : '' }}>
                            {{ $sup['name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="po-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.purchases.index') }}" class="po-clear">Xoá lọc</a>
                @endif
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Thanh thao tác hàng loạt NỔI (hiện khi chọn ≥1 phiếu) --}}
        <div class="po-bulk" id="poBulkBar" hidden>
            <span class="po-bulk-count"><b id="poBulkCount">0</b> phiếu đã chọn</span>
            <button type="button" class="po-bulk-clear" id="poBulkClear">Bỏ chọn</button>
            <div class="po-bulk-actions">
                <button type="button" class="po-btn-ghost" id="poBulkExport">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Xuất CSV
                </button>
                {{-- Chỉ hiện khi MỌI phiếu đã chọn còn là nháp: gửi nhà cung cấp là bước
                     duy nhất chạy hàng loạt được mà không cần nhập gì thêm. --}}
                <button type="button" class="po-btn-primary" id="poBulkSend" hidden>
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                    Gửi nhà cung cấp
                </button>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.purchases.bulkStatus') }}" id="poBulkForm" class="d-none">
            @csrf
            <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
            <input type="hidden" name="status" value="ordered">
            <div id="poBulkIds"></div>
        </form>

        {{-- Bảng --}}
        <div class="po-table-wrap">
            <table class="po-table">
                <thead>
                    <tr>
                        <th class="po-c-check">
                            <input type="checkbox" id="poCheckAll" class="po-check" title="Chọn tất cả trong trang">
                        </th>
                        <th class="po-c-stt">STT</th>
                        <th class="po-c-code">Mã phiếu</th>
                        <th class="po-c-sup">Nhà cung cấp</th>
                        <th class="po-c-qty">Đã nhận / Đặt</th>
                        <th class="po-c-amount">Tổng tiền</th>
                        <th class="po-c-pay">Thanh toán</th>
                        <th class="po-c-status">Trạng thái</th>
                        <th class="po-c-date">Hẹn giao</th>
                        <th class="po-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($purchases as $i => $p)
                        @php
                            $id = $p['id'] ?? 0;
                            $status = $p['status'] ?? 'draft';
                            $items = collect($p['items'] ?? []);
                            $ordered = $items->sum('quantity');
                            $received = $items->sum('received_quantity');
                            $percent = $ordered > 0 ? min(100, round($received * 100 / $ordered)) : 0;
                            $paid = (float) ($p['paid_amount'] ?? 0);
                            $total = (float) ($p['total_amount'] ?? 0);
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="po-c-check">
                                <input type="checkbox" class="po-check po-row-check" value="{{ $id }}"
                                       data-status="{{ $status }}" aria-label="Chọn phiếu {{ $p['po_code'] ?? $id }}">
                            </td>
                            <td class="po-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="po-c-code" data-view="{{ $id }}" title="Xem chi tiết phiếu đặt hàng">
                                <span class="po-code">{{ $p['po_code'] ?? '—' }}</span>
                                <span class="po-sub">
                                    {{ !empty($p['created_at']) ? \Illuminate\Support\Carbon::parse($p['created_at'])->format('d/m/Y H:i') : '—' }}
                                </span>
                            </td>
                            <td class="po-c-sup" data-view="{{ $id }}" title="Xem chi tiết phiếu đặt hàng">
                                <span class="po-name">{{ $p['supplier_name'] ?: '—' }}</span>
                                @if(!empty($p['note']))
                                    <span class="po-sub">{{ \Illuminate\Support\Str::limit($p['note'], 50) }}</span>
                                @endif
                            </td>
                            <td class="po-c-qty">
                                <span class="po-qty-text">{{ number_format($received, 0, ',', '.') }} / {{ number_format($ordered, 0, ',', '.') }}</span>
                                <span class="po-bar"><i style="width: {{ $percent }}%"></i></span>
                            </td>
                            <td class="po-c-amount">
                                <span class="po-total">{{ number_format($total, 0, ',', '.') }}₫</span>
                                @if($paid > 0 && $paid < $total)
                                    <span class="po-sub">đã trả {{ number_format($paid, 0, ',', '.') }}₫</span>
                                @endif
                            </td>
                            <td class="po-c-pay">
                                <span class="po-badge tone-{{ $PAYMENT_TONES[$p['payment_status'] ?? ''] ?? 'info' }}">
                                    {{ $PAYMENTS[$p['payment_status'] ?? ''] ?? '—' }}
                                </span>
                            </td>
                            <td class="po-c-status">
                                <span class="po-badge tone-{{ $TONES[$status] ?? 'info' }}">{{ $STATUSES[$status] ?? '—' }}</span>
                            </td>
                            <td class="po-c-date">
                                {{ !empty($p['expected_date']) ? \Illuminate\Support\Carbon::parse($p['expected_date'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="po-c-act">
                                <button type="button" class="po-rowbtn" data-view="{{ $id }}" title="Xem & xử lý phiếu">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3" />
                                        <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                    </svg>
                                </button>
                                @if(in_array($status, ['ordered', 'partial'], true))
                                    <button type="button" class="po-rowbtn is-primary" data-receive="{{ $id }}" title="Nhận hàng vào kho">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 8v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8" />
                                            <path d="M2 4h20v4H2z" />
                                            <path d="M12 12v5m0 0-2-2m2 2 2-2" />
                                        </svg>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="po-empty">
                                @if($hasFilter)
                                    Không tìm thấy phiếu đặt hàng nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'phiếu đặt hàng',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- ===== Modal chi tiết & xử lý phiếu ===== --}}
    <div class="po-overlay" id="poViewOverlay" style="display:none;">
        <div class="po-dialog">
            <div class="po-modal-head">
                <h4 class="po-modal-title">Chi tiết phiếu đặt hàng</h4>
                <button type="button" class="po-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="po-modal-body">
                <div class="po-view-head">
                    <div class="po-view-ident">
                        <span class="po-view-code" id="vCode">—</span>
                        <span class="po-view-date" id="vCreated">—</span>
                    </div>
                    <span class="po-badge" id="vStatus">—</span>
                </div>

                {{-- Tiến trình phiếu --}}
                <ol class="po-steps" id="vSteps"></ol>

                {{-- Cảnh báo khi phiếu bị huỷ --}}
                <div class="po-alert" id="vAlert" hidden>
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10" /><path d="M12 8v5M12 16h.01" />
                    </svg>
                    <div class="po-alert-body">
                        <b>Phiếu đã bị huỷ</b>
                        <span id="vAlertDesc">—</span>
                    </div>
                </div>

                <div class="po-view-cols">
                    <div class="po-view-sec">
                        <p class="po-sec-title">Nhà cung cấp</p>
                        <div class="po-view-grid">
                            <div class="po-cell"><span class="po-lb">Tên</span><span class="po-vl" id="vSupName">—</span></div>
                            <div class="po-cell"><span class="po-lb">Người liên hệ</span><span class="po-vl" id="vSupContact">—</span></div>
                            <div class="po-cell"><span class="po-lb">Điện thoại</span><span class="po-vl" id="vSupPhone">—</span></div>
                            <div class="po-cell"><span class="po-lb">Địa chỉ</span><span class="po-vl" id="vSupAddress">—</span></div>
                        </div>
                    </div>

                    <div class="po-view-sec">
                        <p class="po-sec-title">Thông tin phiếu</p>
                        <div class="po-view-grid">
                            <div class="po-cell"><span class="po-lb">Hẹn giao</span><span class="po-vl" id="vExpected">—</span></div>
                            <div class="po-cell"><span class="po-lb">Ngày đặt</span><span class="po-vl" id="vOrderedAt">—</span></div>
                            <div class="po-cell"><span class="po-lb">Ngày nhận đủ</span><span class="po-vl" id="vReceivedAt">—</span></div>
                            <div class="po-cell is-full"><span class="po-lb">Ghi chú</span><span class="po-vl" id="vNote">—</span></div>
                        </div>
                    </div>
                </div>

                <div class="po-view-sec">
                    <p class="po-sec-title">Hàng đặt</p>
                    <table class="po-items">
                        <thead>
                            <tr>
                                <th>Sản phẩm</th>
                                <th class="po-i-price">Giá nhập</th>
                                <th class="po-i-qty">Đặt</th>
                                <th class="po-i-qty">Đã nhận</th>
                                <th class="po-i-total">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody id="vItems"></tbody>
                    </table>
                    <div class="po-sum-box">
                        <div><span>Tiền hàng</span><b id="vItemsAmount">0₫</b></div>
                        <div><span>Chiết khấu</span><b id="vDiscount">0₫</b></div>
                        <div><span>Vận chuyển</span><b id="vShipping">0₫</b></div>
                        <div class="is-total"><span>Tổng phải trả NCC</span><b id="vTotal">0₫</b></div>
                    </div>
                </div>

                {{-- Thanh toán: phiếu nháp chưa nợ ai, phiếu huỷ là sổ đã đóng --}}
                <div class="po-view-sec" id="vPaySec">
                    <p class="po-sec-title">Thanh toán cho nhà cung cấp</p>
                    <form method="POST" id="poPayForm" class="po-pay">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                        {{-- Ô tiền hiển thị có phân nhóm nghìn; số thô gửi lên nằm ở input ẩn,
                             nên không phải gỡ dấu chấm lúc submit. --}}
                        <input type="hidden" name="paid_amount" id="vPaidRaw" value="0">
                        <div class="po-pay-row">
                            <div class="po-field">
                                <label class="po-lb" for="vPaid">Đã trả (luỹ kế)</label>
                                <div class="po-money">
                                    <input type="text" id="vPaid" class="po-input po-money-input" inputmode="numeric" value="0">
                                    <span class="po-money-suffix">₫</span>
                                </div>
                            </div>
                            <div class="po-field">
                                <span class="po-lb">Còn nợ</span>
                                <span class="po-debt" id="vDebt">0₫</span>
                            </div>
                            <button type="submit" class="po-btn-ghost">Lưu thanh toán</button>
                        </div>
                        <p class="po-hint">Nhập TỔNG số tiền đã trả cho phiếu này, không phải số vừa trả thêm.</p>
                    </form>
                </div>

                <div class="po-view-sec">
                    <p class="po-sec-title">Lịch sử phiếu</p>
                    <ul class="po-timeline" id="vHistory"></ul>
                </div>
            </div>

            <div class="po-modal-foot">
                <div class="po-actions" id="vActions"></div>
                <div class="po-foot-right">
                    <button type="button" class="po-btn-ghost" data-close>Đóng</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Modal lập / sửa phiếu ===== --}}
    <div class="po-overlay" id="poFormOverlay" style="display:none;">
        <div class="po-dialog">
            <div class="po-modal-head">
                <h4 class="po-modal-title" id="fTitle">Lập phiếu đặt hàng</h4>
                <button type="button" class="po-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.purchases.store') }}" id="poForm">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                {{-- _method chỉ đổi thành PUT khi sửa phiếu. Phải là "POST" chứ không
                     được để rỗng: Symfony coi _method rỗng là method không hợp lệ và
                     ném luôn lỗi 400 trước khi request tới controller. --}}
                <input type="hidden" name="_method" id="fMethod" value="POST">
                <input type="hidden" name="status" id="fStatus" value="draft">
                <input type="hidden" name="discount_amount" id="fDiscountRaw" value="0">
                <input type="hidden" name="shipping_fee" id="fShippingRaw" value="0">

                <div class="po-modal-body">
                    {{-- Bước 1: nhà cung cấp & ngày hẹn --}}
                    <div class="po-view-sec">
                        <p class="po-sec-title">1. Nhà cung cấp</p>
                        <div class="po-form-grid">
                            <div class="po-field">
                                <label class="po-lb" for="fSupplier">Nhà cung cấp <span class="po-req">*</span></label>
                                <div class="po-inline">
                                    <select name="supplier_id" id="fSupplier" class="po-select is-block" data-ph required>
                                        <option value="">Chọn nhà cung cấp…</option>
                                        @foreach($suppliers as $sup)
                                            @if(($sup['is_active'] ?? true))
                                                <option value="{{ $sup['id'] }}">{{ $sup['name'] }} ({{ $sup['code'] }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <button type="button" class="po-btn-ghost po-btn-sq" id="fAddSupplier" title="Thêm nhà cung cấp mới">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M5 12h14M12 5v14" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="po-field">
                                <label class="po-lb" for="fExpected">Ngày hẹn giao</label>
                                <input type="date" name="expected_date" id="fExpected" class="po-input">
                            </div>
                        </div>
                    </div>

                    {{-- Bước 2: chọn hàng --}}
                    <div class="po-view-sec">
                        <p class="po-sec-title">2. Hàng cần nhập</p>
                        {{-- Dropdown chọn hàng: bấm là xổ sẵn các biến thể sắp hết, gõ để lọc
                             tiếp. Panel định vị theo VIEWPORT (position: fixed, toạ độ do JS
                             tính từ nút) chứ không absolute trong modal — nếu không nó sẽ bị
                             khung cuộn của modal cắt mất. --}}
                        <div class="po-dd" id="fVariantDD">
                            <button type="button" class="po-dd-control" id="fVariantBtn"
                                aria-haspopup="listbox" aria-expanded="false">
                                <span class="po-dd-value is-empty">Thêm sản phẩm vào phiếu…</span>
                                <svg class="po-dd-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m6 9 6 6 6-6" />
                                </svg>
                            </button>
                            <div class="po-dd-panel" id="fVariantPanel" role="listbox" hidden>
                                <div class="po-dd-search">
                                    <input type="text" class="po-input po-input-sm" id="fVariantSearch" autocomplete="off"
                                        placeholder="Lọc theo tên sản phẩm hoặc SKU">
                                </div>
                                <div class="po-dd-list" id="fVariantList"></div>
                            </div>
                        </div>
                        <p class="po-hint">Danh sách xếp <b>hàng sắp hết lên đầu</b>. Giá nhập được điền sẵn theo giá vốn
                            đang khai — sửa lại theo giá nhà cung cấp báo.</p>

                        <table class="po-items po-items-edit">
                            <thead>
                                <tr>
                                    <th>Sản phẩm</th>
                                    <th class="po-i-qty">Tồn kho</th>
                                    <th class="po-i-qty">SL đặt</th>
                                    <th class="po-i-price">Giá nhập</th>
                                    <th class="po-i-total">Thành tiền</th>
                                    <th class="po-i-act"></th>
                                </tr>
                            </thead>
                            <tbody id="fItems"></tbody>
                        </table>
                        <p class="po-empty-line" id="fItemsEmpty">Chưa có sản phẩm nào trong phiếu.</p>
                    </div>

                    {{-- Bước 3: chi phí & ghi chú --}}
                    <div class="po-view-sec">
                        <p class="po-sec-title">3. Chi phí & ghi chú</p>
                        <div class="po-form-grid">
                            <div class="po-field">
                                <label class="po-lb" for="fDiscount">Chiết khấu NCC</label>
                                <div class="po-money">
                                    <input type="text" id="fDiscount" class="po-input po-money-input" inputmode="numeric" value="0">
                                    <span class="po-money-suffix">₫</span>
                                </div>
                            </div>
                            <div class="po-field">
                                <label class="po-lb" for="fShipping">Cước vận chuyển</label>
                                <div class="po-money">
                                    <input type="text" id="fShipping" class="po-input po-money-input" inputmode="numeric" value="0">
                                    <span class="po-money-suffix">₫</span>
                                </div>
                            </div>
                            <div class="po-field is-full">
                                <label class="po-lb" for="fNote">Ghi chú</label>
                                <textarea name="note" id="fNote" class="po-textarea" rows="2" maxlength="500"
                                    placeholder="VD: Đặt bù hàng size M, NCC hẹn giao trước cuối tháng"></textarea>
                            </div>
                        </div>

                        <div class="po-sum-box">
                            <div><span>Tiền hàng</span><b id="fItemsAmount">0₫</b></div>
                            <div><span>Chiết khấu</span><b id="fSumDiscount">0₫</b></div>
                            <div><span>Vận chuyển</span><b id="fSumShipping">0₫</b></div>
                            <div class="is-total"><span>Tổng phải trả NCC</span><b id="fTotal">0₫</b></div>
                        </div>

                        <p class="po-note-box">Lập phiếu <b>không cộng tồn kho</b>. Hàng chỉ vào kho khi bạn bấm
                            <b>Nhận hàng</b> trên phiếu — nhận được nhiều đợt nếu nhà cung cấp giao dần.</p>
                    </div>
                </div>

                <div class="po-modal-foot po-foot-center">
                    <button type="button" class="po-btn-ghost" data-close>Huỷ</button>
                    <button type="submit" class="po-btn-ghost" id="fSaveDraft" disabled>Lưu nháp</button>
                    <button type="submit" class="po-btn-primary" id="fSubmit" disabled>Đặt hàng ngay</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal nhận hàng ===== --}}
    <div class="po-overlay" id="poReceiveOverlay" style="display:none;">
        <div class="po-dialog">
            <div class="po-modal-head">
                <h4 class="po-modal-title">Nhận hàng vào kho</h4>
                <button type="button" class="po-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <form method="POST" id="poReceiveForm">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="po-modal-body">
                    <p class="po-note-box" id="rInfo">—</p>

                    <table class="po-items">
                        <thead>
                            <tr>
                                <th class="po-i-qty">
                                    <input type="checkbox" id="rCheckAll" class="po-check" title="Nhận toàn bộ số còn lại">
                                </th>
                                <th>Sản phẩm</th>
                                <th class="po-i-price">Giá nhập</th>
                                <th class="po-i-qty">Còn thiếu</th>
                                <th class="po-i-qty">SL nhận</th>
                            </tr>
                        </thead>
                        <tbody id="rItems"></tbody>
                    </table>

                    <label class="po-checkline">
                        <input type="hidden" name="update_cost" value="0">
                        <input type="checkbox" name="update_cost" value="1" id="rUpdateCost" class="po-check" checked>
                        <span>Cập nhật giá vốn theo giá nhập đợt này
                            <em>— bỏ chọn nếu muốn giữ nguyên giá vốn đang khai</em></span>
                    </label>

                    <div class="po-field">
                        <label class="po-lb" for="rNote">Ghi chú đợt nhận</label>
                        <input type="text" name="note" id="rNote" class="po-input" maxlength="255"
                            placeholder="VD: NCC giao thiếu 6 áo, hẹn tuần sau giao nốt">
                    </div>
                </div>

                <div class="po-modal-foot po-foot-center">
                    <button type="button" class="po-btn-ghost" data-close>Huỷ</button>
                    <button type="submit" class="po-btn-primary" id="rSubmit" disabled>Xác nhận nhận hàng</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal nhập lý do huỷ ===== --}}
    <div class="po-overlay" id="poCancelOverlay" style="display:none;">
        <div class="po-dialog po-dialog-sm">
            <div class="po-modal-head">
                <h4 class="po-modal-title">Huỷ phiếu đặt hàng</h4>
                <button type="button" class="po-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>
            <form method="POST" id="poCancelForm">
                @csrf
                @method('PUT')
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="status" value="cancelled">
                <div class="po-modal-body">
                    <p class="po-note-box" id="cDesc">—</p>
                    <div class="po-field">
                        <label class="po-lb" for="cNote">Lý do huỷ <span class="po-req">*</span></label>
                        <textarea name="note" id="cNote" class="po-textarea" rows="3" maxlength="255" required
                            placeholder="VD: NCC báo hết hàng / Đặt nhầm size / Đã tìm được nguồn khác rẻ hơn"></textarea>
                    </div>
                </div>
                <div class="po-modal-foot">
                    <div class="po-foot-right">
                        <button type="button" class="po-btn-ghost" data-close>Đóng</button>
                        <button type="submit" class="po-btn-danger">Xác nhận huỷ</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal nhà cung cấp ===== --}}
    <div class="po-overlay" id="poSupOverlay" style="display:none;">
        <div class="po-dialog">
            <div class="po-modal-head">
                <h4 class="po-modal-title">Nhà cung cấp</h4>
                <button type="button" class="po-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="po-modal-body">
                <form method="POST" action="{{ route('admin.purchases.storeSupplier') }}" id="poSupForm">
                    @csrf
                    <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                    <input type="hidden" name="_method" id="sMethod" value="POST">
                    <p class="po-sec-title" id="sTitle">Thêm nhà cung cấp</p>
                    <div class="po-form-grid">
                        <div class="po-field">
                            <label class="po-lb" for="sName">Tên nhà cung cấp <span class="po-req">*</span></label>
                            <input type="text" name="name" id="sName" class="po-input" maxlength="150" required>
                        </div>
                        <div class="po-field">
                            <label class="po-lb" for="sCode">Mã</label>
                            <input type="text" name="code" id="sCode" class="po-input" maxlength="30"
                                placeholder="Bỏ trống để tự sinh NCC001…">
                        </div>
                        <div class="po-field">
                            <label class="po-lb" for="sContact">Người liên hệ</label>
                            <input type="text" name="contact_name" id="sContact" class="po-input" maxlength="150">
                        </div>
                        <div class="po-field">
                            <label class="po-lb" for="sPhone">Điện thoại</label>
                            <input type="text" name="phone" id="sPhone" class="po-input" maxlength="20">
                        </div>
                        <div class="po-field">
                            <label class="po-lb" for="sEmail">Email</label>
                            <input type="email" name="email" id="sEmail" class="po-input" maxlength="191">
                        </div>
                        <div class="po-field">
                            <label class="po-lb" for="sTax">Mã số thuế</label>
                            <input type="text" name="tax_code" id="sTax" class="po-input" maxlength="30">
                        </div>
                        <div class="po-field is-full">
                            <label class="po-lb" for="sAddress">Địa chỉ</label>
                            <input type="text" name="address" id="sAddress" class="po-input" maxlength="255">
                        </div>
                    </div>
                    <label class="po-checkline">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" id="sActive" class="po-check" checked>
                        <span>Đang hợp tác <em>— bỏ chọn thì nhà cung cấp không còn hiện trong ô chọn khi lập phiếu</em></span>
                    </label>
                    <div class="po-sup-foot">
                        <button type="button" class="po-btn-ghost" id="sReset" hidden>Thôi sửa</button>
                        <button type="submit" class="po-btn-primary" id="sSubmit">Thêm nhà cung cấp</button>
                    </div>
                </form>

                <div class="po-view-sec">
                    <p class="po-sec-title">Danh sách nhà cung cấp</p>
                    <table class="po-items">
                        <thead>
                            <tr>
                                <th class="po-i-code">Mã</th>
                                <th>Tên & liên hệ</th>
                                <th class="po-i-qty">Số phiếu</th>
                                <th class="po-i-qty">Trạng thái</th>
                                <th class="po-i-act"></th>
                            </tr>
                        </thead>
                        <tbody id="sList"></tbody>
                    </table>
                </div>
            </div>

            <div class="po-modal-foot">
                <div class="po-foot-right">
                    <button type="button" class="po-btn-ghost" data-close>Đóng</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Form ẩn cho các thao tác không cần nhập gì thêm (gửi NCC / xoá nháp) --}}
    <form id="poActionForm" method="POST" class="d-none">
        @csrf
        <input type="hidden" name="_method" id="poActionMethod" value="PUT">
        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
        <input type="hidden" name="status" id="poActionStatus" value="">
    </form>

    <style>
        .po {
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626;
            background: #fff;
        }

        /* Header */
        .po-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .po-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .po-sum { font-size: 13px; color: #595959; }
        .po-sum b { color: #262626; }
        .po-sum em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Bộ lọc */
        .po-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .po-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .po-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .po-searchbox { display: flex; border-radius: 4px; }
        .po-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13, 110, 253, .25); }
        .po-search-input {
            height: 34px; width: 300px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .po-search-input::placeholder { color: #bfbfbf; }
        .po-searchbox:focus-within .po-search-input, .po-searchbox:focus-within .po-search-btn { border-color: #86b7fe; }
        .po-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .po-search-btn:hover { color: #1890ff; }

        .po-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .po-select.is-block { max-width: none; width: 100%; }

        /* Khoảng ngày: nút mở + bảng lịch 2 tháng (đồng bộ với trang Đơn hàng / Trả hàng) */
        .po-daterange { position: relative; display: inline-flex; }
        .po-daterange-trigger {
            height: 34px; display: inline-flex; align-items: center; gap: 8px; padding: 0 12px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; font-size: 13px; color: #262626;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .po-daterange-trigger:hover, .po-daterange.open .po-daterange-trigger { border-color: #1890ff; color: #1890ff; }
        .po-daterange-trigger > svg:first-child { color: #8c8c8c; flex-shrink: 0; }
        .po-daterange-trigger:hover > svg:first-child,
        .po-daterange.open .po-daterange-trigger > svg:first-child { color: #1890ff; }
        .po-daterange-label { white-space: nowrap; }
        .po-daterange-caret { color: #8c8c8c; transition: transform .2s; }
        .po-daterange.open .po-daterange-caret { transform: rotate(180deg); }

        .po-cal-pop {
            position: absolute; top: calc(100% + 6px); left: 0; z-index: 1060; display: flex; background: #fff;
            border: 1px solid #e6e6e6; border-radius: 8px; box-shadow: 0 8px 30px rgba(0, 0, 0, .14);
            overflow: hidden; transform-origin: top left; animation: poCalIn .14s ease;
        }
        @keyframes poCalIn { from { opacity: 0; transform: translateY(-4px) scale(.98); } to { opacity: 1; transform: none; } }
        .po-cal-pop[hidden] { display: none; }
        .po-cal-presets {
            display: flex; flex-direction: column; gap: 2px; padding: 10px; border-right: 1px solid #f0f0f0;
            background: #fafafa; min-width: 132px;
        }
        .po-cal-preset {
            text-align: left; border: 0; background: none; border-radius: 4px; padding: 8px 10px; font-size: 13px;
            color: #595959; cursor: pointer; transition: background .15s, color .15s;
        }
        .po-cal-preset:hover { background: #e6f7ff; color: #1890ff; }
        .po-cal-main { display: flex; flex-direction: column; }
        .po-cal-months { display: flex; gap: 8px; padding: 12px; }
        .po-cal { width: 244px; }
        .po-cal-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .po-cal-title { font-size: 13px; font-weight: 700; color: #262626; }
        .po-cal-nav {
            width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; color: #595959; cursor: pointer;
            transition: all .15s;
        }
        .po-cal-nav:hover { border-color: #1890ff; color: #1890ff; }
        .po-cal-nav[disabled] { visibility: hidden; }
        .po-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
        .po-cal-dow { text-align: center; font-size: 11px; font-weight: 600; color: #bfbfbf; padding: 4px 0; }
        .po-cal-day {
            height: 32px; border: 0; background: none; border-radius: 4px; font-size: 12px; color: #262626;
            cursor: pointer; transition: background .12s, color .12s; position: relative;
        }
        .po-cal-day:hover:not(.is-empty) { background: #e6f7ff; }
        .po-cal-day.is-empty { cursor: default; }
        .po-cal-day.in-range { background: #e6f7ff; border-radius: 0; }
        .po-cal-day.is-start, .po-cal-day.is-end { background: #1890ff; color: #fff; }
        .po-cal-day.is-start { border-radius: 4px 0 0 4px; }
        .po-cal-day.is-end { border-radius: 0 4px 4px 0; }
        .po-cal-day.is-start.is-end { border-radius: 4px; }
        .po-cal-day.is-today::after {
            content: ''; position: absolute; left: 50%; bottom: 4px; transform: translateX(-50%);
            width: 4px; height: 4px; border-radius: 50%; background: #1890ff;
        }
        .po-cal-day.is-start.is-today::after, .po-cal-day.is-end.is-today::after { background: #fff; }
        .po-cal-foot {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px;
            border-top: 1px solid #f0f0f0;
        }
        .po-cal-range { font-size: 13px; color: #595959; }
        .po-cal-foot-btns { display: flex; gap: 8px; }
        .po-cal-btn { height: 30px; padding: 0 14px; }
        @media (max-width: 720px) {
            .po-cal-pop { flex-direction: column; }
            .po-cal-presets { flex-direction: row; flex-wrap: wrap; border-right: 0; border-bottom: 1px solid #f0f0f0; min-width: 0; }
            .po-cal-months { flex-direction: column; }
        }

        .po-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .po-clear:hover { background: #fff1f0; color: #ff4d4f; }

        .po-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .po-toolbar-adv.is-open { display: flex; }

        .po-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .po-adv-btn:hover, .po-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .po-adv-caret { transition: transform .2s; }
        .po-adv-btn.is-open .po-adv-caret { transform: rotate(180deg); }
        .po-adv-count {
            min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff;
            font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
        }

        /* Nút chung */
        .po-btn-primary, .po-btn-ghost, .po-btn-danger {
            height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; text-decoration: none; transition: background .15s, border-color .15s, color .15s;
        }
        .po-btn-primary { background: #1890ff; color: #fff; }
        .po-btn-primary:hover:not([disabled]) { background: #0f7ae5; }
        .po-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .po-btn-ghost:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .po-btn-danger { background: #fff; border-color: #ffa39e; color: #ff4d4f; }
        .po-btn-danger:hover:not([disabled]) { background: #ff4d4f; border-color: #ff4d4f; color: #fff; }
        .po-btn-primary[disabled], .po-btn-ghost[disabled], .po-btn-danger[disabled] { opacity: .5; cursor: not-allowed; }
        .po-btn-sq { width: 34px; padding: 0; flex-shrink: 0; }

        /* Dropdown tiện ích */
        .po-util { position: relative; }
        .po-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
        }
        .po-util-btn:hover { border-color: #1890ff; color: #1890ff; }
        .po-util-caret { transition: transform .2s; }
        .po-util.open .po-util-caret { transform: rotate(180deg); }
        .po-util-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 4px); z-index: 1050; min-width: 190px;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; box-shadow: 0 6px 20px rgba(0, 0, 0, .12);
            padding: 4px; flex-direction: column;
        }
        .po-util.open .po-util-menu { display: flex; }
        .po-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; border-radius: 4px;
            background: none; font-size: 13px; color: #262626; text-align: left; text-decoration: none; cursor: pointer;
        }
        .po-util-item:hover { background: #f5f5f5; color: #1890ff; }

        /* Thanh thao tác hàng loạt — pill TRẮNG nổi giữa đáy vùng nội dung, đúng
           khuôn dùng chung với Đơn hàng / Sản phẩm / Thương hiệu / Nhà cung cấp
           (.ord-bulk/.prd-bulk/...): left bù đúng bề rộng sidebar, sidebar thu gọn
           thì bù lại 48px, màn hẹp thì bỏ bù. */
        .po-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0, 0, 0, .15);
        }
        body:has(.jh-sidebar.collapsed) .po-bulk { left: 48px; }
        @media (max-width: 820px) { .po-bulk { left: 0; } }
        .po-bulk-count { font-size: 13px; font-weight: 500; color: #262626; white-space: nowrap; }
        .po-bulk-count b { color: #1890ff; }
        .po-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; padding: 0; transition: color .15s; }
        .po-bulk-clear:hover { color: #262626; }
        .po-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .po-bulk-actions .po-btn-ghost, .po-bulk-actions .po-btn-primary { height: 30px; display: inline-flex; align-items: center; gap: 6px; }
        .po-bulk-actions svg { flex-shrink: 0; }

        /* Bảng */
        .po-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .po-table-wrap::-webkit-scrollbar { height: 11px; }
        .po-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .po-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        /* Bảng phải THOÁNG như trang Tồn kho: ô rộng 18px hai bên, dòng cao 16px
           trên dưới, chữ không dính vào vạch cột bên cạnh. Cột "Thao tác" hẹp nên
           nới nhẹ hơn để nút không bị đẩy khỏi màn hình. */
        .po-table thead th {
            text-align: left; padding: 13px 18px; border-bottom: 1px solid #f0f0f0;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap; background: #fafafa;
        }
        .po-table tbody td {
            padding: 16px 18px; border-bottom: 1px solid #f5f5f5; vertical-align: middle;
            white-space: nowrap; line-height: 1.5;
        }
        .po-table tbody tr:hover { background: #fafcff; }

        /* Cột bảng — cùng khuôn với trang Tồn kho / Sản phẩm:
           - th và td của MỘT cột phải khai CÙNG `text-align`, nếu không tiêu đề
             căn trái mà số/nút bên dưới căn phải là bảng trông lệch ngay.
           - Mọi cột `width: 1%` (co vừa nội dung), riêng "Nhà cung cấp" `width: 100%`
             hút hết khoảng dư nên không còn cột nào bị trình duyệt dàn rộng vô cớ. */
        .po-table th.po-c-check,  .po-table td.po-c-check  { width: 1%; text-align: center; }
        .po-table th.po-c-stt,    .po-table td.po-c-stt    { width: 1%; text-align: center; color: #8c8c8c; }
        .po-table th.po-c-code,   .po-table td.po-c-code   { width: 1%; }
        .po-table th.po-c-sup,    .po-table td.po-c-sup    { width: 100%; max-width: 0; min-width: 200px; overflow: hidden; }
        .po-table th.po-c-qty,    .po-table td.po-c-qty    { width: 1%; text-align: center; }
        .po-table th.po-c-amount, .po-table td.po-c-amount { width: 1%; text-align: right; }
        .po-table th.po-c-pay,    .po-table td.po-c-pay    { width: 1%; text-align: center; }
        .po-table th.po-c-status, .po-table td.po-c-status { width: 1%; text-align: center; }
        .po-table th.po-c-date,   .po-table td.po-c-date   { width: 1%; text-align: center; color: #595959; }
        .po-table th.po-c-act,    .po-table td.po-c-act    { width: 1%; text-align: center; }

        .po-c-code, .po-c-sup { cursor: pointer; }
        .po-code { display: block; font-weight: 600; color: #1890ff; }
        .po-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; }
        .po-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .po-total { display: block; font-weight: 600; }
        .po-qty-text { display: block; white-space: nowrap; }
        /* Thanh tiến độ canh giữa theo dòng "đã nhận / đặt" ngay trên nó. */
        .po-bar { display: block; margin: 5px auto 0; width: 92px; height: 4px; border-radius: 2px; background: #f0f0f0; overflow: hidden; }
        .po-bar i { display: block; height: 100%; background: #52c41a; }
        .po-rowbtn {
            width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .po-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .po-rowbtn.is-primary { border-color: #b7eb8f; color: #52c41a; }
        .po-rowbtn.is-primary:hover { border-color: #52c41a; background: #f6ffed; }
        .po-c-act .po-rowbtn + .po-rowbtn { margin-left: 6px; }
        /* Dòng trống trải hết bảng nên phải cho xuống dòng, không dùng nowrap như ô dữ liệu. */
        .po-empty { padding: 40px 12px; text-align: center; color: #8c8c8c; white-space: normal; }
        .po-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; }

        /* Badge trạng thái — cùng bảng màu với trang Đơn hàng / Trả hàng */
        .po-badge {
            display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 500; white-space: nowrap;
        }
        .po-badge.tone-wait { background: #fff7e6; color: #d46b08; }
        .po-badge.tone-info { background: #e6f7ff; color: #0958d9; }
        .po-badge.tone-move { background: #f9f0ff; color: #722ed1; }
        .po-badge.tone-done { background: #f6ffed; color: #389e0d; }
        .po-badge.tone-stop { background: #fff1f0; color: #cf1322; }

        /* Modal — hộp thoại canh giữa màn hình, tự cuộn trong thân (giống trang Đơn hàng / Trả hàng) */
        .po-overlay {
            position: fixed; inset: 0; z-index: 1055; background: rgba(0, 0, 0, .45);
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .po-dialog {
            width: 100%; max-width: 940px; max-height: 92vh; overflow-y: auto; background: #fff;
            border-radius: 10px; box-shadow: 0 12px 40px rgba(0, 0, 0, .2); scrollbar-width: thin;
        }
        .po-dialog::-webkit-scrollbar { width: 11px; }
        .po-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .po-dialog-sm { max-width: 520px; }
        .po-modal-head {
            position: sticky; top: 0; z-index: 2; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .po-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .po-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 4px; border-radius: 4px; }
        .po-modal-x:hover { background: #f5f5f5; color: #262626; }
        .po-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 18px; }
        .po-modal-foot {
            position: sticky; bottom: 0; z-index: 2;
            display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap;
            padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa;
        }
        .po-foot-center { justify-content: center; }
        .po-foot-right { display: flex; align-items: center; gap: 8px; }
        .po-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .po-view-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .po-view-code { font-size: 16px; font-weight: 700; }
        .po-view-date { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; }
        .po-view-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .po-view-sec { display: flex; flex-direction: column; gap: 10px; }
        .po-sec-title { margin: 0; font-size: 13px; font-weight: 700; color: #262626; }
        .po-view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
        .po-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .po-cell.is-full { grid-column: 1 / -1; }
        .po-lb { font-size: 12px; color: #8c8c8c; }
        .po-vl { font-size: 13px; color: #262626; word-break: break-word; }

        /* Tiến trình phiếu */
        .po-steps { list-style: none; display: flex; gap: 6px; margin: 0; padding: 0; flex-wrap: wrap; }
        .po-step { flex: 1; min-width: 120px; display: flex; flex-direction: column; gap: 3px; padding: 8px 10px; border-radius: 6px; background: #fafafa; }
        .po-step-dot {
            width: 20px; height: 20px; border-radius: 50%; background: #e5e7eb; color: #8c8c8c;
            display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700;
        }
        .po-step-label { font-size: 12px; color: #8c8c8c; }
        .po-step-time { font-size: 11px; color: #bfbfbf; }
        .po-step.is-done .po-step-dot { background: #52c41a; color: #fff; }
        .po-step.is-done .po-step-label { color: #262626; }
        .po-step.is-current { background: #e6f7ff; }
        .po-step.is-current .po-step-dot { background: #1890ff; color: #fff; }
        .po-step.is-current .po-step-label { color: #0958d9; font-weight: 600; }
        .po-steps.is-stopped .po-step:not(.is-stop) { opacity: .55; }
        .po-step.is-stop { background: #fff1f0; }
        .po-step.is-stop .po-step-dot { background: #ff4d4f; color: #fff; }
        .po-step.is-stop .po-step-label { color: #cf1322; font-weight: 600; }

        .po-alert {
            display: flex; gap: 10px; padding: 10px 12px; border-radius: 6px; background: #fff1f0;
            border: 1px solid #ffccc7; color: #cf1322;
        }
        .po-alert-body { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
        .po-alert-body span { color: #a8071a; }

        /* Bảng hàng trong modal */
        .po-items { width: 100%; border-collapse: collapse; font-size: 13px; }
        .po-items thead th {
            text-align: left; padding: 8px 10px; border-bottom: 1px solid #f0f0f0;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
        }
        .po-items tbody td { padding: 10px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .po-i-price, .po-i-total { text-align: right; white-space: nowrap; }
        .po-i-qty { text-align: center; white-space: nowrap; width: 96px; }
        .po-i-code { width: 90px; }
        .po-i-act { width: 44px; text-align: right; }
        .po-item-name { display: block; font-weight: 500; }
        .po-item-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; }
        .po-items tbody tr.is-off { opacity: .55; }
        .po-item-done { color: #389e0d; font-weight: 600; }
        .po-empty-line { margin: 0; padding: 14px; text-align: center; font-size: 13px; color: #bfbfbf; background: #fafafa; border-radius: 6px; }

        .po-sum-box { display: flex; flex-direction: column; gap: 6px; margin-left: auto; min-width: 280px; font-size: 13px; }
        .po-sum-box > div { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .po-sum-box span { color: #8c8c8c; }
        .po-sum-box .is-total { padding-top: 6px; border-top: 1px dashed #e5e7eb; font-size: 14px; }
        .po-sum-box .is-total b { color: #1890ff; font-size: 15px; }

        /* Form */
        .po-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .po-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .po-field.is-full { grid-column: 1 / -1; }
        .po-inline { display: flex; align-items: center; gap: 8px; }
        .po-input, .po-textarea {
            border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 10px; height: 34px;
            font-size: 13px; color: #262626; outline: none; background: #fff; width: 100%;
        }
        .po-textarea { height: auto; padding: 8px 10px; resize: vertical; font-family: inherit; }
        .po-input-sm { height: 30px; font-size: 12px; }
        .po-input[readonly] { background: #fafafa; color: #8c8c8c; }
        .po-money { position: relative; display: flex; align-items: center; }
        .po-money-input { text-align: right; padding-right: 26px; }
        .po-money-suffix { position: absolute; right: 10px; font-size: 12px; color: #8c8c8c; pointer-events: none; }
        .po-hint { margin: 0; font-size: 12px; color: #8c8c8c; }
        .po-req { color: #ff4d4f; }
        .po-note-box { margin: 0; padding: 10px 12px; border-radius: 6px; background: #f6f8fa; font-size: 12px; color: #595959; }
        .po-note-box.is-error { background: #fff1f0; color: #cf1322; }
        .po-checkline { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: #262626; margin: 0; }
        .po-checkline em { font-style: normal; color: #8c8c8c; }
        .po-pay-row { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
        .po-pay-row .po-field { min-width: 160px; }
        .po-debt { font-size: 15px; font-weight: 700; color: #cf1322; line-height: 34px; }
        .po-sup-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }

        /* Dropdown chọn hàng (panel fixed để không bị modal cắt) */
        .po-dd { position: relative; }
        .po-dd-control {
            width: 100%; height: 34px; display: flex; align-items: center; justify-content: space-between; gap: 8px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 0 10px; font-size: 13px;
            color: #262626; cursor: pointer;
        }
        .po-dd-control:hover, .po-dd.open .po-dd-control { border-color: #1890ff; }
        .po-dd-value.is-empty { color: #bfbfbf; }
        .po-dd-caret { color: #8c8c8c; transition: transform .2s; }
        .po-dd.open .po-dd-caret { transform: rotate(180deg); }
        .po-dd-panel {
            position: fixed; z-index: 1100; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, .14); overflow: hidden; max-height: 320px; display: flex; flex-direction: column;
        }
        .po-dd-panel[hidden] { display: none; }
        .po-dd-search { padding: 8px; border-bottom: 1px solid #f0f0f0; }
        .po-dd-list { overflow-y: auto; max-height: 260px; }
        .po-ac-item { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #fafafa; }
        .po-ac-item:hover { background: #e6f7ff; }
        .po-ac-item b { display: block; font-size: 13px; font-weight: 500; }
        .po-ac-item span { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; }
        .po-ac-empty, .po-ac-loading { margin: 0; padding: 14px; text-align: center; font-size: 13px; color: #bfbfbf; }

        /* Lịch sử */
        .po-timeline { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
        .po-timeline li { display: flex; gap: 10px; }
        .po-tl-dot { width: 8px; height: 8px; margin-top: 6px; border-radius: 50%; background: #1890ff; flex-shrink: 0; }
        .po-tl-body { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
        .po-tl-time { font-size: 11px; color: #bfbfbf; }
        .po-tl-note { font-size: 12px; color: #595959; }
        .po-timeline-empty { font-size: 13px; color: #bfbfbf; }

        @media (max-width: 900px) {
            .po-view-cols, .po-form-grid, .po-view-grid { grid-template-columns: 1fr; }
            .po-sum-box { min-width: 0; width: 100%; }
        }
    </style>

    <script>
        (function () {
            'use strict';

            const STATUSES = @json($STATUSES);
            const TONES = @json($TONES);
            const ACTIONS = @json($ACTIONS);
            const PAYMENTS = @json($PAYMENTS);
            const PAYMENT_TONES = @json($PAYMENT_TONES);

            const URL_BASE = @json(url('/admin/purchases'));
            const EXPORT_URL = @json(route('admin.purchases.export'));
            const VARIANTS_URL = @json(route('admin.purchases.searchVariants'));
            const SUPPLIERS_URL = @json(route('admin.purchases.suppliers'));
            const SUPPLIER_STORE_URL = @json(route('admin.purchases.storeSupplier'));

            // Luồng phiếu (đúng thứ tự backend) — dùng dựng stepper tiến trình.
            const PIPELINE = ['draft', 'ordered', 'partial', 'received'];

            const $ = (id) => document.getElementById(id);
            const $filter = $('poFilter');
            const $viewOverlay = $('poViewOverlay');
            const $formOverlay = $('poFormOverlay');
            const $receiveOverlay = $('poReceiveOverlay');
            const $cancelOverlay = $('poCancelOverlay');
            const $supOverlay = $('poSupOverlay');

            const money = (n) => new Intl.NumberFormat('vi-VN').format(Number(n) || 0) + '₫';
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));
            const fmtDateTime = (s) => {
                if (!s) return '—';
                const d = new Date(s);
                return isNaN(d) ? s : d.toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit', year: 'numeric' });
            };
            const fmtDate = (s) => {
                if (!s) return '—';
                const d = new Date(s);
                return isNaN(d) ? s : d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
            };
            // Ô <input type="date"> chỉ nhận YYYY-MM-DD; API trả kèm giờ nên phải cắt.
            const dateInput = (s) => (s ? String(s).slice(0, 10) : '');
            // Ngày hôm nay theo giờ máy, dạng YYYY-MM-DD — dùng đổ sẵn ô ngày trong modal.
            const todayInput = () => {
                const t = new Date();
                return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
            };
            const toast = (title, tone) => {
                if (window.adminToast) window.adminToast({ title, tone: tone || 'info' });
                else alert(title);
            };

            // ---------- Bộ lọc: đổi ô nào là lọc ngay, không có nút Áp dụng ----------
            $filter.querySelectorAll('select').forEach((el) => {
                el.addEventListener('change', () => $filter.submit());
            });
            (function () {
                const search = $filter.querySelector('input[name="keyword"]');
                let t = null;
                search.addEventListener('input', () => {
                    clearTimeout(t);
                    t = setTimeout(() => $filter.submit(), 400);
                });
            })();

            // ---------- Bảng lịch chọn khoảng ngày (2 tháng) ----------
            (function () {
                const wrap = $('poDateRange');
                const trigger = $('poDateTrigger');
                const pop = $('poCalPop');
                const label = $('poDateLabel');
                const rangeText = $('poCalRange');
                const $from = $('poFrom');
                const $to = $('poTo');
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
                        for (let k = 0; k < offset; k++) cells += `<button type="button" class="po-cal-day is-empty" disabled></button>`;
                        for (let d = 1; d <= days; d++) {
                            const date = new Date(y, mo, d);
                            const todayCls = sameDay(date, today) ? ' is-today' : '';
                            cells += `<button type="button" class="po-cal-day${todayCls}" data-day="${ymd(date)}">${d}</button>`;
                        }
                        pop.querySelector(`.po-cal[data-cal="${i}"]`).innerHTML =
                            `<div class="po-cal-hd">
                                <button type="button" class="po-cal-nav" data-nav="-1" ${i === 1 ? 'disabled' : ''}>&lsaquo;</button>
                                <span class="po-cal-title">Tháng ${mo + 1}/${y}</span>
                                <button type="button" class="po-cal-nav" data-nav="1" ${i === 0 ? 'disabled' : ''}>&rsaquo;</button>
                            </div>` +
                            `<div class="po-cal-grid">${DOW.map((w) => `<span class="po-cal-dow">${w}</span>`).join('')}</div>` +
                            `<div class="po-cal-grid">${cells}</div>`;
                    });
                    paint();
                }

                // Tô lại trạng thái range trên các ô sẵn có — chạy khi hover/chọn (mượt, không dựng lại DOM).
                function paint() {
                    const hi = end || hover;
                    pop.querySelectorAll('.po-cal-day[data-day]').forEach((btn) => {
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
                pop.querySelectorAll('.po-cal-preset').forEach((btn) => {
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

                $('poCalApply').addEventListener('click', (e) => { e.stopPropagation(); apply(); });
                // Xoá lọc ngày rồi tải lại danh sách (hiện tất cả).
                $('poCalClear').addEventListener('click', (e) => {
                    e.stopPropagation();
                    start = null; end = null; hover = null; lastHover = '';
                    $from.value = ''; $to.value = '';
                    $filter.submit();
                });
            })();

            // Nút "Nâng cao": ẩn/hiện hàng bộ lọc phụ (nhớ trạng thái qua localStorage).
            (function () {
                const btn = $('poAdvToggle');
                const row = $('poAdvRow');
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('po-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('po-adv-open', open ? '1' : '0');
                });
            })();

            // ---------- Dropdown Tiện ích ----------
            const util = $('poUtil');
            $('poUtilBtn').addEventListener('click', (e) => { e.stopPropagation(); util.classList.toggle('open'); });
            document.addEventListener('click', () => util.classList.remove('open'));

            // ---------- Modal dùng chung ----------
            const OVERLAYS = [$viewOverlay, $formOverlay, $receiveOverlay, $cancelOverlay, $supOverlay];
            const openOverlay = (el) => { el.style.display = 'flex'; };
            const closeOverlay = (el) => { el.style.display = 'none'; };
            OVERLAYS.forEach((ov) => {
                ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeOverlay(ov)));
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') OVERLAYS.forEach(closeOverlay);
            });

            /* ---------- Ô nhập tiền VN ----------
               Hiển thị có phân nhóm nghìn (1.500.000) cho người đọc, số thô nằm ở
               input ẩn để gửi lên server — không phải gỡ dấu chấm lúc submit. */
            function bindMoney(inputId, hiddenId, onChange) {
                const el = $(inputId), hid = $(hiddenId);
                el.addEventListener('input', () => {
                    const digits = el.value.replace(/\D/g, '');
                    const num = digits ? parseInt(digits, 10) : 0;
                    el.value = digits ? num.toLocaleString('vi-VN') : '';
                    hid.value = num;
                    if (onChange) onChange();
                });
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

            // ===================== CHI TIẾT PHIẾU =====================
            let current = null;

            function renderItems(items) {
                const tbody = $('vItems');
                if (!items || !items.length) {
                    tbody.innerHTML = '<tr><td colspan="5" class="po-empty">Phiếu không có sản phẩm nào.</td></tr>';
                    return;
                }
                tbody.innerHTML = items.map((it) => {
                    const meta = [it.variant_sku, it.size, it.color].filter(Boolean).join(' · ');
                    const done = (Number(it.received_quantity) || 0) >= (Number(it.quantity) || 0);
                    return `<tr>
                            <td>
                                <span class="po-item-name">${esc(it.product_name || '—')}</span>
                                ${meta ? `<span class="po-item-sub">${esc(meta)}</span>` : ''}
                            </td>
                            <td class="po-i-price">${money(it.unit_cost)}</td>
                            <td class="po-i-qty">${Number(it.quantity) || 0}</td>
                            <td class="po-i-qty ${done ? 'po-item-done' : ''}">${Number(it.received_quantity) || 0}</td>
                            <td class="po-i-total">${money(it.total_cost)}</td>
                        </tr>`;
                }).join('');
            }

            function renderHistory(list) {
                const el = $('vHistory');
                if (!list || !list.length) {
                    el.innerHTML = '<li class="po-timeline-empty">Chưa có thao tác nào được ghi nhận.</li>';
                    return;
                }
                el.innerHTML = list.map((h) => {
                    // Dòng thanh toán không đổi trạng thái nên hiện thẳng ghi chú, không
                    // vẽ mũi tên "Đã nhận đủ → Đã nhận đủ" vô nghĩa.
                    const moved = h.from_status !== h.to_status;
                    const head = moved
                        ? `${esc(STATUSES[h.from_status] || 'Khởi tạo')} &rarr; <b>${esc(STATUSES[h.to_status] || h.to_status)}</b>`
                        : `<b>${esc(STATUSES[h.to_status] || h.to_status)}</b>`;
                    return `<li>
                            <span class="po-tl-dot"></span>
                            <span class="po-tl-body">
                                <span>${head}</span>
                                <span class="po-tl-time">${fmtDateTime(h.created_at)}</span>
                                ${h.note ? `<span class="po-tl-note">${esc(h.note)}</span>` : ''}
                            </span>
                        </li>`;
                }).join('');
            }

            // Thời điểm phiếu bước vào một trạng thái (mốc thời gian hoặc lịch sử).
            function stepTime(p, step) {
                if (step === 'draft') return p.created_at || null;
                const map = { ordered: p.ordered_at, received: p.received_at, cancelled: p.cancelled_at };
                if (map[step]) return map[step];
                const h = (p.histories || []).find((x) => x.to_status === step);
                return h ? h.created_at : null;
            }

            function renderSteps(p) {
                const el = $('vSteps');
                const status = p.status || 'draft';
                const stopped = status === 'cancelled';
                const reached = new Set(['draft', ...(p.histories || []).map((h) => h.to_status)]);
                const curIdx = PIPELINE.indexOf(status);

                const shortTime = (t) => {
                    if (!t) return '';
                    const d = new Date(t);
                    return isNaN(d) ? '' : d.toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit' })
                        + ' ' + d.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
                };

                let html = PIPELINE.map((step, i) => {
                    let cls = '', mark = i + 1;
                    if (stopped) {
                        if (reached.has(step)) { cls = 'is-done'; mark = '&#10003;'; }
                    } else if (i < curIdx) { cls = 'is-done'; mark = '&#10003;'; }
                    else if (i === curIdx) {
                        cls = status === 'received' ? 'is-done' : 'is-current';
                        if (status === 'received') mark = '&#10003;';
                    }
                    const t = (cls === 'is-done' || cls === 'is-current') ? shortTime(stepTime(p, step)) : '';
                    return `<li class="po-step ${cls}">
                            <span class="po-step-dot">${mark}</span>
                            <span class="po-step-label">${esc(STATUSES[step] || step)}</span>
                            ${t ? `<span class="po-step-time">${t}</span>` : ''}
                        </li>`;
                }).join('');

                if (stopped) {
                    const t = shortTime(stepTime(p, 'cancelled'));
                    html += `<li class="po-step is-stop">
                            <span class="po-step-dot">&#10007;</span>
                            <span class="po-step-label">Đã huỷ</span>
                            ${t ? `<span class="po-step-time">${t}</span>` : ''}
                        </li>`;
                }
                el.className = 'po-steps' + (stopped ? ' is-stopped' : '');
                el.innerHTML = html;
            }

            /* Nút thao tác dựng từ next_statuses / can_edit / can_receive API trả về —
               không tự đoán bước kế tiếp ở đây, nếu không giao diện sẽ lệch với luật
               của server. */
            function renderActions(p) {
                const box = $('vActions');
                const btns = [];

                if (p.can_receive) {
                    btns.push('<button type="button" class="po-btn-primary" data-act="receive">Nhận hàng</button>');
                }
                (p.next_statuses || []).forEach((st) => {
                    const label = ACTIONS[st] || STATUSES[st] || st;
                    if (st === 'cancelled') {
                        btns.push(`<button type="button" class="po-btn-danger" data-act="cancel">${esc(label)}</button>`);
                    } else {
                        btns.push(`<button type="button" class="${p.can_receive ? 'po-btn-ghost' : 'po-btn-primary'}" data-status="${st}">${esc(label)}</button>`);
                    }
                });
                if (p.can_edit) {
                    btns.push('<button type="button" class="po-btn-ghost" data-act="edit">Sửa phiếu</button>');
                }
                if (p.status === 'draft') {
                    btns.push('<button type="button" class="po-btn-danger" data-act="delete">Xoá nháp</button>');
                }

                box.innerHTML = btns.length
                    ? btns.join('')
                    : '<span class="po-hint">Phiếu đã kết thúc, không còn thao tác nào.</span>';
            }

            function fillView(p) {
                current = p;
                const set = (id, v) => { $(id).textContent = v; };

                set('vCode', p.po_code || '—');
                set('vCreated', `Lập lúc ${fmtDateTime(p.created_at)}`);

                const badge = $('vStatus');
                badge.textContent = STATUSES[p.status] || p.status || '—';
                badge.className = 'po-badge tone-' + (TONES[p.status] || 'info');

                renderSteps(p);

                const alert = $('vAlert');
                if (p.status === 'cancelled') {
                    alert.hidden = false;
                    const when = fmtDateTime(p.cancelled_at);
                    set('vAlertDesc', (when !== '—' ? `Lúc ${when}` : '') + (p.cancel_reason ? ` · ${p.cancel_reason}` : ''));
                } else {
                    alert.hidden = true;
                }

                const sup = p.supplier || {};
                set('vSupName', p.supplier_name || sup.name || '—');
                set('vSupContact', sup.contact_name || '—');
                set('vSupPhone', sup.phone || '—');
                set('vSupAddress', sup.address || '—');

                set('vExpected', fmtDate(p.expected_date));
                set('vOrderedAt', fmtDateTime(p.ordered_at));
                set('vReceivedAt', fmtDateTime(p.received_at));
                set('vNote', p.note || '—');

                renderItems(p.items);
                set('vItemsAmount', money(p.items_amount));
                set('vDiscount', (Number(p.discount_amount) || 0) > 0 ? '-' + money(p.discount_amount) : money(0));
                set('vShipping', money(p.shipping_fee));
                set('vTotal', money(p.total_amount));

                // Phiếu nháp chưa đặt thật nên chưa nợ ai; phiếu huỷ là sổ đã đóng.
                const payable = p.status !== 'draft' && p.status !== 'cancelled';
                $('vPaySec').hidden = !payable;
                if (payable) {
                    $('poPayForm').action = `${URL_BASE}/${p.id}/payment`;
                    setMoney('vPaid', 'vPaidRaw', p.paid_amount);
                    recalcDebt();
                }

                renderHistory(p.histories);
                renderActions(p);
            }

            function recalcDebt() {
                const total = Number(current && current.total_amount) || 0;
                $('vDebt').textContent = money(Math.max(0, total - rawOf('vPaidRaw')));
            }
            bindMoney('vPaid', 'vPaidRaw', recalcDebt);

            function loadDetail(id) {
                return fetch(`${URL_BASE}/${id}/detail`, { headers: { Accept: 'application/json' } })
                    .then((res) => { if (!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
                    .then((d) => d.data);
            }

            function openView(id) {
                loadDetail(id)
                    .then((p) => { fillView(p); openOverlay($viewOverlay); })
                    .catch(() => toast('Không tải được chi tiết phiếu đặt hàng.', 'danger'));
            }

            document.querySelector('.po-table tbody').addEventListener('click', (e) => {
                const recv = e.target.closest('[data-receive]');
                if (recv) {
                    loadDetail(Number(recv.getAttribute('data-receive')))
                        .then((p) => { current = p; openReceive(p); })
                        .catch(() => toast('Không tải được chi tiết phiếu đặt hàng.', 'danger'));
                    return;
                }
                const el = e.target.closest('[data-view]');
                if (el) openView(Number(el.getAttribute('data-view')));
            });

            // ---------- Thao tác trên phiếu ----------
            const actionForm = $('poActionForm');

            $('vActions').addEventListener('click', (e) => {
                if (!current) return;

                const act = e.target.closest('[data-act]');
                if (act) {
                    const what = act.getAttribute('data-act');
                    if (what === 'receive') { closeOverlay($viewOverlay); openReceive(current); }
                    else if (what === 'edit') { closeOverlay($viewOverlay); openEdit(current); }
                    else if (what === 'cancel') { openCancel(current); }
                    else if (what === 'delete') { confirmDelete(current); }
                    return;
                }

                const st = e.target.closest('[data-status]');
                if (!st) return;
                const to = st.getAttribute('data-status');

                Promise.resolve(window.sysConfirm({
                    title: 'Xác nhận thao tác',
                    message: `Gửi phiếu ${current.po_code} cho nhà cung cấp? Sau bước này phiếu không sửa được nữa, chỉ còn nhận hàng hoặc huỷ.`,
                    confirmText: ACTIONS[to] || 'Xác nhận',
                })).then((ok) => {
                    if (!ok) return;
                    actionForm.action = `${URL_BASE}/${current.id}/status`;
                    $('poActionMethod').value = 'PUT';
                    $('poActionStatus').value = to;
                    actionForm.submit();
                });
            });

            function confirmDelete(p) {
                Promise.resolve(window.sysDelete({
                    title: 'Xoá phiếu nháp',
                    message: `Xoá hẳn phiếu ${p.po_code}? Phiếu nháp chưa gửi nhà cung cấp nên không để lại dấu vết nào.`,
                    confirmText: 'Xoá phiếu',
                })).then((ok) => {
                    if (!ok) return;
                    actionForm.action = `${URL_BASE}/${p.id}`;
                    $('poActionMethod').value = 'DELETE';
                    $('poActionStatus').value = '';
                    actionForm.submit();
                });
            }

            function openCancel(p) {
                const received = (p.items || []).reduce((s, it) => s + (Number(it.received_quantity) || 0), 0);
                $('cDesc').innerHTML = `Phiếu <b>${esc(p.po_code)}</b> sẽ chuyển sang <b>Đã huỷ</b> và không quay lại được.`
                    + (received > 0
                        ? ` Phần hàng đã nhận (<b>${received}</b> sản phẩm) vẫn nằm trong kho — huỷ chỉ khép lại số còn thiếu.`
                        : '');
                $('cNote').value = '';
                $('poCancelForm').action = `${URL_BASE}/${p.id}/status`;
                closeOverlay($viewOverlay);
                openOverlay($cancelOverlay);
                setTimeout(() => $('cNote').focus(), 30);
            }

            // ===================== NHẬN HÀNG =====================
            (function () {
                let rows = [];   // các dòng còn thiếu hàng của phiếu

                window.openReceive = function (p) {
                    rows = (p.items || [])
                        .map((it) => ({ ...it, remain: (Number(it.quantity) || 0) - (Number(it.received_quantity) || 0) }))
                        .filter((it) => it.remain > 0);

                    $('rInfo').textContent = `Phiếu ${p.po_code} · ${p.supplier_name} — còn ${rows.reduce((s, r) => s + r.remain, 0)} sản phẩm chưa về.`;
                    $('poReceiveForm').action = `${URL_BASE}/${p.id}/receive`;
                    $('rNote').value = '';
                    $('rUpdateCost').checked = true;

                    $('rItems').innerHTML = rows.map((it, i) => {
                        const meta = [it.variant_sku, it.size, it.color].filter(Boolean).join(' · ');
                        return `<tr data-row="${i}" class="is-off">
                                <td class="po-i-qty"><input type="checkbox" class="po-check r-pick" data-i="${i}"></td>
                                <td>
                                    <span class="po-item-name">${esc(it.product_name)}</span>
                                    ${meta ? `<span class="po-item-sub">${esc(meta)}</span>` : ''}
                                    <input type="hidden" name="items[${i}][item_id]" value="${it.id}">
                                </td>
                                <td class="po-i-price">${money(it.unit_cost)}</td>
                                <td class="po-i-qty">${it.remain} / ${it.quantity}</td>
                                <td class="po-i-qty">
                                    <input type="number" class="po-input po-input-sm r-qty" name="items[${i}][quantity]"
                                           value="0" min="0" max="${it.remain}" data-i="${i}">
                                </td>
                            </tr>`;
                    }).join('');

                    // Mặc định nhận đủ: phần lớn lần nhận là giao đủ, ai giao thiếu thì
                    // sửa lại vài dòng — nhanh hơn là bắt gõ tay từng dòng.
                    rows.forEach((it, i) => setRow(i, it.remain));
                    refresh();
                    openOverlay($receiveOverlay);
                };

                function setRow(i, qty) {
                    const tr = $('rItems').querySelector(`[data-row="${i}"]`);
                    if (!tr) return;
                    const q = Math.min(Math.max(0, qty), rows[i].remain);
                    tr.querySelector('.r-qty').value = q;
                    tr.querySelector('.r-pick').checked = q > 0;
                    tr.classList.toggle('is-off', q === 0);
                }

                function refresh() {
                    let picks = 0;
                    $('rItems').querySelectorAll('[data-row]').forEach((tr) => {
                        if ((Number(tr.querySelector('.r-qty').value) || 0) > 0) picks++;
                    });
                    const all = $('rCheckAll');
                    all.checked = rows.length > 0 && picks === rows.length;
                    all.indeterminate = picks > 0 && picks < rows.length;
                    $('rSubmit').disabled = picks === 0;
                }

                $('rItems').addEventListener('change', (e) => {
                    const pick = e.target.closest('.r-pick');
                    if (!pick) return;
                    const i = Number(pick.dataset.i);
                    setRow(i, pick.checked ? rows[i].remain : 0);
                    refresh();
                });
                $('rItems').addEventListener('input', (e) => {
                    const qty = e.target.closest('.r-qty');
                    if (!qty) return;
                    setRow(Number(qty.dataset.i), Number(qty.value) || 0);
                    refresh();
                });
                $('rCheckAll').addEventListener('change', function () {
                    rows.forEach((it, i) => setRow(i, this.checked ? it.remain : 0));
                    refresh();
                });

                $('poReceiveForm').addEventListener('submit', (e) => {
                    const any = Array.from($('rItems').querySelectorAll('.r-qty')).some((el) => (Number(el.value) || 0) > 0);
                    if (!any) {
                        e.preventDefault();
                        toast('Vui lòng nhập số lượng nhận cho ít nhất một sản phẩm.', 'danger');
                        return;
                    }
                    $('rSubmit').disabled = true;      // chặn bấm hai lần khi mạng chậm
                    $('rSubmit').textContent = 'Đang lưu…';
                });
            })();

            // ===================== LẬP / SỬA PHIẾU =====================
            (function () {
                const form = $('poForm');
                let lines = [];      // dòng hàng đang có trong phiếu
                let seq = 0;         // chỉ số name="items[n]" — tăng dần, không tái dùng
                let loaded = false;  // đã nạp danh sách hàng lần nào chưa
                let searchTimer = null;

                function resetForm() {
                    form.reset();
                    lines = [];
                    seq = 0;
                    form.action = @json(route('admin.purchases.store'));
                    $('fMethod').value = 'POST';
                    $('fTitle').textContent = 'Lập phiếu đặt hàng';
                    $('fStatus').value = 'draft';
                    $('fSaveDraft').hidden = false;
                    $('fSubmit').textContent = 'Đặt hàng ngay';
                    $('fSupplier').value = '';
                    // Ngày hẹn giao đổ sẵn hôm nay — luồng lập phiếu nhanh khỏi phải gõ tay.
                    $('fExpected').value = todayInput();
                    $('fNote').value = '';
                    setMoney('fDiscount', 'fDiscountRaw', 0);
                    setMoney('fShipping', 'fShippingRaw', 0);
                    closeDD();
                    renderLines();
                }

                $('poAddBtn').addEventListener('click', () => {
                    resetForm();
                    openOverlay($formOverlay);
                });

                // Sửa phiếu: cùng một form, đổi action sang PUT và bỏ hai nút nháp/đặt.
                window.openEdit = function (p) {
                    resetForm();
                    form.action = `${URL_BASE}/${p.id}`;
                    $('fMethod').value = 'PUT';
                    $('fTitle').textContent = 'Sửa phiếu ' + p.po_code;
                    $('fSaveDraft').hidden = true;
                    $('fSubmit').textContent = 'Lưu thay đổi';
                    $('fSupplier').value = p.supplier_id || '';
                    $('fExpected').value = dateInput(p.expected_date);
                    $('fNote').value = p.note || '';
                    setMoney('fDiscount', 'fDiscountRaw', p.discount_amount);
                    setMoney('fShipping', 'fShippingRaw', p.shipping_fee);

                    lines = (p.items || []).map((it) => ({
                        variant_id: it.product_variant_id,
                        name: it.product_name,
                        sku: it.variant_sku,
                        size: it.size,
                        color: it.color,
                        stock: null,             // tồn kho hiện tại chỉ có ở luồng chọn hàng
                        quantity: it.quantity,
                        unit_cost: it.unit_cost,
                    }));
                    seq = lines.length;
                    renderLines();
                    openOverlay($formOverlay);
                };

                // ----- Dropdown chọn hàng -----
                const dd = $('fVariantDD');
                const panel = $('fVariantPanel');

                /* Panel là position: fixed nên phải tự đặt toạ độ theo nút. Đổi lại nó
                   thoát khỏi mọi khung overflow (modal, bảng) — không bao giờ bị cắt. */
                function placePanel() {
                    const r = $('fVariantBtn').getBoundingClientRect();
                    panel.style.width = r.width + 'px';
                    panel.style.left = r.left + 'px';
                    const h = panel.offsetHeight || 300;
                    const below = window.innerHeight - r.bottom - 8;
                    panel.style.top = (below >= h || below >= r.top ? r.bottom + 4 : r.top - h - 4) + 'px';
                }

                function openDD() {
                    if (!dd.classList.contains('open')) {
                        dd.classList.add('open');
                        panel.hidden = false;
                        $('fVariantBtn').setAttribute('aria-expanded', 'true');
                    }
                    placePanel();
                    if (!loaded) loadVariants('');
                    $('fVariantSearch').focus();
                }

                function closeDD() {
                    dd.classList.remove('open');
                    panel.hidden = true;
                    $('fVariantBtn').setAttribute('aria-expanded', 'false');
                }

                $('fVariantBtn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    dd.classList.contains('open') ? closeDD() : openDD();
                });
                // Bấm ra ngoài thì đóng; panel nằm ngoài .po-dd trong cây hiển thị nên
                // phải kiểm tra cả hai.
                document.addEventListener('click', (e) => {
                    if (panel.hidden || dd.contains(e.target) || panel.contains(e.target)) return;
                    closeDD();
                });
                window.addEventListener('scroll', () => { if (!panel.hidden) placePanel(); }, true);
                window.addEventListener('resize', () => { if (!panel.hidden) placePanel(); });
                // Esc chỉ đóng dropdown, không đóng luôn cả modal đang nhập dở.
                panel.addEventListener('keydown', (e) => {
                    if (e.key !== 'Escape') return;
                    e.stopPropagation();
                    closeDD();
                    $('fVariantBtn').focus();
                });

                $('fVariantSearch').addEventListener('input', function () {
                    const q = this.value.trim();
                    clearTimeout(searchTimer);
                    // Gõ tới đâu lọc tới đó nhưng chờ ngưng gõ 300ms, tránh bắn request từng phím.
                    searchTimer = setTimeout(() => loadVariants(q), 300);
                });
                // Ô lọc nằm TRONG form lập phiếu: Enter mặc định sẽ gửi form khi phiếu
                // còn dở dang, nên chặn lại và cho Enter nghĩa là "lọc ngay".
                $('fVariantSearch').addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    clearTimeout(searchTimer);
                    loadVariants(this.value.trim());
                });

                function renderVariantList(html) {
                    $('fVariantList').innerHTML = html;
                    placePanel();   // chiều cao panel đổi -> đặt lại toạ độ
                }

                function loadVariants(q) {
                    renderVariantList('<p class="po-ac-loading">Đang tải…</p>');
                    fetch(`${VARIANTS_URL}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                        .then((r) => r.ok ? r.json() : Promise.reject(r))
                        .then((res) => {
                            loaded = true;
                            const data = res.data || [];
                            renderVariantList(data.length
                                ? data.map((v) => {
                                    const meta = [v.sku, v.size, v.color].filter(Boolean).join(' · ');
                                    const cost = v.cost_price != null ? money(v.cost_price) : 'chưa khai giá vốn';
                                    return `<div class="po-ac-item" role="option" data-variant="${encodeURIComponent(JSON.stringify(v))}">
                                            <b>${esc(v.product_name)}</b>
                                            <span>${esc(meta)} · tồn ${v.stock} · ${esc(cost)}</span>
                                        </div>`;
                                }).join('')
                                : `<p class="po-ac-empty">${q ? 'Không có sản phẩm nào khớp từ khoá.' : 'Chưa có sản phẩm nào đang bán.'}</p>`);
                        })
                        .catch(() => renderVariantList('<p class="po-ac-empty">Không tải được danh sách sản phẩm.</p>'));
                }

                $('fVariantList').addEventListener('click', (e) => {
                    const item = e.target.closest('[data-variant]');
                    if (!item) return;
                    const v = JSON.parse(decodeURIComponent(item.dataset.variant));

                    // Chọn lại món đã có trong phiếu = cộng thêm 1 vào dòng cũ, không tạo
                    // dòng thứ hai cùng SKU.
                    const exist = lines.find((l) => Number(l.variant_id) === Number(v.variant_id));
                    if (exist) {
                        exist.quantity += 1;
                    } else {
                        lines.push({
                            variant_id: v.variant_id,
                            name: v.product_name,
                            sku: v.sku,
                            size: v.size,
                            color: v.color,
                            stock: v.stock,
                            quantity: 1,
                            unit_cost: Number(v.cost_price) || 0,
                        });
                    }
                    renderLines();
                });

                function renderLines() {
                    const tbody = $('fItems');
                    $('fItemsEmpty').hidden = lines.length > 0;
                    tbody.innerHTML = lines.map((l, i) => {
                        const meta = [l.sku, l.size, l.color].filter(Boolean).join(' · ');
                        const cost = Math.max(0, Number(l.unit_cost) || 0);
                        return `<tr data-row="${i}">
                                <td>
                                    <span class="po-item-name">${esc(l.name)}</span>
                                    ${meta ? `<span class="po-item-sub">${esc(meta)}</span>` : ''}
                                    <input type="hidden" name="items[${i}][variant_id]" value="${l.variant_id}">
                                </td>
                                <td class="po-i-qty">${l.stock == null ? '—' : l.stock}</td>
                                <td class="po-i-qty">
                                    <input type="number" class="po-input po-input-sm f-qty" name="items[${i}][quantity]"
                                           value="${l.quantity}" min="1" data-i="${i}">
                                </td>
                                <td class="po-i-price">
                                    <div class="po-money">
                                        <input type="text" class="po-input po-input-sm po-money-input f-cost"
                                               inputmode="numeric" value="${cost.toLocaleString('vi-VN')}" data-i="${i}">
                                        <span class="po-money-suffix">₫</span>
                                    </div>
                                    <input type="hidden" name="items[${i}][unit_cost]" value="${cost}">
                                </td>
                                <td class="po-i-total" data-line="${i}">${money(cost * l.quantity)}</td>
                                <td class="po-i-act">
                                    <button type="button" class="po-rowbtn f-del" data-i="${i}" title="Bỏ dòng này">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>`;
                    }).join('');
                    recalc();
                }

                $('fItems').addEventListener('input', (e) => {
                    const qty = e.target.closest('.f-qty');
                    if (qty) {
                        const i = Number(qty.dataset.i);
                        lines[i].quantity = Math.max(1, Number(qty.value) || 1);
                        updateLine(i);
                        return;
                    }
                    const cost = e.target.closest('.f-cost');
                    if (cost) {
                        const i = Number(cost.dataset.i);
                        const digits = cost.value.replace(/\D/g, '');
                        const num = digits ? parseInt(digits, 10) : 0;
                        cost.value = digits ? num.toLocaleString('vi-VN') : '';
                        // Input ẩn cạnh ô hiển thị mới là số gửi lên server.
                        cost.closest('td').querySelector('input[type="hidden"]').value = num;
                        lines[i].unit_cost = num;
                        updateLine(i);
                    }
                });

                $('fItems').addEventListener('click', (e) => {
                    const del = e.target.closest('.f-del');
                    if (!del) return;
                    lines.splice(Number(del.dataset.i), 1);
                    renderLines();
                });

                function updateLine(i) {
                    const tr = $('fItems').querySelector(`[data-row="${i}"]`);
                    if (tr) tr.querySelector('[data-line]').textContent = money(lines[i].unit_cost * lines[i].quantity);
                    recalc();
                }

                function recalc() {
                    const amount = lines.reduce((s, l) => s + (Number(l.unit_cost) || 0) * (Number(l.quantity) || 0), 0);
                    const discount = rawOf('fDiscountRaw');
                    const shipping = rawOf('fShippingRaw');
                    $('fItemsAmount').textContent = money(amount);
                    $('fSumDiscount').textContent = discount > 0 ? '-' + money(discount) : money(0);
                    $('fSumShipping').textContent = money(shipping);
                    // Tính lại y hệt server để con số trên màn hình không khác con số được lưu.
                    $('fTotal').textContent = money(Math.max(0, amount - discount + shipping));

                    const ready = lines.length > 0;
                    $('fSubmit').disabled = !ready;
                    $('fSaveDraft').disabled = !ready;
                }
                bindMoney('fDiscount', 'fDiscountRaw', recalc);
                bindMoney('fShipping', 'fShippingRaw', recalc);

                // Hai nút submit, một form: nút nào bấm thì đặt status tương ứng.
                $('fSaveDraft').addEventListener('click', () => { $('fStatus').value = 'draft'; });
                $('fSubmit').addEventListener('click', () => { $('fStatus').value = 'ordered'; });

                form.addEventListener('submit', (e) => {
                    if (!$('fSupplier').value) {
                        e.preventDefault();
                        toast('Vui lòng chọn nhà cung cấp.', 'danger');
                        return;
                    }
                    if (!lines.length) {
                        e.preventDefault();
                        toast('Vui lòng thêm ít nhất một sản phẩm vào phiếu.', 'danger');
                        return;
                    }
                    $('fSubmit').disabled = true;
                    $('fSaveDraft').disabled = true;
                });
            })();

            // ===================== NHÀ CUNG CẤP =====================
            (function () {
                const form = $('poSupForm');

                function render(list) {
                    const tbody = $('sList');
                    if (!list.length) {
                        tbody.innerHTML = '<tr><td colspan="5" class="po-empty">Chưa có nhà cung cấp nào.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = list.map((s) => {
                        const contact = [s.contact_name, s.phone].filter(Boolean).join(' · ');
                        return `<tr>
                                <td class="po-i-code">${esc(s.code)}</td>
                                <td>
                                    <span class="po-item-name">${esc(s.name)}</span>
                                    ${contact ? `<span class="po-item-sub">${esc(contact)}</span>` : ''}
                                </td>
                                <td class="po-i-qty">${s.purchase_count || 0}</td>
                                <td class="po-i-qty">
                                    <span class="po-badge tone-${s.is_active ? 'done' : 'stop'}">${s.is_active ? 'Hợp tác' : 'Ngừng'}</span>
                                </td>
                                <td class="po-i-act">
                                    <button type="button" class="po-rowbtn s-edit" data-sup="${encodeURIComponent(JSON.stringify(s))}" title="Sửa">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                        </svg>
                                    </button>
                                    ${s.purchase_count ? '' : `<button type="button" class="po-rowbtn s-del" data-id="${s.id}" data-name="${esc(s.name)}" title="Xoá nhà cung cấp">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                                            stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
                                        </svg>
                                    </button>`}
                                </td>
                            </tr>`;
                    }).join('');
                }

                function load() {
                    $('sList').innerHTML = '<tr><td colspan="5" class="po-empty">Đang tải…</td></tr>';
                    fetch(SUPPLIERS_URL, { headers: { Accept: 'application/json' } })
                        .then((r) => r.ok ? r.json() : Promise.reject(r))
                        .then((res) => render(res.data || []))
                        .catch(() => {
                            $('sList').innerHTML = '<tr><td colspan="5" class="po-empty">Không tải được danh sách nhà cung cấp.</td></tr>';
                        });
                }

                function resetSupForm() {
                    form.reset();
                    form.action = SUPPLIER_STORE_URL;
                    $('sMethod').value = 'POST';
                    $('sTitle').textContent = 'Thêm nhà cung cấp';
                    $('sSubmit').textContent = 'Thêm nhà cung cấp';
                    $('sReset').hidden = true;
                    $('sActive').checked = true;
                }

                function openSuppliers() {
                    resetSupForm();
                    load();
                    openOverlay($supOverlay);
                }

                // Lối vào duy nhất của modal này là nút "+" cạnh ô chọn nhà cung cấp trong
                // form lập phiếu: thêm nhanh giữa lúc lập phiếu, thêm xong trang tải lại
                // nên ô chọn có sẵn tên mới. Quản lý đầy đủ ở trang Nhà cung cấp.
                $('fAddSupplier').addEventListener('click', () => {
                    closeOverlay($formOverlay);
                    openSuppliers();
                });

                $('sReset').addEventListener('click', resetSupForm);

                $('sList').addEventListener('click', (e) => {
                    const del = e.target.closest('.s-del');
                    if (del) {
                        // Nút xoá chỉ hiện với nhà cung cấp chưa có phiếu nào; API vẫn
                        // kiểm tra lại nên không có đường nào xoá mất đầu mối của phiếu cũ.
                        Promise.resolve(window.sysDelete({
                            title: 'Xoá nhà cung cấp',
                            message: `Xoá nhà cung cấp "${del.dataset.name}"?`,
                        })).then((ok) => {
                            if (!ok) return;
                            actionForm.action = `${URL_BASE}/suppliers/${del.dataset.id}`;
                            $('poActionMethod').value = 'DELETE';
                            $('poActionStatus').value = '';
                            actionForm.submit();
                        });
                        return;
                    }

                    const btn = e.target.closest('.s-edit');
                    if (!btn) return;
                    const s = JSON.parse(decodeURIComponent(btn.dataset.sup));
                    form.action = `${URL_BASE}/suppliers/${s.id}`;
                    $('sMethod').value = 'PUT';
                    $('sTitle').textContent = 'Sửa nhà cung cấp ' + s.code;
                    $('sSubmit').textContent = 'Lưu thay đổi';
                    $('sReset').hidden = false;
                    $('sName').value = s.name || '';
                    $('sCode').value = s.code || '';
                    $('sContact').value = s.contact_name || '';
                    $('sPhone').value = s.phone || '';
                    $('sEmail').value = s.email || '';
                    $('sTax').value = s.tax_code || '';
                    $('sAddress').value = s.address || '';
                    $('sActive').checked = !!s.is_active;
                    $('sName').focus();
                });
            })();

            // ===================== THAO TÁC HÀNG LOẠT =====================
            (function () {
                const bar = $('poBulkBar');
                const checkAll = $('poCheckAll');
                const tbody = document.querySelector('.po-table tbody');
                if (!bar || !tbody) return;

                const rowChecks = () => Array.from(tbody.querySelectorAll('.po-row-check'));
                const selected = () => rowChecks().filter((c) => c.checked);
                const selectedIds = () => selected().map((c) => c.value);

                function refresh() {
                    const sel = selected();
                    $('poBulkCount').textContent = sel.length;
                    bar.hidden = sel.length === 0;

                    const all = rowChecks();
                    checkAll.checked = all.length > 0 && sel.length === all.length;
                    checkAll.indeterminate = sel.length > 0 && sel.length < all.length;

                    // Gửi nhà cung cấp chỉ áp cho phiếu nháp — các bước còn lại đều cần
                    // nhập thêm (số lượng nhận, lý do huỷ) nên không chạy hàng loạt được.
                    const statuses = [...new Set(sel.map((c) => c.dataset.status))];
                    $('poBulkSend').hidden = !(statuses.length === 1 && statuses[0] === 'draft');
                }

                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((c) => { c.checked = checkAll.checked; });
                    refresh();
                });
                tbody.addEventListener('change', (e) => { if (e.target.classList.contains('po-row-check')) refresh(); });
                $('poBulkClear').addEventListener('click', () => {
                    rowChecks().forEach((c) => { c.checked = false; });
                    refresh();
                });

                $('poBulkExport').addEventListener('click', () => {
                    const ids = selectedIds();
                    if (ids.length) window.location.href = `${EXPORT_URL}?ids=${ids.join(',')}`;
                });

                $('poBulkSend').addEventListener('click', () => {
                    const ids = selectedIds();
                    if (!ids.length) return;
                    Promise.resolve(window.sysConfirm({
                        title: 'Xác nhận thao tác hàng loạt',
                        message: `Gửi ${ids.length} phiếu nháp đã chọn cho nhà cung cấp? Sau bước này các phiếu không sửa được nữa.`,
                        confirmText: 'Gửi nhà cung cấp',
                    })).then((ok) => {
                        if (!ok) return;
                        $('poBulkIds').innerHTML = ids.map((id) => `<input type="hidden" name="ids[]" value="${id}">`).join('');
                        $('poBulkForm').submit();
                    });
                });

                refresh();
            })();
        })();
    </script>
@endsection
