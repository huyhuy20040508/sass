@extends('layouts.app')

@section('title', \App\Http\Controllers\InventoryController::TITLE)

@section('content')
    {{--
    Trang "Tồn kho" — cùng khuôn với trang Trả hàng / Đơn hàng:
    [ header + số liệu ] + [ form lọc ] + [ bảng compact ] + [ chân trang ] + [ modal ].

    Đơn vị của bảng là BIẾN THỂ (size/màu/phiên bản), không phải sản phẩm: tồn kho
    nằm ở từng biến thể nên gộp về mức sản phẩm sẽ giấu mất đúng thứ cần thấy.

    Mọi thay đổi tồn kho đều đi qua Go API để luôn có bút toán trong sổ kho kèm
    người thực hiện — trang này không tự sửa con số tồn ở bất kỳ chỗ nào.
    --}}
    @php
        $STOCK_STATES = \App\Http\Controllers\InventoryController::STOCK_STATES;
        $STOCK_TONES = \App\Http\Controllers\InventoryController::STOCK_TONES;
        $SORTS = \App\Http\Controllers\InventoryController::SORTS;
        $ACTIVE_STATES = \App\Http\Controllers\InventoryController::ACTIVE_STATES;
        $COST_STATES = \App\Http\Controllers\InventoryController::COST_STATES;
        $TX_TYPES = \App\Http\Controllers\InventoryController::TX_TYPES;
        $TX_SOURCES = \App\Http\Controllers\InventoryController::TX_SOURCES;
        $LOW_OPTIONS = \App\Http\Controllers\InventoryController::LOW_STOCK_OPTIONS;
        $PAGE_SIZES = \App\Http\Controllers\InventoryController::PAGE_SIZES;

        $TITLE = \App\Http\Controllers\InventoryController::TITLE;
        $EMPTY_TEXT = \App\Http\Controllers\InventoryController::EMPTY_TEXT;

        // Loại áo của sản phẩm cha — API trả sẵn kèm mỗi dòng kho.
        $KIT_TYPES = \App\Http\Controllers\ProductController::KIT_TYPES;

        $low = $filters['low_stock'];
        // Ngưỡng đang cấu hình (trang Cài đặt) có thể không nằm trong danh sách chọn
        // sẵn — phải chèn vào, nếu không select hiện một mức khác với mức đang lọc.
        $LOW_OPTIONS = collect($LOW_OPTIONS)->push($low)->unique()->sort()->values()->all();
        $firstRank = ($meta['page'] - 1) * $meta['page_size'];

        // Số bộ lọc nâng cao đang bật -> tự mở hàng nâng cao + hiện badge.
        $advCount = ($filters['category_id'] > 0 ? 1 : 0)
            + ($filters['brand_id'] > 0 ? 1 : 0)
            + ($filters['cost'] !== 'all' ? 1 : 0)
            + ($filters['is_active'] !== '' ? 1 : 0)
            + ($filters['sort'] !== 'stock_asc' ? 1 : 0);
        $advOpen = $advCount > 0;

        // Tổng số điều kiện đang lọc (cả hàng chính lẫn hàng nâng cao) — nút "Xoá lọc"
        // hiện con số này để người dùng biết mình đang bị bao nhiêu điều kiện chắn.
        // Ngưỡng "sắp hết" KHÔNG tính vào đây: nó là cách đọc bảng, không phải bộ lọc.
        $filterCount = $advCount
            + ($filters['keyword'] !== '' ? 1 : 0)
            + ($filters['stock'] !== 'all' ? 1 : 0);
        $hasFilter = $filterCount > 0;

        // Link lọc nhanh cho các con số ở header: bấm vào số là xem đúng nhóm đó.
        $stateLink = fn ($state) => request()->fullUrlWithQuery(['stock' => $state, 'page' => 1]);
        // Cảnh báo thiếu giá vốn phải bấm được: biết thiếu bao nhiêu mà không liệt kê
        // ra được thì con số đó chẳng làm gì được.
        $missingCost = (int) ($stats['missing_cost'] ?? 0);
        $missingInStock = (int) ($stats['missing_cost_in_stock'] ?? 0);
        $costLink = route('admin.inventory.index', ['cost' => 'missing', 'stock' => 'all']);
    @endphp

    <div class="inv">
        {{-- Header: tên trang + số liệu toàn kho (không phụ thuộc bộ lọc đang áp) --}}
        <div class="inv-head">
            <h1 class="inv-title">{{ $TITLE }}</h1>
            <div class="inv-sum">
                <a href="{{ $stateLink('all') }}" class="inv-sum-item {{ $filters['stock'] === 'all' ? 'is-on' : '' }}">
                    <span class="inv-sum-lb">Tổng biến thể</span>
                    <b>{{ number_format($stats['total_variants'], 0, ',', '.') }}</b>
                </a>
                <a href="{{ $stateLink('in') }}" class="inv-sum-item tone-done {{ $filters['stock'] === 'in' ? 'is-on' : '' }}">
                    <span class="inv-sum-lb">Còn hàng</span>
                    <b>{{ number_format($stats['in_stock'], 0, ',', '.') }}</b>
                </a>
                <a href="{{ $stateLink('low') }}" class="inv-sum-item tone-wait {{ $filters['stock'] === 'low' ? 'is-on' : '' }}">
                    <span class="inv-sum-lb">Sắp hết (≤ {{ $low }})</span>
                    <b>{{ number_format($stats['low_stock'], 0, ',', '.') }}</b>
                </a>
                <a href="{{ $stateLink('out') }}" class="inv-sum-item tone-stop {{ $filters['stock'] === 'out' ? 'is-on' : '' }}">
                    <span class="inv-sum-lb">Hết hàng</span>
                    <b>{{ number_format($stats['out_of_stock'], 0, ',', '.') }}</b>
                </a>
                {{-- Giá trị kho tính theo GIÁ VỐN, không phải giá bán: đó mới là giá trị
                     tồn kho về kế toán. Biến thể chưa khai giá vốn không được cộng vào,
                     nên phải nói ra số dòng còn thiếu — im lặng thì con số này trông
                     như đã đủ trong khi nó đang hụt. --}}
                @if($missingCost > 0)
                    <a href="{{ $costLink }}" class="inv-sum-item is-warn {{ $filters['cost'] === 'missing' ? 'is-on' : '' }}"
                        title="{{ number_format($missingCost, 0, ',', '.') }} biến thể chưa khai giá vốn{{ $missingInStock > 0 ? ', trong đó '.number_format($missingInStock, 0, ',', '.').' đang còn hàng nên tổng bên trái đang thiếu' : ' (đều đang hết hàng nên chưa ảnh hưởng tổng)' }}. Bấm để xem danh sách.">
                        <span class="inv-sum-lb">Giá trị vốn tồn kho</span>
                        <b>{{ number_format((float) $stats['stock_value'], 0, ',', '.') }}₫</b>
                        <span class="inv-sum-warn">
                            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
                                <path d="M12 9v4M12 17h.01" />
                            </svg>
                            {{ number_format($missingCost, 0, ',', '.') }} chưa khai giá vốn
                        </span>
                    </a>
                @else
                    <span class="inv-sum-item is-static">
                        <span class="inv-sum-lb">Giá trị vốn tồn kho</span>
                        <b>{{ number_format((float) $stats['stock_value'], 0, ',', '.') }}₫</b>
                    </span>
                @endif
            </div>
        </div>

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.inventory.index') }}" id="invFilter" class="inv-filter">
            <div class="inv-toolbar">
                <div class="inv-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="inv-search-input"
                        placeholder="Tìm theo tên sản phẩm hoặc mã SKU" autocomplete="off">
                    {{-- Nút tìm giữ lại cho người bấm Enter/chuột, nhưng gõ xong là tự lọc sau 400ms --}}
                    <button type="submit" class="inv-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8" />
                            <path d="m21 21-4.3-4.3" />
                        </svg>
                    </button>
                </div>

                <select name="stock" class="inv-select" title="Lọc theo mức tồn">
                    <option value="all" {{ $filters['stock'] === 'all' ? 'selected' : '' }}>Tất cả mức tồn</option>
                    @foreach($STOCK_STATES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['stock'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="low_stock" class="inv-select inv-select-sm" title="Ngưỡng cảnh báo sắp hết">
                    @foreach($LOW_OPTIONS as $n)
                        <option value="{{ $n }}" {{ $low === $n ? 'selected' : '' }}>Ngưỡng ≤ {{ $n }}</option>
                    @endforeach
                </select>

                <button type="button" id="invAdvToggle" class="inv-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                    aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 6h18M7 12h10M11 18h2" />
                    </svg>
                    Nâng cao
                    <svg class="inv-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                    @if($advCount > 0)<span class="inv-adv-count">{{ $advCount }}</span>@endif
                </button>

                {{-- Xoá lọc nằm ở hàng CHÍNH, không giấu trong hàng nâng cao: lọc bằng
                     ô "mức tồn" hay ô tìm kiếm ở ngay đây mà nút gỡ lọc lại nằm trong
                     hàng đang đóng thì người dùng không tìm ra đường quay về. --}}
                @if($hasFilter)
                    <a href="{{ route('admin.inventory.index') }}" class="inv-clear" title="Bỏ toàn bộ điều kiện lọc">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6 6 18M6 6l12 12" />
                        </svg>
                        Xoá lọc
                        <span class="inv-clear-count">{{ $filterCount }}</span>
                    </a>
                @endif

                <div class="inv-toolbar-actions">
                    <a href="{{ route('admin.products.index') }}" class="inv-btn-ghost">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                            <path d="m3.3 7 8.7 5 8.7-5M12 22V12" />
                        </svg>
                        Quản lý sản phẩm
                    </a>

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
                            <a href="{{ route('admin.inventory.export', request()->query()) }}" class="inv-util-item">
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
                            <a href="{{ route('admin.inventory.stocktake', request()->query()) }}" class="inv-util-item"
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

            {{-- Hàng nâng cao: danh mục + thương hiệu + trạng thái bán + sắp xếp --}}
            <div class="inv-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="invAdvRow">
                <select name="category_id" class="inv-select" title="Lọc theo danh mục">
                    <option value="">Tất cả danh mục</option>
                    @foreach($categories as $c)
                        <option value="{{ $c['id'] }}" {{ $filters['category_id'] === (int) $c['id'] ? 'selected' : '' }}>
                            {{ $c['name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="brand_id" class="inv-select" title="Lọc theo thương hiệu">
                    <option value="">Tất cả thương hiệu</option>
                    @foreach($brands as $b)
                        <option value="{{ $b['id'] }}" {{ $filters['brand_id'] === (int) $b['id'] ? 'selected' : '' }}>
                            {{ $b['name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="cost" class="inv-select" title="Lọc theo tình trạng khai giá vốn">
                    <option value="">Tất cả giá vốn</option>
                    @foreach($COST_STATES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['cost'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="is_active" class="inv-select" title="Lọc theo trạng thái bán">
                    <option value="">Tất cả trạng thái bán</option>
                    @foreach($ACTIVE_STATES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['is_active'] === (string) $value ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="inv-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

            </div>

            {{-- Giữ số dòng/trang khi đổi bộ lọc --}}
            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Thanh thao tác hàng loạt NỔI (hiện khi chọn ≥1 biến thể) --}}
        <div class="inv-bulk" id="invBulkBar" hidden>
            <span class="inv-bulk-count"><b id="invBulkCount">0</b> biến thể đã chọn</span>
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
        <div class="inv-table-wrap">
            <table class="inv-table">
                <thead>
                    <tr>
                        <th class="inv-c-check">
                            <input type="checkbox" id="invCheckAll" class="inv-check" title="Chọn tất cả trong trang">
                        </th>
                        <th class="inv-c-stt">STT</th>
                        <th class="inv-c-img"></th>
                        <th class="inv-c-name">Sản phẩm</th>
                        <th class="inv-c-variant">Phân loại</th>
                        <th class="inv-c-group">Danh mục</th>
                        <th class="inv-c-qty">Tồn kho</th>
                        <th class="inv-c-price">Giá bán</th>
                        <th class="inv-c-value">Giá trị vốn</th>
                        <th class="inv-c-status">Mức tồn</th>
                        <th class="inv-c-date">Phát sinh cuối</th>
                        <th class="inv-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $i => $it)
                        @php
                            $vid = (int) ($it['variant_id'] ?? 0);
                            $qty = (int) ($it['stock_quantity'] ?? 0);
                            $state = \App\Http\Controllers\InventoryController::stockState($qty, $low);
                            $variantParts = array_filter([
                                $KIT_TYPES[$it['kit_type'] ?? ''] ?? '',
                                $it['size'] ?? '',
                                $it['color'] ?? '',
                            ]);
                        @endphp
                        <tr data-id="{{ $vid }}">
                            <td class="inv-c-check">
                                <input type="checkbox" class="inv-check inv-row-check" value="{{ $vid }}"
                                    data-name="{{ $it['product_name'] ?? '' }}" data-sku="{{ $it['sku'] ?? '' }}"
                                    data-qty="{{ $qty }}" aria-label="Chọn biến thể {{ $it['sku'] ?? $vid }}">
                            </td>
                            <td class="inv-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="inv-c-img" data-view="{{ $vid }}" title="Xem sổ kho">
                                @if(!empty($it['thumbnail']))
                                    <img class="inv-thumb" src="{{ $it['thumbnail'] }}" alt="" loading="lazy">
                                @else
                                    <span class="inv-thumb inv-thumb-empty">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                    </span>
                                @endif
                            </td>
                            <td class="inv-c-name" data-view="{{ $vid }}" title="{{ $it['product_name'] ?? '' }}">
                                <span class="inv-name">{{ $it['product_name'] ?? '—' }}</span>
                                <span class="inv-sub">
                                    {{ $it['sku'] ?? '—' }}
                                    @if(empty($it['product_active']))
                                        · <span class="inv-off">Sản phẩm đang ẩn</span>
                                    @elseif(empty($it['is_active']))
                                        · <span class="inv-off">Biến thể ngừng bán</span>
                                    @endif
                                </span>
                            </td>
                            <td class="inv-c-variant">
                                {{ count($variantParts) ? implode(' / ', $variantParts) : '—' }}
                            </td>
                            <td class="inv-c-group">
                                <span class="inv-name">{{ $it['category_name'] ?: '—' }}</span>
                                <span class="inv-sub">{{ $it['brand_name'] ?: '—' }}</span>
                            </td>
                            <td class="inv-c-qty">
                                <span class="inv-qty is-{{ $state }}">{{ number_format($qty, 0, ',', '.') }}</span>
                            </td>
                            <td class="inv-c-price">{{ number_format((float) ($it['price'] ?? 0), 0, ',', '.') }}₫</td>
                            {{-- Chưa khai giá vốn thì hiện gạch ngang chứ không phải 0₫: 0₫ là
                                 một con số, gạch ngang là "chưa biết" — hai chuyện khác nhau. --}}
                            <td class="inv-c-value">
                                @if(($it['cost_price'] ?? null) === null)
                                    <span class="inv-nocost" title="Chưa khai giá vốn cho sản phẩm này">—</span>
                                @else
                                    <span class="inv-total">{{ number_format((float) ($it['stock_value'] ?? 0), 0, ',', '.') }}₫</span>
                                    <span class="inv-sub">vốn {{ number_format((float) $it['cost_price'], 0, ',', '.') }}₫</span>
                                @endif
                            </td>
                            <td class="inv-c-status">
                                <span class="inv-badge tone-{{ $STOCK_TONES[$state] }}">{{ $STOCK_STATES[$state] }}</span>
                            </td>
                            <td class="inv-c-date">
                                {{ !empty($it['last_moved_at']) ? \Illuminate\Support\Carbon::parse($it['last_moved_at'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="inv-c-act">
                                <button type="button" class="inv-rowbtn" data-adjust="{{ $vid }}"
                                    data-name="{{ $it['product_name'] ?? '' }}" data-sku="{{ $it['sku'] ?? '' }}"
                                    data-variant="{{ implode(' / ', $variantParts) }}" data-qty="{{ $qty }}"
                                    title="Nhập / xuất / kiểm kê">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 7h18M3 12h18M3 17h18" />
                                        <path d="M8 4v6M16 14v6" />
                                    </svg>
                                </button>
                                <button type="button" class="inv-rowbtn" data-view="{{ $vid }}" title="Xem sổ kho">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                                        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20" />
                                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z" />
                                        <path d="M8 7h8M8 11h5" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="inv-empty">
                                @if($hasFilter)
                                    Không tìm thấy biến thể nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'biến thể',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- ===== Modal chỉnh tồn kho (dùng chung cho 1 dòng và hàng loạt) ===== --}}
    <div class="inv-overlay" id="invAdjustOverlay" style="display:none;">
        <div class="inv-dialog inv-dialog-sm">
            <form method="POST" action="{{ route('admin.inventory.bulkAdjust') }}" id="invAdjustForm">
                @csrf
                <input type="hidden" name="_method" value="POST" id="invAdjustMethod">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
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
            <form method="POST" action="{{ route('admin.inventory.import') }}" enctype="multipart/form-data">
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
                        <br><a href="{{ route('admin.inventory.importTemplate') }}">Tải file mẫu</a>
                    </p>

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
            <form method="POST" action="{{ route('admin.inventory.importCost') }}" enctype="multipart/form-data">
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
                        <br><a href="{{ route('admin.inventory.importCostTemplate') }}">Tải file mẫu</a>
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

    {{-- ===== Modal sổ kho của một biến thể ===== --}}
    <div class="inv-overlay" id="invViewOverlay" style="display:none;">
        <div class="inv-dialog">
            <div class="inv-modal-head">
                <h4 class="inv-modal-title">Sổ kho biến thể</h4>
                <button type="button" class="inv-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="inv-modal-body">
                {{-- Định danh: đang xem kho của đúng biến thể nào --}}
                <div class="inv-view-head">
                    <div class="inv-view-ident">
                        <span class="inv-view-name" id="vName">—</span>
                        <span class="inv-view-sku" id="vSku">—</span>
                    </div>
                    <span class="inv-badge" id="vState">—</span>
                </div>

                {{-- Bốn con số quan trọng nhất, để to cho đọc lướt là thấy.
                     Có cả giá bán lẫn giá vốn thì con số "giá trị vốn" bên cạnh mới
                     giải thích được — thiếu giá vốn, người xem sẽ nhân nhẩm theo giá
                     bán rồi tưởng hệ thống tính sai. --}}
                <div class="inv-kpis">
                    <div class="inv-kpi">
                        <span class="inv-kpi-lb">Đang còn trong kho</span>
                        <b class="inv-kpi-num" id="vQty">—</b>
                        <span class="inv-kpi-unit">sản phẩm</span>
                    </div>
                    <div class="inv-kpi">
                        <span class="inv-kpi-lb">Giá bán</span>
                        <b class="inv-kpi-num is-sm" id="vPrice">—</b>
                    </div>
                    <div class="inv-kpi">
                        <span class="inv-kpi-lb">Giá vốn</span>
                        <b class="inv-kpi-num is-sm" id="vCost">—</b>
                    </div>
                    <div class="inv-kpi">
                        <span class="inv-kpi-lb">Giá trị vốn đang giữ</span>
                        <b class="inv-kpi-num is-sm" id="vValue">—</b>
                    </div>
                </div>

                <p class="inv-meta-line">
                    Danh mục <b id="vCategory">—</b> · Thương hiệu <b id="vBrand">—</b>
                </p>

                <div class="inv-view-sec">
                    <p class="inv-sec-title">
                        Lịch sử ra vào kho
                        <span class="inv-sec-note" id="vTotal"></span>
                    </p>
                    {{-- Sổ kho dạng BẢNG: mỗi bút toán một dòng, đọc theo cột thay vì
                         một câu chữ chạy dài — nhân viên kho cần dò nhanh "hôm đó tồn
                         còn bao nhiêu", nên cột "Còn lại" phải nằm thẳng hàng. --}}
                    <div class="inv-ledger-wrap">
                        <table class="inv-ledger">
                            <thead>
                                <tr>
                                    <th class="inv-l-time">Thời gian</th>
                                    <th class="inv-l-what">Việc đã làm</th>
                                    <th class="inv-l-delta">Thay đổi</th>
                                    <th class="inv-l-after">Còn lại</th>
                                    <th class="inv-l-ref">Chứng từ</th>
                                    <th class="inv-l-who">Người làm</th>
                                </tr>
                            </thead>
                            <tbody id="vLedger">
                                <tr><td colspan="6" class="inv-ledger-empty">Đang tải…</td></tr>
                            </tbody>
                        </table>
                    </div>
                    {{-- Nạp thêm nối vào cuối bảng chứ không phân trang: sổ kho đọc theo
                         dòng thời gian, nhảy sang trang khác là mất mạch "trước đó tồn
                         còn bao nhiêu" — đúng thứ nhân viên dò lại đang cần. --}}
                    <div class="inv-ledger-more" id="vMoreWrap" hidden>
                        <button type="button" class="inv-btn-ghost" id="vMoreBtn">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="m6 9 6 6 6-6" />
                            </svg>
                            <span id="vMoreText">Xem thêm</span>
                        </button>
                    </div>
                    <p class="inv-hint">
                        Cột “Còn lại” là số tồn ngay sau lần thay đổi đó. Dòng mới nhất nằm trên cùng.
                    </p>
                </div>
            </div>

            <div class="inv-modal-foot">
                <div class="inv-foot-right">
                    <button type="button" class="inv-btn-ghost" data-close>Đóng</button>
                    <button type="button" class="inv-btn-primary" id="vAdjustBtn">Chỉnh tồn kho</button>
                </div>
            </div>
        </div>
    </div>

    <style>
        .inv {
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626;
            background: #fff;
        }

        /* Header */
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

        .inv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .inv-table thead tr { background: #f0f0f0; color: #262626; }
        /* Bảng dữ liệu phải THOÁNG: dòng cao, ô rộng rãi, các cột không dính vào nhau.
           Khoảng thở dồn vào chiều DỌC (16px) vì bảng có 12 cột — nới ngang quá tay
           thì "Thao tác" bị đẩy ra ngoài màn hình, phải cuộn mới bấm được. */
        .inv-table th, .inv-table td { padding: 16px 18px; vertical-align: middle; white-space: nowrap; line-height: 1.5; }
        .inv-table th { font-weight: 700; text-align: left; padding-top: 13px; padding-bottom: 13px; }
        .inv-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .inv-table tbody tr:hover { background: #fafafa; }
        .inv-table tbody tr.is-selected, .inv-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .inv-c-check { width: 38px; text-align: center; }
        .inv-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        .inv-table th.inv-c-stt, .inv-table td.inv-c-stt { width: 1%; text-align: center; color: #8c8c8c; }
        .inv-table th.inv-c-img, .inv-table td.inv-c-img { width: 1%; padding-right: 0; }
        /* Cột "Sản phẩm" co giãn hút hết khoảng dư -> bảng phủ đều chiều ngang.
           min-width giữ cho tên sản phẩm không bị bóp thành vài ký tự khi màn hẹp. */
        .inv-table th.inv-c-name, .inv-table td.inv-c-name { width: 100%; max-width: 0; min-width: 220px; overflow: hidden; }
        .inv-table th.inv-c-variant, .inv-table td.inv-c-variant { width: 1%; color: #595959; }
        .inv-table th.inv-c-group, .inv-table td.inv-c-group { width: 1%; }
        .inv-table th.inv-c-qty, .inv-table td.inv-c-qty { width: 1%; text-align: center; }
        .inv-table th.inv-c-price, .inv-table td.inv-c-price { width: 1%; text-align: right; color: #595959; }
        .inv-table th.inv-c-value, .inv-table td.inv-c-value { width: 1%; text-align: right; }
        .inv-table th.inv-c-status, .inv-table td.inv-c-status { width: 1%; text-align: center; }
        .inv-table th.inv-c-date, .inv-table td.inv-c-date { width: 1%; color: #8c8c8c; }
        .inv-table th.inv-c-act, .inv-table td.inv-c-act { width: 1%; text-align: center; }

        .inv-c-img[data-view], .inv-c-name[data-view] { cursor: pointer; }
        .inv-c-name[data-view]:hover .inv-name { color: #1890ff; text-decoration: underline; }

        .inv-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; border: 1px solid #f0f0f0; display: block; }
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

        .inv-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0; cursor: pointer;
            color: #1890ff; display: inline-flex; align-items: center; justify-content: center; text-decoration: none;
            transition: background .15s;
        }
        .inv-rowbtn:hover { background: #e6f7ff; }
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
            position: sticky; bottom: 0; display: flex; align-items: center; justify-content: space-between; gap: 12px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px; flex-wrap: wrap;
        }
        .inv-foot-right { margin-left: auto; display: flex; gap: 8px; }

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
            const $ = (id) => document.getElementById(id);
            const nf = new Intl.NumberFormat('vi-VN');
            const money = (n) => nf.format(Number(n) || 0) + '₫';
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

            const LOW = {{ $low }};
            const TX_TYPES = @json($TX_TYPES);
            const TX_SOURCES = @json($TX_SOURCES);
            const KIT_TYPES = @json($KIT_TYPES);

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
                    const ids = selected().map((c) => c.value).join(',');
                    if (!ids) return;
                    const url = new URL(@json(route('admin.inventory.stocktake')), window.location.origin);
                    url.searchParams.set('ids', ids);
                    window.open(url.toString(), '_blank', 'noopener');
                });
            }

            const bulkExport = $('invBulkExport');
            if (bulkExport) {
                bulkExport.addEventListener('click', () => {
                    const ids = selected().map((c) => c.value).join(',');
                    if (!ids) return;
                    const url = new URL(@json(route('admin.inventory.export')), window.location.origin);
                    url.searchParams.set('ids', ids);
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
            const BULK_ACTION = @json(route('admin.inventory.bulkAdjust'));
            const SINGLE_ACTION = @json(route('admin.inventory.adjust', ['id' => 0]));

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

            // state.ids: danh sách biến thể đang chỉnh; state.current: tồn hiện tại
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
                state = { op: 'set', ids: opts.ids, current: opts.current };
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

                // 1 dòng -> PUT /inventory/{id}; nhiều dòng -> POST /inventory/bulk-adjust.
                if (opts.ids.length === 1) {
                    adjForm.action = SINGLE_ACTION.replace(/\/0$/, '/' + opts.ids[0]);
                    $('invAdjustMethod').value = 'PUT';
                    $('invAdjustIds').innerHTML = '';
                } else {
                    adjForm.action = BULK_ACTION;
                    $('invAdjustMethod').value = 'POST';
                    $('invAdjustIds').innerHTML = opts.ids
                        .map((id) => '<input type="hidden" name="ids[]" value="' + esc(id) + '">').join('');
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
                        ids: [btn.dataset.adjust],
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
                            ids: [c.value],
                            current: parseInt(c.dataset.qty, 10) || 0,
                            title: 'Chỉnh tồn kho',
                            name: c.dataset.name || '—',
                            sub: c.dataset.sku || '',
                        });
                        return;
                    }
                    openAdjust({
                        ids: sel.map((c) => c.value),
                        current: null,
                        title: 'Chỉnh tồn kho hàng loạt',
                        name: sel.length + ' biến thể đã chọn',
                        sub: 'Cùng một thao tác được áp cho tất cả. Một dòng lỗi thì cả lô bị huỷ, kho giữ nguyên.',
                    });
                });
            }

            // ---------- Modal sổ kho ----------
            const viewOverlay = $('invViewOverlay');
            const DETAIL_URL = @json(route('admin.inventory.detail', ['id' => 0]));
            const HISTORY_URL = @json(route('admin.inventory.history', ['id' => 0]));
            const LEDGER_PAGE = @json(\App\Http\Controllers\InventoryController::LEDGER_PAGE_SIZE);
            let viewing = null;

            // Sổ kho nạp dần: `detail` trả sẵn trang đầu, nút "Xem thêm" nối tiếp các
            // trang sau. `seen` giữ id các dòng đã hiện — kho vẫn chạy trong lúc modal
            // đang mở, một đơn mới đẩy cả sổ dịch xuống và trang sau sẽ trả lại vài
            // dòng vừa xem; lọc theo id để không hiện trùng.
            //
            // `token` tăng mỗi lần mở modal: phản hồi của lần mở trước về muộn sẽ mang
            // token cũ và bị bỏ, không đổ nhầm sổ của biến thể này sang biến thể khác.
            const ledger = { id: 0, token: 0, page: 1, total: 0, loading: false, seen: new Set() };

            function variantText(it) {
                return [KIT_TYPES[it.kit_type] || '', it.size || '', it.color || ''].filter(Boolean).join(' / ') || '—';
            }

            function stateOf(qty) {
                if (qty <= 0) return { key: 'out', label: 'Hết hàng', tone: 'stop' };
                return qty <= LOW ? { key: 'low', label: 'Sắp hết', tone: 'wait' } : { key: 'in', label: 'Còn hàng', tone: 'done' };
            }

            // Chứng từ đọc thành câu tiếng Việt thay vì để trơ mã: "Đơn FB2026…" rõ
            // hơn hẳn "order · FB2026…" khi nhân viên dò lại một lần xuất kho.
            function refText(h) {
                const code = h.reference_code ? esc(h.reference_code) : '';
                switch (h.reference_type) {
                    case 'order':
                        return code ? 'Đơn ' + code : 'Đơn hàng (đã xoá)';
                    case 'order_return':
                        return code ? 'Phiếu trả ' + code : 'Phiếu trả hàng';
                    case 'supplier':
                        return code ? 'Nhà cung cấp ' + code : 'Nhà cung cấp';
                    case 'manual':
                        return 'Nhân viên tự chỉnh';
                    default:
                        return code || '—';
                }
            }

            // Ngày và giờ tách hai dòng: cột thời gian hẹp, để một dòng sẽ đẩy bảng
            // rộng ra và các cột số phía sau mất chỗ.
            function whenText(iso) {
                if (!iso) return '—';
                const d = new Date(iso);
                const p = (n) => String(n).padStart(2, '0');
                return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()}`
                    + `<span class="inv-l-hour">${p(d.getHours())}:${p(d.getMinutes())}</span>`;
            }

            function renderHistories(rows) {
                const box = $('vLedger');
                if (!rows || !rows.length) {
                    box.innerHTML = '<tr><td colspan="6" class="inv-ledger-empty">'
                        + 'Biến thể này chưa từng có hàng ra vào kho.</td></tr>';
                    return;
                }
                box.innerHTML = ledgerRows(rows);
            }

            function ledgerRows(rows) {
                return rows.map((h) => {
                    const q = Number(h.quantity) || 0;
                    const sign = q > 0 ? '+' : (q < 0 ? '−' : '');
                    return `<tr>
                        <td class="inv-l-time">${whenText(h.created_at)}</td>
                        <td class="inv-l-what">
                            <span class="inv-l-type">${esc(TX_TYPES[h.type] || h.type)}</span>
                            ${h.note ? `<span class="inv-l-note">${esc(h.note)}</span>` : ''}
                        </td>
                        <td class="inv-l-delta ${q >= 0 ? 'is-up' : 'is-down'}">${sign}${nf.format(Math.abs(q))}</td>
                        <td class="inv-l-after">${nf.format(h.quantity_after)}</td>
                        <td class="inv-l-ref">${refText(h)}</td>
                        <td class="inv-l-who">${h.created_by_name ? esc(h.created_by_name) : '—'}</td>
                    </tr>`;
                }).join('');
            }

            /** Cập nhật dòng đếm và nút "Xem thêm" theo số bút toán đã nạp. */
            function renderLedgerMeta() {
                const shown = ledger.seen.size;
                const left = Math.max(0, ledger.total - shown);

                $('vTotal').textContent = left > 0
                    ? `— đang xem ${nf.format(shown)} / ${nf.format(ledger.total)} lần`
                    : (ledger.total > 0 ? `— tất cả ${nf.format(ledger.total)} lần` : '');

                $('vMoreWrap').hidden = left === 0;
                if (left === 0) return;
                $('vMoreBtn').disabled = ledger.loading;
                $('vMoreText').textContent = ledger.loading
                    ? 'Đang tải…'
                    : `Xem thêm ${nf.format(Math.min(left, LEDGER_PAGE))} lần trước đó`;
            }

            /** Nạp trang sổ kho tiếp theo và nối vào cuối bảng. */
            function loadMoreLedger() {
                if (ledger.loading || !ledger.id || ledger.seen.size >= ledger.total) return;
                ledger.loading = true;
                renderLedgerMeta();

                const url = HISTORY_URL.replace(/\/0\/history$/, '/' + ledger.id + '/history')
                    + '?page=' + (ledger.page + 1);
                const token = ledger.token;

                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then((r) => r.ok ? r.json() : Promise.reject(r))
                    .then((res) => {
                        if (ledger.token !== token) return;   // modal đã mở sang thứ khác

                        const rows = res.data || [];
                        const meta = res.meta || {};
                        ledger.loading = false;
                        // Tin theo tổng số API vừa trả: kho có thể vừa phát sinh thêm
                        // bút toán, giữ số cũ thì nút "Xem thêm" biến mất sớm.
                        if (meta.total != null) ledger.total = Number(meta.total) || 0;

                        if (!rows.length) {
                            ledger.total = ledger.seen.size;   // hết sổ thật, dù tổng nói khác
                            renderLedgerMeta();
                            return;
                        }
                        ledger.page += 1;

                        const fresh = rows.filter((h) => !ledger.seen.has(h.id));
                        fresh.forEach((h) => ledger.seen.add(h.id));
                        if (fresh.length) {
                            $('vLedger').insertAdjacentHTML('beforeend', ledgerRows(fresh));
                        }
                        renderLedgerMeta();
                        // Cả trang đều là dòng đã xem (sổ vừa dịch xuống vì có đơn mới)
                        // — đi tiếp luôn để cú bấm của người dùng không thành vô ích.
                        if (!fresh.length) loadMoreLedger();
                    })
                    .catch(() => {
                        if (ledger.token !== token) return;
                        ledger.loading = false;
                        renderLedgerMeta();
                        $('vMoreText').textContent = 'Không tải được — bấm để thử lại';
                    });
            }

            $('vMoreBtn').addEventListener('click', loadMoreLedger);

            function openView(id) {
                viewing = null;
                ledger.id = Number(id) || 0;
                ledger.page = 1;
                ledger.total = 0;
                ledger.loading = false;
                ledger.seen = new Set();
                ledger.token += 1;
                const token = ledger.token;
                $('vMoreWrap').hidden = true;
                $('vLedger').innerHTML = '<tr><td colspan="6" class="inv-ledger-empty">Đang tải…</td></tr>';
                ['vName', 'vSku', 'vCategory', 'vBrand', 'vQty', 'vPrice', 'vCost', 'vValue'].forEach((k) => {
                    $(k).textContent = '—';
                });
                $('vTotal').textContent = '';
                viewOverlay.style.display = 'flex';

                fetch(DETAIL_URL.replace(/\/0\/detail$/, '/' + id + '/detail'), { headers: { 'Accept': 'application/json' } })
                    .then((r) => r.ok ? r.json() : Promise.reject(r))
                    .then((res) => {
                        if (ledger.token !== token) return;   // modal đã mở sang thứ khác

                        const d = res.data || {};
                        const it = d.item || {};
                        viewing = it;
                        const qty = Number(it.stock_quantity) || 0;
                        const st = stateOf(qty);

                        $('vName').textContent = it.product_name || '—';
                        // Gộp SKU và phân loại vào một dòng: đọc "mã — size/màu" liền
                        // mạch dễ hơn là hai ô rời nhau ở hai chỗ khác nhau.
                        $('vSku').textContent = [it.sku, variantText(it)].filter((s) => s && s !== '—').join(' · ');
                        $('vCategory').textContent = it.category_name || '—';
                        $('vBrand').textContent = it.brand_name || '—';
                        $('vQty').textContent = nf.format(qty);
                        $('vPrice').textContent = money(it.price);
                        // Chưa khai giá vốn: hiện "Chưa khai" thay vì 0₫, và giá trị vốn
                        // cũng để gạch ngang — 0₫ ở đây là một lời khẳng định sai.
                        const hasCost = it.cost_price != null;
                        $('vCost').textContent = hasCost ? money(it.cost_price) : 'Chưa khai';
                        $('vCost').classList.toggle('is-muted', !hasCost);
                        $('vValue').textContent = hasCost ? money(it.stock_value) : '—';
                        const badge = $('vState');
                        badge.textContent = st.label;
                        badge.className = 'inv-badge tone-' + st.tone;

                        const rows = d.histories || [];
                        ledger.total = Number(d.total) || 0;
                        rows.forEach((h) => ledger.seen.add(h.id));
                        renderHistories(rows);
                        renderLedgerMeta();
                    })
                    .catch(() => {
                        if (ledger.token !== token) return;
                        $('vMoreWrap').hidden = true;
                        $('vLedger').innerHTML = '<tr><td colspan="6" class="inv-ledger-empty">'
                            + 'Không tải được sổ kho. Vui lòng thử lại.</td></tr>';
                    });
            }

            document.querySelectorAll('[data-view]').forEach((el) => {
                el.addEventListener('click', () => openView(el.dataset.view));
            });

            $('vAdjustBtn').addEventListener('click', () => {
                if (!viewing) return;
                viewOverlay.style.display = 'none';
                openAdjust({
                    ids: [viewing.variant_id],
                    current: Number(viewing.stock_quantity) || 0,
                    title: 'Chỉnh tồn kho',
                    name: viewing.product_name || '—',
                    sub: [viewing.sku, variantText(viewing)].filter(Boolean).join(' · '),
                });
            });

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
