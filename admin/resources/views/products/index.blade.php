@extends('layouts.app')

@section('title', 'Danh sách sản phẩm')

@section('content')
    {{--
        Trang "Danh sách sản phẩm".
        Lọc / tìm kiếm / phân trang chạy phía server (Go API hỗ trợ sẵn): mỗi thay
        đổi bộ lọc là một GET mới qua form #prdFilter (tự submit khi đổi select,
        debounce khi gõ tìm kiếm). Các thao tác đổi trạng thái / xoá / xoá hàng loạt
        dựng form POST động bằng JS (chuẩn CSRF) — cùng phong cách trang Danh mục.
    --}}
@php
        // Sắp xếp danh mục theo cây (thụt lề) để dropdown lọc dễ đọc.
        // API trả về parent_id = null cho danh mục gốc → dùng 'root' thay vì 0 để groupBy đúng.
        $catByParent = collect($categories)->groupBy(fn ($c) => $c['parent_id'] ?? 'root');
        $orderedCats = [];
        $walkCats = function ($parentId, $level) use (&$walkCats, $catByParent, &$orderedCats) {
            foreach ($catByParent->get($parentId, []) as $c) {
                $orderedCats[] = ['id' => $c['id'], 'name' => $c['name'], 'level' => $level];
                $walkCats($c['id'], $level + 1);
            }
        };
        $walkCats('root', 0);

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
    @endphp

    <div class="prd">
        {{-- Header --}}
        <div class="prd-head">
            <h1 class="prd-title">Danh sách sản phẩm</h1>
        </div>

        {{-- Dòng nào của file nhập bị lỗi và lỗi vì gì. Chỉ đếm số dòng hỏng thì
             với file vài trăm dòng, người dùng không có cách nào lần ra chỗ sai. --}}
        @if(session('importErrors'))
            <div class="prd-import-errors">
                <p class="prd-import-errors-head">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                    Các dòng chưa nhập được
                </p>
                <ul>
                    @foreach(session('importErrors') as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ul>
                @if((int) session('importErrorsMore', 0) > 0)
                    <p class="prd-import-errors-more">… và {{ session('importErrorsMore') }} dòng nữa.</p>
                @endif
            </div>
        @endif

        {{-- Bộ lọc --}}
        @php
            // Đếm số bộ lọc nâng cao đang bật -> tự mở hàng nâng cao + hiện badge.
            $advCount = ($filters['brand_id'] ? 1 : 0)
                + ($filters['kit_type'] !== '' ? 1 : 0)
                + ($filters['status'] !== 'all' ? 1 : 0)
                + ($filters['featured'] !== '' ? 1 : 0)
                + ($filters['sort'] !== 'newest' ? 1 : 0);
            $advOpen = $advCount > 0;
        @endphp
        <form method="GET" action="{{ route('admin.products.index') }}" id="prdFilter" class="prd-filter">
            {{-- Hàng cơ bản: tìm kiếm + lọc danh mục + nút Nâng cao --}}
            <div class="prd-toolbar">
                <div class="prd-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="prd-search-input"
                           placeholder="Tìm theo tên, đội bóng hoặc SKU" autocomplete="off">
                    <button type="submit" class="prd-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="category_id" class="prd-select" title="Lọc theo danh mục">
                    <option value="0">Tất cả danh mục</option>
                    @foreach($orderedCats as $c)
                        <option value="{{ $c['id'] }}" {{ $filters['category_id'] === $c['id'] ? 'selected' : '' }}>
                            {{ str_repeat('— ', $c['level']) }}{{ $c['name'] }}
                        </option>
                    @endforeach
                </select>

                <button type="button" id="prdAdvToggle" class="prd-adv-btn {{ $advOpen ? 'is-open' : '' }}" aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="prd-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="prd-adv-count">{{ $advCount }}</span>@endif
                </button>

                {{-- Hành động: Thêm sản phẩm + Tiện ích (ngang hàng bộ lọc, đẩy sang phải) --}}
                <div class="prd-toolbar-actions">
                    <button type="button" id="prdAddBtn" class="prd-btn-primary">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm sản phẩm
                    </button>

                    <div class="prd-util" id="prdUtil">
                        <button type="button" class="prd-util-btn" id="prdUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="prd-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="prd-util-menu">
<a href="{{ route('admin.products.export', request()->query()) }}" class="prd-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                            <button type="button" class="prd-util-item" id="prdImportBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 8l5-5 5 5"/><path d="M12 3v12"/></svg>
                                Import file
                            </button>
                        </div>
                    </div>

                    {{-- Chọn cột hiển thị: bảng 14 cột nên cần cho ẩn bớt thứ không dùng tới.
                         Lựa chọn nhớ trong localStorage, giữ nguyên khi sang trang / lọc lại. --}}
                    <div class="prd-util" id="prdCols">
                        <button type="button" class="prd-util-btn prd-util-icon" id="prdColsBtn"
                                aria-haspopup="true" aria-expanded="false"
                                aria-label="Chọn cột hiển thị" title="Chọn cột hiển thị trên bảng">
                            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3"/><path d="M14 2v4M8 10v4M16 18v4"/></svg>
                            <span class="prd-cols-count" id="prdColsCount" hidden></span>
                        </button>
                        <div class="prd-util-menu prd-cols-menu">
                            <p class="prd-cols-head">Cột hiển thị</p>
                            <div id="prdColsList"></div>
                            <p class="prd-cols-hint">Cột Sản phẩm và Thao tác luôn hiển thị.</p>
                            <div class="prd-cols-foot">
                                <button type="button" class="prd-util-item" id="prdColsReset">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                                    Hiện tất cả
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Hàng nâng cao: các bộ lọc còn lại (ẩn cho tới khi bấm "Nâng cao") --}}
            <div class="prd-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="prdAdvRow">
                <select name="brand_id" class="prd-select" title="Lọc theo thương hiệu">
                    <option value="0">Tất cả thương hiệu</option>
                    @foreach($brands as $b)
                        <option value="{{ $b['id'] }}" {{ $filters['brand_id'] === $b['id'] ? 'selected' : '' }}>{{ $b['name'] }}</option>
                    @endforeach
                </select>

                <select name="kit_type" class="prd-select" title="Lọc theo loại áo">
                    <option value="">Tất cả loại áo</option>
                    @foreach($kitTypes as $val => $label)
                        <option value="{{ $val }}" {{ $filters['kit_type'] === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="status" class="prd-select" title="Lọc theo trạng thái kinh doanh">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>Tất cả trạng thái</option>
                    @foreach($statuses as $val => $label)
                        <option value="{{ $val }}" {{ $filters['status'] === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="featured" class="prd-select" title="Lọc sản phẩm nổi bật">
                    <option value="">Nổi bật: Tất cả</option>
                    <option value="1" {{ $filters['featured'] === '1' ? 'selected' : '' }}>Chỉ nổi bật</option>
                    <option value="0" {{ $filters['featured'] === '0' ? 'selected' : '' }}>Không nổi bật</option>
                </select>

                <select name="sort" class="prd-select" title="Sắp xếp">
                    @foreach($sorts as $val => $label)
                        <option value="{{ $val }}" {{ $filters['sort'] === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Giữ per_page khi đổi bộ lọc --}}
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}" id="prdPerPageHidden">
        </form>

        {{-- Bảng --}}
        <div class="prd-table-wrap">
            <table class="prd-table">
                <thead>
                    <tr>
                        <th class="prd-c-check"><input type="checkbox" id="prdCheckAll" class="prd-check" title="Chọn tất cả"></th>
                        <th class="prd-c-stt">STT</th>
                        <th class="prd-c-code">Mã SP</th>
                        <th class="prd-c-img">Ảnh</th>
                        <th class="prd-c-name">Sản phẩm</th>
                        <th class="prd-c-sku">SKU</th>
                        <th class="prd-c-cat">Danh mục</th>
                        <th class="prd-c-brand">Thương hiệu</th>
                        <th class="prd-c-price">Giá</th>
                        <th class="prd-c-stock">Tồn kho</th>
                        <th class="prd-c-kit">Loại áo</th>
                        <th class="prd-c-feat">Nổi bật</th>
                        <th class="prd-c-status">Trạng thái</th>
                        <th class="prd-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $i => $p)
                        @php
                            $base = (float) ($p['base_price'] ?? 0);

                            // Giá khách THỰC TRẢ, chọn theo đúng thứ tự tầng thanh toán dùng:
                            // giá sau chương trình khuyến mãi > giá giảm gõ tay > giá niêm yết.
                            // Bảng này trước đây chỉ đọc sale_price, nên đang chạy khuyến mãi
                            // là người bán nhìn thấy con số khác hẳn giá ngoài cửa hàng.
                            $now = $base;
                            foreach (['final_price', 'sale_price'] as $key) {
                                $v = $p[$key] ?? null;
                                if ($v !== null && (float) $v > 0 && (float) $v < $now) {
                                    $now = (float) $v;
                                }
                            }
                            $sale = $now < $base;
                            $promoName = (string) ($p['promotion_name'] ?? '');

                            $status = $p['status'] ?? (! empty($p['is_active']) ? 'active' : 'hidden');
                            if (! array_key_exists($status, $statuses)) {
                                $status = 'hidden';
                            }

                            // Tồn kho theo từng biến thể: tổng hiện trên bảng, chi tiết mở ở bảng bung.
                            $vars = array_values(array_filter($p['variants'] ?? [], fn ($v) => ($v['is_active'] ?? true)));
                            $vars = array_map(fn ($v) => [
                                'sku'   => (string) ($v['sku'] ?? ''),
                                'size'  => (string) ($v['size'] ?? ''),
                                'color' => (string) ($v['color'] ?? ''),
                                'stock' => max(0, (int) ($v['stock_quantity'] ?? 0)),
                            ], $vars);
                            $totalStock = array_sum(array_column($vars, 'stock'));
                            $outCount = count(array_filter($vars, fn ($v) => $v['stock'] === 0));
                            // Ngưỡng cảnh báo: hết sạch → đỏ, còn ≤ 5 hoặc có biến thể hết → vàng
                            $stockState = $totalStock === 0 ? 'out' : (($totalStock <= 5 || $outCount > 0) ? 'low' : 'ok');
                        @endphp
                        <tr data-id="{{ $p['id'] }}">
                            <td class="prd-c-check"><input type="checkbox" class="prd-check prd-row-check" value="{{ $p['id'] }}"></td>
                            <td class="prd-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="prd-c-code" data-view="{{ $p['id'] }}" title="Xem chi tiết sản phẩm"><span class="prd-code">{{ 'SP' . str_pad((string) $p['id'], 6, '0', STR_PAD_LEFT) }}</span></td>
                            <td class="prd-c-img" data-view="{{ $p['id'] }}" title="Xem chi tiết sản phẩm">
                                @if(!empty($p['thumbnail']))
                                    <img class="prd-thumb" src="{{ $p['thumbnail'] }}" alt="" loading="lazy">
                                @else
                                    <span class="prd-thumb prd-thumb-empty">
                                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                    </span>
                                @endif
                            </td>
                            <td class="prd-c-name" data-view="{{ $p['id'] }}" title="Xem chi tiết sản phẩm">
                                <span class="prd-name">{{ $p['name'] }}</span>
                                @if(!empty($p['team']) || !empty($p['season']))
                                    <span class="prd-sub">{{ trim(($p['team'] ?? '') . (!empty($p['season']) ? ' · ' . $p['season'] : '')) }}</span>
                                @endif
                            </td>
                            <td class="prd-c-sku" data-view="{{ $p['id'] }}" title="Xem chi tiết sản phẩm"><span class="prd-code">{{ $p['sku'] }}</span></td>
                            <td class="prd-c-cat">{{ $p['category']['name'] ?? '—' }}</td>
                            <td class="prd-c-brand">{{ $p['brand']['name'] ?? '—' }}</td>
                            <td class="prd-c-price">
                                <span class="prd-price-sale">{{ number_format($now, 0, ',', '.') }}₫</span>
                                @if($sale)
                                    <span class="prd-price-base">{{ number_format($base, 0, ',', '.') }}₫</span>
                                @endif
                                {{-- Nói rõ giá đang rẻ vì chương trình nào, để người bán không
                                     tưởng ai đó sửa nhầm giá niêm yết. --}}
                                @if($promoName !== '')
                                    <span class="prd-price-promo" title="Giá đang giảm theo chương trình khuyến mãi đang chạy">
                                        <svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12 12 4h8v8l-8 8-8-8Z"/><circle cx="16" cy="8" r="1.4"/></svg>
                                        {{ $promoName }}
                                    </span>
                                @endif
                            </td>
                            <td class="prd-c-stock">
                                @if(count($vars))
                                    <button type="button" class="prd-stock prd-stock-{{ $stockState }}"
                                            data-stock="{{ $p['id'] }}"
                                            data-variants="{{ json_encode($vars, JSON_UNESCAPED_UNICODE) }}"
                                            data-name="{{ $p['name'] }}"
                                            title="Xem tồn kho từng biến thể">
                                        <span class="prd-stock-num">{{ number_format($totalStock, 0, ',', '.') }}</span>
                                        <span class="prd-stock-sub">{{ count($vars) }} biến thể</span>
                                        <svg class="prd-stock-caret" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                @else
                                    <span class="prd-muted">—</span>
                                @endif
                            </td>
                            <td class="prd-c-kit">
                                @if(!empty($p['kit_type']))
                                    <span class="prd-badge">{{ $kitTypes[$p['kit_type']] ?? $p['kit_type'] }}</span>
                                @else
                                    <span class="prd-muted">—</span>
                                @endif
                            </td>
                            <td class="prd-c-feat">
                                @if(!empty($p['is_featured']))
                                    <svg class="prd-star on" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" stroke="none"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                                @else
                                    <svg class="prd-star" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
                                @endif
                            </td>
                            <td class="prd-c-status">
                                {{-- Ba mức nên không dùng công tắc bật/tắt nữa: bấm vào mở
                                     danh sách chọn, mức đang dùng được đánh dấu sẵn. --}}
                                <button type="button" class="prd-status prd-status-{{ $status }}"
                                        data-status="{{ $p['id'] }}" data-current="{{ $status }}"
                                        title="{{ $statusHints[$status] ?? '' }} Bấm để đổi.">
                                    <span class="prd-status-dot"></span>{{ $statuses[$status] }}
                                    <svg class="prd-status-caret" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                </button>
                            </td>
                            <td class="prd-c-act">
                                <div class="prd-rowacts">
                                    <form action="{{ route('admin.products.duplicate', $p['id']) }}" method="POST" class="d-inline" data-confirm="Sao chép sản phẩm|Bạn có chắc chắn muốn sao chép sản phẩm này? Bản sao sẽ ở trạng thái ẩn.|info">
                                        @csrf
                                        <button type="submit" class="prd-rowbtn prd-copy" title="Sao chép sản phẩm">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        </button>
                                    </form>
                                    <button type="button" class="prd-rowbtn prd-edit" data-edit="{{ $p['id'] }}" title="Sửa sản phẩm">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="prd-rowbtn prd-del" data-remove="{{ $p['id'] }}" title="Xoá sản phẩm">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="14" class="prd-empty">
                                @if($filters['keyword'] !== '' || $filters['category_id'] || $filters['brand_id'] || $filters['kit_type'] || $filters['status'] !== 'all' || $filters['featured'] !== '')
                                    Không tìm thấy sản phẩm nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    Chưa có sản phẩm nào. Bấm “Thêm sản phẩm” để tạo mới.
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
            'noun' => 'sản phẩm',
            'perPageName' => 'per_page',
            'perPageOptions' => $perPageOptions,
        ])
    </div>

    <div id="prdBulkMount"></div>
    <div id="prdModalMount"></div>
    <div id="prdMiniMount"></div>

    {{-- Modal Import file --}}
    <div class="prd-overlay" id="prdImportOverlay" style="display:none;">
        <div class="prd-dialog prd-dialog-sm" id="prdImportDialog">
            <div class="prd-modal-head">
                <h4 class="prd-modal-title">Nhập sản phẩm từ file</h4>
                <button type="button" class="prd-modal-x" id="prdImportX">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST" action="{{ route('admin.products.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="prd-modal-body">
                    <p class="prd-hint" style="margin:0;">
                        Chọn file <b>CSV</b> theo mẫu. Mỗi dòng 1 sản phẩm; cột <b>sizes</b> nhập nhiều size cách nhau dấu phẩy (VD <code>S,M,L</code>) — mỗi size tạo 1 biến thể.
                        <br><a href="{{ route('admin.products.importTemplate') }}">⬇ Tải file mẫu</a>
                    </p>
                    <div>
                        <label class="prd-field-label">File CSV <span class="prd-req">*</span></label>
                        <input type="file" name="file" accept=".csv,text/csv" required class="prd-input" style="padding:6px 10px; height:auto;">
                    </div>
                </div>
                <div class="prd-modal-foot">
                    <button type="button" class="prd-btn-ghost" id="prdImportCancel">Hủy</button>
                    <button type="submit" class="prd-btn-primary">Nhập</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .prd {
            /* Phá padding p-4 của <main> để tràn viền như trang Danh mục */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .prd-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 20px;
        }
        .prd-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .prd-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .prd-btn-primary:hover { background: #40a9ff; }

        /* Bộ lọc */
        .prd-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .prd-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .prd-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .prd-toolbar-adv.is-open { display: flex; }

        /* Nút "Nâng cao" */
        .prd-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .prd-adv-btn:hover, .prd-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .prd-adv-caret { transition: transform .2s; }
        .prd-adv-btn.is-open .prd-adv-caret { transform: rotate(180deg); }
        .prd-adv-count {
            display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff; font-size: 11px; font-weight: 600;
        }

        /* Nhóm hành động (Thêm SP + Tiện ích) — đẩy sang phải toolbar */
        .prd-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .prd-btn-primary svg { flex-shrink: 0; }

        /* Dropdown Tiện ích */
        .prd-util { position: relative; }
        .prd-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .prd-util-btn:hover, .prd-util.open .prd-util-btn { border-color: #1890ff; color: #1890ff; }
        .prd-util-caret { transition: transform .2s; }
        .prd-util.open .prd-util-caret { transform: rotate(180deg); }
        .prd-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 190px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .prd-util.open .prd-util-menu { display: block; }
        .prd-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .prd-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .prd-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .prd-util-item:hover svg { color: #1890ff; }

        /* Chọn cột hiển thị — dùng lại vỏ dropdown .prd-util cho giống "Tiện ích" */
        /* Nút chỉ có icon: vuông, cùng chiều cao 34px với các nút chữ bên cạnh */
        .prd-util-icon {
            position: relative; width: 34px; padding: 0; justify-content: center; flex-shrink: 0;
        }
        .prd-cols-menu { min-width: 210px; max-height: 60vh; overflow-y: auto; }
        .prd-cols-head {
            padding: 8px 10px 6px; font-size: 11px; font-weight: 600; letter-spacing: .3px;
            text-transform: uppercase; color: #8c8c8c;
        }
        .prd-cols-item {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none; transition: background .15s;
        }
        .prd-cols-item:hover { background: #f5f7fa; }
        .prd-cols-item input { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; flex-shrink: 0; }
        .prd-cols-foot { margin-top: 4px; border-top: 1px solid #f0f0f0; padding-top: 4px; }
        .prd-cols-foot .prd-util-item:disabled { color: #bfbfbf; cursor: default; background: none; }
        .prd-cols-foot .prd-util-item:disabled svg { color: #d9d9d9; }
        .prd-cols-hint { padding: 2px 10px 6px; font-size: 11px; color: #8c8c8c; line-height: 1.45; }
        /* Số cột đang ẩn — đậu ở góc nút icon, không có chỗ đặt inline như nút chữ */
        .prd-cols-count {
            position: absolute; top: -6px; right: -6px;
            min-width: 16px; height: 16px; padding: 0 4px; border-radius: 8px; background: #1890ff;
            color: #fff; font-size: 11px; font-weight: 600; line-height: 16px; text-align: center;
            box-shadow: 0 0 0 2px #fff;
        }

        /* Ẩn cột: class đặt trên <table>, ăn cho cả <th> lẫn <td> vì hai bên dùng chung lớp */
        .prd-table.hide-stt    .prd-c-stt,
        .prd-table.hide-code   .prd-c-code,
        .prd-table.hide-img    .prd-c-img,
        .prd-table.hide-sku    .prd-c-sku,
        .prd-table.hide-cat    .prd-c-cat,
        .prd-table.hide-brand  .prd-c-brand,
        .prd-table.hide-price  .prd-c-price,
        .prd-table.hide-stock  .prd-c-stock,
        .prd-table.hide-kit    .prd-c-kit,
        .prd-table.hide-feat   .prd-c-feat,
        .prd-table.hide-status .prd-c-status { display: none; }
        .prd-searchbox { display: flex; border-radius: 4px; }
        .prd-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .prd-search-input {
            height: 34px; width: 240px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .prd-search-input::placeholder { color: #bfbfbf; }
        .prd-searchbox:focus-within .prd-search-input,
        .prd-searchbox:focus-within .prd-search-btn { border-color: #86b7fe; }
        .prd-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer; transition: color .15s;
        }
        .prd-search-btn:hover { color: #1890ff; }

        .prd-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .prd-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Bảng — cột co theo nội dung (compact), không kéo giãn full-width.
           Chỉ cột "Sản phẩm" được phép giãn có giới hạn (min/max). */
        .prd-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; }
        .prd-table { width: auto; border-collapse: collapse; font-size: 13px; }
        .prd-table thead tr { background: #f0f0f0; color: #262626; }
        .prd-table th, .prd-table td { padding: 14px 22px; vertical-align: middle; white-space: nowrap; }
        .prd-table th { font-weight: 700; text-align: left; }
        .prd-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .prd-table tbody tr:hover { background: #fafafa; }
        .prd-table tbody tr.is-selected, .prd-table tbody tr.is-selected:hover { background: #e6f7ff; }

        /* Cột nhỏ để width:1% ép co sát nội dung; cột "Sản phẩm" nhận phần dư còn lại */
        .prd-table th.prd-c-check,  .prd-table td.prd-c-check  { width: 1%; padding-right: 8px; }
        .prd-table th.prd-c-stt,    .prd-table td.prd-c-stt    { width: 1%; text-align: center; }
        .prd-table th.prd-c-code,   .prd-table td.prd-c-code   { width: 1%; }
        .prd-table th.prd-c-img,    .prd-table td.prd-c-img    { width: 1%; text-align: center; }
        .prd-table th.prd-c-name,   .prd-table td.prd-c-name   { min-width: 200px; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-sku,    .prd-table td.prd-c-sku    { width: 1%; }
        .prd-table th.prd-c-cat,    .prd-table td.prd-c-cat    { width: 1%; }
        .prd-table th.prd-c-brand,  .prd-table td.prd-c-brand  { width: 1%; }
        .prd-table th.prd-c-price,  .prd-table td.prd-c-price  { width: 1%; }
        .prd-table th.prd-c-stock,  .prd-table td.prd-c-stock  { width: 1%; text-align: center; }
        .prd-table th.prd-c-kit,    .prd-table td.prd-c-kit    { width: 1%; }
        .prd-table th.prd-c-feat,   .prd-table td.prd-c-feat   { width: 1%; text-align: center; }
        .prd-table th.prd-c-status, .prd-table td.prd-c-status { width: 1%; text-align: center; }
        .prd-table th.prd-c-act,    .prd-table td.prd-c-act    { width: 1%; text-align: center; }

        .prd-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .prd-thumb { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid #f0f0f0; }
        .prd-thumb-empty { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 6px; background: #f5f5f5; color: #bfbfbf; }

        .prd-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-code { font-variant-numeric: tabular-nums; letter-spacing: .2px; color: #595959; }
        .prd-muted { color: #bfbfbf; }

        .prd-price-sale { display: block; font-weight: 600; color: #cf1322; }
        .prd-price-base { display: block; font-size: 12px; color: #bfbfbf; text-decoration: line-through; }
        /* Khi không có giá KM, giá gốc dùng màu thường (không đỏ) */
        .prd-c-price .prd-price-sale:only-child { color: #262626; font-weight: 600; }

        .prd-badge {
            display: inline-block; border-radius: 4px; background: #f0f5ff; color: #1d39c4;
            padding: 2px 8px; font-size: 12px; font-weight: 500; white-space: nowrap;
        }
        /* Tồn kho: ô tổng trên bảng, bấm để bung chi tiết từng biến thể */
        .prd-stock {
            display: inline-flex; flex-direction: column; align-items: center; gap: 0;
            min-width: 78px; padding: 4px 10px 5px; border: 1px solid #f0f0f0; border-radius: 6px;
            background: #fafafa; cursor: pointer; line-height: 1.25; position: relative; transition: .15s all;
        }
        .prd-stock:hover { border-color: #91caff; background: #f0f8ff; }
        .prd-stock-num { font-size: 15px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .prd-stock-sub { font-size: 11px; color: #8c8c8c; white-space: nowrap; }
        .prd-stock-caret { position: absolute; top: 5px; right: 4px; color: #bfbfbf; transition: transform .15s; }
        .prd-stock.is-open .prd-stock-caret { transform: rotate(180deg); }
        .prd-stock-ok   .prd-stock-num { color: #237804; }
        .prd-stock-low  .prd-stock-num { color: #d46b08; }
        .prd-stock-low  { border-color: #ffe7ba; background: #fffbe6; }
        .prd-stock-out  .prd-stock-num { color: #cf1322; }
        .prd-stock-out  { border-color: #ffccc7; background: #fff1f0; }

        /* Bảng bung — thả nổi trên body nên không bị bảng cắt mất */
        .prd-stockpop {
            position: fixed; z-index: 1090; min-width: 260px; max-width: 340px; max-height: 60vh; overflow: auto;
            background: #fff; border: 1px solid #f0f0f0; border-radius: 8px; box-shadow: 0 6px 24px rgba(0,0,0,.14);
        }
        .prd-stockpop-head {
            padding: 9px 12px; border-bottom: 1px solid #f5f5f5; font-size: 12px; color: #8c8c8c;
            position: sticky; top: 0; background: #fff;
        }
        .prd-stockpop-head b { display: block; color: #262626; font-size: 13px; font-weight: 600; margin-bottom: 1px; }
        .prd-stockpop-row {
            display: flex; align-items: center; gap: 10px; padding: 7px 12px; border-bottom: 1px solid #fafafa;
        }
        .prd-stockpop-row:last-child { border-bottom: 0; }
        .prd-stockpop-name { flex: 1; min-width: 0; font-size: 13px; color: #262626; }
        .prd-stockpop-sku { display: block; font-size: 11px; color: #bfbfbf; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-stockpop-qty {
            flex-shrink: 0; min-width: 54px; text-align: right; font-size: 13px; font-weight: 700;
            font-variant-numeric: tabular-nums; color: #237804;
        }
        .prd-stockpop-qty.low { color: #d46b08; }
        .prd-stockpop-qty.out { color: #cf1322; font-weight: 600; }

        .prd-star { color: #d9d9d9; }
        .prd-star.on { color: #faad14; }

        /* Switch trạng thái */
        .prd-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .prd-switch.on { background: #7083b6; }
        .prd-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .prd-switch.on .prd-switch-knob { transform: translateX(23px); }

        .prd-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .prd-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .prd-rowbtn.prd-copy { color: #52c41a; }
        .prd-rowbtn.prd-copy:hover { background: #f6ffed; }
        .prd-rowbtn.prd-edit { color: #1890ff; }
        .prd-rowbtn.prd-edit:hover { background: #e6f7ff; }
        .prd-rowbtn.prd-del { color: #ff4d4f; }
        .prd-rowbtn.prd-del:hover { background: #fff1f0; }

        .prd-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Vòng focus bàn phím đồng bộ cho các nút (cùng màu xanh hệ thống) */
        .prd-btn-primary:focus-visible, .prd-btn-ghost:focus-visible,
        .prd-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        /* Tên & Mã SP có thể click để xem chi tiết */
        .prd-c-code[data-view], .prd-c-name[data-view], .prd-c-sku[data-view], .prd-c-img[data-view] {
            cursor: pointer;
        }
        .prd-c-name[data-view]:hover .prd-name,
        .prd-c-code[data-view]:hover .prd-code,
        .prd-c-sku[data-view]:hover .prd-code {
            color: #1890ff;
            text-decoration: underline;
        }

        /* Thanh bulk nổi (căn giữa vùng nội dung, bù sidebar như trang Danh mục) */
        .prd-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .prd-bulk { left: 48px; }
        @media (max-width: 820px) { .prd-bulk { left: 0; } }
        .prd-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .prd-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .prd-bulk-clear:hover { color: #262626; }
        .prd-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 9999px; background: #ff4d4f;
            padding: 6px 16px; font-size: 13px; font-weight: 500; color: #fff; cursor: pointer; transition: background .15s;
        }
        .prd-bulk-del:hover { background: #ff7875; }

        /* ---- Modal thêm/sửa ---- */
        .prd-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .prd-dialog {
            max-height: 92vh; width: 100%; max-width: 900px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2);
            scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent;
        }
        /* Tiêu đề nhóm trong modal */
        .prd-section-title {
            margin: 2px 0 0; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
            color: #8c8c8c; padding-bottom: 6px; border-bottom: 1px solid #f0f0f0;
        }

        /* Thanh cuộn đẹp — modal & bảng (WebKit + Firefox) */
        .prd-dialog::-webkit-scrollbar { width: 11px; }
        .prd-dialog::-webkit-scrollbar-track { background: transparent; }
        .prd-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .prd-dialog::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }
        .prd-table-wrap { scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent; }
        .prd-table-wrap::-webkit-scrollbar { height: 11px; }
        .prd-table-wrap::-webkit-scrollbar-track { background: transparent; }
        .prd-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .prd-table-wrap::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }
        .prd-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .prd-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .prd-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .prd-modal-x:hover { color: #262626; }
        .prd-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .prd-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .prd-grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .prd-col-2 { grid-column: span 2; }
        .prd-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .prd-req { color: #ff4d4f; }
        .prd-input, .prd-textarea, .prd-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
            font-family: inherit; color: #262626; background: #fff;
        }
        .prd-input { height: 36px; }
        .prd-textarea { padding: 8px 12px; min-height: 64px; resize: vertical; line-height: 1.5; }
        .prd-input::placeholder, .prd-textarea::placeholder { color: #bfbfbf; }
        .prd-input:focus, .prd-textarea:focus, .prd-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .prd-input:disabled { background: #f5f5f5; color: #8c8c8c; cursor: not-allowed; }
        .prd-msel {
            height: 36px; cursor: pointer; padding-right: 32px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        /* Ô select kèm nút cộng bên phải */
        .prd-input-with-btn { display: flex; gap: 8px; align-items: stretch; }
        .prd-input-with-btn .prd-msel { flex: 1; min-width: 0; }
        .prd-addon-btn {
            flex-shrink: 0; width: 36px; height: 36px; border: 1px solid #d9d9d9; border-radius: 4px;
            background: #fff; color: #1890ff; cursor: pointer; display: inline-flex;
            align-items: center; justify-content: center; transition: border-color .15s, background .15s;
        }
        .prd-addon-btn:hover { border-color: #86b7fe; background: #f0f7ff; }

        /* Modal nhỏ (thêm nhanh) — nổi trên modal sản phẩm */
        .prd-overlay.prd-overlay-top { z-index: 1092; }
        .prd-dialog-sm { max-width: 420px; }

        .prd-input-prefix { position: relative; }
        .prd-input-prefix .prd-input { padding-right: 34px; }
        .prd-input-suffix { position: absolute; right: 12px; top: 0; height: 36px; display: inline-flex; align-items: center; font-size: 13px; color: #8c8c8c; pointer-events: none; }
        .prd-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; }

        /* Ảnh đại diện trong modal */
        .prd-img-field { display: flex; align-items: center; gap: 14px; }
        .prd-img-preview {
            width: 84px; height: 84px; flex-shrink: 0; border: 1px solid #d9d9d9; border-radius: 8px;
            overflow: hidden; display: flex; align-items: center; justify-content: center;
            background: #fafafa; color: #bfbfbf; position: relative; transition: opacity .15s;
        }
        .prd-img-preview img { width: 100%; height: 100%; object-fit: cover; }
        .prd-img-preview.is-loading { opacity: .5; }
        .prd-img-preview.is-loading::after {
            content: ''; position: absolute; width: 22px; height: 22px; border-radius: 50%;
            border: 2px solid #d9d9d9; border-top-color: #1890ff; animation: prdspin .7s linear infinite;
        }
        @keyframes prdspin { to { transform: rotate(360deg); } }
        .prd-img-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .prd-img-btns { display: flex; gap: 8px; }
        .prd-img-remove { color: #ff4d4f; }
        .prd-img-remove:hover { border-color: #ffa39e; }

        .prd-switch-row { display: flex; align-items: center; gap: 10px; }
        .prd-switch-label { font-size: 13px; color: #595959; }

        /* Thư viện ảnh */
        .prd-gallery { display: flex; flex-wrap: wrap; gap: 10px; }
        .prd-gallery-empty { margin: 0; font-size: 13px; color: #bfbfbf; }
        .prd-gimg {
            position: relative; width: 88px; height: 88px; border-radius: 8px; overflow: hidden;
            border: 1px solid #e6e6e6; background: #fafafa;
        }
        .prd-gimg.is-primary { border-color: #1890ff; box-shadow: 0 0 0 2px rgba(24,144,255,.25); }
        .prd-gimg img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .prd-gimg-badge {
            position: absolute; left: 4px; bottom: 4px; padding: 1px 6px; border-radius: 4px;
            background: rgba(24,144,255,.92); color: #fff; font-size: 10px; font-weight: 600; line-height: 1.5;
        }
        .prd-gimg-star, .prd-gimg-del {
            position: absolute; top: 4px; width: 22px; height: 22px; border: 0; border-radius: 50%; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; font-size: 13px; line-height: 1;
            background: rgba(0,0,0,.55); color: #fff; opacity: 0; transition: opacity .15s, background .15s;
        }
        .prd-gimg:hover .prd-gimg-star, .prd-gimg:hover .prd-gimg-del { opacity: 1; }
        .prd-gimg-star { left: 4px; }
        .prd-gimg-star:hover { background: #faad14; }
        .prd-gimg.is-primary .prd-gimg-star { opacity: 1; background: #faad14; }
        .prd-gimg-del { right: 4px; font-size: 16px; }
        .prd-gimg-del:hover { background: #ff4d4f; }
        .prd-gallery-foot { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
        .prd-gallery-foot .prd-hint { margin: 0; }

        /* Biến thể (size/màu) */
        .prd-var { display: flex; flex-direction: column; gap: 10px; }
        #mVariants { display: flex; flex-direction: column; gap: 10px; }
        /* Thanh thêm nhanh nhiều size */
        .prd-var-quick {
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
            padding: 12px; background: #f7f9fc; border: 1px solid #eef0f4; border-radius: 6px;
        }
        .prd-var-quick .prd-var-q-color { height: 36px; width: 150px; flex-shrink: 0; }
        .prd-var-quick .prd-var-q-sizes { height: 36px; flex: 1; min-width: 180px; }
        .prd-var-quick .prd-btn-primary { flex-shrink: 0; }
        .prd-var-head, .prd-var-row {
            display: grid; grid-template-columns: 1fr .7fr 1.1fr 1.1fr .7fr 36px; gap: 12px; align-items: center;
        }
        /* Tồn kho chỉ để xem — nghiệp vụ kho mới được đổi, nên không phải ô nhập */
        .prd-var-stock {
            height: 36px; display: inline-flex; align-items: center; justify-content: flex-end;
            padding: 0 10px; border: 1px solid #eef0f4; border-radius: 4px;
            background: #f7f9fc; color: #8c8c8c; font-variant-numeric: tabular-nums;
        }
        .prd-var-stock.is-zero { color: #ff4d4f; }
        .prd-var-head { font-size: 12px; font-weight: 600; color: #8c8c8c; padding: 0 2px 2px; }
        .prd-var-row .prd-input, .prd-var-row .prd-msel { height: 36px; }
        .prd-var-row .prd-msel { padding-right: 28px; background-position: right 8px center; }
        .prd-var-row .prd-input-prefix .prd-input-suffix { height: 36px; }
        .prd-var-del {
            height: 36px; width: 36px; flex-shrink: 0; border: 1px solid #ffccc7; border-radius: 4px;
            background: #fff; color: #ff4d4f; font-size: 20px; line-height: 1; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; transition: background .15s;
        }
        .prd-var-del:hover { background: #fff1f0; }
        .prd-var-add { align-self: flex-start; margin-top: 2px; }
        #mVariants:empty { display: none; }

        .prd-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .prd-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .prd-btn-ghost:hover { border-color: #bfbfbf; }

        /* ===== Trạng thái kinh doanh (3 mức) ===== */
        .prd-status {
            display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 8px;
            border: 1px solid #d9d9d9; border-radius: 13px; background: #fff; cursor: pointer;
            font-size: 12px; font-weight: 500; color: #595959; white-space: nowrap; transition: border-color .15s, background .15s;
        }
        .prd-status:hover, .prd-status.is-open { border-color: #91caff; background: #f5faff; }
        .prd-status.is-static { cursor: default; }
        .prd-status-caret { color: #bfbfbf; flex-shrink: 0; }
        .prd-status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; background: #bfbfbf; }
        /* Xanh = đang bán, vàng = tạm ẩn, xám = ngừng kinh doanh. Cùng bộ màu với
           ô tồn kho để cả bảng đọc theo một quy ước. */
        .prd-status-active .prd-status-dot,     .prd-dot-active       { background: #52c41a; }
        .prd-status-hidden .prd-status-dot,     .prd-dot-hidden       { background: #faad14; }
        .prd-status-discontinued .prd-status-dot, .prd-dot-discontinued { background: #bfbfbf; }
        .prd-status-active { border-color: #d9f7be; background: #f6ffed; color: #389e0d; }
        .prd-status-hidden { border-color: #ffe7ba; background: #fffbe6; color: #d46b08; }
        .prd-status-discontinued { border-color: #e8e8e8; background: #fafafa; color: #8c8c8c; }

        .prd-statuspop {
            position: fixed; z-index: 1090; min-width: 290px; background: #fff;
            border: 1px solid #f0f0f0; border-radius: 8px; box-shadow: 0 6px 24px rgba(0,0,0,.14); padding: 4px;
        }
        .prd-statuspop-item {
            display: flex; align-items: flex-start; gap: 9px; width: 100%; border: 0; border-radius: 6px;
            background: none; padding: 9px 10px; text-align: left; cursor: pointer; transition: background .12s;
        }
        .prd-statuspop-item:hover { background: #f5f5f5; }
        .prd-statuspop-item.is-current { background: #f5faff; }
        .prd-statuspop-item .prd-status-dot { margin-top: 5px; }
        .prd-statuspop-text { display: flex; flex-direction: column; gap: 2px; flex: 1; }
        .prd-statuspop-text b { font-size: 13px; font-weight: 600; color: #262626; }
        .prd-statuspop-text small { font-size: 11px; line-height: 1.4; color: #8c8c8c; }
        .prd-statuspop-check { color: #1890ff; flex-shrink: 0; margin-top: 3px; }

        /* Tên chương trình khuyến mãi dưới ô giá */
        .prd-price-promo {
            display: inline-flex; align-items: center; gap: 3px; margin-top: 2px;
            font-size: 11px; color: #d4380d; max-width: 160px;
        }
        .prd-price-promo svg { flex-shrink: 0; }

        /* Dòng đang chờ tải lại dữ liệu trước khi mở modal */
        tr.is-loading { opacity: .55; pointer-events: none; }

        /* ===== Danh sách dòng lỗi của file nhập ===== */
        .prd-import-errors {
            border: 1px solid #ffccc7; border-radius: 8px; background: #fff2f0; padding: 12px 16px; margin-bottom: 14px;
        }
        .prd-import-errors-head {
            display: flex; align-items: center; gap: 6px; margin: 0 0 6px;
            font-size: 13px; font-weight: 600; color: #cf1322;
        }
        .prd-import-errors ul { margin: 0; padding-left: 20px; }
        .prd-import-errors li { font-size: 12px; color: #595959; line-height: 1.7; }
        .prd-import-errors-more { margin: 6px 0 0; font-size: 12px; color: #8c8c8c; }

        /* ===== Modal: đầu, tab, lỗi từng ô, chân ===== */
        .prd-modal-headmain { display: flex; flex-direction: column; gap: 3px; }
        .prd-modal-sub { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #8c8c8c; }
        .prd-modal-sep { color: #d9d9d9; }
        .prd-modal-sub .prd-status { height: 20px; padding: 0 7px; font-size: 11px; }

        .prd-tabs {
            position: sticky; top: 57px; z-index: 2; display: flex; gap: 2px;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 0 20px;
        }
        .prd-tab {
            display: inline-flex; align-items: center; gap: 7px; border: 0; border-bottom: 2px solid transparent;
            background: none; padding: 11px 14px; font-size: 13px; font-weight: 500; color: #8c8c8c;
            cursor: pointer; transition: color .15s, border-color .15s;
        }
        .prd-tab:hover { color: #262626; }
        .prd-tab.is-active { color: #1890ff; border-bottom-color: #1890ff; }
        .prd-tab-no {
            display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px;
            border-radius: 50%; background: #f0f0f0; color: #8c8c8c; font-size: 11px; font-weight: 700;
        }
        .prd-tab.is-active .prd-tab-no { background: #e6f4ff; color: #1890ff; }
        /* Tab có ô sai: đỏ cả nhãn lẫn số thứ tự, không phải mò từng tab để tìm */
        .prd-tab.has-err { color: #cf1322; border-bottom-color: #ffa39e; }
        .prd-tab.has-err .prd-tab-no { background: #fff1f0; color: #cf1322; }

        .prd-panel { display: none; flex-direction: column; gap: 14px; }
        .prd-panel.is-active { display: flex; }

        .prd-err { margin: 4px 0 0; font-size: 11.5px; color: #cf1322; }
        .prd-err:empty { display: none; }
        .prd-input.is-err, .prd-msel.is-err { border-color: #ff4d4f; }
        .prd-input.is-err:focus, .prd-msel.is-err:focus { border-color: #ff4d4f; }

        .prd-warn {
            display: flex; align-items: flex-start; gap: 6px; margin: 6px 0 0;
            border: 1px solid #ffe7ba; border-radius: 6px; background: #fffbe6;
            padding: 8px 10px; font-size: 11.5px; line-height: 1.6; color: #874d00;
        }
        .prd-warn svg { flex-shrink: 0; margin-top: 2px; }

        /* Xem trước giá — con số khách thực trả, khỏi phải tự suy từ 3 ô giá */
        .prd-price-preview { display: flex; flex-wrap: wrap; align-items: center; gap: 22px; }
        .prd-price-preview:empty { display: none; }
        .prd-price-preview {
            border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa; padding: 10px 14px;
        }
        .prd-pp-item { display: flex; flex-direction: column; gap: 1px; }
        .prd-pp-item small { font-size: 11px; color: #8c8c8c; }
        .prd-pp-item b { font-size: 14px; font-weight: 700; color: #262626; }
        .prd-pp-off { color: #cf1322 !important; }
        .prd-pp-loss { color: #cf1322 !important; }
        .prd-pp-note { font-size: 11.5px; color: #cf1322; }

        .prd-modal-foot { justify-content: space-between; align-items: center; gap: 16px; }
        .prd-foot-btns { display: flex; gap: 8px; flex-shrink: 0; margin-left: auto; }
        .prd-foot-msg { margin: 0; font-size: 12px; color: #8c8c8c; }
        .prd-foot-msg.is-err { color: #cf1322; }
        .prd-foot-msg:empty { display: none; }

        @media (max-width: 720px) {
            .prd-grid3 { grid-template-columns: 1fr 1fr; }
            .prd-grid3 .prd-col-2 { grid-column: span 2; }
            .prd-tabs { overflow-x: auto; padding: 0 10px; }
            .prd-tab { padding: 11px 10px; white-space: nowrap; }
        }
        @media (max-width: 560px) {
            .prd-grid2, .prd-grid3 { grid-template-columns: 1fr; }
            .prd-grid3 .prd-col-2 { grid-column: span 1; }
            .prd-grid2 .prd-col-2 { grid-column: span 1; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());

        // Trần phía trình duyệt, khớp với ImageStore::MAX_UPLOAD_KB bên PHP. Chặn ở
        // đây chỉ để khỏi tải một file khổng lồ lên rồi mới bị từ chối; ảnh vẫn được
        // máy chủ thu nhỏ lại sau khi nhận.
        const MAX_IMG_BYTES = 10 * 1024 * 1024;
            const URL_BULK = @json(route('admin.products.bulkDestroy'));
            const URL_STORE = @json(route('admin.products.store'));
            const URL_UPLOAD = @json(route('admin.products.uploadImage'));
            const URL_BRAND_STORE = @json(route('admin.brands.store'));
            const URL_BASE = @json(url('admin/products'));
const RETURN_URL = @json(route('admin.products.index', request()->query()));
            // Dữ liệu sản phẩm (thô) để dựng payload khi đổi trạng thái mà không mất trường nào.
            const PRODUCTS = @json(json_decode(json_encode($products)) ?? []);
            const BY_ID = new Map(PRODUCTS.map((p) => [p.id, p]));
            // Dữ liệu cho modal thêm/sửa.
            const CATEGORIES = @json($orderedCats);            // [{id, name, level}] đã xếp theo cây
            const BRANDS = @json(array_map(fn ($b) => ['id' => $b['id'], 'name' => $b['name']], $brands));
            const KIT_TYPES = @json($kitTypes);                // { value: label }
            const STATUSES = @json($statuses);                 // { active: 'Đang bán', ... }
            const STATUS_HINTS = @json($statusHints);          // câu giải thích từng mức
            // Địa chỉ lấy chi tiết một sản phẩm. Modal sửa nạp lại từ đây thay vì
            // dùng bản nhúng sẵn trong trang — bản đó là ảnh chụp lúc mở danh sách,
            // người khác vừa sửa hoặc kho vừa đổi tồn là nó đã cũ.
            const URL_SHOW = (id) => `${@json(url('admin/products'))}/${id}`;

            const $filter = document.getElementById('prdFilter');
            const $bulkMount = document.getElementById('prdBulkMount');

            // ---------- Bộ lọc: tự submit ----------
            // Đổi select -> submit ngay; gõ tìm kiếm -> debounce 400ms.
            $filter.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => $filter.submit());
            });

            // Nút "Nâng cao": ẩn/hiện hàng bộ lọc phụ (nhớ trạng thái qua localStorage).
            (function () {
                const btn = document.getElementById('prdAdvToggle');
                const row = document.getElementById('prdAdvRow');
                if (!btn || !row) return;
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                // Server đã mở sẵn nếu có bộ lọc phụ đang bật; ngoài ra khôi phục lựa chọn của người dùng.
                if (!row.classList.contains('is-open') && localStorage.getItem('prd-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('prd-adv-open', open ? '1' : '0');
                });
            })();
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

            // Đổi trạng thái: gửi DUY NHẤT trường status. Không gửi lại toàn bộ sản
            // phẩm — API ghi cả dòng khi PUT, thiếu một trường là bấm đổi trạng thái
            // một cái mất luôn dữ liệu đó.
            function setStatus(id, status) {
                postForm(`${URL_BASE}/${id}/toggle-status`, 'PUT', { status });
            }

            // ---------- Chọn trạng thái (3 mức) ----------
            // Gắn vào body và định vị theo nút, để vùng cuộn ngang của bảng không
            // cắt mất — cùng cách làm với bảng bung tồn kho.
            let statusPop = null;
            let statusBtn = null;

            function closeStatusPop() {
                if (statusPop) { statusPop.remove(); statusPop = null; }
                if (statusBtn) { statusBtn.classList.remove('is-open'); statusBtn = null; }
            }

            function placeStatusPop() {
                if (!statusPop || !statusBtn) return;
                const r = statusBtn.getBoundingClientRect();
                const w = statusPop.offsetWidth;
                const h = statusPop.offsetHeight;
                let top = r.bottom + 6;
                if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
                let left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
                statusPop.style.top = top + 'px';
                statusPop.style.left = left + 'px';
            }

            function openStatusPop(btn) {
                const same = statusBtn === btn;
                closeStatusPop();
                if (same) return; // bấm lại chính nút đang mở = đóng

                const id = Number(btn.getAttribute('data-status'));
                const current = btn.getAttribute('data-current');

                statusPop = document.createElement('div');
                statusPop.className = 'prd-statuspop';
                statusPop.innerHTML = Object.entries(STATUSES).map(([val, label]) => `
                    <button type="button" class="prd-statuspop-item ${val === current ? 'is-current' : ''}" data-pick="${val}">
                        <span class="prd-status-dot prd-dot-${val}"></span>
                        <span class="prd-statuspop-text">
                            <b>${esc(label)}</b>
                            <small>${esc(STATUS_HINTS[val] || '')}</small>
                        </span>
                        ${val === current ? '<svg class="prd-statuspop-check" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m20 6-11 11-5-5"/></svg>' : ''}
                    </button>`).join('');

                statusPop.addEventListener('click', (e) => {
                    const item = e.target.closest('[data-pick]');
                    if (!item) return;
                    const picked = item.getAttribute('data-pick');
                    closeStatusPop();
                    if (picked === current) return; // chọn lại mức đang dùng = không làm gì

                    // Ngừng kinh doanh là quyết định khác hẳn tạm ẩn: hỏi lại một câu.
                    if (picked === 'discontinued') {
                        const p = BY_ID.get(id);
                        sysConfirm({
                            title: 'Ngừng kinh doanh sản phẩm',
                            message: 'Sản phẩm sẽ không hiện ngoài cửa hàng và không nhập thêm hàng nữa. '
                                + 'Đơn cũ, phiếu nhập cũ và báo cáo vẫn tra ra được bình thường.',
                            highlightText: p ? p.name : '',
                            type: 'warning',
                        }).then((ok) => { if (ok) setStatus(id, picked); });
                        return;
                    }
                    setStatus(id, picked);
                });

                document.body.appendChild(statusPop);
                statusBtn = btn;
                btn.classList.add('is-open');
                placeStatusPop();
            }

            document.addEventListener('click', (e) => {
                if (statusPop && !e.target.closest('.prd-statuspop') && !e.target.closest('[data-status]')) closeStatusPop();
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeStatusPop(); });
            window.addEventListener('resize', placeStatusPop);
            window.addEventListener('scroll', closeStatusPop, true);

            function removeProduct(p) {
                sysDelete({
                    title: 'Xác nhận xoá sản phẩm',
                    message: `Bạn có chắc chắn muốn xoá sản phẩm "${p.name}"? Hành động này không thể hoàn tác.`,
                    highlightText: p.name
                }).then((confirmed) => {
                    if (confirmed) {
                        postForm(`${URL_BASE}/${p.id}`, 'DELETE', {});
                    }
                });
            }

            // ---------- Tồn kho từng biến thể ----------
            // Bảng bung gắn thẳng vào body và định vị theo nút, để không bị vùng cuộn
            // ngang của bảng cắt mất khi sản phẩm có nhiều biến thể.
            let stockPop = null;
            let stockBtn = null;

            function closeStockPop() {
                if (stockPop) { stockPop.remove(); stockPop = null; }
                if (stockBtn) { stockBtn.classList.remove('is-open'); stockBtn = null; }
            }

            function placeStockPop() {
                if (!stockPop || !stockBtn) return;
                const r = stockBtn.getBoundingClientRect();
                const w = stockPop.offsetWidth;
                const h = stockPop.offsetHeight;
                // Ưu tiên bung xuống dưới; không đủ chỗ thì lật lên trên
                let top = r.bottom + 6;
                if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
                let left = r.left + r.width / 2 - w / 2;
                left = Math.min(Math.max(8, left), window.innerWidth - w - 8);
                stockPop.style.top = top + 'px';
                stockPop.style.left = left + 'px';
            }

            function openStockPop(btn) {
                const same = stockBtn === btn;
                closeStockPop();
                if (same) return;   // bấm lại chính nút đang mở = đóng

                let vars = [];
                try { vars = JSON.parse(btn.getAttribute('data-variants') || '[]'); } catch (err) { vars = []; }
                const total = vars.reduce((s, v) => s + v.stock, 0);

                stockPop = document.createElement('div');
                stockPop.className = 'prd-stockpop';
                stockPop.innerHTML =
                    `<div class="prd-stockpop-head"><b>${esc(btn.getAttribute('data-name') || '')}</b>`
                    + `Tổng ${total} sản phẩm · ${vars.length} biến thể</div>`
                    + vars.map((v) => {
                        const label = [v.size, v.color].filter(Boolean).join(' · ') || 'Biến thể mặc định';
                        const cls = v.stock === 0 ? 'out' : (v.stock <= 5 ? 'low' : '');
                        const qty = v.stock === 0 ? 'Hết hàng' : v.stock;
                        return '<div class="prd-stockpop-row">'
                            + `<span class="prd-stockpop-name">${esc(label)}`
                            + (v.sku ? `<span class="prd-stockpop-sku">${esc(v.sku)}</span>` : '')
                            + '</span>'
                            + `<span class="prd-stockpop-qty ${cls}">${esc(qty)}</span>`
                            + '</div>';
                    }).join('');

                document.body.appendChild(stockPop);
                stockBtn = btn;
                btn.classList.add('is-open');
                placeStockPop();
            }

            document.addEventListener('click', (e) => {
                if (stockPop && !e.target.closest('.prd-stockpop') && !e.target.closest('[data-stock]')) closeStockPop();
            });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeStockPop(); });
            window.addEventListener('resize', placeStockPop);
            window.addEventListener('scroll', closeStockPop, true);

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.prd-table tbody');
            tbody.addEventListener('click', (e) => {
                const st = e.target.closest('[data-stock]');
                if (st) { openStockPop(st); return; }
                const sw = e.target.closest('[data-status]');
                if (sw) { openStatusPop(sw); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const p = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (p) removeProduct(p); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { openModalById('edit', Number(ed.getAttribute('data-edit'))); return; }
                const vw = e.target.closest('[data-view]');
                if (vw) { openModalById('view', Number(vw.getAttribute('data-view'))); return; }
            });

            // ---------- Chọn dòng + bulk ----------
            const selected = new Set();
            const checkAll = document.getElementById('prdCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.prd-row-check'));

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
                    <div class="prd-bulk">
                        <span class="prd-bulk-text">Đã chọn <b>${n}</b> sản phẩm</span>
                        <button type="button" class="prd-bulk-clear" id="prdBulkClear">Bỏ chọn</button>
                        <button type="button" class="prd-bulk-del" id="prdBulkDel">
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
                if (e.target.closest('#prdBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                } else if (e.target.closest('#prdBulkDel')) {
                    const ids = [...selected];
                    if (!ids.length) return;
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${ids.length} sản phẩm đã chọn? Hành động này không thể hoàn tác.`,
                        highlightText: `Số lượng: ${ids.length} sản phẩm`
                    }).then((confirmed) => {
                        if (confirmed) {
                            const fields = {};
                            ids.forEach((id, i) => { fields[`ids[${i}]`] = id; });
                            postForm(URL_BULK, 'POST', fields);
                        }
                    });
                }
            });

            // ---------- Toast lỗi (client-side validate) ----------
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
            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            // ---------- Modal thêm/sửa ----------
            const $modalMount = document.getElementById('prdModalMount');
            const $miniMount = document.getElementById('prdMiniMount');

            // Toast thành công (dùng cho thêm nhanh thương hiệu).
            function toastOk(msg) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') return;
                const el = document.createElement('div');
                el.className = 'toast align-items-center text-bg-success border-0';
                el.setAttribute('role', 'alert');
                el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-check-circle-fill me-2"></i>${esc(msg)}</div>`
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: 3000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            // ---------- Modal nhỏ: thêm nhanh thương hiệu ----------
            function closeBrandQuick() { $miniMount.innerHTML = ''; }
            function openBrandQuickModal(onCreated) {
                $miniMount.innerHTML = `
                    <div class="prd-overlay prd-overlay-top" id="bqOverlay">
                        <div class="prd-dialog prd-dialog-sm" id="bqDialog">
                            <div class="prd-modal-head">
                                <h4 class="prd-modal-title">Thêm nhanh thương hiệu</h4>
                                <button type="button" class="prd-modal-x" id="bqX">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="prd-modal-body">
                                <div>
                                    <label class="prd-field-label">Tên thương hiệu <span class="prd-req">*</span></label>
                                    <input type="text" id="bqName" class="prd-input" placeholder="VD: Nike, Adidas, Puma" maxlength="150">
                                </div>
                            </div>
                            <div class="prd-modal-foot">
                                <button type="button" class="prd-btn-ghost" id="bqCancel">Hủy</button>
                                <button type="button" class="prd-btn-primary" id="bqSave">Lưu</button>
                            </div>
                        </div>
                    </div>`;
                document.getElementById('bqX').addEventListener('click', closeBrandQuick);
                document.getElementById('bqCancel').addEventListener('click', closeBrandQuick);
                const nameEl = document.getElementById('bqName');
                const saveBtn = document.getElementById('bqSave');
                async function submit() {
                    const name = nameEl.value.trim();
                    if (!name) { toastErr('Vui lòng nhập tên thương hiệu.'); return; }
                    saveBtn.disabled = true;
                    try {
                        const res = await fetch(URL_BRAND_STORE, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                            body: JSON.stringify({ name }),
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) { toastErr(data.message || 'Tạo thương hiệu thất bại.'); saveBtn.disabled = false; return; }
                        onCreated(data);
                        closeBrandQuick();
                    } catch (err) {
                        toastErr('Không kết nối được máy chủ.');
                        saveBtn.disabled = false;
                    }
                }
                saveBtn.addEventListener('click', submit);
                nameEl.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
                setTimeout(() => nameEl.focus(), 0);
            }
            function addBrandToSelect(b) {
                if (!b || !b.id) return;
                const sel = document.getElementById('mBrand');
                if (sel) {
                    const opt = document.createElement('option');
                    opt.value = b.id;
                    opt.textContent = b.name;
                    sel.appendChild(opt);
                    sel.value = String(b.id);
                    sel.dispatchEvent(new Event('change')); // cập nhật trạng thái placeholder xám
                }
                BRANDS.push({ id: b.id, name: b.name });
                toastOk(`Đã thêm thương hiệu "${b.name}".`);
            }

            // Mã sản phẩm hiển thị tự sinh từ id: SP000001, SP000002, ... (chỉ để hiển thị, không lưu).
            const fmtProdCode = (id) => 'SP' + String(id).padStart(6, '0');

            // Tiền tệ VN: chỉ giữ chữ số; hiển thị 850000 -> "850.000" (dấu chấm phân cách nghìn).
            const digitsOnly = (s) => String(s == null ? '' : s).replace(/\D/g, '');
            const fmtVnd = (n) => { const d = digitsOnly(n); return d ? Number(d).toLocaleString('vi-VN') : ''; };
            // Số lượng (tồn kho): luôn hiện số, kể cả 0 — khác fmtVnd trả rỗng khi trống.
            const fmtInt = (n) => Number(n || 0).toLocaleString('vi-VN');

            // Bỏ dấu tiếng Việt -> ASCII (để dựng SKU).
            const deaccent = (s) => String(s == null ? '' : s)
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/đ/g, 'd').replace(/Đ/g, 'D');
            const KIT_SKU = { fan: 'FAN', player: 'PLAYER' };

            // Tự sinh SKU sản phẩm từ đội bóng (hoặc tên) · loại áo · mùa giải.
            // VD: đội "Real Madrid" + fan + "2024/2025" -> "RM-FAN-2425".
            function genSku() {
                const team = document.getElementById('mTeam').value.trim();
                const name = document.getElementById('mName').value.trim();
                const season = document.getElementById('mSeason').value.trim();
                const kit = document.getElementById('mKit').value;
// Thay dấu câu bằng space trước khi tách — xử lý đúng O'NEILLS, M.C., 1.FC
                const normalized = deaccent(team || name).toUpperCase().replace(/[^A-Z0-9\s]+/g, ' ');
const words = normalized.split(/\s+/).filter(Boolean);
                let teamPart = '';
if (words.length >= 2) teamPart = words.slice(0, 2).map((w) => w[0]).join('');
                else teamPart = (words[0] || '').slice(0, 4);
                const kitPart = KIT_SKU[kit] || '';
                const seasonPart = (season.match(/\d+/g) || []).map((g) => g.length === 4 ? g.slice(2) : g).join('');
                const sku = [teamPart, kitPart, seasonPart].filter(Boolean).join('-');
                return sku || ('SP-' + Date.now().toString(36).toUpperCase());
            }

            /**
             * Mở modal cho một sản phẩm trong bảng — NẠP LẠI từ máy chủ trước.
             *
             * Bản nhúng sẵn trong trang là ảnh chụp lúc mở danh sách: người khác vừa
             * sửa giá, kho vừa nhập hàng, hay ai đó vừa đổi trạng thái thì nó đã cũ.
             * Sửa trên bản cũ rồi bấm Lưu là ghi đè công của người ta.
             *
             * Gọi hỏng thì vẫn mở bằng bản đang có, kèm một câu nói rõ đây là dữ
             * liệu cũ — thà cho làm việc tiếp còn hơn chặn cứng.
             */
            async function openModalById(mode, id) {
                const cached = BY_ID.get(id);
                const row = document.querySelector(`tr[data-id="${id}"]`);
                if (row) row.classList.add('is-loading');

                let fresh = null;
                try {
                    const res = await fetch(URL_SHOW(id), { headers: { Accept: 'application/json' } });
                    const data = await res.json().catch(() => ({}));
                    if (res.ok && data.data) {
                        fresh = data.data;
                        BY_ID.set(id, fresh);
                    } else if (res.status === 404) {
                        if (row) row.classList.remove('is-loading');
                        toastErr('Sản phẩm này không còn tồn tại — có thể vừa bị xoá. Hãy tải lại trang.');
                        return;
                    }
                } catch (err) {
                    // Rơi xuống dùng bản cũ ngay dưới.
                }
                if (row) row.classList.remove('is-loading');

                if (!fresh) {
                    if (!cached) { toastErr('Không tải được sản phẩm. Kiểm tra kết nối API.'); return; }
                    toastErr('Không tải được dữ liệu mới nhất — đang mở bản đã tải lúc vào trang.');
                }
                openModal(mode, fresh || cached);
            }

            // Trạng thái của modal đang mở — saveModal() nằm ngoài openModal() nên
            // ba giá trị này phải ở scope chung, không được khai bằng const bên trong.
            let galleryKnown = true;
            let variantsKnown = true;
            let galleryCount0 = 0;

            function openModal(mode, p) {
                const isEdit = mode === 'edit';
                const isView = mode === 'view';
                const g = (k, d = '') => ((isEdit || isView) && p && p[k] != null ? p[k] : d);

                const catOpts = CATEGORIES.map((c) => {
                    const sel = (isEdit || isView) && p && Number(p.category_id) === Number(c.id) ? 'selected' : '';
                    const pad = '&nbsp;&nbsp;'.repeat(Math.max(0, c.level)) + (c.level > 0 ? '└ ' : '');
                    return `<option value="${c.id}" ${sel}>${pad}${esc(c.name)}</option>`;
                }).join('');

                const brandOpts = ['<option value="">Chọn thương hiệu</option>'].concat(
                    BRANDS.map((b) => {
                        const sel = (isEdit || isView) && p && Number(p.brand_id) === Number(b.id) ? 'selected' : '';
                        return `<option value="${b.id}" ${sel}>${esc(b.name)}</option>`;
                    })
                ).join('');

                const kitOpts = ['<option value="">Chọn loại áo</option>'].concat(
                    Object.entries(KIT_TYPES).map(([val, label]) => {
                        const sel = (isEdit || isView) && p && p.kit_type === val ? 'selected' : '';
                        return `<option value="${val}" ${sel}>${esc(label)}</option>`;
                    })
                ).join('');

                // Trạng thái kinh doanh: sản phẩm mới mặc định "tạm ẩn" — khai xong
                // giá, ảnh, size rồi mới cho bán, thay vì vừa bấm Thêm là hàng chưa
                // có tồn đã nằm ngoài cửa hàng.
                const status = (isEdit || isView) && p
                    ? (STATUSES[p.status] ? p.status : (p.is_active ? 'active' : 'hidden'))
                    : 'hidden';
                const statusOpts = Object.entries(STATUSES)
                    .map(([val, label]) => `<option value="${val}" ${val === status ? 'selected' : ''}>${esc(label)}</option>`)
                    .join('');

                const isFeatured = (isEdit || isView) && p ? !!p.is_featured : false;
                const thumb = g('thumbnail', '');

                // Biến thể hiện có (khi sửa/xem) — lấy từ dữ liệu sản phẩm đã preload.
                const existingVariants = ((isEdit || isView) && p && Array.isArray(p.variants)) ? p.variants : [];
                const varRowsHtml = existingVariants.map((v) => variantRowHtml(v, isView)).join('');

                // Thư viện ảnh hiện có (khi sửa/xem).
                const gallery = ((isEdit || isView) && p && Array.isArray(p.images))
                    ? p.images.map((im) => ({ id: im.id || 0, url: im.url, is_primary: !!im.is_primary }))
                    : [];

                // Modal có THẬT SỰ nắm được ảnh & biến thể của sản phẩm này không?
                //
                // Lúc lưu, mảng rỗng gửi lên nghĩa là "xoá sạch" — API xoá cứng mọi
                // ảnh/biến thể không nằm trong danh sách. Nên nếu dữ liệu sản phẩm
                // về thiếu (API chưa chạy nên rơi về bản đã tải sẵn, hay bản cũ
                // trong bộ nhớ chưa có ảnh), modal PHẢI im lặng bỏ qua hai phần này
                // thay vì gửi mảng rỗng và xoá sạch của người ta.
                //
                // Thêm sản phẩm mới thì đương nhiên là nắm được: chưa có gì để mất.
                galleryKnown = !isEdit || !!(p && Array.isArray(p.images));
                variantsKnown = !isEdit || !!(p && Array.isArray(p.variants));
                // Số ảnh ban đầu — dùng để hỏi lại khi một lần lưu sẽ xoá sạch ảnh.
                galleryCount0 = gallery.length;

                const modalTitle = isView ? 'Chi tiết sản phẩm' : (isEdit ? 'Sửa sản phẩm' : 'Thêm sản phẩm');

                // Tổng tồn kho để in lên đầu modal — người sửa giá cần thấy ngay còn
                // bao nhiêu hàng, khỏi phải đóng modal ra tra lại bảng.
                const totalStock = existingVariants.reduce((s, v) => s + Math.max(0, Number(v.stock_quantity || 0)), 0);

                $modalMount.innerHTML = `
                    <div class="prd-overlay" id="prdOverlay">
                        <div class="prd-dialog" id="prdDialog">
                            <div class="prd-modal-head">
                                <div class="prd-modal-headmain">
                                    <h4 class="prd-modal-title">${modalTitle}</h4>
                                    ${(isEdit || isView) && p ? `<div class="prd-modal-sub">
                                        <span class="prd-code">${fmtProdCode(p.id)}</span>
                                        <span class="prd-modal-sep">·</span>
                                        <span class="prd-status prd-status-${status} is-static">
                                            <span class="prd-status-dot"></span>${esc(STATUSES[status] || '')}
                                        </span>
                                        <span class="prd-modal-sep">·</span>
                                        <span>Tồn kho ${fmtInt(totalStock)}</span>
                                    </div>` : '<div class="prd-modal-sub">Mã sản phẩm được cấp sau khi lưu</div>'}
                                </div>
                                <button type="button" class="prd-modal-x" id="prdModalX">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Chia tab thay vì một mạch cuộn dài 7 nhóm: người thêm sản
                                 phẩm mới đi lần lượt, người sửa giá nhảy thẳng vào tab Giá.
                                 Lỗi nằm ở tab đang đóng thì tab đó sáng đỏ và tự mở ra. --}}
                            <div class="prd-tabs" role="tablist">
                                <button type="button" class="prd-tab is-active" data-tab="info" role="tab">
                                    <span class="prd-tab-no">1</span>Thông tin
                                </button>
                                <button type="button" class="prd-tab" data-tab="price" role="tab">
                                    <span class="prd-tab-no">2</span>Giá &amp; biến thể
                                </button>
                                <button type="button" class="prd-tab" data-tab="media" role="tab">
                                    <span class="prd-tab-no">3</span>Hình ảnh &amp; mô tả
                                </button>
                                <button type="button" class="prd-tab" data-tab="publish" role="tab">
                                    <span class="prd-tab-no">4</span>Hiển thị &amp; SEO
                                </button>
                            </div>

                            <div class="prd-modal-body">
                            {{-- ===== Tab 1: Thông tin ===== --}}
                            <section class="prd-panel is-active" data-panel="info">
                                <p class="prd-section-title">Thông tin cơ bản</p>
                                <div class="prd-grid2">
                                    <div class="prd-col-2">
                                        <label class="prd-field-label" for="mName">Tên sản phẩm <span class="prd-req">*</span></label>
                                        <input type="text" id="mName" class="prd-input" placeholder="VD: Áo Real Madrid Sân Nhà 2024/2025" value="${esc(g('name'))}">
                                        <p class="prd-err" data-err="mName"></p>
                                    </div>
                                    <div>
                                        <label class="prd-field-label" for="mSku">SKU <span class="prd-req">*</span></label>
                                        <input type="text" id="mSku" class="prd-input" placeholder="Tự tạo — có thể sửa" value="${esc(g('sku'))}">
                                        <p class="prd-err" data-err="mSku"></p>
                                        <p class="prd-hint">Mỗi sản phẩm một SKU riêng. Hai sản phẩm cùng đội, cùng mùa, cùng loại áo sẽ sinh ra SKU giống nhau — thêm ký tự phân biệt vào.</p>
                                    </div>
                                </div>

                                <p class="prd-section-title">Phân loại</p>
                                <div class="prd-grid3">
                                    <div>
                                        <label class="prd-field-label" for="mCategory">Danh mục <span class="prd-req">*</span></label>
                                        <select id="mCategory" class="prd-msel">${catOpts}</select>
                                        <p class="prd-err" data-err="mCategory"></p>
                                    </div>
                                    <div>
                                        <label class="prd-field-label" for="mBrand">Thương hiệu</label>
                                        <div class="prd-input-with-btn">
                                            <select id="mBrand" class="prd-msel" data-ph>${brandOpts}</select>
                                            ${isView ? '' : `<button type="button" class="prd-addon-btn" id="mBrandAdd" title="Thêm nhanh thương hiệu">
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                                            </button>`}
                                        </div>
                                    </div>
                                    <div>
                                        <label class="prd-field-label" for="mKit">Loại áo</label>
                                        <select id="mKit" class="prd-msel" data-ph>${kitOpts}</select>
                                        <p class="prd-hint">FAN và PLAYER là hai sản phẩm riêng, không phải hai biến thể.</p>
                                    </div>
                                </div>
                                <div class="prd-grid2">
                                    <div>
                                        <label class="prd-field-label" for="mTeam">Đội bóng / Đội tuyển</label>
                                        <input type="text" id="mTeam" class="prd-input" placeholder="VD: Real Madrid" value="${esc(g('team'))}">
                                    </div>
                                    <div>
                                        <label class="prd-field-label" for="mSeason">Mùa giải</label>
                                        <input type="text" id="mSeason" class="prd-input" placeholder="VD: 2024/2025" value="${esc(g('season'))}">
                                    </div>
                                </div>
                            </section>

                            {{-- ===== Tab 2: Giá & biến thể ===== --}}
                            <section class="prd-panel" data-panel="price">
                                <p class="prd-section-title">Giá</p>
                                <div class="prd-grid3">
                                    <div>
                                        <label class="prd-field-label" for="mBasePrice">Giá gốc <span class="prd-req">*</span></label>
                                        <div class="prd-input-prefix">
                                            <input type="text" inputmode="numeric" id="mBasePrice" class="prd-input" placeholder="0" value="${(isEdit || isView) && p && p.base_price != null ? fmtVnd(p.base_price) : ''}">
                                            <span class="prd-input-suffix">₫</span>
                                        </div>
                                        <p class="prd-err" data-err="mBasePrice"></p>
                                    </div>
                                    <div>
                                        <label class="prd-field-label" for="mSalePrice">Giá khuyến mãi</label>
                                        <div class="prd-input-prefix">
                                            <input type="text" inputmode="numeric" id="mSalePrice" class="prd-input" placeholder="Bỏ trống nếu không giảm" value="${p && p.sale_price != null ? fmtVnd(p.sale_price) : ''}">
                                            <span class="prd-input-suffix">₫</span>
                                        </div>
                                        <p class="prd-err" data-err="mSalePrice"></p>
                                    </div>
                                    <div>
                                        <label class="prd-field-label" for="mCostPrice">Giá vốn</label>
                                        <div class="prd-input-prefix">
                                            <input type="text" inputmode="numeric" id="mCostPrice" class="prd-input" placeholder="Chưa khai" value="${p && p.cost_price != null ? fmtVnd(p.cost_price) : ''}">
                                            <span class="prd-input-suffix">₫</span>
                                        </div>
                                        <p class="prd-err" data-err="mCostPrice"></p>
                                    </div>
                                </div>
                                {{-- Giá vốn không hiện ra storefront; nó chỉ để tính giá trị tồn kho
                                     ở trang Tồn kho. Bỏ trống thì hàng của sản phẩm này không được
                                     cộng vào giá trị kho và trang Tồn kho sẽ báo còn thiếu. --}}
                                <p class="prd-hint">Giá vốn chỉ dùng để tính giá trị tồn kho, không hiển thị cho khách. Bỏ trống = chưa khai.</p>
                                {{-- Bảng xem trước giá: ba nguồn giá chồng lên nhau rất dễ nhầm,
                                     in thẳng con số khách sẽ trả ra đây thì khỏi phải đoán. --}}
                                <div class="prd-price-preview" id="mPricePreview"></div>

                                <p class="prd-section-title">Biến thể (size / màu) <span class="prd-req">*</span></p>
                                <div class="prd-var">
                                    ${isView ? '' : `{{-- Thêm nhanh nhiều size cùng lúc --}}
                                    <div class="prd-var-quick">
                                        <input type="text" id="mVarQColor" class="prd-input prd-var-q-color" placeholder="Màu chung (tùy chọn)">
                                        <input type="text" id="mVarQSizes" class="prd-input prd-var-q-sizes" placeholder="Nhiều size, cách nhau dấu phẩy: S, M, L, XL">
                                        <button type="button" class="prd-btn-primary" id="mVarQAdd">Thêm các size</button>
                                    </div>`}

                                    <div class="prd-var-head">
                                        <span>Màu</span>
                                        <span>Size <span class="prd-req">*</span></span>
                                        <span>Giá riêng</span>
                                        <span>Giá vốn riêng</span>
                                        <span style="text-align:right">Tồn kho</span>
                                        <span></span>
                                    </div>
                                    <div id="mVariants">${varRowsHtml}</div>
                                    <p class="prd-err" data-err="mVariants"></p>
                                    ${isView ? '' : `<button type="button" class="prd-btn-ghost prd-var-add" id="mVarAdd">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                                        Thêm biến thể
                                    </button>
                                    <p class="prd-hint">Cần ít nhất 1 biến thể (size). SKU biến thể tự sinh nếu không nhập.</p>
                                    {{-- Cảnh báo có thật, không phải câu cho đủ: giá riêng của
                                         biến thể ĐÈ giá khuyến mãi của sản phẩm lúc tính tiền. --}}
                                    <p class="prd-warn">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6A2 2 0 0 0 22 18L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                                        Khai <b>giá riêng</b> cho biến thể nào thì biến thể đó thu tiền theo giá riêng, <b>bỏ qua giá khuyến mãi</b> của sản phẩm. Chương trình khuyến mãi theo đợt vẫn áp bình thường.
                                    </p>
                                    <p class="prd-hint">Tồn kho chỉ xem, không sửa ở đây. Biến thể mới luôn bắt đầu ở 0 — muốn có hàng bán thì vào <a href="{{ route('admin.inventory.index') }}" target="_blank">Kho</a> để nhập hàng hoặc điều chỉnh.</p>`}
                                </div>
                            </section>

                            {{-- ===== Tab 3: Hình ảnh & mô tả ===== --}}
                            <section class="prd-panel" data-panel="media">
                                <div>
                                    <label class="prd-field-label">Ảnh đại diện</label>
                                    <div class="prd-img-field">
                                        <div class="prd-img-preview" id="mImgPreview"></div>
                                        ${isView ? '' : `<div class="prd-img-actions">
                                            <div class="prd-img-btns">
                                                <button type="button" class="prd-btn-ghost" id="mImgPick">Chọn ảnh</button>
                                                <button type="button" class="prd-btn-ghost prd-img-remove" id="mImgRemove">Xoá ảnh</button>
                                            </div>
                                            <p class="prd-hint">JPG, PNG, WEBP, AVIF — tối đa 10MB, ảnh lớn sẽ được tự thu nhỏ</p>
                                        </div>
                                        <input type="file" id="mImgInput" accept="image/*" hidden>`}
                                    </div>
                                </div>
                                <div>
                                    <label class="prd-field-label">Thư viện ảnh</label>
                                    <div class="prd-gallery" id="mGallery"></div>
                                    ${isView ? '' : `<div class="prd-gallery-foot">
                                        <button type="button" class="prd-btn-ghost" id="mGalleryPick">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                                            Thêm ảnh
                                        </button>
                                        <p class="prd-hint">Chọn được nhiều ảnh (mỗi ảnh ≤ 10MB). Bấm ★ để đặt ảnh chính — dùng làm ảnh đại diện nếu để trống ô trên.</p>
                                    </div>
                                    <input type="file" id="mGalleryInput" accept="image/*" multiple hidden>`}
                                </div>
                                <div>
                                    <label class="prd-field-label" for="mShortDesc">Mô tả ngắn</label>
                                    <textarea id="mShortDesc" class="prd-textarea" maxlength="500" placeholder="Một hai câu giới thiệu — cũng là đoạn hiện trên Google nếu để trống ô Meta description">${esc(g('short_description'))}</textarea>
                                </div>
                                <div>
                                    <label class="prd-field-label" for="mDesc">Mô tả chi tiết</label>
                                    <textarea id="mDesc" class="prd-textarea" placeholder="Chất liệu, form dáng, hướng dẫn chọn size, bảo quản...">${esc(g('description'))}</textarea>
                                </div>
                            </section>

                            {{-- ===== Tab 4: Hiển thị & SEO ===== --}}
                            <section class="prd-panel" data-panel="publish">
                                <p class="prd-section-title">Trạng thái kinh doanh</p>
                                <div class="prd-grid2">
                                    <div>
                                        <label class="prd-field-label" for="mStatus">Trạng thái</label>
                                        <select id="mStatus" class="prd-msel">${statusOpts}</select>
                                        {{-- Câu giải thích đổi theo mức đang chọn — ba mức nghe
                                             na ná nhau, không nói rõ là chọn bừa. --}}
                                        <p class="prd-hint" id="mStatusHint">${esc(STATUS_HINTS[status] || '')}</p>
                                    </div>
                                    <div>
                                        <label class="prd-field-label">Nổi bật</label>
                                        <div class="prd-switch-row">
                                            <button type="button" class="prd-switch ${isFeatured ? 'on' : ''}" id="mFeatured" data-on="${isFeatured ? 1 : 0}"><span class="prd-switch-knob"></span></button>
                                            <span class="prd-switch-label" id="mFeaturedLabel">${isFeatured ? 'Có' : 'Không'}</span>
                                        </div>
                                        <p class="prd-hint">Sản phẩm nổi bật xuất hiện ở khối “Xu hướng” ngoài trang chủ.</p>
                                    </div>
                                </div>

                                <p class="prd-section-title">SEO (tùy chọn)</p>
                                <div class="prd-grid2">
                                    <div class="prd-col-2">
                                        <label class="prd-field-label" for="mMetaTitle">Meta title</label>
                                        <input type="text" id="mMetaTitle" class="prd-input" maxlength="255" placeholder="Bỏ trống = dùng tên sản phẩm" value="${esc(g('meta_title'))}">
                                        <p class="prd-hint">Tiêu đề hiện trên Google. Khoảng 60 ký tự là vừa, dài hơn sẽ bị cắt.</p>
                                    </div>
                                    <div class="prd-col-2">
                                        <label class="prd-field-label" for="mMetaDesc">Meta description</label>
                                        <input type="text" id="mMetaDesc" class="prd-input" maxlength="320" placeholder="Bỏ trống = tự lấy từ mô tả ngắn" value="${esc(g('meta_description'))}">
                                        <p class="prd-hint">Đoạn mô tả dưới tiêu đề trên Google. 150–160 ký tự là vừa.</p>
                                    </div>
                                </div>
                            </section>
                            </div>

                            <div class="prd-modal-foot">
                                {{-- Câu tóm tắt lỗi nằm ngay cạnh nút Lưu: người dùng bấm Lưu
                                     là mắt đang ở đây, báo lỗi tận trên đầu trang thì không thấy. --}}
                                <p class="prd-foot-msg" id="prdModalMsg"></p>
                                <div class="prd-foot-btns">
                                    <button type="button" class="prd-btn-ghost" id="prdModalClose">Đóng</button>
                                    ${isView
                                        ? `<button type="button" class="prd-btn-primary" id="prdModalToEdit">Chuyển sang sửa</button>`
                                        : `<button type="button" class="prd-btn-primary" id="prdModalSave">${isEdit ? 'Cập nhật' : 'Thêm sản phẩm'}</button>`
                                    }
                                </div>
                            </div>
                        </div>
                    </div>`;

                const dialog = document.getElementById('prdDialog');
                dialog.dataset.mode = mode;
                dialog.dataset.id = (isEdit || isView) && p ? p.id : '';
                dialog.dataset.slug = (isEdit || isView) && p ? (p.slug || '') : '';
                dialog.dataset.thumbnail = thumb || '';

                // Đóng modal
                document.getElementById('prdModalX').addEventListener('click', closeModal);
                document.getElementById('prdModalClose').addEventListener('click', closeModal);

                // Chuyển tab. Cuộn lên đầu mỗi lần đổi — không thì sang tab ngắn hơn
                // là màn hình đứng ở chỗ trống.
                const body = dialog.querySelector('.prd-modal-body');
                dialog.querySelectorAll('.prd-tab').forEach((tab) => {
                    tab.addEventListener('click', () => showTab(dialog, tab.dataset.tab, body));
                });

                wireSwitch('mFeatured', 'mFeaturedLabel', ['Có', 'Không'], isView);

                // Câu giải thích đi theo mức trạng thái đang chọn.
                const statusEl = document.getElementById('mStatus');
                const statusHintEl = document.getElementById('mStatusHint');
                if (statusEl && statusHintEl) {
                    statusEl.addEventListener('change', () => {
                        statusHintEl.textContent = STATUS_HINTS[statusEl.value] || '';
                    });
                }

                if (isView) {
                    const dialogEl = document.getElementById('prdDialog');
                    dialogEl.querySelectorAll('input, select, textarea').forEach(el => {
                        el.disabled = true;
                    });
                    const toEditBtn = document.getElementById('prdModalToEdit');
                    if (toEditBtn) {
                        toEditBtn.addEventListener('click', () => {
                            openModal('edit', p);
                        });
                    }
                } else {
                    // Ô giá — định dạng tiền VN ngay khi gõ (1.000.000)
                    wireMoney('mBasePrice');
                    wireMoney('mSalePrice');
                    wireMoney('mCostPrice');
                    ['mBasePrice', 'mSalePrice', 'mCostPrice'].forEach((id) => {
                        const el = document.getElementById(id);
                        if (el) el.addEventListener('input', renderPricePreview);
                    });
                    renderPricePreview();

                    // Biến thể: thêm dòng, xoá dòng, định dạng tiền/số khi gõ.
                    const varsBox = document.getElementById('mVariants');
                    const mVarAddBtn = document.getElementById('mVarAdd');
                    if (mVarAddBtn) {
                        mVarAddBtn.addEventListener('click', () => {
                            varsBox.insertAdjacentHTML('beforeend', variantRowHtml(null, false));
                        });
                    }

                    // Thêm nhanh nhiều size: mỗi size -> một dòng (chung màu), bỏ trùng.
                    function quickAddSizes() {
                        const color = document.getElementById('mVarQColor').value.trim();
                        const sizesInput = document.getElementById('mVarQSizes');
                        const sizes = sizesInput.value.split(/[,;\n]+/).map((s) => s.trim()).filter(Boolean);
                        if (!sizes.length) { toastErr('Nhập ít nhất một size (cách nhau dấu phẩy).'); return; }

                        const rowKey = (s, c) => (s + '|' + c).toLowerCase();
                        const existing = new Set([...varsBox.querySelectorAll('.prd-var-row')].map((r) => rowKey(
                            r.querySelector('.prd-var-size').value.trim(),
                            r.querySelector('.prd-var-color').value.trim(),
                        )));

                        let added = 0;
                        const seen = new Set();
                        sizes.forEach((sz) => {
                            const key = rowKey(sz, color);
                            if (existing.has(key) || seen.has(key)) return;
                            seen.add(key);
                            varsBox.insertAdjacentHTML('beforeend', variantRowHtml({ color: color, size: sz }, false));
                            added++;
                        });
                        if (added === 0) { toastErr('Các size này đã có trong danh sách.'); return; }
                        sizesInput.value = '';
                        sizesInput.focus();
                    }
                    const mVarQAddBtn = document.getElementById('mVarQAdd');
                    if (mVarQAddBtn) mVarQAddBtn.addEventListener('click', quickAddSizes);
                    const mVarQSizesInput = document.getElementById('mVarQSizes');
                    if (mVarQSizesInput) {
                        mVarQSizesInput.addEventListener('keydown', (e) => {
                            if (e.key === 'Enter') { e.preventDefault(); quickAddSizes(); }
                        });
                    }

                    // Thêm nhanh thương hiệu (nút +)
                    const brandAddBtn = document.getElementById('mBrandAdd');
                    if (brandAddBtn) brandAddBtn.addEventListener('click', () => openBrandQuickModal(addBrandToSelect));

                    // SKU tự tạo từ đội bóng · loại áo · mùa giải (vẫn sửa tay được).
                    let skuDirty = isEdit; // khi sửa: giữ SKU cũ, không tự đè.
                    const skuEl = document.getElementById('mSku');
                    if (skuEl) skuEl.addEventListener('input', () => { skuDirty = true; });
                    const refreshSku = () => { if (!skuDirty && skuEl) skuEl.value = genSku(); };
                    ['mName', 'mTeam', 'mSeason'].forEach((id) => {
                        const el = document.getElementById(id);
                        if (el) el.addEventListener('input', refreshSku);
                    });
                    const mKitEl = document.getElementById('mKit');
                    if (mKitEl) mKitEl.addEventListener('change', refreshSku);
                    if (!isEdit) refreshSku();
                }

                // Thư viện ảnh: render
                const galleryBox = document.getElementById('mGallery');
                function renderGallery() {
                    // Không nắm được thư viện ảnh thì nói thẳng, và khoá luôn phần
                    // này lại: để người dùng thêm ảnh ở đây thì lúc lưu chúng sẽ bị
                    // bỏ qua trong im lặng (payload không gửi khoá images), còn gửi
                    // đi thì xoá mất những ảnh mình không biết. Cả hai đều tệ hơn
                    // là bảo họ tải lại trang.
                    if (!galleryKnown) {
                        galleryBox.innerHTML = '<p class="prd-gallery-empty">Chưa đọc được thư viện ảnh của sản phẩm này. '
                            + 'Ảnh hiện có vẫn an toàn và sẽ được giữ nguyên khi bạn lưu — '
                            + 'muốn sửa ảnh thì tải lại trang rồi mở lại sản phẩm.</p>';
                        return;
                    }
                    if (!gallery.length) { galleryBox.innerHTML = '<p class="prd-gallery-empty">Chưa có ảnh nào.</p>'; return; }
                    galleryBox.innerHTML = gallery.map((im, i) => `
                        <div class="prd-gimg ${im.is_primary ? 'is-primary' : ''}" data-id="${im.id || 0}">
                            <img src="${esc(im.url)}" alt="">
                            ${im.is_primary ? '<span class="prd-gimg-badge">Ảnh chính</span>' : ''}
                            ${isView ? '' : `<button type="button" class="prd-gimg-star" data-star="${i}" title="Đặt làm ảnh chính">★</button>
                            <button type="button" class="prd-gimg-del" data-del="${i}" title="Xoá ảnh">×</button>`}
                        </div>`).join('');
                }

                if (!isView) {
                    const galleryInput = document.getElementById('mGalleryInput');
                    const galleryPick = document.getElementById('mGalleryPick');
                    // Nút vẫn bấm được nhưng nói rõ lý do, không đứng im như hỏng.
                    if (galleryPick) galleryPick.addEventListener('click', () => {
                        if (!galleryKnown) {
                            toastErr('Chưa đọc được thư viện ảnh của sản phẩm này nên tạm thời không thêm ảnh được. '
                                + 'Hãy tải lại trang rồi mở lại sản phẩm — ảnh đang có vẫn nguyên vẹn.');
                            return;
                        }
                        galleryInput.click();
                    });
                    if (galleryInput) {
                        galleryInput.addEventListener('change', async () => {
                            const files = [...(galleryInput.files || [])];
                            galleryInput.value = '';
                            if (!files.length) return;
                            galleryPick.disabled = true;
                            for (const file of files) {
                                if (!file.type.startsWith('image/')) { toastErr(`"${file.name}" không phải ảnh — bỏ qua.`); continue; }
                                if (file.size > MAX_IMG_BYTES) { toastErr(`"${file.name}" vượt 10MB — bỏ qua.`); continue; }
                                try {
                                    const fd = new FormData();
                                    fd.append('image', file);
                                    const res = await fetch(URL_UPLOAD, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
                                    const data = await res.json().catch(() => ({}));
                                    if (!res.ok) { toastErr(data.message || `Tải "${file.name}" thất bại.`); continue; }
                                    gallery.push({ id: 0, url: data.url, is_primary: gallery.length === 0 });
                                    renderGallery();
                                } catch (err) {
                                    toastErr('Không kết nối được máy chủ để tải ảnh.');
                                }
                            }
                            galleryPick.disabled = false;
                        });
                    }
                    if (galleryBox) {
                        galleryBox.addEventListener('click', (e) => {
                            const star = e.target.closest('[data-star]');
                            if (star) { const i = +star.getAttribute('data-star'); gallery.forEach((im, k) => { im.is_primary = k === i; }); renderGallery(); return; }
                            const del = e.target.closest('[data-del]');
                            if (del) {
                                const i = +del.getAttribute('data-del');
                                const wasPrimary = gallery[i].is_primary;
                                gallery.splice(i, 1);
                                if (wasPrimary && gallery.length) gallery[0].is_primary = true;
                                renderGallery();
                            }
                        });
                    }
                }
                renderGallery();

                const varsBox = document.getElementById('mVariants');
                if (!isView && varsBox) {
                    varsBox.addEventListener('click', (e) => {
                        const del = e.target.closest('.prd-var-del');
                        if (del) del.closest('.prd-var-row').remove();
                    });
                    varsBox.addEventListener('input', (e) => {
                        if (e.target.classList.contains('prd-var-price')
                            || e.target.classList.contains('prd-var-cost')) formatMoneyEl(e.target);
                    });
                }

                // Ảnh đại diện
                const imgInput = document.getElementById('mImgInput');
                const imgPreview = document.getElementById('mImgPreview');
                const imgRemove = document.getElementById('mImgRemove');
                const phSvg = `<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>`;
                const renderImg = (url) => {
                    dialog.dataset.thumbnail = url || '';
                    if (imgPreview) imgPreview.innerHTML = url ? `<img src="${esc(url)}" alt="">` : phSvg;
                    if (imgRemove) imgRemove.style.display = (!isView && url) ? '' : 'none';
                };
                renderImg(thumb);

                if (!isView) {
                    const mImgPickBtn = document.getElementById('mImgPick');
                    if (mImgPickBtn) mImgPickBtn.addEventListener('click', () => imgInput.click());
                    if (imgRemove) imgRemove.addEventListener('click', () => { renderImg(''); imgInput.value = ''; });
                    if (imgInput) {
                        imgInput.addEventListener('change', async () => {
                            const file = imgInput.files && imgInput.files[0];
                            if (!file) return;
                            if (!file.type.startsWith('image/')) { toastErr('File tải lên không phải ảnh.'); imgInput.value = ''; return; }
                            if (file.size > MAX_IMG_BYTES) { toastErr('Ảnh vượt quá 10MB.'); imgInput.value = ''; return; }
                            imgPreview.classList.add('is-loading');
                            try {
                                const fd = new FormData();
                                fd.append('image', file);
                                const res = await fetch(URL_UPLOAD, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
                                const data = await res.json().catch(() => ({}));
                                if (!res.ok) { toastErr(data.message || 'Tải ảnh thất bại, vui lòng thử lại.'); return; }
                                renderImg(data.url);
                            } catch (err) {
                                toastErr('Không kết nối được máy chủ để tải ảnh.');
                            } finally {
                                imgPreview.classList.remove('is-loading');
                                imgInput.value = '';
                            }
                        });
                    }

                    const saveBtn = document.getElementById('prdModalSave');
                    if (saveBtn) saveBtn.addEventListener('click', saveModal);
                }
                setTimeout(() => {
                    const nameEl = document.getElementById('mName');
                    if (nameEl && !isView) nameEl.focus();
                }, 0);
            }

            /** Hiện một tab của modal, ẩn các tab còn lại. */
            function showTab(dialog, key, body) {
                dialog.querySelectorAll('.prd-tab').forEach((t) => t.classList.toggle('is-active', t.dataset.tab === key));
                dialog.querySelectorAll('.prd-panel').forEach((s) => s.classList.toggle('is-active', s.dataset.panel === key));
                if (body) body.scrollTop = 0;
            }

            /** Tab chứa một ô nhập — dùng để nhảy tới đúng chỗ khi ô đó báo lỗi. */
            function tabOfField(id) {
                const el = document.getElementById(id) || document.querySelector(`[data-err="${id}"]`);
                const panel = el ? el.closest('.prd-panel') : null;
                return panel ? panel.dataset.panel : 'info';
            }

            /** Xoá hết dấu lỗi đang hiện trong modal. */
            function clearErrors(dialog) {
                dialog.querySelectorAll('.prd-err').forEach((el) => { el.textContent = ''; });
                dialog.querySelectorAll('.prd-input.is-err, .prd-msel.is-err').forEach((el) => el.classList.remove('is-err'));
                dialog.querySelectorAll('.prd-tab.has-err').forEach((el) => el.classList.remove('has-err'));
                const msg = document.getElementById('prdModalMsg');
                if (msg) { msg.textContent = ''; msg.classList.remove('is-err'); }
            }

            /**
             * Báo lỗi ngay tại ô sai: tô viền đỏ, in câu lỗi dưới ô, đánh dấu tab
             * chứa nó rồi mở tab đó và đưa con trỏ vào.
             *
             * Trước đây mọi lỗi đều đổ ra một toast góc màn hình — người dùng đọc
             * xong vẫn phải tự dò xem ô nào sai giữa mấy chục ô.
             */
            function showFieldError(dialog, fieldId, message) {
                const body = dialog.querySelector('.prd-modal-body');
                const slot = dialog.querySelector(`[data-err="${fieldId}"]`);
                const input = document.getElementById(fieldId);
                const key = tabOfField(fieldId);

                if (slot) slot.textContent = message;
                if (input) input.classList.add('is-err');
                const tab = dialog.querySelector(`.prd-tab[data-tab="${key}"]`);
                if (tab) tab.classList.add('has-err');

                showTab(dialog, key, body);

                const msg = document.getElementById('prdModalMsg');
                if (msg) { msg.textContent = message; msg.classList.add('is-err'); }

                if (input) {
                    input.focus();
                    input.scrollIntoView({ block: 'center', behavior: 'smooth' });
                } else if (slot) {
                    slot.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            }

            /**
             * Xem trước giá khách thực trả.
             *
             * Ba nguồn giá chồng lên nhau (giá gốc, giá khuyến mãi, giá riêng của
             * biến thể) rất dễ nhầm, nên in thẳng con số cuối cùng ra đây thay vì
             * để người bán tự suy.
             */
            function renderPricePreview() {
                const box = document.getElementById('mPricePreview');
                if (!box) return;
                const base = Number(digitsOnly(document.getElementById('mBasePrice').value) || 0);
                const sale = Number(digitsOnly(document.getElementById('mSalePrice').value) || 0);
                const cost = Number(digitsOnly(document.getElementById('mCostPrice').value) || 0);

                if (base <= 0) { box.innerHTML = ''; return; }

                const hasSale = sale > 0 && sale < base;
                const now = hasSale ? sale : base;
                const off = hasSale ? Math.round((base - now) / base * 100) : 0;
                const margin = cost > 0 ? now - cost : null;

                box.innerHTML = `
                    <span class="prd-pp-item">
                        <small>Khách trả</small>
                        <b>${fmtVnd(now)}₫</b>
                    </span>
                    ${hasSale ? `<span class="prd-pp-item">
                        <small>Giảm</small>
                        <b class="prd-pp-off">${off}%</b>
                    </span>` : ''}
                    ${margin !== null ? `<span class="prd-pp-item">
                        <small>Lãi gộp mỗi cái</small>
                        <b class="${margin < 0 ? 'prd-pp-loss' : ''}">${margin < 0 ? '−' : ''}${fmtVnd(Math.abs(margin))}₫</b>
                    </span>` : ''}
                    ${margin !== null && margin < 0 ? '<span class="prd-pp-note">Đang bán dưới giá vốn.</span>' : ''}`;
            }

            function wireSwitch(btnId, labelId, labels, isView = false) {
                const btn = document.getElementById(btnId);
                const label = document.getElementById(labelId);
                if (!btn) return;
                if (isView) {
                    btn.style.cursor = 'default';
                    btn.disabled = true;
                    return;
                }
                btn.addEventListener('click', () => {
                    const on = btn.dataset.on === '1';
                    btn.dataset.on = on ? '0' : '1';
                    btn.classList.toggle('on', !on);
                    label.textContent = on ? labels[1] : labels[0];
                });
            }

            // Định dạng tiền VN cho 1 ô, giữ nguyên vị trí con trỏ khi chèn dấu chấm.
            function formatMoneyEl(el) {
                const before = el.value;
                const caretFromEnd = before.length - el.selectionStart;
                el.value = fmtVnd(before);
                const pos = Math.max(0, el.value.length - caretFromEnd);
                el.setSelectionRange(pos, pos);
            }
            function wireMoney(id) {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', () => formatMoneyEl(el));
            }

            // HTML một dòng biến thể (v = null khi thêm dòng trống).
            // Tồn kho hiển thị để đối chiếu nhưng KHÔNG phải ô nhập: cột này chỉ
            // đổi qua nghiệp vụ kho nên mọi biến động đều có vết trong sổ kho.
            function variantRowHtml(v, isView = false) {
                const vid = v && v.id ? v.id : 0;
                const color = v ? esc(v.color || '') : '';
                const size = v ? esc(v.size || '') : '';
                const price = v && v.price != null ? fmtVnd(v.price) : '';
                const cost = v && v.cost_price != null ? fmtVnd(v.cost_price) : '';
                const stock = v && v.stock_quantity != null ? Number(v.stock_quantity) : 0;
                const stockTitle = vid
                    ? 'Tồn kho hiện tại — đổi ở trang Kho'
                    : 'Biến thể mới luôn bắt đầu ở 0, phải nhập kho mới có hàng';
                return `
                    <div class="prd-var-row" data-vid="${vid}">
                        <input type="text" class="prd-input prd-var-color" placeholder="VD: Trắng" value="${color}">
                        <input type="text" class="prd-input prd-var-size" placeholder="VD: M" value="${size}">
                        <div class="prd-input-prefix">
                            <input type="text" inputmode="numeric" class="prd-input prd-var-price" placeholder="Theo giá SP" value="${price}">
                            <span class="prd-input-suffix">₫</span>
                        </div>
                        <div class="prd-input-prefix">
                            <input type="text" inputmode="numeric" class="prd-input prd-var-cost" placeholder="Theo giá vốn SP" value="${cost}">
                            <span class="prd-input-suffix">₫</span>
                        </div>
                        <span class="prd-var-stock ${stock === 0 ? 'is-zero' : ''}" title="${stockTitle}">${fmtInt(stock)}</span>
                        ${isView ? '<span></span>' : '<button type="button" class="prd-var-del" title="Xoá biến thể">×</button>'}
                    </div>`;
            }

            function closeModal() { $modalMount.innerHTML = ''; }

            function saveModal() {
                const dialog = document.getElementById('prdDialog');
                const val = (id) => document.getElementById(id).value.trim();
                clearErrors(dialog);

                const name = val('mName');
                if (!name) { showFieldError(dialog, 'mName', 'Vui lòng nhập tên sản phẩm.'); return; }
                const sku = val('mSku');
                if (!sku) { showFieldError(dialog, 'mSku', 'Vui lòng nhập mã SKU.'); return; }
                const categoryId = document.getElementById('mCategory').value;
                if (!categoryId) { showFieldError(dialog, 'mCategory', 'Vui lòng chọn danh mục.'); return; }
                const basePrice = digitsOnly(document.getElementById('mBasePrice').value);
                if (basePrice === '' || Number(basePrice) < 0) { showFieldError(dialog, 'mBasePrice', 'Vui lòng nhập giá gốc.'); return; }
                const salePrice = digitsOnly(document.getElementById('mSalePrice').value);
                if (salePrice !== '' && Number(salePrice) > Number(basePrice)) {
                    showFieldError(dialog, 'mSalePrice', 'Giá khuyến mãi phải nhỏ hơn hoặc bằng giá gốc.');
                    return;
                }
                // Giá vốn cao hơn giá bán chỉ cảnh báo chứ không chặn: hàng thanh lý bán
                // dưới giá vốn là chuyện có thật, chặn ở đây là chặn nghiệp vụ đúng.
                const costPrice = digitsOnly(document.getElementById('mCostPrice').value);

                const fields = {
                    name,
                    sku,
                    category_id: categoryId,
                    brand_id: document.getElementById('mBrand').value,
                    slug: dialog.dataset.slug || '',
                    team: val('mTeam'),
                    season: val('mSeason'),
                    kit_type: document.getElementById('mKit').value,
                    base_price: basePrice,
                    sale_price: salePrice,
                    cost_price: costPrice,
                    thumbnail: dialog.dataset.thumbnail || '',
                    short_description: document.getElementById('mShortDesc').value.trim(),
                    description: document.getElementById('mDesc').value.trim(),
                    meta_title: val('mMetaTitle'),
                    meta_description: val('mMetaDesc'),
                    // Gửi status thôi — API tự suy is_active ra từ nó. Gửi cả hai là
                    // có ngày chúng lệch nhau.
                    status: document.getElementById('mStatus').value,
                    is_featured: document.getElementById('mFeatured').dataset.on === '1',
                };

                // Gom biến thể: bỏ dòng trống, chặn thiếu size / trùng size+màu.
                // Không gửi tồn kho — cột đó chỉ đổi qua nghiệp vụ kho.
                const seen = new Set();
                let vi = 0;
                let variantErr = null;
                [...document.querySelectorAll('#mVariants .prd-var-row')].forEach((row) => {
                    const color = row.querySelector('.prd-var-color').value.trim();
                    const size = row.querySelector('.prd-var-size').value.trim();
                    const price = digitsOnly(row.querySelector('.prd-var-price').value);
                    const cost = digitsOnly(row.querySelector('.prd-var-cost').value);
                    if (!size && !color && !price && !cost) return; // dòng trống
                    if (!size) {
                        variantErr = variantErr || 'Mỗi biến thể phải có Size.';
                        row.querySelector('.prd-var-size').classList.add('is-err');

                        return;
                    }
                    const key = (size + '|' + color).toLowerCase();
                    if (seen.has(key)) {
                        const tag = [size, color].filter(Boolean).join(' / ');
                        variantErr = variantErr || `Biến thể trùng: ${tag}.`;
                        return;
                    }
                    seen.add(key);
                    fields[`variants[${vi}][id]`] = row.dataset.vid || '0';
                    fields[`variants[${vi}][size]`] = size;
                    fields[`variants[${vi}][color]`] = color;
                    fields[`variants[${vi}][price]`] = price;          // '' nếu theo giá SP
                    fields[`variants[${vi}][cost_price]`] = cost;      // '' nếu theo giá vốn SP
                    vi++;
                });
                if (variantErr) { showFieldError(dialog, 'mVariants', variantErr); return; }
                // Không đọc được biến thể thì báo đúng lý do, đừng bảo người ta
                // "phải có ít nhất 1 size" trong khi sản phẩm vốn đang có đủ.
                if (vi === 0 && !variantsKnown) {
                    showFieldError(dialog, 'mVariants', 'Chưa đọc được danh sách biến thể của sản phẩm này. '
                        + 'Hãy tải lại trang rồi mở lại — biến thể đang có vẫn nguyên vẹn.');
                    return;
                }
                if (vi === 0) {
                    showFieldError(dialog, 'mVariants', 'Sản phẩm phải có ít nhất 1 biến thể (size). Dùng ô “Thêm các size” ở trên cho nhanh.');
                    return;
                }

                // Biến thể chỉ được gửi khi modal nắm được chúng — xem galleryKnown.
                if (!variantsKnown) {
                    Object.keys(fields).forEach((k) => { if (k.startsWith('variants[')) delete fields[k]; });
                } else {
                    fields['variants_loaded'] = 1;
                }

                // Thư viện ảnh (đọc từ các thẻ đã render).
                let anhGui = 0;
                if (galleryKnown) {
                    fields['images_loaded'] = 1;
                    [...document.querySelectorAll('#mGallery .prd-gimg')].forEach((card, i) => {
                        const img = card.querySelector('img');
                        if (!img) return;
                        fields[`images[${i}][id]`] = card.dataset.id || '0';
                        fields[`images[${i}][url]`] = img.getAttribute('src');
                        fields[`images[${i}][is_primary]`] = card.classList.contains('is-primary') ? 1 : 0;
                        fields[`images[${i}][sort_order]`] = i;
                        anhGui++;
                    });
                }

                const luu = () => {
                    if (dialog.dataset.mode === 'add') {
                        postForm(URL_STORE, 'POST', fields);
                    } else {
                        postForm(`${URL_BASE}/${dialog.dataset.id}`, 'PUT', fields);
                    }
                };

                // Sản phẩm đang có ảnh mà lưu lại thành không còn ảnh nào — hỏi lại
                // một câu. Xoá hết ảnh là việc hiếm và không hoàn tác được, trong khi
                // bấm nhầm nút × trên thẻ ảnh thì quá dễ.
                if (galleryKnown && galleryCount0 > 0 && anhGui === 0) {
                    sysDelete({
                        title: 'Lưu mà không còn ảnh nào?',
                        message: `Sản phẩm này đang có ${galleryCount0} ảnh, nhưng thư viện ảnh hiện đang trống. `
                            + 'Lưu tiếp là toàn bộ ảnh bị xoá và không lấy lại được.',
                        confirmText: 'Vẫn xoá hết ảnh',
                        cancelText: 'Quay lại',
                    }).then((ok) => { if (ok) luu(); });
                    return;
                }

                luu();
            }

            // Đóng modal bằng phím Esc (modal nhỏ ưu tiên trước).
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                if ($miniMount.innerHTML) { closeBrandQuick(); return; }
                if ($modalMount.innerHTML) closeModal();
            });

            document.getElementById('prdAddBtn').addEventListener('click', () => openModal('add', null));

            // Dropdown "Tiện ích" và "Cột": mở/đóng, mở cái này thì đóng cái kia,
            // bấm ra ngoài đóng hết.
            (function () {
                const drops = [['prdUtil', 'prdUtilBtn'], ['prdCols', 'prdColsBtn']]
                    .map(([boxId, btnId]) => ({
                        box: document.getElementById(boxId),
                        btn: document.getElementById(btnId),
                    }))
                    .filter((d) => d.box && d.btn);

                const closeAll = (except) => drops.forEach((d) => {
                    if (d.box === except) return;
                    d.box.classList.remove('open');
                    d.btn.setAttribute('aria-expanded', 'false');
                });

                drops.forEach((d) => {
                    d.btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        closeAll(d.box);
                        const open = d.box.classList.toggle('open');
                        d.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    });
                });

                document.addEventListener('click', (e) => {
                    drops.forEach((d) => { if (!d.box.contains(e.target)) d.box.classList.remove('open'); });
                });
                document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(null); });
            })();

            // Bật/tắt cột của bảng. Ẩn bằng CSS (class trên <table>) thay vì gỡ ô ra
            // khỏi DOM: dữ liệu vẫn còn nguyên nên bật lại là hiện ngay, và các thao
            // tác đọc từ ô (tồn kho, trạng thái) không bị hụt phần tử.
            (function () {
                const STORE_KEY = 'prd_cols_hidden_v1';
                // Không cho tắt: ô chọn, tên sản phẩm và cột thao tác — tắt đi thì
                // bảng không còn nhận ra dòng nào là dòng nào.
                const COLS = [
                    { key: 'stt', label: 'STT' },
                    { key: 'code', label: 'Mã SP' },
                    { key: 'img', label: 'Ảnh' },
                    { key: 'sku', label: 'SKU' },
                    { key: 'cat', label: 'Danh mục' },
                    { key: 'brand', label: 'Thương hiệu' },
                    { key: 'price', label: 'Giá' },
                    { key: 'stock', label: 'Tồn kho' },
                    { key: 'kit', label: 'Loại áo' },
                    { key: 'feat', label: 'Nổi bật' },
                    { key: 'status', label: 'Trạng thái' },
                ];
                const TOTAL_COLS = 14; // gồm cả 3 cột không tắt được

                const table = document.querySelector('.prd-table');
                const list = document.getElementById('prdColsList');
                const badge = document.getElementById('prdColsCount');
                const resetBtn = document.getElementById('prdColsReset');
                if (!table || !list) return;

                const valid = new Set(COLS.map((c) => c.key));

                function load() {
                    try {
                        const raw = JSON.parse(localStorage.getItem(STORE_KEY));
                        // Lọc theo danh sách cột hiện có: bản cũ lưu key đã bỏ thì kệ nó,
                        // không để một key rác khoá luôn nút "Hiện tất cả".
                        return Array.isArray(raw) ? raw.filter((k) => valid.has(k)) : [];
                    } catch (e) { return []; }
                }

                function save(hidden) {
                    try { localStorage.setItem(STORE_KEY, JSON.stringify(hidden)); } catch (e) { /* hết quota thì thôi */ }
                }

                function apply(hidden) {
                    COLS.forEach((c) => table.classList.toggle('hide-' + c.key, hidden.includes(c.key)));

                    // Dòng "chưa có sản phẩm" phải co theo số cột còn hiện, không thì
                    // ô trống kéo dài quá khổ bảng.
                    const empty = table.querySelector('.prd-empty');
                    if (empty) empty.setAttribute('colspan', String(TOTAL_COLS - hidden.length));

                    if (badge) {
                        badge.hidden = hidden.length === 0;
                        badge.textContent = String(hidden.length);
                        badge.title = hidden.length ? ('Đang ẩn ' + hidden.length + ' cột') : '';
                    }
                    if (resetBtn) resetBtn.disabled = hidden.length === 0;
                }

                let hidden = load();

                list.innerHTML = COLS.map((c) => `
                    <label class="prd-cols-item">
                        <input type="checkbox" value="${c.key}" ${hidden.includes(c.key) ? '' : 'checked'}>
                        <span>${c.label}</span>
                    </label>`).join('');

                list.addEventListener('change', (e) => {
                    const cb = e.target.closest('input[type="checkbox"]');
                    if (!cb) return;
                    hidden = cb.checked ? hidden.filter((k) => k !== cb.value) : hidden.concat(cb.value);
                    save(hidden);
                    apply(hidden);
                });

                if (resetBtn) {
                    resetBtn.addEventListener('click', () => {
                        hidden = [];
                        list.querySelectorAll('input[type="checkbox"]').forEach((cb) => { cb.checked = true; });
                        save(hidden);
                        apply(hidden);
                    });
                }

                apply(hidden);
            })();

            // Modal Import file: mở/đóng.
            (function () {
                const overlay = document.getElementById('prdImportOverlay');
                const openBtn = document.getElementById('prdImportBtn');
                if (!overlay || !openBtn) return;
                const close = () => { overlay.style.display = 'none'; };
                openBtn.addEventListener('click', () => {
                    overlay.style.display = 'flex';
                    const u = document.getElementById('prdUtil');
                    if (u) u.classList.remove('open');
                });
                document.getElementById('prdImportX').addEventListener('click', close);
                document.getElementById('prdImportCancel').addEventListener('click', close);
            })();
        })();
    </script>
@endsection
