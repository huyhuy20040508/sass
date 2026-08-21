@extends('layouts.app')

@section('title', \App\Http\Controllers\PurchaseReturnController::TITLE)

@section('content')
    {{--
        Trang "Trả hàng nhập" — chiều NGƯỢC của nhập hàng: hàng nhà cung cấp giao bị
        lỗi / sai mẫu / thừa thì lập phiếu trả lại. Cùng khuôn với trang Nhập hàng:
        [ header ] + [ dải việc cần làm ] + [ thanh lọc realtime ] + [ bảng ] + [ chân trang ] + [ modal ].

        Luồng phiếu: Nháp → Đã trả NCC (TRỪ tồn kho đúng lúc này) → NCC đã hoàn tiền.
        Phiếu nháp sửa/xoá/huỷ được; phiếu đã trả thì không — mọi luật do Go API giữ,
        giao diện chỉ hiện đúng nút được phép bấm theo next_statuses API trả về.
    --}}
    @php
        $C = \App\Http\Controllers\PurchaseReturnController::class;
        $STATUSES = $C::STATUSES;
        $TONES = $C::STATUS_TONES;
        $ACTIONS = $C::STATUS_ACTIONS;
        $REFUNDS = $C::REFUND_STATUSES;
        $REFUND_TONES = $C::REFUND_TONES;
        $REASONS = $C::REASONS;
        $SORTS = $C::SORTS;
        $PAGE_SIZES = $C::PAGE_SIZES;
        $TITLE = $C::TITLE;
        $EMPTY_TEXT = $C::EMPTY_TEXT;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['refund_status'] !== 'all'
            || $filters['reason'] !== 'all'
            || $filters['supplier_id'] !== ''
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        // Số bộ lọc nâng cao đang bật -> tự mở hàng nâng cao + hiện badge.
        // Khoảng ngày nằm ngoài hàng nâng cao (luôn thấy trên thanh công cụ) nên không đếm ở đây.
        $advCount = ($filters['status'] !== 'all' ? 1 : 0)
            + ($filters['refund_status'] !== 'all' ? 1 : 0)
            + ($filters['reason'] !== 'all' ? 1 : 0)
            + ($filters['supplier_id'] !== '' ? 1 : 0)
            + ($filters['sort'] !== 'newest' ? 1 : 0);
        $advOpen = $advCount > 0;

        $money = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
    @endphp

    <div class="pr">
        {{-- Header --}}
        <div class="pr-head">
            <h1 class="pr-title">{{ $TITLE }}</h1>
            <span class="pr-sum">
                Đã trả NCC: <b>{{ number_format($stats['returned'] + $stats['refunded'], 0, ',', '.') }}</b> phiếu ·
                <b>{{ number_format($stats['returned_quantity'], 0, ',', '.') }}</b> sản phẩm
                ({{ $money($stats['returned_amount']) }}) ·
                Nháp: <b>{{ number_format($stats['draft'], 0, ',', '.') }}</b>
                <em>(giá trị tính theo giá nhập trên phiếu đặt)</em>
            </span>
        </div>

        {{-- Việc cần làm: tiền NCC còn phải hoàn cho các phiếu đã trả. --}}
        @if(($stats['pending_refund'] ?? 0) > 0)
            <a class="pr-live" href="{{ route('admin.purchase-returns.index', ['status' => 'returned']) }}">
                <span class="pr-live-dot"></span>
                Nhà cung cấp còn phải hoàn <b>{{ $money($stats['pending_refund']) }}</b> cho các phiếu đã trả hàng
                <span class="pr-live-cta">Xem phiếu chờ hoàn tiền</span>
            </a>
        @endif

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.purchase-returns.index') }}" id="prFilter" class="pr-filter">
            <div class="pr-toolbar">
                <div class="pr-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="pr-search-input"
                           placeholder="Tìm theo mã phiếu trả, mã phiếu đặt hoặc nhà cung cấp" autocomplete="off">
                    <button type="submit" class="pr-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                {{-- Khoảng ngày lập phiếu — dùng component chung, KHÔNG tự dựng ô ngày riêng. --}}
                @include('partials.date-range', [
                    'formId' => 'prFilter',
                    'from' => $filters['from_date'],
                    'to' => $filters['to_date'],
                    'title' => 'Lọc theo ngày lập phiếu',
                ])

                <button type="button" id="prAdvToggle" class="pr-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                        aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M7 12h10M11 18h2"/>
                    </svg>
                    Nâng cao
                    <svg class="pr-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6"/>
                    </svg>
                    @if($advCount > 0)<span class="pr-adv-count">{{ $advCount }}</span>@endif
                </button>

                <div class="pr-toolbar-actions">
                    {{-- KHÔNG disable khi chưa có phiếu đặt nào nhận hàng: bấm vào phải nói rõ
                         vì sao chưa lập được phiếu trả, chứ không im lặng không phản ứng gì. --}}
                    <button type="button" class="pr-btn-primary" id="prCreateBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8"/><path d="M2 4h20v4H2z"/><path d="M12 17v-5m0 0-2 2m2-2 2 2"/>
                        </svg>
                        Lập phiếu trả hàng
                    </button>

                    <div class="pr-util" id="prUtil">
                        <button type="button" class="pr-util-btn" id="prUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="pr-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="pr-util-menu">
                            <a href="{{ route('admin.purchase-returns.export', request()->query()) }}" class="pr-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Hàng nâng cao: trạng thái + hoàn tiền + lý do + nhà cung cấp + sắp xếp --}}
            <div class="pr-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="prAdvRow">
                <select name="status" class="pr-select" title="Lọc theo trạng thái phiếu">
                    <option value="all">Tất cả trạng thái</option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="refund_status" class="pr-select" title="Lọc theo tình trạng hoàn tiền">
                    <option value="all">Tất cả hoàn tiền</option>
                    @foreach($REFUNDS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['refund_status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="reason" class="pr-select" title="Lọc theo lý do trả">
                    <option value="all">Tất cả lý do</option>
                    @foreach($REASONS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['reason'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="supplier_id" class="pr-select" title="Lọc theo nhà cung cấp">
                    <option value="">Tất cả nhà cung cấp</option>
                    @foreach($suppliers as $sup)
                        <option value="{{ $sup['id'] }}" {{ (string) $filters['supplier_id'] === (string) $sup['id'] ? 'selected' : '' }}>
                            {{ $sup['name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="pr-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.purchase-returns.index') }}" class="pr-clear">Xoá lọc</a>
                @endif
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng phiếu trả hàng --}}
        <div class="pr-table-wrap">
            <table class="pr-table">
                <thead>
                    <tr>
                        <th class="pr-c-stt">STT</th>
                        <th class="pr-c-code">Mã phiếu trả</th>
                        <th class="pr-c-sup">Nhà cung cấp</th>
                        <th class="pr-c-reason">Lý do</th>
                        <th class="pr-c-qty">SL trả</th>
                        <th class="pr-c-amount">Giá trị trả</th>
                        <th class="pr-c-refund">NCC đã hoàn</th>
                        <th class="pr-c-status">Trạng thái</th>
                        <th class="pr-c-date">Ngày lập</th>
                        <th class="pr-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($returns as $i => $r)
                        @php
                            $status = $r['status'] ?? '';
                            $refund = $r['refund_status'] ?? '';
                            $items = collect($r['items'] ?? []);
                            $qty = (int) $items->sum('quantity');
                            // Bước kế tiếp của phiếu: nháp -> trả NCC, đã trả -> hoàn tiền.
                            $next = ['draft' => 'returned', 'returned' => 'refunded'][$status] ?? null;
                        @endphp
                        <tr data-id="{{ $r['id'] }}">
                            <td class="pr-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="pr-c-code" data-view="{{ $r['id'] }}" title="Xem chi tiết phiếu trả">
                                <span class="pr-code">{{ $r['return_code'] ?? '' }}</span>
                                <span class="pr-sub">từ phiếu đặt {{ !empty($r['po_code']) ? $r['po_code'] : '—' }}</span>
                            </td>
                            <td class="pr-c-sup" data-view="{{ $r['id'] }}" title="Xem chi tiết phiếu trả">
                                <span class="pr-name">{{ !empty($r['supplier_name']) ? $r['supplier_name'] : '—' }}</span>
                                @if(!empty($r['note']))
                                    <span class="pr-sub" title="{{ $r['note'] }}">{{ \Illuminate\Support\Str::limit($r['note'], 60) }}</span>
                                @endif
                            </td>
                            <td class="pr-c-reason">{{ $REASONS[$r['reason'] ?? ''] ?? '—' }}</td>
                            <td class="pr-c-qty"><span class="pr-qty">-{{ number_format($qty, 0, ',', '.') }}</span></td>
                            <td class="pr-c-amount">{{ $money($r['items_amount'] ?? 0) }}</td>
                            <td class="pr-c-refund">
                                @if($status === 'cancelled')
                                    —
                                @else
                                    <span class="pr-refund-val">{{ $money($r['refund_amount'] ?? 0) }}</span>
                                    <span class="pr-badge pr-badge-sm tone-{{ $REFUND_TONES[$refund] ?? 'info' }}">{{ $REFUNDS[$refund] ?? '—' }}</span>
                                @endif
                            </td>
                            <td class="pr-c-status">
                                <span class="pr-badge tone-{{ $TONES[$status] ?? 'info' }}">{{ $STATUSES[$status] ?? '—' }}</span>
                            </td>
                            <td class="pr-c-date">
                                {{-- Carbon::parse giữ nguyên offset +07:00 API trả về (strtotime đổi về UTC làm lệch ngày) --}}
                                {{ !empty($r['created_at']) ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="pr-c-act">
                                {{-- Nút trạng thái hiện ĐÚNG bước kế tiếp của phiếu, không menu chung chung. --}}
                                @if($next)
                                    <button type="button" class="pr-rowbtn pr-rowbtn-next" data-next="{{ $next }}"
                                            data-id="{{ $r['id'] }}" data-code="{{ $r['return_code'] ?? '' }}">
                                        {{ $ACTIONS[$next] }}
                                    </button>
                                @endif
                                <button type="button" class="pr-rowbtn" data-view="{{ $r['id'] }}" title="Xem chi tiết phiếu trả">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="pr-empty">
                                @if($hasFilter)
                                    Không tìm thấy phiếu trả nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'phiếu trả',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- ===== Modal chọn phiếu đặt để trả hàng ===== --}}
    <div class="pr-overlay" id="prPickOverlay" style="display:none;">
        <div class="pr-dialog">
            <div class="pr-modal-head">
                <h4 class="pr-modal-title">Chọn phiếu đặt cần trả hàng</h4>
                <button type="button" class="pr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="pr-modal-body">
                <p class="pr-note">Chỉ hiện phiếu đặt <b>đã có hàng về kho</b> — hàng chưa nhận thì không có gì để trả.
                    Số trả được của từng mặt hàng = số đã nhận − số đã nằm trong các phiếu trả khác.</p>
                <div class="pr-pick-search">
                    <input type="text" class="pr-input" id="prPickSearch" autocomplete="off"
                           placeholder="Lọc theo mã phiếu hoặc nhà cung cấp">
                </div>
                <div class="pr-pick-list" id="prPickList"></div>
            </div>
            <div class="pr-modal-foot">
                <button type="button" class="pr-btn-ghost" data-close>Đóng</button>
            </div>
        </div>
    </div>

    {{-- ===== Modal lập / sửa phiếu trả ===== --}}
    <div class="pr-overlay" id="prFormOverlay" style="display:none;">
        <div class="pr-dialog">
            <div class="pr-modal-head">
                <h4 class="pr-modal-title" id="prFormTitle">Lập phiếu trả hàng</h4>
                <button type="button" class="pr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" id="prForm">
                @csrf
                <input type="hidden" name="_method" value="POST" id="prFormMethod">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="purchase_order_id" id="prFormPO">
                <input type="hidden" name="status" id="prFormStatus" value="draft">

                <div class="pr-modal-body">
                    <p class="pr-note" id="prFormInfo">—</p>

                    <table class="pr-items">
                        <thead>
                            <tr>
                                <th class="pr-i-qty"><input type="checkbox" id="prCheckAll" class="pr-check" title="Trả toàn bộ số còn trả được"></th>
                                <th>Sản phẩm</th>
                                <th class="pr-i-price">Giá nhập</th>
                                <th class="pr-i-qty">Còn trả được</th>
                                <th class="pr-i-qty">SL trả</th>
                            </tr>
                        </thead>
                        <tbody id="prFormItems"></tbody>
                    </table>

                    <div class="pr-form-row">
                        <div class="pr-field">
                            <label class="pr-lb" for="prReason">Lý do trả hàng</label>
                            <select name="reason" id="prReason" class="pr-select pr-select-full">
                                @foreach($REASONS as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="pr-field pr-field-grow">
                            <label class="pr-lb" for="prNote">Ghi chú</label>
                            <input type="text" name="note" id="prNote" class="pr-input" maxlength="500"
                                   placeholder="VD: 6 áo bị lỗi đường may, NCC đồng ý nhận lại">
                        </div>
                    </div>

                    <div class="pr-sum-box">
                        <div><span>Số mặt hàng trả</span><b id="prFormLines">0</b></div>
                        <div><span>Tổng số lượng trả</span><b id="prFormQty">0</b></div>
                        <div class="is-total"><span>Giá trị trả lại</span><b id="prFormAmount">0₫</b></div>
                    </div>
                </div>

                <div class="pr-modal-foot pr-foot-center">
                    <button type="button" class="pr-btn-ghost" id="prBackToPick">Chọn phiếu khác</button>
                    <span class="pr-foot-right">
                        <button type="button" class="pr-btn-ghost" id="prSaveDraft" disabled>Lưu nháp</button>
                        <button type="button" class="pr-btn-primary" id="prSaveReturn" disabled>Trả hàng ngay</button>
                    </span>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal chi tiết phiếu trả ===== --}}
    <div class="pr-overlay" id="prViewOverlay" style="display:none;">
        <div class="pr-dialog">
            <div class="pr-modal-head">
                <h4 class="pr-modal-title">Chi tiết phiếu trả <span id="vCode"></span></h4>
                <button type="button" class="pr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="pr-modal-body">
                <div class="pr-view-grid">
                    <div class="pr-cell"><span class="pr-lb">Phiếu đặt gốc</span><span class="pr-vl" id="vPO">—</span></div>
                    <div class="pr-cell"><span class="pr-lb">Nhà cung cấp</span><span class="pr-vl" id="vSup">—</span></div>
                    <div class="pr-cell"><span class="pr-lb">Trạng thái</span><span class="pr-vl" id="vStatus">—</span></div>
                    <div class="pr-cell"><span class="pr-lb">Lý do trả</span><span class="pr-vl" id="vReason">—</span></div>
                    <div class="pr-cell"><span class="pr-lb">Ngày lập</span><span class="pr-vl" id="vCreated">—</span></div>
                    <div class="pr-cell"><span class="pr-lb">Ngày trả hàng</span><span class="pr-vl" id="vReturned">—</span></div>
                    <div class="pr-cell is-full"><span class="pr-lb">Ghi chú</span><span class="pr-vl" id="vNote">—</span></div>
                    <div class="pr-cell is-full" id="vCancelRow" style="display:none;">
                        <span class="pr-lb">Lý do huỷ</span><span class="pr-vl" id="vCancel">—</span>
                    </div>
                </div>

                <table class="pr-items">
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th class="pr-i-price">Giá nhập</th>
                            <th class="pr-i-qty">SL trả</th>
                            <th class="pr-i-total">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody id="vItems"></tbody>
                </table>

                <div class="pr-sum-box">
                    <div><span>Tổng số lượng trả</span><b id="vQty">0</b></div>
                    <div><span>Giá trị trả lại</span><b id="vAmount">0₫</b></div>
                    <div><span>NCC đã hoàn</span><b id="vRefund">0₫</b></div>
                    <div class="is-total"><span>Còn phải hoàn</span><b id="vRemain">0₫</b></div>
                </div>

                <div class="pr-hist" id="vHistWrap">
                    <span class="pr-lb">Lịch sử phiếu</span>
                    <ul class="pr-hist-list" id="vHist"></ul>
                </div>
            </div>

            <div class="pr-modal-foot">
                <span class="pr-foot-left" id="vDangerZone"></span>
                <span class="pr-foot-right" id="vActionZone">
                    <button type="button" class="pr-btn-ghost" data-close>Đóng</button>
                </span>
            </div>
        </div>
    </div>

    {{-- ===== Modal huỷ phiếu (bắt buộc lý do) ===== --}}
    <div class="pr-overlay" id="prCancelOverlay" style="display:none;">
        <div class="pr-dialog pr-dialog-sm">
            <div class="pr-modal-head">
                <h4 class="pr-modal-title">Huỷ phiếu trả <span id="cCode"></span></h4>
                <button type="button" class="pr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="pr-modal-body">
                <p class="pr-note">Phiếu nháp chưa trừ kho nên huỷ không ảnh hưởng tồn kho. Phiếu huỷ vẫn nằm lại
                    danh sách để tra cứu — cần ghi rõ lý do.</p>
                <div class="pr-field">
                    <label class="pr-lb" for="prCancelNote">Lý do huỷ <b class="pr-req">*</b></label>
                    <textarea id="prCancelNote" class="pr-input pr-textarea" maxlength="255" rows="3"
                              placeholder="VD: NCC không đồng ý nhận lại, thương lượng đổi hàng thay vì trả"></textarea>
                </div>
            </div>
            <div class="pr-modal-foot pr-foot-center">
                <button type="button" class="pr-btn-ghost" data-close>Không huỷ</button>
                <button type="button" class="pr-btn-danger" id="prCancelSubmit">Huỷ phiếu</button>
            </div>
        </div>
    </div>

    {{-- ===== Modal ghi nhận NCC hoàn tiền ===== --}}
    <div class="pr-overlay" id="prRefundOverlay" style="display:none;">
        <div class="pr-dialog pr-dialog-sm">
            <div class="pr-modal-head">
                <h4 class="pr-modal-title">NCC hoàn tiền phiếu <span id="rfCode"></span></h4>
                <button type="button" class="pr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="pr-modal-body">
                <p class="pr-note" id="rfInfo">—</p>
                <div class="pr-field">
                    <label class="pr-lb" for="prRefundInput">Tổng số tiền NCC đã hoàn (luỹ kế)</label>
                    <input type="text" id="prRefundInput" class="pr-input" inputmode="numeric" autocomplete="off" placeholder="0">
                </div>
            </div>
            <div class="pr-modal-foot pr-foot-center">
                <button type="button" class="pr-btn-ghost" data-close>Đóng</button>
                <button type="button" class="pr-btn-primary" id="prRefundSubmit">Ghi nhận hoàn tiền</button>
            </div>
        </div>
    </div>

    {{-- Form ẩn dùng chung cho đổi trạng thái / hoàn tiền / xoá --}}
    <form method="POST" id="prActionForm" style="display:none;">
        @csrf
        <input type="hidden" name="_method" id="prActionMethod" value="PUT">
        <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
        <input type="hidden" name="status" id="prActionStatus">
        <input type="hidden" name="note" id="prActionNote">
        <input type="hidden" name="refund_amount" id="prActionRefund" disabled>
    </form>

    <style>
        .pr {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .pr-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .pr-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .pr-sum { font-size: 13px; color: #595959; }
        .pr-sum b { color: #262626; }
        .pr-sum em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Dải "NCC còn phải hoàn tiền" — cùng ngôn ngữ pill với trang Nhập hàng */
        .pr-live {
            display: flex; align-items: center; gap: 8px; width: calc(100% - 40px); margin: 0 20px 12px;
            padding: 9px 14px; border: 1px solid #ffe58f; border-radius: 9999px; background: #fffbe6;
            font-size: 13px; color: #d46b08; cursor: pointer; text-align: left; text-decoration: none;
            transition: background .15s, border-color .15s;
        }
        .pr-live:hover { background: #fff7db; border-color: #ffd666; color: #d46b08; }
        .pr-live b { color: #ad4e00; }
        .pr-live-dot {
            width: 8px; height: 8px; border-radius: 9999px; background: #faad14; flex-shrink: 0;
            animation: prLivePulse 1.6s ease-in-out infinite;
        }
        @keyframes prLivePulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
        @media (prefers-reduced-motion: reduce) { .pr-live-dot { animation: none; } }
        .pr-live-cta { margin-left: auto; font-weight: 600; text-decoration: underline; white-space: nowrap; }

        /* Bộ lọc */
        .pr-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .pr-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .pr-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .pr-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .pr-toolbar-adv.is-open { display: flex; }

        .pr-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .pr-adv-btn:hover, .pr-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .pr-adv-caret { transition: transform .2s; }
        .pr-adv-btn.is-open .pr-adv-caret { transform: rotate(180deg); }
        .pr-adv-count {
            min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff;
            font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
        }

        .pr-searchbox { display: flex; border-radius: 4px; }
        .pr-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .pr-search-input {
            height: 34px; width: 280px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .pr-search-input::placeholder { color: #bfbfbf; }
        .pr-searchbox:focus-within .pr-search-input,
        .pr-searchbox:focus-within .pr-search-btn { border-color: #86b7fe; }
        .pr-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .pr-search-btn:hover { color: #1890ff; }

        .pr-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 200px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .pr-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .pr-select-full { max-width: none; width: 100%; }

        .pr-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .pr-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Nút chung */
        .pr-btn-primary, .pr-btn-ghost, .pr-btn-danger {
            height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; text-decoration: none; transition: background .15s, border-color .15s, color .15s;
        }
        .pr-btn-primary { background: #1890ff; color: #fff; }
        .pr-btn-primary:hover:not([disabled]) { background: #40a9ff; }
        .pr-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .pr-btn-ghost:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .pr-btn-danger { background: #ff4d4f; color: #fff; }
        .pr-btn-danger:hover:not([disabled]) { background: #ff7875; }
        .pr-btn-ghost.is-danger { color: #cf1322; border-color: #ffccc7; }
        .pr-btn-ghost.is-danger:hover:not([disabled]) { background: #fff1f0; border-color: #ff4d4f; color: #cf1322; }
        .pr-btn-primary[disabled], .pr-btn-ghost[disabled], .pr-btn-danger[disabled] { opacity: .5; cursor: not-allowed; }
        .pr-btn-primary svg, .pr-btn-ghost svg { flex-shrink: 0; }

        /* Dropdown Tiện ích */
        .pr-util { position: relative; }
        .pr-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
        }
        .pr-util-btn:hover, .pr-util.open .pr-util-btn { border-color: #1890ff; color: #1890ff; }
        .pr-util-caret { transition: transform .2s; }
        .pr-util.open .pr-util-caret { transform: rotate(180deg); }
        .pr-util-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 4px); z-index: 1050; min-width: 210px;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; box-shadow: 0 6px 20px rgba(0,0,0,.12);
            padding: 4px;
        }
        .pr-util.open .pr-util-menu { display: block; }
        .pr-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; border-radius: 4px;
            background: none; font-size: 13px; color: #262626; text-align: left; text-decoration: none; cursor: pointer;
        }
        .pr-util-item:hover { background: #f5f5f5; color: #1890ff; }
        .pr-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .pr-util-item:hover svg { color: #1890ff; }

        /* Bảng — ô rộng dòng cao, th/td cùng cột khai CÙNG text-align để không lệch */
        .pr-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .pr-table-wrap::-webkit-scrollbar { height: 11px; }
        .pr-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        {{-- table-layout: fixed + % cụ thể cho từng cột — auto-layout để MỘT cột
             width:100% "hút" khoảng dư (kiểu cũ) không đáng tin: trên màn rộng mà
             dữ liệu ngắn, trình duyệt dồn hết khoảng trống vào đúng cột đó, tạo một
             khoảng trắng lệch nhìn như bảng bị "gãy". Fixed layout giữ mọi cột đúng
             tỉ lệ khai báo bất kể nội dung dài ngắn, cột nào tràn thì ellipsis lo. --}}
        {{-- --pr-line = chiều cao MỘT dòng chữ trong ô (13px * line-height 1.5).
             Badge và nút cao hơn một dòng được kéo lại đúng nửa phần dôi ra để tâm
             của chúng trùng tâm dòng chữ đầu — xem quy tắc canh hàng bên dưới. --}}
        .pr-table {
            width: 100%; min-width: 1320px; table-layout: fixed; border-collapse: collapse;
            font-size: 13px; --pr-line: 19.5px;
        }
        .pr-table thead tr { background: #f0f0f0; color: #262626; }
        .pr-table thead th {
            text-align: center; padding: 14px 10px; font-size: 13px; font-weight: 700;
            color: #262626; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        {{-- vertical-align: top, KHÔNG middle — trong một hàng có ô 2 dòng (mã phiếu +
             dòng phụ, số tiền hoàn + badge) lẫn ô 1 dòng. middle canh giữa RIÊNG từng
             ô theo chiều cao nội dung của nó, nên dòng chữ đầu mỗi ô nằm mỗi nơi một
             kiểu. top cho mọi ô bắt đầu từ cùng một mép trên. --}}
        .pr-table tbody td {
            padding: 14px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top;
            text-align: center; white-space: nowrap; line-height: 1.5; overflow: hidden;
        }
        .pr-table tbody tr:hover { background: #fafafa; }

        {{-- Badge và nút cao hơn một dòng chữ nên nếu để nguyên sẽ tụt xuống so với
             chữ ở các ô bên cạnh. vertical-align: top bỏ khoảng thừa do canh theo
             baseline, margin âm hai đầu kéo chúng về đúng tâm dòng đầu — công thức
             (dòng - cao)/2 tự tính lại nếu sau này đổi chiều cao nút/badge.
             Trừ .pr-badge-sm: nó nằm ở DÒNG THỨ HAI của ô "NCC đã hoàn". --}}
        .pr-table tbody .pr-badge:not(.pr-badge-sm),
        .pr-table tbody .pr-rowbtn { vertical-align: top; }
        .pr-table tbody .pr-badge:not(.pr-badge-sm) {
            margin-top: calc((var(--pr-line) - 24px) / 2);
            margin-bottom: calc((var(--pr-line) - 24px) / 2);
        }
        .pr-table tbody .pr-rowbtn {
            margin-top: calc((var(--pr-line) - 30px) / 2);
            margin-bottom: calc((var(--pr-line) - 30px) / 2);
        }

        .pr-table th.pr-c-stt,    .pr-table td.pr-c-stt    { width: 5%; color: #8c8c8c; }
        .pr-table th.pr-c-code,   .pr-table td.pr-c-code   { width: 16%; }
        .pr-table th.pr-c-sup,    .pr-table td.pr-c-sup    { width: 15%; }
        .pr-table th.pr-c-reason, .pr-table td.pr-c-reason { width: 9%; text-overflow: ellipsis; }
        .pr-table th.pr-c-qty,    .pr-table td.pr-c-qty    { width: 6%; }
        .pr-table th.pr-c-amount, .pr-table td.pr-c-amount { width: 8%; font-variant-numeric: tabular-nums; }
        .pr-table th.pr-c-refund, .pr-table td.pr-c-refund { width: 9%; font-variant-numeric: tabular-nums; }
        .pr-table th.pr-c-status, .pr-table td.pr-c-status { width: 8%; }
        .pr-table th.pr-c-date,   .pr-table td.pr-c-date   { width: 10%; color: #595959; }
        .pr-table th.pr-c-act,    .pr-table td.pr-c-act    { width: 14%; overflow: visible; }

        .pr-c-code, .pr-c-sup { cursor: pointer; }
        .pr-code { display: block; font-weight: 600; color: #1890ff; overflow: hidden; text-overflow: ellipsis; }
        .pr-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; }
        .pr-sub { display: block; margin-top: 3px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .pr-qty { font-weight: 600; color: #cf1322; }
        .pr-refund-val { display: block; }
        .pr-c-code[data-view]:hover .pr-code { text-decoration: underline; }

        .pr-badge {
            display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 500; white-space: nowrap; text-decoration: none;
        }
        .pr-badge-sm { margin-top: 3px; padding: 1px 8px; font-size: 11px; }
        .pr-badge.tone-wait { background: #fff7e6; color: #d46b08; }
        .pr-badge.tone-info { background: #e6f7ff; color: #0958d9; }
        .pr-badge.tone-move { background: #f9f0ff; color: #722ed1; }
        .pr-badge.tone-done { background: #f6ffed; color: #389e0d; }
        .pr-badge.tone-stop { background: #fff1f0; color: #cf1322; }

        .pr-rowbtn {
            height: 30px; display: inline-flex; align-items: center; justify-content: center; gap: 4px;
            padding: 0 8px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; color: #595959;
            font-size: 12px; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .pr-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .pr-rowbtn-next { font-weight: 500; }

        /* Dòng trống trải hết bảng nên phải cho xuống dòng, không nowrap như ô dữ liệu. */
        .pr-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; white-space: normal; }

        /* ---- Modal ---- */
        .pr-overlay {
            position: fixed; inset: 0; z-index: 1055; background: rgba(0,0,0,.45);
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .pr-dialog {
            width: 100%; max-width: 860px; max-height: 92vh; overflow-y: auto; background: #fff;
            border-radius: 10px; box-shadow: 0 12px 40px rgba(0,0,0,.2); scrollbar-width: thin;
        }
        .pr-dialog-sm { max-width: 480px; }
        .pr-dialog::-webkit-scrollbar { width: 11px; }
        .pr-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .pr-modal-head {
            position: sticky; top: 0; z-index: 2; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .pr-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .pr-modal-title span { color: #1890ff; }
        .pr-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 4px; border-radius: 4px; }
        .pr-modal-x:hover { background: #f5f5f5; color: #262626; }
        .pr-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        .pr-modal-foot {
            position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; justify-content: center;
            gap: 12px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa;
        }
        .pr-foot-center { justify-content: center; }
        .pr-foot-left { display: flex; align-items: center; gap: 8px; }
        .pr-foot-right { display: flex; align-items: center; gap: 8px; }

        .pr-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px; background: #f5f9ff;
            font-size: 12px; line-height: 1.55; color: #595959;
        }
        .pr-req { color: #cf1322; }

        /* Danh sách phiếu đặt để chọn */
        .pr-pick-search input { width: 100%; }
        .pr-pick-list { display: flex; flex-direction: column; gap: 8px; max-height: 46vh; overflow-y: auto; }
        .pr-pick-item {
            display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px 14px; text-align: left;
            border: 1px solid #e6e6e6; border-radius: 8px; background: #fff; cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .pr-pick-item:hover { border-color: #1890ff; background: #f5faff; }
        .pr-pick-main { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .pr-pick-code { font-weight: 600; color: #1890ff; }
        .pr-pick-sub { font-size: 12px; color: #8c8c8c; }
        .pr-pick-remain { margin-left: auto; text-align: right; font-size: 12px; color: #595959; white-space: nowrap; }
        .pr-pick-remain b { display: block; font-size: 15px; color: #d46b08; }
        .pr-pick-empty { padding: 24px; text-align: center; font-size: 13px; color: #8c8c8c; }

        /* Bảng trong modal */
        .pr-items { width: 100%; border-collapse: collapse; font-size: 13px; }
        .pr-items thead th {
            text-align: left; padding: 9px 10px; border-bottom: 1px solid #f0f0f0; background: #fafafa;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
        }
        .pr-items tbody td { padding: 10px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .pr-items th.pr-i-qty, .pr-items td.pr-i-qty { width: 1%; text-align: center; white-space: nowrap; }
        .pr-items th.pr-i-price, .pr-items td.pr-i-price,
        .pr-items th.pr-i-total, .pr-items td.pr-i-total { width: 1%; text-align: right; white-space: nowrap; }
        .pr-items tbody tr.is-off { opacity: .5; }
        .pr-item-name { display: block; font-weight: 500; }
        .pr-item-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; }

        .pr-sum-box { display: flex; flex-direction: column; gap: 6px; align-self: flex-end; min-width: 280px; font-size: 13px; }
        .pr-sum-box > div { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .pr-sum-box span { color: #595959; }
        .pr-sum-box .is-total { padding-top: 6px; border-top: 1px dashed #e6e6e6; font-size: 14px; }
        .pr-sum-box .is-total b { color: #1890ff; }

        .pr-view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
        .pr-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .pr-cell.is-full { grid-column: span 2; }
        .pr-lb { font-size: 12px; color: #8c8c8c; }
        .pr-vl { font-size: 13px; color: #262626; word-break: break-word; }

        /* Lịch sử phiếu */
        .pr-hist { display: flex; flex-direction: column; gap: 6px; }
        .pr-hist-list { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
        .pr-hist-list li {
            display: flex; align-items: baseline; gap: 10px; padding: 7px 10px;
            border: 1px solid #f0f0f0; border-radius: 6px; font-size: 12px; color: #595959;
        }
        .pr-hist-at { color: #8c8c8c; white-space: nowrap; }
        .pr-hist-move { font-weight: 500; color: #262626; white-space: nowrap; }
        .pr-hist-note { color: #8c8c8c; }

        .pr-form-row { display: flex; gap: 14px; flex-wrap: wrap; }
        .pr-field { display: flex; flex-direction: column; gap: 4px; min-width: 200px; }
        .pr-field-grow { flex: 1; }
        .pr-input {
            height: 36px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            outline: none; font-family: inherit; color: #262626; background: #fff; transition: border-color .15s;
        }
        .pr-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .pr-input::placeholder { color: #bfbfbf; }
        .pr-input-sm { height: 30px; width: 84px; padding: 0 8px; text-align: center; }
        .pr-textarea { height: auto; padding: 8px 12px; resize: vertical; }
        .pr-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; margin: 0; }

        @media (max-width: 640px) {
            .pr-view-grid { grid-template-columns: 1fr; }
            .pr-cell.is-full { grid-column: span 1; }
            .pr-live { width: calc(100% - 24px); margin-inline: 12px; }
        }
    </style>

    <script>
        (function () {
            const SOURCES = @json($sources);
            const BASE = @json(url('admin/purchase-returns'));
            const STATUSES = @json($STATUSES);
            const TONES = @json($TONES);
            const ACTIONS = @json($ACTIONS);
            const REASONS = @json($REASONS);

            const $ = (id) => document.getElementById(id);
            const $filter = $('prFilter');
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));
            const money = (n) => new Intl.NumberFormat('vi-VN').format(Number(n) || 0) + '₫';
            const num = (n) => new Intl.NumberFormat('vi-VN').format(Number(n) || 0);
            const fmtAt = (s) => {
                if (!s) return '—';
                const d = new Date(s);
                return isNaN(d) ? '—' : d.toLocaleString('vi-VN', { hour12: false });
            };

            // ---------- Bộ lọc: đổi select/ngày -> chạy ngay; gõ tìm kiếm -> chờ 400ms ----------
            // Khoảng ngày tự submit trong partials/date-range, ở đây chỉ lo các ô select.
            $filter.querySelectorAll('select').forEach((el) => {
                el.addEventListener('change', () => $filter.submit());
            });
            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // Nút "Nâng cao": ẩn/hiện hàng bộ lọc phụ (nhớ trạng thái qua localStorage).
            (function () {
                const btn = $('prAdvToggle');
                const row = $('prAdvRow');
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('pr-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('pr-adv-open', open ? '1' : '0');
                });
            })();

            // ---------- Đóng/mở modal ----------
            const overlays = ['prPickOverlay', 'prFormOverlay', 'prViewOverlay', 'prCancelOverlay', 'prRefundOverlay'].map($);
            const open = (el) => { el.style.display = 'flex'; };
            const close = (el) => { el.style.display = 'none'; };
            const closeAll = () => overlays.forEach(close);
            overlays.forEach((ov) => {
                ov.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => close(ov)));
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });

            function toastErr(msg) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') { alert(msg); return; }
                const el = document.createElement('div');
                el.className = 'toast align-items-center text-bg-danger border-0';
                el.setAttribute('role', 'alert');
                el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-exclamation-circle-fill me-2"></i>${esc(msg)}</div>`
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: 6000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            async function fetchJSON(url, failMsg) {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                const body = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(body.message || failMsg);
                return body.data;
            }

            // Form ẩn dùng chung: đổi trạng thái / hoàn tiền / xoá.
            const actionForm = $('prActionForm');
            function submitAction(url, method, fields) {
                actionForm.action = url;
                $('prActionMethod').value = method;
                $('prActionStatus').value = fields.status || '';
                $('prActionNote').value = fields.note || '';
                const refund = $('prActionRefund');
                refund.disabled = fields.refund === undefined;
                refund.value = fields.refund === undefined ? '' : fields.refund;
                // status rỗng thì bỏ khỏi payload (route xoá không nhận status)
                $('prActionStatus').disabled = !fields.status;
                $('prActionNote').disabled = fields.note === undefined;
                actionForm.submit();
            }

            // ---------- Bước 1: chọn phiếu đặt có hàng về ----------
            function renderPick(keyword) {
                const kw = (keyword || '').trim().toLowerCase();
                const list = SOURCES.filter((p) => !kw
                    || `${p.po_code} ${p.supplier_name}`.toLowerCase().includes(kw));

                if (!list.length) {
                    $('prPickList').innerHTML = '<p class="pr-pick-empty">' + (SOURCES.length
                        ? 'Không có phiếu nào khớp từ khoá.'
                        : 'Chưa có phiếu đặt nào nhận hàng về kho.<br>Hàng phải <b>nhập kho trước</b> rồi mới trả lại '
                          + 'nhà cung cấp được — hãy nhận hàng ở trang Nhập hàng trước.')
                        + '</p>';
                    return;
                }

                $('prPickList').innerHTML = list.map((p) => `
                    <button type="button" class="pr-pick-item" data-pick="${p.id}">
                        <span class="pr-pick-main">
                            <span class="pr-pick-code">${esc(p.po_code)}</span>
                            <span class="pr-pick-sub">${esc(p.supplier_name || '—')} · lập ${fmtAt(p.created_at)}</span>
                        </span>
                        <span class="pr-pick-remain">đã nhận<b>${num(p.received)}</b>sản phẩm</span>
                    </button>`).join('');
            }

            function openPick() {
                $('prPickSearch').value = '';
                renderPick('');
                closeAll();
                open($('prPickOverlay'));
                setTimeout(() => $('prPickSearch').focus(), 30);
            }

            $('prCreateBtn').addEventListener('click', openPick);
            $('prPickSearch').addEventListener('input', (e) => renderPick(e.target.value));
            $('prPickList').addEventListener('click', async (e) => {
                const btn = e.target.closest('[data-pick]');
                if (!btn) return;
                const po = SOURCES.find((p) => p.id === Number(btn.getAttribute('data-pick')));
                if (!po) return;
                try {
                    const lines = (await fetchJSON(`${BASE}/returnable/${po.id}`,
                        'Không tải được danh sách hàng còn trả được.')) || [];
                    const usable = lines.filter((it) => (it.remain || 0) > 0);
                    if (!usable.length) {
                        toastErr(`Phiếu ${po.po_code} không còn mặt hàng nào trả được — toàn bộ số đã nhận đều nằm trong các phiếu trả khác.`);
                        return;
                    }
                    openForm(po, usable, null);
                } catch (err) {
                    toastErr(err.message);
                }
            });

            // ---------- Bước 2: form lập / sửa phiếu ----------
            // rows[i] = dòng returnable + .cap (trần nhập) + .qty hiện tại.
            let rows = [];
            let editing = null; // phiếu đang sửa (null = lập mới)

            function openForm(po, lines, ticket) {
                editing = ticket;
                rows = lines;
                $('prFormTitle').textContent = ticket ? `Sửa phiếu trả ${ticket.return_code}` : 'Lập phiếu trả hàng';
                $('prForm').action = ticket ? `${BASE}/${ticket.id}` : BASE;
                $('prFormMethod').value = ticket ? 'PUT' : 'POST';
                $('prFormPO').value = po.id;
                $('prFormInfo').innerHTML = `Phiếu đặt <b>${esc(po.po_code)}</b> · ${esc(po.supplier_name || '—')}. `
                    + 'Tồn kho chỉ bị trừ khi phiếu chuyển sang <b>Đã trả NCC</b> — lưu nháp thì chưa đụng tới kho.';
                $('prReason').value = ticket ? (ticket.reason || 'other') : 'other';
                $('prNote').value = ticket ? (ticket.note || '') : '';

                // Sửa phiếu nháp: số đang nằm trong CHÍNH phiếu này cộng lại vào trần trả được.
                const inTicket = {};
                (ticket ? ticket.items || [] : []).forEach((it) => {
                    if (it.purchase_order_item_id) inTicket[it.purchase_order_item_id] = it.quantity || 0;
                });
                rows.forEach((it) => {
                    it.cap = (it.remain || 0) + (inTicket[it.purchase_order_item_id] || 0);
                    it.qty = inTicket[it.purchase_order_item_id] || 0;
                });

                $('prFormItems').innerHTML = rows.map((it, i) => {
                    const meta = [it.variant_sku, it.variant_name].filter(Boolean).join(' · ');
                    return `<tr data-row="${i}" class="is-off">
                            <td class="pr-i-qty"><input type="checkbox" class="pr-check pr-pick-row" data-i="${i}"></td>
                            <td>
                                <span class="pr-item-name">${esc(it.product_name)}</span>
                                ${meta ? `<span class="pr-item-sub">${esc(meta)}</span>` : ''}
                                <span class="pr-item-sub">đã nhận ${num(it.received)} · đã trả ${num(it.returned)} · tồn kho ${num(it.stock)}</span>
                                <input type="hidden" name="items[${i}][purchase_order_item_id]" value="${it.purchase_order_item_id}">
                            </td>
                            <td class="pr-i-price">${money(it.unit_cost)}</td>
                            <td class="pr-i-qty">${num(it.cap)}</td>
                            <td class="pr-i-qty">
                                <input type="number" class="pr-input pr-input-sm pr-qty" name="items[${i}][quantity]"
                                       value="0" min="0" max="${it.cap}" data-i="${i}">
                            </td>
                        </tr>`;
                }).join('');

                // Lập mới để 0 hết (trả hàng là ngoại lệ, không phải mặc định);
                // sửa nháp thì hiện lại đúng số đang có trên phiếu.
                rows.forEach((it, i) => setRow(i, ticket ? it.qty : 0));
                refresh();

                // Sửa phiếu chỉ có MỘT nút lưu; trạng thái giữ nguyên là nháp.
                $('prSaveDraft').textContent = ticket ? 'Lưu thay đổi' : 'Lưu nháp';
                $('prSaveReturn').style.display = ticket ? 'none' : '';
                $('prBackToPick').style.display = ticket ? 'none' : '';

                closeAll();
                open($('prFormOverlay'));
            }

            function setRow(i, qty) {
                const tr = $('prFormItems').querySelector(`[data-row="${i}"]`);
                if (!tr) return;
                const q = Math.min(Math.max(0, qty), rows[i].cap);
                rows[i].qty = q;
                tr.querySelector('.pr-qty').value = q;
                tr.querySelector('.pr-pick-row').checked = q > 0;
                tr.classList.toggle('is-off', q === 0);
            }

            function refresh() {
                let lines = 0, qty = 0, amount = 0;
                rows.forEach((it) => {
                    if (it.qty > 0) {
                        lines++;
                        qty += it.qty;
                        amount += it.qty * (Number(it.unit_cost) || 0);
                    }
                });
                const all = $('prCheckAll');
                all.checked = rows.length > 0 && lines === rows.length;
                all.indeterminate = lines > 0 && lines < rows.length;
                $('prFormLines').textContent = num(lines);
                $('prFormQty').textContent = num(qty);
                $('prFormAmount').textContent = money(amount);
                $('prSaveDraft').disabled = lines === 0;
                $('prSaveReturn').disabled = lines === 0;
            }

            $('prFormItems').addEventListener('change', (e) => {
                const pick = e.target.closest('.pr-pick-row');
                if (!pick) return;
                const i = Number(pick.dataset.i);
                setRow(i, pick.checked ? rows[i].cap : 0);
                refresh();
            });
            $('prFormItems').addEventListener('input', (e) => {
                const qty = e.target.closest('.pr-qty');
                if (!qty) return;
                setRow(Number(qty.dataset.i), Number(qty.value) || 0);
                refresh();
            });
            $('prCheckAll').addEventListener('change', function () {
                rows.forEach((it, i) => setRow(i, this.checked ? it.cap : 0));
                refresh();
            });
            $('prBackToPick').addEventListener('click', openPick);

            function submitForm(status) {
                if (!rows.some((it) => it.qty > 0)) {
                    toastErr('Vui lòng nhập số lượng trả cho ít nhất một sản phẩm.');
                    return;
                }
                $('prFormStatus').value = status;
                $('prFormStatus').disabled = !!editing; // sửa phiếu không gửi status
                $('prSaveDraft').disabled = true;       // chặn bấm hai lần khi mạng chậm
                $('prSaveReturn').disabled = true;
                $('prForm').submit();
            }

            $('prSaveDraft').addEventListener('click', () => submitForm('draft'));
            $('prSaveReturn').addEventListener('click', () => {
                const qty = rows.reduce((s, it) => s + it.qty, 0);
                Promise.resolve(window.sysConfirm({
                    title: 'Trả hàng cho nhà cung cấp',
                    message: `Lập phiếu và trừ ngay ${num(qty)} sản phẩm khỏi tồn kho? Sau bước này phiếu không sửa được nữa.`,
                    confirmText: 'Trả hàng ngay',
                })).then((ok) => { if (ok) submitForm('returned'); });
            });

            // ---------- Chi tiết phiếu trả ----------
            let current = null; // detail đang mở trong modal xem

            async function openView(id) {
                let data = null;
                try {
                    data = await fetchJSON(`${BASE}/${id}/detail`, 'Không tải được chi tiết phiếu trả.');
                } catch (err) {
                    toastErr(err.message);
                    return;
                }
                if (!data) { toastErr('Không tải được chi tiết phiếu trả.'); return; }
                current = data;

                $('vCode').textContent = data.return_code || '';
                $('vPO').textContent = data.po_code || '—';
                $('vSup').textContent = data.supplier_name || '—';
                $('vStatus').innerHTML = `<span class="pr-badge tone-${TONES[data.status] || 'info'}">${esc(STATUSES[data.status] || '—')}</span>`;
                $('vReason').textContent = REASONS[data.reason] || '—';
                $('vCreated').textContent = fmtAt(data.created_at);
                $('vReturned').textContent = fmtAt(data.returned_at);
                $('vNote').textContent = data.note || '—';
                $('vCancelRow').style.display = data.cancel_reason ? '' : 'none';
                $('vCancel').textContent = data.cancel_reason || '—';

                const items = data.items || [];
                let qty = 0;
                $('vItems').innerHTML = items.length ? items.map((it) => {
                    qty += Number(it.quantity) || 0;
                    const meta = [it.variant_sku, it.variant_name].filter(Boolean).join(' · ');
                    return `<tr>
                            <td>
                                <span class="pr-item-name">${esc(it.product_name || '—')}</span>
                                ${meta ? `<span class="pr-item-sub">${esc(meta)}</span>` : ''}
                            </td>
                            <td class="pr-i-price">${money(it.unit_cost)}</td>
                            <td class="pr-i-qty">-${num(it.quantity)}</td>
                            <td class="pr-i-total">${money(it.total_cost)}</td>
                        </tr>`;
                }).join('') : '<tr><td colspan="4" class="pr-empty">Phiếu không có dòng hàng nào.</td></tr>';

                const amount = Number(data.items_amount) || 0;
                const refunded = Number(data.refund_amount) || 0;
                $('vQty').textContent = num(qty);
                $('vAmount').textContent = money(amount);
                $('vRefund').textContent = money(refunded);
                $('vRemain').textContent = money(Math.max(amount - refunded, 0));

                const hist = data.histories || [];
                $('vHistWrap').style.display = hist.length ? '' : 'none';
                $('vHist').innerHTML = hist.map((h) => `
                    <li>
                        <span class="pr-hist-at">${fmtAt(h.created_at)}</span>
                        <span class="pr-hist-move">${esc(STATUSES[h.from_status] || 'Tạo phiếu')} → ${esc(STATUSES[h.to_status] || '—')}</span>
                        ${h.note ? `<span class="pr-hist-note">${esc(h.note)}</span>` : ''}
                    </li>`).join('');

                renderViewActions(data);
                closeAll();
                open($('prViewOverlay'));
            }

            // Nút trong modal xem bám theo next_statuses / can_edit / can_refund API trả
            // về — giao diện không tự đoán luật.
            function renderViewActions(d) {
                const danger = [];
                const main = [];
                const next = d.next_statuses || [];

                if (d.can_edit) {
                    danger.push('<button type="button" class="pr-btn-ghost is-danger" data-act="delete">Xoá phiếu nháp</button>');
                    main.push('<button type="button" class="pr-btn-ghost" data-act="edit">Sửa phiếu</button>');
                }
                if (next.includes('cancelled')) {
                    danger.push(`<button type="button" class="pr-btn-ghost is-danger" data-act="cancel">${esc(ACTIONS.cancelled)}</button>`);
                }
                if (d.can_refund) {
                    main.push('<button type="button" class="pr-btn-ghost" data-act="refund">Ghi nhận hoàn tiền</button>');
                }
                if (next.includes('returned')) {
                    main.push(`<button type="button" class="pr-btn-primary" data-act="mark-returned">${esc(ACTIONS.returned)}</button>`);
                }
                if (next.includes('refunded')) {
                    main.push(`<button type="button" class="pr-btn-primary" data-act="mark-refunded">${esc(ACTIONS.refunded)}</button>`);
                }

                $('vDangerZone').innerHTML = danger.join('');
                $('vActionZone').innerHTML = main.join('') + '<button type="button" class="pr-btn-ghost" data-close-view>Đóng</button>';
            }

            $('prViewOverlay').addEventListener('click', async (e) => {
                if (e.target.closest('[data-close-view]')) { close($('prViewOverlay')); return; }
                const btn = e.target.closest('[data-act]');
                if (!btn || !current) return;
                const act = btn.getAttribute('data-act');

                if (act === 'edit') {
                    if (!current.purchase_order_id) { toastErr('Phiếu đặt gốc đã bị xoá nên không sửa được dòng hàng.'); return; }
                    try {
                        const lines = (await fetchJSON(`${BASE}/returnable/${current.purchase_order_id}`,
                            'Không tải được danh sách hàng còn trả được.')) || [];
                        const mine = new Set((current.items || []).map((it) => it.purchase_order_item_id));
                        const usable = lines.filter((it) => (it.remain || 0) > 0 || mine.has(it.purchase_order_item_id));
                        openForm({ id: current.purchase_order_id, po_code: current.po_code, supplier_name: current.supplier_name },
                            usable, current);
                    } catch (err) {
                        toastErr(err.message);
                    }
                    return;
                }
                if (act === 'delete') return confirmDelete(current);
                if (act === 'cancel') return openCancel(current);
                if (act === 'refund') return openRefund(current);
                if (act === 'mark-returned') return confirmMark(current, 'returned');
                if (act === 'mark-refunded') return confirmMark(current, 'refunded');
            });

            // ---------- Đổi trạng thái / huỷ / hoàn tiền / xoá ----------
            function confirmMark(t, status) {
                const messages = {
                    returned: `Xác nhận đã trả hàng phiếu ${t.return_code} cho nhà cung cấp? Tồn kho sẽ bị TRỪ ngay và phiếu không sửa được nữa.`,
                    refunded: `Xác nhận nhà cung cấp đã hoàn ĐỦ tiền phiếu ${t.return_code}? Phiếu sẽ đóng lại, không thao tác thêm được.`,
                };
                Promise.resolve(window.sysConfirm({
                    title: 'Xác nhận thao tác',
                    message: messages[status],
                    confirmText: ACTIONS[status] || 'Xác nhận',
                })).then((ok) => {
                    if (ok) submitAction(`${BASE}/${t.id}/status`, 'PUT', { status, note: '' });
                });
            }

            function confirmDelete(t) {
                Promise.resolve(window.sysDelete({
                    title: 'Xoá phiếu nháp',
                    message: `Xoá hẳn phiếu ${t.return_code}? Phiếu nháp chưa trừ kho nên không để lại dấu vết nào.`,
                    confirmText: 'Xoá phiếu',
                })).then((ok) => {
                    if (ok) submitAction(`${BASE}/${t.id}`, 'DELETE', {});
                });
            }

            let cancelTarget = null;
            function openCancel(t) {
                cancelTarget = t;
                $('cCode').textContent = t.return_code || '';
                $('prCancelNote').value = '';
                closeAll();
                open($('prCancelOverlay'));
                setTimeout(() => $('prCancelNote').focus(), 30);
            }
            $('prCancelSubmit').addEventListener('click', () => {
                const note = $('prCancelNote').value.trim();
                if (!note) { toastErr('Vui lòng nhập lý do huỷ phiếu trả hàng.'); return; }
                submitAction(`${BASE}/${cancelTarget.id}/status`, 'PUT', { status: 'cancelled', note });
            });

            // Ô tiền định dạng nghìn kiểu VN (1.500.000) — gõ tới đâu format tới đó.
            const refundInput = $('prRefundInput');
            const parseMoney = (s) => Number(String(s).replace(/[^\d]/g, '')) || 0;
            refundInput.addEventListener('input', () => {
                const v = parseMoney(refundInput.value);
                refundInput.value = v ? new Intl.NumberFormat('vi-VN').format(v) : '';
            });

            let refundTarget = null;
            function openRefund(t) {
                refundTarget = t;
                const amount = Number(t.items_amount) || 0;
                const refunded = Number(t.refund_amount) || 0;
                $('rfCode').textContent = t.return_code || '';
                $('rfInfo').innerHTML = `Giá trị phiếu <b>${money(amount)}</b> · NCC đã hoàn <b>${money(refunded)}</b>. `
                    + 'Nhập tổng số đã hoàn LUỸ KẾ — hoàn đủ thì phiếu tự chuyển sang "NCC đã hoàn tiền".';
                refundInput.value = amount ? new Intl.NumberFormat('vi-VN').format(amount) : '';
                closeAll();
                open($('prRefundOverlay'));
                setTimeout(() => refundInput.focus(), 30);
            }
            $('prRefundSubmit').addEventListener('click', () => {
                const v = parseMoney(refundInput.value);
                const amount = Number(refundTarget.items_amount) || 0;
                if (v > amount) {
                    toastErr(`Số tiền hoàn không được vượt giá trị phiếu (${money(amount)}).`);
                    return;
                }
                submitAction(`${BASE}/${refundTarget.id}/refund`, 'PUT', { refund: v });
            });

            // ---------- Bảng: mở chi tiết + nút bước kế tiếp trên dòng ----------
            document.querySelector('.pr-table tbody').addEventListener('click', async (e) => {
                const nextBtn = e.target.closest('[data-next]');
                if (nextBtn) {
                    confirmMark(
                        { id: Number(nextBtn.getAttribute('data-id')), return_code: nextBtn.getAttribute('data-code') },
                        nextBtn.getAttribute('data-next')
                    );
                    return;
                }
                const view = e.target.closest('[data-view]');
                if (view) openView(Number(view.getAttribute('data-view')));
            });

            // ---------- Dropdown Tiện ích ----------
            const util = $('prUtil');
            $('prUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = !util.classList.contains('open');
                util.classList.toggle('open', isOpen);
                e.currentTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            document.addEventListener('click', () => {
                util.classList.remove('open');
                $('prUtilBtn').setAttribute('aria-expanded', 'false');
            });
        })();
    </script>
@endsection
