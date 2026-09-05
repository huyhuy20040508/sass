@extends('layouts.app')

@section('title', \App\Http\Controllers\ProductController::TITLE_PAGE)

@section('content')
    {{--
        Trang "Danh sách hàng hóa" — dựng theo màn cùng tên của bản cũ v2
        (menu/menu) nhưng theo khuôn BÁN LẺ CHUNG: không còn đội bóng / mùa giải /
        loại áo, và biến thể là TỔ HỢP THUỘC TÍNH do cửa hàng tự khai ở màn
        Hàng hóa → Thuộc tính.

        Lọc / tìm kiếm / phân trang chạy phía server (Go API hỗ trợ sẵn): mỗi thay
        đổi bộ lọc là một GET mới qua form #prdFilter (tự submit khi đổi select,
        debounce khi gõ tìm kiếm). Các thao tác đổi trạng thái / xoá / xoá hàng loạt
        dựng form POST động bằng JS (chuẩn CSRF) — cùng phong cách trang Danh mục.
    --}}
@php
        // Sắp xếp nhóm hàng theo cây (thụt lề) để dropdown dễ đọc.
        // API trả về parent_id = null cho nhóm gốc → dùng 'root' thay vì 0 để groupBy đúng.
        //
        // Mỗi dòng mang theo ba thứ mà hộp thoại cần, đúng quy tắc bản cũ v2:
        //   leaf     — nhóm KHÔNG có nhóm con. Chỉ nhóm lá mới gắn hàng được; nhóm
        //              cha là khung phân loại, gắn hàng vào đó thì báo cáo theo
        //              nhóm đếm hai lần và người bán không biết món nằm nhánh nào.
        //   sellable — nằm dưới nhóm gốc "Hàng bán". v2 lọc đúng như vậy
        //              (id_menu_group_parent = 1), phần còn lại là hàng không bán.
        //   vat      — mức thuế của nhóm, để chọn nhóm là ô thuế tự điền.
        $catByParent = collect($categories)->groupBy(fn ($c) => $c['parent_id'] ?? 'root');
        $orderedCats = [];
        $walkCats = function ($parentId, $level, $sellable) use (&$walkCats, $catByParent, &$orderedCats) {
            foreach ($catByParent->get($parentId, []) as $c) {
                $con = $catByParent->get($c['id'], []);
                $duocBan = $sellable || ($c['slug'] ?? '') === 'hang-ban';
                $orderedCats[] = [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'level' => $level,
                    'leaf' => count($con) === 0,
                    'sellable' => $duocBan,
                    'vat' => (int) ($c['vat'] ?? 0),
                ];
                $walkCats($c['id'], $level + 1, $duocBan);
            }
        };
        $walkCats('root', 0, false);

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];

        // Đổi thứ tự chỉ có nghĩa khi danh sách đang ở ĐÚNG thứ tự tự xếp: không
        // lọc gì, không sắp theo cột. Bản cũ v2 cũng khoá hai mũi tên đúng như vậy.
        $doiThuTuDuoc = $filters['keyword'] === ''
            && empty($filters['category_ids'])
            && $filters['location_id'] === ''
            && ! $filters['unit_id']
            && $filters['multi_variant'] === ''
            && empty($filters['statuses'])
            && $filters['sort'] === 'newest';
    @endphp

    <div class="prd">
        {{-- Header --}}
        <div class="prd-head">
            <h1 class="prd-title">{{ \App\Http\Controllers\ProductController::TITLE_PAGE }}</h1>
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
            // Trạng thái nay nằm ở hàng chính (v2 coi nó là điều kiện lọc chính),
            // nên không tính vào số bộ lọc phụ nữa.
            $advCount = ($filters['location_id'] !== '' ? 1 : 0)
                + ($filters['unit_id'] ? 1 : 0)
                + ($filters['multi_variant'] !== '' ? 1 : 0);
            $locNhom = $filters['category_ids'];
            $locTrangThai = $filters['statuses'];
            $advOpen = $advCount > 0;
        @endphp
        <form method="GET" action="{{ route('admin.products.index') }}" id="prdFilter" class="prd-filter">
            {{-- Hàng cơ bản: tìm kiếm + lọc danh mục + nút Nâng cao --}}
            <div class="prd-toolbar">
                <div class="prd-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="prd-search-input"
                           placeholder="Tìm theo tên, mã hàng hoặc mã vạch" autocomplete="off">
                    <button type="submit" class="prd-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                {{-- Nhóm hàng hoá và Trạng thái đều CHỌN ĐƯỢC NHIỀU, đúng như bản
                     cũ (bên đó là ô chọn nhiều và một cụm ô tick). Không chọn gì =
                     xem tất cả. --}}
                <div class="prd-multi" data-multi>
                    <button type="button" class="prd-multi-btn">
                        <span>{{ empty($locNhom) ? 'Tất cả nhóm hàng hóa' : count($locNhom).' nhóm hàng hóa' }}</span>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="prd-multi-menu">
                        @foreach($orderedCats as $c)
                            <label class="prd-multi-item">
                                <input type="checkbox" name="category_ids[]" value="{{ $c['id'] }}"
                                       {{ in_array($c['id'], $locNhom, true) ? 'checked' : '' }}>
                                <span>{{ str_repeat('— ', $c['level']) }}{{ $c['name'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="prd-multi" data-multi>
                    <button type="button" class="prd-multi-btn">
                        <span>{{ empty($locTrangThai) ? 'Tất cả trạng thái' : count($locTrangThai).' trạng thái' }}</span>
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    </button>
                    <div class="prd-multi-menu">
                        @foreach($statuses as $val => $label)
                            <label class="prd-multi-item">
                                <input type="checkbox" name="statuses[]" value="{{ $val }}"
                                       {{ in_array($val, $locTrangThai, true) ? 'checked' : '' }}>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <button type="button" id="prdAdvToggle" class="prd-adv-btn {{ $advOpen ? 'is-open' : '' }}" aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="prd-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="prd-adv-count">{{ $advCount }}</span>@endif
                </button>

                {{-- Hành động: Tạo mới + Nâng cao (ngang hàng bộ lọc, đẩy sang phải) --}}
                <div class="prd-toolbar-actions">
                    <button type="button" id="prdAddBtn" class="prd-btn-primary">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Tạo mới
                    </button>

                    <div class="prd-util" id="prdUtil">
                        <button type="button" class="prd-util-btn" id="prdUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Nâng cao
                            <svg class="prd-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="prd-util-menu">
<a href="{{ route('admin.products.export', request()->query()) }}" class="prd-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                            <button type="button" class="prd-util-item" id="prdImportBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 8l5-5 5 5"/><path d="M12 3v12"/></svg>
                                Nhập file
                            </button>
                            {{-- Tệp mẫu để ngay đây như v2, không giấu trong hộp thoại
                                 nhập: người ta cần nó TRƯỚC khi mở hộp thoại. --}}
                            <a href="{{ route('admin.products.importTemplate') }}" class="prd-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 15h6M9 11h2"/></svg>
                                Tải file mẫu
                            </a>
                        </div>
                    </div>

                    {{-- Chọn cột hiển thị — bản cũ v2 cũng có, cùng bộ cột.
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
                            <p class="prd-cols-hint">Ô chọn, STT và cột Thao tác luôn hiển thị.</p>
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
                {{-- Vị trí để hàng. "Chưa gán vị trí" là lựa chọn có thật chứ không
                     phải trạng thái thiếu dữ liệu: đó đúng là câu người đi soạn hàng
                     hỏi — còn món nào chưa biết để đâu. --}}
                <select name="location_id" class="prd-select" title="Lọc theo vị trí để hàng">
                    <option value="">Tất cả vị trí</option>
                    <option value="none" {{ $filters['location_id'] === 'none' ? 'selected' : '' }}>Chưa gán vị trí</option>
                    @foreach($locations as $loc)
                        <option value="{{ $loc['id'] }}" {{ $filters['location_id'] === (string) $loc['id'] ? 'selected' : '' }}>
                            {{ $loc['code'] }} · {{ $loc['name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="unit_id" class="prd-select" title="Lọc theo đơn vị tính">
                    <option value="0">Tất cả đơn vị tính</option>
                    @foreach($units as $u)
                        <option value="{{ $u['id'] }}" {{ $filters['unit_id'] === (int) $u['id'] ? 'selected' : '' }}>
                            {{ $u['code'] }} · {{ $u['name'] }}
                        </option>
                    @endforeach
                </select>

                {{-- Hàng nhiều biến thể vs hàng đơn: hai thứ khai và đếm kho khác
                     hẳn nhau, nên tách được ra là đỡ phải lọc bằng mắt. --}}
                <select name="multi_variant" class="prd-select" title="Lọc theo hàng có biến thể">
                    <option value="">Biến thể: Tất cả</option>
                    <option value="1" {{ $filters['multi_variant'] === '1' ? 'selected' : '' }}>Hàng nhiều biến thể</option>
                    <option value="0" {{ $filters['multi_variant'] === '0' ? 'selected' : '' }}>Hàng đơn (không biến thể)</option>
                </select>

            </div>

            {{-- Kiểu sắp xếp đi theo tiêu đề cột (xem products/th-sort), nhưng vẫn
                 phải mang sang lượt lọc sau — không thì đổi bộ lọc là mất thứ tự
                 vừa chọn. --}}
            <input type="hidden" name="sort" value="{{ $filters['sort'] }}">

            {{-- Giữ per_page khi đổi bộ lọc --}}
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
        </form>

        {{-- Bảng --}}
        <div class="prd-table-wrap">
            <table class="prd-table">
                <thead>
                    <tr>
                        <th class="prd-c-check"><input type="checkbox" id="prdCheckAll" class="prd-check" title="Chọn tất cả"></th>
                        <th class="prd-c-stt">STT</th>
                        <th class="prd-c-sku">Mã hàng</th>
                        {{-- Ba tiêu đề bấm được — v2 sắp xếp bằng cách bấm cột chứ
                             không có ô chọn "sắp xếp theo". --}}
                        <th class="prd-c-name">@include('products.th-sort', ['key' => 'name', 'label' => 'Tên hàng hóa'])</th>
                        <th class="prd-c-cat">@include('products.th-sort', ['key' => 'group', 'label' => 'Nhóm hàng hóa'])</th>
                        <th class="prd-c-vat">VAT</th>
                        <th class="prd-c-unit">ĐVT</th>
                        <th class="prd-c-price">@include('products.th-sort', ['key' => 'price', 'label' => 'Giá bán'])</th>
                        <th class="prd-c-branch">Chi nhánh</th>
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

                            // Hàng đơn có đúng một dòng mặc định — đếm ra 1 thì ghi 0,
                            // không thì bảng nói "1 biến thể" cho một món không có biến thể nào.
                            $soBienThe = empty($p['is_multi_variant'])
                                ? 0
                                : count(array_filter($p['variants'] ?? [], fn ($v) => ($v['is_active'] ?? true)));
                        @endphp
                        <tr data-id="{{ $p['id'] }}">
                            <td class="prd-c-check"><input type="checkbox" class="prd-check prd-row-check" value="{{ $p['id'] }}"></td>
                            <td class="prd-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="prd-c-sku" data-view="{{ $p['id'] }}" title="Xem chi tiết hàng hóa"><span class="prd-code">{{ $p['sku'] }}</span></td>
                            <td class="prd-c-name" data-view="{{ $p['id'] }}" title="Xem chi tiết hàng hóa">
                                <span class="prd-name">{{ $p['name'] }}</span>
                                @if($soBienThe > 0)
                                    <span class="prd-sub">{{ $soBienThe }} biến thể</span>
                                @endif
                            </td>
                            <td class="prd-c-cat">{{ $p['category']['name'] ?? '—' }}</td>
                            <td class="prd-c-vat">
                                {{ \App\Http\Controllers\ProductController::vatText($p['vat'] ?? 0) }}
                            </td>
                            <td class="prd-c-unit">
                                @if(!empty($p['unit']['name']))
                                    {{ $p['unit']['name'] }}
                                @else
                                    <span class="prd-muted">—</span>
                                @endif
                            </td>
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
                            <td class="prd-c-branch">
                                {{-- Chưa gán chi nhánh nào = bán ở MỌI chi nhánh. Nói
                                     thẳng ra chứ không để trống: ô trống đọc như dữ
                                     liệu thiếu. --}}
                                @php $chiNhanh = \App\Http\Controllers\ProductController::chiNhanhText($p); @endphp
                                @if($chiNhanh !== '')
                                    <span class="prd-badge" title="{{ $chiNhanh }}">{{ $chiNhanh }}</span>
                                @else
                                    <span class="prd-muted">Mọi chi nhánh</span>
                                @endif
                            </td>
                            <td class="prd-c-status">
                                {{-- Công tắc: bật là đang bán, tắt là không bán. --}}
                                <button type="button" class="prd-switch {{ $status === 'active' ? 'on' : '' }}"
                                        data-status="{{ $p['id'] }}" data-current="{{ $status }}"
                                        title="{{ $statuses[$status] }} — {{ $statusHints[$status] ?? '' }}">
                                    <span class="prd-switch-knob"></span>
                                </button>
                            </td>
                            <td class="prd-c-act">
                                <div class="prd-rowacts">
                                    {{-- Hai mũi tên đổi thứ tự, đúng như bản cũ. Chỉ bấm được khi
                                         danh sách đang ở thứ tự tự xếp: đang lọc hay đang sắp theo
                                         cột thì "lên một bậc" không còn nghĩa gì — mặt hàng ngay
                                         trên màn hình chưa chắc là mặt hàng liền kề trong sổ. --}}
                                    <button type="button" class="prd-rowbtn prd-move {{ $doiThuTuDuoc ? '' : 'is-off' }}"
                                            data-move="up" data-id="{{ $p['id'] }}"
                                            title="{{ $doiThuTuDuoc ? 'Đưa lên trên' : 'Xoá lọc và bỏ sắp xếp cột thì mới đổi được thứ tự' }}">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                                    </button>
                                    <button type="button" class="prd-rowbtn prd-move {{ $doiThuTuDuoc ? '' : 'is-off' }}"
                                            data-move="down" data-id="{{ $p['id'] }}"
                                            title="{{ $doiThuTuDuoc ? 'Đưa xuống dưới' : 'Xoá lọc và bỏ sắp xếp cột thì mới đổi được thứ tự' }}">
                                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <form action="{{ route('admin.products.duplicate', $p['id']) }}" method="POST" class="d-inline" data-confirm="Sao chép hàng hóa|Bạn có chắc chắn muốn sao chép mặt hàng này? Bản sao sẽ ở trạng thái tạm ẩn.|info">
                                        @csrf
                                        <button type="submit" class="prd-rowbtn prd-copy" title="Sao chép hàng hóa">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                        </button>
                                    </form>
                                    <button type="button" class="prd-rowbtn prd-edit" data-edit="{{ $p['id'] }}" title="Sửa hàng hóa">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="prd-rowbtn prd-del" data-remove="{{ $p['id'] }}" title="Xoá hàng hóa">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="prd-empty">
                                @if($filters['keyword'] !== '' || ! empty($filters['category_ids']) || $filters['location_id'] !== '' || $filters['unit_id'] || $filters['multi_variant'] !== '' || ! empty($filters['statuses']))
                                    Không tìm thấy mặt hàng nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    Chưa có mặt hàng nào. Bấm “Thêm hàng hóa” để khai mặt hàng đầu tiên.
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
            'noun' => 'mặt hàng',
            'perPageName' => 'per_page',
            'perPageOptions' => $perPageOptions,
        ])
    </div>

    <div id="prdBulkMount"></div>
    <div id="prdModalMount"></div>

    {{-- Modal Import file --}}
    <div class="prd-overlay" id="prdImportOverlay" style="display:none;">
        <div class="prd-dialog prd-dialog-sm">
            <div class="prd-modal-head">
                <h4 class="prd-modal-title">Nhập hàng hóa từ file</h4>
                <button type="button" class="prd-modal-x" id="prdImportX">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <form method="POST" action="{{ route('admin.products.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="prd-modal-body">
                    <p class="prd-hint" style="margin:0;">
                        Chọn file <b>CSV</b> theo mẫu. Mỗi dòng 1 mặt hàng; cột <b>bien_the</b> nhập nhiều tên biến thể cách nhau dấu phẩy (VD <code>Đen,Trắng</code>) — mỗi tên tạo 1 biến thể. Bỏ trống = hàng đơn.
                        <br>Tổ hợp thuộc tính (chiều nào ứng với giá trị nào) khai trong hộp thoại sửa, không khai qua tệp.
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

    @include('products.partials.style')

    @include('products.partials.script')
@endsection
