@extends('layouts.app')

@section('title', \App\Http\Controllers\ReceiptController::TITLE)

@section('content')
    {{--
        Trang "Nhập hàng" — sổ hàng về kho, cùng khuôn với Đặt hàng nhập / Nhà cung cấp:
        [ header ] + [ dải việc cần làm ] + [ thanh lọc realtime ] + [ bảng ] + [ chân trang ] + [ modal ].

        Mỗi lần bấm "Nhận hàng" trên một phiếu đặt là MỘT ĐỢT nhập, mã đợt dạng
        <mã phiếu đặt>-N<đợt>. Đợt nhập không có bảng riêng: API dựng lại từ mốc lịch
        sử phiếu + bút toán sổ kho, nên các đợt đã nhận từ trước cũng hiện đủ.

        Form nhận hàng ở đây gửi tới ĐÚNG route của trang Đặt hàng nhập
        (admin.purchases.receive) — chỗ duy nhất được cộng tồn kho. Trang này chỉ là
        một lối vào khác của cùng luồng, không có đường ghi kho riêng.
    --}}
    @php
        $SORTS = \App\Http\Controllers\ReceiptController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\ReceiptController::PAGE_SIZES;
        $TITLE = \App\Http\Controllers\ReceiptController::TITLE;
        $EMPTY_TEXT = \App\Http\Controllers\ReceiptController::EMPTY_TEXT;
        $PO_STATUSES = \App\Http\Controllers\PurchaseController::STATUSES;
        $PO_TONES = \App\Http\Controllers\PurchaseController::STATUS_TONES;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['supplier_id'] !== ''
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        $money = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
    @endphp

    <div class="rc">
        {{-- Header --}}
        <div class="rc-head">
            <h1 class="rc-title">{{ $TITLE }}</h1>
            <span class="rc-sum">
                Đã nhập: <b>{{ number_format($stats['total_receipts'], 0, ',', '.') }}</b> đợt ·
                <b>{{ number_format($stats['total_quantity'], 0, ',', '.') }}</b> sản phẩm
                ({{ $money($stats['total_amount']) }}) ·
                Hôm nay: <b>{{ number_format($stats['today_receipts'], 0, ',', '.') }}</b> đợt
                ({{ number_format($stats['today_quantity'], 0, ',', '.') }} sản phẩm)
                <em>(giá trị tính theo giá nhập trên phiếu đặt)</em>
            </span>
        </div>

        {{-- Việc cần làm: hàng đã đặt mà chưa về. Bấm vào là mở luôn form nhận hàng. --}}
        @if($stats['pending_orders'] > 0)
            <button type="button" class="rc-live" id="rcPendingBar">
                <span class="rc-live-dot"></span>
                Đang chờ <b>{{ number_format($stats['pending_orders'], 0, ',', '.') }}</b> phiếu với
                <b>{{ number_format($stats['pending_quantity'], 0, ',', '.') }}</b> sản phẩm chưa về kho
                <span class="rc-live-cta">Nhận hàng ngay</span>
            </button>
        @endif

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.receipts.index') }}" id="rcFilter" class="rc-filter">
            <div class="rc-toolbar">
                <div class="rc-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="rc-search-input"
                           placeholder="Tìm theo mã đợt, mã phiếu đặt, nhà cung cấp hoặc người nhận" autocomplete="off">
                    <button type="submit" class="rc-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="supplier_id" class="rc-select" title="Lọc theo nhà cung cấp">
                    <option value="">Tất cả nhà cung cấp</option>
                    @foreach($suppliers as $sup)
                        <option value="{{ $sup['id'] }}" {{ (string) $filters['supplier_id'] === (string) $sup['id'] ? 'selected' : '' }}>
                            {{ $sup['name'] }}
                        </option>
                    @endforeach
                </select>

                {{-- Khoảng ngày nhận hàng — dùng component chung, KHÔNG tự dựng ô ngày riêng. --}}
                @include('partials.date-range', [
                    'formId' => 'rcFilter',
                    'from' => $filters['from_date'],
                    'to' => $filters['to_date'],
                    'title' => 'Lọc theo ngày nhận hàng',
                ])

                <select name="sort" class="rc-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.receipts.index') }}" class="rc-clear">Xoá lọc</a>
                @endif

                <div class="rc-toolbar-actions">
                    {{-- KHÔNG disable khi chưa có phiếu chờ: bấm vào phải mở ra và nói rõ vì
                         sao chưa nhận được hàng, chứ không im lặng không phản ứng gì. --}}
                    <button type="button" class="rc-btn-primary" id="rcReceiveBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8"/><path d="M2 4h20v4H2z"/><path d="M12 12v5m0 0-2-2m2 2 2-2"/>
                        </svg>
                        Nhận hàng
                    </button>

                    <div class="rc-util" id="rcUtil">
                        <button type="button" class="rc-util-btn" id="rcUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="rc-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="rc-util-menu">
                            <a href="{{ route('admin.receipts.export', request()->query()) }}" class="rc-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng sổ nhập hàng --}}
        <div class="rc-table-wrap">
            <table class="rc-table">
                <thead>
                    <tr>
                        <th class="rc-c-stt">STT</th>
                        <th class="rc-c-code">Mã đợt nhập</th>
                        <th class="rc-c-sup">Nhà cung cấp</th>
                        <th class="rc-c-lines">Mặt hàng</th>
                        <th class="rc-c-qty">SL nhận</th>
                        <th class="rc-c-amount">Giá trị nhập</th>
                        <th class="rc-c-user">Người nhận</th>
                        <th class="rc-c-status">Phiếu đặt</th>
                        <th class="rc-c-date">Thời điểm nhận</th>
                        <th class="rc-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($receipts as $i => $r)
                        @php
                            $code = $r['code'] ?? '';
                            $poStatus = $r['po_status'] ?? '';
                        @endphp
                        <tr data-code="{{ $code }}">
                            <td class="rc-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="rc-c-code" data-view="{{ $code }}" title="Xem chi tiết đợt nhập">
                                <span class="rc-code">{{ $code }}</span>
                                <span class="rc-sub">
                                    đợt {{ $r['batch'] ?? 1 }} của phiếu {{ $r['po_code'] ?? '—' }}
                                </span>
                            </td>
                            <td class="rc-c-sup" data-view="{{ $code }}" title="Xem chi tiết đợt nhập">
                                <span class="rc-name">{{ !empty($r['supplier_name']) ? $r['supplier_name'] : '—' }}</span>
                                @if(!empty($r['note']))
                                    <span class="rc-sub" title="{{ $r['note'] }}">{{ \Illuminate\Support\Str::limit($r['note'], 60) }}</span>
                                @endif
                            </td>
                            <td class="rc-c-lines">{{ number_format((int) ($r['line_count'] ?? 0), 0, ',', '.') }}</td>
                            <td class="rc-c-qty"><span class="rc-qty">+{{ number_format((int) ($r['quantity'] ?? 0), 0, ',', '.') }}</span></td>
                            <td class="rc-c-amount">{{ $money($r['amount'] ?? 0) }}</td>
                            <td class="rc-c-user">{{ !empty($r['created_by_name']) ? $r['created_by_name'] : '—' }}</td>
                            <td class="rc-c-status">
                                <a class="rc-badge tone-{{ $PO_TONES[$poStatus] ?? 'info' }}"
                                   href="{{ route('admin.purchases.index', ['keyword' => $r['po_code'] ?? '']) }}"
                                   title="Mở phiếu đặt {{ $r['po_code'] ?? '' }}">
                                    {{ $PO_STATUSES[$poStatus] ?? '—' }}
                                </a>
                            </td>
                            <td class="rc-c-date">
                                {{-- Carbon::parse giữ nguyên offset +07:00 API trả về (strtotime đổi về UTC làm lệch ngày) --}}
                                {{ !empty($r['received_at']) ? \Illuminate\Support\Carbon::parse($r['received_at'])->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="rc-c-act">
                                <button type="button" class="rc-rowbtn" data-view="{{ $code }}" title="Xem chi tiết đợt nhập">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="3"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="rc-empty">
                                @if($hasFilter)
                                    Không tìm thấy đợt nhập nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'đợt nhập',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- ===== Modal chọn phiếu để nhận hàng ===== --}}
    <div class="rc-overlay" id="rcPickOverlay" style="display:none;">
        <div class="rc-dialog">
            <div class="rc-modal-head">
                <h4 class="rc-modal-title">Chọn phiếu đặt có hàng về</h4>
                <button type="button" class="rc-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="rc-modal-body">
                <p class="rc-note">Chỉ hiện phiếu <b>đã gửi nhà cung cấp</b> mà hàng chưa về đủ. Phiếu nháp chưa đặt thật nên không nhận được hàng.</p>
                <div class="rc-pick-search">
                    <input type="text" class="rc-input" id="rcPickSearch" autocomplete="off"
                           placeholder="Lọc theo mã phiếu hoặc nhà cung cấp">
                </div>
                <div class="rc-pick-list" id="rcPickList"></div>
            </div>
            <div class="rc-modal-foot">
                <button type="button" class="rc-btn-ghost" data-close>Đóng</button>
            </div>
        </div>
    </div>

    {{-- ===== Modal nhận hàng (gửi tới route của trang Đặt hàng nhập) ===== --}}
    <div class="rc-overlay" id="rcReceiveOverlay" style="display:none;">
        <div class="rc-dialog">
            <div class="rc-modal-head">
                <h4 class="rc-modal-title">Nhận hàng vào kho</h4>
                <button type="button" class="rc-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" id="rcReceiveForm">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="rc-modal-body">
                    <p class="rc-note" id="rcInfo">—</p>

                    <table class="rc-items">
                        <thead>
                            <tr>
                                <th class="rc-i-qty"><input type="checkbox" id="rcCheckAll" class="rc-check" title="Nhận toàn bộ số còn lại"></th>
                                <th>Sản phẩm</th>
                                <th class="rc-i-price">Giá nhập</th>
                                <th class="rc-i-qty">Còn thiếu</th>
                                <th class="rc-i-qty">SL nhận</th>
                            </tr>
                        </thead>
                        <tbody id="rcItems"></tbody>
                    </table>

                    <label class="rc-checkline">
                        <input type="hidden" name="update_cost" value="0">
                        <input type="checkbox" name="update_cost" value="1" id="rcUpdateCost" class="rc-check" checked>
                        <span>Cập nhật giá vốn theo giá nhập đợt này
                            <em>— bỏ chọn nếu muốn giữ nguyên giá vốn đang khai</em></span>
                    </label>

                    <div class="rc-field">
                        <label class="rc-lb" for="rcNote">Ghi chú đợt nhận</label>
                        <input type="text" name="note" id="rcNote" class="rc-input" maxlength="255"
                               placeholder="VD: NCC giao thiếu 6 áo, hẹn tuần sau giao nốt">
                    </div>
                </div>

                <div class="rc-modal-foot rc-foot-center">
                    <button type="button" class="rc-btn-ghost" id="rcBackToPick">Chọn phiếu khác</button>
                    <button type="submit" class="rc-btn-primary" id="rcSubmit" disabled>Xác nhận nhận hàng</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal chi tiết đợt nhập ===== --}}
    <div class="rc-overlay" id="rcViewOverlay" style="display:none;">
        <div class="rc-dialog">
            <div class="rc-modal-head">
                <h4 class="rc-modal-title">Chi tiết đợt nhập <span id="vCode"></span></h4>
                <button type="button" class="rc-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="rc-modal-body">
                <div class="rc-view-grid">
                    <div class="rc-cell"><span class="rc-lb">Phiếu đặt</span><span class="rc-vl" id="vPO">—</span></div>
                    <div class="rc-cell"><span class="rc-lb">Nhà cung cấp</span><span class="rc-vl" id="vSup">—</span></div>
                    <div class="rc-cell"><span class="rc-lb">Thời điểm nhận</span><span class="rc-vl" id="vAt">—</span></div>
                    <div class="rc-cell"><span class="rc-lb">Người nhận</span><span class="rc-vl" id="vUser">—</span></div>
                    <div class="rc-cell is-full"><span class="rc-lb">Ghi chú</span><span class="rc-vl" id="vNote">—</span></div>
                </div>

                <table class="rc-items">
                    <thead>
                        <tr>
                            <th>Sản phẩm</th>
                            <th class="rc-i-price">Giá nhập</th>
                            <th class="rc-i-qty">SL nhận</th>
                            <th class="rc-i-total">Thành tiền</th>
                            <th class="rc-i-qty">Tồn trước → sau</th>
                        </tr>
                    </thead>
                    <tbody id="vItems"></tbody>
                </table>

                <div class="rc-sum-box">
                    <div><span>Số mặt hàng</span><b id="vLines">0</b></div>
                    <div><span>Tổng số lượng nhận</span><b id="vQty">0</b></div>
                    <div class="is-total"><span>Giá trị nhập</span><b id="vAmount">0₫</b></div>
                </div>

                <p class="rc-note">Số liệu đợt nhập dựng từ sổ kho: cột <b>Tồn trước → sau</b> chính là bút toán đã ghi vào
                    kho lúc đó, nên đối chiếu được với trang Tồn kho.</p>
            </div>

            <div class="rc-modal-foot">
                <div class="rc-foot-right">
                    <a class="rc-btn-ghost" id="vPOLink" href="#">Mở phiếu đặt</a>
                    <button type="button" class="rc-btn-ghost" data-close>Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .rc {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .rc-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .rc-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .rc-sum { font-size: 13px; color: #595959; }
        .rc-sum b { color: #262626; }
        .rc-sum em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Dải "đang chờ hàng về" — cùng ngôn ngữ pill với dải đơn mới bên trang Đơn hàng */
        .rc-live {
            display: flex; align-items: center; gap: 8px; width: calc(100% - 40px); margin: 0 20px 12px;
            padding: 9px 14px; border: 1px solid #bae0ff; border-radius: 9999px; background: #f0f8ff;
            font-size: 13px; color: #0958d9; cursor: pointer; text-align: left;
            transition: background .15s, border-color .15s;
        }
        .rc-live:hover { background: #e6f4ff; border-color: #91caff; }
        .rc-live-dot {
            width: 8px; height: 8px; border-radius: 9999px; background: #1890ff; flex-shrink: 0;
            animation: rcLivePulse 1.6s ease-in-out infinite;
        }
        @keyframes rcLivePulse { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
        @media (prefers-reduced-motion: reduce) { .rc-live-dot { animation: none; } }
        .rc-live-cta { margin-left: auto; font-weight: 600; text-decoration: underline; white-space: nowrap; }

        /* Bộ lọc */
        .rc-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .rc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .rc-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .rc-searchbox { display: flex; border-radius: 4px; }
        .rc-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .rc-search-input {
            height: 34px; width: 300px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .rc-search-input::placeholder { color: #bfbfbf; }
        .rc-searchbox:focus-within .rc-search-input,
        .rc-searchbox:focus-within .rc-search-btn { border-color: #86b7fe; }
        .rc-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .rc-search-btn:hover { color: #1890ff; }

        .rc-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .rc-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        .rc-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .rc-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Nút chung */
        .rc-btn-primary, .rc-btn-ghost {
            height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; text-decoration: none; transition: background .15s, border-color .15s, color .15s;
        }
        .rc-btn-primary { background: #1890ff; color: #fff; }
        .rc-btn-primary:hover:not([disabled]) { background: #40a9ff; }
        .rc-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .rc-btn-ghost:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .rc-btn-primary[disabled], .rc-btn-ghost[disabled] { opacity: .5; cursor: not-allowed; }
        .rc-btn-primary svg, .rc-btn-ghost svg { flex-shrink: 0; }

        /* Dropdown Tiện ích */
        .rc-util { position: relative; }
        .rc-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
        }
        .rc-util-btn:hover, .rc-util.open .rc-util-btn { border-color: #1890ff; color: #1890ff; }
        .rc-util-caret { transition: transform .2s; }
        .rc-util.open .rc-util-caret { transform: rotate(180deg); }
        .rc-util-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 4px); z-index: 1050; min-width: 210px;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; box-shadow: 0 6px 20px rgba(0,0,0,.12);
            padding: 4px;
        }
        .rc-util.open .rc-util-menu { display: block; }
        .rc-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; border-radius: 4px;
            background: none; font-size: 13px; color: #262626; text-align: left; text-decoration: none; cursor: pointer;
        }
        .rc-util-item:hover { background: #f5f5f5; color: #1890ff; }
        .rc-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .rc-util-item:hover svg { color: #1890ff; }

        /* Bảng — ô rộng dòng cao, th/td cùng cột khai CÙNG text-align để không lệch */
        .rc-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .rc-table-wrap::-webkit-scrollbar { height: 11px; }
        .rc-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        .rc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rc-table thead th {
            text-align: left; padding: 13px 18px; border-bottom: 1px solid #f0f0f0; background: #fafafa;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
        }
        .rc-table tbody td {
            padding: 16px 18px; border-bottom: 1px solid #f5f5f5; vertical-align: middle;
            white-space: nowrap; line-height: 1.5;
        }
        .rc-table tbody tr:hover { background: #fafcff; }

        .rc-table th.rc-c-stt,    .rc-table td.rc-c-stt    { width: 1%; text-align: center; color: #8c8c8c; }
        .rc-table th.rc-c-code,   .rc-table td.rc-c-code   { width: 1%; }
        .rc-table th.rc-c-sup,    .rc-table td.rc-c-sup    { width: 100%; max-width: 0; min-width: 200px; overflow: hidden; }
        .rc-table th.rc-c-lines,  .rc-table td.rc-c-lines  { width: 1%; text-align: center; }
        .rc-table th.rc-c-qty,    .rc-table td.rc-c-qty    { width: 1%; text-align: center; }
        .rc-table th.rc-c-amount, .rc-table td.rc-c-amount { width: 1%; text-align: right; }
        .rc-table th.rc-c-user,   .rc-table td.rc-c-user   { width: 1%; }
        .rc-table th.rc-c-status, .rc-table td.rc-c-status { width: 1%; text-align: center; }
        .rc-table th.rc-c-date,   .rc-table td.rc-c-date   { width: 1%; text-align: center; color: #595959; }
        .rc-table th.rc-c-act,    .rc-table td.rc-c-act    { width: 1%; text-align: center; }

        .rc-c-code, .rc-c-sup { cursor: pointer; }
        .rc-code { display: block; font-weight: 600; color: #1890ff; }
        .rc-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; }
        .rc-sub { display: block; margin-top: 3px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .rc-qty { font-weight: 600; color: #389e0d; }
        .rc-c-code[data-view]:hover .rc-code { text-decoration: underline; }

        .rc-badge {
            display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 500; white-space: nowrap; text-decoration: none;
        }
        .rc-badge.tone-wait { background: #fff7e6; color: #d46b08; }
        .rc-badge.tone-info { background: #e6f7ff; color: #0958d9; }
        .rc-badge.tone-move { background: #f9f0ff; color: #722ed1; }
        .rc-badge.tone-done { background: #f6ffed; color: #389e0d; }
        .rc-badge.tone-stop { background: #fff1f0; color: #cf1322; }

        .rc-rowbtn {
            width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #e5e7eb; border-radius: 6px; background: #fff; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .rc-rowbtn:hover { border-color: #1890ff; color: #1890ff; }

        /* Dòng trống trải hết bảng nên phải cho xuống dòng, không nowrap như ô dữ liệu. */
        .rc-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; white-space: normal; }

        /* ---- Modal ---- */
        .rc-overlay {
            position: fixed; inset: 0; z-index: 1055; background: rgba(0,0,0,.45);
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .rc-dialog {
            width: 100%; max-width: 860px; max-height: 92vh; overflow-y: auto; background: #fff;
            border-radius: 10px; box-shadow: 0 12px 40px rgba(0,0,0,.2); scrollbar-width: thin;
        }
        .rc-dialog::-webkit-scrollbar { width: 11px; }
        .rc-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .rc-modal-head {
            position: sticky; top: 0; z-index: 2; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .rc-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .rc-modal-title span { color: #1890ff; }
        .rc-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 4px; border-radius: 4px; }
        .rc-modal-x:hover { background: #f5f5f5; color: #262626; }
        .rc-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        .rc-modal-foot {
            position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; justify-content: center;
            gap: 12px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa;
        }
        .rc-foot-center { justify-content: center; }
        .rc-foot-right { display: flex; align-items: center; gap: 8px; }

        .rc-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px; background: #f5f9ff;
            font-size: 12px; line-height: 1.55; color: #595959;
        }

        /* Danh sách phiếu chờ nhận */
        .rc-pick-search input { width: 100%; }
        .rc-pick-list { display: flex; flex-direction: column; gap: 8px; max-height: 46vh; overflow-y: auto; }
        .rc-pick-item {
            display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px 14px; text-align: left;
            border: 1px solid #e6e6e6; border-radius: 8px; background: #fff; cursor: pointer;
            transition: border-color .15s, background .15s;
        }
        .rc-pick-item:hover { border-color: #1890ff; background: #f5faff; }
        .rc-pick-main { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .rc-pick-code { font-weight: 600; color: #1890ff; }
        .rc-pick-sub { font-size: 12px; color: #8c8c8c; }
        .rc-pick-remain { margin-left: auto; text-align: right; font-size: 12px; color: #595959; white-space: nowrap; }
        .rc-pick-remain b { display: block; font-size: 15px; color: #d46b08; }
        .rc-pick-empty { padding: 24px; text-align: center; font-size: 13px; color: #8c8c8c; }

        /* Bảng trong modal */
        .rc-items { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rc-items thead th {
            text-align: left; padding: 9px 10px; border-bottom: 1px solid #f0f0f0; background: #fafafa;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
        }
        .rc-items tbody td { padding: 10px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .rc-items th.rc-i-qty, .rc-items td.rc-i-qty { width: 1%; text-align: center; white-space: nowrap; }
        .rc-items th.rc-i-price, .rc-items td.rc-i-price,
        .rc-items th.rc-i-total, .rc-items td.rc-i-total { width: 1%; text-align: right; white-space: nowrap; }
        .rc-items tbody tr.is-off { opacity: .5; }
        .rc-item-name { display: block; font-weight: 500; }
        .rc-item-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; }

        .rc-sum-box { display: flex; flex-direction: column; gap: 6px; align-self: flex-end; min-width: 280px; font-size: 13px; }
        .rc-sum-box > div { display: flex; align-items: center; justify-content: space-between; gap: 20px; }
        .rc-sum-box span { color: #595959; }
        .rc-sum-box .is-total { padding-top: 6px; border-top: 1px dashed #e6e6e6; font-size: 14px; }
        .rc-sum-box .is-total b { color: #1890ff; }

        .rc-view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
        .rc-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .rc-cell.is-full { grid-column: span 2; }
        .rc-lb { font-size: 12px; color: #8c8c8c; }
        .rc-vl { font-size: 13px; color: #262626; word-break: break-word; }

        .rc-field { display: flex; flex-direction: column; gap: 4px; }
        .rc-input {
            height: 36px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            outline: none; font-family: inherit; color: #262626; background: #fff; transition: border-color .15s;
        }
        .rc-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .rc-input::placeholder { color: #bfbfbf; }
        .rc-input-sm { height: 30px; width: 84px; padding: 0 8px; text-align: center; }
        .rc-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        .rc-checkline { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; color: #262626; }
        .rc-checkline em { font-style: normal; color: #8c8c8c; }

        @media (max-width: 640px) {
            .rc-view-grid { grid-template-columns: 1fr; }
            .rc-cell.is-full { grid-column: span 1; }
            .rc-live { width: calc(100% - 24px); margin-inline: 12px; }
        }
    </style>

    <script>
        (function () {
            const PENDING = @json($pending);
            const RECEIVE_URL = @json(url('admin/purchases'));   // + /{id}/receive
            const DETAIL_URL = @json(url('admin/receipts'));     // + /{code}/detail
            const PURCHASES_URL = @json(route('admin.purchases.index'));

            const $ = (id) => document.getElementById(id);
            const $filter = $('rcFilter');
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

            // ---------- Đóng/mở modal ----------
            const overlays = ['rcPickOverlay', 'rcReceiveOverlay', 'rcViewOverlay'].map($);
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

            // ---------- Bước 1: chọn phiếu đặt có hàng về ----------
            function renderPick(keyword) {
                const kw = (keyword || '').trim().toLowerCase();
                const list = PENDING.filter((p) => !kw
                    || `${p.po_code} ${p.supplier_name}`.toLowerCase().includes(kw));

                if (!list.length) {
                    $('rcPickList').innerHTML = '<p class="rc-pick-empty">' + (PENDING.length
                        ? 'Không có phiếu nào khớp từ khoá.'
                        : 'Không có phiếu đặt nào đang chờ hàng về.<br>Hàng chỉ nhận được từ phiếu đã ở trạng thái '
                          + '<b>Đã đặt hàng</b> hoặc <b>Nhận một phần</b> — hãy lập phiếu đặt hàng và gửi nhà cung cấp trước.')
                        + '</p>';
                    return;
                }

                $('rcPickList').innerHTML = list.map((p) => {
                    const hen = p.expected_date
                        ? `Hẹn giao ${new Date(p.expected_date).toLocaleDateString('vi-VN')}`
                        : 'Chưa hẹn ngày giao';
                    return `<button type="button" class="rc-pick-item" data-pick="${p.id}">
                            <span class="rc-pick-main">
                                <span class="rc-pick-code">${esc(p.po_code)}</span>
                                <span class="rc-pick-sub">${esc(p.supplier_name || '—')} · ${esc(hen)}</span>
                            </span>
                            <span class="rc-pick-remain">còn thiếu<b>${num(p.remain)}</b>sản phẩm</span>
                        </button>`;
                }).join('');
            }

            function openPick() {
                $('rcPickSearch').value = '';
                renderPick('');
                closeAll();
                open($('rcPickOverlay'));
                setTimeout(() => $('rcPickSearch').focus(), 30);
            }

            const receiveBtn = $('rcReceiveBtn');
            if (receiveBtn) receiveBtn.addEventListener('click', openPick);
            const pendingBar = $('rcPendingBar');
            if (pendingBar) pendingBar.addEventListener('click', openPick);

            $('rcPickSearch').addEventListener('input', (e) => renderPick(e.target.value));
            $('rcPickList').addEventListener('click', (e) => {
                const btn = e.target.closest('[data-pick]');
                if (!btn) return;
                const po = PENDING.find((p) => p.id === Number(btn.getAttribute('data-pick')));
                if (po) openReceive(po);
            });

            // ---------- Bước 2: nhập số lượng nhận ----------
            // Dữ liệu dòng hàng lấy từ PENDING (controller đã lọc sẵn dòng còn thiếu),
            // form gửi tới route nhận hàng của trang Đặt hàng nhập.
            let rows = [];

            function openReceive(po) {
                rows = po.items || [];
                $('rcReceiveForm').action = `${RECEIVE_URL}/${po.id}/receive`;
                $('rcInfo').textContent = `Phiếu ${po.po_code} · ${po.supplier_name || '—'} — còn ${num(po.remain)} sản phẩm chưa về.`;
                $('rcNote').value = '';
                $('rcUpdateCost').checked = true;

                $('rcItems').innerHTML = rows.map((it, i) => {
                    const meta = [it.variant_sku, it.size, it.color].filter(Boolean).join(' · ');
                    return `<tr data-row="${i}" class="is-off">
                            <td class="rc-i-qty"><input type="checkbox" class="rc-check rc-pick-row" data-i="${i}"></td>
                            <td>
                                <span class="rc-item-name">${esc(it.product_name)}</span>
                                ${meta ? `<span class="rc-item-sub">${esc(meta)}</span>` : ''}
                                <input type="hidden" name="items[${i}][item_id]" value="${it.item_id}">
                            </td>
                            <td class="rc-i-price">${money(it.unit_cost)}</td>
                            <td class="rc-i-qty">${it.remain} / ${it.quantity}</td>
                            <td class="rc-i-qty">
                                <input type="number" class="rc-input rc-input-sm rc-qty" name="items[${i}][quantity]"
                                       value="0" min="0" max="${it.remain}" data-i="${i}">
                            </td>
                        </tr>`;
                }).join('');

                // Mặc định nhận đủ: phần lớn lần nhận là giao đủ, ai giao thiếu thì sửa
                // vài dòng — nhanh hơn bắt gõ tay từng dòng.
                rows.forEach((it, i) => setRow(i, it.remain));
                refresh();
                closeAll();
                open($('rcReceiveOverlay'));
            }

            function setRow(i, qty) {
                const tr = $('rcItems').querySelector(`[data-row="${i}"]`);
                if (!tr) return;
                const q = Math.min(Math.max(0, qty), rows[i].remain);
                tr.querySelector('.rc-qty').value = q;
                tr.querySelector('.rc-pick-row').checked = q > 0;
                tr.classList.toggle('is-off', q === 0);
            }

            function refresh() {
                let picks = 0;
                $('rcItems').querySelectorAll('[data-row]').forEach((tr) => {
                    if ((Number(tr.querySelector('.rc-qty').value) || 0) > 0) picks++;
                });
                const all = $('rcCheckAll');
                all.checked = rows.length > 0 && picks === rows.length;
                all.indeterminate = picks > 0 && picks < rows.length;
                $('rcSubmit').disabled = picks === 0;
            }

            $('rcItems').addEventListener('change', (e) => {
                const pick = e.target.closest('.rc-pick-row');
                if (!pick) return;
                const i = Number(pick.dataset.i);
                setRow(i, pick.checked ? rows[i].remain : 0);
                refresh();
            });
            $('rcItems').addEventListener('input', (e) => {
                const qty = e.target.closest('.rc-qty');
                if (!qty) return;
                setRow(Number(qty.dataset.i), Number(qty.value) || 0);
                refresh();
            });
            $('rcCheckAll').addEventListener('change', function () {
                rows.forEach((it, i) => setRow(i, this.checked ? it.remain : 0));
                refresh();
            });
            $('rcBackToPick').addEventListener('click', openPick);

            $('rcReceiveForm').addEventListener('submit', (e) => {
                const any = Array.from($('rcItems').querySelectorAll('.rc-qty')).some((el) => (Number(el.value) || 0) > 0);
                if (!any) {
                    e.preventDefault();
                    toastErr('Vui lòng nhập số lượng nhận cho ít nhất một sản phẩm.');
                    return;
                }
                $('rcSubmit').disabled = true;      // chặn bấm hai lần khi mạng chậm
                $('rcSubmit').textContent = 'Đang lưu…';
            });

            // ---------- Chi tiết đợt nhập ----------
            async function openView(code) {
                let data = null;
                try {
                    const res = await fetch(`${DETAIL_URL}/${encodeURIComponent(code)}/detail`, {
                        headers: { Accept: 'application/json' },
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok) { toastErr(body.message || 'Không tải được chi tiết đợt nhập.'); return; }
                    data = body.data;
                } catch (err) {
                    toastErr('Không kết nối được máy chủ để tải chi tiết đợt nhập.');
                    return;
                }
                if (!data) { toastErr('Không tải được chi tiết đợt nhập.'); return; }

                $('vCode').textContent = data.code || '';
                $('vPO').textContent = `${data.po_code || '—'} (đợt ${data.batch || 1})`;
                $('vSup').textContent = data.supplier_name || '—';
                $('vAt').textContent = fmtAt(data.received_at);
                $('vUser').textContent = data.created_by_name || '—';
                $('vNote').textContent = data.note || '—';
                $('vPOLink').href = `${PURCHASES_URL}?keyword=${encodeURIComponent(data.po_code || '')}`;

                const items = data.items || [];
                $('vItems').innerHTML = items.length ? items.map((it) => {
                    const meta = [it.sku, it.size, it.color].filter(Boolean).join(' · ');
                    return `<tr>
                            <td>
                                <span class="rc-item-name">${esc(it.product_name || '—')}</span>
                                ${meta ? `<span class="rc-item-sub">${esc(meta)}</span>` : ''}
                            </td>
                            <td class="rc-i-price">${money(it.unit_cost)}</td>
                            <td class="rc-i-qty">+${num(it.quantity)}</td>
                            <td class="rc-i-total">${money(it.amount)}</td>
                            <td class="rc-i-qty">${num(it.quantity_before)} → ${num(it.quantity_after)}</td>
                        </tr>`;
                }).join('') : '<tr><td colspan="5" class="rc-empty">Không còn dòng hàng nào của đợt này trong sổ kho.</td></tr>';

                $('vLines').textContent = num(data.line_count);
                $('vQty').textContent = num(data.quantity);
                $('vAmount').textContent = money(data.amount);

                closeAll();
                open($('rcViewOverlay'));
            }

            document.querySelector('.rc-table tbody').addEventListener('click', (e) => {
                if (e.target.closest('a')) return;   // badge trạng thái là link sang phiếu đặt
                const view = e.target.closest('[data-view]');
                if (view) openView(view.getAttribute('data-view'));
            });

            // ---------- Dropdown Tiện ích ----------
            const util = $('rcUtil');
            $('rcUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const isOpen = !util.classList.contains('open');
                util.classList.toggle('open', isOpen);
                e.currentTarget.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
            document.addEventListener('click', () => {
                util.classList.remove('open');
                $('rcUtilBtn').setAttribute('aria-expanded', 'false');
            });
        })();
    </script>
@endsection
