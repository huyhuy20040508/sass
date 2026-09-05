@extends('layouts.app')

@section('title', \App\Http\Controllers\VoucherController::TITLE)

@section('content')
    {{--
        Trang "Voucher" — dựng theo đúng khuôn trang Khuyến mãi / Thương hiệu:
        [ header ] + [ thanh lọc realtime ] + [ bảng ] + [ chân trang ] + [ modal CRUD ].

        Voucher KHÁC chương trình khuyến mãi, và trang này nói thẳng ra để người bán
        không phải đoán:
          - Khuyến mãi giảm TỰ ĐỘNG trên TỪNG SẢN PHẨM, khách không phải làm gì.
          - Voucher là mã khách TỰ GÕ lúc thanh toán và giảm trên TỔNG ĐƠN.

        Ba con số quyết định một mã còn dùng được hay không, nên cả ba đều nằm ngay
        trên bảng: còn hạn không, còn lượt không, đang bật không.
    --}}
    @php
        $STATUSES = \App\Http\Controllers\VoucherController::STATUSES;
        $TYPES = \App\Http\Controllers\VoucherController::DISCOUNT_TYPES;
        $SORTS = \App\Http\Controllers\VoucherController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\VoucherController::PAGE_SIZES;
        $TITLE = \App\Http\Controllers\VoucherController::TITLE;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['type'] !== 'all'
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        $money = fn ($v) => number_format((float) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');

        // Mức giảm dạng chữ: "-20%" hoặc "-50.000₫".
        $discountText = function (array $v) use ($money) {
            $value = (float) ($v['discount_value'] ?? 0);
            if (($v['discount_type'] ?? '') === 'percentage') {
                return '-' . rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%';
            }
            return '-' . $money($value);
        };
    @endphp

    <div class="vch">
        {{-- Header --}}
        <div class="vch-head">
            <h1 class="vch-title">{{ $TITLE }}</h1>
            <span class="vch-sum">
                Đang phát: <b>{{ $num($stats['running'] ?? 0) }}</b>/{{ $num($stats['total'] ?? 0) }} ·
                Chờ tới ngày: <b>{{ $num($stats['scheduled'] ?? 0) }}</b> ·
                Hết lượt: <b>{{ $num($stats['used_up'] ?? 0) }}</b> ·
                Tạm dừng: <b>{{ $num($stats['paused'] ?? 0) }}</b>
                <em>(mã khách tự nhập lúc thanh toán, giảm trên tổng đơn)</em>
            </span>
        </div>

        {{-- Bộ lọc: đổi select là chạy ngay, gõ tìm kiếm thì chờ 400ms — không có nút "Áp dụng" --}}
        <form method="GET" action="{{ route('admin.vouchers.index') }}" id="vchFilter" class="vch-filter">
            <div class="vch-toolbar">
                <div class="vch-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="vch-search-input"
                           placeholder="Tìm theo mã hoặc mô tả" autocomplete="off">
                    <button type="submit" class="vch-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="vch-select" title="Lọc theo trạng thái">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ $num($stats['total'] ?? 0) }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ $num($stats[$value] ?? 0) }})
                        </option>
                    @endforeach
                </select>

                <select name="type" class="vch-select" title="Lọc theo kiểu giảm">
                    <option value="all" {{ $filters['type'] === 'all' ? 'selected' : '' }}>Mọi kiểu giảm</option>
                    @foreach($TYPES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['type'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @include('partials.date-range', [
                    'formId' => 'vchFilter',
                    'from' => $filters['from_date'],
                    'to' => $filters['to_date'],
                    'title' => 'Lọc theo khoảng thời gian hiệu lực',
                ])

                <select name="sort" class="vch-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.vouchers.index') }}" class="vch-clear">Xoá lọc</a>
                @endif

                <div class="vch-toolbar-actions">
                    <button type="button" class="vch-btn-primary" id="vchAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Tạo voucher
                    </button>

                    <div class="vch-util" id="vchUtil">
                        <button type="button" class="vch-util-btn" id="vchUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="vch-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="vch-util-menu">
                            <a href="{{ route('admin.vouchers.export', request()->query()) }}" class="vch-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Giữ số dòng/trang khi đổi bộ lọc --}}
            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng --}}
        <div class="vch-table-wrap">
            <table class="vch-table">
                <thead>
                    <tr>
                        <th class="vch-c-check"><input type="checkbox" id="vchCheckAll" class="vch-check" title="Chọn tất cả trong trang"></th>
                        <th class="vch-c-stt">STT</th>
                        <th class="vch-c-code">Mã voucher</th>
                        <th class="vch-c-off">Mức giảm</th>
                        <th class="vch-c-used">Lượt dùng</th>
                        <th class="vch-c-time">Hiệu lực</th>
                        <th class="vch-c-state">Trạng thái</th>
                        <th class="vch-c-switch">Bật</th>
                        <th class="vch-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($vouchers as $i => $v)
                        @php
                            $id = (int) ($v['id'] ?? 0);
                            $code = (string) ($v['code'] ?? '');
                            $state = (string) ($v['status'] ?? 'ended');
                            $isOn = (bool) ($v['is_active'] ?? false);
                            $start = !empty($v['start_at']) ? \Illuminate\Support\Carbon::parse($v['start_at']) : null;
                            $end = !empty($v['end_at']) ? \Illuminate\Support\Carbon::parse($v['end_at']) : null;
                            $used = (int) ($v['used_count'] ?? 0);
                            $limit = $v['usage_limit'] ?? null;
                            $perUser = $v['usage_limit_per_user'] ?? null;
                            $minOrder = (float) ($v['min_order_amount'] ?? 0);

                            // Dòng phụ của cột "Lượt dùng": còn bao nhiêu lượt + trần mỗi khách.
                            $usedParts = [];
                            $usedParts[] = $limit === null
                                ? 'Không giới hạn'
                                : 'Còn ' . $num($v['remaining'] ?? 0) . ' lượt';
                            if ($perUser !== null) {
                                $usedParts[] = 'mỗi khách ' . $num($perUser);
                            }
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="vch-c-check"><input type="checkbox" class="vch-check vch-row-check" value="{{ $id }}"
                                       aria-label="Chọn voucher {{ $code !== '' ? $code : $id }}"></td>
                            <td class="vch-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="vch-c-code" data-edit="{{ $id }}" title="Bấm để sửa voucher">
                                <span class="vch-codeline">
                                    <span class="vch-code">{{ $code !== '' ? $code : '—' }}</span>
                                    @if($v['is_public'] ?? false)
                                        {{-- Chỉ gắn nhãn cho mã CÔNG KHAI: đó là ngoại lệ đáng
                                             chú ý (khách nào cũng thấy), còn gửi tay là mặc định
                                             nên gắn nhãn cho cả bảng chỉ tổ ồn. --}}
                                        <span class="vch-public" title="Mã này hiện sẵn ở ô nhập mã cho mọi khách">Công khai</span>
                                    @endif
                                </span>
                                <span class="vch-sub">
                                    {{ $TYPES[$v['discount_type'] ?? ''] ?? '' }}
                                    @if(!empty($v['max_discount_amount'])) · tối đa {{ $money($v['max_discount_amount']) }} @endif
                                    @if(!empty($v['description'])) · {{ \Illuminate\Support\Str::limit($v['description'], 50) }} @endif
                                </span>
                            </td>
                            <td class="vch-c-off">
                                <span class="vch-off">{{ $discountText($v) }}</span>
                                {{-- Đơn tối thiểu là điều kiện dùng mã, thuộc về mức giảm chứ
                                     không phải một cột riêng: không đạt ngưỡng thì mã không giảm. --}}
                                <span class="vch-sub">{{ $minOrder > 0 ? 'đơn từ ' . $money($minOrder) : 'mọi đơn' }}</span>
                            </td>
                            <td class="vch-c-used">
                                <span class="vch-used">{{ $num($used) }}{{ $limit === null ? '' : '/' . $num($limit) }} lượt</span>
                                <span class="vch-sub">{{ implode(' · ', $usedParts) }}</span>
                            </td>
                            <td class="vch-c-time">
                                {{-- Mốc để trống = không giới hạn phía đó. Ghi thẳng chữ ra
                                     thay vì gạch ngang: gạch ngang đọc thành "chưa nhập". --}}
                                <span class="vch-time">{{ $start?->format('d/m/Y H:i') ?? 'Không giới hạn' }}</span>
                                <span class="vch-sub">đến {{ $end?->format('d/m/Y H:i') ?? 'không giới hạn' }}</span>
                            </td>
                            <td class="vch-c-state"><span class="vch-badge vch-badge-{{ $state }}">{{ $STATUSES[$state] ?? $state }}</span></td>
                            <td class="vch-c-switch">
                                <button type="button" class="vch-switch {{ $isOn ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $isOn ? 1 : 0 }}" data-state="{{ $state }}"
                                        title="{{ $isOn ? 'Đang bật — bấm để tạm dừng' : 'Đang tắt — bấm để bật lại' }}">
                                    <span class="vch-switch-knob"></span>
                                </button>
                            </td>
                            <td class="vch-c-act">
                                <div class="vch-rowacts">
                                    {{-- Chép mã: việc làm nhiều nhất với một voucher là gửi mã đó
                                         cho khách, không phải sửa nó. --}}
                                    <button type="button" class="vch-rowbtn vch-copy" data-copy="{{ $code }}" title="Chép mã">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>
                                    <button type="button" class="vch-rowbtn vch-edit" data-edit="{{ $id }}" title="Sửa voucher">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="vch-rowbtn vch-del" data-remove="{{ $id }}" title="Xoá voucher">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="vch-empty">
                                @if($hasFilter)
                                    Không tìm thấy voucher nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    Chưa có voucher nào. Bấm “Tạo voucher” để phát mã đầu tiên — đặt mã, mức giảm,
                                    số lượt phát ra rồi gửi mã cho khách; khách nhập mã ở bước thanh toán để được giảm trên tổng đơn.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'voucher',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    <div id="vchBulkMount"></div>

    {{-- Modal Tạo / Sửa voucher --}}
    <div class="vch-overlay" id="vchFormOverlay" style="display:none;">
        <div class="vch-dialog">
            <div class="vch-modal-head">
                <h4 class="vch-modal-title" id="vchFormTitle">Tạo voucher</h4>
                <button type="button" class="vch-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="vchForm" method="POST" action="{{ route('admin.vouchers.store') }}">
                @csrf
                <input type="hidden" name="_method" id="vchFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="vch-modal-body">
                    <div class="vch-grid2">
                        <div>
                            <label class="vch-field-label" for="vchCode">Mã voucher <span class="vch-req">*</span></label>
                            <div class="vch-code-row">
                                <input type="text" id="vchCode" name="code" class="vch-input vch-input-code" maxlength="50" required
                                       autocomplete="off" placeholder="VD: SALE10" spellcheck="false">
                                <button type="button" class="vch-btn-ghost vch-gen" id="vchGenBtn" title="Sinh mã ngẫu nhiên 8 ký tự">
                                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                    Sinh mã
                                </button>
                            </div>
                            <p class="vch-hint">Khách phải gõ tay mã này, nên chỉ nhận chữ không dấu, số, gạch ngang và gạch dưới. Tự chuyển thành CHỮ HOA.</p>
                        </div>

                        <div>
                            <label class="vch-field-label" for="vchDesc">Mô tả</label>
                            <input type="text" id="vchDesc" name="description" class="vch-input" maxlength="255"
                                   autocomplete="off" placeholder="Ghi chú nội bộ, VD: mã tặng khách mới">
                        </div>

                        <div>
                            <label class="vch-field-label" for="vchType">Kiểu giảm <span class="vch-req">*</span></label>
                            <select id="vchType" name="discount_type" class="vch-msel" required>
                                @foreach($TYPES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="vch-field-label" for="vchValue">
                                Mức giảm <span class="vch-req">*</span>
                                <span class="vch-unit" id="vchValueUnit">(%)</span>
                            </label>
                            <input type="text" id="vchValue" name="discount_value" class="vch-input" required
                                   inputmode="numeric" autocomplete="off" placeholder="10">
                            <p class="vch-hint" id="vchValueHint">Giảm bao nhiêu phần trăm trên tổng tiền hàng của đơn.</p>
                        </div>

                        {{-- Trần giảm chỉ có nghĩa khi giảm theo %: ẩn hẳn ở kiểu giảm số tiền
                             thay vì để đó rồi có người tưởng nó đang có tác dụng. --}}
                        <div id="vchMaxWrap">
                            <label class="vch-field-label" for="vchMax">Trần giảm <span class="vch-unit">(₫)</span></label>
                            <input type="text" id="vchMax" name="max_discount_amount" class="vch-input"
                                   inputmode="numeric" autocomplete="off" placeholder="Bỏ trống = không giới hạn">
                            <p class="vch-hint">VD: giảm 20% nhưng tối đa 100.000₫ một đơn.</p>
                        </div>
                        <div>
                            <label class="vch-field-label" for="vchMinOrder">Đơn tối thiểu <span class="vch-unit">(₫)</span></label>
                            <input type="text" id="vchMinOrder" name="min_order_amount" class="vch-input"
                                   inputmode="numeric" autocomplete="off" placeholder="Bỏ trống = áp dụng mọi đơn">
                            <p class="vch-hint">Đơn chưa đạt mức này thì nhập mã sẽ không được giảm.</p>
                        </div>

                        <div>
                            <label class="vch-field-label" for="vchLimit">Tổng lượt dùng</label>
                            <input type="text" id="vchLimit" name="usage_limit" class="vch-input"
                                   inputmode="numeric" autocomplete="off" placeholder="Bỏ trống = không giới hạn">
                            <p class="vch-hint" id="vchLimitHint">Phát hết số lượt này là mã tự ngừng, không cần tắt tay.</p>
                        </div>
                        <div>
                            <label class="vch-field-label" for="vchPerUser">Lượt mỗi khách</label>
                            <input type="text" id="vchPerUser" name="usage_limit_per_user" class="vch-input"
                                   inputmode="numeric" autocomplete="off" placeholder="Bỏ trống = không giới hạn">
                            <p class="vch-hint">Đặt 1 để mỗi khách chỉ dùng được đúng một lần.</p>
                        </div>

                        <div>
                            <label class="vch-field-label" for="vchStart">Bắt đầu</label>
                            <input type="datetime-local" id="vchStart" name="start_at" class="vch-input">
                            <p class="vch-hint">Bỏ trống = dùng được ngay.</p>
                        </div>
                        <div>
                            <label class="vch-field-label" for="vchEnd">Kết thúc</label>
                            <input type="datetime-local" id="vchEnd" name="end_at" class="vch-input">
                            <p class="vch-hint">Bỏ trống = dùng mãi tới khi tạm dừng.</p>
                        </div>

                        <div>
                            <label class="vch-field-label" for="vchActive">Trạng thái <span class="vch-req">*</span></label>
                            <select id="vchActive" name="is_active" class="vch-msel" required>
                                <option value="1">Bật</option>
                                <option value="0">Tạm dừng</option>
                            </select>
                            <p class="vch-hint">Bật rồi vẫn phải tới ngày bắt đầu (nếu có) mới dùng được.</p>
                        </div>

                        {{-- Cách phát quyết định mã có bị KHOE RA hay không. Để mặc định
                             "gửi tay" là cố ý: khoe nhầm một mã đền bù cho cả thiên hạ thì
                             không rút lại được, còn quên bật công khai chỉ là ít người dùng. --}}
                        <div>
                            <label class="vch-field-label" for="vchPublic">Cách phát mã <span class="vch-req">*</span></label>
                            <select id="vchPublic" name="is_public" class="vch-msel" required>
                                <option value="0">Gửi tay — chỉ ai biết mã mới dùng được</option>
                                <option value="1">Công khai — hiện sẵn cho mọi khách</option>
                            </select>
                            <p class="vch-hint" id="vchPublicHint">
                                Mã chỉ hiện khi bạn tự gửi cho khách (Facebook, tin nhắn, in trên phiếu).
                            </p>
                        </div>
                    </div>

                    {{-- ---- Chi nhánh áp dụng ----
                         Bỏ trống là HỢP LỆ và có nghĩa "dùng được ở mọi kho" — cùng
                         quy ước với ô "Chi nhánh" của mặt hàng. --}}
                    @if(count($chiNhanh) > 1)
                        <div style="margin-top: 16px">
                            <label class="vch-field-label">
                                Chi nhánh áp dụng
                                <span class="vch-hint" id="vchShopSum" style="margin-left: 6px">Mọi chi nhánh</span>
                            </label>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 6px">
                                @foreach($chiNhanh as $cn)
                                    <label style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer">
                                        <input type="checkbox" class="vch-shop" name="shop_ids[]" value="{{ $cn['id'] ?? 0 }}">
                                        <span>{{ $cn['name'] ?? '' }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="vch-hint">Để trống là mã dùng được ở <b>mọi chi nhánh</b>.</p>
                        </div>
                    @endif
                </div>

                <div class="vch-modal-foot">
                    <button type="button" class="vch-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="vch-btn-primary" id="vchFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .vch {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .vch-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .vch-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .vch-sum { font-size: 13px; color: #595959; }
        .vch-sum b { color: #262626; }
        .vch-sum em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Bộ lọc */
        .vch-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .vch-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .vch-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .vch-searchbox { display: flex; border-radius: 4px; }
        .vch-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .vch-search-input {
            height: 34px; width: 260px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .vch-search-input::placeholder { color: #bfbfbf; }
        .vch-searchbox:focus-within .vch-search-input,
        .vch-searchbox:focus-within .vch-search-btn { border-color: #86b7fe; }
        .vch-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .vch-search-btn:hover { color: #1890ff; }

        .vch-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 240px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .vch-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        .vch-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .vch-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Nút */
        .vch-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .vch-btn-primary:hover { background: #40a9ff; }
        .vch-btn-primary svg { flex-shrink: 0; }
        .vch-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px;
            font-weight: 500; color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .vch-btn-ghost:hover { border-color: #bfbfbf; }

        /* Dropdown Tiện ích */
        .vch-util { position: relative; }
        .vch-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .vch-util-btn:hover, .vch-util.open .vch-util-btn { border-color: #1890ff; color: #1890ff; }
        .vch-util-caret { transition: transform .2s; }
        .vch-util.open .vch-util-caret { transform: rotate(180deg); }
        .vch-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 210px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .vch-util.open .vch-util-menu { display: block; }
        .vch-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .vch-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .vch-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .vch-util-item:hover svg { color: #1890ff; }

        /* Bảng — th và td của cùng một cột khai CÙNG text-align để tiêu đề thẳng cột. */
        .vch-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .vch-table-wrap::-webkit-scrollbar { height: 11px; }
        .vch-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        .vch-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .vch-table thead th {
            text-align: left; padding: 13px 18px; border-bottom: 1px solid #f0f0f0; background: #fafafa;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
        }
        .vch-table tbody td {
            padding: 16px 18px; border-bottom: 1px solid #f5f5f5; vertical-align: middle;
            white-space: nowrap; line-height: 1.5;
        }
        .vch-table tbody tr:hover { background: #fafcff; }
        .vch-table tbody tr.is-selected, .vch-table tbody tr.is-selected:hover { background: #e6f7ff; }

        /* Mọi cột co vừa nội dung, riêng "Mã voucher" hút hết khoảng dư.
           min-width là thứ giữ cho bảng THOÁNG: không có nó thì cột chỉ rộng đúng
           bằng chữ bên trong, sáu cột cuối dồn hết sang phải và dính vào nhau —
           mắt phải dò xem con số nào thuộc cột nào. */
        .vch-table th.vch-c-check,  .vch-table td.vch-c-check  { width: 1%; min-width: 46px;  text-align: center; }
        .vch-table th.vch-c-stt,    .vch-table td.vch-c-stt    { width: 1%; min-width: 58px;  text-align: center; color: #8c8c8c; }
        .vch-table th.vch-c-code,   .vch-table td.vch-c-code   { width: 100%; max-width: 0; min-width: 210px; overflow: hidden; }
        .vch-table th.vch-c-off,    .vch-table td.vch-c-off    { width: 1%; min-width: 128px; text-align: right; }
        .vch-table th.vch-c-used,   .vch-table td.vch-c-used   { width: 1%; min-width: 150px; }
        .vch-table th.vch-c-time,   .vch-table td.vch-c-time   { width: 1%; min-width: 168px; }
        .vch-table th.vch-c-state,  .vch-table td.vch-c-state  { width: 1%; min-width: 122px; text-align: center; }
        .vch-table th.vch-c-switch, .vch-table td.vch-c-switch { width: 1%; min-width: 76px;  text-align: center; }
        .vch-table th.vch-c-act,    .vch-table td.vch-c-act    { width: 1%; min-width: 126px; text-align: center; }

        .vch-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        /* Mã dùng font đều nét: người bán hay phải đọc và đọc lại mã cho khách qua
           điện thoại, mà font thường thì 0 với O, 1 với l nhìn gần như nhau. */
        .vch-codeline { display: flex; align-items: center; gap: 8px; min-width: 0; }
        .vch-code {
            font-weight: 600; color: #262626; letter-spacing: .4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            min-width: 0; overflow: hidden; text-overflow: ellipsis;
        }
        /* Nhãn "Công khai" — mã này khách nào cũng thấy, khác hẳn mã gửi tay. */
        .vch-public {
            flex-shrink: 0; padding: 1px 8px; border-radius: 9999px; background: #f0f5ff;
            color: #2f54eb; border: 1px solid #adc6ff; font-size: 11px; font-weight: 500;
        }
        .vch-sub { display: block; margin-top: 3px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .vch-off { display: block; font-weight: 700; color: #cf1322; font-variant-numeric: tabular-nums; }
        .vch-used { display: block; font-weight: 500; color: #262626; font-variant-numeric: tabular-nums; }
        .vch-time { display: block; color: #262626; }

        .vch-c-code[data-edit] { cursor: pointer; }
        .vch-c-code[data-edit]:hover .vch-code { color: #1890ff; text-decoration: underline; }

        /* Năm trạng thái — màu phải khác nhau rõ, vì đây là thứ người bán liếc qua là
           phải biết mã còn dùng được hay không. "Hết lượt" tách riêng khỏi "hết hạn"
           vì cách sửa khác hẳn: một bên nâng lượt, một bên dời ngày. */
        .vch-badge {
            display: inline-block; padding: 3px 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 500; white-space: nowrap;
        }
        .vch-badge-running { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .vch-badge-scheduled { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .vch-badge-ended { background: #fafafa; color: #8c8c8c; border: 1px solid #e8e8e8; }
        .vch-badge-used_up { background: #fff1f0; color: #cf1322; border: 1px solid #ffccc7; }
        .vch-badge-paused { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }

        /* Công tắc — cùng cấu trúc & màu với trang Khuyến mãi / Sản phẩm */
        .vch-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .vch-switch.on { background: #7083b6; }
        .vch-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .vch-switch.on .vch-switch-knob { transform: translateX(23px); }

        .vch-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .vch-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .vch-rowbtn.vch-copy { color: #595959; }
        .vch-rowbtn.vch-copy:hover { background: #f5f5f5; color: #262626; }
        .vch-rowbtn.vch-edit { color: #1890ff; }
        .vch-rowbtn.vch-edit:hover { background: #e6f7ff; }
        .vch-rowbtn.vch-del { color: #ff4d4f; }
        .vch-rowbtn.vch-del:hover { background: #fff1f0; }

        .vch-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; white-space: normal; line-height: 1.7; }

        .vch-btn-primary:focus-visible, .vch-btn-ghost:focus-visible,
        .vch-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Thanh thao tác hàng loạt (pill nổi — đồng bộ các trang khác) */
        .vch-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 14px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .vch-bulk { left: 48px; }
        @media (max-width: 820px) { .vch-bulk { left: 0; } }
        .vch-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .vch-bulk-text b { color: #1890ff; }
        .vch-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .vch-bulk-clear:hover { color: #262626; }
        .vch-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; border: 0; border-radius: 9999px;
            background: #ff4d4f; padding: 0 16px; font-size: 13px; font-weight: 500; color: #fff;
            cursor: pointer; transition: background .15s;
        }
        .vch-bulk-del:hover { background: #ff7875; }

        /* ---- Modal ---- */
        .vch-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .vch-dialog {
            max-height: 92vh; width: 100%; max-width: 760px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2); scrollbar-width: thin;
        }
        .vch-dialog::-webkit-scrollbar { width: 11px; }
        .vch-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .vch-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .vch-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .vch-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .vch-modal-x:hover { color: #262626; }
        .vch-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        .vch-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }

        .vch-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .vch-col-2 { grid-column: span 2; }
        .vch-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .vch-req { color: #ff4d4f; }
        .vch-unit { font-weight: 400; color: #8c8c8c; }
        .vch-input, .vch-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            outline: none; transition: border-color .15s; font-family: inherit; color: #262626; background: #fff;
        }
        .vch-input { height: 36px; }
        .vch-input::placeholder { color: #bfbfbf; }
        .vch-input:focus, .vch-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .vch-msel {
            height: 36px; cursor: pointer; padding-right: 32px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .vch-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; line-height: 1.5; }

        /* Ô mã + nút sinh mã đứng cùng hàng, nút co vừa chữ để ô nhập vẫn rộng. */
        .vch-code-row { display: flex; gap: 8px; }
        .vch-input-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            letter-spacing: .5px; text-transform: uppercase;
        }
        .vch-input-code::placeholder { letter-spacing: normal; }
        .vch-gen { height: 36px; flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; }

        @media (max-width: 760px) {
            .vch-grid2 { grid-template-columns: 1fr; }
            .vch-col-2 { grid-column: span 1; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());
            const URL_BASE = @json(url('admin/voucher'));
            const URL_STORE = @json(route('admin.vouchers.store'));
            const URL_BULK_DEL = @json(route('admin.vouchers.bulkDestroy'));
            const RETURN_URL = @json(request()->getRequestUri());
            const VOUCHERS = @json($vouchers);
            const BY_ID = new Map(VOUCHERS.map((v) => [v.id, v]));

            const $filter = document.getElementById('vchFilter');
            const $bulkMount = document.getElementById('vchBulkMount');

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

            // Toast phía client (dùng chung hạ tầng toast của layout).
            function toast(msg, kind) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') { alert(msg); return; }
                const danger = kind !== 'ok';
                const el = document.createElement('div');
                el.className = 'toast align-items-center border-0 ' + (danger ? 'text-bg-danger' : 'text-bg-success');
                el.setAttribute('role', 'alert');
                const icon = danger ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill';
                el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi ${icon} me-2"></i>${esc(msg)}</div>`
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: danger ? 7000 : 2500 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }
            const toastErr = (msg) => toast(msg, 'err');

            function toggleStatus(btn) {
                const turningOn = btn.dataset.on !== '1';
                // Mã đã hết hạn hoặc hết lượt thì bật lên cũng không ai dùng được — nói
                // trước, và nói rõ CÁCH SỬA vì hai trường hợp sửa khác nhau.
                if (turningOn && btn.dataset.state === 'ended') {
                    toastErr('Voucher này đã qua ngày kết thúc nên bật lên cũng chưa dùng được. '
                        + 'Hãy mở phần sửa và dời ngày kết thúc sang mốc trong tương lai (hoặc bỏ trống để không giới hạn).');
                    return;
                }
                if (turningOn && btn.dataset.state === 'used_up') {
                    toastErr('Voucher này đã phát hết số lượt nên bật lên cũng chưa dùng được. '
                        + 'Hãy mở phần sửa và nâng "Tổng lượt dùng" lên (hoặc bỏ trống để không giới hạn).');
                    return;
                }
                postForm(`${URL_BASE}/${btn.getAttribute('data-toggle')}/toggle-status`, 'PUT', {
                    is_active: turningOn ? 1 : 0,
                });
            }

            function removeVoucher(v) {
                const used = Number(v.used_count) || 0;
                sysDelete({
                    title: 'Xác nhận xoá voucher',
                    message: `Bạn có chắc chắn muốn xoá mã "${v.code}"? Khách đang giữ mã này sẽ không dùng được nữa.`
                        + (used ? ` Mã đã được dùng ${used} lượt — các đơn cũ vẫn giữ nguyên mã và số tiền đã giảm.` : '')
                        + ' Hành động này không thể hoàn tác.',
                    highlightText: v.code || '',
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${v.id}`, 'DELETE', {}); });
            }

            // ---------- Ô nhập số ----------
            const digits = (s) => String(s == null ? '' : s).replace(/\D/g, '');
            const groupVN = (s) => digits(s).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            // Ô tiền: hiện dấu chấm ngăn nghìn kiểu Việt Nam.
            function bindMoney(el) {
                el.addEventListener('input', () => { el.value = groupVN(el.value); });
            }
            // Ô đếm lượt: số thuần, không ngăn nghìn — "1.000 lượt" dễ đọc nhầm thành 1 lượt.
            function bindCount(el) {
                el.addEventListener('input', () => { el.value = digits(el.value); });
            }

            // ---------- Modal tạo / sửa ----------
            const $overlay = document.getElementById('vchFormOverlay');
            const form = document.getElementById('vchForm');
            const $code = document.getElementById('vchCode');
            const $type = document.getElementById('vchType');
            const $value = document.getElementById('vchValue');
            const $max = document.getElementById('vchMax');
            const $maxWrap = document.getElementById('vchMaxWrap');
            const $minOrder = document.getElementById('vchMinOrder');
            const $limit = document.getElementById('vchLimit');
            const $limitHint = document.getElementById('vchLimitHint');
            const $perUser = document.getElementById('vchPerUser');
            const $valueUnit = document.getElementById('vchValueUnit');
            const $valueHint = document.getElementById('vchValueHint');
            const $public = document.getElementById('vchPublic');
            const $publicHint = document.getElementById('vchPublicHint');

            // Nói thẳng hệ quả của lựa chọn ngay dưới ô, vì đây là chỗ dễ chọn nhầm
            // nhất trong biểu mẫu: bật công khai một mã đền bù là ai cũng dùng được.
            function syncPublic() {
                $publicHint.textContent = $public.value === '1'
                    ? 'Mã hiện sẵn ở ô nhập mã lúc thanh toán — MỌI khách đều thấy và bấm dùng được.'
                    : 'Mã chỉ hiện khi bạn tự gửi cho khách (Facebook, tin nhắn, in trên phiếu).';
            }
            $public.addEventListener('change', syncPublic);

            bindMoney($max);
            bindMoney($minOrder);
            bindCount($limit);
            bindCount($perUser);

            // Mã luôn là chữ hoa và không dấu cách: chuẩn hoá ngay lúc gõ để cái người
            // dùng nhìn thấy đúng bằng cái sẽ lưu xuống.
            $code.addEventListener('input', () => {
                const before = $code.value;
                const after = before.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
                if (after === before) return;
                // Gán lại value là con trỏ nhảy về cuối ô. Bù đúng số ký tự vừa bị loại
                // bỏ để người đang sửa GIỮA mã không bị hất về cuối sau mỗi phím.
                const pos = $code.selectionStart;
                const next = Math.max(0, pos - (before.length - after.length));
                $code.value = after;
                $code.setSelectionRange(next, next);
            });

            // Sinh mã ngẫu nhiên: bỏ O/0 và I/1 khỏi bộ ký tự — mã sinh ra là để đọc
            // cho khách qua điện thoại, hai cặp đó nghe và nhìn đều lẫn nhau.
            document.getElementById('vchGenBtn').addEventListener('click', () => {
                const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                const buf = new Uint32Array(8);
                crypto.getRandomValues(buf);
                $code.value = Array.from(buf, (n) => chars[n % chars.length]).join('');
                $code.focus();
            });

            const closeOverlay = () => { $overlay.style.display = 'none'; };
            $overlay.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeOverlay));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlay(); });

            // Kiểu giảm quyết định đơn vị của ô "Mức giảm" và sự tồn tại của ô "Trần giảm".
            function syncType() {
                const percent = $type.value === 'percentage';
                $maxWrap.style.display = percent ? '' : 'none';
                $valueUnit.textContent = percent ? '(%)' : '(₫)';
                $value.placeholder = percent ? '10' : '50.000';
                $valueHint.textContent = percent
                    ? 'Giảm bao nhiêu phần trăm trên tổng tiền hàng của đơn.'
                    : 'Giảm thẳng bao nhiêu tiền trên tổng đơn.';
                // Đổi kiểu thì định dạng số cũng phải đổi theo, không thì "20" thành "20.000".
                $value.value = percent ? digits($value.value) : groupVN($value.value);
            }
            $type.addEventListener('change', syncType);
            $value.addEventListener('input', () => {
                $value.value = $type.value === 'percentage' ? digits($value.value) : groupVN($value.value);
            });

            function openForm(mode, v) {
                const isEdit = mode === 'edit';
                form.action = isEdit ? `${URL_BASE}/${v.id}` : URL_STORE;
                document.getElementById('vchFormMethod').value = isEdit ? 'PUT' : 'POST';
                document.getElementById('vchFormTitle').textContent = isEdit ? 'Sửa voucher' : 'Tạo voucher';
                document.getElementById('vchFormSubmit').textContent = isEdit ? 'Cập nhật' : 'Lưu';

                $code.value = isEdit ? (v.code || '') : '';
                document.getElementById('vchDesc').value = isEdit ? (v.description || '') : '';
                $type.value = isEdit ? (v.discount_type || 'percentage') : 'percentage';
                document.getElementById('vchActive').value = isEdit ? (v.is_active ? '1' : '0') : '1';
                $public.value = isEdit ? (v.is_public ? '1' : '0') : '0';
                syncPublic();

                const shops = new Set((isEdit ? v.shop_ids : []) || []);
                oShop().forEach((cb) => { cb.checked = shops.has(Number(cb.value)); });
                syncShop();

                // Hai mốc thời gian được phép bỏ trống, nên KHÔNG đổ sẵn giá trị mặc định
                // cho voucher mới: mã dùng được ngay và không hết hạn là lựa chọn đúng
                // trong đa số trường hợp, còn đổ sẵn là bắt người dùng đi xoá.
                document.getElementById('vchStart').value = isEdit ? (v.start_at || '') : '';
                document.getElementById('vchEnd').value = isEdit ? (v.end_at || '') : '';

                const int = (n) => (n === null || n === undefined || n === '' ? '' : String(Math.round(Number(n))));
                $value.value = isEdit ? int(v.discount_value) : '';
                $max.value = isEdit && v.max_discount_amount ? groupVN(int(v.max_discount_amount)) : '';
                $minOrder.value = isEdit && Number(v.min_order_amount) > 0 ? groupVN(int(v.min_order_amount)) : '';
                $limit.value = isEdit ? int(v.usage_limit) : '';
                $perUser.value = isEdit ? int(v.usage_limit_per_user) : '';

                // Nhắc ngưỡng dưới của tổng lượt ngay tại ô: hạ xuống dưới số đã phát là
                // API chặn, biết trước thì đỡ phải gửi lên rồi bị trả về.
                const used = isEdit ? (Number(v.used_count) || 0) : 0;
                $limitHint.textContent = used > 0
                    ? `Mã đã phát ${used} lượt, nên tổng lượt không đặt được dưới ${used}. Bỏ trống = không giới hạn.`
                    : 'Phát hết số lượt này là mã tự ngừng, không cần tắt tay.';

                syncType();
                $overlay.style.display = 'flex';
                setTimeout(() => $code.focus(), 30);
            }

            // Rỗng thì nói thẳng "Mọi chi nhánh": ở ô này "chưa chọn gì" nghĩa là
            // dùng được khắp nơi, ngược hẳn với cảm giác thường gặp.
            const oShop = () => Array.from(form.querySelectorAll('.vch-shop'));

            function syncShop() {
                const n = oShop().filter((cb) => cb.checked).length;
                const $sum = document.getElementById('vchShopSum');
                if ($sum) {
                    $sum.textContent = n === 0 ? 'Mọi chi nhánh' : n + ' chi nhánh';
                }
            }

            form.addEventListener('change', (e) => {
                if (e.target.classList && e.target.classList.contains('vch-shop')) {
                    syncShop();
                }
            });

            document.getElementById('vchAddBtn').addEventListener('click', () => openForm('add', null));

            form.addEventListener('submit', (e) => {
                if ($type.value === 'percentage' && Number(digits($value.value)) > 100) {
                    e.preventDefault();
                    toastErr('Phần trăm giảm phải trong khoảng 1–100.');
                    return;
                }
                if ($code.value.trim().length < 3) {
                    e.preventDefault();
                    toastErr('Mã voucher phải dài ít nhất 3 ký tự.');
                    return;
                }
                // Bỏ dấu chấm ngăn nghìn trước khi gửi: server nhận số thuần.
                $value.value = digits($value.value);
                $max.value = digits($max.value);
                $minOrder.value = digits($minOrder.value);
            });

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.vch-table tbody');
            tbody.addEventListener('click', (e) => {
                if (e.target.closest('a')) return;

                const cp = e.target.closest('[data-copy]');
                if (cp) { copyCode(cp.getAttribute('data-copy')); return; }
                const tg = e.target.closest('[data-toggle]');
                if (tg) { toggleStatus(tg); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const v = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (v) removeVoucher(v); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { const v = BY_ID.get(Number(ed.getAttribute('data-edit'))); if (v) openForm('edit', v); return; }
            });

            function copyCode(code) {
                if (!code) return;
                // navigator.clipboard chỉ có ở ngữ cảnh bảo mật (https / localhost). Trang
                // quản trị chạy http trong mạng nội bộ là chuyện thường, nên phải có
                // đường lui bằng textarea + execCommand.
                const done = () => toast(`Đã chép mã ${code}`, 'ok');
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(code).then(done).catch(() => fallbackCopy(code, done));
                    return;
                }
                fallbackCopy(code, done);
            }

            function fallbackCopy(code, done) {
                const ta = document.createElement('textarea');
                ta.value = code;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                let ok = false;
                try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                ta.remove();
                ok ? done() : toastErr('Trình duyệt không cho chép tự động. Bạn hãy bôi đen mã rồi chép tay.');
            }

            // ---------- Chọn dòng + thanh thao tác hàng loạt ----------
            const selected = new Set();
            const checkAll = document.getElementById('vchCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.vch-row-check'));

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
                const running = ids.filter((id) => BY_ID.get(id)?.status === 'running').length;

                $bulkMount.innerHTML = `
                    <div class="vch-bulk">
                        <span class="vch-bulk-text">Đã chọn <b>${n}</b> voucher${running ? ` · ${running} đang phát` : ''}</span>
                        <button type="button" class="vch-bulk-clear" id="vchBulkClear">Bỏ chọn</button>
                        <button type="button" class="vch-bulk-del" id="vchBulkDel">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
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

            function idFields(ids) {
                const out = {};
                ids.forEach((id, i) => { out[`ids[${i}]`] = id; });
                return out;
            }

            $bulkMount.addEventListener('click', (e) => {
                if (e.target.closest('#vchBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                    return;
                }

                if (e.target.closest('#vchBulkDel')) {
                    const ids = [...selected];
                    const running = ids.filter((id) => BY_ID.get(id)?.status === 'running').length;
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${ids.length} voucher đã chọn?`
                            + (running ? ` ${running} mã đang phát — khách đang giữ các mã đó sẽ không dùng được nữa.` : '')
                            + ' Hành động này không thể hoàn tác.',
                        highlightText: `Số lượng: ${ids.length} voucher`,
                    }).then((ok) => { if (ok) postForm(URL_BULK_DEL, 'POST', idFields(ids)); });
                }
            });

            // ---------- Dropdown Tiện ích ----------
            const util = document.getElementById('vchUtil');
            document.getElementById('vchUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const open = !util.classList.contains('open');
                util.classList.toggle('open', open);
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', () => {
                util.classList.remove('open');
                document.getElementById('vchUtilBtn').setAttribute('aria-expanded', 'false');
            });
        })();
    </script>
@endsection
