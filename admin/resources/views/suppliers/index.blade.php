@extends('layouts.app')

@section('title', \App\Http\Controllers\SupplierController::TITLE)

@section('content')
    {{--
        Trang "Nhà cung cấp" — dựng theo đúng khuôn trang Thương hiệu / Khách hàng:
        [ header ] + [ thanh lọc realtime ] + [ bảng compact ] + [ chân trang ] + [ modal CRUD ].

        Đây là danh mục đầu mối MUA VÀO: trang Đặt hàng nhập chọn nhà cung cấp từ đây.
        Số phiếu / tổng đã đặt / còn nợ / lần đặt gần nhất do API tổng hợp sẵn từ phiếu
        đặt hàng (không tính phiếu nháp và phiếu đã huỷ) — trang này chỉ hiển thị.

        Bên nào còn phiếu đặt hàng thì KHÔNG xoá được (phiếu cũ sẽ mất đầu mối liên hệ);
        lối đúng là chuyển sang "Ngừng hợp tác" — bên đó biến khỏi ô chọn khi lập phiếu.
    --}}
    @php
        $STATUSES = \App\Http\Controllers\SupplierController::STATUSES;
        $DEBTS = \App\Http\Controllers\SupplierController::DEBTS;
        $SORTS = \App\Http\Controllers\SupplierController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\SupplierController::PAGE_SIZES;
        $TITLE = \App\Http\Controllers\SupplierController::TITLE;
        $EMPTY_TEXT = \App\Http\Controllers\SupplierController::EMPTY_TEXT;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['debt'] !== 'all'
            || $filters['sort'] !== 'name_asc';

        $money = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
    @endphp

    <div class="sup">
        {{-- Header --}}
        <div class="sup-head">
            <h1 class="sup-title">{{ $TITLE }}</h1>
            <span class="sup-sum">
                Đang hợp tác: <b>{{ number_format($stats['active'], 0, ',', '.') }}</b>/{{ number_format($stats['total'], 0, ',', '.') }} ·
                Đã đặt: <b>{{ number_format($stats['purchases'], 0, ',', '.') }}</b> phiếu
                ({{ $money($stats['amount']) }}) ·
                Còn nợ: <b>{{ $money($stats['debt_amount']) }}</b>
                <em>(chưa tính phiếu nháp và phiếu đã huỷ)</em>
            </span>
        </div>

        {{-- Bộ lọc: đổi select là chạy ngay, gõ tìm kiếm thì chờ 400ms — không có nút "Áp dụng" --}}
        <form method="GET" action="{{ route('admin.suppliers.index') }}" id="supFilter" class="sup-filter">
            <div class="sup-toolbar">
                <div class="sup-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="sup-search-input"
                           placeholder="Tìm theo mã, tên, người liên hệ, SĐT hoặc MST" autocomplete="off">
                    <button type="submit" class="sup-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="sup-select" title="Lọc theo tình trạng hợp tác">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ number_format($stats[$value] ?? 0, 0, ',', '.') }})
                        </option>
                    @endforeach
                </select>

                @php
                    // Bộ lọc phụ đang bật -> tự mở hàng "Nâng cao" + hiện badge đếm.
                    $advCount = ($filters['debt'] !== 'all' ? 1 : 0) + ($filters['sort'] !== 'name_asc' ? 1 : 0);
                    $advOpen = $advCount > 0;
                @endphp
                <button type="button" id="supAdvToggle" class="sup-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                        aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="sup-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="sup-adv-count">{{ $advCount }}</span>@endif
                </button>

                @if($hasFilter)
                    <a href="{{ route('admin.suppliers.index') }}" class="sup-clear">Xoá lọc</a>
                @endif

                <div class="sup-toolbar-actions">
                    <button type="button" class="sup-btn-primary" id="supAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm nhà cung cấp
                    </button>

                    <div class="sup-util" id="supUtil">
                        <button type="button" class="sup-util-btn" id="supUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="sup-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="sup-util-menu">
                            <a href="{{ route('admin.suppliers.export', request()->query()) }}" class="sup-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Hàng nâng cao: công nợ và kiểu sắp xếp. Hai thứ này hỏi câu phụ
                 ("ai đang nợ", "xếp theo gì") nên giấu đi cho hàng chính còn chỗ,
                 đúng như bản cũ v2 gom lọc phụ vào một khối riêng. --}}
            <div class="sup-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="supAdvRow">
                <select name="debt" class="sup-select" title="Lọc theo công nợ">
                    <option value="all" {{ $filters['debt'] === 'all' ? 'selected' : '' }}>Tất cả công nợ</option>
                    @foreach($DEBTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['debt'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ number_format($stats[$value] ?? 0, 0, ',', '.') }})
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="sup-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Giữ số dòng/trang khi đổi bộ lọc --}}
            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng --}}
        <div class="sup-table-wrap">
            <table class="sup-table">
                <thead>
                    <tr>
                        <th class="sup-c-check"><input type="checkbox" id="supCheckAll" class="sup-check" title="Chọn tất cả trong trang"></th>
                        <th class="sup-c-stt">STT</th>
                        <th class="sup-c-name">Nhà cung cấp</th>
                        <th class="sup-c-contact">Người liên hệ</th>
                        <th class="sup-c-phone">Điện thoại</th>
                        <th class="sup-c-count">Số phiếu</th>
                        <th class="sup-c-amount">Đã đặt</th>
                        <th class="sup-c-debt">Còn nợ</th>
                        <th class="sup-c-date">Đặt gần nhất</th>
                        <th class="sup-c-status">Hợp tác</th>
                        <th class="sup-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suppliers as $i => $s)
                        @php
                            $id = $s['id'] ?? 0;
                            $name = $s['name'] ?? '';
                            $isOn = (bool) ($s['is_active'] ?? false);
                            $count = (int) ($s['purchase_count'] ?? 0);
                            $debt = (float) ($s['debt_amount'] ?? 0);
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="sup-c-check"><input type="checkbox" class="sup-check sup-row-check" value="{{ $id }}"
                                       aria-label="Chọn nhà cung cấp {{ $name !== '' ? $name : $id }}"></td>
                            <td class="sup-c-stt">{{ $firstRank + $i + 1 }}</td>
                            {{-- Mã + ghi chú xếp thành dòng phụ dưới tên: cả hai đều ngắn, tách
                                 thành cột riêng chỉ tạo khoảng trống lớn giữa các cột. --}}
                            <td class="sup-c-name" data-edit="{{ $id }}" title="Bấm để sửa nhà cung cấp">
                                <span class="sup-name">{{ $name !== '' ? $name : '—' }}</span>
                                <span class="sup-sub">
                                    <span class="sup-code">{{ $s['code'] ?? '—' }}</span>
                                    @if(!empty($s['tax_code'])) · MST {{ $s['tax_code'] }} @endif
                                    @if(!empty($s['note'])) · {{ \Illuminate\Support\Str::limit($s['note'], 60) }} @endif
                                </span>
                            </td>
                            <td class="sup-c-contact">
                                <span class="sup-contact">{{ !empty($s['contact_name']) ? $s['contact_name'] : '—' }}</span>
                                @if(!empty($s['email']))
                                    <span class="sup-sub">{{ $s['email'] }}</span>
                                @endif
                            </td>
                            <td class="sup-c-phone">
                                @if(!empty($s['phone']))
                                    <a class="sup-phone" href="tel:{{ preg_replace('/\s+/', '', $s['phone']) }}"
                                       title="Gọi {{ $s['phone'] }}">{{ $s['phone'] }}</a>
                                @else
                                    <span class="sup-muted">—</span>
                                @endif
                            </td>
                            <td class="sup-c-count">
                                @if($count > 0)
                                    <a class="sup-count" href="{{ route('admin.purchases.index', ['supplier_id' => $id]) }}"
                                       title="Xem {{ $count }} phiếu đặt hàng của nhà cung cấp này">
                                        {{ number_format($count, 0, ',', '.') }}
                                    </a>
                                @else
                                    <span class="sup-muted">0</span>
                                @endif
                            </td>
                            <td class="sup-c-amount">
                                {{ (float) ($s['purchase_amount'] ?? 0) > 0 ? $money($s['purchase_amount']) : '—' }}
                            </td>
                            <td class="sup-c-debt">
                                @if($debt > 0)
                                    <span class="sup-debt">{{ $money($debt) }}</span>
                                @else
                                    <span class="sup-muted">—</span>
                                @endif
                            </td>
                            <td class="sup-c-date">
                                {{-- Carbon::parse giữ nguyên offset +07:00 API trả về (strtotime đổi về UTC làm lệch ngày) --}}
                                {{ !empty($s['last_order_at']) ? \Illuminate\Support\Carbon::parse($s['last_order_at'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="sup-c-status">
                                <button type="button" class="sup-switch {{ $isOn ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $isOn ? 1 : 0 }}"
                                        title="{{ $isOn ? 'Đang hợp tác — bấm để ngừng (ẩn khỏi ô chọn khi lập phiếu)' : 'Đã ngừng hợp tác — bấm để hợp tác lại' }}">
                                    <span class="sup-switch-knob"></span>
                                </button>
                            </td>
                            <td class="sup-c-act">
                                <div class="sup-rowacts">
                                    <button type="button" class="sup-rowbtn sup-edit" data-edit="{{ $id }}" title="Sửa nhà cung cấp">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="sup-rowbtn sup-del" data-remove="{{ $id }}" title="Xoá nhà cung cấp">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="sup-empty">
                                @if($hasFilter)
                                    Không tìm thấy nhà cung cấp nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'nhà cung cấp',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    <div id="supBulkMount"></div>

    {{-- Modal Thêm / Sửa nhà cung cấp --}}
    <div class="sup-overlay" id="supFormOverlay" style="display:none;">
        <div class="sup-dialog">
            <div class="sup-modal-head">
                <h4 class="sup-modal-title" id="supFormTitle">Thêm nhà cung cấp</h4>
                <button type="button" class="sup-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="supForm" method="POST" action="{{ route('admin.suppliers.store') }}">
                @csrf
                <input type="hidden" name="_method" id="supFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="sup-modal-body">
                    <div class="sup-grid2">
                        <div>
                            <label class="sup-field-label" for="supName">Tên nhà cung cấp <span class="sup-req">*</span></label>
                            <input type="text" id="supName" name="name" class="sup-input" maxlength="150" required
                                   autocomplete="off" placeholder="VD: Công ty TNHH Sport Import">
                        </div>
                        <div>
                            <label class="sup-field-label" for="supCode">Mã nhà cung cấp</label>
                            <input type="text" id="supCode" name="code" class="sup-input" maxlength="30"
                                   autocomplete="off" placeholder="Bỏ trống để tự sinh NCC001…">
                            <p class="sup-hint">Mã đã in trên chứng từ giấy — để trống khi sửa là giữ nguyên mã cũ.</p>
                        </div>

                        <div>
                            <label class="sup-field-label" for="supContact">Người liên hệ</label>
                            <input type="text" id="supContact" name="contact_name" class="sup-input" maxlength="150"
                                   autocomplete="off" placeholder="VD: Anh Tuấn — phụ trách kinh doanh">
                        </div>
                        <div>
                            <label class="sup-field-label" for="supPhone">Điện thoại</label>
                            <input type="text" id="supPhone" name="phone" class="sup-input" maxlength="20"
                                   autocomplete="off" placeholder="09xxxxxxxx">
                        </div>

                        <div>
                            <label class="sup-field-label" for="supEmail">Email</label>
                            <input type="email" id="supEmail" name="email" class="sup-input" maxlength="191"
                                   autocomplete="off" placeholder="email@congty.vn">
                        </div>
                        <div>
                            <label class="sup-field-label" for="supTax">Mã số thuế</label>
                            <input type="text" id="supTax" name="tax_code" class="sup-input" maxlength="30"
                                   autocomplete="off" placeholder="Cần khi lấy hoá đơn VAT">
                        </div>

                        <div class="sup-col-2">
                            <label class="sup-field-label" for="supAddress">Địa chỉ</label>
                            <input type="text" id="supAddress" name="address" class="sup-input" maxlength="255"
                                   autocomplete="off" placeholder="Số nhà, đường, phường/xã, tỉnh/thành">
                        </div>

                        <div>
                            <label class="sup-field-label" for="supStatus">Tình trạng hợp tác <span class="sup-req">*</span></label>
                            <select id="supStatus" name="is_active" class="sup-msel" required>
                                <option value="1">{{ $STATUSES['active'] }}</option>
                                <option value="0">{{ $STATUSES['inactive'] }}</option>
                            </select>
                            <p class="sup-hint">"Ngừng hợp tác" thì bên này không còn hiện trong ô chọn khi lập phiếu đặt hàng.</p>
                        </div>
                        <div>
                            <label class="sup-field-label" for="supNote">Ghi chú</label>
                            <input type="text" id="supNote" name="note" class="sup-input" maxlength="500"
                                   autocomplete="off" placeholder="VD: giao hàng 3-5 ngày, chiết khấu 5% từ 50 áo">
                        </div>
                    </div>

                    {{-- Chỉ hiện khi sửa một bên đang có phiếu đặt hàng --}}
                    <p class="sup-note" id="supUsedNote" style="display:none;"></p>
                </div>

                <div class="sup-modal-foot">
                    <button type="button" class="sup-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="sup-btn-primary" id="supFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .sup {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .sup-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .sup-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .sup-sum { font-size: 13px; color: #595959; }
        .sup-sum b { color: #262626; }
        .sup-sum em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Bộ lọc */
        .sup-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .sup-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .sup-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .sup-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .sup-toolbar-adv.is-open { display: flex; }
        .sup-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .sup-adv-btn:hover, .sup-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .sup-adv-caret { transition: transform .2s; }
        .sup-adv-btn.is-open .sup-adv-caret { transform: rotate(180deg); }
        .sup-adv-count {
            display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff; font-size: 11px; font-weight: 600;
        }

        .sup-searchbox { display: flex; border-radius: 4px; }
        .sup-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .sup-search-input {
            height: 34px; width: 300px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .sup-search-input::placeholder { color: #bfbfbf; }
        .sup-searchbox:focus-within .sup-search-input,
        .sup-searchbox:focus-within .sup-search-btn { border-color: #86b7fe; }
        .sup-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .sup-search-btn:hover { color: #1890ff; }

        .sup-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .sup-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        .sup-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .sup-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Nút */
        .sup-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .sup-btn-primary:hover { background: #40a9ff; }
        .sup-btn-primary svg { flex-shrink: 0; }
        .sup-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px;
            font-weight: 500; color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .sup-btn-ghost:hover { border-color: #bfbfbf; }

        /* Dropdown Tiện ích */
        .sup-util { position: relative; }
        .sup-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .sup-util-btn:hover, .sup-util.open .sup-util-btn { border-color: #1890ff; color: #1890ff; }
        .sup-util-caret { transition: transform .2s; }
        .sup-util.open .sup-util-caret { transform: rotate(180deg); }
        .sup-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 210px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .sup-util.open .sup-util-menu { display: block; }
        .sup-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .sup-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .sup-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .sup-util-item:hover svg { color: #1890ff; }

        /* Bảng — ô rộng dòng cao như trang Tồn kho / Đặt hàng nhập; th và td của cùng
           một cột khai CÙNG text-align để tiêu đề luôn thẳng cột với dữ liệu. */
        .sup-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .sup-table-wrap::-webkit-scrollbar { height: 11px; }
        .sup-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        /* Bảng — cùng khuôn với mọi trang danh sách: mọi ô canh giữa, bề rộng khai
           theo % và cộng đúng 100%. Cột không khai width sẽ nuốt hết phần dư. */
        .sup-table { width: 100%; min-width: 1000px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .sup-table thead tr { background: #f0f0f0; color: #262626; }
        .sup-table thead th {
            text-align: center; padding: 14px 10px; font-size: 13px; font-weight: 700;
            color: #262626; white-space: nowrap;
        }
        .sup-table tbody td {
            padding: 14px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle;
            text-align: center; white-space: nowrap; line-height: 1.5;
        }
        .sup-table tbody tr:hover { background: #fafafa; }
        .sup-table tbody tr.is-selected, .sup-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .sup-table th.sup-c-check,   .sup-table td.sup-c-check   { width: 4%; }
        .sup-table th.sup-c-stt,     .sup-table td.sup-c-stt     { width: 4%; color: #8c8c8c; }
        .sup-table th.sup-c-name,    .sup-table td.sup-c-name    { width: 20%; overflow: hidden; text-overflow: ellipsis; }
        .sup-table th.sup-c-contact, .sup-table td.sup-c-contact { width: 13%; overflow: hidden; text-overflow: ellipsis; }
        .sup-table th.sup-c-phone,   .sup-table td.sup-c-phone   { width: 10%; }
        .sup-table th.sup-c-count,   .sup-table td.sup-c-count   { width: 7%; }
        .sup-table th.sup-c-amount,  .sup-table td.sup-c-amount  { width: 11%; font-variant-numeric: tabular-nums; }
        .sup-table th.sup-c-debt,    .sup-table td.sup-c-debt    { width: 11%; font-variant-numeric: tabular-nums; }
        .sup-table th.sup-c-date,    .sup-table td.sup-c-date    { width: 9%; color: #595959; }
        .sup-table th.sup-c-status,  .sup-table td.sup-c-status  { width: 7%; }
        .sup-table th.sup-c-act,     .sup-table td.sup-c-act     { width: 4%; }

        .sup-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        .sup-muted { color: #bfbfbf; }
        .sup-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; }
        .sup-sub { display: block; margin-top: 3px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .sup-code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: #595959; }
        .sup-contact { display: block; font-weight: 500; color: #262626; }
        .sup-phone { color: #262626; text-decoration: none; }
        .sup-phone:hover { color: #1890ff; text-decoration: underline; }
        .sup-debt { font-weight: 600; color: #cf1322; }

        .sup-count {
            display: inline-block; min-width: 34px; padding: 2px 8px; border-radius: 9999px;
            background: #f0f5ff; color: #1890ff; font-weight: 600; text-decoration: none;
            font-variant-numeric: tabular-nums; transition: background .15s;
        }
        .sup-count:hover { background: #d6e8ff; }

        /* Tên bấm được để sửa (giống trang Thương hiệu / Khách hàng) */
        .sup-c-name[data-edit] { cursor: pointer; }
        .sup-c-name[data-edit]:hover .sup-name { color: #1890ff; text-decoration: underline; }

        /* Công tắc hợp tác — cùng cấu trúc & màu với trang Sản phẩm / Thương hiệu */
        .sup-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .sup-switch.on { background: #7083b6; }
        .sup-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .sup-switch.on .sup-switch-knob { transform: translateX(23px); }

        .sup-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        /* Nút hành động: ô vuông bo góc CÓ VIỀN, icon xám — cùng bộ với các trang
           danh sách khác. Màu chỉ hiện lúc rê chuột, và chỉ nút xoá mới đỏ. */
        .sup-rowbtn {
            width: 30px; height: 30px; border: 1px solid #d9d9d9; background: #fff; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .15s, color .15s;
        }
        .sup-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .sup-rowbtn.sup-del:hover { border-color: #ff4d4f; color: #ff4d4f; }

        /* Dòng trống trải hết bảng nên phải cho xuống dòng, không nowrap như ô dữ liệu. */
        .sup-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; white-space: normal; }

        .sup-btn-primary:focus-visible, .sup-btn-ghost:focus-visible,
        .sup-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Thanh thao tác hàng loạt (pill nổi — đồng bộ trang Thương hiệu / Sản phẩm) */
        .sup-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 14px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .sup-bulk { left: 48px; }
        @media (max-width: 820px) { .sup-bulk { left: 0; } }
        .sup-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .sup-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .sup-bulk-clear:hover { color: #262626; }
        .sup-bulk-btn {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; border: 1px solid #d9d9d9;
            border-radius: 9999px; background: #fff; padding: 0 14px; font-size: 13px; font-weight: 500;
            color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .sup-bulk-btn:hover { border-color: #1890ff; color: #1890ff; }
        .sup-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; border: 0; border-radius: 9999px;
            background: #ff4d4f; padding: 0 16px; font-size: 13px; font-weight: 500; color: #fff;
            cursor: pointer; transition: background .15s;
        }
        .sup-bulk-del:hover { background: #ff7875; }

        /* ---- Modal ---- */
        .sup-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .sup-dialog {
            max-height: 92vh; width: 100%; max-width: 720px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2); scrollbar-width: thin;
        }
        .sup-dialog::-webkit-scrollbar { width: 11px; }
        .sup-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .sup-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .sup-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .sup-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .sup-modal-x:hover { color: #262626; }
        .sup-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .sup-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }

        .sup-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .sup-col-2 { grid-column: span 2; }
        .sup-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .sup-req { color: #ff4d4f; }
        .sup-input, .sup-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            outline: none; transition: border-color .15s; font-family: inherit; color: #262626; background: #fff;
        }
        .sup-input { height: 36px; }
        .sup-input::placeholder { color: #bfbfbf; }
        .sup-input:focus, .sup-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .sup-msel {
            height: 36px; cursor: pointer; padding-right: 32px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .sup-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; }
        .sup-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px; background: #f5f9ff;
            font-size: 12px; line-height: 1.55; color: #595959;
        }
        .sup-note a { color: #1890ff; }

        @media (max-width: 560px) {
            .sup-grid2 { grid-template-columns: 1fr; }
            .sup-col-2 { grid-column: span 1; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());
            const URL_BASE = @json(url('admin/suppliers'));
            const URL_STORE = @json(route('admin.suppliers.store'));
            const URL_BULK_DEL = @json(route('admin.suppliers.bulkDestroy'));
            const URL_BULK_STATUS = @json(route('admin.suppliers.bulkStatus'));
            const URL_PURCHASES = @json(route('admin.purchases.index'));
            const RETURN_URL = @json(request()->getRequestUri());
            const SUPPLIERS = @json($suppliers);
            const BY_ID = new Map(SUPPLIERS.map((s) => [s.id, s]));

            const $filter = document.getElementById('supFilter');
            const $bulkMount = document.getElementById('supBulkMount');

            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));
            const money = (v) => (Number(v) || 0).toLocaleString('vi-VN') + '₫';

            // ---------- Bộ lọc: đổi select -> chạy ngay; gõ tìm kiếm -> chờ 400ms ----------
            // Nút "Nâng cao": ẩn/hiện hàng bộ lọc phụ, nhớ lựa chọn qua localStorage.
            (function () {
                const btn = document.getElementById('supAdvToggle');
                const row = document.getElementById('supAdvRow');
                if (!btn || !row) return;
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('sup-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('sup-adv-open', open ? '1' : '0');
                });
            })();

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

            function toggleStatus(btn) {
                postForm(`${URL_BASE}/${btn.getAttribute('data-toggle')}/toggle-status`, 'PUT', {
                    is_active: btn.dataset.on === '1' ? 0 : 1,
                });
            }

            // Còn phiếu đặt hàng thì API chặn xoá — nói ngay tại chỗ, kèm lối đi đúng.
            function removeSupplier(s) {
                const used = Number(s.purchase_count) || 0;
                if (used > 0) {
                    toastErr(`Không xoá được "${s.name}": còn ${used} phiếu đặt hàng gắn với nhà cung cấp này. `
                        + 'Hãy chuyển sang "Ngừng hợp tác" thay vì xoá, để các phiếu cũ vẫn giữ được đầu mối liên hệ.');
                    return;
                }

                sysDelete({
                    title: 'Xác nhận xoá nhà cung cấp',
                    message: `Bạn có chắc chắn muốn xoá nhà cung cấp "${s.name}"? Hành động này không thể hoàn tác.`,
                    highlightText: `${s.code || ''} — ${s.name || ''}`,
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${s.id}`, 'DELETE', {}); });
            }

            // ---------- Modal thêm / sửa ----------
            const $overlay = document.getElementById('supFormOverlay');
            const form = document.getElementById('supForm');
            const usedNote = document.getElementById('supUsedNote');
            const fields = {
                name: document.getElementById('supName'),
                code: document.getElementById('supCode'),
                contact_name: document.getElementById('supContact'),
                phone: document.getElementById('supPhone'),
                email: document.getElementById('supEmail'),
                tax_code: document.getElementById('supTax'),
                address: document.getElementById('supAddress'),
                note: document.getElementById('supNote'),
                is_active: document.getElementById('supStatus'),
            };

            const closeOverlay = () => { $overlay.style.display = 'none'; };
            $overlay.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeOverlay));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlay(); });

            function openForm(mode, s) {
                const isEdit = mode === 'edit';
                form.action = isEdit ? `${URL_BASE}/${s.id}` : URL_STORE;
                document.getElementById('supFormMethod').value = isEdit ? 'PUT' : 'POST';
                document.getElementById('supFormTitle').textContent = isEdit ? 'Sửa nhà cung cấp' : 'Thêm nhà cung cấp';
                document.getElementById('supFormSubmit').textContent = isEdit ? 'Cập nhật' : 'Lưu';

                const d = isEdit ? s : { is_active: true };
                for (const [key, el] of Object.entries(fields)) {
                    if (key === 'is_active') { el.value = d.is_active ? '1' : '0'; continue; }
                    el.value = d[key] || '';
                }

                const used = isEdit ? (Number(s.purchase_count) || 0) : 0;
                usedNote.style.display = used > 0 ? '' : 'none';
                if (used > 0) {
                    usedNote.innerHTML = `Nhà cung cấp này đang có <b>${used}</b> phiếu đặt hàng`
                        + ` (đã đặt <b>${money(s.purchase_amount)}</b>, còn nợ <b>${money(s.debt_amount)}</b>) — `
                        + `<a href="${URL_PURCHASES}?supplier_id=${s.id}">xem các phiếu</a>. `
                        + 'Vì còn phiếu nên không xoá được: muốn dừng nhập hàng thì chọn <b>Ngừng hợp tác</b>, '
                        + 'phiếu cũ vẫn giữ nguyên đầu mối liên hệ.';
                }

                $overlay.style.display = 'flex';
                setTimeout(() => fields.name.focus(), 30);
            }

            document.getElementById('supAddBtn').addEventListener('click', () => openForm('add', null));

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
                const t = new bootstrap.Toast(el, { delay: 6000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.sup-table tbody');
            tbody.addEventListener('click', (e) => {
                // Số phiếu / số điện thoại là link — để trình duyệt xử lý.
                if (e.target.closest('a')) return;

                const tg = e.target.closest('[data-toggle]');
                if (tg) { toggleStatus(tg); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const s = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (s) removeSupplier(s); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { const s = BY_ID.get(Number(ed.getAttribute('data-edit'))); if (s) openForm('edit', s); return; }
            });

            // ---------- Chọn dòng + thanh thao tác hàng loạt ----------
            const selected = new Set();
            const checkAll = document.getElementById('supCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.sup-row-check'));

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

                const ids = [...selected];
                // Bên còn phiếu sẽ bị bỏ qua khi xoá — nói trước con số cho rõ.
                const blocked = ids.filter((id) => (Number(BY_ID.get(id)?.purchase_count) || 0) > 0).length;
                const activeCount = ids.filter((id) => BY_ID.get(id)?.is_active).length;

                $bulkMount.innerHTML = `
                    <div class="sup-bulk">
                        <span class="sup-bulk-text">Đã chọn <b>${n}</b> nhà cung cấp${blocked ? ` · ${blocked} bên còn phiếu nên không xoá được` : ''}</span>
                        <button type="button" class="sup-bulk-clear" id="supBulkClear">Bỏ chọn</button>
                        ${activeCount > 0 ? `
                        <button type="button" class="sup-bulk-btn" id="supBulkOff">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 12h6"/></svg>
                            Ngừng hợp tác (${activeCount})
                        </button>` : `
                        <button type="button" class="sup-bulk-btn" id="supBulkOn">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.2 2.2 4.8-5"/></svg>
                            Bật lại hợp tác (${n})
                        </button>`}
                        <button type="button" class="sup-bulk-del" id="supBulkDel">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Xoá (${n - blocked})
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
                if (e.target.closest('#supBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                    return;
                }

                if (e.target.closest('#supBulkOff') || e.target.closest('#supBulkOn')) {
                    const on = !!e.target.closest('#supBulkOn');
                    const ids = [...selected];
                    sysConfirm({
                        title: on ? 'Bật lại hợp tác' : 'Ngừng hợp tác',
                        message: on
                            ? `Bật lại hợp tác với ${ids.length} nhà cung cấp đã chọn? Các bên này sẽ hiện lại trong ô chọn khi lập phiếu đặt hàng.`
                            : `Chuyển ${ids.length} nhà cung cấp đã chọn sang "Ngừng hợp tác"? Các bên này sẽ không còn hiện trong ô chọn khi lập phiếu đặt hàng — phiếu cũ giữ nguyên.`,
                        confirmText: on ? 'Bật lại' : 'Ngừng hợp tác',
                    }).then((ok) => {
                        if (ok) postForm(URL_BULK_STATUS, 'POST', { is_active: on ? 1 : 0, ...idFields(ids) });
                    });
                    return;
                }

                if (e.target.closest('#supBulkDel')) {
                    const ids = [...selected];
                    const deletable = ids.filter((id) => (Number(BY_ID.get(id)?.purchase_count) || 0) === 0);
                    if (!deletable.length) {
                        toastErr('Tất cả nhà cung cấp đã chọn đều còn phiếu đặt hàng nên không xoá được. '
                            + 'Dùng "Ngừng hợp tác" nếu muốn dừng nhập hàng từ các bên này.');
                        return;
                    }
                    const skipped = ids.length - deletable.length;
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${deletable.length} nhà cung cấp đã chọn?`
                            + (skipped ? ` ${skipped} bên còn phiếu đặt hàng sẽ được giữ lại.` : '')
                            + ' Hành động này không thể hoàn tác.',
                        highlightText: `Số lượng: ${deletable.length} nhà cung cấp`,
                    }).then((ok) => { if (ok) postForm(URL_BULK_DEL, 'POST', idFields(deletable)); });
                }
            });

            // ---------- Dropdown Tiện ích ----------
            const util = document.getElementById('supUtil');
            document.getElementById('supUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const open = !util.classList.contains('open');
                util.classList.toggle('open', open);
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', () => {
                util.classList.remove('open');
                document.getElementById('supUtilBtn').setAttribute('aria-expanded', 'false');
            });
        })();
    </script>
@endsection
