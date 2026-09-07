{{-- Màn Khách hàng dựng theo khuôn v2 cũ (3rd/customer/index + list + list-history
     + list-debt + list-point-usage-history).
     Dữ liệu do CustomerController đẩy sang: $list, $filters, $meta, $ranks. --}}
@extends('v2::layouts.master')

@section('title', 'Khách hàng')

@push('styles')
    <style>
        .btn_top_content > * { margin-left: 8px; }
        li { list-style-type: none; }
        @media (max-width: 991px) { .dropup .dropbtn { display: none !important; } }
        @media screen and (min-width: 426px) and (max-width: 991px) {
            .modal .modal-body .nav-detail { grid-template-columns: repeat(4, 1fr); }
        }

        /* ===== KHUNG LỌC =====
           Lấy nguyên bộ số đã chốt ở màn Thu chi, để mọi khung lọc trong khu v2
           cùng một nhịp: khối cách nhau 13px, nhãn cách ô 5px, thân card đệm
           12px trên dưới.

           Chỉ từ 992px trở lên — dưới mức đó vỏ v2 giấu hẳn cột này
           (responsive-manager.css) và bưng từng ô sang tấm offcanvas, nơi rộng
           rãi nên không cần nén. */
        @media (min-width: 992px) {
            .index-customer-page .fillter-box .card-body { padding-top: 12px; padding-bottom: 12px; }
            .index-customer-page .fillter-box .card-body div[id^="filter"] { margin-bottom: 13px !important; }
            .index-customer-page .fillter-box .title_search { display: block; margin-bottom: 5px; }
            .index-customer-page .fillter-box .mt-1 { margin-top: 0 !important; }
            /* Hai ô tick: bỏ chiều cao tối thiểu của Bootstrap (nó chừa chỗ cho
               cỡ chữ lớn hơn cỡ đang dùng), nhưng vẫn để mỗi hàng cách nhau một
               nhịp — hai hàng tick dính liền là đọc nhầm dòng. */
            .index-customer-page .fillter-box .form-check { min-height: 0; margin-bottom: 4px; }
            .index-customer-page .fillter-box .form-check-label { line-height: 1.5; }
        }

        /* Bảng danh sách: 11 cột CHIA THEO %, cộng đúng 100 — vừa khít khung, không
           cuộn ngang, cột Hành động không rơi ra ngoài màn. Tắt bớt cột thì phần %
           hụt được trình duyệt chia lại cho cột còn lại. */
        .list .table-responsive { overflow-x: auto; }
        table.table-customer.none_mobile { width: 100%; table-layout: fixed; }
        /* HÀNG TIÊU ĐỀ — cao 32px: chữ 13,5px đậm, dòng cao 20px, đệm dọc 6px.
           Nới thêm một nhịp so với bản 28px theo ý chủ tiệm; chữ to hơn nửa điểm
           nên nhìn rõ hơn mà hàng vẫn chưa dày.
           Nhãn KHÔNG xuống dòng — bề rộng cột bên dưới đã cấp đủ cho nhãn dài
           nhất; để nó gãy dòng là hàng tiêu đề cao gấp đôi ngay. */
        table.table-customer.none_mobile th {
            white-space: nowrap; font-size: 13.5px; font-weight: 600; line-height: 20px;
            padding: 6px 10px; vertical-align: middle;
        }
        /* KHÔNG cắt chữ: chữ dài thì XUỐNG DÒNG, ô cao lên chứ không nuốt mất chữ.
           Riêng ô tiền và số điện thoại để `nowrap` — số gãy giữa chừng khó đọc hơn. */
        table.table-customer.none_mobile td {
            white-space: normal; word-break: break-word; vertical-align: middle;
            padding: 8px;
        }
        table.table-customer.none_mobile td.action a { padding: 0 2px; }
        table.table-customer.none_mobile td.la-tien { white-space: nowrap; }
        /* Đo ở khung ~1182px (màn 1536): mọi tiêu đề nằm gọn MỘT DÒNG.
           ☐ 3 · STT 3.5 · Mã 9.5 · Tên 13 · Loại 9 · Nhóm 12 · Tổng mua 10.5
           · Tổng đã thanh toán 11.5 · Còn nợ 8 · SĐT 11 · Hành động 9 = 100 */
        table.table-customer.none_mobile th:first-child { width: 3%; }
        table.table-customer.none_mobile th:nth-child(2) { width: 3.5%; }
        table.table-customer.none_mobile th.show_code { width: 9.5%; }
        table.table-customer.none_mobile th.show_name { width: 13%; }
        table.table-customer.none_mobile th.show_type { width: 9%; }
        table.table-customer.none_mobile th.show_customer_group { width: 12%; }
        table.table-customer.none_mobile th.show_total_purchases { width: 10.5%; }
        table.table-customer.none_mobile th.show_total_paid { width: 11.5%; }
        table.table-customer.none_mobile th.show_still_in_debt { width: 8%; }
        table.table-customer.none_mobile th.show_phone { width: 11%; }
        table.table-customer.none_mobile th:last-child { width: 9%; }

        /* Ba bảng trong hộp Chi tiết — cùng khuôn với hộp chi tiết nhà cung cấp. */
        .kh-tab-wrap { overflow-x: auto; }
        .kh-tab-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 12.5px; }
        /* Cũng không cắt chữ: ô dài thì xuống dòng. */
        .kh-tab-table th, .kh-tab-table td {
            border: 1px solid #eee; padding: 6px 4px; text-align: center;
            white-space: normal; word-break: break-word;
        }
        .kh-tab-table th { background: #c4c9d7; font-weight: 600; }
        .kh-tab-table td.is-tien { text-align: right; }
        /* Lịch sử giao dịch: STT 4 · Mã 14 · Loại đơn 9 · Tổng tiền 10 · Người tạo 9
           · Ngày 8.5 · Chi nhánh 10 · Trạng thái TT 10 · Nợ 5 · Lý do trả 10 · Ghi chú 10.5 = 100 */
        .kh-tab-lich-su th:nth-child(1) { width: 4%; }   .kh-tab-lich-su th:nth-child(2) { width: 14%; }
        .kh-tab-lich-su th:nth-child(3) { width: 9%; }   .kh-tab-lich-su th:nth-child(4) { width: 10%; }
        .kh-tab-lich-su th:nth-child(5) { width: 9%; }   .kh-tab-lich-su th:nth-child(6) { width: 8.5%; }
        .kh-tab-lich-su th:nth-child(7) { width: 10%; }  .kh-tab-lich-su th:nth-child(8) { width: 10%; }
        .kh-tab-lich-su th:nth-child(9) { width: 5%; }   .kh-tab-lich-su th:nth-child(10) { width: 10%; }
        .kh-tab-lich-su th:nth-child(11) { width: 10.5%; }
        .kh-tab-table td.qua-han { color: #ff4d4f; font-weight: 600; }
        .kh-tab-table td.het-han-hom-nay { color: #fa8c16; font-weight: 600; }
        .kh-tab-chan { display: flex; align-items: center; gap: 12px; margin-top: 10px; }
        .kh-tab-chan .select-width { width: auto; }
        .kh-tab-pagi { flex: 1; }
        .kh-tab-pagi nav { display: flex; justify-content: center; }
        .kh-tab-pagi .pagination { margin: 0; }
        .kh-tab-rong { padding: 24px 0; text-align: center; color: #8c8c8c; }

        /* Hộp Thêm/Sửa: điện thoại giữ 90%, từ lg trở lên thu về 70% cho đỡ rộng — như v2. */
        #modalCrUd .modal-dialog { max-width: 90%; }
        @media (min-width: 992px) { #modalCrUd .modal-dialog { max-width: 70%; } }
        #modalCrUd label, #table-detail-customer label { font-weight: bold; }
        #modalCrUd #img-preview { width: 100%; }
        #table-detail-customer input, #table-detail-customer select { color: black !important; }
        /* Khung "Cá nhân | Doanh nghiệp" + Trạng thái — bo góc, viền mảnh, đúng
           như ảnh chủ tiệm gửi. Trạng thái nằm CUỐI khung, cách hai dòng radio
           bằng một đường kẻ nhạt để mắt đọc ra là hai chuyện khác nhau. */
        .khung-loai { border: 1px solid #d9d9d9; border-radius: 8px; padding: 10px 12px; }
        .khung-loai .form-check { min-height: 0; margin-bottom: 6px; }
        .khung-loai .form-check:last-of-type { margin-bottom: 0; }
        .khung-loai .form-check label { font-weight: 600; cursor: pointer; }
        .khung-loai-trang-thai {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 10px; padding-top: 10px; border-top: 1px solid #eee;
        }
        .khung-loai-trang-thai span { font-weight: 600; }

        /* Ô chọn trong hộp thoại phải NỔI TRÊN lớp phủ của modal, không thì bấm
           ra là danh sách chui xuống dưới nền mờ. Cùng cách màn Điều chỉnh tồn
           kho đang làm. */
        #modalCrUd .select2-container--open,
        #modalCrUd .select2-dropdown { z-index: 1065 !important; }
        /* Nút "+" cạnh ô nhóm: select2 dựng thẻ thay thế cao 38px, để nút cao
           theo cho hai thứ bằng nhau. */
        #modalCrUd .add_group, #modalCrUd .add_group_business { height: 38px; }
    </style>
@endpush

@php
    $C = \App\Http\Controllers\CustomerController::class;
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $anhMacDinh = asset('v2/images/image_defaul.png');
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($s) => $s ? \Illuminate\Support\Carbon::parse($s)->format('d/m/Y') : '';

    // Đang lọc mà bảng rỗng thì nói "không khớp bộ lọc", đừng nói "chưa có" —
    // hai việc phải làm khác hẳn nhau.
    $hasFilter = $filters['keyword'] !== ''
        || $filters['point_from'] !== ''
        || $filters['point_to'] !== ''
        || $filters['rank'] !== ''
        || count($filters['type']) < 2;

    // v2 để trạng thái cột trong bảng user_selected_columns; ở đây lấy từ query
    // để giữ được sau khi tải lại mà không cần bảng riêng.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $columns = [];
    // Chỉ liệt kê cột BẢNG THẬT SỰ CÓ. v2 để sót vài ô tick không còn cột nào
    // (nhóm, email, ghi chú) — chép cả thì tick xong không thấy gì đổi.
    foreach ([
        'code', 'name', 'type', 'customer_group', 'total_purchases',
        'total_paid', 'still_in_debt', 'phone', 'action',
    ] as $c) {
        $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }
    $convertMessage = [
        'show_code' => 'customer-code', 'show_name' => 'customer-name',
        'show_type' => 'customer_type', 'show_customer_group' => 'customer_group',
        'show_total_purchases' => 'total_purchases', 'show_total_paid' => 'total_paid',
        'show_still_in_debt' => 'still_in_debt', 'show_phone' => 'phone-number',
        'show_action' => 'action',
    ];
@endphp

@section('content')
    {{-- Dãy nút mở bộ lọc, CHỈ hiện trên điện thoại — bốn khối đúng như v2. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterBranch',
                'modalLabel' => __('message.search'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterPoint',
                'modalLabel' => __('message.point'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterLevelMembership',
                'modalLabel' => __('message.level-membership'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterCustomer',
                'modalLabel' => __('message.customer_type'),
            ])
        </div>
    </div>

    <div class="row index-customer-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Bốn khối lọc, đúng thứ tự v2: Tìm kiếm · Điểm · Loại
                             khách hàng · Cấp độ thành viên.

                             Xếp thẳng hàng dọc, mỗi khối một `mb-3` — cùng khuôn
                             với Thu chi / Công nợ / Nhân sự. Bản v2 gói chúng vào
                             hai cột `col-md-6 col-lg-12` để trên tablet nằm hai
                             hàng ngang, nhưng vỏ v2 bên mình giấu hẳn cột lọc dưới
                             992px và bưng từng ô sang offcanvas, nên hai cột đó
                             không bao giờ có tác dụng.

                             Không có nút "Lọc": gõ hay đổi ô nào là lọc lại ngay. --}}
                        <form action="{{ route('admin.customers.index') }}" method="GET" id="search-form">
                            <div id="filterSearch" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.search') }}</span>
                                    <div class="input-group">
                                        <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                            class="form-control search" autocomplete="off"
                                            placeholder="{{ __('message.enter-name-or-code') }}">
                                        <button class="btn seach-item" type="button">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div id="filterPoint" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.point') }}</span>
                                    <input type="number" name="point_from" value="{{ $filters['point_from'] }}"
                                        class="form-control point_from mb-2" placeholder="{{ __('message.from') }}">
                                    <input type="number" name="point_to" value="{{ $filters['point_to'] }}"
                                        class="form-control point_to" placeholder="{{ __('message.to') }}">
                                </div>
                            </div>

                            <div id="filterCustomerType" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.customer_type') }}</span>
                                    @foreach ([['0', __('message.personal')], ['1', __('message.business')]] as [$ma, $ten])
                                        <div class="form-check">
                                            <input class="me-2 form-check-input check-to-search" type="checkbox"
                                                value="{{ $ma }}" name="type[]" id="type_{{ $ma }}_search"
                                                {{ in_array($ma, $filters['type'], true) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="type_{{ $ma }}_search">{{ $ten }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div id="filterLevelMembership" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.level-membership') }}</span>
                                    <select name="rank" class="form-control select_rank">
                                        <option value="">{{ __('message.all') }}</option>
                                        @foreach ($ranks as $hang)
                                            <option value="{{ $hang['id'] }}"
                                                {{ $filters['rank'] === (string) $hang['id'] ? 'selected' : '' }}>
                                                {{ $hang['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="content_midd">
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">{{ __('message.customer-management') }}</h1>
                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green add-item">{{ __('message.create') }}</a>
                            <a type="button" class="bt btn_red mass-delete">{{ __('message.delete') }}</a>

                            <div class="dropdown dropdown_advanced">
                                <button class="bt btn_advanced dropdown-toggle py-1" type="button" data-bs-toggle="dropdown"
                                    aria-expanded="false">{{ __('message.advanced') }}</button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item btn_import_file" type="button">{{ __('message.import_file') }}</a>
                                        <a class="dropdown-item" href="{{ route('admin.customers.importTemplate') }}">{{ __('message.download_sample_file') }}</a>
                                        <a class="dropdown-item" href="{{ route('admin.customers.export', request()->query()) }}">{{ __('message.export-excel') }}</a>
                                    </li>
                                </ul>
                            </div>

                            {{-- Chọn cột: bỏ tick là thêm cột vào ?hide=, tải lại giữ nguyên lựa chọn. --}}
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col" href="#">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" data-col="show_all" type="checkbox" id="show_all"
                                                    {{ count($cotTat) ? '' : 'checked' }}>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($columns as $col => $value)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="{{ $col }}"
                                                        type="checkbox" id="show_{{ $col }}" {{ $value == 1 ? 'checked' : '' }}>
                                                    <label for="show_{{ $col }}">{{ __('message.' . ($convertMessage[$col] ?? $col)) }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>

                            <form id="import-form" method="POST" action="{{ route('admin.customers.import') }}"
                                enctype="multipart/form-data" class="d-none">
                                @csrf
                                <input type="file" name="file" id="excel_file" accept=".csv,text/csv"
                                    onchange="this.form.submit()">
                            </form>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-striped table-customer none_mobile">
                            <tr class="header-table-list">
                                <th class="text-center not-export"><input class="form-check-input item-select-all" type="checkbox"></th>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ __('message.customer-code') }}</th>
                                <th class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">{{ __('message.customer-name') }}</th>
                                <th class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">{{ __('message.customer_type') }}</th>
                                <th class="text-left show_customer_group {{ $columns['show_customer_group'] ? '' : 'hide' }}">{{ __('message.customer_group') }}</th>
                                <th class="text-right show_total_purchases {{ $columns['show_total_purchases'] ? '' : 'hide' }}">{{ __('message.total_purchases') }}</th>
                                <th class="text-right show_total_paid {{ $columns['show_total_paid'] ? '' : 'hide' }}">{{ __('message.total_paid') }}</th>
                                <th class="text-right show_still_in_debt {{ $columns['show_still_in_debt'] ? '' : 'hide' }}">{{ __('message.still_in_debt') }}</th>
                                <th class="text-right show_phone {{ $columns['show_phone'] ? '' : 'hide' }}">{{ __('message.phone-number') }}</th>
                                <th class="text-center not-export show_action {{ $columns['show_action'] ? '' : 'hide' }}">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($list as $i => $kh)
                                @php $id = (int) ($kh['id'] ?? 0); @endphp
                                <tr class="item not-export" data-id="{{ $id }}">
                                    <td class="text-center not-export">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    </td>
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    <td class="text-left item-code show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ $kh['code'] ?? '' }}</td>
                                    <td class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">{{ $kh['full_name'] ?? '' }}</td>
                                    <td class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">
                                        {{ ((int) ($kh['type'] ?? 0)) === 0 ? __('message.personal') : __('message.business') }}
                                    </td>
                                    <td class="text-left show_customer_group {{ $columns['show_customer_group'] ? '' : 'hide' }}">{{ $kh['group_name'] ?? '' }}</td>
                                    <td class="text-right la-tien show_total_purchases {{ $columns['show_total_purchases'] ? '' : 'hide' }}">{{ $tien($kh['total_spent'] ?? 0) }}</td>
                                    <td class="text-right la-tien show_total_paid {{ $columns['show_total_paid'] ? '' : 'hide' }}">{{ $tien($kh['total_paid'] ?? 0) }}</td>
                                    <td class="text-right la-tien show_still_in_debt {{ $columns['show_still_in_debt'] ? '' : 'hide' }}">{{ $tien($kh['still_in_debt'] ?? 0) }}</td>
                                    <td class="text-right la-tien show_phone {{ $columns['show_phone'] ? '' : 'hide' }}">{{ $kh['phone'] ?? '' }}</td>
                                    {{-- Bốn nút, đúng thứ tự v2: xem · sửa · xoá · nhân bản. --}}
                                    <td class="text-center action not-export show_action {{ $columns['show_action'] ? '' : 'hide' }}">
                                        <a class="detail-item" type="button" title="{{ __('message.detail') }}"><i class="fa fa-eye"></i></a>
                                        <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                        <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                        <a class="copy_bt copy-item" type="button" title="{{ __('message.copy') }}"><i class="fa fa-copy"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center py-4">
                                        {{ $hasFilter
                                            ? 'Không có khách hàng nào khớp bộ lọc đang bật.'
                                            : $C::EMPTY_TEXT }}
                                    </td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai. --}}
                        <div class="table-customer none_desktop">
                            @foreach ($list as $kh)
                                <div class="item" data-id="{{ (int) ($kh['id'] ?? 0) }}">
                                    <div class="d-flex align-items-center w-100 justify-content-between">
                                        <div class="form-check me-2">
                                            <input class="form-check-input item-select" type="checkbox" value="{{ (int) ($kh['id'] ?? 0) }}">
                                        </div>
                                        <div class="d-flex flex-column flex-grow-1 detail-item" role="button">
                                            <span class="fw-semibold">{{ $kh['full_name'] ?? '' }}</span>
                                            <span style="font-size: 14px">{{ $kh['code'] ?? '' }}</span>
                                        </div>
                                        <div class="d-flex gap-2">{{ $kh['phone'] ?? '' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dữ liệu đầy đủ của từng dòng, ĐẶT TRONG khối được thay khi nạp lại. --}}
                    <script type="application/json" id="v2-rows">@json(collect($list)->keyBy('id'))</script>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width" data-param="page_size">
                    @foreach ($C::PAGE_SIZES as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Thêm / Sửa ===================== --}}
    <div class="modal" id="modalCrUd">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl mx-auto">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add') }} / {{ __('message.edit') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="modal_center">
                        <input type="hidden" class="id">
                        <input type="hidden" class="ip_image">

                        <div class="row">
                            <div class="col-sm-3 d-flex align-items-start justify-content-center">
                                <div class="img_st w-100">
                                    <label class="d-none d-lg-block">{{ __('message.image') }}</label>
                                    <div class="d-flex justify-content-center">
                                        <div class="pic_add">
                                            <img id="img-preview" class="mx-auto" src="{{ $anhMacDinh }}">
                                        </div>
                                    </div>
                                    <div class="upload_pic">
                                        {{ __('message.upload') }}
                                        <input type="file" class="ip_img" accept="image/*">
                                    </div>

                                    {{-- Một khung gom ba thứ cùng nói về "khách này là ai":
                                         loại khách và trạng thái. Đổi radio là đổi hẳn khối ô
                                         bên phải, như v2. Trạng thái nằm CUỐI khung. --}}
                                    <div class="khung-loai mt-3">
                                        <div class="form-check">
                                            <input type="radio" class="me-2 form-check-input radio-input" id="type_1" value="0" name="type" checked>
                                            <label for="type_1">{{ __('message.personal') }}</label>
                                        </div>
                                        <div class="form-check">
                                            <input type="radio" class="me-2 form-check-input radio-input" id="type_2" value="1" name="type">
                                            <label for="type_2">{{ __('message.business') }}</label>
                                        </div>

                                        <div class="khung-loai-trang-thai">
                                            <span>{{ __('message.status') }}</span>
                                            <input type="checkbox" class="switch_customer ip_status" checked>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-sm-9 ps-sm-0" style="color: #212529;">
                                {{-- ---------- Khối CÁ NHÂN ---------- --}}
                                <div class="row khoi-ca-nhan">
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.customer-code') }}</label>
                                        <input disabled type="text" class="form-control ip_code"
                                            placeholder="{{ __('message.auto-increment-code') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.customer_group') }}</label>
                                        <div class="d-flex">
                                            <select class="form-control group" name="customer_group_id">
                                                <option value="">--</option>
                                                @foreach ($nhomCaNhan as $n)
                                                    <option value="{{ $n['id'] }}">{{ $n['name'] }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" class="ms-2 btn add_group px-2 py-1" data-loai="0"
                                                style="border: 1px solid #7083B6"><i class="fa fa-plus" style="font-size: 12px"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.date-of-birth') }}</label>
                                        <input type="text" class="form-control ip_birthday" autocomplete="off"
                                            placeholder="{{ __('message.date-of-birth') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.customer-name') }} <span style="color:red">*</span></label>
                                        <input type="text" class="form-control ip_name" maxlength="150"
                                            placeholder="{{ __('message.customer-name') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.gender') }}</label>
                                        <select class="form-select ip_gender">
                                            <option value="">--</option>
                                            <option value="male">{{ __('message.male') }}</option>
                                            <option value="female">{{ __('message.female') }}</option>
                                            <option value="other">{{ __('message.other') }}</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.email') }}</label>
                                        <input type="email" class="form-control ip_email" maxlength="191"
                                            placeholder="{{ __('message.email') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.citizen-id') }}</label>
                                        <input type="text" class="form-control ip_cccd" maxlength="12"
                                            placeholder="{{ __('message.citizen-id') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.phone') }}</label>
                                        <input type="text" class="form-control ip_phone" maxlength="20"
                                            placeholder="{{ __('message.phone') }}">
                                    </div>
                                </div>

                                {{-- ---------- Khối DOANH NGHIỆP ---------- --}}
                                <div class="row khoi-doanh-nghiep d-none">
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.business_code') }}</label>
                                        <input disabled type="text" class="form-control ip_code_business"
                                            placeholder="{{ __('message.auto-increment-code') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.business_group') }}</label>
                                        <div class="d-flex">
                                            <select class="form-control group_business" name="customer_group_id_business">
                                                <option value="">--</option>
                                                @foreach ($nhomDoanhNghiep as $n)
                                                    <option value="{{ $n['id'] }}">{{ $n['name'] }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" class="ms-2 btn add_group_business px-2 py-1" data-loai="1"
                                                style="border: 1px solid #7083B6"><i class="fa fa-plus" style="font-size: 12px"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.representative_info') }}</label>
                                        <input type="text" class="form-control ip_representative_info"
                                            placeholder="{{ __('message.representative_info') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.business_name') }} <span style="color:red">*</span></label>
                                        <input type="text" class="form-control ip_name_business" maxlength="150"
                                            placeholder="{{ __('message.business_name') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.business_email') }}</label>
                                        <input type="email" class="form-control ip_email_business" maxlength="191"
                                            placeholder="{{ __('message.business_email') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.representative_phone') }}</label>
                                        <input type="text" class="form-control ip_representative_phone"
                                            placeholder="{{ __('message.representative_phone') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.tax_code') }}</label>
                                        <input type="text" class="form-control ip_tax_code" maxlength="20"
                                            placeholder="{{ __('message.tax_code') }}">
                                    </div>
                                    <div class="col-12 col-md-6 col-xl-4 mt-3">
                                        <label class="form-label">{{ __('message.business_phone') }}</label>
                                        <input type="text" class="form-control ip_phone_business" maxlength="20"
                                            placeholder="{{ __('message.business_phone') }}">
                                    </div>
                                </div>

                                {{-- Hai ô dùng chung cho cả hai loại, như v2. --}}
                                <div class="row mt-3">
                                    <label class="form-label d-block">{{ __('message.address') }}</label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_address" maxlength="255"
                                            placeholder="{{ __('message.address') }}" style="height: 70px"></textarea>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <label class="form-label d-block">{{ __('message.note') }}</label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_note" maxlength="200"
                                            placeholder="{{ __('message.note') }}" style="height: 70px"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-item">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Chi tiết — bốn tab như v2 ===================== --}}
    <div class="modal" id="modalDetail">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl mx-auto" style="max-width: 80%">
            <div class="modal-content">
                <div class="modal-header">
                    <input type="hidden" id="id_customer">
                    <h4 class="modal-title">{{ __('message.detail') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-2">
                    <div class="col-md-12 border">
                        <ul class="nav nav-tabs nav-detail" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#detail-customer"
                                    type="button" role="tab">{{ __('message.detail') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#transaction-history"
                                    type="button" role="tab">{{ __('message.transaction-history') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#debt-detail"
                                    type="button" role="tab">{{ __('message.debt') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#point-history"
                                    type="button" role="tab">{{ __('message.point_usage_history') }}</button>
                            </li>
                        </ul>

                        <div class="tab-content p-3">
                            <div class="tab-pane fade show active" id="detail-customer" role="tabpanel">
                                <div class="row" id="table-detail-customer">
                                    <div class="col-sm-3 d-flex align-items-start justify-content-center">
                                        <div class="img_st w-100">
                                            <label class="d-none d-lg-block">{{ __('message.image') }}</label>
                                            <div class="d-flex justify-content-center">
                                                <div class="pic_add">
                                                    <img id="img-preview-detail" class="mx-auto" src="{{ $anhMacDinh }}">
                                                </div>
                                            </div>
                                            <div class="form-check mt-3">
                                                <input type="radio" class="me-2 form-check-input radio-input" id="type_1_show"
                                                    value="0" name="type_show" disabled>
                                                <label for="type_1_show">{{ __('message.personal') }}</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="radio" class="me-2 form-check-input radio-input" id="type_2_show"
                                                    value="1" name="type_show" disabled>
                                                <label for="type_2_show">{{ __('message.business') }}</label>
                                            </div>
                                            <div class="mt-3 text-center">
                                                <label class="form-label d-block">{{ __('message.status') }}</label>
                                                <input disabled type="checkbox" class="switch_customer ip_status" checked>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-sm-9" style="color: #212529;">
                                        <div class="row mt-3">
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.customer-code') }}</label>
                                                <input disabled type="text" class="form-control ip_code">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.customer_group') }}</label>
                                                <input disabled type="text" class="form-control ip_group">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.date-of-birth') }}</label>
                                                <input disabled type="text" class="form-control ip_birthday">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.customer-name') }}</label>
                                                <input disabled type="text" class="form-control ip_name">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.gender') }}</label>
                                                <input disabled type="text" class="form-control ip_gender">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.email') }}</label>
                                                <input disabled type="text" class="form-control ip_email">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.citizen-id') }}</label>
                                                <input disabled type="text" class="form-control ip_cccd">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.phone') }}</label>
                                                <input disabled type="text" class="form-control ip_phone">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.creation-date') }}</label>
                                                <input disabled type="text" class="form-control ip_created_at">
                                            </div>
                                        </div>

                                        <div class="row mt-3">
                                            <div class="col-12 col-md-6 col-xl-3 mb-2">
                                                <label class="form-label">{{ __('message.total_purchases') }}</label>
                                                <input disabled type="text" class="form-control ip_total_purchases">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-3 mb-2">
                                                <label class="form-label">{{ __('message.total_paid') }}</label>
                                                <input disabled type="text" class="form-control ip_total_paid">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-3 mb-2">
                                                <label class="form-label">{{ __('message.still_in_debt') }}</label>
                                                <input disabled type="text" class="form-control ip_still_in_debt">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-3 mb-2">
                                                <label class="form-label">{{ __('message.remaining_points') }}</label>
                                                <input disabled type="text" class="form-control ip_point">
                                            </div>
                                        </div>

                                        <div class="row mt-3">
                                            <label class="form-label d-block">{{ __('message.address') }}</label>
                                            <div class="box-textarea-cus">
                                                <textarea disabled class="form-control ip_address" style="height: 70px"></textarea>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <label class="form-label d-block">{{ __('message.note') }}</label>
                                            <div class="box-textarea-cus">
                                                <textarea disabled class="form-control ip_note" style="height: 70px"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Tab 2 — đọc thẳng sổ đơn hàng của khách này. --}}
                            <div class="tab-pane fade" id="transaction-history" role="tabpanel">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                    <div class="input-group" style="max-width: 250px">
                                        <input type="text" class="form-control" id="search_history"
                                            placeholder="{{ __('message.enter_order_code') }}">
                                        <button class="btn seach-item" type="button">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                        </button>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="text" class="form-control" id="from-date" style="max-width: 160px"
                                            placeholder="Từ ngày" autocomplete="off">
                                        <input type="text" class="form-control" id="to-date" style="max-width: 160px"
                                            placeholder="Đến ngày" autocomplete="off">
                                    </div>
                                    <select class="form-control" id="order_channel" style="max-width: 200px">
                                        <option value="">{{ __('message.all') }}</option>
                                        <option value="pos">{{ __('message.sales') }}</option>
                                        <option value="web">{{ __('message.online_order') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-12" id="table-transaction-history"></div>
                                <div class="kh-tab-chan">
                                    <select class="form-control select-width kh-tab-size" data-tab="lich-su">
                                        @foreach ($C::PAGE_SIZES as $muc)
                                            <option value="{{ $muc }}">{{ __('message.display', ['name' => $muc]) }}</option>
                                        @endforeach
                                    </select>
                                    <div class="kh-tab-pagi" data-tab="lich-su" id="pagi-transaction-history"></div>
                                </div>
                            </div>

                            {{-- Tab 3 và 4 — dựng đủ khuôn của v2, nhưng bên mình CHƯA có sổ nợ
                                 khách và chưa có hệ điểm. Nói thẳng ra thay vì bày bảng rỗng
                                 trông như đọc hỏng. --}}
                            <div class="tab-pane fade" id="debt-detail" role="tabpanel">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                    <div class="input-group" style="max-width: 250px">
                                        <input type="text" class="form-control" id="search_debt" disabled
                                            placeholder="{{ __('message.enter_order_code') }}">
                                        <button class="btn seach-item" type="button" disabled>
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-12" id="list-debt">
                                    <p class="kh-tab-rong">Chưa có sổ công nợ khách hàng — Công nợ hiện chỉ theo dõi phía nhà cung cấp.</p>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="point-history" role="tabpanel">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                    <input type="text" class="form-control" id="from-date-point" style="max-width: 160px"
                                        placeholder="Từ ngày" autocomplete="off" disabled>
                                    <input type="text" class="form-control" id="to-date-point" style="max-width: 160px"
                                        placeholder="Đến ngày" autocomplete="off" disabled>
                                </div>
                                <div class="col-md-12" id="table-point-usage-history">
                                    <p class="kh-tab-rong">Chưa có hệ điểm tích luỹ — hạng thành viên và lịch sử đổi điểm sẽ hiện ở đây khi dựng xong.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green detail-edit">{{ __('message.edit') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp thêm nhanh Nhóm khách hàng ===================== --}}
    <div class="modal" id="addGroup">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.add_customer_group') }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center">
                        <input type="hidden" class="loai_nhom" value="0">
                        <label class="form-label">{{ __('message.customer_group_name') }} <span style="color:red">*</span></label>
                        <input type="text" class="form-control ten_nhom" maxlength="150"
                            placeholder="{{ __('message.customer_group_name') }}">
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-group">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>


    {{-- ===================== Hộp Xoá ===================== --}}
    <div class="modal" id="deleteItem">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.delete') }} ?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="deleteValue">
                    <div class="modal_center">
                        <div class="row">
                            <div class="col">
                                <label class="form-label">{{ __('message.delete-confirm') }}</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green delete-value">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const CSRF = '{{ csrf_token() }}';
        const URL_BASE = @json(url('/admin/customers'));
        const URL_STORE = @json(route('admin.customers.store'));
        const URL_BULK_DEL = @json(route('admin.customers.bulkDestroy'));
        const URL_ANH = @json(route('admin.customers.uploadAvatar'));
        const URL_NHOM = @json(route('admin.customers.taoNhom'));
        const ANH_MAC_DINH = @json($anhMacDinh);

        // Cả bản ghi của từng dòng — hộp Sửa / Chi tiết đọc thẳng ở đây, khỏi rải
        // hơn hai chục data-* lên mỗi <tr> như bản v2.
        let KH = docDongHienCo();

        function docDongHienCo() {
            try {
                return JSON.parse(document.getElementById('v2-rows').textContent) || {};
            } catch (e) {
                return {};
            }
        }

        $(document).on('v2:da-nap', function () { KH = docDongHienCo(); });

        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const tien = (v) => (Number(v) || 0).toLocaleString('vi-VN') + '₫';
        const ngay = (s) => (s ? String(s).slice(0, 10).split('-').reverse().join('/') : '');
        const GIOI_TINH = { male: '{{ __('message.male') }}', female: '{{ __('message.female') }}', other: '{{ __('message.other') }}' };

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        function postForm(action, method, fields) {
            const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
            const them = (n, v) => $f.append($('<input>', { type: 'hidden', name: n, value: v == null ? '' : v }));
            them('_token', CSRF);
            if (method && method !== 'POST') them('_method', method);
            them('return', location.pathname + location.search);
            $.each(fields || {}, (k, v) => {
                Array.isArray(v) ? v.forEach((x) => them(k + '[]', x)) : them(k, v);
            });
            $('body').append($f);
            $f.trigger('submit');
        }

        // ---------- Chọn cột ----------
        function apDungCot() {
            const tat = $('.show_col').filter((i, el) => !el.checked)
                .map((i, el) => $(el).data('col').replace('show_', '')).get();
            const q = new URLSearchParams(location.search);
            tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            apDungCot();
        });

        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        $(document).on('click', '.btn_import_file', () => $('#excel_file').click());

        // ---------- Bộ lọc chạy ngay: đổi ô chọn là lọc luôn, gõ thì chờ 400ms ----------
        // Tự dựng URL thay vì submit form: trên điện thoại khung v2 bưng từng khối
        // lọc sang offcanvas nên submit sẽ đánh rơi các ô còn lại, và lựa chọn cột
        // nằm ở ?hide= chứ không phải ô trong form.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        // Mọi ô một dòng của chín khối lọc.
        const O_LOC = [
            'keyword', 'created_from', 'created_to', 'address',
            'birthday_mode', 'birthday_preset', 'birthday_from', 'birthday_to',
            'last_tx_mode', 'last_tx_preset', 'last_tx_from', 'last_tx_to',
            'point_from', 'point_to', 'age_from', 'age_to', 'rank',
        ];

        function locLai() {
            const q = new URLSearchParams();
            O_LOC.forEach((ten) => {
                const $o = oLoc(ten);
                // Radio: chỉ lấy cái đang chọn.
                const v = String(($o.is(':radio') ? $o.filter(':checked') : $o).val() || '').trim();
                if (v) q.set(ten, v);
            });
            // Hai nhóm tick: bỏ tick hết là bảng rỗng — đúng như v2.
            $('.fillter-box [name="type[]"]:checked').each((i, el) => q.append('type[]', el.value));
            $('.fillter-box [name="gender[]"]:checked').each((i, el) => q.append('gender[]', el.value));

            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size', 'sort_by', 'sort_dir'].forEach((ten) => {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            // Cố ý không mang `page` theo: lọc lại thì trang 5 của bộ lọc cũ vô nghĩa.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerTim = null;
        const HEN = 'input[name="keyword"], input[name="address"], input[name="point_from"], '
            + 'input[name="point_to"], input[name="age_from"], input[name="age_to"]';

        $(document).on('change', '.fillter-box select, .fillter-box [name="type[]"], .fillter-box [name="gender[]"]', locLai);
        $(document).on('input', '.fillter-box ' + HEN.split(', ').join(', .fillter-box '), function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(locLai, 400);
        });
        $(document).on('submit', '#search-form', function (e) {
            e.preventDefault();
            locLai();
        });

        // Hai khối Sinh nhật / Giao dịch cuối: chọn "tự chọn" mới mở ô lịch ra.
        $(document).on('change', '.fillter-box [name="birthday_mode"], .fillter-box [name="last_tx_mode"]', function () {
            const ten = this.name.replace('_mode', '');
            const tuChon = this.value === 'custom';
            $('.fillter-box .' + ten + '_range').prop('disabled', ! tuChon);
            $('.fillter-box .' + ten + '_preset').prop('disabled', tuChon);
            // Đổi lối chọn thì bỏ luôn giá trị của lối kia, không thì lọc theo cả hai.
            tuChon ? $('.fillter-box .' + ten + '_preset').val('')
                   : $('.fillter-box .' + ten + '_from, .fillter-box .' + ten + '_to').val('');
            locLai();
        });

        // Ba ô lịch chọn KHOẢNG ngày; ô hiện chữ, hai ô ẩn giữ hai đầu để gửi đi.
        [['created_range', 'created'], ['birthday_range', 'birthday'], ['last_tx_range', 'last_tx']]
            .forEach(([o, ten]) => {
                $('.fillter-box .' + o).daterangepicker({
                    autoUpdateInput: false, showDropdowns: true, locale: V2.lichVN(),
                }, function (tu, den) {
                    $(this.element).val(tu.format('DD/MM/YYYY') + ' - ' + den.format('DD/MM/YYYY'));
                    $('.fillter-box .' + ten + '_from').val(tu.format('YYYY-MM-DD'));
                    $('.fillter-box .' + ten + '_to').val(den.format('YYYY-MM-DD'));
                    locLai();
                });

                // Xoá trắng ô là bỏ lọc theo khoảng đó.
                $(document).on('input', '.fillter-box .' + o, function () {
                    if (String(this.value).trim() !== '') return;
                    $('.fillter-box .' + ten + '_from, .fillter-box .' + ten + '_to').val('');
                    locLai();
                });
            });

        // =====================================================================
        //  Hộp Thêm / Sửa
        // =====================================================================
        const $crud = $('#modalCrUd');

        // Ba ô chọn trong hộp dựng bằng select2 cho giống mọi ô chọn khác của hệ
        // thống (khung lọc bên trái, hộp lập phiếu điều chỉnh tồn kho…).
        //
        // `dropdownParent` trỏ vào chính hộp thoại: để mặc định là <body> thì
        // danh sách bung ra nằm NGOÀI modal, và Bootstrap chặn mọi lượt bấm ra
        // ngoài — chọn được bằng bàn phím nhưng bấm chuột thì không.
        //
        // Chạy MỘT LẦN lúc mở hộp đầu tiên, không phải mỗi lượt mở: select2 gọi
        // lại trên cùng một thẻ là dựng chồng thêm một lớp nữa.
        $crud.on('show.bs.modal', function () {
            $crud.find('select.group, select.group_business, select.ip_gender')
                .not('.select2-hidden-accessible')
                .select2({
                    dropdownParent: $crud,
                    width: '100%',
                    // Danh sách ngắn thì ô tìm chỉ tổ vướng; dài mới cần.
                    minimumResultsForSearch: 8,
                    language: {
                        noResults: () => 'Không có mục nào khớp',
                        searching: () => 'Đang tìm…',
                    },
                });
        });

        /** Đặt giá trị cho ô select2 — phải báo `change` thì chữ hiện ra mới đổi theo. */
        function datOChon($o, giaTri) {
            $o.val(giaTri || '');
            if ($o.hasClass('select2-hidden-accessible')) {
                $o.trigger('change.select2');
            }
        }

        /** Đổi radio Cá nhân | Doanh nghiệp là đổi hẳn khối ô, như v2. */
        function doiKhoiTheoLoai() {
            const dn = $crud.find('input[name="type"]:checked').val() === '1';
            $crud.find('.khoi-ca-nhan').toggleClass('d-none', dn);
            $crud.find('.khoi-doanh-nghiep').toggleClass('d-none', !dn);
        }
        $(document).on('change', '#modalCrUd input[name="type"]', doiKhoiTheoLoai);

        function xoaTrangCrUd() {
            $crud.find('input[type=text], input[type=email], textarea').val('');
            $crud.find('input.id, input.ip_image').val('');
            $crud.find('input.ip_img').val('');
            datOChon($crud.find('select.ip_gender'), '');
            datOChon($crud.find('select.group, select.group_business'), '');
            $crud.find('input.ip_status').prop('checked', true);
            $crud.find('#type_1').prop('checked', true);
            $crud.find('#img-preview').attr('src', ANH_MAC_DINH);
            doiKhoiTheoLoai();
        }

        /** Đổ một bản ghi vào hộp. giuMa = false là nhân bản — mã để trống cho API tự sinh. */
        function doVaoCrUd(k, giuMa) {
            const dn = Number(k.type) === 1;
            $crud.find(dn ? '#type_2' : '#type_1').prop('checked', true);
            doiKhoiTheoLoai();

            $crud.find('input.ip_code, input.ip_code_business').val(giuMa ? (k.code || '') : '');
            $crud.find('input.ip_name, input.ip_name_business').val(k.full_name || '');
            $crud.find('input.ip_email, input.ip_email_business').val(k.email || '');
            $crud.find('input.ip_phone, input.ip_phone_business').val(k.phone || '');
            $crud.find('input.ip_birthday').val(ngay(k.date_of_birth));
            datOChon($crud.find('select.ip_gender'), k.gender);
            $crud.find('textarea.ip_address').val(k.address || '');
            $crud.find('input.ip_status').prop('checked', (k.status || 'active') === 'active');
            $crud.find('input.ip_image').val(k.avatar || '');
            $crud.find('#img-preview').attr('src', k.avatar || ANH_MAC_DINH);

            datOChon($crud.find('select.group, select.group_business'), k.customer_group_id);
            $crud.find('input.ip_cccd').val(k.citizen_id || '');
            $crud.find('input.ip_tax_code').val(k.tax_code || '');
            $crud.find('input.ip_representative_info').val(k.representative_name || '');
            $crud.find('input.ip_representative_phone').val(k.representative_phone || '');
            $crud.find('textarea.ip_note').val(k.customer_note || '');
        }

        function moSua(k) {
            xoaTrangCrUd();
            $crud.find('input.id').val(k.id);
            $crud.find('.modal-title').text('{{ __('message.edit') }}');
            doVaoCrUd(k, true);
            $crud.modal('show');
        }

        const cuaDong = (el) => KH[$(el).closest('.item').data('id')];

        $(document).on('click', '.add-item', function () {
            xoaTrangCrUd();
            $crud.find('.modal-title').text('{{ __('message.create') }}');
            $crud.modal('show');
        });

        $(document).on('click', '.edit-item', function () {
            const k = cuaDong(this);
            if (k) moSua(k);
        });

        $(document).on('click', '.copy-item', function () {
            const k = cuaDong(this);
            if (!k) return;
            xoaTrangCrUd();
            $crud.find('.modal-title').text('{{ __('message.create') }}');
            doVaoCrUd(k, false);
            // Email không chép sang: khai thật thì phải duy nhất trong cửa hàng,
            // nhân bản mà giữ nguyên là API từ chối ngay.
            $crud.find('input.ip_email, input.ip_email_business').val('');
            $crud.find('input.ip_name, input.ip_name_business').val(((k.full_name || '') + ' copy').trim());
            $crud.modal('show');
        });

        // Ảnh tải lên ngay lúc chọn; form chỉ mang theo đường dẫn.
        $(document).on('change', '#modalCrUd .ip_img', function () {
            const f = this.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('image', f);
            fd.append('_token', CSRF);
            $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((r) => {
                    $crud.find('input.ip_image').val(r.url);
                    $crud.find('#img-preview').attr('src', r.url);
                })
                .fail((x) => toastr.error((x.responseJSON && x.responseJSON.message) || 'Không tải được ảnh lên.'));
        });

        // Ngày sinh gõ theo DD-MM-YYYY, gửi đi YYYY-MM-DD.
        $('#modalCrUd .ip_birthday').daterangepicker({
            singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
            locale: V2.lichVN(),
        }, function (start) {
            $(this.element).val(start.format('DD-MM-YYYY'));
        });

        const veISO = (s) => {
            const m = String(s || '').match(/^(\d{2})-(\d{2})-(\d{4})$/);
            return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
        };

        $(document).on('click', '#modalCrUd .save-item', function () {
            const id = $crud.find('input.id').val();
            const dn = $crud.find('input[name="type"]:checked').val() === '1';
            const ten = $crud.find(dn ? 'input.ip_name_business' : 'input.ip_name').val().trim();
            const email = $crud.find(dn ? 'input.ip_email_business' : 'input.ip_email').val().trim();
            const sdt = $crud.find(dn ? 'input.ip_phone_business' : 'input.ip_phone').val().trim();

            if (!ten) { toastr.error('Chưa nhập tên khách hàng.'); return; }

            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI cho người dùng sửa, thay vì tải
            // lại trang làm mất sạch cả form dài này.
            V2.luuHop($crud.closest('.modal'), id ? URL_BASE + '/' + id : URL_STORE,
                id ? 'PUT' : 'POST', {
                full_name: ten,
                email: email,
                phone: sdt,
                gender: dn ? '' : ($crud.find('select.ip_gender').val() || ''),
                date_of_birth: dn ? '' : veISO($crud.find('input.ip_birthday').val()),
                address: $crud.find('textarea.ip_address').val().trim(),
                avatar: $crud.find('input.ip_image').val(),
                status: $crud.find('input.ip_status').is(':checked') ? 'active' : 'inactive',

                // Hồ sơ riêng của khách. Mỗi loại chỉ gửi phần của mình — API tự
                // xoá phần của loại kia, khỏi để sót mã số thuế trên khách cá nhân.
                customer_type: dn ? 1 : 0,
                customer_group_id: $crud.find(dn ? 'select.group_business' : 'select.group').val() || '',
                citizen_id: dn ? '' : $crud.find('input.ip_cccd').val().trim(),
                tax_code: dn ? $crud.find('input.ip_tax_code').val().trim() : '',
                representative_name: dn ? $crud.find('input.ip_representative_info').val().trim() : '',
                representative_phone: dn ? $crud.find('input.ip_representative_phone').val().trim() : '',
                customer_note: $crud.find('textarea.ip_note').val().trim(),
            }, $(this));
        });

        // =====================================================================
        //  Thêm nhanh Nhóm khách hàng (nút "+" cạnh ô chọn)
        // =====================================================================
        // Đóng hộp Thêm/Sửa trước rồi mới mở hộp nhóm: hai modal chồng nhau là
        // kẹt nền mờ. Lưu xong thì trang tải lại nên ô chọn có ngay nhóm mới.
        $(document).on('click', '.add_group, .add_group_business', function () {
            const loai = String($(this).data('loai') || '0');
            $('#addGroup')
                .find('.loai_nhom').val(loai).end()
                .find('.modal-title').text(loai === '1'
                    ? '{{ __('message.add') }} {{ mb_strtolower(__('message.business_group')) }}'
                    : '{{ __('message.add_customer_group') }}').end()
                .find('.ten_nhom').val('').end()
                .modal('show');
        });

        $(document).on('click', '#addGroup .save-group', function () {
            const $hop = $('#addGroup');
            const ten = $hop.find('.ten_nhom').val().trim();
            if (!ten) { toastr.error('Chưa nhập tên nhóm khách hàng.'); return; }

            V2.luuHop($hop, URL_NHOM, 'POST',
                { name: ten, type: $hop.find('.loai_nhom').val() || '0' }, $(this));
        });

        // =====================================================================
        //  Hộp Chi tiết
        // =====================================================================
        const $detail = $('#modalDetail');
        let dangXem = null;
        const TAB_LICH_SU = { page: 1, luot: 0 };

        $(document).on('click', '.detail-item', function () {
            const k = cuaDong(this);
            if (!k) return;
            dangXem = k;

            $('#id_customer').val(k.id);
            $detail.find('#img-preview-detail').attr('src', k.avatar || ANH_MAC_DINH);
            $detail.find(Number(k.type) === 1 ? '#type_2_show' : '#type_1_show').prop('checked', true);
            $detail.find('input.ip_status').prop('checked', (k.status || 'active') === 'active');
            $detail.find('input.ip_code').val(k.code || '');
            $detail.find('input.ip_group').val(k.group_name || '');
            $detail.find('input.ip_birthday').val(ngay(k.date_of_birth));
            $detail.find('input.ip_name').val(k.full_name || '');
            $detail.find('input.ip_gender').val(GIOI_TINH[k.gender] || '');
            $detail.find('input.ip_email').val(k.email || '');
            $detail.find('input.ip_cccd').val(k.cccd || '');
            $detail.find('input.ip_phone').val(k.phone || '');
            $detail.find('input.ip_created_at').val(ngay(k.created_at));
            $detail.find('input.ip_total_purchases').val(tien(k.total_spent));
            $detail.find('input.ip_total_paid').val(tien(k.total_paid));
            $detail.find('input.ip_still_in_debt').val(tien(k.still_in_debt));
            $detail.find('input.ip_point').val(Number(k.remaining_score || 0).toLocaleString('vi-VN'));
            $detail.find('textarea.ip_address').val(k.address || '');
            $detail.find('textarea.ip_note').val(k.note || '');

            // Về trạng thái đầu cho bên vừa mở — không thì thấy bộ lọc và trang của
            // người trước. Lịch sử lọc sẵn THÁNG NÀY, như mọi sổ chứng từ khác.
            $('#search_history').val('');
            $('#order_channel').val('');
            $('#from-date').val(moment().startOf('month').format('DD-MM-YYYY'));
            $('#to-date').val(moment().format('DD-MM-YYYY'));
            TAB_LICH_SU.page = 1;
            $detail.find('.nav-detail .nav-link').first().tab('show');

            $detail.modal('show');
            napLichSu();
        });

        $(document).on('click', '#modalDetail .detail-edit', function () {
            if (!dangXem) return;
            $detail.modal('hide');
            moSua(dangXem);
        });

        const NHAN_TRA = { pending: '{{ __('message.not_paid') }}', paid: '{{ __('message.paid') }}',
            failed: 'Thanh toán hỏng', refunded: 'Đã hoàn tiền' };
        const NHAN_KENH = { pos: '{{ __('message.sales') }}', web: '{{ __('message.online_order') }}' };

        function napLichSu() {
            if (!dangXem) return;
            const q = {
                page: TAB_LICH_SU.page,
                page_size: $('.kh-tab-size[data-tab="lich-su"]').val() || 10,
                keyword: $('#search_history').val().trim(),
                channel: $('#order_channel').val(),
                from_date: $('#from-date').val(),
                to_date: $('#to-date').val(),
            };
            $('#table-transaction-history').html('<p class="kh-tab-rong">Đang đọc…</p>');
            $('#pagi-transaction-history').empty();

            // `luot` để lượt trả lời chậm không đè lên lượt mới hơn.
            const luot = ++TAB_LICH_SU.luot;
            $.getJSON(URL_BASE + '/' + dangXem.id + '/orders', q)
                .done((r) => { if (luot === TAB_LICH_SU.luot) veLichSu(r.data || [], r.meta || {}); })
                .fail((x) => {
                    if (luot !== TAB_LICH_SU.luot) return;
                    $('#table-transaction-history').html('<p class="kh-tab-rong">'
                        + esc((x.responseJSON && x.responseJSON.message) || 'Không đọc được sổ đơn hàng.') + '</p>');
                });
        }

        function veLichSu(rows, meta) {
            const stt0 = ((meta.page || 1) - 1) * (meta.page_size || rows.length);

            $('#table-transaction-history').html(rows.length ? `
                <div class="kh-tab-wrap"><table class="kh-tab-table kh-tab-lich-su">
                    <thead><tr>
                        <th>{{ __('message.stt') }}</th><th>{{ __('message.invoice_code') }}</th>
                        <th>{{ __('message.order_type') }}</th><th>{{ __('message.total') }}</th>
                        <th>{{ __('message.creator') }}</th><th>{{ __('message.order-created-at') }}</th>
                        <th>{{ __('message.branch') }}</th><th>{{ __('message.payment-status') }}</th>
                        <th>{{ __('message.debt') }}</th><th>{{ __('message.reason_for_return') }}</th>
                        <th>{{ __('message.note') }}</th>
                    </tr></thead>
                    <tbody>${rows.map((o, i) => `<tr>
                        <td>${stt0 + i + 1}</td>
                        <td>${esc(o.order_code || '')}</td>
                        <td class="${o.channel === 'web' ? 'text-primary' : ''}">${esc(NHAN_KENH[o.channel] || o.channel || '')}</td>
                        <td class="is-tien"><b>${tien(o.total_amount)}</b></td>
                        <td>${esc(o.created_by_name || '')}</td>
                        <td>${ngay(o.created_at)}</td>
                        <td>${esc(o.shop_name || '')}</td>
                        <td>${esc(NHAN_TRA[o.payment_status] || o.payment_status || '')}</td>
                        <td></td>
                        <td title="${esc(o.cancel_reason || '')}">${esc(o.cancel_reason || '')}</td>
                        <td title="${esc(o.note || '')}">${esc(o.note || '')}</td>
                    </tr>`).join('')}</tbody>
                </table></div>`
                : '<p class="kh-tab-rong">Khách này chưa có đơn nào khớp bộ lọc.</p>');

            $('#pagi-transaction-history').html(vePhanTrang(meta.page || 1, meta.total_pages || 1));
        }

        /** Dựng đúng khuôn của v2::partials.pagination: hai đầu, cụm quanh trang đang xem, "…" ở chỗ đứt. */
        function vePhanTrang(trang, tong) {
            if (tong <= 1) return '';
            const so = tong <= 9
                ? Array.from({ length: tong }, (_, i) => i + 1)
                : [...new Set([1, 2, trang - 1, trang, trang + 1, tong - 1, tong])].filter((p) => p >= 1 && p <= tong).sort((a, b) => a - b);
            const o = [];
            let truoc = 0;
            so.forEach((p) => { if (truoc && p > truoc + 1) o.push('...'); o.push(p); truoc = p; });

            const nut = (p, nhan, dis, active) => dis || active
                ? `<li class="page-item ${active ? 'active' : 'disabled'}"><span class="page-link">${nhan}</span></li>`
                : `<li class="page-item"><a class="page-link" href="#" data-page="${p}">${nhan}</a></li>`;

            return '<nav><ul class="pagination">'
                + nut(trang - 1, '&lsaquo;', trang <= 1)
                + o.map((p) => (p === '...' ? nut(0, '…', true) : nut(p, p, false, p === trang))).join('')
                + nut(trang + 1, '&rsaquo;', trang >= tong)
                + '</ul></nav>';
        }

        $(document).on('click', '.kh-tab-pagi a.page-link', function (e) {
            e.preventDefault();
            TAB_LICH_SU.page = Number($(this).data('page')) || 1;
            napLichSu();
        });

        // Lọc realtime trong tab: gõ xong 300ms mới hỏi máy chủ, và về trang 1.
        let henTab = null;
        function locLaiLichSu() {
            TAB_LICH_SU.page = 1;
            clearTimeout(henTab);
            henTab = setTimeout(napLichSu, 300);
        }
        $(document).on('input', '#search_history', locLaiLichSu);
        $(document).on('change', '#order_channel, .kh-tab-size', locLaiLichSu);

        $('#from-date, #to-date').each(function () {
            $(this).daterangepicker({
                singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
                locale: V2.lichVN(),
            }, function (start) {
                $(this.element).val(start.format('DD-MM-YYYY'));
                locLaiLichSu();
            });
        });

        // =====================================================================
        //  Xoá
        // =====================================================================
        $(document).on('click', '.delete-item', function () {
            $('#deleteValue').val($(this).closest('.item').data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            // Bảng và dãy thẻ điện thoại cùng dựng một danh sách nên id hay lặp.
            const ids = [...new Set($('.item-select:checked').map((i, el) => el.value).get())].filter(Boolean);
            if (!ids.length) { toastr.error('{{ __('message.delete-none') }}'); return; }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.delete-value', function () {
            const ids = String($('#deleteValue').val()).split(',').filter(Boolean);
            if (!ids.length) return;
            ids.length === 1
                ? postForm(URL_BASE + '/' + ids[0], 'DELETE', {})
                : postForm(URL_BULK_DEL, 'POST', { ids: ids });
        });
    </script>
@endpush
