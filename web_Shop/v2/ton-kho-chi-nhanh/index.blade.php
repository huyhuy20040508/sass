{{-- Màn Tồn kho chi nhánh hiện tại dựng theo khuôn v2 (warehouse: index + list).

     Chép từ bản v2 cũ `resources/views/warehouse/index.blade.php` +
     `list.blade.php`: khung lọc bên trái, khối `content_midd` bên phải, bảng
     `table-list-warehouse` kẻ so le bg1/bg2, bản thẻ cho điện thoại và hộp Chi
     tiết trượt từ phải sang.

     Dữ liệu do TonKhoChiNhanhController đẩy sang: $rows, $groups, $filters,
     $meta, $chiNhanh, $categories. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\TonKhoChiNhanhController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\TonKhoChiNhanhController::class;
    $low = (int) $filters['low_stock'];

    // Tổng của TỪNG chi nhánh tính trên toàn bộ lọc, không phải trên trang đang
    // xem: đếm theo trang thì con số ở đầu nhóm tụt xuống khi lật trang và người
    // đọc hiểu thành kho vừa mất hàng.
    $tomTat = collect($groups)->keyBy('shop_id');

    // Bảng đi theo chi nhánh đang làm việc, nên bình thường chỉ có MỘT kho và
    // không cần nhắc lại tên kho ở từng dòng. Chỉ khi người dùng đang xem GỘP
    // (thanh trên cùng không chọn kho nào) thì bảng mới có nhiều kho — lúc đó
    // thiếu tiêu đề nhóm là cùng một mã hàng hiện mấy lần không rõ vì sao.
    $tenKho = \App\Services\ChiNhanhDangLam::ten();
    $nhieuKho = collect($rows)->pluck('shop_id')->unique()->count() > 1;

    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
@endphp

@push('styles')
    <style>
        /* ===== Chép nguyên khối style của warehouse/index bản v2 ===== */
        .table-list-warehouse td { align-content: center; }

        /* Bảng KÉO NGANG như bản v2 cũ: không khai bề rộng cột, không ép chữ
           xuống dòng. Cột tự co theo nội dung, dài quá thì cuộn ngang trong
           .table-responsive — y hệt warehouse/list.blade.php bên v2.

           (Có một đợt thử ép vừa khít khung bằng table-layout: fixed cộng cho
           chữ xuống dòng. Bỏ: tên hàng gãy làm hai ba dòng khiến bảng cao vống
           lên, đọc mệt hơn hẳn so với kéo ngang.) */

        /* Ô gộp theo lô: chữ neo lên đỉnh chứ không giữa. Mặt hàng có bốn lô thì
           ô tên hàng cao gấp bốn, để giữa là tên trôi xuống lưng chừng, không
           còn ngang hàng với dòng lô đầu tiên của chính nó. */
        .table-list-warehouse.none_mobile td.merge { vertical-align: top; }

        /* Nút Xem thêm / Thu gọn — chép khối style của bản v2 đang chạy. Bề rộng
           ghim 115px là cố ý: chữ đổi giữa "Xem thêm (3)" và "Thu gọn" nên để tự
           co thì cả cột Hành động nhảy ngang mỗi lần bấm. */
        .btn-expand-batch {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 2px 8px;
            font-size: 12px;
            transition: all 0.3s ease;
            width: 115px;
        }
        .btn-expand-batch:hover { background: #e9ecef; transform: scale(1.05); }
        .batch-expanded .btn-expand-batch { background: #e9ecef; width: 115px; }
        .bg1 td, .bg1 th { background-color: #f2f2f2; }
        .bg2 td { background-color: #ffffff; }
        li { list-style-type: none; }

        /* Dòng đầu mỗi nhóm chi nhánh. Bản v2 không có vì màn đó chỉ nhìn một
           kho; bên mình một mặt hàng đứng ở nhiều dòng (mỗi kho một dòng) nên
           thiếu tiêu đề nhóm là bảng đọc thành hàng lặp lại vô cớ. */
        tr.group-branch td {
            background-color: #d3daeb !important;
            font-weight: 600;
            text-align: left !important;
        }
        tr.group-branch .group-code { color: #6b7280; font-weight: 400; margin-left: 8px; }

        .qty-in { color: #198754; }
        .qty-low { color: #d97706; }
        .qty-out { color: #dc3545; }
        .muted { color: #9ca3af; }
    </style>
@endpush

@section('content')
    {{-- Nút mở bộ lọc dạng offcanvas, chỉ hiện trên điện thoại — đúng khuôn v2. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterSearch">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterProductCatalog">
                <p class="open-modal-label">{{ __('message.product_category') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-layer-group"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-warehouse-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <form action="{{ route('admin.ton-kho-chi-nhanh.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterSearch" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.search') }}</span>
                                    <div class="d-flex input-group mt-2" style="width: 100%;">
                                        <input style="flex: 1;" type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                            class="form-control ip_search search_menu" autocomplete="off"
                                            placeholder="{{ __('message.menu-code') }}, {{ __('message.menu-name') }}">
                                        <button class="btn btn-search-menu" type="submit">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {{-- Khung lọc CHỈ có hai ô — tìm kiếm và danh mục — đúng bằng bản
                                 v2 đang chạy.

                                 Không có ô chi nhánh: kho đang xem do thanh trên cùng quyết
                                 định, bày thêm một ô nữa là hỏi lại đúng câu người dùng vừa trả
                                 lời, và hai chỗ lệch nhau thì không biết con số của kho nào.

                                 Không có ô mức tồn / sắp xếp: bản gốc không có. Controller vẫn
                                 hiểu `stock` và `sort` trên URL (xem filters()), chỉ là không
                                 bày ra ô nào — ai cần thì gõ thẳng địa chỉ. --}}

                            <div id="filterProductCatalog" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.product_category') }}</span>
                                    <select class="form-control form-select mt-2" name="category_id">
                                        <option value="0">{{ __('message.all') }}</option>
                                        @foreach ($categories as $dm)
                                            <option value="{{ $dm['id'] }}"
                                                {{ (int) $filters['category_id'] === (int) $dm['id'] ? 'selected' : '' }}>
                                                {{ $dm['name'] ?? '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <input type="hidden" name="page_size" value="{{ $filters['page_size'] }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 mt-md-2 mt-lg-0 wrapper-content-dashboard-middle">
            <div class="content_midd">
                <div class="content_midd_title">
                    {{-- Phải NÓI RA đang xem kho nào: bỏ ô lọc chi nhánh đi rồi thì con số
                         trên bảng chỉ còn một chỗ giải thích được nó thuộc về đâu. --}}
                    <div class="d-flex flex-column">
                        <h1 class="tieu-de-trang">{{ __('message.product-list') }}</h1>
                        <small class="muted">
                            {{ $tenKho !== null ? 'Kho: ' . $tenKho : 'Đang xem gộp mọi chi nhánh' }}
                            @if (!$nhieuKho && ($g0 = collect($groups)->first()))
                                · {{ $so($g0['so_dong'] ?? 0) }} dòng
                                · tồn {{ $so($g0['tong_ton'] ?? 0) }}
                                · giá trị {{ $so($g0['gia_tri'] ?? 0) }}₫
                            @endif
                        </small>
                    </div>

                    <div class="button-group d-flex my-auto">
                        <a class="btn btn-sm d-flex align-items-center btn-export"
                            href="{{ route('admin.ton-kho-chi-nhanh.export', request()->query()) }}">
                            <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export-excel') }}
                        </a>
                    </div>
                </div>

                @if (!empty($error))
                    <div class="alert alert-warning py-2 my-2">{{ $error }}</div>
                @endif

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-list-warehouse none_mobile">
                            {{-- Bộ cột, THỨ TỰ và cách gộp lô lấy đúng của bản v2 ĐANG CHẠY
                                 (table1.klkim.com/v2/warehouse): không có cột phân loại, không
                                 khai bề rộng cột, và ba cột lô gập lại sau nút "Xem thêm (N)" ở
                                 cột Hành động.

                                 Giá trị vốn tồn kho KHÔNG có ở đây vì bản gốc không có — con số
                                 đó nằm ở dòng dưới tiêu đề trang và trong tệp xuất. --}}
                            <thead>
                                <tr>
                                    <th data-column="stt" class="text-center">{{ __('message.stt') }}</th>
                                    <th data-column="menu-code" class="text-left show_menu_code">{{ __('message.menu-code') }}</th>
                                    <th data-column="menu-name" class="text-left show_menu_name">{{ __('message.menu-name') }}</th>
                                    <th data-column="quantity" class="text-right show_quantity">{{ __('message.total-quantity') }}</th>
                                    <th data-column="menu-unit" class="text-center show_menu_unit">{{ __('message.menu-unit') }}</th>
                                    <th data-column="menu-type" class="text-left show_menu_type">{{ __('message.menu-type') }}</th>
                                    <th data-column="menu-group" class="text-left show_menu_group">{{ __('message.menu-group') }}</th>
                                    <th data-column="lot" class="text-right show_lot">{{ __('message.batch_number') }}</th>
                                    <th data-column="lot-qty" class="text-right show_lot_qty">{{ __('message.quantity') }}</th>
                                    <th data-column="expire" class="text-right show_expire">{{ __('message.expiration_date') }}</th>
                                    <th data-column="action" class="text-center">{{ __('message.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $cnHienTai = null;
                                    $index = 1;
                                    // Một kho: STT chạy tiếp qua các trang, như bản v2. Xem gộp:
                                    // đánh lại từ 1 ở mỗi kho vì con số thuộc về nhóm, không phải
                                    // về trang.
                                    $stt = $nhieuKho ? 0 : ($meta['page'] - 1) * $meta['page_size'];
                                @endphp
                                @forelse ($rows as $r)
                                    @php
                                        $shopId = (int) ($r['shop_id'] ?? 0);
                                        $qty = (int) ($r['quantity'] ?? 0);
                                        $muc = $C::mucTon($qty, $low);

                                        // Gộp lô đúng như bản v2 đang chạy: lô ĐẦU nằm ngay trên dòng
                                        // chính, các lô sau là những dòng ẩn, bấm "Xem thêm (N)" mới
                                        // bung ra. Bảy ô đầu để rowspan=1 lúc gập và JS nâng lên
                                        // $soLo khi mở — xem khối script cuối trang.
                                        $lo = $r['lots'] ?? [];
                                        $soLo = count($lo);
                                        $maDong = (int) ($r['variant_id'] ?? 0);
                                        $keDong = $index % 2 == 0 ? 'bg2' : 'bg1';
                                    @endphp

                                    {{-- Chỉ khi đang xem GỘP nhiều kho mới cần tiêu đề nhóm. Dòng của
                                         một chi nhánh luôn nằm liền nhau vì API sắp theo shop_id trước
                                         mọi kiểu sắp xếp khác. --}}
                                    @if ($nhieuKho && $cnHienTai !== $shopId)
                                        @php
                                            $cnHienTai = $shopId;
                                            $g = $tomTat->get($shopId, []);
                                            $stt = 0;
                                            $index++;
                                        @endphp
                                        <tr class="group-branch" data-cn="{{ $shopId }}">
                                            <td colspan="3">
                                                {{ $r['shop_name'] ?: 'Chi nhánh #' . $shopId }}
                                                ({{ $so($g['so_dong'] ?? 0) }})
                                                @if (!empty($r['shop_code']))
                                                    <span class="group-code">{{ $r['shop_code'] }}</span>
                                                @endif
                                            </td>
                                            <td class="text-right">{{ $so($g['tong_ton'] ?? 0) }}</td>
                                            <td colspan="7"></td>
                                        </tr>
                                    @endif

                                    @php $stt++; $index++; @endphp
                                    <tr class="item {{ $keDong }}" data-cn="{{ $shopId }}"
                                        data-id="{{ $maDong }}" data-item-id="{{ $maDong }}">
                                        <td rowspan="1" class="merge rowspan-{{ $maDong }} text-center">{{ $stt }}</td>
                                        <td rowspan="1" class="merge rowspan-{{ $maDong }} text-left show_menu_code">{{ $r['sku'] ?? '' }}</td>
                                        <td rowspan="1" class="merge rowspan-{{ $maDong }} text-left show_menu_name">
                                            {{ $r['product_name'] ?? '' }}
                                            @if (empty($r['is_active']))
                                                <small class="muted">(Ngừng bán)</small>
                                            @endif
                                        </td>
                                        <td rowspan="1" class="merge rowspan-{{ $maDong }} text-right show_quantity">
                                            <b class="qty-{{ $muc }}">{{ $so($qty) }}</b>
                                        </td>
                                        <td rowspan="1" class="merge rowspan-{{ $maDong }} text-center show_menu_unit">
                                            {{ ($r['unit_name'] ?? '') !== '' ? $r['unit_name'] : '-' }}
                                        </td>
                                        {{-- Loại hàng hoá: bản v2 chia năm loại theo nhóm cha
                                             (thành phẩm / nguyên phụ liệu / bán thành phẩm / hàng
                                             mẫu / không kiểm kê) — bên bán lẻ không có bậc nhóm cha
                                             đó. Thứ mình có mà đúng nghĩa với cột này là cờ trừ kho:
                                             tắt thì bán bao nhiêu dòng tồn cũng đứng yên. --}}
                                        <td rowspan="1" class="merge rowspan-{{ $maDong }} text-left show_menu_type">
                                            {{ empty($r['is_stock_deducted']) ? __('message.non_inventory_goods') : __('message.goods_sold') }}
                                        </td>
                                        <td rowspan="1" class="merge rowspan-{{ $maDong }} text-left show_menu_group">
                                            {{ ($r['category_name'] ?? '') !== '' ? $r['category_name'] : '-' }}
                                        </td>

                                        @if ($lo)
                                            @include('v2::ton-kho-chi-nhanh._o-lo', ['l' => $lo[0]])
                                        @else
                                            <td colspan="3"></td>
                                        @endif

                                        <td class="text-center">
                                            @if ($soLo > 1)
                                                <button type="button" class="btn btn-sm btn-expand-batch"
                                                    data-item-id="{{ $maDong }}" data-expanded="false"
                                                    data-total-rows="{{ $soLo }}">
                                                    <i class="fa-solid fa-chevron-down"></i>
                                                    {{-- Chữ giấu trên điện thoại, chỉ còn mũi tên: cột Hành
                                                         động ở đó hẹp, đúng như bản gốc. --}}
                                                    <span class="d-none d-md-inline">Xem thêm ({{ $soLo - 1 }})</span>
                                                </button>
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>

                                    {{-- Lô thứ hai trở đi: ẩn sẵn, chỉ có ba ô lô cộng một ô Hành động
                                         rỗng. Bảy cột đầu do rowspan của dòng trên phủ xuống — mà lúc
                                         gập rowspan đang là 1, nên mấy dòng này phải ẩn, nếu không
                                         chúng lệch hẳn sang trái. --}}
                                    @foreach (array_slice($lo, 1) as $l)
                                        <tr class="item batch-{{ $maDong }} {{ $keDong }}" style="display: none"
                                            data-cn="{{ $shopId }}" data-item-id="{{ $maDong }}" data-batch-row="true">
                                            @include('v2::ton-kho-chi-nhanh._o-lo', ['l' => $l])
                                            <td class="text-center"></td>
                                        </tr>
                                    @endforeach
                                @empty
                                    <tr>
                                        <td colspan="11" class="text-center py-4">{{ $C::EMPTY_TEXT }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai — đúng như v2. --}}
                        <div class="table-list-warehouse none_desktop">
                            <div class="item">
                                <div class="fw-bold">{{ __('message.menu-name') }}</div>
                                <div class="fw-bold">{{ __('message.total-quantity') }}</div>
                            </div>
                            @php $cnMobile = null; @endphp
                            @foreach ($rows as $r)
                                @php $shopId = (int) ($r['shop_id'] ?? 0); @endphp
                                @if ($nhieuKho && $cnMobile !== $shopId)
                                    @php $cnMobile = $shopId; @endphp
                                    <div class="item fw-bold" style="background-color: #d3daeb">
                                        {{ $r['shop_name'] ?: 'Chi nhánh #' . $shopId }}
                                    </div>
                                @endif
                                <div class="item" data-id="{{ $r['variant_id'] ?? 0 }}"
                                    data-code="{{ $r['sku'] ?? '' }}"
                                    data-name="{{ $r['product_name'] ?? '' }}"
                                    data-quantity="{{ $so($r['quantity'] ?? 0) }}"
                                    data-unit="{{ $r['unit_name'] ?? '' }}"
                                    data-group="{{ $r['category_name'] ?? '' }}"
                                    data-type="{{ empty($r['is_stock_deducted']) ? __('message.non_inventory_goods') : __('message.goods_sold') }}"
                                    data-branch="{{ $r['shop_name'] ?? '' }}"
                                    data-lots="{{ json_encode($r['lots'] ?? [], JSON_UNESCAPED_UNICODE) }}">
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold">{{ $r['product_name'] ?? '' }}</span>
                                        <span style="font-size: 14px">{{ $r['sku'] ?? '' }}</span>
                                    </div>
                                    <div class="d-flex text-right show_quantity gap-2">
                                        {{ $so($r['quantity'] ?? 0) }}
                                        <i class="fa-solid fa-angle-right"></i>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Phân trang dựng đúng khuôn bootstrap-4 mà bản v2 in ra. --}}
                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width" data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ (int) $filters['page_size'] === $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Hộp Chi tiết trượt từ phải — chỉ dùng trên điện thoại, đúng như v2. --}}
    <div class="offcanvas offcanvas-end offcanvas-custom" tabindex="-1" id="offcanvasDetail"
        aria-labelledby="offcanvasDetailLabel">
        <div class="offcanvas-header">
            <a type="button" aria-label="Đóng" class="btn-back" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-arrow-left" style="font-size: 20px;"></i>
            </a>
            <div class="d-flex" style="flex: 1;">
                <h5 class="offcanvas-title" id="offcanvasDetailLabel">{{ __('message.detail') }}</h5>
            </div>
        </div>
        <div class="offcanvas-body" style="height: calc(100vh - 58px); padding: 0px;">
            <div class="item-common">
                <span class="name"></span>
                <span class="code"></span>
                <span class="quantity"></span>
            </div>
            <div class="item-quantity">
                <h5>{{ __('message.general_information') }}</h5>
                @if ($nhieuKho)
                    <div class="d-flex align-items-center justify-content-between" style="border-bottom: 1px solid #ddd; padding: 4px;">
                        <span class="show_menu">{{ __('message.branch') }}</span>
                        <span class="menu-branch"></span>
                    </div>
                @endif
                <div class="d-flex align-items-center justify-content-between" style="border-bottom: 1px solid #ddd; padding: 4px;">
                    <span class="show_menu">{{ __('message.menu-unit') }}</span>
                    <span class="menu-unit"></span>
                </div>
                <div class="d-flex align-items-center justify-content-between" style="border-bottom: 1px solid #ddd; padding: 4px;">
                    <span class="show_menu">{{ __('message.menu-type') }}</span>
                    <span class="menu-type"></span>
                </div>
                <div class="d-flex align-items-center justify-content-between" style="border-bottom: 1px solid #ddd; padding: 4px;">
                    <span class="show_menu">{{ __('message.menu-group') }}</span>
                    <span class="menu-group"></span>
                </div>
            </div>

            {{-- Khối "Thông tin lô" — chép đúng khuôn item-materials của bản v2.
                 Trên điện thoại bảng chỉ còn hai cột (tên hàng + tổng), nên ba cột
                 lô phải có chỗ ở đây, không thì người dùng điện thoại mất hẳn. --}}
            <div class="item-materials">
                <h5>{{ __('message.batch_info') }}</h5>
                <div class="mt-3">
                    <table>
                        <thead>
                            <th>{{ __('message.batch_number') }}</th>
                            <th>{{ __('message.quantity') }}</th>
                            <th class="text-right">{{ __('message.expiration_date') }}</th>
                        </thead>
                        <tbody id="menu-materials"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // ---------- Bộ lọc: đổi ô nào là lọc lại ngay, gõ thì chờ 400ms ----------
        const $form = $('#search-form');
        $form.on('change', 'select', () => $form.trigger('submit'));

        let timerTim = null;
        $form.on('input', '.search_menu', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(() => $form.trigger('submit'), 400);
        });

        // ---------- Gập / mở các dòng lô ----------
        //
        // Chép nguyên cách làm của bản v2 đang chạy: lúc gập, bảy ô đầu để
        // rowspan=1 và các dòng lô sau bị ẩn; lúc mở thì nâng rowspan lên đúng số
        // lô rồi hiện chúng ra. Nâng rowspan là bắt buộc — để nguyên 1 thì mấy
        // dòng vừa hiện không có gì phủ bảy cột đầu và chúng dạt hết sang trái.
        //
        // Gắn bằng uỷ quyền trên document chứ không phải bind thẳng như bản gốc:
        // khung v2 thay ruột bảng bằng ajax khi lật trang (V2.napLai), bind thẳng
        // thì sang trang 2 là nút chết.
        $(document).on('click', '.btn-expand-batch', function () {
            const $nut = $(this);
            const ma = $nut.data('item-id');
            const soLo = $nut.data('total-rows');
            const dangMo = $nut.data('expanded');
            const $icon = $nut.find('i');
            const $dongDau = $nut.closest('tr');

            if (dangMo) {
                $('.batch-' + ma).hide();
                $('.rowspan-' + ma).attr('rowspan', 1);
                $nut.data('expanded', false);
                $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                $nut.find('span').text('Xem thêm (' + (soLo - 1) + ')');
                $dongDau.removeClass('batch-expanded');
            } else {
                $('.batch-' + ma).show();
                $('.rowspan-' + ma).attr('rowspan', soLo);
                $nut.data('expanded', true);
                $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                $nut.find('span').text('Thu gọn');
                $dongDau.addClass('batch-expanded');
            }
        });

        // Bản thẻ trên điện thoại: bấm một dòng thì mở hộp Chi tiết.
        $(document).on('click', '.table-list-warehouse.none_desktop .item[data-id]', function () {
            const $it = $(this);
            const $box = $('#offcanvasDetail');

            $box.find('.item-common .name').text($it.data('name') || '');
            $box.find('.item-common .code').text($it.data('code') || '');
            $box.find('.item-common .quantity').text($it.data('quantity') || '');

            $box.find('.menu-branch').text($it.data('branch') || '-');
            $box.find('.menu-unit').text($it.data('unit') || '-');
            $box.find('.menu-type').text($it.data('type') || '-');
            $box.find('.menu-group').text($it.data('group') || '-');

            // Danh sách lô: cùng thứ tự và cùng luật màu hạn dùng với bảng.
            const $lo = $('#menu-materials').empty();
            const ds = $it.data('lots') || [];
            if (!ds.length) {
                $lo.append('<tr><td colspan="3" class="text-center muted">Chưa có lô nào</td></tr>');
            }
            ds.forEach(function (l) {
                const han = l.expire_date ? new Date(l.expire_date) : null;
                let mau = '';
                if (han) {
                    const homNay = new Date(); homNay.setHours(0, 0, 0, 0);
                    const ngay = new Date(han.getFullYear(), han.getMonth(), han.getDate());
                    if (ngay <= homNay) mau = 'qty-out';
                    else if (ngay <= new Date(homNay.getTime() + 7 * 86400000)) mau = 'qty-low';
                }
                $lo.append($('<tr>').append(
                    $('<td>').text(l.lot_number || '{{ __('message.unknown') }}'),
                    $('<td>').text(Number(l.quantity || 0).toLocaleString('vi-VN')),
                    $('<td>').addClass('text-right ' + mau).text(han ? han.toLocaleDateString('vi-VN') : '-'),
                ));
            });

            new bootstrap.Offcanvas(document.getElementById('offcanvasDetail')).show();
        });
    </script>
@endpush
