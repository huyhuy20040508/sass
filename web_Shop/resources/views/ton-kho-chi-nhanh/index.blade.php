@extends('layouts.app')

@section('title', \App\Http\Controllers\TonKhoChiNhanhController::TITLE_PAGE)

@section('content')
    {{--
    Trang "Tồn kho chi nhánh" — dựng theo màn "Báo cáo tồn kho hiện tại" của bản
    cũ v2: [ header + số liệu ] + [ thanh lọc ] + [ bảng gom nhóm theo chi nhánh ].

    Đơn vị của bảng là MỘT BIẾN THỂ TẠI MỘT CHI NHÁNH, nên cùng một mặt hàng xuất
    hiện nhiều lần — mỗi kho một dòng. Đó là khác biệt duy nhất so với trang Tồn
    kho, và cũng là toàn bộ lý do màn này tồn tại.

    Ba chỗ cố ý làm KHÁC bản cũ:
      · Bản cũ nạp thẳng mọi dòng của mọi chi nhánh trong một lượt (->get(), không
        phân trang). Ở đây có phân trang, nên số ở đầu mỗi nhóm lấy từ tổng của
        TOÀN bộ lọc chứ không đếm dòng đang hiện — đếm theo trang thì con số tụt
        xuống khi lật trang và người đọc hiểu là kho vừa mất hàng.
      · Bản cũ có cột số lô và hạn dùng; bên mình chưa quản kho theo lô nên bỏ hẳn
        hai cột đó thay vì để hai cột trống.
      · Bản cũ cộng tổng chỉ những dòng dương trong khi bảng vẫn liệt kê dòng âm,
        nên tổng không khớp cột. Ở đây tổng cộng đúng những gì bảng đang hiện.
    --}}
    @php
        $C = \App\Http\Controllers\TonKhoChiNhanhController::class;
        $STOCK_STATES = $C::STOCK_STATES;
        // Hai bảng nhãn cho hộp thoại sổ kho (phần JS bê nguyên từ trang Tồn kho cũ).
        $TX_TYPES = $C::TX_TYPES;
        $TX_SOURCES = $C::TX_SOURCES;
        $STOCK_TONES = $C::STOCK_TONES;
        $SORTS = $C::SORTS;
        $PAGE_SIZES = $C::PAGE_SIZES;
        $TITLE = $C::TITLE_PAGE;
        $EMPTY_TEXT = $C::EMPTY_TEXT;

        $low = (int) $filters['low_stock'];
        $firstRank = ($meta['page'] - 1) * $meta['page_size'];

        // Số liệu ở header cộng từ tổng của TỪNG chi nhánh (API tính trên toàn bộ
        // lọc), không cộng từ các dòng đang hiện — trang 2 mà số tổng đổi thì nó
        // không còn là số tổng nữa.
        $tongDong = collect($groups)->sum('so_dong');
        $tongTon = collect($groups)->sum('tong_ton');
        $tongGiaTri = collect($groups)->sum('gia_tri');

        // Tra nhanh tổng của một chi nhánh khi dựng dòng tiêu đề nhóm.
        $tomTat = collect($groups)->keyBy('shop_id');

        $soChiNhanh = count($filters['shops']) ?: count($groups);

        // Bộ lọc phụ đang bật -> tự mở hàng "Nâng cao" + hiện badge đếm.
        $advCount = ($filters['category_id'] > 0 ? 1 : 0)
            + ($filters['sort'] !== 'stock_asc' ? 1 : 0);
        $advOpen = $advCount > 0;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    @endphp
    <div class="tkc">
        {{-- Header: tên trang + số liệu của đúng những chi nhánh đang xem --}}
        <div class="tkc-head">
            <div>
                <h1 class="tkc-title">{{ $TITLE }}</h1>
                <p class="tkc-sub">
                    Số hàng của trang Tồn kho, tách ra theo từng điểm bán —
                    đang xem <b>{{ $so($soChiNhanh) }}</b> chi nhánh.
                </p>
            </div>

            <div class="tkc-sum">
                <span class="tkc-sum-item">
                    <span class="tkc-sum-lb">Dòng tồn</span>
                    <b>{{ $so($tongDong) }}</b>
                </span>
                <span class="tkc-sum-item">
                    <span class="tkc-sum-lb">Tổng số lượng</span>
                    <b>{{ $so($tongTon) }}</b>
                </span>
                {{-- Giá trị tính theo GIÁ VỐN như mọi chỗ khác trong module kho.
                     Biến thể chưa khai giá vốn đóng góp 0₫ — trang Tồn kho là chỗ
                     đếm và sửa phần thiếu đó, đây chỉ hiển thị. --}}
                <span class="tkc-sum-item">
                    <span class="tkc-sum-lb">Giá trị vốn</span>
                    <b>{{ $so($tongGiaTri) }}₫</b>
                </span>
            </div>
        </div>

        @if(!empty($error))
            <p class="tkc-callout is-error">{{ $error }}</p>
        @endif
        {{-- Thanh lọc: gõ/đổi là lọc ngay, không có nút "Lọc" --}}
        <form method="GET" action="{{ route('admin.ton-kho-chi-nhanh.index') }}" id="tkcFilter" class="tkc-filter">
            <div class="tkc-toolbar">
                <div class="tkc-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="tkc-search-input"
                           placeholder="Tìm theo tên hàng hoặc mã SKU" autocomplete="off">
                    <button type="submit" class="tkc-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                {{-- Chi nhánh: CHỌN ĐƯỢC NHIỀU, đúng như ô select2 multiple của bản
                     cũ. Không tick gì = mọi chi nhánh đang mở. Chi nhánh đã đóng
                     vẫn tick được — hàng còn kẹt ở một điểm bán vừa đóng là thứ
                     người ta cần tra nhất — nhưng phải chọn đích danh. --}}
                <div class="tkc-multi" data-multi>
                    <button type="button" class="tkc-multi-btn">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/></svg>
                        <span>{{ empty($filters['shops']) ? 'Tất cả chi nhánh' : count($filters['shops']).' chi nhánh' }}</span>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="tkc-multi-menu">
                        @forelse($chiNhanh as $cn)
                            <label class="tkc-multi-item">
                                <input type="checkbox" name="shops[]" value="{{ $cn['id'] }}"
                                       {{ in_array((int) $cn['id'], $filters['shops'], true) ? 'checked' : '' }}>
                                <span>
                                    {{ $cn['name'] }}
                                    @if(empty($cn['is_active']))
                                        <em class="tkc-multi-off">đã đóng</em>
                                    @endif
                                </span>
                            </label>
                        @empty
                            <p class="tkc-multi-empty">Không đọc được danh sách chi nhánh.</p>
                        @endforelse
                    </div>
                </div>

                <select name="stock" class="tkc-select" title="Lọc theo mức tồn">
                    <option value="all">Tất cả mức tồn</option>
                    @foreach($STOCK_STATES as $val => $label)
                        <option value="{{ $val }}" {{ $filters['stock'] === $val ? 'selected' : '' }}>
                            {{ $label }}{{ $val === 'low' ? ' (≤ '.$low.')' : '' }}
                        </option>
                    @endforeach
                </select>

                <button type="button" id="tkcAdvToggle" class="tkc-adv-btn {{ $advOpen ? 'is-open' : '' }}" aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="tkc-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="tkc-adv-count">{{ $advCount }}</span>@endif
                </button>

                {{-- Trang này chỉ ĐỌC: không có nút Thêm, chỉ còn đường mang số liệu
                     ra ngoài. Nút đứng cuối thanh lọc như mọi trang danh sách. --}}
                {{-- Cụm công cụ: giữ nguyên bộ của trang Tồn kho cũ. Chúng vẫn giữ
                     tiền tố lớp `inv-` vì cả hộp thoại lẫn CSS đi kèm được bê
                     nguyên sang đây khi trang cũ bị bỏ — đổi tên chỉ để cho đẹp
                     là mở đường cho một chỗ đổi sót. --}}
                <div class="tkc-toolbar-actions">
                    <div class="inv-util" id="invUtil">
                        <button type="button" class="inv-util-btn" id="invUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="1.6" />
                                <circle cx="19" cy="12" r="1.6" />
                                <circle cx="5" cy="12" r="1.6" />
                            </svg>
                            Tiện ích
                            <svg class="inv-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div class="inv-util-menu">
                            <a href="{{ route('admin.ton-kho-chi-nhanh.export', request()->query()) }}" class="inv-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <path d="M7 10l5 5 5-5" />
                                    <path d="M12 15V3" />
                                </svg>
                                Xuất file (CSV)
                            </a>
                            <button type="button" class="inv-util-item" id="invImportBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                    <path d="M7 8l5-5 5 5" />
                                    <path d="M12 3v12" />
                                </svg>
                                Nhập file (CSV)
                            </button>
                            <button type="button" class="inv-util-item" id="invCostBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="9" />
                                    <path d="M14.5 9.5a2.5 2.5 0 0 0-2.5-1.5c-1.4 0-2.5.7-2.5 2s1.1 2 2.5 2 2.5.7 2.5 2-1.1 2-2.5 2a2.5 2.5 0 0 1-2.5-1.5" />
                                    <path d="M12 6.5v11" />
                                </svg>
                                Khai giá vốn (CSV)
                            </button>
                            <a href="{{ route('admin.ton-kho-chi-nhanh.stocktake', request()->query()) }}" class="inv-util-item"
                                target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                    stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M6 9V2h12v7" />
                                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2" />
                                    <path d="M6 14h12v8H6z" />
                                </svg>
                                In phiếu kiểm kê
                            </a>
                        </div>
                    </div>
            </div>
            </div>

            {{-- Hàng nâng cao: các bộ lọc còn lại --}}
            <div class="tkc-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="tkcAdvRow">
                <select name="category_id" class="tkc-select" title="Lọc theo nhóm hàng hóa">
                    <option value="0">Tất cả nhóm hàng hóa</option>
                    @foreach($categories as $c)
                        <option value="{{ $c['id'] }}" {{ $filters['category_id'] === (int) $c['id'] ? 'selected' : '' }}>
                            {{ $c['name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="tkc-select" title="Sắp xếp trong từng chi nhánh">
                    @foreach($SORTS as $val => $label)
                        <option value="{{ $val }}" {{ $filters['sort'] === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                {{-- Nói rõ thứ tự chỉ chạy TRONG từng nhóm: chi nhánh luôn đứng
                     trước, nếu không thì bảng không gom nhóm được nữa. --}}
                <span class="tkc-hint">Thứ tự áp dụng bên trong từng chi nhánh.</span>
            </div>

            <input type="hidden" name="page_size" value="{{ $filters['page_size'] }}">
        </form>
        {{-- Thanh thao tác hàng loạt NỔI (hiện khi chọn ≥1 biến thể) --}}
        <div class="inv-bulk" id="invBulkBar" hidden>
            <span class="inv-bulk-count"><b id="invBulkCount">0</b> dòng đã chọn</span>
            <button type="button" class="inv-bulk-clear" id="invBulkClear">Bỏ chọn</button>
            <div class="inv-bulk-actions">
                <button type="button" class="inv-btn-ghost" id="invBulkPrint">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                    In phiếu kiểm kê
                </button>
                <button type="button" class="inv-btn-ghost" id="invBulkExport">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Xuất CSV
                </button>
                <button type="button" class="inv-btn-primary" id="invBulkAdjust">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18M3 12h18M3 17h18"/><path d="M8 4v6M16 14v6"/></svg>
                    Chỉnh tồn kho
                </button>
            </div>
        </div>
        {{-- Bảng --}}
        <div class="tkc-table-wrap">
            <table class="tkc-table">
                <thead>
                    <tr>
                        <th class="tkc-c-check">
                            <input type="checkbox" id="invCheckAll" class="inv-check" title="Chọn tất cả trong trang">
                        </th>
                        <th class="tkc-c-stt">STT</th>
                        <th class="tkc-c-sku">Mã hàng</th>
                        <th class="tkc-c-name">Tên hàng hóa</th>
                        <th class="tkc-c-variant">Phân loại</th>
                        <th class="tkc-c-group">Nhóm hàng</th>
                        <th class="tkc-c-unit">ĐVT</th>
                        <th class="tkc-c-qty">Tồn kho</th>
                        <th class="tkc-c-state">Mức tồn</th>
                        <th class="tkc-c-value">Giá trị vốn</th>
                        <th class="tkc-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @php $cnHienTai = null; $stt = 0; @endphp
                    @forelse($rows as $r)
                        @php
                            $shopId = (int) ($r['shop_id'] ?? 0);
                            $qty = (int) ($r['quantity'] ?? 0);
                            $muc = \App\Http\Controllers\TonKhoChiNhanhController::mucTon($qty, $low);
                        @endphp

                        {{-- Dòng tiêu đề nhóm: mở ra mỗi khi sang chi nhánh khác.
                             Dòng của một chi nhánh luôn nằm liền nhau vì API sắp
                             theo shop_id trước mọi kiểu sắp xếp khác. --}}
                        @if($cnHienTai !== $shopId)
                            @php
                                $cnHienTai = $shopId;
                                $g = $tomTat->get($shopId, []);
                                $stt = 0;
                            @endphp
                            <tr class="tkc-group" data-cn="{{ $shopId }}">
                                <td colspan="7">
                                    <button type="button" class="tkc-group-btn" data-toggle-cn="{{ $shopId }}">
                                        <svg class="tkc-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                        <span class="tkc-group-name">{{ $r['shop_name'] ?: 'Chi nhánh #'.$shopId }}</span>
                                        <span class="tkc-group-count">({{ $so($g['so_dong'] ?? 0) }})</span>
                                        @if(!empty($r['shop_code']))
                                            <span class="tkc-group-code">{{ $r['shop_code'] }}</span>
                                        @endif
                                    </button>
                                </td>
                                <td class="tkc-c-qty tkc-group-total">{{ $so($g['tong_ton'] ?? 0) }}</td>
                                <td></td>
                                <td class="tkc-c-value tkc-group-total">{{ $so($g['gia_tri'] ?? 0) }}₫</td>
                                <td></td>
                            </tr>
                        @endif

                        @php $stt++; @endphp
                        <tr class="tkc-row" data-cn="{{ $shopId }}">
                            {{-- Khoá của một dòng là CẶP (chi nhánh, biến thể): cùng một mặt
                                 hàng đứng ở nhiều dòng, mỗi kho một dòng, nên chỉ id biến thể
                                 thôi thì không trỏ được vào dòng nào. --}}
                            <td class="tkc-c-check">
                                <input type="checkbox" class="inv-check inv-row-check"
                                       value="{{ $shopId }}:{{ $r['variant_id'] }}"
                                       data-qty="{{ $qty }}"
                                       data-name="{{ $r['product_name'] }}"
                                       data-sku="{{ $r['sku'] }} · {{ $r['shop_name'] }}">
                            </td>
                            <td class="tkc-c-stt">{{ $stt }}</td>
                            <td class="tkc-c-sku"><span class="tkc-code">{{ $r['sku'] ?? '' }}</span></td>
                            <td class="tkc-c-name" title="{{ $r['product_name'] ?? '' }}">
                                <span class="tkc-name">{{ $r['product_name'] ?? '' }}</span>
                                @if(empty($r['is_active']))
                                    <span class="tkc-sub">Ngừng bán</span>
                                @endif
                            </td>
                            <td class="tkc-c-variant">
                                {{ $r['variant_name'] !== '' ? $r['variant_name'] : '—' }}
                            </td>
                            <td class="tkc-c-group">{{ $r['category_name'] !== '' ? $r['category_name'] : '—' }}</td>
                            <td class="tkc-c-unit">{{ $r['unit_name'] !== '' ? $r['unit_name'] : '—' }}</td>
                            <td class="tkc-c-qty">
                                <b class="tkc-qty tone-{{ $STOCK_TONES[$muc] }}">{{ $so($qty) }}</b>
                            </td>
                            <td class="tkc-c-state">
                                <span class="tkc-badge tone-{{ $STOCK_TONES[$muc] }}">{{ $STOCK_STATES[$muc] }}</span>
                            </td>
                            <td class="tkc-c-value">
                                {{-- Chưa khai giá vốn thì nói ra, không ghi 0₫: 0₫ đọc như
                                     "hàng này không đáng tiền" trong khi thật ra là chưa ai
                                     khai giá. --}}
                                @if(($r['cost_price'] ?? null) === null)
                                    <span class="tkc-muted" title="Biến thể này chưa khai giá vốn nên không cộng vào tổng">Chưa khai</span>
                                @else
                                    {{ $so($r['stock_value'] ?? 0) }}₫
                                @endif
                            </td>
                            <td class="tkc-c-act">
                                {{-- Sổ kho của ĐÚNG kho ở dòng này. Không có nút chỉnh kho:
                                     mọi lượt ghi đều rơi vào chi nhánh đang làm việc, mà ở đây
                                     người dùng đang nhìn nhiều kho cùng lúc — bấm ở dòng kho A
                                     mà hàng chạy vào kho B là kiểu sai không ai nhận ra ngay.
                                     Muốn chỉnh thì đổi kho ở thanh trên cùng rồi sang trang
                                     Tồn kho, đúng một đường duy nhất. --}}
                                {{-- Chỉnh kho GHI THẲNG vào kho của dòng này, không đi theo chi
                                     nhánh đang làm việc: bảng bày nhiều kho cùng lúc, bấm ở dòng
                                     kho A mà hàng chạy vào kho B là kiểu sai không ai nhận ra. --}}
                                <button type="button" class="tkc-rowbtn" title="Nhập / xuất / kiểm kê tại {{ $r['shop_name'] }}"
                                        data-adjust="{{ $shopId }}:{{ $r['variant_id'] }}"
                                        data-qty="{{ $qty }}"
                                        data-name="{{ $r['product_name'] }}"
                                        data-sku="{{ $r['sku'] }}"
                                        data-variant="{{ trim(($r['variant_name'] ?? '').' · '.$r['shop_name'], ' ·') }}">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18M3 12h18M3 17h18"/><path d="M8 4v6M16 14v6"/></svg>
                                </button>
                                <button type="button" class="tkc-rowbtn" title="Xem sổ kho của mặt hàng này tại {{ $r['shop_name'] }}"
                                        data-so-kho="{{ $r['variant_id'] }}"
                                        data-shop="{{ $shopId }}"
                                        data-shop-name="{{ $r['shop_name'] }}"
                                        data-name="{{ $r['product_name'] }}"
                                        data-sku="{{ $r['sku'] }}"
                                        data-variant="{{ $r['variant_name'] }}"
                                        data-qty="{{ $qty }}">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M8 7h8M8 11h5"/></svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="tkc-empty">{{ $EMPTY_TEXT }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'dòng tồn',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>
    </div>

    {{-- ===== Modal chỉnh tồn kho (dùng chung cho 1 dòng và hàng loạt) ===== --}}
    <div class="inv-overlay" id="invAdjustOverlay" style="display:none;">
        <div class="inv-dialog inv-dialog-sm">
            <form method="POST" action="{{ route('admin.ton-kho-chi-nhanh.bulkAdjust') }}" id="invAdjustForm">
                @csrf
                <input type="hidden" name="_method" value="POST" id="invAdjustMethod">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                {{-- Kho bị sửa đi kèm ngay trong form. Bỏ trống rồi để API rơi về
                     "chi nhánh đang làm việc" là mở đường cho hàng chạy nhầm kho. --}}
                <input type="hidden" name="shop_id" value="" id="invAdjustShop">
                <input type="hidden" name="mode" value="set" id="invAdjustMode">
                <input type="hidden" name="quantity" value="0" id="invAdjustQtyRaw">
                <div id="invAdjustIds"></div>

                <div class="inv-modal-head">
                    <h4 class="inv-modal-title" id="invAdjustTitle">Chỉnh tồn kho</h4>
                    <button type="button" class="inv-modal-x" data-close>
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="inv-modal-body">
                    {{-- Đối tượng đang chỉnh: 1 biến thể cụ thể, hoặc N biến thể đã chọn --}}
                    <div class="inv-target" id="invAdjustTarget">
                        <span class="inv-target-name" id="invAdjustName">—</span>
                        <span class="inv-target-sub" id="invAdjustSub">—</span>
                        {{-- Ô nhập để trống nên tồn hiện tại phải hiện ở đây, không thì
                             người dùng mất mốc để biết mình đang chỉnh từ số nào. --}}
                        <span class="inv-target-now" id="invAdjustNow" hidden></span>
                    </div>

                    {{-- Thao tác: quyết định cách hiểu số lượng bên dưới --}}
                    <div class="inv-field">
                        <span class="inv-lb">Thao tác</span>
                        <div class="inv-segment" id="invAdjustOps" role="group" aria-label="Chọn thao tác kho">
                            <button type="button" class="inv-seg is-on" data-op="set">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                Kiểm kê
                            </button>
                            <button type="button" class="inv-seg" data-op="import">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                                Nhập thêm
                            </button>
                            <button type="button" class="inv-seg" data-op="export">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                Xuất bớt
                            </button>
                        </div>
                        <p class="inv-hint" id="invAdjustHint">Đặt lại tồn kho về đúng số đếm được trên kệ.</p>
                    </div>

                    <div class="inv-form-grid">
                        <div class="inv-field">
                            <label class="inv-lb" for="invAdjustQty" id="invAdjustQtyLb">Số lượng kiểm kê <span class="inv-req">*</span></label>
                            {{-- Để TRỐNG, không điền sẵn số: điền sẵn tồn hiện tại thì người
                                 dùng dễ bấm Lưu luôn mà tưởng đã nhập, kho không đổi gì. --}}
                            <input type="text" inputmode="numeric" id="invAdjustQty" class="inv-input inv-qty-input"
                                value="" placeholder="Nhập số" autocomplete="off">
                        </div>
                        <div class="inv-field">
                            <span class="inv-lb">Tồn sau khi lưu</span>
                            <span class="inv-preview" id="invAdjustPreview">—</span>
                        </div>
                        <div class="inv-field is-full">
                            <label class="inv-lb" for="invAdjustNote">Ghi chú</label>
                            <input type="text" name="note" id="invAdjustNote" class="inv-input" maxlength="255"
                                placeholder="VD: nhập lô ngày 28/07, kiểm kê cuối tháng…" autocomplete="off">
                        </div>
                    </div>

                    <p class="inv-hint">Mỗi lần chỉnh đều được ghi vào sổ kho kèm tên người thực hiện.</p>
                </div>

                <div class="inv-modal-foot">
                    <div class="inv-foot-right">
                        <button type="button" class="inv-btn-ghost" data-close>Huỷ</button>
                        <button type="submit" class="inv-btn-primary" id="invAdjustSubmit">Lưu thay đổi</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal nhập số kiểm kê từ file CSV ===== --}}
    <div class="inv-overlay" id="invImportOverlay" style="display:none;">
        <div class="inv-dialog inv-dialog-sm">
            <form method="POST" action="{{ route('admin.ton-kho-chi-nhanh.import') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="mode" value="set" id="invImportMode">

                <div class="inv-modal-head">
                    <h4 class="inv-modal-title">Nhập số kiểm kê từ file</h4>
                    <button type="button" class="inv-modal-x" data-close>
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="inv-modal-body">
                    <p class="inv-note">
                        File <b>CSV</b> hai cột: <b>sku</b> và <b>quantity</b>, mỗi dòng một biến thể. SKU không phân biệt
                        hoa thường. Thêm cột <b>mode</b> (<b>set</b> hoặc <b>delta</b>) nếu muốn vài dòng chạy khác cách áp
                        chung chọn bên dưới.
                        <br><a href="{{ route('admin.ton-kho-chi-nhanh.importTemplate') }}">Tải file mẫu</a>
                    </p>

                    {{-- MỘT LẦN KIỂM KÊ LÀ ĐẾM MỘT KHO: người cầm máy đi giữa các kệ của
                         một điểm bán. File chỉ có sku + số lượng nên không tự nói được kho
                         nào — phải chọn ở đây. Mặc định là kho đang lọc nếu người dùng chỉ
                         chọn đúng một, còn không thì để trống bắt chọn, chứ không đoán. --}}
                    <div class="inv-field">
                        <label class="inv-lb" for="invImportShop">Đổ vào kho <span class="inv-req">*</span></label>
                        <select name="shop_id" id="invImportShop" class="inv-input" required>
                            <option value="">— Chọn chi nhánh —</option>
                            @foreach($chiNhanh as $cn)
                                <option value="{{ $cn['id'] }}"
                                    {{ count($filters['shops']) === 1 && (int) $cn['id'] === $filters['shops'][0] ? 'selected' : '' }}>
                                    {{ $cn['name'] }}{{ empty($cn['is_active']) ? ' (đã đóng)' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Cùng bộ nút với modal chỉnh tay, để hai chỗ nói cùng một ngôn ngữ --}}
                    <div class="inv-field">
                        <span class="inv-lb">Cách áp số liệu</span>
                        <div class="inv-segment" id="invImportOps" role="group" aria-label="Chọn cách áp số liệu">
                            <button type="button" class="inv-seg is-on" data-mode="set">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                Kiểm kê
                            </button>
                            <button type="button" class="inv-seg" data-mode="delta">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                                Cộng / bớt
                            </button>
                        </div>
                        <p class="inv-hint" id="invImportHint">Đặt lại tồn kho về đúng số đếm được trong file.</p>
                    </div>

                    <div class="inv-field">
                        <label class="inv-lb" for="invImportFile">File CSV <span class="inv-req">*</span></label>
                        <input type="file" id="invImportFile" name="file" accept=".csv,text/csv" required
                            class="inv-input inv-input-file">
                    </div>

                    <div class="inv-field">
                        <label class="inv-lb" for="invImportNote">Ghi chú</label>
                        <input type="text" name="note" id="invImportNote" class="inv-input" maxlength="255"
                            placeholder="VD: kiểm kê cuối tháng 07/2026" autocomplete="off">
                    </div>

                    <p class="inv-hint">
                        Dòng sai (SKU không có trong kho, số không đọc được) bị loại ra và báo lại theo số dòng — phần còn
                        lại vẫn vào kho. Mỗi dòng đều được ghi vào sổ kho kèm tên người thực hiện.
                    </p>
                </div>

                <div class="inv-modal-foot">
                    <div class="inv-foot-right">
                        <button type="button" class="inv-btn-ghost" data-close>Huỷ</button>
                        <button type="submit" class="inv-btn-primary">Nhập số liệu</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- ===== Modal khai giá vốn từ file CSV ===== --}}
    <div class="inv-overlay" id="invCostOverlay" style="display:none;">
        <div class="inv-dialog inv-dialog-sm">
            <form method="POST" action="{{ route('admin.ton-kho-chi-nhanh.importCost') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="inv-modal-head">
                    <h4 class="inv-modal-title">Khai giá vốn từ file</h4>
                    <button type="button" class="inv-modal-x" data-close>
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                    </button>
                </div>

                <div class="inv-modal-body">
                    <p class="inv-note">
                        File <b>CSV</b> hai cột: <b>sku</b> và <b>cost_price</b>, mỗi dòng một biến thể. Giá vốn ghi ở mức
                        biến thể (ghi đè giá vốn của sản phẩm cha). Ô giá vốn để <b>trống</b> là xoá giá vốn riêng, cho biến
                        thể quay về lấy theo sản phẩm — muốn khai giá vốn bằng không thì phải gõ số <b>0</b>.
                        <br><a href="{{ route('admin.ton-kho-chi-nhanh.importCostTemplate') }}">Tải file mẫu</a>
                    </p>

                    <div class="inv-field">
                        <label class="inv-lb" for="invCostFile">File CSV <span class="inv-req">*</span></label>
                        <input type="file" id="invCostFile" name="file" accept=".csv,text/csv" required
                            class="inv-input inv-input-file">
                    </div>

                    <p class="inv-hint">
                        Giá vốn chỉ dùng để tính giá trị tồn kho, không hiển thị cho khách. Thao tác này không ghi vào sổ
                        kho vì nó sửa thuộc tính của hàng, không phải hàng ra vào kho.
                    </p>
                </div>

                <div class="inv-modal-foot">
                    <div class="inv-foot-right">
                        <button type="button" class="inv-btn-ghost" data-close>Huỷ</button>
                        <button type="submit" class="inv-btn-primary">Khai giá vốn</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <style>
        .tkc {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .tkc-head {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
            padding: 14px 20px 10px;
        }
        .tkc-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 24px; }
        .tkc-sub { margin: 4px 0 0; font-size: 12px; color: #8c8c8c; }

        .tkc-sum { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .tkc-sum-item {
            display: flex; flex-direction: column; gap: 2px; min-width: 116px;
            border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa; padding: 8px 12px;
        }
        .tkc-sum-lb { font-size: 11px; color: #8c8c8c; }
        .tkc-sum-item b { font-size: 15px; font-weight: 700; color: #262626; font-variant-numeric: tabular-nums; }

        .tkc-callout {
            margin: 0 20px 12px; border: 1px solid #f0f0f0; border-radius: 6px;
            padding: 10px 12px; font-size: 13px;
        }
        .tkc-callout.is-error { background: #fff1f0; border-color: #ffa39e; color: #a8071a; }

        /* Bộ lọc */
        .tkc-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .tkc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .tkc-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .tkc-toolbar-adv.is-open { display: flex; }
        .tkc-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .tkc-hint { font-size: 12px; color: #8c8c8c; }

        .tkc-searchbox { display: flex; border-radius: 4px; }
        .tkc-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .tkc-search-input {
            height: 34px; width: 240px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .tkc-search-input::placeholder { color: #bfbfbf; }
        .tkc-searchbox:focus-within .tkc-search-input,
        .tkc-searchbox:focus-within .tkc-search-btn { border-color: #86b7fe; }
        .tkc-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .tkc-search-btn:hover { color: #1890ff; }

        .tkc-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .tkc-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Ô lọc chọn nhiều — bản cũ dùng select2 multiple, đây là bản không cần
           thư viện ngoài: một nút xổ danh sách ô tick. */
        .tkc-multi { position: relative; }
        .tkc-multi-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 8px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 10px 0 12px; font-size: 13px; color: #262626;
            cursor: pointer; max-width: 240px; transition: border-color .15s;
        }
        .tkc-multi-btn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tkc-multi-btn svg { color: #8c8c8c; flex-shrink: 0; }
        .tkc-multi-btn:hover, .tkc-multi.open .tkc-multi-btn { border-color: #1890ff; }
        .tkc-multi-menu {
            position: absolute; left: 0; top: calc(100% + 4px); min-width: 240px; max-height: 320px; overflow-y: auto;
            z-index: 1050; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .tkc-multi.open .tkc-multi-menu { display: block; }
        .tkc-multi-item {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none; white-space: nowrap;
        }
        .tkc-multi-item:hover { background: #f5f7fa; }
        .tkc-multi-item input { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; flex-shrink: 0; }
        .tkc-multi-off { margin-left: 6px; font-style: normal; font-size: 11px; color: #bfbfbf; }
        .tkc-multi-empty { padding: 10px; font-size: 12px; color: #8c8c8c; }

        /* Nút "Nâng cao" */
        .tkc-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .tkc-adv-btn:hover, .tkc-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .tkc-adv-caret { transition: transform .2s; }
        .tkc-adv-btn.is-open .tkc-adv-caret { transform: rotate(180deg); }
        .tkc-adv-count {
            display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff; font-size: 11px; font-weight: 600;
        }

        .tkc-btn-ghost {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            text-decoration: none; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .tkc-btn-ghost:hover { border-color: #1890ff; color: #1890ff; }
        .tkc-btn-ghost svg { flex-shrink: 0; }

        /* Bảng — cùng khuôn với mọi trang danh sách: rộng hết khung, mọi ô canh
           giữa, bề rộng khai theo % và cộng đúng 100%. */
        .tkc-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; }
        .tkc-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .tkc-table thead tr { background: #f0f0f0; color: #262626; }
        .tkc-table th, .tkc-table td { padding: 14px 10px; vertical-align: middle; white-space: nowrap; text-align: center; }
        .tkc-table th { font-weight: 700; }
        .tkc-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .tkc-table tbody tr.tkc-row:hover { background: #fafafa; }

        .tkc-table th.tkc-c-check,   .tkc-table td.tkc-c-check   { width: 4%; }
        .tkc-table th.tkc-c-stt,     .tkc-table td.tkc-c-stt     { width: 4%; }
        .tkc-table th.tkc-c-sku,     .tkc-table td.tkc-c-sku     { width: 11%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-name,    .tkc-table td.tkc-c-name    { width: 18%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-variant, .tkc-table td.tkc-c-variant { width: 10%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-group,   .tkc-table td.tkc-c-group   { width: 10%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-unit,    .tkc-table td.tkc-c-unit    { width: 6%; }
        .tkc-table th.tkc-c-qty,     .tkc-table td.tkc-c-qty     { width: 8%; font-variant-numeric: tabular-nums; }
        .tkc-table th.tkc-c-state,   .tkc-table td.tkc-c-state   { width: 8%; }
        .tkc-table th.tkc-c-value,   .tkc-table td.tkc-c-value   { width: 13%; font-variant-numeric: tabular-nums; }
        .tkc-table th.tkc-c-act,     .tkc-table td.tkc-c-act     { width: 8%; }
        .tkc-table td.tkc-c-act { white-space: nowrap; }
        .tkc-table td.tkc-c-act .tkc-rowbtn + .tkc-rowbtn { margin-left: 6px; }
        .tkc-table tbody tr.is-selected, .tkc-table tbody tr.is-selected:hover { background: #e6f7ff; }

        /* Nút hành động: ô vuông bo góc có viền, icon xám — cùng bộ với mọi trang
           danh sách khác. */
        .tkc-rowbtn {
            width: 30px; height: 30px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            color: #595959; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .15s, color .15s;
        }
        .tkc-rowbtn:hover { border-color: #1890ff; color: #1890ff; }

        /* Dòng tiêu đề nhóm chi nhánh — nền xám nhạt để tách hẳn khỏi dòng hàng,
           nhưng vẫn là một dòng của bảng chứ không phải một khối riêng: gom nhóm
           bằng thẻ rời thì cột không còn thẳng hàng với phần đầu bảng. */
        .tkc-table tbody tr.tkc-group { background: #f5f7fa; }
        .tkc-table tbody tr.tkc-group td { padding: 10px; text-align: left; }
        .tkc-group-btn {
            display: inline-flex; align-items: center; gap: 8px; border: 0; background: none; padding: 0;
            font-size: 13px; color: #262626; cursor: pointer;
        }
        .tkc-group-name { font-weight: 700; }
        .tkc-group-count { color: #8c8c8c; }
        .tkc-group-code {
            border-radius: 4px; background: #f0f5ff; color: #1d39c4; padding: 1px 7px;
            font-size: 11px; font-weight: 500;
        }
        .tkc-group-total { font-weight: 700; text-align: center !important; }
        .tkc-caret { transition: transform .2s; color: #8c8c8c; }
        .tkc-group.is-closed .tkc-caret { transform: rotate(-90deg); }

        .tkc-code { color: #595959; font-variant-numeric: tabular-nums; letter-spacing: .2px; }
        .tkc-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tkc-sub { display: block; margin-top: 2px; font-size: 12px; color: #bfbfbf; }
        .tkc-muted { color: #bfbfbf; }
        .tkc-qty { font-size: 14px; }
        .tkc-qty.tone-done { color: #262626; }
        .tkc-qty.tone-wait { color: #d46b08; }
        .tkc-qty.tone-stop { color: #cf1322; }

        .tkc-badge {
            display: inline-block; border-radius: 4px; padding: 2px 10px;
            font-size: 12px; font-weight: 500; white-space: nowrap;
        }
        .tkc-badge.tone-done { background: #f6ffed; color: #389e0d; }
        .tkc-badge.tone-wait { background: #fff7e6; color: #d46b08; }
        .tkc-badge.tone-stop { background: #fff1f0; color: #cf1322; }

        .tkc-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Hộp thoại sổ kho */
        .tkc-overlay {
            position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center;
            padding: 16px; background: rgba(0, 0, 0, .4);
        }
        .tkc-overlay[hidden] { display: none; }
        .tkc-dialog {
            width: 100%; max-width: 860px; max-height: 92vh; display: flex; flex-direction: column;
            border-radius: 6px; background: #fff; box-shadow: 0 10px 40px rgba(0, 0, 0, .2);
        }
        .tkc-dialog-head {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
            padding: 16px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .tkc-dialog-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .tkc-dialog-sub { margin: 4px 0 0; font-size: 12px; color: #8c8c8c; }
        .tkc-dialog-x {
            width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
            border: 0; background: none; border-radius: 4px; color: #8c8c8c; cursor: pointer; flex-shrink: 0;
        }
        .tkc-dialog-x:hover { background: #f5f5f5; color: #262626; }
        .tkc-dialog-body { padding: 12px 20px 16px; overflow-y: auto; }
        /* Hàng nút ở chân hộp thoại luôn CANH GIỮA, như mọi hộp thoại khác. */
        .tkc-dialog-foot {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            padding: 12px 20px 16px; border-top: 1px solid #f0f0f0;
        }
        .tkc-btn-close {
            height: 34px; min-width: 96px; border: 0; border-radius: 4px; background: #ff4d4f; color: #fff;
            font-size: 13px; font-weight: 500; cursor: pointer; transition: background .15s;
        }
        .tkc-btn-close:hover { background: #ff7875; }

        .tkc-ledger-wrap { width: 100%; overflow-x: auto; }
        .tkc-ledger { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .tkc-ledger thead tr { background: #f0f0f0; }
        .tkc-ledger th, .tkc-ledger td {
            padding: 10px; vertical-align: middle; text-align: center; white-space: nowrap;
            border-bottom: 1px solid #f0f0f0;
        }
        .tkc-ledger th { font-weight: 700; }
        .tkc-ledger .tkc-l-time  { width: 17%; color: #595959; }
        .tkc-ledger .tkc-l-what  { width: 25%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-ledger .tkc-l-delta { width: 13%; font-weight: 700; font-variant-numeric: tabular-nums; }
        .tkc-ledger .tkc-l-after { width: 13%; font-variant-numeric: tabular-nums; }
        .tkc-ledger .tkc-l-ref   { width: 17%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-ledger .tkc-l-who   { width: 15%; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .tkc-ledger .is-up { color: #389e0d; }
        .tkc-ledger .is-down { color: #cf1322; }
        .tkc-l-note { display: block; margin-top: 2px; font-size: 11px; color: #8c8c8c; }
        .tkc-ledger-empty { padding: 32px 10px; color: #8c8c8c; white-space: normal; }

        .tkc-more-wrap { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 12px; }
        .tkc-more {
            height: 30px; padding: 0 14px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            font-size: 12px; color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .tkc-more:hover { border-color: #1890ff; color: #1890ff; }
        .tkc-more-note { font-size: 12px; color: #8c8c8c; }

        .tkc-search-btn:focus-visible, .tkc-btn-ghost:focus-visible {
            outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25);
        }
        .inv {
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626;
            background: #fff;
        }

        /* Header */
        .inv-head-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        /* Nhãn kho đang xem — đứng cạnh tên trang chứ không nhét vào thanh lọc:
           nó không phải điều kiện lọc mà là PHẠM VI của mọi con số bên dưới. */
        .inv-kho {
            display: inline-flex; align-items: center; gap: 6px; border-radius: 4px;
            background: #f0f5ff; color: #1d39c4; padding: 3px 10px; font-size: 12px; font-weight: 500;
        }
        .inv-kho.is-gop { background: #f5f5f5; color: #595959; }
        .inv-kho svg { flex-shrink: 0; }
        .inv-kho-link {
            display: inline-flex; align-items: center; gap: 4px; font-size: 12px;
            color: #1890ff; text-decoration: none;
        }
        .inv-kho-link:hover { text-decoration: underline; }

        .inv-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .inv-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }

        /* Số liệu toàn kho: bấm vào là lọc đúng nhóm đó, trừ ô giá trị kho (chỉ đọc) */
        .inv-sum { display: flex; align-items: stretch; gap: 8px; flex-wrap: wrap; }
        .inv-sum-item {
            display: flex; flex-direction: column; gap: 1px; min-width: 96px; padding: 5px 12px;
            border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa; text-decoration: none;
            transition: border-color .15s, background .15s;
        }
        .inv-sum-item:not(.is-static):hover { border-color: #91caff; background: #f0f8ff; }
        .inv-sum-item.is-on { border-color: #1890ff; background: #e6f7ff; }
        .inv-sum-item.is-static { cursor: default; }
        .inv-sum-lb { font-size: 11px; color: #8c8c8c; white-space: nowrap; }
        /* nowrap: ô "Giá trị kho" dài nhất, thiếu nó thì ký hiệu ₫ bị cắt mất ở rìa phải */
        .inv-sum-item b { font-size: 15px; font-weight: 700; color: #262626; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .inv-sum-item.tone-done b { color: #237804; }
        .inv-sum-item.tone-wait b { color: #d46b08; }
        .inv-sum-item.tone-stop b { color: #cf1322; }
        .inv-sum-item.is-warn { border-color: #ffd591; background: #fff7e6; }
        .inv-sum-warn {
            display: inline-flex; align-items: center; gap: 4px; margin-left: 2px;
            font-size: 11px; font-weight: 600; color: #d46b08; white-space: nowrap;
        }
        .inv-sum-warn svg { flex-shrink: 0; }
        .inv-nocost { color: #bfbfbf; font-weight: 600; cursor: help; }

        /* Bộ lọc */
        .inv-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .inv-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .inv-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .inv-searchbox { display: flex; border-radius: 4px; }
        .inv-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13, 110, 253, .25); }
        .inv-search-input {
            height: 34px; width: 300px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .inv-search-input::placeholder { color: #bfbfbf; }
        .inv-searchbox:focus-within .inv-search-input, .inv-searchbox:focus-within .inv-search-btn { border-color: #86b7fe; }
        .inv-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .inv-search-btn:hover { color: #1890ff; }

        .inv-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .inv-select-sm { max-width: 150px; }

        /* Xoá lọc: viền đứt để trông rõ là nút "gỡ bỏ", khác hẳn các nút thao tác đặc.
           Hover chuyển sang tông đỏ vì nó xoá đi thứ người dùng vừa chọn. */
        .inv-clear {
            display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px;
            border: 1px dashed #d9d9d9; border-radius: 4px; background: #fff;
            font-size: 13px; color: #8c8c8c; text-decoration: none;
            transition: border-color .15s, background .15s, color .15s;
        }
        .inv-clear:hover { border-color: #ffa39e; background: #fff1f0; color: #ff4d4f; }
        .inv-clear svg { flex-shrink: 0; }
        .inv-clear-count {
            display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 9999px; background: #f0f0f0; color: #595959;
            font-size: 11px; font-weight: 600;
        }
        .inv-clear:hover .inv-clear-count { background: #ff4d4f; color: #fff; }

        .inv-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .inv-toolbar-adv.is-open { display: flex; }

        .inv-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .inv-adv-btn:hover, .inv-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .inv-adv-caret { transition: transform .2s; }
        .inv-adv-btn.is-open .inv-adv-caret { transform: rotate(180deg); }
        .inv-adv-count {
            display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff; font-size: 11px; font-weight: 600;
        }

        .inv-util { position: relative; }
        .inv-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .inv-util-btn:hover, .inv-util.open .inv-util-btn { border-color: #1890ff; color: #1890ff; }
        .inv-util-caret { transition: transform .2s; }
        .inv-util.open .inv-util-caret { transform: rotate(180deg); }
        .inv-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 200px; z-index: 1050; background: #fff;
            border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px; box-shadow: 0 6px 24px rgba(0, 0, 0, .12); display: none;
        }
        .inv-util.open .inv-util-menu { display: block; }
        .inv-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; background: none;
            border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none; cursor: pointer;
        }
        .inv-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .inv-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .inv-util-item:hover svg { color: #1890ff; }

        /* Thanh bulk NỔI — đồng bộ với trang Đơn hàng/Sản phẩm/Trả hàng */
        .inv-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0, 0, 0, .15);
        }
        body:has(.jh-sidebar.collapsed) .inv-bulk { left: 48px; }
        @media (max-width: 820px) { .inv-bulk { left: 0; } }
        .inv-bulk-count { font-size: 13px; font-weight: 500; color: #262626; white-space: nowrap; }
        .inv-bulk-count b { color: #1890ff; }
        .inv-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .inv-bulk-clear:hover { color: #262626; }
        .inv-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .inv-bulk-actions .inv-btn-ghost, .inv-bulk-actions .inv-btn-primary { display: inline-flex; align-items: center; gap: 6px; }
        .inv-bulk-actions svg { flex-shrink: 0; }

        /* Bảng */
        .inv-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; scrollbar-width: thin; }
        .inv-table-wrap::-webkit-scrollbar { height: 11px; }
        .inv-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        /* Bảng — cùng khuôn với mọi trang danh sách: rộng hết khung, MỌI Ô CANH
           GIỮA, bề rộng khai theo % và cộng đúng 100%. Để một cột không có width
           là cột đó nuốt hết phần dư và các cột còn lại dồn cục lại. */
        .inv-table { width: 100%; min-width: 1080px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .inv-table thead tr { background: #f0f0f0; color: #262626; }
        .inv-table th, .inv-table td { padding: 14px 10px; vertical-align: middle; white-space: nowrap; text-align: center; line-height: 1.5; }
        .inv-table th { font-weight: 700; }
        .inv-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .inv-table tbody tr:hover { background: #fafafa; }
        .inv-table tbody tr.is-selected, .inv-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .inv-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        .inv-table th.inv-c-check,   .inv-table td.inv-c-check   { width: 4%; }
        .inv-table th.inv-c-stt,     .inv-table td.inv-c-stt     { width: 4%; color: #8c8c8c; }
        .inv-table th.inv-c-img,     .inv-table td.inv-c-img     { width: 5%; }
        .inv-table th.inv-c-name,    .inv-table td.inv-c-name    { width: 20%; overflow: hidden; text-overflow: ellipsis; }
        .inv-table th.inv-c-variant, .inv-table td.inv-c-variant { width: 10%; color: #595959; overflow: hidden; text-overflow: ellipsis; }
        .inv-table th.inv-c-group,   .inv-table td.inv-c-group   { width: 10%; overflow: hidden; text-overflow: ellipsis; }
        .inv-table th.inv-c-qty,     .inv-table td.inv-c-qty     { width: 8%; }
        .inv-table th.inv-c-price,   .inv-table td.inv-c-price   { width: 9%; color: #595959; font-variant-numeric: tabular-nums; }
        .inv-table th.inv-c-value,   .inv-table td.inv-c-value   { width: 10%; font-variant-numeric: tabular-nums; }
        .inv-table th.inv-c-status,  .inv-table td.inv-c-status  { width: 7%; }
        .inv-table th.inv-c-date,    .inv-table td.inv-c-date    { width: 8%; color: #8c8c8c; }
        .inv-table th.inv-c-act,     .inv-table td.inv-c-act     { width: 5%; }

        .inv-c-img[data-view], .inv-c-name[data-view] { cursor: pointer; }
        .inv-c-name[data-view]:hover .inv-name { color: #1890ff; text-decoration: underline; }

        .inv-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; border: 1px solid #f0f0f0; display: inline-block; vertical-align: middle; }
        .inv-thumb-empty { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 6px; background: #f5f5f5; color: #bfbfbf; border: 1px solid #f0f0f0; }

        .inv-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .inv-sub { display: block; margin-top: 4px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .inv-off { color: #d46b08; }
        .inv-total { font-weight: 600; color: #262626; font-variant-numeric: tabular-nums; }

        .inv-qty {
            display: inline-block; min-width: 46px; padding: 2px 8px; border-radius: 6px; border: 1px solid #f0f0f0;
            background: #fafafa; font-size: 14px; font-weight: 700; font-variant-numeric: tabular-nums; text-align: center;
        }
        .inv-qty.is-in { color: #237804; }
        .inv-qty.is-low { color: #d46b08; border-color: #ffe7ba; background: #fffbe6; }
        .inv-qty.is-out { color: #cf1322; border-color: #ffccc7; background: #fff1f0; }

        .inv-badge {
            display: inline-block; padding: 3px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500;
            border: 1px solid #d9d9d9; color: #595959; background: #fafafa; white-space: nowrap;
        }
        .inv-badge.tone-wait { border-color: #ffd591; color: #d46b08; background: #fff7e6; }
        .inv-badge.tone-info { border-color: #91d5ff; color: #096dd9; background: #e6f7ff; }
        .inv-badge.tone-done { border-color: #b7eb8f; color: #389e0d; background: #f6ffed; }
        .inv-badge.tone-stop { border-color: #ffa39e; color: #cf1322; background: #fff1f0; }

        .inv-rowacts { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .inv-rowbtn {
            width: 30px; height: 30px; border: 1px solid #d9d9d9; background: #fff; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none; transition: border-color .15s, color .15s;
        }
        .inv-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .inv-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; white-space: normal; }

        /* Modal */
        .inv-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0, 0, 0, .4); }
        .inv-dialog {
            max-height: 92vh; width: 100%; max-width: 880px; overflow-y: auto; border-radius: 6px; background: #fff;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .2); scrollbar-width: thin;
        }
        .inv-dialog::-webkit-scrollbar { width: 11px; }
        .inv-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .inv-dialog-sm { max-width: 480px; }

        .inv-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .inv-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .inv-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; }
        .inv-modal-x:hover { color: #262626; }
        .inv-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 18px; }
        .inv-modal-foot {
            position: sticky; bottom: 0; display: flex; align-items: center; justify-content: center; gap: 12px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px; flex-wrap: wrap;
        }
        .inv-foot-right { display: flex; gap: 8px; }

        .inv-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px;
            font-weight: 500; color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none;
        }
        .inv-btn-ghost:hover { border-color: #bfbfbf; }
        .inv-btn-primary {
            height: 34px; border: 0; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #fff; background: #1890ff; cursor: pointer; transition: background .15s;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        }
        .inv-btn-primary:hover:not([disabled]) { background: #40a9ff; }
        .inv-btn-primary[disabled] { opacity: .5; cursor: not-allowed; }

        /* Nội dung modal */
        .inv-target { display: flex; flex-direction: column; gap: 2px; padding: 10px 12px; border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa; }
        .inv-target-name { font-size: 13px; font-weight: 600; color: #262626; }
        .inv-target-sub { font-size: 12px; color: #8c8c8c; }
        .inv-target-now { margin-top: 4px; font-size: 12px; color: #595959; }
        .inv-target-now[hidden] { display: none; }
        .inv-target-now b { font-size: 14px; font-weight: 700; color: #262626; font-variant-numeric: tabular-nums; }

        .inv-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .inv-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .inv-field.is-full { grid-column: span 2; }
        .inv-lb { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c; }
        .inv-req { color: #ff4d4f; }
        .inv-hint { margin: 0; font-size: 11px; color: #bfbfbf; }
        .inv-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px;
            background: #f5f9ff; font-size: 12px; line-height: 1.55; color: #595959;
        }
        .inv-note b { color: #262626; }
        /* .inv-input có chiều cao cố định — ô chọn file cần tự giãn theo nút của trình duyệt. */
        .inv-input-file { height: auto; padding: 7px 10px; }

        .inv-input {
            width: 100%; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            font-family: inherit; color: #262626; background: #fff; outline: none; transition: border-color .15s, box-shadow .15s;
        }
        .inv-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 3px rgba(13, 110, 253, .15); }
        .inv-input::placeholder { color: #bfbfbf; }
        .inv-qty-input { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
        .inv-preview {
            display: inline-flex; align-items: center; height: 34px; padding: 0 12px; border-radius: 4px;
            border: 1px solid #f0f0f0; background: #fafafa; font-size: 14px; font-weight: 700;
            font-variant-numeric: tabular-nums; color: #262626;
        }
        .inv-preview.is-bad { border-color: #ffccc7; background: #fff1f0; color: #cf1322; }

        /* Nút chọn thao tác kho */
        .inv-segment { display: flex; gap: 0; border: 1px solid #d9d9d9; border-radius: 4px; overflow: hidden; width: max-content; }
        .inv-seg {
            display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 14px; border: 0;
            border-right: 1px solid #d9d9d9; background: #fff; font-size: 13px; color: #595959; cursor: pointer;
            transition: background .15s, color .15s;
        }
        .inv-seg:last-child { border-right: 0; }
        .inv-seg:hover { background: #f5f7fa; color: #1890ff; }
        .inv-seg.is-on { background: #1890ff; color: #fff; }
        .inv-seg svg { flex-shrink: 0; }

        .inv-view-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .inv-view-ident { display: flex; flex-direction: column; gap: 2px; margin-right: auto; min-width: 0; }
        .inv-view-name { font-size: 15px; font-weight: 700; color: #262626; }
        .inv-view-sku { font-size: 12px; color: #8c8c8c; }

        /* Ba con số chính của biến thể — để to, đọc lướt là thấy ngay */
        .inv-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .inv-kpi {
            display: flex; flex-direction: column; gap: 2px; padding: 12px 14px;
            border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa; min-width: 0;
        }
        .inv-kpi-lb { font-size: 11px; color: #8c8c8c; }
        .inv-kpi-num { font-size: 22px; font-weight: 700; color: #262626; font-variant-numeric: tabular-nums; line-height: 1.2; }
        .inv-kpi-num.is-sm { font-size: 16px; }
        .inv-kpi-num.is-muted { font-size: 13px; font-weight: 600; color: #bfbfbf; }
        .inv-kpi-unit { font-size: 11px; color: #bfbfbf; }

        .inv-meta-line { margin: -6px 0 0; font-size: 12px; color: #8c8c8c; }
        .inv-meta-line b { font-weight: 600; color: #595959; }

        /* Sổ kho dạng bảng */
        .inv-ledger-wrap { width: 100%; overflow-x: auto; scrollbar-width: thin; }
        .inv-ledger { width: 100%; border-collapse: collapse; font-size: 13px; }
        .inv-ledger thead tr { background: #fafafa; }
        .inv-ledger th, .inv-ledger td { padding: 10px 12px; text-align: left; vertical-align: top; border-bottom: 1px solid #f0f0f0; white-space: nowrap; }
        .inv-ledger th { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c; }
        .inv-ledger tbody tr:last-child td { border-bottom: 0; }
        .inv-ledger tbody tr:hover { background: #fafafa; }

        .inv-ledger .inv-l-time { color: #595959; }
        .inv-l-hour { display: block; margin-top: 2px; font-size: 11px; color: #bfbfbf; }
        .inv-l-type { display: block; font-weight: 500; color: #262626; }
        .inv-l-note { display: block; margin-top: 2px; font-size: 11px; color: #8c8c8c; white-space: normal; max-width: 220px; }
        /* Tiêu đề hai cột số phải canh phải theo đúng con số bên dưới, nếu không
           chữ đứng một nơi mà số nằm một nẻo. */
        .inv-ledger th.inv-l-delta, .inv-ledger th.inv-l-after { text-align: right; }
        .inv-ledger .inv-l-delta { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; }
        .inv-l-delta.is-up { color: #237804; }
        .inv-l-delta.is-down { color: #cf1322; }
        .inv-ledger .inv-l-after { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; color: #262626; }
        .inv-ledger .inv-l-ref { color: #595959; }
        .inv-ledger .inv-l-who { color: #8c8c8c; }
        .inv-ledger .inv-l-shop { color: #1d39c4; white-space: nowrap; }
        .inv-ledger-empty { padding: 28px 12px !important; text-align: center; color: #bfbfbf; white-space: normal; }

        .inv-view-sec { display: flex; flex-direction: column; gap: 10px; }
        .inv-sec-title {
            margin: 0; padding-bottom: 6px; border-bottom: 1px solid #f0f0f0; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .05em; color: #8c8c8c;
        }
        .inv-sec-note { font-weight: 500; text-transform: none; letter-spacing: 0; color: #bfbfbf; }

        .inv-ledger-more { display: flex; justify-content: center; padding: 12px 0 2px; }
        .inv-ledger-more[hidden] { display: none; }
        .inv-ledger-more .inv-btn-ghost[disabled] { color: #bfbfbf; cursor: default; }

        @media (max-width: 720px) {
            .inv-form-grid, .inv-kpis { grid-template-columns: 1fr; }
            .inv-field.is-full { grid-column: span 1; }
            .inv-segment { width: 100%; }
            .inv-seg { flex: 1; justify-content: center; padding: 0 8px; }
        }
    </style>
    <script>
        (function () {
            const $filter = document.getElementById('tkcFilter');
            if (!$filter) return;

            // ---------- Bộ lọc: tự lọc, không có nút "Lọc" ----------
            $filter.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => $filter.submit());
            });

            // Ô chọn nhiều chi nhánh: tick xong lọc ngay, danh sách KHÔNG tự đóng —
            // người ta thường tick liền mấy kho, đóng sau mỗi ô thì phải mở lại.
            $filter.querySelectorAll('[data-multi]').forEach((box) => {
                const btn = box.querySelector('.tkc-multi-btn');
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    box.classList.toggle('open');
                });
                box.addEventListener('change', () => $filter.submit());
            });
            document.addEventListener('click', (e) => {
                $filter.querySelectorAll('[data-multi]').forEach((box) => {
                    if (!box.contains(e.target)) box.classList.remove('open');
                });
            });

            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // Nút "Nâng cao": ẩn/hiện hàng bộ lọc phụ, nhớ lựa chọn qua localStorage.
            (function () {
                const btn = document.getElementById('tkcAdvToggle');
                const row = document.getElementById('tkcAdvRow');
                if (!btn || !row) return;
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('tkc-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('tkc-adv-open', open ? '1' : '0');
                });
            })();

            // ---------- Gập / mở từng chi nhánh ----------
            // Nhớ theo id chi nhánh trong localStorage: người quản kho thường chỉ
            // theo dõi một hai kho, gập những kho còn lại rồi lật trang mà chúng mở
            // lại hết thì lần nào cũng phải gập lại từ đầu.
            const KHOA = 'tkc-closed';
            const dong = new Set(JSON.parse(localStorage.getItem(KHOA) || '[]').map(Number));

            function apDung(id, closed) {
                document.querySelectorAll(`tr.tkc-row[data-cn="${id}"]`).forEach((tr) => {
                    tr.style.display = closed ? 'none' : '';
                });
                const head = document.querySelector(`tr.tkc-group[data-cn="${id}"]`);
                if (head) head.classList.toggle('is-closed', closed);
            }

            document.querySelectorAll('[data-toggle-cn]').forEach((btn) => {
                const id = Number(btn.dataset.toggleCn);
                if (dong.has(id)) apDung(id, true);
                btn.addEventListener('click', () => {
                    const closed = !dong.has(id);
                    if (closed) { dong.add(id); } else { dong.delete(id); }
                    localStorage.setItem(KHOA, JSON.stringify([...dong]));
                    apDung(id, closed);
                });
            });
        })();
        (function () {
            const $ = (id) => document.getElementById(id);
            const nf = new Intl.NumberFormat('vi-VN');
            const money = (n) => nf.format(Number(n) || 0) + '₫';
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            const LOW = {{ $low }};
            const TX_TYPES = @json($TX_TYPES);
            const TX_SOURCES = @json($TX_SOURCES);

            // ---------- Bộ lọc realtime ----------
            // Không có nút "Áp dụng": đổi select là lọc ngay, gõ tìm kiếm thì chờ
            // 400ms cho gõ xong rồi mới lọc (đúng cách trang Khách hàng/Sản phẩm làm).
            const filterForm = $('invFilter');
            filterForm.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => filterForm.submit());
            });
            const search = filterForm.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => filterForm.submit(), 400);
            });
            // Bấm Enter thì lọc ngay, không phải chờ nốt quãng debounce đang chạy.
            search.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') clearTimeout(searchTimer);
            });

            // ---------- Bộ lọc nâng cao ----------
            const advBtn = $('invAdvToggle'), advRow = $('invAdvRow');
            if (advBtn) {
                advBtn.addEventListener('click', () => {
                    const open = advRow.classList.toggle('is-open');
                    advBtn.classList.toggle('is-open', open);
                    advBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            }

            // ---------- Menu tiện ích ----------
            const util = $('invUtil');
            if (util) {
                $('invUtilBtn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    const open = util.classList.toggle('open');
                    $('invUtilBtn').setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                document.addEventListener('click', () => util.classList.remove('open'));
            }

            // ---------- Chọn dòng + thanh bulk ----------
            const checkAll = $('invCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.inv-row-check'));
            const selected = () => rowChecks().filter((c) => c.checked);
            const bulkBar = $('invBulkBar');

            function syncBulk() {
                const sel = selected();
                bulkBar.hidden = sel.length === 0;
                $('invBulkCount').textContent = nf.format(sel.length);
                rowChecks().forEach((c) => c.closest('tr').classList.toggle('is-selected', c.checked));
                if (checkAll) {
                    const all = rowChecks();
                    checkAll.checked = all.length > 0 && sel.length === all.length;
                    checkAll.indeterminate = sel.length > 0 && sel.length < all.length;
                }
            }

            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((c) => { c.checked = checkAll.checked; });
                    syncBulk();
                });
            }
            rowChecks().forEach((c) => c.addEventListener('change', syncBulk));
            const bulkClear = $('invBulkClear');
            if (bulkClear) {
                bulkClear.addEventListener('click', () => {
                    rowChecks().forEach((c) => { c.checked = false; });
                    syncBulk();
                });
            }
            syncBulk();

            // Phiếu in mở tab mới (tab hiện tại giữ nguyên lựa chọn để còn chỉnh tiếp),
            // khác Xuất CSV — tải file thì ở lại cùng tab.
            const bulkPrint = $('invBulkPrint');
            if (bulkPrint) {
                bulkPrint.addEventListener('click', () => {
                    const rows = selected().map((c) => c.value).join(',');
                    if (!rows) return;
                    // Mang theo cả bộ lọc đang áp: phiếu in phải khớp đúng danh sách
                    // trên màn hình, không phải toàn kho.
                    const url = new URL(@json(route('admin.ton-kho-chi-nhanh.stocktake')), window.location.origin);
                    url.search = window.location.search;
                    url.searchParams.set('rows', rows);
                    window.open(url.toString(), '_blank', 'noopener');
                });
            }

            const bulkExport = $('invBulkExport');
            if (bulkExport) {
                bulkExport.addEventListener('click', () => {
                    const rows = selected().map((c) => c.value).join(',');
                    if (!rows) return;
                    const url = new URL(@json(route('admin.ton-kho-chi-nhanh.export')), window.location.origin);
                    url.search = window.location.search;
                    url.searchParams.set('rows', rows);
                    window.location.href = url.toString();
                });
            }

            // ---------- Modal chỉnh tồn kho ----------
            // Một modal dùng cho cả hai luồng: chỉnh 1 biến thể (PUT theo id) và
            // chỉnh hàng loạt (POST kèm danh sách id). Chỉ khác action + danh sách id,
            // phần nhập liệu giống hệt nhau nên không tách làm hai khối.
            const adjOverlay = $('invAdjustOverlay');
            const adjForm = $('invAdjustForm');
            const qtyInput = $('invAdjustQty');
            const BULK_ACTION = @json(route('admin.ton-kho-chi-nhanh.bulkAdjust'));
            const SINGLE_ACTION = @json(route('admin.ton-kho-chi-nhanh.adjust', ['id' => 0]));

            const OP_TEXT = {
                set: {
                    hint: 'Đặt lại tồn kho về đúng số đếm được trên kệ.',
                    label: 'Số lượng kiểm kê',
                    submit: 'Lưu số kiểm kê',
                },
                import: {
                    hint: 'Cộng thêm số lượng vừa nhập về vào tồn hiện tại.',
                    label: 'Số lượng nhập thêm',
                    submit: 'Nhập kho',
                },
                export: {
                    hint: 'Trừ bớt số lượng xuất khỏi kho (hàng hỏng, xuất mẫu…).',
                    label: 'Số lượng xuất bớt',
                    submit: 'Xuất kho',
                },
            };

            // state.rows: các cặp (kho, biến thể) đang chỉnh; state.current: tồn hiện tại
            // (chỉ có nghĩa khi chỉnh đúng 1 dòng — chỉnh nhiều dòng thì mỗi dòng một số).
            let state = { op: 'set', ids: [], current: null };

            const qtyDigits = () => qtyInput.value.replace(/\D/g, '');
            function qtyValue() {
                const digits = qtyDigits();
                return digits ? parseInt(digits, 10) : 0;
            }

            function renderPreview() {
                const q = qtyValue();
                const box = $('invAdjustPreview');
                // Ô để trống = chưa nhập gì; nhập/xuất 0 sản phẩm cũng là thao tác rỗng.
                // Cả hai đều khoá nút Lưu để không ghi một bút toán chẳng đổi gì vào sổ.
                const blank = qtyDigits() === '';
                const emptyOp = blank || (state.op !== 'set' && q === 0);

                if (state.current === null) {
                    // Chỉnh nhiều dòng: mỗi biến thể một tồn khác nhau nên không xem trước
                    // được một con số chung; phần tồn âm để server chặn (cả lô bị huỷ).
                    box.textContent = blank ? '—' : 'Áp cho từng biến thể';
                    box.classList.remove('is-bad');
                    $('invAdjustSubmit').disabled = emptyOp;
                    return;
                }

                if (blank) {
                    box.textContent = '—';
                    box.classList.remove('is-bad');
                    $('invAdjustSubmit').disabled = true;
                    return;
                }

                const after = state.op === 'set' ? q : (state.op === 'import' ? state.current + q : state.current - q);
                box.textContent = nf.format(after);
                // Xuất quá số đang có: báo ngay tại chỗ thay vì để server trả lỗi.
                box.classList.toggle('is-bad', after < 0);
                $('invAdjustSubmit').disabled = after < 0 || emptyOp;
            }

            function setOp(op) {
                state.op = op;
                document.querySelectorAll('#invAdjustOps .inv-seg').forEach((b) => {
                    b.classList.toggle('is-on', b.dataset.op === op);
                });
                $('invAdjustHint').textContent = OP_TEXT[op].hint;
                $('invAdjustQtyLb').innerHTML = OP_TEXT[op].label + ' <span class="inv-req">*</span>';
                $('invAdjustSubmit').textContent = OP_TEXT[op].submit;
                $('invAdjustMode').value = op === 'set' ? 'set' : 'delta';
                renderPreview();
            }

            document.querySelectorAll('#invAdjustOps .inv-seg').forEach((b) => {
                b.addEventListener('click', () => setOp(b.dataset.op));
            });

            // Ô số lượng: hiển thị phân nhóm nghìn kiểu VN, gửi đi số thô ở ô ẩn.
            qtyInput.addEventListener('input', () => {
                const n = qtyValue();
                qtyInput.value = qtyInput.value.replace(/\D/g, '') ? nf.format(n) : '';
                renderPreview();
            });
            function openAdjust(opts) {
                state = { op: 'set', rows: opts.rows, current: opts.current };
                $('invAdjustTitle').textContent = opts.title;
                $('invAdjustName').textContent = opts.name;
                $('invAdjustSub').textContent = opts.sub;
                $('invAdjustNote').value = '';
                // Luôn mở ra với ô trống — người dùng tự gõ con số họ vừa đếm được.
                qtyInput.value = '';
                const now = $('invAdjustNow');
                now.hidden = opts.current === null;
                if (opts.current !== null) {
                    now.innerHTML = 'Đang còn trong kho: <b>' + nf.format(opts.current) + '</b> sản phẩm';
                }

                // Mỗi phần tử của opts.rows là "chi nhánh:biến thể".
                // 1 dòng -> PUT /inventory/{biến thể} kèm shop_id;
                // nhiều dòng -> POST bulk, mỗi dòng tự mang kho của nó.
                if (opts.rows.length === 1) {
                    const [shop, variant] = String(opts.rows[0]).split(':');
                    adjForm.action = SINGLE_ACTION.replace(/\/0$/, '/' + variant);
                    $('invAdjustMethod').value = 'PUT';
                    $('invAdjustShop').value = shop;
                    $('invAdjustIds').innerHTML = '';
                } else {
                    adjForm.action = BULK_ACTION;
                    $('invAdjustMethod').value = 'POST';
                    $('invAdjustShop').value = '';
                    $('invAdjustIds').innerHTML = opts.rows
                        .map((r) => '<input type="hidden" name="rows[]" value="' + esc(r) + '">').join('');
                }

                setOp('set');
                adjOverlay.style.display = 'flex';
                setTimeout(() => qtyInput.focus(), 30);
            }

            // Ô nhập luôn là số dương (người dùng gõ "5 cái"); dấu do thao tác quyết định:
            // xuất bớt gửi số âm, nhập thêm và kiểm kê gửi số dương.
            adjForm.addEventListener('submit', () => {
                const q = qtyValue();
                $('invAdjustQtyRaw').value = state.op === 'export' ? -q : q;
            });

            document.querySelectorAll('[data-adjust]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    openAdjust({
                        rows: [btn.dataset.adjust],
                        current: parseInt(btn.dataset.qty, 10) || 0,
                        title: 'Chỉnh tồn kho',
                        name: btn.dataset.name || '—',
                        sub: [btn.dataset.sku, btn.dataset.variant].filter(Boolean).join(' · '),
                    });
                });
            });

            const bulkAdjustBtn = $('invBulkAdjust');
            if (bulkAdjustBtn) {
                bulkAdjustBtn.addEventListener('click', () => {
                    const sel = selected();
                    if (!sel.length) return;
                    if (sel.length === 1) {
                        const c = sel[0];
                        openAdjust({
                            rows: [c.value],
                            current: parseInt(c.dataset.qty, 10) || 0,
                            title: 'Chỉnh tồn kho',
                            name: c.dataset.name || '—',
                            sub: c.dataset.sku || '',
                        });
                        return;
                    }
                    openAdjust({
                        rows: sel.map((c) => c.value),
                        current: null,
                        title: 'Chỉnh tồn kho hàng loạt',
                        name: sel.length + ' biến thể đã chọn',
                        sub: 'Cùng một thao tác được áp cho tất cả. Một dòng lỗi thì cả lô bị huỷ, kho giữ nguyên.',
                    });
                });
            }

            // ---------- Modal nhập file CSV ----------
            const impOverlay = $('invImportOverlay');

            const IMPORT_HINT = {
                set: 'Đặt lại tồn kho về đúng số đếm được trong file.',
                delta: 'Cộng số trong file vào tồn hiện tại; số âm là xuất bớt.',
            };

            // Chỉ áp cho những dòng KHÔNG tự khai cột mode — nói rõ để người dùng
            // không tưởng lựa chọn ở đây ghi đè cả file.
            $('invImportOps').addEventListener('click', (e) => {
                const btn = e.target.closest('[data-mode]');
                if (!btn) return;
                $('invImportOps').querySelectorAll('.inv-seg')
                    .forEach((b) => b.classList.toggle('is-on', b === btn));
                $('invImportMode').value = btn.dataset.mode;
                $('invImportHint').textContent = IMPORT_HINT[btn.dataset.mode] || '';
            });

            $('invImportBtn').addEventListener('click', () => {
                if (util) util.classList.remove('open');
                impOverlay.style.display = 'flex';
            });

            // ---------- Modal khai giá vốn ----------
            const costOverlay = $('invCostOverlay');
            $('invCostBtn').addEventListener('click', () => {
                if (util) util.classList.remove('open');
                costOverlay.style.display = 'flex';
            });

            // ---------- Đóng modal ----------
            [adjOverlay, impOverlay, costOverlay, viewOverlay].forEach((ov) => {
                ov.addEventListener('click', (e) => {
                    if (e.target.closest('[data-close]')) ov.style.display = 'none';
                });
            });
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                [adjOverlay, impOverlay, costOverlay, viewOverlay].forEach((ov) => { ov.style.display = 'none'; });
            });
        })();
    </script>
@endsection
