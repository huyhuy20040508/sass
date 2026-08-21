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
                <div class="tkc-toolbar-actions">
                    <a href="{{ route('admin.ton-kho-chi-nhanh.export', request()->query()) }}" class="tkc-btn-ghost">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                        Xuất file (CSV)
                    </a>
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

        {{-- Bảng --}}
        <div class="tkc-table-wrap">
            <table class="tkc-table">
                <thead>
                    <tr>
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
                                <td colspan="6">
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
                            <td colspan="10" class="tkc-empty">{{ $EMPTY_TEXT }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Hộp thoại sổ kho — mở từ nút ở cột Thao tác. Chỉ ĐỌC, và nói rõ ngay
             trên đầu là sổ của kho nào: mở từ một bảng nhiều kho mà không ghi tên
             kho thì đúng một phút sau người xem đã không còn chắc mình đang đọc sổ
             của đâu. --}}
        <div class="tkc-overlay" id="tkcSoKho" hidden>
            <div class="tkc-dialog" role="dialog" aria-modal="true" aria-labelledby="tkcSoTieuDe">
                <div class="tkc-dialog-head">
                    <div>
                        <h2 class="tkc-dialog-title" id="tkcSoTieuDe">Sổ kho</h2>
                        <p class="tkc-dialog-sub" id="tkcSoSub"></p>
                    </div>
                    <button type="button" class="tkc-dialog-x" data-tkc-dong aria-label="Đóng">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="tkc-dialog-body">
                    <div class="tkc-ledger-wrap">
                        <table class="tkc-ledger">
                            <thead>
                                <tr>
                                    <th class="tkc-l-time">Thời gian</th>
                                    <th class="tkc-l-what">Việc đã làm</th>
                                    <th class="tkc-l-delta">Thay đổi</th>
                                    <th class="tkc-l-after">Còn lại</th>
                                    <th class="tkc-l-ref">Chứng từ</th>
                                    <th class="tkc-l-who">Người làm</th>
                                </tr>
                            </thead>
                            <tbody id="tkcSoRows">
                                <tr><td colspan="6" class="tkc-ledger-empty">Đang tải…</td></tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Nạp thêm nối vào cuối bảng chứ không phân trang: sổ kho đọc theo
                         dòng thời gian, nhảy trang là mất mạch "trước đó còn bao nhiêu". --}}
                    <div class="tkc-more-wrap" id="tkcSoMoreWrap" hidden>
                        <button type="button" class="tkc-more" id="tkcSoMore">Xem thêm</button>
                        <span class="tkc-more-note" id="tkcSoNote"></span>
                    </div>
                </div>

                <div class="tkc-dialog-foot">
                    <button type="button" class="tkc-btn-close" data-tkc-dong>Đóng</button>
                </div>
            </div>
        </div>

        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'dòng tồn',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
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

        .tkc-table th.tkc-c-stt,     .tkc-table td.tkc-c-stt     { width: 4%; }
        .tkc-table th.tkc-c-sku,     .tkc-table td.tkc-c-sku     { width: 12%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-name,    .tkc-table td.tkc-c-name    { width: 20%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-variant, .tkc-table td.tkc-c-variant { width: 10%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-group,   .tkc-table td.tkc-c-group   { width: 11%; overflow: hidden; text-overflow: ellipsis; }
        .tkc-table th.tkc-c-unit,    .tkc-table td.tkc-c-unit    { width: 6%; }
        .tkc-table th.tkc-c-qty,     .tkc-table td.tkc-c-qty     { width: 9%; font-variant-numeric: tabular-nums; }
        .tkc-table th.tkc-c-state,   .tkc-table td.tkc-c-state   { width: 9%; }
        .tkc-table th.tkc-c-value,   .tkc-table td.tkc-c-value   { width: 14%; font-variant-numeric: tabular-nums; }
        .tkc-table th.tkc-c-act,     .tkc-table td.tkc-c-act     { width: 5%; }

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

            // ---------- Hộp thoại sổ kho ----------
            const URL_SO_KHO = (id) => `{{ url('admin/inventory/branches') }}/${id}/history`;
            const TX_TYPES = @json(\App\Http\Controllers\InventoryController::TX_TYPES);
            const nf = new Intl.NumberFormat('vi-VN');
            const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            const hop = document.getElementById('tkcSoKho');
            const soRows = document.getElementById('tkcSoRows');
            const soMoreWrap = document.getElementById('tkcSoMoreWrap');
            const soMore = document.getElementById('tkcSoMore');
            const soNote = document.getElementById('tkcSoNote');

            // token: mỗi lần mở là một lượt xem. Người dùng đóng rồi mở dòng khác
            // trong lúc lượt cũ còn đang tải thì phản hồi đến muộn phải bị bỏ, nếu
            // không sổ của mặt hàng trước sẽ đắp vào hộp thoại đang mở.
            const so = { token: 0, id: 0, shop: 0, page: 1, hetTrang: true };

            function gioPhut(raw) {
                if (!raw) return '—';
                const d = new Date(raw);
                if (isNaN(d)) return '—';
                const p = (n) => String(n).padStart(2, '0');
                return `${p(d.getDate())}/${p(d.getMonth() + 1)}/${d.getFullYear()} ${p(d.getHours())}:${p(d.getMinutes())}`;
            }

            function chungTu(h) {
                if (h.reference_code) return esc(h.reference_code);
                return '<span class="tkc-muted">—</span>';
            }

            function dongSo(rows) {
                return rows.map((h) => {
                    const q = Number(h.quantity) || 0;
                    const dau = q > 0 ? '+' : (q < 0 ? '−' : '');
                    return `<tr>
                        <td class="tkc-l-time">${gioPhut(h.created_at)}</td>
                        <td class="tkc-l-what">
                            ${esc(TX_TYPES[h.type] || h.type)}
                            ${h.note ? `<span class="tkc-l-note">${esc(h.note)}</span>` : ''}
                        </td>
                        <td class="tkc-l-delta ${q >= 0 ? 'is-up' : 'is-down'}">${dau}${nf.format(Math.abs(q))}</td>
                        <td class="tkc-l-after">${nf.format(h.quantity_after)}</td>
                        <td class="tkc-l-ref">${chungTu(h)}</td>
                        <td class="tkc-l-who">${h.created_by_name ? esc(h.created_by_name) : '—'}</td>
                    </tr>`;
                }).join('');
            }

            function napSo(noiTiep) {
                const token = so.token;
                const trang = noiTiep ? so.page + 1 : 1;

                fetch(`${URL_SO_KHO(so.id)}?shop_id=${so.shop}&page=${trang}`, {
                    headers: { 'Accept': 'application/json' },
                })
                    .then((r) => (r.ok ? r.json() : Promise.reject(r)))
                    .then((res) => {
                        if (so.token !== token) return;
                        const rows = res.data || [];
                        const meta = res.meta || {};
                        so.page = trang;
                        so.hetTrang = trang >= (meta.total_pages || 1);

                        if (!noiTiep && !rows.length) {
                            soRows.innerHTML = '<tr><td colspan="6" class="tkc-ledger-empty">'
                                + 'Kho này chưa từng có hàng ra vào với mặt hàng đó.</td></tr>';
                            soMoreWrap.hidden = true;
                            return;
                        }

                        if (noiTiep) {
                            soRows.insertAdjacentHTML('beforeend', dongSo(rows));
                        } else {
                            soRows.innerHTML = dongSo(rows);
                        }

                        soMoreWrap.hidden = so.hetTrang;
                        const tong = meta.total || rows.length;
                        soNote.textContent = so.hetTrang ? '' : `Đang xem ${soRows.children.length}/${nf.format(tong)} bút toán`;
                    })
                    .catch(() => {
                        if (so.token !== token) return;
                        soMoreWrap.hidden = true;
                        soRows.innerHTML = '<tr><td colspan="6" class="tkc-ledger-empty">'
                            + 'Không tải được sổ kho. Vui lòng thử lại.</td></tr>';
                    });
            }

            function moSo(btn) {
                so.token++;
                so.id = Number(btn.dataset.soKho);
                so.shop = Number(btn.dataset.shop);
                so.page = 1;

                const bienThe = btn.dataset.variant ? ` · ${btn.dataset.variant}` : '';
                document.getElementById('tkcSoTieuDe').textContent = btn.dataset.name || 'Sổ kho';
                document.getElementById('tkcSoSub').textContent =
                    `${btn.dataset.sku}${bienThe} — kho ${btn.dataset.shopName}, đang còn ${nf.format(Number(btn.dataset.qty) || 0)}`;

                soRows.innerHTML = '<tr><td colspan="6" class="tkc-ledger-empty">Đang tải…</td></tr>';
                soMoreWrap.hidden = true;
                soNote.textContent = '';
                hop.hidden = false;
                napSo(false);
            }

            function dongSoKho() {
                so.token++;
                hop.hidden = true;
            }

            document.querySelectorAll('[data-so-kho]').forEach((btn) => {
                btn.addEventListener('click', () => moSo(btn));
            });
            document.querySelectorAll('[data-tkc-dong]').forEach((btn) => {
                btn.addEventListener('click', dongSoKho);
            });
            // Bấm ra nền hoặc bấm Esc đều đóng — hộp thoại này chỉ để đọc, không có
            // gì để mất khi đóng nhầm.
            hop.addEventListener('click', (e) => { if (e.target === hop) dongSoKho(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !hop.hidden) dongSoKho(); });
            soMore.addEventListener('click', () => napSo(true));

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
    </script>
@endsection
