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
            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}" id="prdPerPageHidden">
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
        <div class="prd-dialog prd-dialog-sm" id="prdImportDialog">
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

        /* Nhóm hành động (Tạo mới + Nâng cao) — đẩy sang phải toolbar */
        .prd-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .prd-btn-primary svg { flex-shrink: 0; }

        /* Dropdown Nâng cao */
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

        /* Chọn cột hiển thị — dùng lại vỏ dropdown .prd-util cho giống "Nâng cao" */
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
        .prd-table.hide-sku    .prd-c-sku,
        .prd-table.hide-name   .prd-c-name,
        .prd-table.hide-cat    .prd-c-cat,
        .prd-table.hide-vat    .prd-c-vat,
        .prd-table.hide-unit   .prd-c-unit,
        .prd-table.hide-price  .prd-c-price,
        .prd-table.hide-branch .prd-c-branch,
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

        /* Ô lọc chọn NHIỀU — bản cũ dùng select2 multiple; đây là bản không cần
           thư viện ngoài: một nút xổ danh sách ô tick. */
        .prd-multi { position: relative; }
        .prd-multi-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 8px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 10px 0 12px; font-size: 13px; color: #262626;
            cursor: pointer; max-width: 220px; transition: border-color .15s;
        }
        .prd-multi-btn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-multi-btn:hover, .prd-multi.open .prd-multi-btn { border-color: #1890ff; }
        .prd-multi-menu {
            position: absolute; left: 0; top: calc(100% + 4px); min-width: 220px; max-height: 320px; overflow-y: auto;
            z-index: 1050; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .prd-multi.open .prd-multi-menu { display: block; }
        .prd-multi-item {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none; white-space: nowrap;
        }
        .prd-multi-item:hover { background: #f5f7fa; }
        .prd-multi-item input { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; flex-shrink: 0; }

        /* Bảng — cùng khuôn với mọi trang danh sách khác: rộng hết khung, mọi ô
           canh giữa, bề rộng khai theo % và cộng đúng 100%. Để một cột không có
           width là cột đó nuốt hết phần dư, các cột còn lại dồn cục. */
        .prd-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; }
        .prd-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .prd-table thead tr { background: #f0f0f0; color: #262626; }
        .prd-table th, .prd-table td { padding: 14px 10px; vertical-align: middle; white-space: nowrap; text-align: center; }
        .prd-table th { font-weight: 700; }
        .prd-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .prd-table tbody tr:hover { background: #fafafa; }
        .prd-table tbody tr.is-selected, .prd-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .prd-table th.prd-c-check,  .prd-table td.prd-c-check  { width: 4%; }
        .prd-table th.prd-c-stt,    .prd-table td.prd-c-stt    { width: 4%; }
        .prd-table th.prd-c-sku,    .prd-table td.prd-c-sku    { width: 12%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-name,   .prd-table td.prd-c-name   { width: 19%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-cat,    .prd-table td.prd-c-cat    { width: 12%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-vat,    .prd-table td.prd-c-vat    { width: 6%; font-variant-numeric: tabular-nums; }
        .prd-table th.prd-c-unit,   .prd-table td.prd-c-unit   { width: 7%; }
        .prd-table th.prd-c-price,  .prd-table td.prd-c-price  { width: 10%; }
        .prd-table th.prd-c-branch, .prd-table td.prd-c-branch { width: 10%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-status, .prd-table td.prd-c-status { width: 8%; }
        .prd-table th.prd-c-act,    .prd-table td.prd-c-act    { width: 8%; }

        /* Tiêu đề cột bấm được để sắp xếp */
        .prd-th-sort {
            display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none;
            font-weight: 700; transition: color .15s;
        }
        .prd-th-sort svg { color: #bfbfbf; flex-shrink: 0; transition: color .15s; }
        .prd-th-sort:hover, .prd-th-sort:hover svg { color: #1890ff; }
        .prd-th-sort.is-on svg { color: #1890ff; }

        .prd-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .prd-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-c-price { font-variant-numeric: tabular-nums; }
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
        .prd-rowbtn.prd-move { color: #8c8c8c; width: 24px; }
        .prd-rowbtn.prd-move:hover { background: #f0f5ff; color: #1890ff; }
        .prd-rowbtn.prd-move.is-off { color: #d9d9d9; cursor: default; }
        .prd-rowbtn.prd-move.is-off:hover { background: none; color: #d9d9d9; }
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
        /* Mã hàng & tên hàng bấm được để xem chi tiết */
        .prd-c-name[data-view], .prd-c-sku[data-view] { cursor: pointer; }
        .prd-c-name[data-view]:hover .prd-name,
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
            /* v2 để hộp thoại rộng 95% màn hình vì tab Chi tiết chia hai cột. */
            max-height: 92vh; width: 100%; max-width: min(1180px, 95vw); overflow-y: auto; border-radius: 6px;
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
        /* Tab Chi tiết chia hai cột như bản cũ: cột trái ảnh + công tắc, cột phải
           lưới BỐN ô mỗi hàng. */
        .prd-two { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 20px; align-items: start; }
        .prd-side { display: flex; flex-direction: column; gap: 10px; }
        .prd-side-title { margin: 0; text-align: center; font-size: 13px; font-weight: 500; color: #262626; }
        .prd-side .prd-img-preview { width: 100%; height: 190px; border-radius: 4px; }
        /* Nút "Tải lên" chạy hết bề ngang ô ảnh, đúng như bản cũ. */
        .prd-upload-btn {
            width: 100%; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            font-size: 13px; color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .prd-upload-btn:hover { border-color: #1890ff; color: #1890ff; }
        /* Khung có viền bao các công tắc — bản cũ gom chúng vào một hộp riêng. */
        .prd-side-box {
            border: 1px solid #d9d9d9; border-radius: 6px; padding: 10px 12px;
            display: flex; flex-direction: column; gap: 12px;
        }
        .prd-side-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .prd-msel-sm { height: 30px; font-size: 12px; padding: 0 26px 0 8px; width: 132px; }
        .prd-body { min-width: 0; display: flex; flex-direction: column; gap: 14px; }
        .prd-grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        /* Định mức tồn: v2 gộp min và max vào một ô, giữ nguyên. */
        .prd-col-4 { grid-column: span 4; }

        /* Ô chọn nhiều trong hộp thoại (chi nhánh, thẻ). Cùng cách bày với ô lọc
           nhiều mục ngoài thanh lọc, chỉ rộng bằng ô nhập cạnh nó. */
        .prd-pick { position: relative; }
        .prd-pick-btn {
            width: 100%; height: 34px; display: flex; align-items: center; justify-content: space-between; gap: 8px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 0 10px 0 12px;
            font-size: 13px; color: #262626; cursor: pointer; transition: border-color .15s;
        }
        .prd-pick-btn:hover, .prd-pick.open .prd-pick-btn { border-color: #1890ff; }
        .prd-pick-btn[disabled] { background: #fafafa; color: #8c8c8c; cursor: default; }
        /* Chưa chọn gì thì chữ xám như placeholder — cùng quy ước với ô chọn. */
        .prd-pick-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-pick.is-empty .prd-pick-text { color: #9ca3af; }
        .prd-pick-pop {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px); max-height: 260px; overflow-y: auto;
            z-index: 1060; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .prd-pick.open .prd-pick-pop { display: block; }
        .prd-pick-item {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none;
        }
        .prd-pick-item:hover { background: #f5f7fa; }
        .prd-pick-item input { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; flex-shrink: 0; }
        .prd-pick-empty { padding: 8px 10px; font-size: 12px; color: #8c8c8c; }
        .prd-pick-new { display: flex; align-items: center; gap: 6px; padding: 6px; border-top: 1px solid #f0f0f0; }
        /* Ô nhập ăn hết phần thừa, nút Thêm CAO BẰNG ô — hai mực cao khác nhau
           thì nút trông như bị lọt thỏm vào giữa hàng. */
        .prd-pick-new .prd-input-sm { flex: 1; min-width: 0; height: 30px; font-size: 12px; }
        .prd-pick-new .prd-btn-xs { height: 30px; flex-shrink: 0; }

        /* Khối xếp gọn ở chân cột phải — chỗ v2 để accordion "Quy đổi đơn vị". */
        .prd-acc { border: 1px solid #f0f0f0; border-radius: 6px; }
        .prd-acc > summary {
            cursor: pointer; list-style: none; padding: 9px 12px; font-size: 13px; font-weight: 600;
            color: #595959; user-select: none;
        }
        .prd-acc > summary::-webkit-details-marker { display: none; }
        .prd-acc > summary::before { content: '▸ '; color: #bfbfbf; }
        .prd-acc[open] > summary::before { content: '▾ '; }
        .prd-acc > summary:hover { color: #1890ff; }
        .prd-acc-body { padding: 0 12px 12px; }
        /* Nút Thêm / xoá hết nằm ngay trên thanh tiêu đề khối, như bản cũ. */
        .prd-acc-tools { float: right; display: inline-flex; align-items: center; gap: 6px; }
        .prd-btn-xs { height: 24px; padding: 0 10px; font-size: 12px; border-radius: 4px; }
        .prd-btn-x {
            width: 24px; height: 24px; border: 1px solid #ffccc7; border-radius: 4px; background: #fff;
            color: #ff4d4f; font-size: 16px; line-height: 1; cursor: pointer;
        }
        .prd-btn-x:hover { background: #fff1f0; }

        /* Bảng quy đổi: 1 <ĐV quy đổi> = <SL> <ĐVT chính> */
        .prd-conv-head, .prd-conv-row {
            display: grid; grid-template-columns: 90px 1fr 20px 1fr 110px 34px; gap: 8px; align-items: center;
        }
        .prd-conv-head { font-size: 12px; font-weight: 600; color: #8c8c8c; padding: 0 2px 4px; }
        .prd-conv-row { margin-bottom: 8px; }
        .prd-conv-row .prd-input, .prd-conv-row .prd-msel { height: 34px; }
        .prd-conv-eq { text-align: center; color: #8c8c8c; }
        .prd-conv-main { font-size: 13px; color: #595959; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-conv-del {
            height: 34px; width: 34px; border: 1px solid #ffccc7; border-radius: 4px; background: #fff;
            color: #ff4d4f; font-size: 18px; line-height: 1; cursor: pointer;
        }
        .prd-conv-del:hover { background: #fff1f0; }
        .prd-conv-empty { margin: 4px 0 0; font-size: 12px; color: #bfbfbf; }

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

        /* Biến thể — tổ hợp thuộc tính */
        .prd-var { display: flex; flex-direction: column; gap: 10px; }
        #mVariants { display: flex; flex-direction: column; gap: 10px; }

        /* Khối chọn thuộc tính: mỗi thuộc tính một hàng, tick giá trị nào thì
           giá trị đó vào tổ hợp. Nguồn là màn Hàng hóa → Thuộc tính. */
        .prd-attrs {
            display: flex; flex-direction: column; gap: 10px;
            padding: 12px; background: #f7f9fc; border: 1px solid #eef0f4; border-radius: 6px;
        }
        .prd-attr-row { display: flex; align-items: flex-start; gap: 12px; }
        .prd-attr-name {
            flex: 0 0 130px; padding-top: 4px; font-size: 13px; font-weight: 600; color: #262626;
            overflow: hidden; text-overflow: ellipsis;
        }
        .prd-attr-vals { display: flex; flex-wrap: wrap; gap: 6px; flex: 1; }
        .prd-attr-val {
            display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9; border-radius: 14px;
            background: #fff; padding: 3px 11px 3px 8px; font-size: 12px; color: #595959; cursor: pointer;
            user-select: none; transition: border-color .15s, background .15s, color .15s;
        }
        .prd-attr-val:hover { border-color: #91caff; }
        .prd-attr-val input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }
        .prd-attr-val.is-on { border-color: #1890ff; background: #e6f7ff; color: #0958d9; }
        .prd-attrs-foot { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .prd-attrs-empty { margin: 0; font-size: 12px; color: #8c8c8c; line-height: 1.6; }

        .prd-var-head, .prd-var-row {
            display: grid; grid-template-columns: 1.2fr .9fr 1fr .9fr .9fr .6fr 36px; gap: 10px; align-items: center;
        }
        /* Tên biến thể do tổ hợp thuộc tính quyết định — hiện để đối chiếu, không gõ tay. */
        .prd-var-name {
            height: 36px; display: inline-flex; align-items: center; padding: 0 10px;
            border: 1px solid #eef0f4; border-radius: 4px; background: #f7f9fc; color: #262626;
            font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .prd-var-name.is-plain { color: #8c8c8c; font-style: italic; }
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
        .prd-status.is-static { cursor: default; }
        .prd-status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; background: #bfbfbf; }
        /* Xanh = đang bán, vàng = tạm ẩn, xám = ngừng kinh doanh. Cùng bộ màu với
           ô tồn kho để cả bảng đọc theo một quy ước. */
        .prd-status-active .prd-status-dot,     .prd-dot-active       { background: #52c41a; }
        .prd-status-hidden .prd-status-dot,     .prd-dot-hidden       { background: #faad14; }
        .prd-status-discontinued .prd-status-dot, .prd-dot-discontinued { background: #bfbfbf; }
        .prd-status-active { border-color: #d9f7be; background: #f6ffed; color: #389e0d; }
        .prd-status-hidden { border-color: #ffe7ba; background: #fffbe6; color: #d46b08; }
        .prd-status-discontinued { border-color: #e8e8e8; background: #fafafa; color: #8c8c8c; }

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
        /* Tab có ô sai thì sáng đỏ, không phải mò từng tab để tìm */
        .prd-tab.has-err { color: #cf1322; border-bottom-color: #ffa39e; }

        .prd-panel { display: none; flex-direction: column; gap: 14px; }
        .prd-panel.is-active { display: flex; }

        .prd-err { margin: 4px 0 0; font-size: 11.5px; color: #cf1322; }
        .prd-err:empty { display: none; }
        .prd-input.is-err, .prd-msel.is-err { border-color: #ff4d4f; }
        .prd-input.is-err:focus, .prd-msel.is-err:focus { border-color: #ff4d4f; }

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

        .prd-modal-foot { justify-content: center; align-items: center; gap: 16px; }
        .prd-foot-btns { display: flex; gap: 8px; flex-shrink: 0; }
        .prd-foot-msg { margin: 0; font-size: 12px; color: #8c8c8c; }
        .prd-foot-msg.is-err { color: #cf1322; }
        .prd-foot-msg:empty { display: none; }

        @media (max-width: 1100px) {
            .prd-grid4 { grid-template-columns: 1fr 1fr; }
            .prd-col-4 { grid-column: span 2; }
        }
        @media (max-width: 900px) {
            /* Hết chỗ cho hai cột: cột ảnh lên trên, lưới ô nhập xuống dưới. */
            .prd-two { grid-template-columns: 1fr; }
            .prd-side { flex-direction: row; flex-wrap: wrap; align-items: flex-end; }
            .prd-side .prd-img-preview { width: 140px; height: 140px; }
            .prd-side-box { flex: 1; min-width: 200px; }
        }
        @media (max-width: 560px) {
            .prd-grid4 { grid-template-columns: 1fr; }
            .prd-col-4 { grid-column: span 1; }
        }
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
            // Cửa hàng đã bật quy tắc mã hàng hoá (Cài đặt → Thông số chung):
            // ô SKU khoá lại, mã do máy chủ đặt lúc lưu.
            const MA_TU_SINH = @json($maTuSinh ?? false);

        // Trần phía trình duyệt, khớp với ImageStore::MAX_UPLOAD_KB bên PHP. Chặn ở
        // đây chỉ để khỏi tải một file khổng lồ lên rồi mới bị từ chối; ảnh vẫn được
        // máy chủ thu nhỏ lại sau khi nhận.
        const MAX_IMG_BYTES = 10 * 1024 * 1024;
            const URL_BULK = @json(route('admin.products.bulkDestroy'));
            const URL_STORE = @json(route('admin.products.store'));
            const URL_UPLOAD = @json(route('admin.products.uploadImage'));
            const URL_BASE = @json(url('admin/products'));
const RETURN_URL = @json(route('admin.products.index', request()->query()));
            // Dữ liệu sản phẩm (thô) để dựng payload khi đổi trạng thái mà không mất trường nào.
            const PRODUCTS = @json(json_decode(json_encode($products)) ?? []);
            const BY_ID = new Map(PRODUCTS.map((p) => [p.id, p]));
            // Dữ liệu cho modal thêm/sửa.
            const CATEGORIES = @json($orderedCats);            // [{id, name, level}] đã xếp theo cây
            const LOCATIONS = @json($locations);               // [{id, code, name}] — chỉ vị trí đang bật
            const UNITS = @json($units);                       // [{id, code, name}] — chỉ đơn vị đang bật
            // Thuộc tính + giá trị con: nguồn của bảng tổ hợp biến thể.
            const ATTRIBUTES = @json($attributes);             // [{id, code, name, values:[{id, code, name}]}]
            // Bộ mức thuế đang bật ở màn Thuế (loại "mac-dinh"). Hai mã âm là KCT/KKKNT.
            const VAT_RATES = @json($vatRates);                // [0, 8, 10, ...]
            const VAT_LABELS = @json(\App\Http\Controllers\ProductController::VAT_LABELS);
            const STATUSES = @json($statuses);                 // { active: 'Đang bán', ... }
            const BRANCHES = @json($branches);                 // chi nhánh đang hoạt động
            const TAGS = @json($tags);                         // thẻ hàng hóa đang có
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

            // Ô lọc chọn nhiều: bấm nút xổ danh sách, tick xong là lọc ngay.
            // Không đóng danh sách sau mỗi lần tick — người dùng thường tick liền
            // mấy ô, đóng lại sau mỗi ô thì phải mở đi mở lại.
            $filter.querySelectorAll('[data-multi]').forEach((box) => {
                const btn = box.querySelector('.prd-multi-btn');
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    $filter.querySelectorAll('[data-multi]').forEach((k) => { if (k !== box) k.classList.remove('open'); });
                    box.classList.toggle('open');
                });
                box.addEventListener('change', () => $filter.submit());
            });
            document.addEventListener('click', (e) => {
                $filter.querySelectorAll('[data-multi]').forEach((box) => {
                    if (!box.contains(e.target)) box.classList.remove('open');
                });
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

            // Đổi trạng thái: gửi DUY NHẤT cờ bật/tắt tới endpoint chuyên biệt. Không
            // gửi lại toàn bộ mặt hàng — API ghi cả dòng khi PUT, thiếu một trường là
            // bấm công tắc một cái mất luôn dữ liệu đó.
            //
            // Gửi CỜ chứ không gửi status vì công tắc chỉ có hai nấc mà trạng thái có
            // ba mức: mặt hàng đang "ngừng kinh doanh" phải giữ nguyên mức ấy khi tắt,
            // không bị hạ xuống "tạm ẩn" (xem resolveProductStatus bên API).
            function setActive(id, active) {
                postForm(`${URL_BASE}/${id}/toggle-status`, 'PUT', { is_active: active ? 1 : 0 });
            }

            function removeProduct(p) {
                sysDelete({
                    title: 'Xác nhận xoá hàng hóa',
                    message: `Bạn có chắc chắn muốn xoá mặt hàng "${p.name}"? Hành động này không thể hoàn tác.`,
                    highlightText: p.name
                }).then((confirmed) => {
                    if (confirmed) {
                        postForm(`${URL_BASE}/${p.id}`, 'DELETE', {});
                    }
                });
            }

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.prd-table tbody');
            tbody.addEventListener('click', (e) => {
                const sw = e.target.closest('[data-status]');
                if (sw) {
                    const bat = !sw.classList.contains('on');
                    sw.classList.toggle('on', bat);   // lật ngay cho đỡ khựng, trang tự tải lại sau
                    setActive(Number(sw.getAttribute('data-status')), bat);
                    return;
                }
                const mv = e.target.closest('[data-move]');
                if (mv) {
                    if (mv.classList.contains('is-off')) {
                        toastErr('Đang lọc hoặc đang sắp theo cột nên không đổi được thứ tự. Xoá lọc rồi thử lại.');
                        return;
                    }
                    postForm(`${URL_BASE}/${mv.getAttribute('data-id')}/sort`, 'PUT', { huong: mv.getAttribute('data-move') });
                    return;
                }
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
                        <span class="prd-bulk-text">Đã chọn <b>${n}</b> mặt hàng</span>
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
                        message: `Bạn có chắc chắn muốn xoá ${ids.length} mặt hàng đã chọn? Hành động này không thể hoàn tác.`,
                        highlightText: `Số lượng: ${ids.length} mặt hàng`
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

            // Mã sản phẩm hiển thị tự sinh từ id: SP000001, SP000002, ... (chỉ để hiển thị, không lưu).
            const fmtProdCode = (id) => 'SP' + String(id).padStart(6, '0');

            // Tiền tệ VN: chỉ giữ chữ số; hiển thị 850000 -> "850.000" (dấu chấm phân cách nghìn).
            const digitsOnly = (s) => String(s == null ? '' : s).replace(/\D/g, '');
            const fmtVnd = (n) => { const d = digitsOnly(n); return d ? Number(d).toLocaleString('vi-VN') : ''; };
            // Số lượng (tồn kho): luôn hiện số, kể cả 0 — khác fmtVnd trả rỗng khi trống.
            const fmtInt = (n) => Number(n || 0).toLocaleString('vi-VN');

            // Bỏ dấu tiếng Việt -> ASCII (để dựng mã hàng).
            const deaccent = (s) => String(s == null ? '' : s)
                .normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/đ/g, 'd').replace(/Đ/g, 'D');

            /**
             * Tự sinh mã hàng từ TÊN: chữ cái đầu của các từ + 4 số.
             * VD "iPhone 15 Pro Max" -> "IPM-4821".
             *
             * Có đuôi số vì tên hàng bán lẻ trùng nhau rất dễ ("Ốp lưng", "Cáp sạc")
             * mà mã hàng thì duy nhất — không có đuôi là lượt Lưu thứ hai ăn lỗi
             * trùng mã. Khớp với ProductController::productSku() bên PHP.
             *
             * Bật quy tắc đánh số thì hàm này không được gọi: mã do máy chủ đặt.
             */
            function genSku() {
                const name = document.getElementById('mName').value.trim();
                // Thay dấu câu bằng space trước khi tách — xử lý đúng O'NEILLS, M.C., 1.FC
                const normalized = deaccent(name).toUpperCase().replace(/[^A-Z0-9\s]+/g, ' ');
                const words = normalized.split(/\s+/).filter(Boolean);
                let dau = '';
                if (words.length >= 2) dau = words.slice(0, 4).map((w) => w[0]).join('');
                else dau = (words[0] || '').slice(0, 4);
                if (!dau) return 'SP-' + Date.now().toString(36).toUpperCase();
                return dau + '-' + String(Math.floor(Math.random() * 10000)).padStart(4, '0');
            }

            /** Nhãn đọc được của một mức thuế: "10%", "KCT", "KKKNT". */
            function vatText(v) {
                const n = Number(v);
                if (n === -1) return 'KCT';
                if (n === -2) return 'KKKNT';
                return n + '%';
            }

            /** Khoá định danh của một tổ hợp thuộc tính — dùng để so hai biến thể. */
            function attrKey(attrs) {
                return (attrs || []).slice()
                    .sort((a, b) => a.attribute_id - b.attribute_id)
                    .map((a) => a.attribute_id + ':' + a.value_id)
                    .join('|');
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
                        toastErr('Mặt hàng này không còn tồn tại — có thể vừa bị xoá. Hãy tải lại trang.');
                        return;
                    }
                } catch (err) {
                    // Rơi xuống dùng bản cũ ngay dưới.
                }
                if (row) row.classList.remove('is-loading');

                if (!fresh) {
                    if (!cached) { toastErr('Không tải được mặt hàng. Kiểm tra kết nối API.'); return; }
                    toastErr('Không tải được dữ liệu mới nhất — đang mở bản đã tải lúc vào trang.');
                }
                openModal(mode, fresh || cached);
            }

            // Trạng thái của hộp thoại đang mở — saveModal() nằm ngoài openModal()
            // nên giá trị này phải ở scope chung, không được khai bằng const bên trong.
            let variantsKnown = true;

            function openModal(mode, p) {
                const isEdit = mode === 'edit';
                const isView = mode === 'view';
                const g = (k, d = '') => ((isEdit || isView) && p && p[k] != null ? p[k] : d);

                // Quy tắc bản cũ v2: chỉ NHÓM LÁ dưới nhánh "Hàng bán" mới gắn hàng
                // được. Nhóm cha vẫn bày ra nhưng khoá lại — bỏ hẳn thì người dùng
                // mất luôn đường nhìn ra món này nằm nhánh nào.
                //
                // Mặt hàng cũ đang nằm ở một nhóm ngoài tập ấy (nhánh khác, hoặc
                // nhóm vừa được thêm con nên hết là lá) thì vẫn phải chọn được chính
                // nó, nếu không lượt Lưu sau âm thầm đổi nhóm của người ta.
                const catNow = (isEdit || isView) && p && p.category_id != null ? Number(p.category_id) : 0;
                const catOpts = ['<option value="">Chọn nhóm hàng hóa</option>'].concat(
                    CATEGORIES.filter((c) => c.sellable || Number(c.id) === catNow).map((c) => {
                        const chonDuoc = (c.leaf && c.sellable) || Number(c.id) === catNow;
                        const sel = catNow === Number(c.id) ? 'selected' : '';
                        const pad = '&nbsp;&nbsp;'.repeat(Math.max(0, c.level)) + (c.level > 0 ? '└ ' : '');
                        return `<option value="${c.id}" data-vat="${c.vat}" ${sel} ${chonDuoc ? '' : 'disabled'}>${pad}${esc(c.name)}</option>`;
                    })
                ).join('');

                // Ô chọn vị trí. LOCATIONS chỉ chứa vị trí ĐANG BẬT, nên sản phẩm đang
                // để ở một vị trí vừa bị tắt sẽ không tìm thấy dòng của nó — và lượt
                // Lưu tiếp theo âm thầm gỡ mất vị trí ấy. Chèn lại dòng đó vào đầu
                // danh sách, ghi rõ là đã tắt.
                const locId = (isEdit || isView) && p && p.location_id != null ? Number(p.location_id) : 0;
                const locList = LOCATIONS.slice();
                if (locId > 0 && !locList.some((l) => Number(l.id) === locId)) {
                    const cu = p && p.location ? p.location : null;
                    locList.unshift({ id: locId, code: (cu && cu.code) || '', name: ((cu && cu.name) || 'Vị trí không còn bày ra') + ' (đã tắt)' });
                }
                const locOpts = ['<option value="0">Chưa gán vị trí</option>'].concat(
                    locList.map((l) => {
                        const sel = locId === Number(l.id) ? 'selected' : '';
                        const nhan = l.code ? `${esc(l.code)} · ${esc(l.name)}` : esc(l.name);
                        return `<option value="${l.id}" ${sel}>${nhan}</option>`;
                    })
                ).join('');

                // Ô chọn đơn vị tính. Cùng cách chèn lại dòng "đã tắt" như vị trí:
                // đơn vị vừa bị tắt mà mặt hàng đang dùng thì phải còn trong danh
                // sách, không thì lượt Lưu sau âm thầm gỡ mất đơn vị ấy.
                const unitId = (isEdit || isView) && p && p.unit_id != null ? Number(p.unit_id) : 0;
                const unitList = UNITS.slice();
                if (unitId > 0 && !unitList.some((u) => Number(u.id) === unitId)) {
                    const cu = p && p.unit ? p.unit : null;
                    unitList.unshift({ id: unitId, code: (cu && cu.code) || '', name: ((cu && cu.name) || 'Đơn vị không còn bày ra') + ' (đã tắt)' });
                }
                const unitOpts = ['<option value="0">Chưa khai</option>'].concat(
                    unitList.map((u) => {
                        const sel = unitId === Number(u.id) ? 'selected' : '';
                        const nhan = u.code ? `${esc(u.code)} · ${esc(u.name)}` : esc(u.name);
                        return `<option value="${u.id}" ${sel}>${nhan}</option>`;
                    })
                ).join('');

                // Mức thuế: bộ mức lấy từ màn Thuế. Mặt hàng cũ mang một mức đã bị
                // bỏ tick ở đó thì vẫn phải hiện ra, không thì lưu lại là đổi thuế.
                const vatNow = (isEdit || isView) && p && p.vat != null ? Number(p.vat) : 0;
                const vatList = VAT_RATES.slice();
                if (!vatList.includes(vatNow)) vatList.unshift(vatNow);
                const vatOpts = vatList.map((v) => {
                    const sel = v === vatNow ? 'selected' : '';
                    return `<option value="${v}" ${sel}>${esc(VAT_LABELS[v] || vatText(v))}</option>`;
                }).join('');

                // Trạng thái kinh doanh. Mặt hàng mới mở sẵn công tắc "đang bán" như
                // bản cũ; muốn khai xong giá, ảnh rồi mới bán thì tắt công tắc đi.
                const status = (isEdit || isView) && p
                    ? (STATUSES[p.status] ? p.status : (p.is_active ? 'active' : 'hidden'))
                    : 'active';

                const thumb = g('thumbnail', '');

                // Biến thể hiện có (khi sửa/xem) — lấy từ dữ liệu sản phẩm đã preload.
                const existingVariants = ((isEdit || isView) && p && Array.isArray(p.variants)) ? p.variants : [];
                const varRowsHtml = existingVariants.map((v) => variantRowHtml(v, isView)).join('');

                // Hộp thoại có THẬT SỰ nắm được biến thể của mặt hàng này không?
                //
                // Lúc lưu, mảng rỗng gửi lên nghĩa là "xoá sạch" — API xoá cứng mọi
                // biến thể không nằm trong danh sách. Nên nếu dữ liệu về thiếu (API
                // chưa chạy nên rơi về bản đã tải sẵn), hộp thoại PHẢI im lặng bỏ
                // qua phần này thay vì gửi mảng rỗng và xoá sạch của người ta.
                //
                // Thêm mặt hàng mới thì đương nhiên là nắm được: chưa có gì để mất.
                variantsKnown = !isEdit || !!(p && Array.isArray(p.variants));

                // Hàng nhiều biến thể hay hàng đơn? Bảng biến thể và ô Mã vạch ở
                // tab Chi tiết bật/tắt theo đúng câu trả lời này.
                const coToHop = existingVariants.some((v) => Array.isArray(v.attributes) && v.attributes.length > 0);
                // Hàng đơn chỉ có một dòng biến thể mặc định; mã vạch của nó chính là
                // mã vạch của mặt hàng, nên bày thẳng ở tab Chi tiết như v2.
                const donMacDinh = coToHop ? null : (existingVariants[0] || null);
                const barcodeDon = donMacDinh && donMacDinh.barcode ? donMacDinh.barcode : '';

                // Bốn công tắc. Mặt hàng mới lấy mặc định của bản cũ: đang bán, in
                // tem và trừ kho bật sẵn, seri tắt.
                const dangBan = (isEdit || isView) && p ? status === 'active' : true;
                const coInTem = (isEdit || isView) && p ? !!p.print_label : true;
                const coTruKho = (isEdit || isView) && p ? !!p.is_stock_deducted : true;
                const coSeri = (isEdit || isView) && p ? !!p.is_serial : false;

                // Quy đổi đơn vị đang khai của mặt hàng.
                const quyDoi = ((isEdit || isView) && p && Array.isArray(p.unit_conversions)) ? p.unit_conversions : [];

                const modalTitle = isView ? 'Chi tiết hàng hóa' : (isEdit ? 'Sửa hàng hóa' : 'Thêm mới');

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
                                    </div>` : '<div class="prd-modal-sub">Mã hàng được cấp sau khi lưu</div>'}
                                </div>
                                <button type="button" class="prd-modal-x" id="prdModalX">
                                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                                </button>
                            </div>

                            {{-- Hai tab đúng như bản cũ v2. Lỗi nằm ở tab đang đóng thì
                                 tab đó sáng đỏ và tự mở ra. --}}
                            <div class="prd-tabs" role="tablist">
                                <button type="button" class="prd-tab is-active" data-tab="info" role="tab">Chi tiết</button>
                                <button type="button" class="prd-tab" data-tab="attr" role="tab">Thuộc tính</button>
                            </div>

                            <div class="prd-modal-body">
                            {{-- ===== Tab 1: Chi tiết ===== --}}
                            <section class="prd-panel is-active" data-panel="info">
                                <div class="prd-two">
                                    {{-- Cột trái: nhãn "Hình ảnh", ô ảnh, nút Tải lên, rồi
                                         khung có viền chứa các công tắc — xếp đúng như bản cũ. --}}
                                    <div class="prd-side">
                                        <p class="prd-side-title">Hình ảnh</p>
                                        <div class="prd-img-preview" id="mImgPreview"></div>
                                        ${isView ? '' : `<button type="button" class="prd-upload-btn" id="mImgPick">Tải lên</button>
                                        <input type="file" id="mImgInput" accept="image/*" hidden>`}

                                        {{-- Bốn công tắc, đúng thứ tự bản cũ. --}}
                                        <div class="prd-side-box">
                                            <div class="prd-side-row">
                                                <span class="prd-switch-label">Trạng thái</span>
                                                <button type="button" class="prd-switch ${dangBan ? 'on' : ''}" id="mActive" data-on="${dangBan ? 1 : 0}"><span class="prd-switch-knob"></span></button>
                                            </div>
                                            <div class="prd-side-row">
                                                <span class="prd-switch-label">In tem</span>
                                                <button type="button" class="prd-switch ${coInTem ? 'on' : ''}" id="mPrintLabel" data-on="${coInTem ? 1 : 0}"><span class="prd-switch-knob"></span></button>
                                            </div>
                                            <div class="prd-side-row">
                                                <span class="prd-switch-label">Trừ kho</span>
                                                <button type="button" class="prd-switch ${coTruKho ? 'on' : ''}" id="mStockDeducted" data-on="${coTruKho ? 1 : 0}"><span class="prd-switch-knob"></span></button>
                                            </div>
                                            <div class="prd-side-row">
                                                <span class="prd-switch-label">Số seri/IMEI</span>
                                                <button type="button" class="prd-switch ${coSeri ? 'on' : ''}" id="mSerial" data-on="${coSeri ? 1 : 0}"><span class="prd-switch-knob"></span></button>
                                            </div>
                                        </div>
                                        {{-- Mặt hàng đang NGỪNG KINH DOANH: công tắc chỉ có hai nấc nên
                                             không diễn tả được mức thứ ba. Nói thẳng ra đây, và lượt Lưu
                                             gửi cờ bật/tắt để máy chủ giữ nguyên mức ấy (xem
                                             resolveProductStatus) thay vì hạ xuống "tạm ẩn". --}}
                                        ${status === 'discontinued' ? '<p class="prd-hint">Mặt hàng đang <b>ngừng kinh doanh</b>. Bật công tắc để bán lại; để tắt thì giữ nguyên mức này.</p>' : ''}
                                        ${isView ? '' : `<button type="button" class="prd-btn-ghost prd-img-remove" id="mImgRemove">Xoá ảnh</button>`}
                                    </div>

                                    {{-- Cột phải: lưới BỐN ô mỗi hàng, đúng như bản cũ. --}}
                                    <div class="prd-body">
                                        <div class="prd-grid4">
                                            <div>
                                                <label class="prd-field-label" for="mSku">Mã hàng hóa ${MA_TU_SINH ? '' : '<span class="prd-req">*</span>'}</label>
                                                <input type="text" id="mSku" class="prd-input" value="${esc(g('sku'))}"
                                                       placeholder="${MA_TU_SINH ? 'Mã tăng tự động' : 'Mã hàng hóa'}"
                                                       ${MA_TU_SINH && !isEdit ? 'readonly' : ''}>
                                                <p class="prd-err" data-err="mSku"></p>
                                            </div>
                                            <div>
                                                <label class="prd-field-label" for="mName">Tên hàng hóa <span class="prd-req">*</span></label>
                                                <input type="text" id="mName" class="prd-input" placeholder="Tên hàng hóa" value="${esc(g('name'))}">
                                                <p class="prd-err" data-err="mName"></p>
                                            </div>
                                            <div>
                                                <label class="prd-field-label" for="mBarcode">Bar/Qr Code</label>
                                                <input type="text" id="mBarcode" class="prd-input" value="${esc(barcodeDon)}" placeholder="Mã vạch">
                                                {{-- Hàng nhiều biến thể thì mỗi biến thể một mã vạch
                                                     riêng, khai ở tab Thuộc tính — ô này khoá lại. --}}
                                                <p class="prd-hint" id="mBarcodeHint"></p>
                                            </div>
                                            <div>
                                                <label class="prd-field-label" for="mCategory">Nhóm hàng hóa <span class="prd-req">*</span></label>
                                                <select id="mCategory" class="prd-msel">${catOpts}</select>
                                                <p class="prd-err" data-err="mCategory"></p>
                                            </div>
                                        </div>

                                        <div class="prd-grid4">
                                            <div>
                                                <label class="prd-field-label" for="mBasePrice">Giá bán <span class="prd-req">*</span></label>
                                                <div class="prd-input-prefix">
                                                    <input type="text" inputmode="numeric" id="mBasePrice" class="prd-input" placeholder="Giá bán" value="${(isEdit || isView) && p && p.base_price != null ? fmtVnd(p.base_price) : ''}">
                                                    <span class="prd-input-suffix">₫</span>
                                                </div>
                                                <p class="prd-err" data-err="mBasePrice"></p>
                                            </div>
                                            <div>
                                                <label class="prd-field-label" for="mCostPrice">Giá vốn</label>
                                                <div class="prd-input-prefix">
                                                    <input type="text" inputmode="numeric" id="mCostPrice" class="prd-input" placeholder="Giá vốn" value="${p && p.cost_price != null ? fmtVnd(p.cost_price) : ''}">
                                                    <span class="prd-input-suffix">₫</span>
                                                </div>
                                                <p class="prd-err" data-err="mCostPrice"></p>
                                            </div>
                                            <div>
                                                <label class="prd-field-label" for="mUnit">Đơn vị tính</label>
                                                <select id="mUnit" class="prd-msel">${unitOpts}</select>
                                            </div>
                                            <div>
                                                {{-- Thẻ hàng hóa: nhãn người bán tự dán, thu ngân sẽ bày
                                                     thành dãy phím lọc. Tick thẻ có sẵn hoặc gõ thẻ mới. --}}
                                                <label class="prd-field-label">Thẻ hàng hóa</label>
                                                <div class="prd-pick" id="mTagsPick" data-pick="tags">
                                                    <button type="button" class="prd-pick-btn" ${isView ? 'disabled' : ''}>
                                                        <span class="prd-pick-text">Chưa dán thẻ</span>
                                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                                    </button>
                                                    <div class="prd-pick-pop">
                                                        <div class="prd-pick-list"></div>
                                                        <div class="prd-pick-new">
                                                            <input type="text" class="prd-input prd-input-sm" maxlength="50" placeholder="Thẻ mới rồi Enter">
                                                            <button type="button" class="prd-btn-primary prd-btn-xs">Thêm</button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="prd-grid4">
                                            <div>
                                                <label class="prd-field-label" for="mVat">% VAT</label>
                                                <select id="mVat" class="prd-msel">${vatOpts}</select>
                                            </div>
                                            <div>
                                                {{-- Giá bán và giá sau thuế tự tính cho nhau theo %VAT
                                                     đang chọn, y như bản cũ. Chỉ giá bán được lưu. --}}
                                                <label class="prd-field-label" for="mPriceAfterTax">Giá sau thuế</label>
                                                <div class="prd-input-prefix">
                                                    <input type="text" inputmode="numeric" id="mPriceAfterTax" class="prd-input" placeholder="Tự tính">
                                                    <span class="prd-input-suffix">₫</span>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="prd-field-label" for="mLocation">Vị trí</label>
                                                <select id="mLocation" class="prd-msel">${locOpts}</select>
                                            </div>
                                            <div>
                                                {{-- Chi nhánh QUẢN LÝ mặt hàng. Không tick gì = mọi chi
                                                     nhánh — cửa hàng một chi nhánh không phải đụng tới. --}}
                                                <label class="prd-field-label">Chi nhánh</label>
                                                <div class="prd-pick" id="mShopsPick" data-pick="shops">
                                                    <button type="button" class="prd-pick-btn" ${isView ? 'disabled' : ''}>
                                                        <span class="prd-pick-text">Mọi chi nhánh</span>
                                                        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                                    </button>
                                                    <div class="prd-pick-pop">
                                                        <div class="prd-pick-list"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- KHÔNG có ô "Giá khuyến mãi": giảm giá là việc của màn
                                             Khuyến mãi, nơi khai được thời gian chạy và điều kiện áp
                                             dụng. Một ô giá nằm ở đây thì không ai biết nó hết hạn
                                             lúc nào. --}}
                                        <div class="prd-grid4">
                                            <div class="prd-col-4">
                                                <label class="prd-field-label" for="mShortDesc">Mô tả ngắn</label>
                                                <input type="text" id="mShortDesc" class="prd-input" maxlength="500" placeholder="Một hai câu giới thiệu" value="${esc(g('short_description'))}">
                                            </div>
                                        </div>

                                        <div class="prd-price-preview" id="mPricePreview"></div>

                                        {{-- Quy đổi đơn vị hàng hoá — đúng chỗ và đúng dạng bản cũ:
                                             khối xếp gọn ở chân cột phải, có nút Thêm và nút xoá hết. --}}
                                        <details class="prd-acc" ${quyDoi.length ? 'open' : ''}>
                                            <summary>
                                                Quy đổi đơn vị hàng hóa
                                                ${isView ? '' : `<span class="prd-acc-tools">
                                                    <button type="button" class="prd-btn-primary prd-btn-xs" id="mConvAdd">Thêm</button>
                                                    <button type="button" class="prd-btn-x" id="mConvClear" title="Xoá hết dòng quy đổi">&times;</button>
                                                </span>`}
                                            </summary>
                                            <div class="prd-acc-body">
                                                <div class="prd-conv-head">
                                                    <span>SL quy đổi</span>
                                                    <span>ĐV quy đổi</span>
                                                    <span class="prd-conv-eq">=</span>
                                                    <span>Số lượng</span>
                                                    <span>ĐVT chính</span>
                                                    <span></span>
                                                </div>
                                                <div id="mConvRows">${quyDoi.map((c) => convRowHtml(c, isView)).join('')}</div>
                                                <p class="prd-conv-empty" id="mConvEmpty" ${quyDoi.length ? 'hidden' : ''}>Chưa khai quy đổi nào.</p>
                                                <p class="prd-err" data-err="mConvRows"></p>
                                            </div>
                                        </details>

                                        <details class="prd-acc">
                                            <summary>Mô tả chi tiết &amp; SEO</summary>
                                            <div class="prd-acc-body">
                                                <div>
                                                    <label class="prd-field-label" for="mDesc">Mô tả chi tiết</label>
                                                    <textarea id="mDesc" class="prd-textarea" placeholder="Thông số, chất liệu, bảo hành...">${esc(g('description'))}</textarea>
                                                </div>
                                                <div class="prd-grid2" style="margin-top:12px">
                                                    <div>
                                                        <label class="prd-field-label" for="mMetaTitle">Meta title</label>
                                                        <input type="text" id="mMetaTitle" class="prd-input" maxlength="255" placeholder="Bỏ trống = dùng tên hàng hóa" value="${esc(g('meta_title'))}">
                                                    </div>
                                                    <div>
                                                        <label class="prd-field-label" for="mMetaDesc">Meta description</label>
                                                        <input type="text" id="mMetaDesc" class="prd-input" maxlength="320" placeholder="Bỏ trống = tự lấy từ mô tả ngắn" value="${esc(g('meta_description'))}">
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </section>

                            {{-- ===== Tab 2: Thuộc tính ===== --}}
                            <section class="prd-panel" data-panel="attr">
                                <div class="prd-var">
                                    ${isView ? '' : `<div class="prd-attrs" id="mAttrs">${attrPickerHtml(existingVariants)}</div>`}

                                    {{-- Bảng biến thể chỉ hiện khi có tổ hợp. Hàng đơn thì mã
                                         vạch và giá khai ở tab Chi tiết, bày thêm một bảng một
                                         dòng ở đây chỉ tổ hai chỗ nhập cho cùng một thứ. --}}
                                    <div id="mVarWrap" ${coToHop ? '' : 'hidden'}>
                                        <div class="prd-var-head">
                                            <span>Tên biến thể</span>
                                            <span>Mã hàng</span>
                                            <span>Mã vạch</span>
                                            <span>Giá riêng</span>
                                            <span>Giá vốn riêng</span>
                                            <span style="text-align:right">Tồn kho</span>
                                            <span></span>
                                        </div>
                                        <div id="mVariants">${varRowsHtml}</div>
                                    </div>
                                    <p class="prd-err" data-err="mVariants"></p>
                                    ${isView ? '' : `<p class="prd-hint">Giá riêng của biến thể đè giá khuyến mãi của mặt hàng. Tồn kho chỉ xem — đổi ở <a href="{{ route('admin.inventory.index') }}" target="_blank">Kho</a>.</p>`}
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
                                        : `<button type="button" class="prd-btn-primary" id="prdModalSave">Xác nhận</button>`
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

                // Chi nhánh quản lý mặt hàng. Chi nhánh đã ngừng hoạt động không
                // nằm trong danh sách chọn nữa, nhưng mặt hàng đang gắn nó thì phải
                // thấy — không thì lượt Lưu sau lặng lẽ gỡ mất.
                const chiNhanhDaGan = ((p && p.shops) || []).map((c) => ({ id: c.id, name: c.name }));
                const nguonChiNhanh = [...BRANCHES.map((c) => ({ id: c.id, name: c.name }))];
                chiNhanhDaGan.forEach((c) => {
                    if (!nguonChiNhanh.some((x) => x.id === c.id)) nguonChiNhanh.push(c);
                });
                dungOChon(dialog, 'shops', {
                    nguon: nguonChiNhanh,
                    dangChon: chiNhanhDaGan.map((c) => String(c.id)),
                    chuaChon: 'Mọi chi nhánh',
                    khongCo: 'Cửa hàng chưa mở chi nhánh nào.',
                    chiDoc: isView,
                });

                // Thẻ đi theo TÊN chứ không theo id: ô này cho gõ thẻ mới tại chỗ.
                const theDaDan = ((p && p.tags) || []).map((t) => t.name);
                const nguonThe = [...TAGS.map((t) => t.name)];
                theDaDan.forEach((ten) => {
                    if (!nguonThe.some((x) => x.toLowerCase() === ten.toLowerCase())) nguonThe.push(ten);
                });
                dungOChon(dialog, 'tags', {
                    nguon: nguonThe.map((ten) => ({ id: ten, name: ten })),
                    dangChon: theDaDan,
                    chuaChon: 'Chưa dán thẻ',
                    khongCo: 'Chưa có thẻ nào — gõ tên bên dưới để mở thẻ mới.',
                    chiDoc: isView,
                });

                // Bốn công tắc: bấm là lật, giá trị nằm ở data-on.
                if (!isView) {
                    ['mActive', 'mPrintLabel', 'mStockDeducted', 'mSerial'].forEach((id) => {
                        const btn = document.getElementById(id);
                        if (!btn) return;
                        btn.addEventListener('click', () => {
                            const on = btn.dataset.on === '1';
                            btn.dataset.on = on ? '0' : '1';
                            btn.classList.toggle('on', !on);
                        });
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
                    wireMoney('mCostPrice');
                    ['mBasePrice', 'mCostPrice'].forEach((id) => {
                        const el = document.getElementById(id);
                        if (el) el.addEventListener('input', renderPricePreview);
                    });
                    renderPricePreview();

                    wireMoney('mPriceAfterTax');

                    // Giá bán <-> giá sau thuế tự tính cho nhau theo %VAT đang chọn,
                    // đúng như v2. KCT (-1) / KKKNT (-2) coi như 0%. Chỉ giá bán được
                    // lưu; ô giá sau thuế chỉ để người khai đối chiếu với bảng giá.
                    const vatEl2 = document.getElementById('mVat');
                    const baseEl = document.getElementById('mBasePrice');
                    const afterEl = document.getElementById('mPriceAfterTax');
                    let dangDongBoGia = false;
                    const mucVat = () => {
                        const v = Number(vatEl2.value);
                        return (isNaN(v) || v < 0) ? 0 : v;
                    };
                    const tinhSauThue = () => {
                        if (dangDongBoGia) return;
                        dangDongBoGia = true;
                        const gia = Number(digitsOnly(baseEl.value) || 0);
                        afterEl.value = gia > 0 ? fmtVnd(Math.round(gia * (1 + mucVat() / 100))) : '';
                        dangDongBoGia = false;
                    };
                    const tinhGiaBan = () => {
                        if (dangDongBoGia) return;
                        dangDongBoGia = true;
                        const sau = Number(digitsOnly(afterEl.value) || 0);
                        baseEl.value = sau > 0 ? fmtVnd(Math.round(sau / (1 + mucVat() / 100))) : '';
                        dangDongBoGia = false;
                        renderPricePreview();
                    };
                    baseEl.addEventListener('input', tinhSauThue);
                    afterEl.addEventListener('input', tinhGiaBan);
                    vatEl2.addEventListener('change', tinhSauThue);
                    tinhSauThue();

                    // Quy đổi đơn vị: thêm dòng, xoá dòng, xoá hết.
                    const convRows = document.getElementById('mConvRows');
                    const convEmpty = document.getElementById('mConvEmpty');
                    const syncConvEmpty = () => { convEmpty.hidden = !!convRows.querySelector('.prd-conv-row'); };
                    // Tên đơn vị tính chính hiện ở cột cuối mỗi dòng cho đọc thành câu:
                    // "1 Thùng = 24 Cái". Đổi ĐVT chính thì mọi dòng đổi theo.
                    const syncConvMain = () => {
                        const el = document.getElementById('mUnit');
                        const ten = el && el.selectedOptions[0] && Number(el.value) > 0
                            ? el.selectedOptions[0].textContent.split('·').pop().trim()
                            : '—';
                        convRows.querySelectorAll('.prd-conv-main').forEach((n) => { n.textContent = ten; });
                    };
                    // Hai nút nằm TRONG <summary> nên bấm là trình duyệt gập/mở khối
                    // — chặn lại, và mở sẵn khối khi vừa thêm dòng.
                    document.getElementById('mConvAdd').addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        convRows.closest('details').open = true;
                        convRows.insertAdjacentHTML('beforeend', convRowHtml(null, false));
                        syncConvEmpty();
                        syncConvMain();
                    });
                    document.getElementById('mConvClear').addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        convRows.innerHTML = '';
                        syncConvEmpty();
                    });
                    convRows.addEventListener('click', (e) => {
                        const del = e.target.closest('.prd-conv-del');
                        if (del) { del.closest('.prd-conv-row').remove(); syncConvEmpty(); }
                    });
                    document.getElementById('mUnit').addEventListener('change', syncConvMain);
                    syncConvMain();

                    // Ô Mã vạch ở tab Chi tiết chỉ dùng cho HÀNG ĐƠN. Hàng nhiều biến
                    // thể thì mỗi biến thể một mã riêng, khai ở bảng bên tab Thuộc tính.
                    const barcodeEl = document.getElementById('mBarcode');
                    const barcodeHint = document.getElementById('mBarcodeHint');
                    window.prdSyncBarcode = (nhieuBienThe) => {
                        barcodeEl.disabled = !!nhieuBienThe;
                        barcodeHint.textContent = nhieuBienThe
                            ? 'Hàng nhiều biến thể: mã vạch khai ở tab Thuộc tính.'
                            : '';
                    };
                    prdSyncBarcode(coToHop);

                    // Tick / bỏ tick một giá trị thuộc tính là dựng lại bảng tổ hợp.
                    //
                    // Dựng lại chứ không thêm dồn: tổ hợp là TÍCH của các chiều đang
                    // chọn, thêm một màu là mọi dung lượng đều sinh thêm một dòng.
                    // Dòng cũ khớp đúng tổ hợp thì GIỮ NGUYÊN id, mã, mã vạch và giá —
                    // đổi một chiều mà mất hết mã vạch đã dán là không chấp nhận được.
                    const attrsBox = document.getElementById('mAttrs');
                    if (attrsBox) {
                        attrsBox.addEventListener('change', (e) => {
                            const cb = e.target.closest('input[type="checkbox"]');
                            if (!cb) return;
                            cb.closest('.prd-attr-val').classList.toggle('is-on', cb.checked);
                            regenVariants();
                        });
                    }

                    // Mặt hàng MỚI chưa có dòng biến thể nào, mà mọi mặt hàng đều
                    // phải có ít nhất DÒNG MẶC ĐỊNH — không thì lượt Lưu không gửi
                    // biến thể nào và bị chặn ngay tại chỗ ("Bảng biến thể đang
                    // trống"), tức là không thêm được mặt hàng nào cả.
                    //
                    // Dựng bằng chính regenVariants: chưa tick chiều nào thì nó sinh
                    // đúng một dòng mặc định và giấu bảng tổ hợp đi — hàng đơn.
                    if (!varRowsHtml) regenVariants();

                    // Chọn nhóm hàng hóa thì ô thuế tự điền theo mức của nhóm — quy tắc
                    // bản cũ v2 (odr_menu_groups.id_tax). Vẫn sửa đè được sau đó.
                    //
                    // Mức của nhóm có thể không nằm trong bộ mức đang bật ở màn Thuế
                    // (vừa bị bỏ tick), nên chèn thêm dòng thay vì bỏ qua trong im lặng.
                    const catEl = document.getElementById('mCategory');
                    const vatEl = document.getElementById('mVat');
                    if (catEl && vatEl) {
                        catEl.addEventListener('change', () => {
                            const opt = catEl.selectedOptions[0];
                            if (!opt || !opt.dataset.vat) return;
                            const v = opt.dataset.vat;
                            if (!vatEl.querySelector(`option[value="${v}"]`)) {
                                vatEl.insertAdjacentHTML('afterbegin',
                                    `<option value="${v}">${esc(VAT_LABELS[v] || vatText(v))}</option>`);
                            }
                            vatEl.value = v;
                        });
                    }

                    // Mã hàng tự ghép từ TÊN (vẫn sửa tay được).
                    // Quy tắc đánh số đang bật thì mã do máy chủ đặt — trình duyệt
                    // không ghép mã nữa, nếu không người dùng thấy một mã rồi lưu ra mã khác.
                    let skuDirty = isEdit || MA_TU_SINH;
                    const skuEl = document.getElementById('mSku');
                    if (skuEl) skuEl.addEventListener('input', () => { skuDirty = true; });
                    const refreshSku = () => { if (!skuDirty && skuEl) skuEl.value = genSku(); };
                    const nameEl2 = document.getElementById('mName');
                    if (nameEl2) nameEl2.addEventListener('input', refreshSku);
                    if (!isEdit) refreshSku();
                }

                const varsBox = document.getElementById('mVariants');
                if (!isView && varsBox) {
                    // Xoá một dòng = bỏ tổ hợp đó. Bỏ tick ở khối thuộc tính cũng ra
                    // cùng kết quả; giữ nút xoá ở đây cho trường hợp chỉ muốn bỏ đúng
                    // một tổ hợp (VD "256GB · Vàng" không nhập nữa) mà vẫn giữ cả hai
                    // chiều — tích đầy đủ thì bỏ tick không làm được việc ấy.
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
             * Dựng một ô CHỌN NHIỀU trong hộp thoại (chi nhánh, thẻ).
             *
             * Giá trị nằm ngay trên các ô tick chứ không giữ trong một biến riêng:
             * lượt Lưu đọc thẳng từ DOM, nên không có đường nào để cái đang hiện và
             * cái sắp gửi lệch nhau.
             */
            function dungOChon(dialog, ten, o) {
                const boc = dialog.querySelector(`[data-pick="${ten}"]`);
                if (!boc) return;
                const nut = boc.querySelector('.prd-pick-btn');
                const chu = boc.querySelector('.prd-pick-text');
                const ds = boc.querySelector('.prd-pick-list');
                const oMoi = boc.querySelector('.prd-pick-new input');
                const nutMoi = boc.querySelector('.prd-pick-new button');

                const dangChon = new Set(o.dangChon.map(String));

                function ve() {
                    ds.innerHTML = o.nguon.length
                        ? o.nguon.map((m) => `
                            <label class="prd-pick-item">
                                <input type="checkbox" value="${esc(String(m.id))}" ${dangChon.has(String(m.id)) ? 'checked' : ''} ${o.chiDoc ? 'disabled' : ''}>
                                <span>${esc(m.name)}</span>
                            </label>`).join('')
                        : `<p class="prd-pick-empty">${esc(o.khongCo)}</p>`;
                    capNhatChu();
                }

                function capNhatChu() {
                    const ten = o.nguon.filter((m) => dangChon.has(String(m.id))).map((m) => m.name);
                    chu.textContent = ten.length ? ten.join(', ') : o.chuaChon;
                    chu.title = ten.join(', ');
                    boc.classList.toggle('is-empty', ten.length === 0);
                }

                ds.addEventListener('change', (e) => {
                    const ô = e.target.closest('input[type=checkbox]');
                    if (!ô) return;
                    if (ô.checked) dangChon.add(ô.value); else dangChon.delete(ô.value);
                    capNhatChu();
                });

                if (!o.chiDoc) {
                    nut.addEventListener('click', (e) => {
                        e.stopPropagation();
                        boc.classList.toggle('open');
                    });
                    boc.addEventListener('click', (e) => e.stopPropagation());
                    dialog.addEventListener('click', () => boc.classList.remove('open'));
                }

                // Ô "thẻ mới": tên chưa có thì thêm vào danh sách và tick sẵn.
                if (oMoi && nutMoi) {
                    const themThe = () => {
                        const ten = oMoi.value.trim().replace(/\s+/g, ' ');
                        if (!ten) return;
                        const trung = o.nguon.find((m) => String(m.name).toLowerCase() === ten.toLowerCase());
                        const khoa = trung ? String(trung.id) : ten;
                        if (!trung) o.nguon.push({ id: ten, name: ten });
                        dangChon.add(khoa);
                        oMoi.value = '';
                        ve();
                    };
                    nutMoi.addEventListener('click', themThe);
                    oMoi.addEventListener('keydown', (e) => {
                        if (e.key !== 'Enter') return;
                        e.preventDefault();     // Enter trong hộp thoại vốn là Lưu
                        themThe();
                    });
                }

                ve();
            }

            /**
             * Xem trước lãi gộp trên một cái.
             *
             * Giá bán và giá vốn nằm cách nhau một ô trong hộp thoại, và người khai
             * hiếm khi trừ nhẩm — in thẳng con số ra đây thì bán dưới giá vốn lộ ra
             * ngay lúc gõ, không đợi tới cuối tháng xem báo cáo.
             *
             * KHÔNG còn nhánh giá khuyến mãi: giảm giá do màn Khuyến mãi quyết, mà
             * chương trình nào đang chạy thì hộp thoại này không biết.
             */
            function renderPricePreview() {
                const box = document.getElementById('mPricePreview');
                if (!box) return;
                const base = Number(digitsOnly(document.getElementById('mBasePrice').value) || 0);
                const cost = Number(digitsOnly(document.getElementById('mCostPrice').value) || 0);

                if (base <= 0) { box.innerHTML = ''; return; }

                const now = base;
                const margin = cost > 0 ? now - cost : null;

                box.innerHTML = `
                    <span class="prd-pp-item">
                        <small>Khách trả</small>
                        <b>${fmtVnd(now)}₫</b>
                    </span>
                    ${margin !== null ? `<span class="prd-pp-item">
                        <small>Lãi gộp mỗi cái</small>
                        <b class="${margin < 0 ? 'prd-pp-loss' : ''}">${margin < 0 ? '−' : ''}${fmtVnd(Math.abs(margin))}₫</b>
                    </span>` : ''}
                    ${margin !== null && margin < 0 ? '<span class="prd-pp-note">Đang bán dưới giá vốn.</span>' : ''}`;
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

            /**
             * HTML một dòng biến thể.
             *
             * v = { id, sku, barcode, name, price, cost_price, stock_quantity,
             *       attributes: [{attribute_id, value_id}] }.
             * v = null hoặc attributes rỗng -> dòng MẶC ĐỊNH của hàng đơn.
             *
             * Tồn kho hiển thị để đối chiếu nhưng KHÔNG phải ô nhập: cột này chỉ đổi
             * qua nghiệp vụ kho nên mọi biến động đều có vết trong sổ kho.
             */
            function variantRowHtml(v, isView = false) {
                const vid = v && v.id ? v.id : 0;
                const attrs = (v && Array.isArray(v.attributes)) ? v.attributes.map((a) => ({
                    attribute_id: Number(a.attribute_id), value_id: Number(a.value_id),
                })) : [];
                const ten = v ? String(v.name || '') : '';
                const sku = v ? esc(v.sku || '') : '';
                const barcode = v ? esc(v.barcode || '') : '';
                const price = v && v.price != null ? fmtVnd(v.price) : '';
                const cost = v && v.cost_price != null ? fmtVnd(v.cost_price) : '';
                const stock = v && v.stock_quantity != null ? Number(v.stock_quantity) : 0;
                const stockTitle = vid
                    ? 'Tồn kho hiện tại — đổi ở trang Kho'
                    : 'Biến thể mới luôn bắt đầu ở 0, phải nhập kho mới có hàng';
                const nhan = ten || 'Hàng đơn (không biến thể)';
                return `
                    <div class="prd-var-row" data-vid="${vid}" data-attrs='${esc(JSON.stringify(attrs))}'>
                        <span class="prd-var-name ${ten ? '' : 'is-plain'}" title="${esc(nhan)}">${esc(nhan)}</span>
                        <input type="text" class="prd-input prd-var-sku" placeholder="Tự sinh" value="${sku}">
                        <input type="text" class="prd-input prd-var-barcode" placeholder="Quét hoặc gõ"
                               title="Mã vạch in trên hàng — quầy quét mã này để bán. Để trống nếu chưa dán tem."
                               value="${barcode}">
                        <div class="prd-input-prefix">
                            <input type="text" inputmode="numeric" class="prd-input prd-var-price" placeholder="Theo giá hàng" value="${price}">
                            <span class="prd-input-suffix">₫</span>
                        </div>
                        <div class="prd-input-prefix">
                            <input type="text" inputmode="numeric" class="prd-input prd-var-cost" placeholder="Theo giá vốn hàng" value="${cost}">
                            <span class="prd-input-suffix">₫</span>
                        </div>
                        <span class="prd-var-stock ${stock === 0 ? 'is-zero' : ''}" title="${stockTitle}">${fmtInt(stock)}</span>
                        ${(isView || attrs.length === 0) ? '<span></span>' : '<button type="button" class="prd-var-del" title="Bỏ tổ hợp này">×</button>'}
                    </div>`;
            }

            /**
             * Một dòng quy đổi: 1 <đơn vị quy đổi> = <số lượng> <đơn vị tính chính>.
             *
             * Ô "SL quy đổi" khoá ở 1 như bản cũ — quy đổi luôn tính từ MỘT đơn vị,
             * cho nhập số khác thì cùng một tỉ lệ có hai cách khai và báo cáo lệch.
             */
            function convRowHtml(c, isView = false) {
                const unitId = c && c.unit_id ? Number(c.unit_id) : 0;
                const qty = c && c.quantity != null ? c.quantity : '';
                const opts = UNITS.map((u) => `<option value="${u.id}" ${unitId === Number(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`).join('');
                return `
                    <div class="prd-conv-row">
                        <input type="text" class="prd-input" value="1" disabled>
                        <select class="prd-msel prd-conv-unit">${opts}</select>
                        <span class="prd-conv-eq">=</span>
                        <input type="text" inputmode="decimal" class="prd-input prd-conv-qty" placeholder="0" value="${esc(qty)}">
                        <span class="prd-conv-main" id="">—</span>
                        ${isView ? '<span></span>' : '<button type="button" class="prd-conv-del" title="Xoá dòng">×</button>'}
                    </div>`;
            }

            /**
             * Khối chọn thuộc tính: mỗi thuộc tính một hàng, giá trị là các chip tick được.
             *
             * Giá trị nào đang được một biến thể của mặt hàng dùng thì tick sẵn — mở
             * hộp thoại sửa ra là thấy đúng bộ chiều đang bán, không phải tự nhớ.
             */
            function attrPickerHtml(variants) {
                if (!ATTRIBUTES.length) {
                    return '<p class="prd-attrs-empty">Chưa khai thuộc tính nào — mặt hàng sẽ là hàng đơn. '
                        + 'Khai ở <a href="{{ route('admin.thuoc-tinh.index') }}" target="_blank">Hàng hóa → Thuộc tính</a>.</p>';
                }

                const dangDung = new Set();
                (variants || []).forEach((v) => {
                    (v.attributes || []).forEach((a) => dangDung.add(Number(a.value_id)));
                });

                return ATTRIBUTES.map((tt) => {
                    const vals = (tt.values || []).map((gt) => {
                        const on = dangDung.has(Number(gt.id));
                        return `<label class="prd-attr-val ${on ? 'is-on' : ''}">
                            <input type="checkbox" data-attr="${tt.id}" data-value="${gt.id}" data-name="${esc(gt.name)}" ${on ? 'checked' : ''}>
                            <span>${esc(gt.name)}</span>
                        </label>`;
                    }).join('');
                    if (!vals) return '';
                    return `<div class="prd-attr-row">
                        <span class="prd-attr-name" title="${esc(tt.name)}">${esc(tt.name)}</span>
                        <span class="prd-attr-vals">${vals}</span>
                    </div>`;
                }).join('')
                    + '<div class="prd-attrs-foot"><p class="prd-attrs-empty">'
                    + 'Không tick gì = hàng đơn.</p></div>';
            }

            /** Tích Descartes của các chiều đang tick -> danh sách tổ hợp. */
            function comboList() {
                const box = document.getElementById('mAttrs');
                if (!box) return [];
                const theoThuocTinh = new Map();
                box.querySelectorAll('input[type="checkbox"]:checked').forEach((cb) => {
                    const aid = Number(cb.getAttribute('data-attr'));
                    if (!theoThuocTinh.has(aid)) theoThuocTinh.set(aid, []);
                    theoThuocTinh.get(aid).push({
                        attribute_id: aid,
                        value_id: Number(cb.getAttribute('data-value')),
                        name: cb.getAttribute('data-name') || '',
                    });
                });
                if (!theoThuocTinh.size) return [];

                // Giữ đúng thứ tự thuộc tính như ATTRIBUTES bày ra: tên biến thể ghép
                // theo thứ tự ấy, và người khai đọc lại thấy quen mắt.
                const chieu = ATTRIBUTES
                    .map((tt) => theoThuocTinh.get(Number(tt.id)))
                    .filter(Boolean);

                let out = [[]];
                chieu.forEach((vals) => {
                    const tiep = [];
                    out.forEach((tohop) => vals.forEach((v) => tiep.push(tohop.concat([v]))));
                    out = tiep;
                });
                return out;
            }

            /** Dựng lại bảng biến thể từ các chiều đang tick, giữ dữ liệu dòng cũ. */
            function regenVariants() {
                const box = document.getElementById('mVariants');
                if (!box) return;

                // Chụp lại dòng đang có theo khoá tổ hợp để không mất mã vạch / giá.
                const cu = new Map();
                [...box.querySelectorAll('.prd-var-row')].forEach((row) => {
                    let attrs = [];
                    try { attrs = JSON.parse(row.dataset.attrs || '[]'); } catch (e) { attrs = []; }
                    cu.set(attrKey(attrs), {
                        id: Number(row.dataset.vid || 0),
                        sku: row.querySelector('.prd-var-sku').value,
                        barcode: row.querySelector('.prd-var-barcode').value,
                        price: digitsOnly(row.querySelector('.prd-var-price').value) || null,
                        cost_price: digitsOnly(row.querySelector('.prd-var-cost').value) || null,
                        stock_quantity: Number(digitsOnly(row.querySelector('.prd-var-stock').textContent) || 0),
                    });
                });

                const combos = comboList();
                // Hàng đơn thì giấu cả bảng đi và trả ô Mã vạch ở tab Chi tiết về
                // dùng được — xem prdSyncBarcode.
                const wrap = document.getElementById('mVarWrap');
                if (wrap) wrap.hidden = combos.length === 0;
                if (typeof prdSyncBarcode === 'function') prdSyncBarcode(combos.length > 0);

                const dsach = combos.length
                    ? combos.map((tohop) => ({
                        attributes: tohop.map((v) => ({ attribute_id: v.attribute_id, value_id: v.value_id })),
                        name: tohop.map((v) => v.name).join(' · '),
                    }))
                    : [{ attributes: [], name: '' }];

                box.innerHTML = dsach.map((moi) => {
                    const goc = cu.get(attrKey(moi.attributes)) || {};
                    return variantRowHtml({
                        id: goc.id || 0,
                        sku: goc.sku || '',
                        barcode: goc.barcode || '',
                        name: moi.name,
                        price: goc.price != null ? goc.price : null,
                        cost_price: goc.cost_price != null ? goc.cost_price : null,
                        stock_quantity: goc.stock_quantity || 0,
                        attributes: moi.attributes,
                    }, false);
                }).join('');
            }

            function closeModal() { $modalMount.innerHTML = ''; }

            function saveModal() {
                const dialog = document.getElementById('prdDialog');
                const val = (id) => document.getElementById(id).value.trim();
                clearErrors(dialog);

                const name = val('mName');
                if (!name) { showFieldError(dialog, 'mName', 'Vui lòng nhập tên hàng hóa.'); return; }
                const sku = val('mSku');
                if (!sku && !MA_TU_SINH) { showFieldError(dialog, 'mSku', 'Vui lòng nhập mã hàng.'); return; }
                const categoryId = document.getElementById('mCategory').value;
                if (!categoryId) { showFieldError(dialog, 'mCategory', 'Vui lòng chọn nhóm hàng.'); return; }
                const basePrice = digitsOnly(document.getElementById('mBasePrice').value);
                if (basePrice === '' || Number(basePrice) < 0) { showFieldError(dialog, 'mBasePrice', 'Vui lòng nhập giá bán.'); return; }
                // Giá vốn cao hơn giá bán chỉ cảnh báo chứ không chặn: hàng thanh lý bán
                // dưới giá vốn là chuyện có thật, chặn ở đây là chặn nghiệp vụ đúng.
                const costPrice = digitsOnly(document.getElementById('mCostPrice').value);

                const fields = {
                    name,
                    sku,
                    category_id: categoryId,
                    // Luôn gửi, kể cả 0 ("chưa gán"): hai ô này có mặt ở mọi lượt sửa
                    // nên để trống là ý muốn thật, không phải màn hình dựng hụt.
                    location_id: Number(document.getElementById('mLocation').value || 0),
                    unit_id: Number(document.getElementById('mUnit').value || 0),
                    slug: dialog.dataset.slug || '',
                    vat: document.getElementById('mVat').value,
                    base_price: basePrice,
                    // KHÔNG gửi sale_price: hộp thoại không còn ô ấy, mà gửi rỗng là
                    // gỡ mất giá khuyến mãi đang chạy của màn Khuyến mãi.
                    cost_price: costPrice,
                    thumbnail: dialog.dataset.thumbnail || '',
                    short_description: document.getElementById('mShortDesc').value.trim(),
                    description: document.getElementById('mDesc').value.trim(),
                    meta_title: val('mMetaTitle'),
                    meta_description: val('mMetaDesc'),
                    // Gửi CỜ BẬT/TẮT chứ không gửi status: công tắc chỉ có hai nấc,
                    // mà trạng thái có ba mức. Máy chủ nhận cờ thì giữ nguyên mức
                    // "ngừng kinh doanh" thay vì hạ xuống "tạm ẩn" — xem
                    // resolveProductStatus bên API.
                    is_active: document.getElementById('mActive').dataset.on === '1',
                    print_label: document.getElementById('mPrintLabel').dataset.on === '1',
                    is_stock_deducted: document.getElementById('mStockDeducted').dataset.on === '1',
                    is_serial: document.getElementById('mSerial').dataset.on === '1',
                };

                // Chi nhánh và thẻ: gửi kèm cờ *_loaded để máy chủ phân biệt "gỡ hết"
                // với "màn hình không dựng được ô ấy" — cùng quy ước với ảnh, biến thể
                // và quy đổi đơn vị.
                fields['shops_loaded'] = 1;
                let si = 0;
                dialog.querySelectorAll('[data-pick="shops"] input[type=checkbox]:checked').forEach((ô) => {
                    fields[`shop_ids[${si}]`] = ô.value;
                    si++;
                });
                fields['tags_loaded'] = 1;
                let ti = 0;
                dialog.querySelectorAll('[data-pick="tags"] input[type=checkbox]:checked').forEach((ô) => {
                    fields[`tags[${ti}]`] = ô.value;
                    ti++;
                });

                const barcodeChiTiet = (document.getElementById('mBarcode').value || '').trim();

                // Quy đổi đơn vị: chặn trùng đơn vị và trùng chính ĐVT của mặt hàng
                // ngay tại đây — để API từ chối thì người dùng mất nguyên lượt Lưu.
                const donViChinh = Number(document.getElementById('mUnit').value || 0);
                const daCoDonVi = new Set();
                let convErr = null;
                let ci = 0;
                fields['conversions_loaded'] = 1;
                [...document.querySelectorAll('#mConvRows .prd-conv-row')].forEach((row) => {
                    const uid = Number(row.querySelector('.prd-conv-unit').value || 0);
                    const qty = (row.querySelector('.prd-conv-qty').value || '').replace(',', '.').trim();
                    if (!uid && qty === '') return;   // dòng trống hoàn toàn
                    if (!uid || Number(qty) <= 0) {
                        convErr = convErr || 'Mỗi dòng quy đổi phải chọn đơn vị và nhập số lượng lớn hơn 0.';
                        return;
                    }
                    if (donViChinh > 0 && uid === donViChinh) {
                        convErr = convErr || 'Không khai quy đổi cho chính đơn vị tính của mặt hàng.';
                        return;
                    }
                    if (daCoDonVi.has(uid)) {
                        convErr = convErr || 'Mỗi đơn vị chỉ được khai quy đổi một lần.';
                        return;
                    }
                    daCoDonVi.add(uid);
                    fields[`unit_conversions[${ci}][unit_id]`] = uid;
                    fields[`unit_conversions[${ci}][quantity]`] = qty;
                    ci++;
                });
                if (convErr) { showFieldError(dialog, 'mConvRows', convErr); return; }

                // Gom biến thể theo TỔ HỢP thuộc tính. Không gửi tồn kho — cột đó chỉ
                // đổi qua nghiệp vụ kho.
                const seen = new Set();
                let vi = 0;
                let variantErr = null;
                [...document.querySelectorAll('#mVariants .prd-var-row')].forEach((row) => {
                    let attrs = [];
                    try { attrs = JSON.parse(row.dataset.attrs || '[]'); } catch (e) { attrs = []; }
                    const ten = row.querySelector('.prd-var-name').textContent.trim();
                    const skuBt = row.querySelector('.prd-var-sku').value.trim();
                    const barcode = row.querySelector('.prd-var-barcode').value.trim();
                    const price = digitsOnly(row.querySelector('.prd-var-price').value);
                    const cost = digitsOnly(row.querySelector('.prd-var-cost').value);

                    const key = attrKey(attrs);
                    if (seen.has(key)) {
                        variantErr = variantErr || `Tổ hợp bị trùng: ${ten || 'hàng đơn'}.`;
                        return;
                    }
                    seen.add(key);

                    fields[`variants[${vi}][id]`] = row.dataset.vid || '0';
                    // Tên chỉ gửi khi có tổ hợp; hàng đơn để trống cho máy chủ hiểu
                    // đây là dòng mặc định.
                    fields[`variants[${vi}][name]`] = attrs.length ? ten : '';
                    fields[`variants[${vi}][sku]`] = skuBt;             // '' = máy chủ tự ghép
                    // Hàng đơn: mã vạch lấy từ ô ở tab Chi tiết, không phải từ bảng
                    // (bảng đang bị giấu nên ô trong đó luôn rỗng).
                    fields[`variants[${vi}][barcode]`] = attrs.length ? barcode : barcodeChiTiet;
                    fields[`variants[${vi}][price]`] = price;           // '' nếu theo giá mặt hàng
                    fields[`variants[${vi}][cost_price]`] = cost;       // '' nếu theo giá vốn mặt hàng
                    attrs.forEach((a, ai) => {
                        fields[`variants[${vi}][attributes][${ai}][attribute_id]`] = a.attribute_id;
                        fields[`variants[${vi}][attributes][${ai}][value_id]`] = a.value_id;
                    });
                    vi++;
                });
                if (variantErr) { showFieldError(dialog, 'mVariants', variantErr); return; }
                // Không đọc được biến thể thì báo đúng lý do, đừng bảo người ta
                // "phải có ít nhất 1 dòng" trong khi mặt hàng vốn đang có đủ.
                if (vi === 0 && !variantsKnown) {
                    showFieldError(dialog, 'mVariants', 'Chưa đọc được danh sách biến thể của mặt hàng này. '
                        + 'Hãy tải lại trang rồi mở lại — biến thể đang có vẫn nguyên vẹn.');
                    return;
                }
                if (vi === 0) {
                    showFieldError(dialog, 'mVariants', 'Bảng biến thể đang trống. Bỏ hết tick ở khối Thuộc tính để quay về hàng đơn.');
                    return;
                }

                // Biến thể chỉ được gửi khi hộp thoại nắm được chúng.
                if (!variantsKnown) {
                    Object.keys(fields).forEach((k) => { if (k.startsWith('variants[')) delete fields[k]; });
                } else {
                    fields['variants_loaded'] = 1;
                }

                // KHÔNG gửi khoá `images`: mặt hàng chỉ có MỘT ảnh (ô Ảnh đại diện
                // ở trên, đi theo trường `thumbnail`) — đúng như bản cũ v2. Gửi mảng
                // rỗng ở đây nghĩa là "xoá sạch thư viện ảnh", mà hộp thoại này
                // không còn quản lý thư viện ảnh nữa nên nó không có quyền nói câu đó.

                if (dialog.dataset.mode === 'add') {
                    postForm(URL_STORE, 'POST', fields);
                } else {
                    postForm(`${URL_BASE}/${dialog.dataset.id}`, 'PUT', fields);
                }
            }

            // Đóng modal bằng phím Esc (modal nhỏ ưu tiên trước).
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                if ($modalMount.innerHTML) closeModal();
            });

            document.getElementById('prdAddBtn').addEventListener('click', () => openModal('add', null));

            // Dropdown "Nâng cao" và "Cột": mở/đóng, mở cái này thì đóng cái kia,
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
                // Đúng bộ cột tắt được của bản cũ v2: tám cột dữ liệu. Ô tick, STT
                // và cột Thao tác không tắt được — tắt đi thì bảng không còn nhận
                // ra dòng nào là dòng nào.
                const COLS = [
                    { key: 'sku', label: 'Mã hàng' },
                    { key: 'name', label: 'Tên hàng hóa' },
                    { key: 'cat', label: 'Nhóm hàng hóa' },
                    { key: 'vat', label: 'VAT' },
                    { key: 'unit', label: 'ĐVT' },
                    { key: 'price', label: 'Giá bán' },
                    { key: 'branch', label: 'Chi nhánh' },
                    { key: 'status', label: 'Trạng thái' },
                ];
                const TOTAL_COLS = 11; // gồm cả 3 cột không tắt được

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

                // Dòng "Tất cả" đứng đầu như v2: tick là hiện hết, bỏ tick là ẩn hết.
                const veDanhSach = () => {
                    const hetCa = hidden.length === 0;
                    list.innerHTML = `
                        <label class="prd-cols-item">
                            <input type="checkbox" data-all ${hetCa ? 'checked' : ''}>
                            <span><b>Tất cả</b></span>
                        </label>`
                        + COLS.map((c) => `
                            <label class="prd-cols-item">
                                <input type="checkbox" value="${c.key}" ${hidden.includes(c.key) ? '' : 'checked'}>
                                <span>${c.label}</span>
                            </label>`).join('');
                };
                veDanhSach();

                list.addEventListener('change', (e) => {
                    const cb = e.target.closest('input[type="checkbox"]');
                    if (!cb) return;
                    if (cb.hasAttribute('data-all')) {
                        hidden = cb.checked ? [] : COLS.map((c) => c.key);
                    } else {
                        hidden = cb.checked ? hidden.filter((k) => k !== cb.value) : hidden.concat(cb.value);
                    }
                    save(hidden);
                    veDanhSach();
                    apply(hidden);
                });

                if (resetBtn) {
                    resetBtn.addEventListener('click', () => {
                        hidden = [];
                        save(hidden);
                        veDanhSach();
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
