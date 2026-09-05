{{-- Màn Nhà cung cấp dựng theo khuôn v2 (3rd/supplier/index + list).
     Dữ liệu do NhaCungCapController đẩy sang: $list, $filters, $meta, $thongKe. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\NhaCungCapController::TITLE)

@push('styles')
    <style>
        .btn_top_content > * { margin-left: 8px; }
        li { list-style-type: none; }
        @media (max-width: 991px) { .dropup .dropbtn { display: none !important; } }
        @media screen and (min-width: 426px) and (max-width: 991px) {
            .modal .modal-body .nav-detail { grid-template-columns: repeat(3, 1fr); }
        }
        /* Bảng danh sách: 14 cột CHIA THEO %, cộng đúng 100 — bảng luôn vừa khít
           khung, không cuộn ngang, cột Hành động không bao giờ rơi ra ngoài màn.
           `table-layout: fixed` để bề rộng do hàng tiêu đề quyết chứ không do nội
           dung. Ô dữ liệu dài cắt bằng "…" (chữ đủ nằm ở title); nhãn cột dài thì
           XUỐNG DÒNG chứ không cắt — cắt "Tổng thanh…" là mất đúng chữ phân biệt.
           Tắt bớt cột thì phần % hụt được trình duyệt chia lại cho cột còn lại.
           `overflow-x: auto` chỉ là đường lùi. */
        .list .table-responsive { overflow-x: auto; }
        table.table-supplier.none_mobile { width: 100%; table-layout: fixed; }
        table.table-supplier.none_mobile th { white-space: normal; line-height: 1.3; }
        table.table-supplier.none_mobile td { overflow: hidden; text-overflow: ellipsis; }
        /* Đệm ngang 4px thay vì 8px của v2: 14 cột × 8px là thêm gần một cột tiền. */
table.table-supplier.none_mobile th, table.table-supplier.none_mobile td { padding: 8px 4px; }
table.table-supplier.none_mobile td.action a { padding: 0 2px; }
        /* Chia % — đo thật ở khung 1182px (màn 1536): cột SỐ (tiền tới 13 chữ số,
           SĐT, MST) và 4 nút Hành động không bị cắt; chữ tự do (tên, email, địa
           chỉ) chịu cắt "…" vì đã có title. Màn 1366 chỉ tiền tỷ mới bị cắt.
           ☐ 2.5 · STT 3.5 · Mã 8 · Tên 11 · MST 7 · SĐT 7 · Email 8 · Địa chỉ 8 · Địa chỉ 2 4.5
           · Trạng thái 4.5 · Tổng mua 9.5 · Tổng TT 9.5 · Còn nợ 7.5 · Hành động 9.5 = 100 */
        table.table-supplier.none_mobile th:first-child { width: 2.5%; }
        table.table-supplier.none_mobile th:nth-child(2) { width: 3.5%; }
        table.table-supplier.none_mobile th.show_code { width: 8%; }
        table.table-supplier.none_mobile th.show_name { width: 11%; }
        table.table-supplier.none_mobile th.show_tax_code { width: 7%; }
        table.table-supplier.none_mobile th.show_phone { width: 7%; }
        table.table-supplier.none_mobile th.show_email { width: 8%; }
        table.table-supplier.none_mobile th.show_address { width: 8%; }
        table.table-supplier.none_mobile th.show_address_2 { width: 4.5%; }
        table.table-supplier.none_mobile th.show_status { width: 4.5%; }
        table.table-supplier.none_mobile th.show_total_purchases { width: 9.5%; }
        table.table-supplier.none_mobile th.show_total_payment { width: 9.5%; }
        table.table-supplier.none_mobile th.show_still_in_debt { width: 7.5%; }
        table.table-supplier.none_mobile th:last-child { width: 9.5%; }

        /* Hai bảng trong hộp chi tiết: chia cột theo %, vừa khít hộp, không cuộn ngang;
           ô dài cắt "…" (ghi chú có title). Tiêu đề màu #c4c9d7 theo ý chủ tiệm. */
        .ncc-tab-wrap { overflow-x: auto; }
        .ncc-tab-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 12.5px; }
        .ncc-tab-table th, .ncc-tab-table td {
            border: 1px solid #eee; padding: 6px 4px; text-align: center;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ncc-tab-table th { background: #c4c9d7; font-weight: 600; }
        .ncc-tab-table td.is-tien { text-align: right; }
        /* Lịch sử (đo thật trong hộp rộng ~1165px): mã 15 ký tự, ngày, tiền, trạng thái
           không bị cắt; chỉ ghi chú chịu cắt "…" (có title).
           STT 3.5 · Mã 13 · Người lập 8 · Chi nhánh 8 · Ngày CT 9.5 · Ngày lập 8
           · Tiền hàng 9.5 · Tổng tiền 9.5 · Trạng thái 7 · Thanh toán 9 · Còn nợ 9.5 · Ghi chú 5.5 = 100 */
        .ncc-tab-lich-su th:nth-child(1) { width: 3.5%; } .ncc-tab-lich-su th:nth-child(2) { width: 13%; }
        .ncc-tab-lich-su th:nth-child(3) { width: 8%; }   .ncc-tab-lich-su th:nth-child(4) { width: 8%; }
        .ncc-tab-lich-su th:nth-child(5) { width: 9.5%; } .ncc-tab-lich-su th:nth-child(6) { width: 8%; }
        .ncc-tab-lich-su th:nth-child(7) { width: 9.5%; } .ncc-tab-lich-su th:nth-child(8) { width: 9.5%; }
        .ncc-tab-lich-su th:nth-child(9) { width: 7%; }   .ncc-tab-lich-su th:nth-child(10) { width: 9%; }
        .ncc-tab-lich-su th:nth-child(11) { width: 9.5%; } .ncc-tab-lich-su th:nth-child(12) { width: 5.5%; }
        /* Công nợ: STT 4 · Mã 13 · Người lập 10 · Chi nhánh 10 · Ngày lập 9 · Tổng tiền 10.5
           · Đã trả 9.5 · Còn nợ 10.5 · Thanh toán 9.5 · Còn hạn 8 · Ghi chú 6 = 100 */
        .ncc-tab-cong-no th:nth-child(1) { width: 4%; }    .ncc-tab-cong-no th:nth-child(2) { width: 13%; }
        .ncc-tab-cong-no th:nth-child(3) { width: 10%; }   .ncc-tab-cong-no th:nth-child(4) { width: 10%; }
        .ncc-tab-cong-no th:nth-child(5) { width: 9%; }    .ncc-tab-cong-no th:nth-child(6) { width: 10.5%; }
        .ncc-tab-cong-no th:nth-child(7) { width: 9.5%; }  .ncc-tab-cong-no th:nth-child(8) { width: 10.5%; }
        .ncc-tab-cong-no th:nth-child(9) { width: 9.5%; }  .ncc-tab-cong-no th:nth-child(10) { width: 8%; }
        .ncc-tab-cong-no th:nth-child(11) { width: 6%; }
        /* Còn hạn: quá hạn đỏ, còn đúng hôm nay cam — nhìn lướt bảng là biết phải trả ai trước. */
        .ncc-tab-table td.qua-han { color: #ff4d4f; font-weight: 600; }
        .ncc-tab-table td.het-han-hom-nay { color: #fa8c16; font-weight: 600; }
        /* Chân tab: ô "Hiển thị N" bên trái, dãy số trang canh giữa — như chân trang danh sách. */
        .ncc-tab-chan { display: flex; align-items: center; gap: 12px; margin-top: 10px; }
        .ncc-tab-chan .select-width { width: auto; }
        .ncc-tab-pagi { flex: 1; }
        .ncc-tab-pagi nav { display: flex; justify-content: center; }
        .ncc-tab-pagi .pagination { margin: 0; }
        .ncc-tab-rong { padding: 24px 0; text-align: center; color: #8c8c8c; }
        .ncc-tien { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .ncc-tien-o { flex: 1 1 160px; border: 1px solid #eee; border-radius: 6px; padding: 8px 12px; }
        .ncc-tien-lb { display: block; font-size: 12px; color: #8c8c8c; }
        .ncc-tien-vl { font-size: 15px; font-weight: 700; }
        .ncc-tien-vl.is-no { color: #ff4d4f; }
    </style>
@endpush

@php
    $C = \App\Http\Controllers\NhaCungCapController::class;
    // Đang lọc mà bảng rỗng thì nói "không khớp bộ lọc", đừng nói "chưa có":
    // chưa có là chưa khai gì, còn khớp là khai rồi nhưng lọc không ra — hai
    // việc phải làm khác hẳn nhau. Cùng khuôn với khu cũ (resources/views/chi-nhanh).
    $hasFilter = collect($filters)->only(['keyword', 'status'])
        ->contains(fn ($v) => $v !== '' && $v !== null && $v !== 0 && $v !== [] && $v !== 'all');
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $anhMacDinh = asset('v2/images/image_defaul.png');

    // Ba cột tiền do API gộp từ phiếu mua đã duyệt. Chưa mua gì thì in 0₫ chứ
    // không để trống: ô trống đọc ra là "chưa biết", còn đây thì biết rõ là không.
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');

    // v2 để trạng thái cột trong $columns; ở đây lấy từ query để giữ được sau khi tải lại.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $columns = [];
    foreach ([
        'code', 'name', 'tax_code', 'phone', 'email', 'address', 'address_2', 'status',
        'total_purchases', 'total_payment', 'still_in_debt',
    ] as $c) {
        $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }
    $convertMessage = [
        'show_code' => 'supplier-code', 'show_name' => 'supplier-name',
        'show_tax_code' => 'tax_code', 'show_phone' => 'phone-number',
        'show_email' => 'email', 'show_address' => 'address',
        'show_address_2' => 'address_2', 'show_status' => 'status',
        'show_total_purchases' => 'total_purchases', 'show_total_payment' => 'total_payment',
        'show_still_in_debt' => 'still_in_debt',
    ];
@endphp

@section('content')
    {{-- Dãy nút mở bộ lọc, CHỈ hiện trên điện thoại: mỗi nút kéo một khối lọc
         bên dưới vào tấm offcanvas chung của khung v2. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterSearch">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterStatus">
                <p class="open-modal-label">{{ __('message.status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-toggle-on"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-supplier-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2.
             d-none d-lg-block: trên điện thoại các khối này đã đi vào offcanvas,
             để hiện cả hai chỗ là bộ lọc nằm hai nơi trong cùng một trang. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không có nút "Lọc": gõ hay đổi ô nào là lọc lại ngay. --}}
                        <form action="{{ route('admin.nha-cung-cap.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterSearch" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.search') }}</span>
                                    <div class="d-flex w-100 mt-1">
                                        <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                            class="form-control search" autocomplete="off"
                                            placeholder="{{ __('message.enter-name-or-code') }}">
                                    </div>
                                </div>
                            </div>

                            <div id="filterStatus" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.status') }}</span>
                                    <select name="status" class="form-control form-select mt-1">
                                        <option value="">{{ __('message.all') }}</option>
                                        @foreach ($C::TRANG_THAI as $ma => $ten)
                                            <option value="{{ $ma }}" {{ $filters['status'] === (string) $ma ? 'selected' : '' }}>
                                                {{ $ten }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Đổi bộ lọc là về trang 1; số dòng mỗi trang thì giữ. --}}
                            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="content_midd">
                <div class="content_midd_title">
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang, trình
                         đọc màn hình lấy nó làm mốc. Class .tieu-de-trang giữ nguyên
                         dáng chữ của h4 bên v2. --}}
                    <h1 class="tieu-de-trang">{{ __('message.supplier-management') }}</h1>
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
                                        <a class="dropdown-item" href="{{ route('admin.nha-cung-cap.mauNhap') }}">{{ __('message.download_sample_file') }}</a>
                                        <a class="dropdown-item" href="{{ route('admin.nha-cung-cap.export', request()->query()) }}">{{ __('message.export-excel') }}</a>
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

                            <form id="import-form" method="POST" action="{{ route('admin.nha-cung-cap.import') }}"
                                enctype="multipart/form-data" class="d-none">
                                @csrf
                                <input type="file" name="file" id="excel_file" accept=".xlsx,.csv"
                                    onchange="this.form.submit()">
                            </form>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-supplier none_mobile">
                            <tr>
                                <th class="text-center not-export"><input class="form-check-input item-select-all" type="checkbox"></th>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ __('message.supplier-code') }}</th>
                                <th class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">{{ __('message.supplier-name') }}</th>
                                <th class="text-left show_tax_code {{ $columns['show_tax_code'] ? '' : 'hide' }}">{{ __('message.tax_code') }}</th>
                                <th class="text-left show_phone {{ $columns['show_phone'] ? '' : 'hide' }}">{{ __('message.phone-number') }}</th>
                                <th class="text-left show_email {{ $columns['show_email'] ? '' : 'hide' }}">{{ __('message.email') }}</th>
                                <th class="text-left show_address {{ $columns['show_address'] ? '' : 'hide' }}">{{ __('message.address') }}</th>
                                <th class="text-left show_address_2 {{ $columns['show_address_2'] ? '' : 'hide' }}">{{ __('message.address_2') }}</th>
                                <th class="text-center show_status {{ $columns['show_status'] ? '' : 'hide' }}">{{ __('message.status') }}</th>
                                <th class="text-right show_total_purchases {{ $columns['show_total_purchases'] ? '' : 'hide' }}">{{ __('message.total_purchases') }}</th>
                                <th class="text-right show_total_payment {{ $columns['show_total_payment'] ? '' : 'hide' }}">{{ __('message.total_payment') }}</th>
                                <th class="text-right show_still_in_debt {{ $columns['show_still_in_debt'] ? '' : 'hide' }}">{{ __('message.still_in_debt') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($list as $i => $ncc)
                                @php
                                    $id = (int) ($ncc['id'] ?? 0);
                                    $bat = (int) ($ncc['status'] ?? 1) === $C::DANG_HOP_TAC;
                                @endphp
                                <tr class="item not-export" data-id="{{ $id }}">
                                    <td class="text-center item-select not-export">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    </td>
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    <td class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }} item-code">{{ $ncc['code'] ?? '' }}</td>
                                    <td class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }} item-name" title="{{ $ncc['name'] ?? '' }}">{{ $ncc['name'] ?? '' }}</td>
                                    <td class="text-left show_tax_code {{ $columns['show_tax_code'] ? '' : 'hide' }} item-tax">{{ $ncc['tax_code'] ?? '' }}</td>
                                    <td class="text-left show_phone {{ $columns['show_phone'] ? '' : 'hide' }} item-phone">{{ $ncc['phone'] ?? '' }}</td>
                                    <td class="text-left show_email {{ $columns['show_email'] ? '' : 'hide' }} item-email" title="{{ $ncc['email'] ?? '' }}">{{ $ncc['email'] ?? '' }}</td>
                                    <td class="text-left show_address {{ $columns['show_address'] ? '' : 'hide' }} item-address" title="{{ $ncc['address'] ?? '' }}">{{ $ncc['address'] ?? '' }}</td>
                                    <td class="text-left show_address_2 {{ $columns['show_address_2'] ? '' : 'hide' }}" title="{{ $ncc['address_line2'] ?? '' }}">{{ $ncc['address_line2'] ?? '' }}</td>
                                    <td class="text-center show_status {{ $columns['show_status'] ? '' : 'hide' }}">
                                        <input type="checkbox" class="switch_customer item-status" data-id="{{ $id }}"
                                            {{ $bat ? 'checked' : '' }}>
                                    </td>
                                    <td class="text-right show_total_purchases {{ $columns['show_total_purchases'] ? '' : 'hide' }}">{{ $tien($ncc['total_purchases'] ?? 0) }}</td>
                                    <td class="text-right show_total_payment {{ $columns['show_total_payment'] ? '' : 'hide' }}">{{ $tien($ncc['total_payment'] ?? 0) }}</td>
                                    <td class="text-right show_still_in_debt {{ $columns['show_still_in_debt'] ? '' : 'hide' }} ncc-con-no">{{ $tien($ncc['still_in_debt'] ?? 0) }}</td>
                                    <td class="text-center action not-export">
                                        <a class="detail-item" type="button" title="{{ __('message.detail') }}"><i class="fa fa-eye"></i></a>
                                        <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                        <a class="copy-item" type="button" title="Nhân bản"><i class="fa fa-copy"></i></a>
                                        <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="text-center py-4">{{ $hasFilter ? 'Không có nhà cung cấp nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}</td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai. --}}
                        <div class="table-supplier none_desktop">
                            @foreach ($list as $ncc)
                                <div class="item" data-id="{{ (int) ($ncc['id'] ?? 0) }}">
                                    <div class="d-flex align-items-center w-100 justify-content-between">
                                        <div class="form-check me-2">
                                            <input class="form-check-input item-select" type="checkbox" value="{{ (int) ($ncc['id'] ?? 0) }}">
                                        </div>
                                        <div class="d-flex flex-column flex-grow-1 detail-item" role="button">
                                            <span class="fw-semibold">{{ $ncc['name'] ?? '' }}</span>
                                            <span style="font-size: 14px">{{ $ncc['code'] ?? '' }}</span>
                                        </div>
                                        <div class="d-flex gap-2">{{ $ncc['phone'] ?? '' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dữ liệu đầy đủ của từng dòng, ĐẶT TRONG khối được thay khi nạp
                         lại. Để ở ngoài thì lọc bằng AJAX xong bảng là bảng mới mà hộp
                         Sửa / Chi tiết vẫn đọc dữ liệu của lượt tải đầu tiên. --}}
                    <script type="application/json" id="v2-rows">@json(collect($list)->keyBy('id'))</script>

                    {{-- Phân trang dựng đúng khuôn bootstrap-4 mà bản v2 in ra. --}}
                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width" data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
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

                        <div class="row wrapper-modalCrUd">
                            <div class="col-sm-3 d-flex align-items-start justify-content-center">
                                <div class="img_st w-100">
                                    <label>{{ __('message.image') }}</label>
                                    <div class="d-flex justify-content-center">
                                        <div class="pic_add">
                                            <img id="img-preview" class="mx-auto" src="{{ $anhMacDinh }}">
                                        </div>
                                    </div>

                                    <div class="upload_pic">
                                        {{ __('message.upload') }}
                                        <input type="file" class="ip_img" accept="image/*">
                                    </div>

                                    <div class="mt-2 text-center">
                                        <label class="form-label d-block">{{ __('message.status') }}</label>
                                        <input type="checkbox" class="switch_customer ip_status" checked>
                                    </div>
                                </div>
                            </div>

                            <div class="col-sm-9 ps-sm-0 row mx-auto" style="color: #212529;">
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.supplier-code') }}</label>
                                    <input type="text" class="form-control ip_code" maxlength="30"
                                        placeholder="{{ __('message.auto-increment-code') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.supplier-name') }} <span style="color:red">*</span></label>
                                    <input type="text" class="form-control ip_name" maxlength="150"
                                        placeholder="{{ __('message.supplier-name') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.short_name') }}</label>
                                    <input type="text" class="form-control ip_short_name" maxlength="100"
                                        placeholder="{{ __('message.short_name') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.phone') }}</label>
                                    <input type="text" class="form-control ip_phone" maxlength="20"
                                        placeholder="{{ __('message.phone') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.email') }}</label>
                                    <input type="email" class="form-control ip_email" maxlength="191"
                                        placeholder="{{ __('message.email') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.tax_code') }}</label>
                                    <input type="text" class="form-control ip_tax_code" maxlength="30"
                                        placeholder="{{ __('message.tax_code') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.representative_info') }}</label>
                                    <input type="text" class="form-control ip_representative_name" maxlength="150"
                                        placeholder="{{ __('message.representative_info') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.representative_phone') }}</label>
                                    <input type="text" class="form-control ip_representative_phone" maxlength="20"
                                        placeholder="{{ __('message.representative_phone') }}">
                                </div>

                                <div class="col-12 mt-3">
                                    <label class="form-label d-block">{{ __('message.address') }} <span style="color:red">*</span></label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_address" maxlength="255"
                                            placeholder="{{ __('message.address') }}" style="height: 70px"></textarea>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <label class="form-label d-block">{{ __('message.address_2') }}</label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_address_line2" maxlength="200"
                                            placeholder="{{ __('message.address_2') }}" style="height: 70px"></textarea>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <label class="form-label d-block">{{ __('message.note') }}</label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_note" maxlength="500"
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

    {{-- ===================== Hộp Chi tiết — ba tab như bản v2 ===================== --}}
    <div class="modal" id="modalDetail">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl mx-auto" style="max-width: 80%">
            {{-- BỎ `h-100`: nó ép thân hộp cao bằng cả màn hình, nên hộp ít ô vẫn
                 hiện thanh cuộn và chừa một khoảng trống lớn phía dưới. Không có
                 nó thì hộp co đúng theo nội dung, còn `modal-dialog-scrollable`
                 vẫn lo phần cuộn khi nội dung thật sự dài quá màn. --}}
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.detail_info') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-2">
                    <div class="col-md-12 border">
                        <ul class="nav nav-tabs nav-detail" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#detail-supplier"
                                    type="button" role="tab">{{ __('message.detail') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#transaction-history"
                                    type="button" role="tab">{{ __('message.transaction-history') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#debt-history"
                                    type="button" role="tab">{{ __('message.debt') }}</button>
                            </li>
                        </ul>

                        <div class="tab-content p-3">
                            <div class="tab-pane fade show active" id="detail-supplier" role="tabpanel">
                                <div class="row">
                                    <div class="col-sm-3 d-flex align-items-start justify-content-center">
                                        <div class="img_st w-100">
                                            <label>{{ __('message.image') }}</label>
                                            <div class="d-flex justify-content-center">
                                                <div class="pic_add">
                                                    <img id="img-preview-detail" class="mx-auto" src="{{ $anhMacDinh }}">
                                                </div>
                                            </div>
                                            <div class="mt-2 text-center">
                                                <label class="form-label d-block">{{ __('message.status') }}</label>
                                                <input disabled type="checkbox" class="switch_customer ip_status" checked>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-sm-9" style="color: #212529;">
                                        <input type="hidden" class="detail_id">
                                        <div class="row mt-3">
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.supplier-code') }}</label>
                                                <input disabled type="text" class="form-control ip_code">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.supplier-name') }}</label>
                                                <input disabled type="text" class="form-control ip_name">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.short_name') }}</label>
                                                <input disabled type="text" class="form-control ip_short_name">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.phone') }}</label>
                                                <input disabled type="text" class="form-control ip_phone">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.email') }}</label>
                                                <input disabled type="text" class="form-control ip_email">
                                            </div>
                                            <div class="col-12 col-md-6 col-xl-4 mb-2">
                                                <label class="form-label">{{ __('message.tax_code') }}</label>
                                                <input disabled type="text" class="form-control ip_tax_code">
                                            </div>
                                        </div>

                                        <div class="row mt-3">
                                            <div class="col-12 col-sm-6 col-xl-4">
                                                <label class="form-label">{{ __('message.representative_info') }}</label>
                                                <input disabled type="text" class="form-control ip_representative_name">
                                            </div>
                                            <div class="col-12 col-sm-6 col-xl-4">
                                                <label class="form-label">{{ __('message.representative_phone') }}</label>
                                                <input disabled type="text" class="form-control ip_representative_phone">
                                            </div>
                                            <div class="col-12 col-sm-6 col-xl-4">
                                                <label class="form-label">{{ __('message.creation-date') }}</label>
                                                <input disabled type="text" class="form-control ip_created_at">
                                            </div>
                                        </div>

                                        <div class="row mt-3">
                                            <div class="col-6">
                                                <label class="form-label">{{ __('message.debt') }}</label>
                                                <input disabled type="text" class="form-control ip_total_debt">
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label">{{ __('message.total_purchases') }}</label>
                                                <input disabled type="text" class="form-control ip_total_purchases">
                                            </div>
                                        </div>

                                        <div class="row mt-3">
                                            <label class="form-label d-block">{{ __('message.address') }}</label>
                                            <div class="box-textarea-cus">
                                                <textarea disabled class="form-control ip_address" style="height: 70px"></textarea>
                                            </div>
                                        </div>
                                        <div class="row mt-3">
                                            <label class="form-label d-block">{{ __('message.address_2') }}</label>
                                            <div class="box-textarea-cus">
                                                <textarea disabled class="form-control ip_address_line2" style="height: 70px"></textarea>
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

                            {{-- Hai tab dưới đọc từ máy chủ theo bộ lọc + trang, không lọc tại chỗ.
                                 Ô "Hiển thị N" và nút Xuất Excel là của riêng từng tab. --}}
                            <div class="tab-pane fade" id="transaction-history" role="tabpanel">
                                <div class="d-flex flex-wrap align-items-center gap-2 filter-supplier-container mb-3">
                                    <div class="input-group" style="max-width: 250px">
                                        <input type="text" class="form-control" id="search_purchase"
                                            placeholder="{{ __('message.enter_search_info') }}">
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
                                    <a class="btn btn-sm d-flex align-items-center btn-export ncc-tab-xuat ms-auto" data-tab="lich-su" href="#">
                                        <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export-excel') }}
                                    </a>
                                </div>
                                <div class="col-md-12" id="list-purchase-order"></div>
                                <div class="ncc-tab-chan">
                                    <select class="form-control select-width ncc-tab-size" data-tab="lich-su">
                                        @foreach ($C::MUC_SO_DONG as $muc)
                                            <option value="{{ $muc }}">{{ __('message.display', ['name' => $muc]) }}</option>
                                        @endforeach
                                    </select>
                                    <div class="ncc-tab-pagi" data-tab="lich-su" id="pagi-purchase-order"></div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="debt-history" role="tabpanel">
                                <div class="ncc-tien" id="debt-summary"></div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                    <div class="input-group" style="max-width: 250px">
                                        <input type="text" class="form-control" id="search_debt"
                                            placeholder="{{ __('message.enter_search_info') }}">
                                        <button class="btn seach-item" type="button">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                        </button>
                                    </div>
                                    <a class="btn btn-sm d-flex align-items-center btn-export ncc-tab-xuat ms-auto" data-tab="cong-no" href="#">
                                        <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export-excel') }}
                                    </a>
                                </div>
                                <div class="col-md-12" id="list-debt"></div>
                                <div class="ncc-tab-chan">
                                    <select class="form-control select-width ncc-tab-size" data-tab="cong-no">
                                        @foreach ($C::MUC_SO_DONG as $muc)
                                            <option value="{{ $muc }}">{{ __('message.display', ['name' => $muc]) }}</option>
                                        @endforeach
                                    </select>
                                    <div class="ncc-tab-pagi" data-tab="cong-no" id="pagi-debt"></div>
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
        const URL_BASE = @json(url('/admin/suppliers'));
        const URL_STORE = @json(route('admin.nha-cung-cap.store'));
        const URL_BULK_DEL = @json(route('admin.nha-cung-cap.bulkDestroy'));
        const URL_ANH = @json(route('admin.nha-cung-cap.anh'));
        const ANH_MAC_DINH = @json($anhMacDinh);

        // Cả bản ghi của từng dòng — hộp Sửa / Chi tiết đọc thẳng ở đây, khỏi rải
        // hơn chục data-* lên mỗi <tr> như bản v2. Đọc lại sau mỗi lượt nạp danh
        // sách bằng AJAX, không thì dữ liệu là của bảng đã bị thay đi.
        let NCC = docDongHienCo();

        function docDongHienCo() {
            try {
                return JSON.parse(document.getElementById('v2-rows').textContent) || {};
            } catch (e) {
                return {};
            }
        }

        $(document).on('v2:da-nap', function () { NCC = docDongHienCo(); });

        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const tien = (v) => (Number(v) || 0).toLocaleString('vi-VN') + '₫';
        const ngay = (s) => (s ? String(s).slice(0, 10).split('-').reverse().join('/') : '');

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
        // Cột đang tắt ghi vào ?hide= rồi tải lại, để giữ sau khi đổi trang.
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
        //
        // Tự dựng URL thay vì submit form, vì hai lẽ:
        //   - trên điện thoại khung v2 BƯNG khối lọc sang tấm offcanvas, và mỗi
        //     lượt chỉ bưng MỘT khối, nên submit lúc đó sẽ đánh rơi hai ô còn lại;
        //   - lựa chọn cột nằm ở ?hide=, không phải ô trong form, submit là mất.
        //
        // Ô lọc nằm ở đâu cũng tìm ra: khung bên trái và tấm offcanvas cùng mang
        // class .fillter-box.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai() {
            const q = new URLSearchParams();
            ['keyword', 'status'].forEach((ten) => {
                const v = String(oLoc(ten).val() || '').trim();
                if (v) q.set(ten, v);
            });

            // Mấy tham số không có ô trong khung lọc thì chép lại từ URL cũ, không
            // là đổi bộ lọc một cái là mất luôn cột đang ẩn và cỡ trang.
            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size', 'sort'].forEach((ten) => {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            // Cố ý không mang `page` theo: lọc lại thì trang 5 của bộ lọc cũ
            // không còn nghĩa gì.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerTim = null;
        $(document).on('change', '.fillter-box select[name="status"]', locLai);
        $(document).on('input', '.fillter-box input[name="keyword"]', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(locLai, 400);
        });
        $(document).on('submit', '#search-form', function (e) {
            e.preventDefault();
            locLai();
        });

        // Công tắc hợp tác ngay tại bảng — ghi tại chỗ, không tải lại trang.
        $(document).on('change', '.item-status', function () {
            V2.ghi(URL_BASE + '/' + $(this).data('id') + '/status', 'PUT', { status: this.checked ? 1 : 0 });
        });

        // =====================================================================
        //  Hộp Thêm / Sửa
        // =====================================================================
        const $crud = $('#modalCrUd');

        function xoaTrangCrUd() {
            $crud.find('input[type=text], input[type=email], textarea').val('');
            $crud.find('input.id, input.ip_image').val('');
            $crud.find('input.ip_img').val('');
            $crud.find('input.ip_status').prop('checked', true);
            $crud.find('#img-preview').attr('src', ANH_MAC_DINH);
        }

        /** Đổ một bản ghi vào hộp. giuMa = false là nhân bản — mã để trống cho API tự sinh. */
        function doVaoCrUd(s, giuMa) {
            $crud.find('input.ip_code').val(giuMa ? (s.code || '') : '');
            $crud.find('input.ip_name').val(s.name || '');
            $crud.find('input.ip_short_name').val(s.short_name || '');
            $crud.find('input.ip_phone').val(s.phone || '');
            $crud.find('input.ip_email').val(s.email || '');
            $crud.find('input.ip_tax_code').val(s.tax_code || '');
            $crud.find('input.ip_representative_name').val(s.representative_name || '');
            $crud.find('input.ip_representative_phone').val(s.representative_phone || '');
            $crud.find('textarea.ip_address').val(s.address || '');
            $crud.find('textarea.ip_address_line2').val(s.address_line2 || '');
            $crud.find('textarea.ip_note').val(s.note || '');
            $crud.find('input.ip_status').prop('checked', Number(s.status) === 1);
            $crud.find('input.ip_image').val(s.image || '');
            $crud.find('#img-preview').attr('src', s.image || ANH_MAC_DINH);
        }

        function moSua(s) {
            xoaTrangCrUd();
            $crud.find('input.id').val(s.id);
            $crud.find('.modal-title').text('{{ __('message.edit') }}');
            doVaoCrUd(s, true);
            $crud.modal('show');
        }

        const cuaDong = (el) => NCC[$(el).closest('.item').data('id')];

        $(document).on('click', '.add-item', function () {
            xoaTrangCrUd();
            $crud.find('.modal-title').text('{{ __('message.create') }}');
            $crud.modal('show');
        });

        $(document).on('click', '.edit-item', function () {
            const s = cuaDong(this);
            if (s) moSua(s);
        });

        $(document).on('click', '.copy-item', function () {
            const s = cuaDong(this);
            if (!s) return;
            xoaTrangCrUd();
            $crud.find('.modal-title').text('{{ __('message.create') }}');
            doVaoCrUd(s, false);
            // Thêm chữ "copy" vào tên: hai dòng trùng tên y hệt thì lúc chọn bên
            // bán trong phiếu mua không biết đâu là đâu.
            $crud.find('input.ip_name').val(((s.name || '') + ' copy').trim());
            $crud.modal('show');
        });

        // Ảnh tải lên ngay lúc chọn; form chỉ mang theo đường dẫn.
        $(document).on('change', '#modalCrUd .ip_img', function () {
            const f = this.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('anh', f);
            fd.append('_token', CSRF);
            $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((r) => {
                    $crud.find('input.ip_image').val(r.url);
                    $crud.find('#img-preview').attr('src', r.url);
                })
                .fail((x) => toastr.error((x.responseJSON && x.responseJSON.message) || 'Không tải được ảnh lên.'));
        });

        $(document).on('click', '#modalCrUd .save-item', function () {
            const id = $crud.find('input.id').val();
            const ten = $crud.find('input.ip_name').val().trim();
            const diaChi = $crud.find('textarea.ip_address').val().trim();
            if (!ten) { toastr.error('Chưa nhập tên nhà cung cấp.'); return; }
            if (!diaChi) { toastr.error('Chưa nhập địa chỉ.'); return; }

            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI cho người dùng sửa (VD trùng
            // tên), thay vì tải lại trang làm mất sạch cả form dài này.
            V2.luuHop($crud.closest('.modal'), id ? URL_BASE + '/' + id : URL_STORE,
                id ? 'PUT' : 'POST', {
                code: $crud.find('input.ip_code').val().trim(),
                name: ten,
                short_name: $crud.find('input.ip_short_name').val().trim(),
                phone: $crud.find('input.ip_phone').val().trim(),
                email: $crud.find('input.ip_email').val().trim(),
                tax_code: $crud.find('input.ip_tax_code').val().trim(),
                representative_name: $crud.find('input.ip_representative_name').val().trim(),
                representative_phone: $crud.find('input.ip_representative_phone').val().trim(),
                address: diaChi,
                address_line2: $crud.find('textarea.ip_address_line2').val().trim(),
                note: $crud.find('textarea.ip_note').val().trim(),
                image: $crud.find('input.ip_image').val(),
                status: $crud.find('input.ip_status').is(':checked') ? 1 : 0,
            }, $(this));
        });

        // =====================================================================
        //  Hộp Chi tiết
        // =====================================================================
        const $detail = $('#modalDetail');
        let dangXem = null;

        $(document).on('click', '.detail-item', function () {
            const s = cuaDong(this);
            if (!s) return;
            dangXem = s;

            $detail.find('.detail_id').val(s.id);
            $detail.find('#img-preview-detail').attr('src', s.image || ANH_MAC_DINH);
            $detail.find('input.ip_status').prop('checked', Number(s.status) === 1);
            $detail.find('input.ip_code').val(s.code || '');
            $detail.find('input.ip_name').val(s.name || '');
            $detail.find('input.ip_short_name').val(s.short_name || '');
            $detail.find('input.ip_phone').val(s.phone || '');
            $detail.find('input.ip_email').val(s.email || '');
            $detail.find('input.ip_tax_code').val(s.tax_code || '');
            $detail.find('input.ip_representative_name').val(s.representative_name || '');
            $detail.find('input.ip_representative_phone').val(s.representative_phone || '');
            $detail.find('input.ip_created_at').val(ngay(s.created_at));
            $detail.find('textarea.ip_address').val(s.address || '');
            $detail.find('textarea.ip_address_line2').val(s.address_line2 || '');
            $detail.find('textarea.ip_note').val(s.note || '');
            // Hai ô tiền lấy con số API đã gộp — đúng với mọi số phiếu, và hiện
            // ngay chứ không phải chờ lượt đọc phiếu bên dưới.
            $detail.find('input.ip_total_purchases').val(tien(s.total_purchases));
            $detail.find('input.ip_total_debt').val(tien(s.still_in_debt));

            // Về trạng thái đầu cho bên vừa mở — không thì thấy bộ lọc và trang của
            // người trước. Lịch sử lọc sẵn THÁNG NÀY (quy tắc chung của mọi sổ chứng
            // từ); công nợ không lọc ngày.
            $('#search_purchase, #search_debt').val('');
            $('#from-date').val(moment().startOf('month').format('DD-MM-YYYY'));
            $('#to-date').val(moment().format('DD-MM-YYYY'));
            Object.values(TAB).forEach((t) => { t.page = 1; });
            $detail.find('.nav-detail .nav-link').first().tab('show');

            // Ba ô tổng lấy con số API đã gộp trên TOÀN BỘ phiếu, không cộng lại
            // từ bảng dưới — bảng chỉ là một trang.
            $('#debt-summary').html(`
                <div class="ncc-tien-o"><span class="ncc-tien-lb">Tổng mua</span>
                    <span class="ncc-tien-vl">${tien(s.total_purchases)}</span></div>
                <div class="ncc-tien-o"><span class="ncc-tien-lb">Đã trả</span>
                    <span class="ncc-tien-vl">${tien(s.total_payment)}</span></div>
                <div class="ncc-tien-o"><span class="ncc-tien-lb">Còn nợ</span>
                    <span class="ncc-tien-vl is-no">${tien(s.still_in_debt)}</span></div>`);

            $detail.modal('show');
            napTab('lich-su');
            napTab('cong-no');
        });

        $(document).on('click', '#modalDetail .detail-edit', function () {
            if (!dangXem) return;
            $detail.modal('hide');
            moSua(dangXem);
        });

        const NHAN_TRA = { unpaid: 'Chưa trả', partial: 'Trả một phần', paid: 'Đã trả đủ' };
        const NHAN_PHIEU = { draft: 'Lưu tạm', approved: 'Đã duyệt', cancelled: 'Đã huỷ' };

        // Hai tab, mỗi tab giữ trang đang xem và số thứ tự lượt gọi của mình.
        // `luot` để lượt trả lời chậm không đè lên lượt mới hơn: gõ nhanh ba chữ
        // là ba lượt gọi, mà lượt về sau cùng chưa chắc là lượt gửi sau cùng.
        const TAB = {
            'lich-su': { list: '#list-purchase-order', pagi: '#pagi-purchase-order', page: 1, luot: 0 },
            'cong-no': { list: '#list-debt', pagi: '#pagi-debt', page: 1, luot: 0 },
        };

        /** Bộ lọc đang bật của một tab. Ngày luôn gửi (kể cả rỗng): rỗng = bỏ lọc. */
        function locCuaTab(tab) {
            const q = { tab, page: TAB[tab].page, page_size: $(`.ncc-tab-size[data-tab="${tab}"]`).val() || 10 };
            if (tab === 'lich-su') {
                q.keyword = $('#search_purchase').val().trim();
                q.from_date = $('#from-date').val();
                q.to_date = $('#to-date').val();
            } else {
                q.keyword = $('#search_debt').val().trim();
            }
            return q;
        }

        function napTab(tab) {
            if (!dangXem) return;
            const t = TAB[tab];
            const q = locCuaTab(tab);
            $(t.list).html('<p class="ncc-tab-rong">Đang đọc…</p>');
            $(t.pagi).empty();
            // Nút Xuất Excel đi theo đúng bộ lọc đang bật.
            $(`.ncc-tab-xuat[data-tab="${tab}"]`).attr('href', URL_BASE + '/' + dangXem.id + '/purchase-orders/export?' + $.param(q));

            const luot = ++t.luot;
            $.getJSON(URL_BASE + '/' + dangXem.id + '/purchase-orders', q)
                .done((r) => { if (luot === t.luot) veTab(tab, r.data || [], r.meta || {}); })
                .fail((x) => {
                    if (luot !== t.luot) return;
                    $(t.list).html('<p class="ncc-tab-rong">'
                        + esc((x.responseJSON && x.responseJSON.message) || 'Không đọc được phiếu mua.') + '</p>');
                });
        }

        /** Còn mấy ngày tới hạn trả (theo `debt_due_date` ghi lúc lập phiếu).
         *  Phiếu không ghi hạn thì để TRỐNG — chủ tiệm chốt vậy, không "—". */
        function conHan(p) {
            if (!p.debt_due_date) return { chu: '', lop: '', title: '' };
            const han = moment(String(p.debt_due_date).slice(0, 10), 'YYYY-MM-DD');
            const ngayCon = han.diff(moment().startOf('day'), 'days');
            const title = 'Hạn thanh toán: ' + han.format('DD/MM/YYYY');
            if (ngayCon < 0) return { chu: 'Quá ' + (-ngayCon) + ' ngày', lop: 'qua-han', title };
            if (ngayCon === 0) return { chu: 'Hôm nay', lop: 'het-han-hom-nay', title };
            return { chu: ngayCon + ' ngày', lop: '', title };
        }

        function veTab(tab, rows, meta) {
            const t = TAB[tab];
            const stt0 = ((meta.page || 1) - 1) * (meta.page_size || rows.length);
            const con = (p) => Math.max(0, Number(p.total_amount || 0) - Number(p.paid_amount || 0));

            if (tab === 'lich-su') {
                $(t.list).html(rows.length ? `
                    <div class="ncc-tab-wrap"><table class="ncc-tab-table ncc-tab-lich-su">
                        <thead><tr>
                            <th>STT</th><th>Mã phiếu</th><th>Người lập</th><th>Chi nhánh</th><th>Ngày chứng từ</th>
                            <th>Ngày lập</th><th>Tiền hàng</th><th>Tổng tiền</th>
                            <th>Trạng thái</th><th>Thanh toán</th><th>Còn nợ</th><th>Ghi chú</th>
                        </tr></thead>
                        <tbody>${rows.map((p, i) => `<tr>
                            <td>${stt0 + i + 1}</td>
                            <td>${esc(p.po_code || '')}</td>
                            <td>${esc(p.created_by_name || '')}</td>
                            <td>${esc(p.shop_name || '')}</td>
                            <td>${ngay(p.document_date)}</td>
                            <td>${ngay(p.created_at)}</td>
                            <td class="is-tien">${tien(p.items_amount)}</td>
                            <td class="is-tien"><b>${tien(p.total_amount)}</b></td>
                            <td>${esc(NHAN_PHIEU[p.status] || p.status || '')}</td>
                            <td>${p.status === 'cancelled' ? '' : esc(NHAN_TRA[p.payment_status] || '')}</td>
                            <td class="is-tien">${p.status === 'approved' && con(p) > 0 ? tien(con(p)) : ''}</td>
                            <td title="${esc(p.note || '')}">${esc(p.note || '')}</td>
                        </tr>`).join('')}</tbody>
                    </table></div>`
                    : '<p class="ncc-tab-rong">Bên này chưa có phiếu mua nào khớp bộ lọc.</p>');
            } else {
                $(t.list).html(rows.length ? `
                    <div class="ncc-tab-wrap"><table class="ncc-tab-table ncc-tab-cong-no">
                        <thead><tr>
                            <th>STT</th><th>Mã phiếu</th><th>Người lập</th><th>Chi nhánh</th><th>Ngày lập</th>
                            <th>Tổng tiền</th><th>Đã trả</th><th>Còn nợ</th><th>Thanh toán</th><th>Còn hạn</th><th>Ghi chú</th>
                        </tr></thead>
                        <tbody>${rows.map((p, i) => { const h = conHan(p); return `<tr>
                            <td>${stt0 + i + 1}</td>
                            <td>${esc(p.po_code || '')}</td>
                            <td>${esc(p.created_by_name || '')}</td>
                            <td>${esc(p.shop_name || '')}</td>
                            <td>${ngay(p.created_at)}</td>
                            <td class="is-tien">${tien(p.total_amount)}</td>
                            <td class="is-tien">${tien(p.paid_amount)}</td>
                            <td class="is-tien"><b>${tien(con(p))}</b></td>
                            <td>${esc(NHAN_TRA[p.payment_status] || '')}</td>
                            <td class="${h.lop}" title="${esc(h.title)}">${esc(h.chu)}</td>
                            <td title="${esc(p.note || '')}">${esc(p.note || '')}</td>
                        </tr>`; }).join('')}</tbody>
                    </table></div>`
                    : '<p class="ncc-tab-rong">Bên này không còn khoản nợ nào.</p>');
            }

            $(t.pagi).html(vePhanTrang(meta.page || 1, meta.total_pages || 1));
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

        $(document).on('click', '.ncc-tab-pagi a.page-link', function (e) {
            e.preventDefault();
            const tab = $(this).closest('.ncc-tab-pagi').data('tab');
            TAB[tab].page = Number($(this).data('page')) || 1;
            napTab(tab);
        });

        // Lọc realtime: gõ xong 300ms mới hỏi máy chủ, và về trang 1 vì bộ lọc đã đổi.
        const hen = {};
        function locLaiTab(tab) {
            TAB[tab].page = 1;
            clearTimeout(hen[tab]);
            hen[tab] = setTimeout(() => napTab(tab), 300);
        }
        $(document).on('input', '#search_purchase, #from-date, #to-date', () => locLaiTab('lich-su'));
        $(document).on('input', '#search_debt', () => locLaiTab('cong-no'));
        $(document).on('change', '.ncc-tab-size', function () { locLaiTab($(this).data('tab')); });

        // Hai ô ngày dùng lịch một ngày của v2, khuôn DD-MM-YYYY.
        $('#from-date, #to-date').each(function () {
            $(this).daterangepicker({
                singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
                locale: V2.lichVN(),
            }, function (start) {
                $(this.element).val(start.format('DD-MM-YYYY'));
                locLaiTab('lich-su');
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
