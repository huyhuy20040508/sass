{{-- Màn Phiếu điều chuyển dựng theo khuôn v2 (purchase/transfer-slip:
     index + list, hộp lập/sửa/xem gộp vào một modal).
     Dữ liệu do PhieuDieuChuyenController đẩy sang: $list, $filters, $meta,
     $chiNhanh, $nhanVien, $chiNhanhDangLam. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\PhieuDieuChuyenController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\PhieuDieuChuyenController::class;
    $stt = ($meta['page'] - 1) * $meta['page_size'];

    $ngay = function ($v) {
        $t = $v ? strtotime($v) : false;

        return $t ? date('d-m-Y', $t) : '';
    };

    $trangThaiChon = array_filter(explode(',', $filters['status']));

    // Bản v2 xếp "Lưu tạm" trước "Đã được duyệt" trong khối lọc — giữ đúng thứ tự.
    $thuTuLoc = ['draft', 'approved'];
@endphp

@push('styles')
    <style>
        /* Lấy khối style của purchase/transfer-slip bản v2, bỏ phần khung v2 đã lo sẵn. */
        body { overflow-x: hidden }
        li { list-style-type: none; }
        .select2-container { width: 100% !important; }
        /* Select2 trong hộp thoại phải nổi trên lớp phủ của modal. */
        #modalCreate .select2-container--open, #modalCreate .select2-dropdown { z-index: 1065 !important; }
        #content_create label.form-label { text-align: left !important }
        .table-lines td, .table-lines th { vertical-align: middle; }

        /* ============ HỘP LẬP / SỬA PHIẾU ============
           Bản v2 của màn này xếp thông tin phiếu thành BỐN cột `col-md-3`, mỗi ô
           là nhãn nằm TRÊN ô nhập — giống màn Phiếu mua hàng, khác màn Trả hàng
           hai cột. Giữ đúng hình đó: nhãn cao 20px, ô nhập cao 34px, nên dòng thứ
           n của bốn cột luôn nằm đúng một hàng. */
        #content_create { --pdc-nhan: 20px; --pdc-o: 34px; }

        /* Thanh nút lưu trên cùng hộp thoại. */
        .pdc-thanh {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px; flex-wrap: wrap;
            padding: 8px 0; border-bottom: 1px solid #e9ecef; margin-bottom: 16px;
        }
        /* margin-left:auto chứ không trông vào `space-between`: lập mới thì thẻ mã
           phiếu rỗng nên ẩn, còn mỗi cụm nút, và `space-between` với một phần tử
           sẽ đẩy nó về TRÁI. */
        .pdc-thanh-nut { display: flex; gap: 8px; margin-left: auto; }
        .pdc-thanh-nut .bt { margin: 0; }
        .pdc-thanh-nut .bt i { margin-right: 4px; }
        .pdc-ma-phieu { margin: 0; font-size: 16px; font-weight: 600; color: #1a2b58; }
        .pdc-ma-phieu:empty { display: none; }
        /* style.css của vỏ v2 chỉ có luật :hover cho .btn-print, thiếu màu lúc
           thường nên nút In ra trắng trơn. Bù đúng tông xanh của v2. */
        .pdc-thanh-nut .btn-print {
            background: #026b97; border: 1px solid #026b97; color: #fff;
        }
        /* Vỏ v2 chỉ đặt `div.btn_top_content { display: flex }`, không có gap. */
        .btn_top_content { gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn_top_content > * { margin: 0; }

        /* Bốn cột thông tin phiếu — bốn `col-md-3` của v2, máng lưới 20px. */
        .pdc-form {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0 20px; margin-bottom: 8px;
        }
        .pdc-cot { min-width: 0; }

        /* MỘT ô: nhãn một dòng cao cố định + ô nhập cao cố định. Nhãn dài thì cắt
           bằng "…" chứ KHÔNG xuống dòng — xuống dòng là cột đó cao hơn ba cột kia
           và cả hàng lệch theo. */
        .pdc-o { margin-bottom: 14px; }
        .pdc-o > .form-label {
            display: block; height: var(--pdc-nhan); line-height: var(--pdc-nhan);
            margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #1a2b58;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .pdc-o .form-control, .pdc-o .form-select,
        .pdc-o .select2-container--default .select2-selection--single {
            height: var(--pdc-o); min-height: var(--pdc-o);
        }
        .pdc-o .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(var(--pdc-o) - 2px);
        }
        .pdc-o .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(var(--pdc-o) - 2px);
        }
        /* Ô khoá: tô nền xám cho thấy ngay là chỉ để đọc. CSS của v2 ép màu chữ
           mọi input nên chỉ dựa vào thuộc tính disabled thì nhìn y hệt ô gõ được. */
        .pdc-o .form-control:disabled, .pdc-o .form-control[readonly] {
            background-color: #f4f6f8; color: #6c757d !important;
        }

        /* Ô ghi chú chiếm cả bề ngang, có bộ đếm ký tự ở góc — đúng
           `box-textarea-cus` của v2. */
        .pdc-rong { grid-column: 1 / -1; }
        .pdc-textarea { position: relative; }
        .pdc-textarea .form-control { resize: vertical; min-height: 58px; }
        .pdc-dem-chu {
            position: absolute; right: 8px; bottom: 6px;
            font-size: 11px; color: #adb5bd; pointer-events: none;
        }

        @media (max-width: 1199px) {
            .pdc-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 767px) {
            .pdc-form { grid-template-columns: minmax(0, 1fr); }
        }

        /* Tiêu đề khối hàng hoá + ô tìm hàng đứng cùng một hàng. */
        .content_midd_title.pdc-thanh-hang {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px; flex-wrap: wrap; margin-top: 8px;
        }
        .content_midd_title.pdc-thanh-hang > h4 { margin: 0; }
        /* custom.css của vỏ v2 giấu `div.content_midd_title h4` dưới 992px — luật
           dành cho tiêu đề TRANG, nhưng nó ăn luôn tiêu đề khối hàng hoá trong hộp
           thoại. Giành lại bằng độ ưu tiên của #modalCreate. */
        #modalCreate .content_midd_title h4 { display: block; }

        /* `min-width: 0` là chỗ mấu chốt: phần tử của flex mặc định không co xuống
           dưới bề rộng nội dung, mà "nội dung" của ô chọn là dòng option dài nhất —
           nên nó đội thân hộp thoại rộng ra trên màn hẹp. */
        .pdc-hang-cong-cu { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
        .pdc-hang-cong-cu > * { min-width: 0; }
        /* `:not(.select2-hidden-accessible)` là bắt buộc: select2 giấu thẻ <select>
           gốc bằng lớp ấy, mà luật ở đây ưu tiên cao hơn nên sẽ thổi cái ô VÔ HÌNH
           đó phồng trở lại và đẩy hộp thoại cuộn ngang. */
        .pdc-hang-cong-cu .select-menus:not(.select2-hidden-accessible) {
            width: 320px; flex: 0 1 320px; max-width: 100%;
        }
        .pdc-hang-cong-cu .select2-container { width: 320px !important; flex: 0 1 320px; max-width: 100%; }
        .pdc-hang-cong-cu .form-control, .pdc-hang-cong-cu .bt,
        .pdc-hang-cong-cu .select2-container--default .select2-selection--single {
            height: var(--pdc-o); min-height: var(--pdc-o);
        }
        .pdc-hang-cong-cu .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(var(--pdc-o) - 2px);
        }
        .pdc-hang-cong-cu .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(var(--pdc-o) - 2px);
        }
        .pdc-hang-cong-cu .bt { display: inline-flex; align-items: center; margin: 0; }
        @media (max-width: 767px) {
            .pdc-hang-cong-cu { width: 100%; }
            .pdc-hang-cong-cu .select-menus:not(.select2-hidden-accessible),
            .pdc-hang-cong-cu .select2-container { width: 100% !important; }
        }

        /* Lưới hàng KHÔNG cuộn ngang trên máy tính — xem bề rộng cột ở dưới.
           Dưới 992px thì 11 cột không thể nào vừa, nên chỗ đó mới cho cuộn. */
        .pdc-luoi { overflow-x: hidden; }

        /* ---- Canh lưới hàng ----
           style.css của v2 ép `th, td { text-align: center !important }` cho MỌI
           bảng. Với lưới nhập liệu thì hỏng: con số canh giữa không so được với
           nhau. Ba lớp dưới đây kê lại, và phải !important mới thắng nổi. */
        .table-lines th.la-chu, .table-lines td.la-chu { text-align: left !important; }
        .table-lines th.la-so, .table-lines td.la-so { text-align: right !important; }
        .table-lines th.la-giua, .table-lines td.la-giua { text-align: center !important; }

        /* `table-layout: fixed` là chỗ chặn cuộn ngang.
           Để `auto` (mặc định) thì các % dưới đây chỉ là GỢI Ý: trình duyệt vẫn nới
           cột theo nội dung, nên một tên hàng dài, hay tiêu đề `nowrap` cộng với ô
           nhập 90px, là đủ đội bảng rộng hơn khung và đẻ ra thanh cuộn. Với `fixed`,
           % thành bề rộng THẬT: chọn bao nhiêu mặt hàng, tên dài cỡ nào, bảng vẫn
           đúng bằng khung. Đổi lại, chữ tràn phải tự cắt — xem luật "…" ngay dưới.
           Cộng lại đúng 100%, bỏ trống một cột là bảng hở khoảng chết bên phải. */
        .table-lines { width: 100%; table-layout: fixed; border-collapse: collapse; border: 1px solid #d9d9d9; }
        .table-lines th, .table-lines td { padding: 8px; border: 1px solid #d9d9d9; }
        /* Tiêu đề ĐƯỢC xuống dòng: `nowrap` ở đây chính là thứ ép cột rộng ra. */
        .table-lines th { white-space: normal; vertical-align: bottom; background: #f8f9fa; }
        /* Mọi ô dữ liệu cắt bằng "…" — chữ đủ nằm ở thuộc tính title của ô. */
        .table-lines td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .table-lines th.c-xoa { width: 3%; }
        .table-lines th.c-stt { width: 4%; }
        .table-lines th.c-loai { width: 9%; }
        .table-lines th.c-ma { width: 10%; }
        .table-lines th.c-ten { width: 17%; }
        .table-lines th.c-dv { width: 8%; }
        .table-lines th.c-ton { width: 8%; }
        .table-lines th.c-gia { width: 11%; }
        .table-lines th.c-sl { width: 10%; }
        .table-lines th.c-lo { width: 9%; }
        /* Ngày dạng 31-12-2026 cần ~90px mới không bị cắt ở màn hẹp nhất. */
        .table-lines th.c-han { width: 11%; }

        /* Viền và nền khai THẲNG ở đây, không dựa vào `.form-control` của Bootstrap:
           v2 nạp năm tệp CSS sau Bootstrap và đã có tiền lệ đè lên nó. Ô nhập mất
           viền thì nhìn y như chữ thường, không ai biết chỗ nào gõ được.
           Bề rộng ăn theo cột, không giữ 90px cứng: cột đã cố định thì một con số
           cứng lớn hơn cột sẽ chọc thủng ô. */
        .table-lines .ip-line {
            width: 100%; max-width: 100%; height: 32px; padding: 2px 8px; font-size: 13px; text-align: right;
            border: 1px solid #dee2e6; border-radius: 4px; background-color: #fff; color: #212529;
        }
        .table-lines .ip-line:focus { border-color: #86b7fe; outline: 0; }
        .table-lines .ip-line:disabled { background-color: #f4f6f8; color: #6c757d !important; }
        .table-lines .remove-line i { font-size: 14px; color: #dc3545; cursor: pointer; }

        /* Phiếu chỉ xem: bỏ luôn cột nút xoá. Bảng chạy `table-layout: fixed` nên
           10 cột còn lại tự giãn ra chia hết 100%. */
        .table-lines.chi-xem th.c-xoa, .table-lines.chi-xem td:first-child { display: none; }

        /* Dòng hết hàng trong kho xuất: tô nền hồng nhạt, số cần chuyển kẹp về 0. */
        .table-lines tr.is-het td { background-color: #fff9f9; }

        /* Điện thoại: 11 cột chia theo % thì cột nào cũng hẹp tới mức không đọc nổi.
           Chỗ này trả lại cuộn ngang và neo một bề rộng tối thiểu — cuộn thì phiền,
           nhưng vẫn hơn một bảng không đọc được. */
        @media (max-width: 991px) {
            .pdc-luoi { overflow-x: auto; }
            .table-lines { min-width: 900px; }
        }

        /* Bảng danh sách: KHÔNG `min-width`, y như bản v2 cũ — bên đó `table` chỉ có
           `width: 100%` nên bảng vừa khít khung và trang KHÔNG cuộn ngang.
           `.table-responsive` vẫn để `overflow-x: auto` làm đường lùi. */
        .list .table-responsive { overflow-x: auto; }
        table.table-transfer.none_mobile { width: 100%; }
        table.table-transfer.none_mobile th { white-space: nowrap; }
        /* Năm cột chữ tự do phải cắt bằng "…": một tên kho hay ghi chú dài sẽ đội cả
           bảng rộng ra và kéo theo thanh cuộn ngang. Chữ đủ nằm ở thuộc tính title. */
        table.table-transfer.none_mobile td.col-from,
        table.table-transfer.none_mobile td.col-to,
        table.table-transfer.none_mobile td.col-creator,
        table.table-transfer.none_mobile td.col-receiver,
        table.table-transfer.none_mobile td.col-note {
            max-width: 220px; overflow: hidden; text-overflow: ellipsis;
        }
        table.table-transfer th.col-check { width: 3%; }
        table.table-transfer th.col-stt { width: 3%; }
        table.table-transfer th.col-code { width: 11%; }
        table.table-transfer th.col-from { width: 12%; }
        table.table-transfer th.col-to { width: 12%; }
        table.table-transfer th.col-status { width: 9%; }
        table.table-transfer th.col-creator { width: 11%; }
        table.table-transfer th.col-receiver { width: 11%; }
        table.table-transfer th.col-date { width: 9%; }
        table.table-transfer th.col-note { width: 13%; }
        table.table-transfer th.col-act { width: 6%; }
    </style>
    {{-- Cột đang tắt ở ô "chọn cột". Là CSS nên nạp lại danh sách bằng AJAX vẫn giữ. --}}
    <style id="cotAnCss"></style>
@endpush

@section('content')
    {{-- Nút mở bộ lọc dạng offcanvas, chỉ hiện trên điện thoại — đúng ba nút của v2. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterTranferDate">
                <p class="open-modal-label">{{ __('message.transfer_date') }}</p>
                <div class="icon-for-cta"><i class="fa-regular fa-calendar"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterTransferSlip">
                <p class="open-modal-label">{{ __('message.status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-filter"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterMenu">
                <p class="open-modal-label">{{ __('message.goods') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-box"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-transfer-slip-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2.
             d-none d-lg-block: trên điện thoại các khối này đã đi vào offcanvas. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không có nút "Lọc": đổi ô nào là lọc lại ngay. --}}
                        <form action="{{ route('admin.phieu-dieu-chuyen.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterTranferDate" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.transfer_date') }}</span>
                                    {{-- Hai ô ngày gõ theo DD-MM-YYYY và mở lịch daterangepicker, đúng như v2. --}}
                                    <div class="d-flex flex-lg-column gap-2 gap-lg-0">
                                        <input type="text" name="from_date" autocomplete="off"
                                            value="{{ $filters['from_date'] ? date('d-m-Y', strtotime($filters['from_date'])) : '' }}"
                                            class="form-control mb-lg-1" id="from_date"
                                            placeholder="{{ __('message.from_date') }}">
                                        <input type="text" name="to_date" autocomplete="off"
                                            value="{{ $filters['to_date'] ? date('d-m-Y', strtotime($filters['to_date'])) : '' }}"
                                            class="form-control" id="to_date" placeholder="{{ __('message.to_date') }}">
                                    </div>
                                </div>
                            </div>

                            <div id="filterTransferSlip" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">
                                        {{ __('message.status') }} {{ Str::lower(__('message.transfer-slip')) }}
                                    </span>
                                    @foreach ($thuTuLoc as $ma)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input status" type="checkbox" name="status[]"
                                                value="{{ $ma }}" id="transfer_status_{{ $ma }}"
                                                {{ in_array($ma, $trangThaiChon, true) ? 'checked' : '' }}>
                                            <label class="form-check-label ms-2 {{ $C::CHU_TRANG_THAI[$ma] ?? '' }}"
                                                style="font-weight: bold"
                                                for="transfer_status_{{ $ma }}">{{ $C::TRANG_THAI[$ma] }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div id="filterMenu" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.goods') }}</span>
                                    {{-- Ô tìm hàng gọi API, y như `menu.select` của v2. --}}
                                    <select name="product_id" class="form-control filter-menu-select mt-1">
                                        <option value="">{{ __('message.all') }}</option>
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
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang. --}}
                    <h1 class="tieu-de-trang">{{ __('message.transfer-slip') }}</h1>

                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green btn_create">{{ __('message.create_new') }}</a>
                            <a type="button" class="bt btn_red mass-delete">{{ __('message.delete') }}</a>

                            <div class="dropdown dropdown_advanced">
                                <button class="bt btn_advanced dropdown-toggle py-1" type="button" data-bs-toggle="dropdown"
                                    aria-expanded="false">{{ __('message.advanced') }}</button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item btn_export" type="button">{{ __('message.export-excel') }}</a>
                                        <a class="dropdown-item btn_print_list" type="button">{{ __('message.print') }}</a>
                                    </li>
                                </ul>
                            </div>

                            {{-- Chọn cột — lựa chọn nằm ở localStorage nên nạp lại danh sách vẫn giữ. --}}
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col" href="#">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="show_all" checked>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($C::COT_BANG as $ma => $ten)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="{{ $ma }}"
                                                        type="checkbox" id="show_{{ $ma }}" checked>
                                                    <label for="show_{{ $ma }}">{{ $ten }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-transfer none_mobile">
                            <tr>
                                <th class="text-center not-export col-check">
                                    <input class="form-check-input item-select-all" type="checkbox">
                                </th>
                                <th class="text-center col-stt">{{ __('message.serial') }}</th>
                                <th class="text-left col-code">{{ __('message.entry_number') }}</th>
                                <th class="text-left col-from">{{ __('message.output_warehouse') }}</th>
                                <th class="text-left col-to">{{ __('message.input_warehouse') }}</th>
                                <th class="text-center col-status">{{ __('message.status') }}</th>
                                <th class="text-left col-creator">{{ __('message.creator') }}</th>
                                <th class="text-left col-receiver">{{ __('message.receiving_staff') }}</th>
                                <th class="text-center col-date">{{ __('message.warehouse_date') }}</th>
                                <th class="text-left col-note">{{ __('message.note') }}</th>
                                <th class="text-center not-export col-act">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($list as $i => $p)
                                @php
                                    $id = (int) ($p['id'] ?? 0);
                                    $tt = $p['status'] ?? 'draft';
                                    $nhap = $tt === 'draft';
                                @endphp
                                <tr class="item" data-id="{{ $id }}" data-status="{{ $tt }}">
                                    <td class="text-center not-export col-check">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    </td>
                                    <td class="text-center col-stt">{{ $stt + $i + 1 }}</td>
                                    <td class="text-left col-code">
                                        {{-- Bấm số phiếu là mở phiếu, đúng lối của v2 — lưu tạm thì sửa được,
                                             đã duyệt thì chỉ xem. --}}
                                        <a type="button" data-id="{{ $id }}"
                                            class="edit_bt detail-item text-decoration-none" title="{{ __('message.detail') }}">
                                            {{ ($p['transfer_code'] ?? '') ?: '—' }}
                                        </a>
                                    </td>
                                    <td class="text-left col-from" title="{{ $p['from_shop_name'] ?? '' }}">
                                        {{ $p['from_shop_name'] ?? '' }}
                                    </td>
                                    <td class="text-left col-to" title="{{ $p['to_shop_name'] ?? '' }}">
                                        {{ $p['to_shop_name'] ?? '' }}
                                    </td>
                                    <td class="text-center col-status">
                                        <b class="{{ $C::CHU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                    </td>
                                    <td class="text-left col-creator" title="{{ $p['creator_name'] ?? '' }}">{{ $p['creator_name'] ?? '' }}</td>
                                    <td class="text-left col-receiver" title="{{ $p['receiver_name'] ?? '' }}">{{ $p['receiver_name'] ?? '' }}</td>
                                    <td class="text-center col-date">{{ $ngay($p['created_at'] ?? null) ?: '-' }}</td>
                                    <td class="text-left col-note" title="{{ $p['note'] ?? '' }}">{{ $p['note'] ?? '' }}</td>
                                    {{-- Con mắt mở phiếu. Xoá chỉ hiện ở phiếu LƯU TẠM — y như v2, bên đó
                                         cột này cũng chỉ bày nút xoá khi `$item->status == 1`. --}}
                                    <td class="action not-export col-act">
                                        <a class="detail-item" type="button" title="{{ __('message.detail') }}"><i class="fa fa-eye"></i></a>
                                        @if ($nhap)
                                            <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center py-4">{{ $C::EMPTY_TEXT }}</td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai — đúng như v2. --}}
                        <div class="table-transfer none_desktop">
                            <div class="d-flex align-items-center justify-content-between gap-1 p-2 border">
                                <input class="form-check-input item-select-all" type="checkbox">
                                <div class="fw-bold" style="flex: 1">{{ __('message.input_warehouse') }}</div>
                                <div class="fw-bold">{{ __('message.creator') }}</div>
                            </div>
                            @foreach ($list as $p)
                                @php
                                    $id = (int) ($p['id'] ?? 0);
                                    $tt = $p['status'] ?? 'draft';
                                @endphp
                                <div class="item" data-id="{{ $id }}" data-status="{{ $tt }}">
                                    <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    <div class="d-flex flex-column detail-item" role="button" style="flex: 1">
                                        <span class="fw-semibold">{{ $p['to_shop_name'] ?? '' }}</span>
                                        <span style="font-size: 14px">{{ $p['transfer_code'] ?? '' }}</span>
                                    </div>
                                    <div class="d-flex text-right show_quantity gap-2">{{ $p['creator_name'] ?? '' }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dữ liệu đầy đủ của từng dòng, ĐẶT TRONG khối được thay khi nạp lại.
                         Để ở ngoài thì lọc bằng AJAX xong bảng là bảng mới mà hộp thoại vẫn
                         đọc dữ liệu của lượt tải đầu tiên. --}}
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

    {{-- ===================== Hộp lập / sửa / xem phiếu =====================
         Ba khối xếp dọc, đúng thứ tự của modal `transferSlipModal` bên v2:
           1. Hàng nút Lưu tạm / Duyệt / In nằm TRÊN CÙNG, canh phải.
           2. Thông tin phiếu: bốn cột, ô ghi chú chiếm cả hàng cuối.
           3. Ô tìm hàng, rồi lưới hàng — mỗi LÔ của mặt hàng là một dòng. --}}
    <div class="modal" id="modalCreate" data-bs-keyboard="false">
        {{-- `modal-xl` đúng như bản v2 của màn này, KHÔNG kéo 90% bề ngang màn
             hình: phiếu chỉ có tám ô thông tin và lưới hàng mười một cột hẹp, nên
             hộp rộng hơn 1140px là bốn cột giãn ra thành bốn dải trống. Lưới hàng
             có `.pdc-luoi { overflow-x: auto }` lo phần cuộn khi màn hẹp. --}}
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl mx-auto">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.transfer-slip') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body pt-0" id="content_create">
                    <input type="hidden" id="transfer_slip_id" value="">
                    {{-- Mốc sửa của BẢN đang mở. Gửi lại lúc lưu để API biết có ai
                         vừa lưu đè hay không — xem chong_ghi_de.go bên API. --}}
                    <input type="hidden" id="transfer_updated_at" value="">

                    {{-- 1. Thanh thao tác — mã phiếu bên trái, cụm nút bên phải. Lập mới
                         thì bên trái để trống nên thẻ tự ẩn. --}}
                    <div class="pdc-thanh">
                        <h4 class="pdc-ma-phieu"></h4>
                        <div class="pdc-thanh-nut">
                            <button type="button" class="bt btn_gray save-slip" data-duyet="0">
                                {{ __('message.status-temporary') }}
                            </button>
                            <button type="button" class="bt btn_green save-slip" data-duyet="1">
                                {{ __('message.approve') }}
                            </button>
                            <button type="button" class="bt btn btn-print pdc-in d-none">
                                <i class="fa-solid fa-print"></i> {{ __('message.print') }}
                            </button>
                        </div>
                    </div>

                    {{-- 2. Thông tin phiếu — đúng tám ô của v2, cùng thứ tự đọc theo hàng:
                         Số phiếu · Người tạo · Kho xuất · Ngày tạo
                         Kho nhập · Trạng thái · Loại chứng từ · Người nhận --}}
                    <div class="pdc-form">
                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="voucher_number">
                                    {{ __('message.entry_number') }} <span class="required">*</span>
                                </label>
                                <input type="text" class="form-control" id="voucher_number"
                                    placeholder="{{ __('message.auto-increment-code') }}" disabled>
                            </div>
                        </div>
                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="created_by">{{ __('message.creator') }}</label>
                                <input type="text" class="form-control" id="created_by" readonly
                                    value="{{ session('api.user.full_name') ?? '' }}">
                            </div>
                        </div>
                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="from_warehouse">
                                    {{ __('message.output_warehouse') }} <span class="required">*</span>
                                </label>
                                <select class="form-select form-control" id="from_warehouse" name="from_warehouse_id">
                                    <option value="">{{ __('message.chose') }}</option>
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}"
                                            {{ (int) ($cn['id'] ?? 0) === (int) $chiNhanhDangLam['id'] ? 'selected' : '' }}>
                                            {{ $cn['name'] ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="created_at">{{ __('message.creation-date') }}</label>
                                <input type="text" class="form-control" id="created_at" disabled>
                            </div>
                        </div>

                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="to_warehouse">
                                    {{ __('message.input_warehouse') }} <span class="required">*</span>
                                </label>
                                <select class="form-select form-control" id="to_warehouse" name="to_warehouse_id"
                                    data-placeholder="{{ __('message.select_import_warehouse') }}">
                                    <option value="">{{ __('message.chose') }}</option>
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}">{{ $cn['name'] ?? '' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="status">{{ __('message.status') }}</label>
                                <input type="text" class="form-control" id="status" readonly
                                    value="{{ $C::TRANG_THAI_MOI }}">
                            </div>
                        </div>
                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="document_type">{{ __('message.document_type') }}</label>
                                <input type="text" class="form-control" id="document_type" readonly
                                    value="{{ $C::LOAI_CHUNG_TU }}">
                            </div>
                        </div>
                        <div class="pdc-cot">
                            <div class="pdc-o">
                                <label class="form-label" for="receiving_staff">
                                    {{ __('message.receiving_staff') }} <span class="required">*</span>
                                </label>
                                <select class="form-select form-control receiving_staff" id="receiving_staff"
                                    name="receiver_id">
                                    <option value="">{{ __('message.chose') }} {{ Str::lower(__('message.receiving_staff')) }}</option>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}">
                                            {{ ($nv['code'] ?? '') ? $nv['code'].' - ' : '' }}{{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Ghi chú chiếm cả hàng, có bộ đếm ký tự như v2. --}}
                        <div class="pdc-cot pdc-rong">
                            <div class="pdc-o">
                                <label class="form-label" for="note">{{ __('message.note') }}</label>
                                <div class="pdc-textarea">
                                    <textarea class="form-control p-2" id="note" name="note" rows="2" maxlength="200"></textarea>
                                    <small class="pdc-dem-chu">0/200</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- 3. Khối hàng hoá — tiêu đề bên trái, ô tìm hàng bên phải, cùng một
                         hàng và cùng chiều cao với nhau. --}}
                    <div class="content_midd_title pdc-thanh-hang">
                        <h4>{{ __('message.product') }} <span class="required">*</span></h4>
                        <div class="pdc-hang-cong-cu">
                            <select class="form-control select-menus"><option></option></select>
                        </div>
                    </div>

                    <p id="line_error" class="text-danger my-2" style="display:none;"></p>

                    <div class="pdc-luoi mt-2">
                        <table class="table-lines">
                            <thead>
                                <tr>
                                    {{-- Nút xoá dòng đứng ĐẦU hàng, đúng như bản v2 của màn này. --}}
                                    <th class="la-giua c-xoa not-export"></th>
                                    <th class="la-giua c-stt">{{ __('message.serial') }}</th>
                                    <th class="la-chu c-loai">{{ __('message.transaction_type') }}</th>
                                    <th class="la-chu c-ma">{{ __('message.product_code') }}</th>
                                    <th class="la-chu c-ten">{{ __('message.product_name') }}</th>
                                    <th class="la-chu c-dv">{{ __('message.unit_of_measurement') }}</th>
                                    <th class="la-so c-ton">{{ __('message.quantity') }}</th>
                                    <th class="la-so c-gia">{{ __('message.unit_price') }}</th>
                                    <th class="la-so c-sl">{{ __('message.transfer_quantity') }}</th>
                                    <th class="la-chu c-lo">{{ __('message.batch_number') }}</th>
                                    <th class="la-giua c-han">{{ __('message.expiry_date') }}</th>
                                </tr>
                            </thead>
                            <tbody class="list-menu"></tbody>
                        </table>
                    </div>
                    <p class="text-center text-secondary py-3 mb-0 lines-empty">
                        Chọn kho xuất rồi tìm hàng ở ô trên — mỗi lô còn hàng của mặt hàng sẽ thành một dòng tại đây.
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp xoá phiếu ===================== --}}
    <div class="modal" id="deleteItem">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">
                        {{ __('message.delete') }} {{ Str::lower(__('message.transfer-slip')) }}?
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="deleteValue">
                    <div class="modal_center">
                        <div class="row">
                            <div class="col text-center">
                                <label class="form-label">{{ __('message.delete-confirm') }}</label>
                                <p class="text-secondary mb-0">
                                    Chỉ phiếu lưu tạm mới xoá được — phiếu đã duyệt nằm lại trong sổ vì kho
                                    đã đổi theo nó.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_red delete-value">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_MAT_HANG = @json(route('admin.phieu-dieu-chuyen.matHang'));

        const NHAN_TRANG_THAI = @json(\App\Http\Controllers\PhieuDieuChuyenController::TRANG_THAI);
        const LOAI_GIAO_DICH = @json(\App\Http\Controllers\PhieuDieuChuyenController::LOAI_GIAO_DICH);

        const URL_BASE = @json(url('/admin/stock-transfers'));
        const URL_STORE = @json(route('admin.phieu-dieu-chuyen.store'));

        // Cả bản ghi của từng dòng — hộp thoại và nút Xoá đọc thẳng ở đây, khỏi rải
        // hơn chục data-* lên mỗi <tr>. Đọc lại sau mỗi lượt nạp danh sách bằng AJAX.
        let ROWS = docDongHienCo();

        function docDongHienCo() {
            try {
                return JSON.parse(document.getElementById('v2-rows').textContent) || {};
            } catch (e) {
                return {};
            }
        }

        // ---------- Tiện ích chung ----------
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const tien = (v) => (Number(v) || 0).toLocaleString('vi-VN') + '₫';
        const nhomSo = (v) => (Number(v) || 0).toLocaleString('vi-VN');
        const tenMatHang = (r) => [r.product_name, r.variant_name].filter(Boolean).join(' · ');
        // Số lượng điều chuyển là số NGUYÊN: sổ kho của hệ thống này đếm nguyên.
        const soNguyen = (v) => Math.max(0, Math.round(Number(String(v == null ? '' : v).replace(/[^\d]/g, '')) || 0));

        /**
         * Con trỏ trong ô số đếm TỪ PHẢI SANG.
         *
         * Ô số lượng được chấm lại dấu nghìn sau mỗi phím, nên vị trí tính từ trái
         * nhảy một bước mỗi lần chuỗi dài thêm một dấu chấm — gõ số hàng nghìn là
         * con trỏ tự bò về đầu.
         */
        function viTriTuPhai(o) {
            try {
                return o.selectionStart == null ? null : o.value.length - o.selectionStart;
            } catch (e) {
                return null;
            }
        }

        function datConTro(o, tuPhai) {
            if (tuPhai == null) return;
            const i = Math.max(0, o.value.length - tuPhai);
            try { o.setSelectionRange(i, i); } catch (e) { /* ô số */ }
        }

        // =====================================================================
        //  Bộ lọc — đổi ô nào là lọc lại ngay
        // =====================================================================
        //
        // Tự dựng URL thay vì submit form: trên điện thoại khung v2 BƯNG khối lọc
        // sang tấm offcanvas, và mỗi lượt chỉ bưng MỘT khối, nên submit lúc đó sẽ
        // đánh rơi mấy ô còn lại.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai() {
            const q = new URLSearchParams();

            const mh = String(oLoc('product_id').val() || '').trim();
            if (mh) q.set('product_id', mh);

            // Hai ô ngày LUÔN được gửi, kể cả khi rỗng: máy chủ mặc định lọc tháng
            // này khi KHÔNG thấy tham số, nên bỏ qua ô rỗng là xoá ngày xong bảng
            // vẫn chỉ có tháng này — xem PhieuDieuChuyenController::filters.
            ['from_date', 'to_date'].forEach((ten) => {
                q.set(ten, String(oLoc(ten).val() || '').trim());
            });
            oLoc('status[]').filter(':checked').each(function () { q.append('status[]', this.value); });

            // Cỡ trang không có ô trong khung lọc nhưng phải giữ. Cố ý KHÔNG mang
            // `page`: trang 5 của bộ lọc cũ không còn nghĩa gì.
            const cu = new URLSearchParams(location.search);
            if (cu.get('page_size')) q.set('page_size', cu.get('page_size'));

            V2.napLai(location.pathname + '?' + q);
        }

        $(document).on('change', '.fillter-box select, .fillter-box input[type="checkbox"]', locLai);

        $(document).on('submit', '#search-form', function (e) {
            e.preventDefault();
            locLai();
        });

        // Ô tìm hàng của khối lọc — gọi API như ô `filter-menu-select` của v2.
        $(function () {
            $('.filter-menu-select').select2({
                width: '100%',
                allowClear: true,
                placeholder: @json(__('message.all')),
                ajax: {
                    url: URL_MAT_HANG,
                    dataType: 'json',
                    delay: 300,
                    data: (params) => ({ keyword: params.term || '' }),
                    processResults: (res) => ({
                        results: (res.data || []).map((r) => ({
                            id: r.variant_id,
                            text: tenMatHang(r) + ' (' + (r.sku || '') + ')',
                        })),
                    }),
                },
            });
        });

        // =====================================================================
        //  Hai ô ngày — lịch một ngày, khuôn DD-MM-YYYY, cùng bộ của v2
        // =====================================================================
        const KHUON_NGAY = 'DD-MM-YYYY';
        const homNay = moment().endOf('day');

        /** Đọc một ô ngày ra moment; rỗng hoặc gõ sai khuôn thì trả null. */
        const docNgay = ($o) => {
            const v = String($o.val() || '').trim();
            if (!v) return null;
            const m = moment(v, KHUON_NGAY, true);

            return m.isValid() ? m : null;
        };

        const lich = (sel) => $(sel).data('daterangepicker');

        /** Kẹp hai ô vào nhau: "từ" không quá "đến", "đến" không dưới "từ". */
        function ketNgay() {
            const tu = docNgay($('#from_date'));
            const den = docNgay($('#to_date'));

            // Khoảng ngược (chỉ tới được bằng đường dẫn gõ tay): KHÔNG kẹp chéo,
            // kẹp thì hai lịch có minDate lớn hơn maxDate và mọi ngày đều xám.
            const nguoc = tu && den && tu.isAfter(den, 'day');

            const pTu = lich('#from_date');
            const pDen = lich('#to_date');
            if (pTu) pTu.maxDate = nguoc ? homNay : (den || homNay);
            if (pDen) {
                pDen.minDate = nguoc ? null : (tu || null);
                pDen.maxDate = homNay;
            }
        }

        $(document).on('change', '#from_date, #to_date', function () {
            const v = String(this.value || '').trim();
            // Gõ tay sai khuôn thì xoá đi chứ không gửi lên máy chủ một chuỗi rác.
            if (v && !moment(v, KHUON_NGAY, true).isValid()) {
                this.value = '';
            }
            ketNgay();
        });

        $('#from_date, #to_date').each(function () {
            $(this).daterangepicker({
                singleDatePicker: true,
                autoUpdateInput: false,
                maxDate: homNay,
                locale: V2.lichVN({ format: KHUON_NGAY }),
            }, function (start) {
                $(this.element).val(start.format(KHUON_NGAY)).trigger('change');
                locLai();
            });
        });
        ketNgay();

        // =====================================================================
        //  Chọn cột hiện / ẩn — lưu ở localStorage
        // =====================================================================
        const COT_KEY = 'pdc-v2-cot-an';

        function apDungCot() {
            const $cbs = $('.show_col');
            const an = $cbs.filter((i, el) => !el.checked).map((i, el) => String($(el).data('col'))).get();

            document.getElementById('cotAnCss').textContent = an.length
                ? an.map((c) => '.col-' + c).join(',') + '{display:none!important}'
                : '';

            try { localStorage.setItem(COT_KEY, JSON.stringify(an)); } catch (e) { /* chế độ riêng tư */ }

            $('#show_all').prop('checked', an.length === 0);
        }

        (function napCotDaLuu() {
            let an = [];
            try { an = JSON.parse(localStorage.getItem(COT_KEY) || '[]') || []; } catch (e) { an = []; }
            $('.show_col').each(function () { this.checked = an.indexOf(String($(this).data('col'))) === -1; });
            apDungCot();
        })();

        $(document).on('change', '.show_col', apDungCot);

        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            apDungCot();
        });

        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        // Nạp lại danh sách bằng AJAX là thay cả khối bảng — dựng lại phần phụ thuộc.
        $(document).on('v2:da-nap', function () {
            ROWS = docDongHienCo();
            apDungCot();
        });

        const idsDaChon = () => $('.item-select:checked').map((i, el) => Number(el.value)).get()
            .filter((v) => v > 0);

        // =====================================================================
        //  Hộp lập / sửa phiếu
        // =====================================================================
        const $mc = () => $('#modalCreate');

        /** Mọi dòng hàng đang có trên phiếu. */
        let DONG = [];
        let dongSeq = 0;

        // Phiếu đang mở trong hộp. Nút In lấy phần đầu tờ từ đây (mã phiếu, người
        // lập) — mấy thứ không có ô nhập nào trên màn nên không đọc ngược ra được.
        // Khai cạnh DONG vì cùng là trạng thái của hộp phiếu, và donHopPhieu phải
        // dọn cả hai; để rời nhau là một ngày nào đó dọn sót một cái.
        let PHIEU_DANG_XEM = {};

        const EMPTY_DAU = $('#modalCreate .lines-empty').text().trim();

        /**
         * Phiếu đang mở chỉ ĐƯỢC XEM, không sửa.
         *
         * Trước đây chỉ giấu nút Lưu, còn cả hộp vẫn gõ được: mở một phiếu đã duyệt
         * là vẫn tìm được hàng, thêm dòng, sửa số, xoá dòng. Không gì lưu xuống cả,
         * nhưng người dùng nhìn thấy phiếu đã duyệt biến đổi trước mắt — tưởng vừa
         * sửa được kho. Cờ này khoá đúng mọi chỗ gõ được.
         */
        let CHI_XEM = false;

        /** Bật/tắt khoá cho cả hộp theo CHI_XEM. Gọi lại sau mỗi lượt vẽ lưới. */
        function khoaHop() {
            const $m = $mc();

            // Select2 không tự đọc thuộc tính disabled — phải báo cho nó vẽ lại.
            $m.find('#from_warehouse, #to_warehouse, #receiving_staff')
                .prop('disabled', CHI_XEM).trigger('change.select2');

            // Ô tìm hàng: giấu hẳn chứ không để mờ. Phiếu chỉ xem thì thêm hàng là
            // việc không tồn tại, bày ra một ô xám chỉ tổ để người ta bấm thử.
            $m.find('.pdc-hang-cong-cu').toggle(!CHI_XEM);
            $m.find('.pdc-thanh-hang .required').toggle(!CHI_XEM);

            $m.find('#note').prop('readonly', CHI_XEM);
            $m.find('.table-lines').toggleClass('chi-xem', CHI_XEM);
        }

        function baoLoiDong(cau) {
            $('#line_error').text(cau || '').toggle(!!cau);
        }


        /**
         * Dọn sạch hộp phiếu — KHÔNG bày ra.
         *
         * Tách khỏi moPhieuMoi vì lượt mở một phiếu ĐÃ CÓ cũng phải dọn trước rồi
         * mới đổ dữ liệu vào: gọi thẳng moPhieuMoi thì hộp bày ra rỗng một nhịp
         * rồi mới có chữ, nhìn như trang lỗi.
         */
        function donHopPhieu() {
            const $m = $mc();
            DONG = [];
            dongSeq = 0;

            $m.find('.pdc-ma-phieu').text('');
            $m.find('#transfer_slip_id').val('');
            $m.find('#transfer_updated_at').val('');
            PHIEU_DANG_XEM = {};
            $m.find('#voucher_number').val('');
            $m.find('#created_at').val(moment().format('DD-MM-YYYY HH:mm'));
            $m.find('#note').val('');
            $m.find('.pdc-dem-chu').text('0/200');
            $m.find('#receiving_staff').val('').trigger('change.select2');
            $m.find('.pdc-in').addClass('d-none');
            $m.find('.save-slip').removeClass('d-none');

            // Dọn hộp là trả về phiếu lập mới — mở phiếu đã duyệt rồi mở tiếp một
            // phiếu lưu tạm mà quên bước này thì hộp còn khoá nguyên.
            CHI_XEM = false;
            khoaHop();

            khoaKhoTrung();
            baoLoiDong('');
            veLuoi();
        }

        /** Mở hộp lập phiếu mới — dọn sạch mọi ô rồi mới bày ra. */
        function moPhieuMoi() {
            donHopPhieu();
            $mc().modal('show');
        }

        $(document).on('click', '.btn_create', moPhieuMoi);

        /** Đếm ký tự ghi chú, y như v2. */
        $(document).on('input', '#modalCreate #note', function () {
            $mc().find('.pdc-dem-chu').text(String(this.value.length) + '/200');
        });

        /**
         * Kho xuất và kho nhập KHÔNG được trùng nhau: chọn bên nào thì khoá đúng
         * mục đó ở ô bên kia — đúng cách v2 làm.
         */
        function khoaKhoTrung() {
            const $m = $mc();
            const tu = String($m.find('#from_warehouse').val() || '');
            const den = String($m.find('#to_warehouse').val() || '');

            $m.find('#to_warehouse option').each(function () {
                this.disabled = this.value !== '' && this.value === tu;
            });
            $m.find('#from_warehouse option').each(function () {
                this.disabled = this.value !== '' && this.value === den;
            });
        }

        // Đổi kho xuất là dọn sạch lưới hàng: tồn và lô của kho cũ không còn nghĩa
        // gì ở kho mới — v2 cũng nạp lại danh sách hàng theo cặp kho.
        $(document).on('change', '#modalCreate #from_warehouse', function () {
            khoaKhoTrung();
            if (DONG.length) {
                DONG = [];
                veLuoi();
                toastr.info('Đổi kho xuất nên lưới hàng đã dọn lại — tồn của kho cũ không dùng được cho kho mới.');
            }
        });

        $(document).on('change', '#modalCreate #to_warehouse', khoaKhoTrung);

        // ---------- Ô tìm hàng (select2 gọi API) ----------
        function napSelectMenus() {
            const $m = $mc();
            $m.find('#from_warehouse, #to_warehouse, #receiving_staff').each(function () {
                if ($(this).hasClass('select2-hidden-accessible')) return;
                $(this).select2({ dropdownParent: $m, width: '100%' });
            });

            if ($m.find('.select-menus').hasClass('select2-hidden-accessible')) return;
            $m.find('.select-menus').select2({
                dropdownParent: $m,
                placeholder: @json(__('message.chose').' '.Str::lower(__('message.product'))),
                width: '100%',
                minimumInputLength: 0,
                ajax: {
                    url: URL_MAT_HANG,
                    dataType: 'json',
                    delay: 300,
                    data: (params) => ({ keyword: params.term || '' }),
                    processResults: (res) => ({
                        results: (res.data || []).map((r) => ({
                            id: r.variant_id,
                            text: tenMatHang(r) + ' (' + (r.sku || '') + ')'
                                + (r.cost_price == null ? '' : ' · ' + tien(r.cost_price)),
                            mh: r,
                        })),
                    }),
                },
            });
        }

        // Dựng select2 ngay từ đầu chứ không đợi lượt mở hộp: hộp nằm sẵn trong trang,
        // mà dựng lúc hộp còn ẩn thì ô chọn hay ra sai bề ngang ở lượt mở đầu tiên.
        $(napSelectMenus);

        $(document).on('select2:select', '#modalCreate .select-menus', function (e) {
            if (CHI_XEM) return;
            themDong(e.params.data.mh);
            veLuoi();
            $(this).val(null).trigger('change');
        });

        /**
         * Thêm một mặt hàng vào lưới.
         *
         * MỖI LÔ CÒN HÀNG LÀ MỘT DÒNG, đúng như v2: điều chuyển là bốc hàng có thật
         * ra khỏi kho, mà hàng nằm trong lô nào thì phải chỉ rõ lô đó — gộp chung
         * một dòng thì lúc duyệt không biết trừ lô nào. Mặt hàng không quản theo lô
         * thì vẫn ra một dòng, số lô để trống.
         */
        function themDong(mh) {
            if (!mh) return;

            if (!String($mc().find('#from_warehouse').val() || '')) {
                baoLoiDong('Chọn kho xuất trước rồi mới thêm hàng — tồn và lô đọc theo kho đó.');

                return;
            }
            baoLoiDong('');

            const lots = (mh.lots || []).length ? mh.lots : [{
                lot_number: '', expire_date: '', quantity: Number(mh.stock || 0),
                unit_cost: Number(mh.cost_price || 0),
            }];

            let daCo = 0;
            lots.forEach((lo) => {
                const soLo = String(lo.lot_number || '');
                // Cùng mặt hàng, cùng lô thì KHÔNG đẻ dòng thứ hai: hai dòng cùng lô
                // là hai lệnh trừ trên cùng một chỗ hàng, cộng lại vượt tồn lúc nào
                // không hay.
                if (DONG.some((d) => d.variant_id === mh.variant_id && d.lot_number === soLo)) {
                    daCo++;

                    return;
                }

                DONG.push({
                    key: ++dongSeq,
                    variant_id: mh.variant_id,
                    product_name: mh.product_name || '',
                    variant_name: mh.variant_name || '',
                    sku: mh.sku || '',
                    unit_name: mh.base_unit_name || '',
                    ton: Math.max(0, Number(lo.quantity || 0)),
                    unit_cost: Number(lo.unit_cost || mh.cost_price || 0),
                    // Dòng mới bắt đầu từ 0 — người lập phiếu tự gõ số thật. Điền sẵn
                    // 1 thì dòng nào quên gõ lại lặng lẽ trôi vào phiếu với số 1.
                    quantity: 0,
                    lot_number: soLo,
                    expire_date: lo.expire_date || '',
                });
            });

            if (daCo && daCo === lots.length) {
                toastr.info('Mặt hàng này đã có đủ lô trên phiếu.');
            }
        }

        /** Vẽ lại toàn bộ lưới hàng từ DONG. */
        function veLuoi() {
            const $m = $mc();
            const html = DONG.map((d, i) => {
                const het = d.ton <= 0;
                const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');
                const han = d.expire_date
                    ? moment(String(d.expire_date).slice(0, 10), 'YYYY-MM-DD').format('DD-MM-YYYY')
                    : '-';

                return '<tr data-key="' + d.key + '"' + (het ? ' class="is-het"' : '') + '>'
                    + '<td class="la-giua not-export">'
                    + (CHI_XEM ? '' : '<a class="remove-line" type="button" title="' + @json(__('message.delete')) + '"><i class="fa fa-times"></i></a>')
                    + '</td>'
                    + '<td class="la-giua">' + (i + 1) + '</td>'
                    + '<td class="la-chu" title="' + esc(LOAI_GIAO_DICH) + '">' + esc(LOAI_GIAO_DICH) + '</td>'
                    + '<td class="la-chu" title="' + esc(d.sku) + '">' + esc(d.sku) + '</td>'
                    + '<td class="la-chu" title="' + esc(ten) + '">' + esc(ten) + '</td>'
                    + '<td class="la-chu" title="' + esc(d.unit_name) + '">' + esc(d.unit_name) + '</td>'
                    + '<td class="la-so">' + nhomSo(d.ton) + '</td>'
                    + '<td class="la-so">' + tien(d.unit_cost) + '</td>'
                    + '<td class="la-so">'
                    + '<input type="text" inputmode="numeric" class="ip-line" data-f="quantity"'
                    + ' value="' + nhomSo(d.quantity) + '"'
                    + ' title="' + (het ? 'Lô này hết hàng' : 'Tối đa ' + nhomSo(d.ton)) + '"'
                    + (het || CHI_XEM ? ' disabled' : '') + '>'
                    + '</td>'
                    + '<td class="la-chu" title="' + esc(d.lot_number) + '">' + (esc(d.lot_number) || '-') + '</td>'
                    + '<td class="la-giua">' + esc(han) + '</td>'
                    + '</tr>';
            }).join('');

            $m.find('.list-menu').html(html);
            $m.find('.pdc-luoi').toggle(DONG.length > 0);
            // Phiếu chỉ xem mà rỗng thì câu "Chọn kho xuất rồi tìm hàng" là chỉ dẫn
            // cho một việc người ta không làm được.
            $m.find('.lines-empty')
                .text(CHI_XEM ? 'Phiếu này không có dòng hàng nào.' : EMPTY_DAU)
                .toggle(DONG.length === 0);
        }

        // Ô số lượng: kẹp theo tồn của chính lô đó, chấm lại dấu nghìn sau mỗi phím.
        $(document).on('input', '#modalCreate .list-menu [data-f="quantity"]', function () {
            if (CHI_XEM) return;
            const key = Number($(this).closest('tr').data('key'));
            const d = DONG.find((x) => x.key === key);
            if (!d) return;

            let n = soNguyen(this.value);
            if (n > d.ton) {
                n = d.ton;
                toastr.warning('Số lượng điều chuyển không vượt quá tồn của lô: ' + nhomSo(d.ton) + '.');
            }
            d.quantity = n;

            const phai = viTriTuPhai(this);
            this.value = nhomSo(n);
            datConTro(this, phai);
        });

        $(document).on('click', '#modalCreate .remove-line', function () {
            if (CHI_XEM) return;
            const key = Number($(this).closest('tr').data('key'));
            DONG = DONG.filter((d) => d.key !== key);
            veLuoi();
        });

        // ---------- Lưu tạm / Duyệt ----------
        //
        // Soát đủ điều kiện NGAY Ở ĐÂY rồi mới tính chuyện gửi đi: bốn ô bắt buộc
        // của v2 (kho xuất, kho nhập, người nhận, ít nhất một dòng có số lượng).
        $(document).on('click', '#modalCreate .save-slip', function () {
            if (CHI_XEM) return;
            const $m = $mc();
            const tu = String($m.find('#from_warehouse').val() || '');
            const den = String($m.find('#to_warehouse').val() || '');
            const nguoiNhan = String($m.find('#receiving_staff').val() || '');

            if (!tu) return toastr.error('Chưa chọn kho xuất.');
            if (!den) return toastr.error('Chưa chọn kho nhập.');
            if (tu === den) return toastr.error('Kho xuất và kho nhập không được trùng nhau.');
            if (!nguoiNhan) return toastr.error('Chưa chọn người nhận.');

            const hang = DONG.filter((d) => d.quantity > 0);
            if (!hang.length) {
                baoLoiDong('Phiếu chưa có dòng hàng nào có số lượng điều chuyển.');

                return;
            }
            baoLoiDong('');

            // `duyet` do CHÍNH cái nút vừa bấm quyết định: "Lưu tạm" để 0, "Duyệt"
            // để 1. Controller lưu xong mới gọi tiếp đường duyệt — duyệt là lúc kho
            // hai đầu đổi số nên bên API nó là một quyền riêng.
            const id = String($m.find('#transfer_slip_id').val() || '');
            const f = {
                from_shop_id: tu,
                to_shop_id: den,
                receiver_id: nguoiNhan,
                note: $m.find('#note').val() || '',
                duyet: $(this).data('duyet') ? 1 : 0,
                // Rỗng khi lập mới — API bỏ qua, đúng ý: chưa có bản nào để đè.
                updated_at: String($m.find('#transfer_updated_at').val() || ''),
            };
            hang.forEach((d, i) => {
                f['items[' + i + '][variant_id]'] = d.variant_id;
                f['items[' + i + '][quantity]'] = d.quantity;
                f['items[' + i + '][lot_number]'] = d.lot_number || '';
            });

            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI cho người dùng sửa (kho xuất
            // không đủ hàng, mặt hàng của chi nhánh khác), thay vì tải lại trang
            // làm mất sạch lưới hàng vừa dựng.
            V2.luuHop($m, id ? URL_BASE + '/' + id : URL_STORE, id ? 'PUT' : 'POST', f, $(this));
        });

        // ---------- Mở phiếu đã có ----------
        $(document).on('click', '.detail-item, .edit_bt', function () {
            const id = Number($(this).closest('.item').data('id') || $(this).data('id') || 0);
            if (!id) return;

            $.getJSON(URL_BASE + '/' + id)
                .done((r) => moPhieuCu(r.data || {}))
                .fail((x) => toastr.error((x.responseJSON && x.responseJSON.message) || 'Không đọc được phiếu.'));
        });

        /**
         * Mở một phiếu đã lưu.
         *
         * Phiếu ĐÃ DUYỆT chỉ xem: kho hai đầu đã đổi theo nó nên API khoá lại, và
         * bày nút Lưu ra chỉ để bấm vào rồi ăn lỗi. Cờ `can_edit` do server tính —
         * chép luật sang giao diện là hai bên lệch nhau vào một ngày nào đó.
         */
        function moPhieuCu(p) {
            const $m = $mc();
            donHopPhieu();

            $m.find('#transfer_slip_id').val(p.id || '');
            $m.find('#transfer_updated_at').val(p.updated_at || '');
            PHIEU_DANG_XEM = p || {};
            $m.find('.pdc-ma-phieu').text(p.transfer_code || '');
            $m.find('#voucher_number').val(p.transfer_code || '');
            $m.find('#from_warehouse').val(String(p.from_shop_id || '')).trigger('change.select2');
            $m.find('#to_warehouse').val(String(p.to_shop_id || '')).trigger('change.select2');
            $m.find('#receiving_staff').val(String(p.receiver_id || '')).trigger('change.select2');
            $m.find('#note').val(p.note || '');
            $m.find('#status').val(NHAN_TRANG_THAI[p.status] || '');
            if (p.created_at) {
                $m.find('#created_at').val(moment(p.created_at).format('DD-MM-YYYY HH:mm'));
            }

            // Đặt TRƯỚC veLuoi: lưới đọc cờ này lúc dựng từng dòng.
            CHI_XEM = !p.can_edit;

            DONG = (p.items || []).map((it) => ({
                key: ++dongSeq,
                variant_id: it.product_variant_id,
                product_name: it.product_name || '',
                variant_name: it.variant_name || '',
                sku: it.variant_sku || '',
                unit_name: it.unit_name || '',
                // Tồn lấy con số HÔM NAY của kho xuất (server tra kèm), không phải
                // con số lúc lập phiếu: phiếu tuần trước có thể đã không còn đủ hàng.
                ton: Math.max(0, Number(it.remaining_stock || 0)),
                unit_cost: Number(it.unit_cost || 0),
                quantity: Number(it.quantity || 0),
                lot_number: it.lot_number || '',
                expire_date: it.expire_date ? String(it.expire_date).slice(0, 10) : '',
            }));
            veLuoi();

            khoaHop();
            $m.find('.save-slip').toggleClass('d-none', CHI_XEM);
            $m.find('.pdc-in').removeClass('d-none');
            $m.modal('show');
        }

        // ---------- Xoá ----------
        $(document).on('click', '.delete-item', function (e) {
            e.stopPropagation();
            const id = Number($(this).closest('.item').data('id') || 0);
            $('#deleteValue').val(id);
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            const ids = idsDaChon();
            if (!ids.length) return toastr.warning('Chưa chọn phiếu nào để xoá.');

            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#deleteItem .delete-value', function () {
            const ids = String($('#deleteValue').val() || '').split(',').filter(Boolean);
            if (!ids.length) return;
            $('#deleteItem').modal('hide');

            // Xoá lần lượt: API chỉ nhận phiếu LƯU TẠM, nên chọn nhầm một phiếu đã
            // duyệt thì chỉ mình nó bị từ chối, những phiếu còn lại vẫn xoá được.
            (function xoaTiep(i) {
                if (i >= ids.length) {
                    V2.napLai(location.href, false);

                    return;
                }
                fetch(URL_BASE + '/' + ids[i], {
                    method: 'POST',
                    body: new URLSearchParams({
                        _token: $('meta[name="csrf-token"]').attr('content'),
                        _method: 'DELETE',
                    }),
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then((res) => res.json().then((r) => ({ ok: res.ok, body: r || {} })))
                    .then((r) => {
                        r.ok
                            ? toastr.success(r.body.message || 'Đã xoá phiếu.')
                            : toastr.error(r.body.message || 'Không xoá được một phiếu.');
                    })
                    .catch(() => toastr.error('Không gửi được lượt xoá.'))
                    .finally(() => xoaTiep(i + 1));
            })(0);
        });

        // =====================================================================
        //  Xuất tệp và In — dựng từ chính bảng đang hiện, không cần API
        // =====================================================================
        function bangDangHien() {
            const dong = [];
            $('table.table-transfer.none_mobile tr').each(function () {
                const o = [];
                $(this).find('th, td').each(function () {
                    const $o = $(this);
                    // Bỏ cột chọn và cột Hành động: xuất ra tệp thì hai cột đó rỗng.
                    if ($o.hasClass('not-export')) return;
                    if ($o.css('display') === 'none') return;
                    o.push($o.text().trim());
                });
                if (o.length) dong.push(o);
            });

            return dong;
        }

        $(document).on('click', '.btn_export', function () {
            const dong = bangDangHien();
            if (dong.length <= 1) return toastr.warning('Bảng đang trống, chưa có gì để xuất.');

            const noi = '﻿' + dong.map((h) => h.map((o) => {
                const v = String(o == null ? '' : o);

                return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
            }).join(',')).join('\n');

            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([noi], { type: 'text/csv;charset=utf-8;' }));
            a.download = 'phieu-dieu-chuyen-' + moment().format('YYYYMMDD-HHmmss') + '.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
        });

                // =====================================================================
        //  IN PHIẾU
        //
        //  Hai đường vào, MỘT khuôn in: nút In trong hộp phiếu (in đúng cái đang
        //  mở), và Nâng cao → In ngoài bảng (in những phiếu ĐÃ TICK). Cùng cách
        //  làm với màn Phiếu mua hàng.
        //
        //  Nút trong hộp chỉ hiện với phiếu ĐÃ LƯU — cùng quy ước với màn Phiếu
        //  mua hàng. Tờ in mang mã phiếu, mà mã chỉ có sau khi lưu.
        //
        //  Trước bản này, nút In trong hộp được BÀY RA nhưng không gắn handler
        //  nào — bấm vào không có gì xảy ra, không cả một dòng báo lỗi.
        // =====================================================================

        const ngayVN = (v) => (v ? moment(String(v)).format('DD-MM-YYYY') : '');

        /** Cộng hai con số tổng — một chỗ cộng duy nhất cho cả hai đường vào. */
        function congBaoDC(b) {
            b.tongSL = b.dong.reduce((t, d) => t + d.sl, 0);
            b.cong = b.dong.reduce((t, d) => t + d.tien, 0);

            return b;
        }

        /** Bản in gom từ HỘP PHIẾU đang mở. */
        function baoPhieuDC() {
            const $m = $mc();
            const p = PHIEU_DANG_XEM;
            const ten = (sel) => $m.find(sel + ' option:selected').text().trim();

            const dong = DONG.map((d, i) => ({
                stt: i + 1,
                sku: d.sku || '',
                ten: [d.product_name, d.variant_name].filter(Boolean).join(' · '),
                donVi: d.unit_name || '',
                sl: Number(d.quantity) || 0,
                gia: Number(d.unit_cost) || 0,
                tien: Math.round((Number(d.unit_cost) || 0) * (Number(d.quantity) || 0)),
                lo: d.lot_number || '',
                han: ngayVN(d.expire_date),
            }));

            return congBaoDC({
                ma: p.transfer_code || '',
                khoXuat: ten('#from_warehouse'),
                khoNhan: ten('#to_warehouse'),
                nguoiNhan: ten('#receiving_staff'),
                nguoiLap: p.creator_name || '',
                ngay: $m.find('#created_at').val() || '',
                ghiChu: $m.find('#note').val() || '',
                trangThai: $m.find('#status').val() || '',
                dong,
            });
        }

        /** Bản in gom từ một phiếu ĐÃ LƯU, đọc thẳng từ API. */
        function baoTuPhieuDC(p) {
            const dong = (p.items || []).map((it, i) => {
                const sl = Number(it.quantity) || 0;
                const gia = Number(it.unit_cost) || 0;

                return {
                    stt: i + 1,
                    sku: it.variant_sku || '',
                    ten: [it.product_name, it.variant_name].filter(Boolean).join(' · '),
                    donVi: it.unit_name || '',
                    sl,
                    gia,
                    tien: Math.round(gia * sl),
                    lo: it.lot_number || '',
                    han: ngayVN(it.expire_date),
                };
            });

            return congBaoDC({
                ma: p.transfer_code || '',
                khoXuat: p.from_shop_name || '',
                khoNhan: p.to_shop_name || '',
                nguoiNhan: p.receiver_name || '',
                nguoiLap: p.creator_name || '',
                ngay: p.created_at ? moment(p.created_at).format('DD-MM-YYYY HH:mm') : '',
                ghiChu: p.note || '',
                trangThai: NHAN_TRANG_THAI[p.status] || p.status || '',
                dong,
            });
        }

        const KIEU_IN =
            'body{font:13px/1.5 system-ui,Arial,sans-serif;color:#1a2b58;padding:24px}'
            + '.to{page-break-after:always}.to:last-child{page-break-after:auto}'
            + 'h1{font-size:19px;margin:0 0 4px}.ph{color:#6c757d;margin:0 0 16px}'
            + '.ho{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 24px;margin-bottom:16px}'
            + '.ho b{display:inline-block;min-width:130px}'
            + 'table{width:100%;border-collapse:collapse}'
            + 'th,td{border:1px solid #cfd6e4;padding:5px 7px}'
            + 'th{background:#f4f6f8;font-size:12px}.p{text-align:right}.g{text-align:center}'
            + '.tg{margin-top:14px;width:auto;margin-left:auto}'
            + '@media print{body{padding:0}}';

        /** Một tờ phiếu. Nhiều phiếu thì mỗi tờ một trang giấy. */
        function toPhieuDC(b) {
            const hang = b.dong.map((d) => '<tr>'
                + '<td class="g">' + d.stt + '</td><td>' + esc(d.sku) + '</td><td>' + esc(d.ten) + '</td>'
                + '<td class="g">' + esc(d.donVi) + '</td><td class="p">' + nhomSo(d.sl) + '</td>'
                + '<td class="p">' + nhomSo(d.gia) + '</td><td class="p">' + nhomSo(d.tien) + '</td>'
                + '<td class="g">' + esc(d.lo || '—') + '</td><td class="g">' + esc(d.han || '—') + '</td>'
                + '</tr>').join('');

            return '<div class="to">'
                + '<h1>{{ __('message.transfer-slip') }} ' + esc(b.ma) + '</h1>'
                + '<p class="ph">' + esc(b.trangThai) + '</p>'
                + '<div class="ho">'
                    + '<div><b>{{ __('message.output_warehouse') }}:</b> ' + esc(b.khoXuat || '—') + '</div>'
                    + '<div><b>{{ __('message.input_warehouse') }}:</b> ' + esc(b.khoNhan || '—') + '</div>'
                    + '<div><b>{{ __('message.creator') }}:</b> ' + esc(b.nguoiLap || '—') + '</div>'
                    + '<div><b>{{ __('message.receiving_staff') }}:</b> ' + esc(b.nguoiNhan || '—') + '</div>'
                    + '<div><b>{{ __('message.warehouse_date') }}:</b> ' + esc(b.ngay || '—') + '</div>'
                    + '<div><b>{{ __('message.note') }}:</b> ' + esc(b.ghiChu || '—') + '</div>'
                + '</div>'
                + '<table><thead><tr><th>#</th>'
                    + '<th>{{ __('message.product_code') }}</th><th>{{ __('message.product_name') }}</th>'
                    + '<th>{{ __('message.unit_of_measurement') }}</th>'
                    + '<th>{{ __('message.transfer_quantity') }}</th>'
                    + '<th>{{ __('message.unit_price') }}</th><th>{{ __('message.money_into') }}</th>'
                    + '<th>{{ __('message.batch_number') }}</th><th>{{ __('message.expiry_date') }}</th>'
                + '</tr></thead><tbody>' + hang + '</tbody></table>'
                + '<table class="tg"><tbody>'
                    + '<tr><th>{{ __('message.transfer_quantity') }}</th>'
                    + '<td class="p">' + nhomSo(b.tongSL) + '</td></tr>'
                    + '<tr><th>{{ __('message.total_money') }}</th>'
                    + '<td class="p"><b>' + nhomSo(b.cong) + '</b></td></tr>'
                + '</tbody></table></div>';
        }

        /** Mở cửa sổ in với một hay nhiều tờ phiếu. */
        function inCacToDC(ds) {
            const w = window.open('', '_blank');
            if (!w) { toastr.error('Trình duyệt đang chặn cửa sổ in.'); return; }

            const ten = ds.length === 1
                ? '{{ __('message.transfer-slip') }} ' + ds[0].ma
                : '{{ __('message.transfer-slip') }} (' + ds.length + ')';

            w.document.write('<!doctype html><html lang="vi"><head><meta charset="utf-8">'
                + '<title>' + esc(ten) + '</title><style>' + KIEU_IN + '</style></head><body>'
                + ds.map(toPhieuDC).join('')
                + '</body></html>');
            w.document.close();
            w.focus();
            w.print();
        }

        $(document).on('click', '#modalCreate .pdc-in', function () {
            if (!DONG.length) return toastr.warning('Phiếu chưa có dòng hàng nào để in.');

            inCacToDC([baoPhieuDC()]);
        });

$(document).on('click', '.btn_print_list', function () {
            // Phải TICK dòng trước. Trước đây nút này in cái BẢNG đang hiện —
            // ra một bản chụp danh sách chứ không phải chứng từ, không ai cầm
            // tờ ấy đi giao nhận hàng giữa hai kho được. Giờ dùng chung khuôn in
            // với nút In trong hộp phiếu, y như màn Phiếu mua hàng.
            const ids = idsDaChon();
            if (!ids.length) {
                toastr.error('Chọn phiếu muốn in ở cột đầu bảng đã.');

                return;
            }

            // Đọc theo ĐÚNG thứ tự đã tick, và chỉ in khi đọc được HẾT: in thiếu
            // một tờ giữa chừng mà không ai hay còn tệ hơn không in.
            Promise.all(ids.map((id) => $.getJSON(URL_BASE + '/' + id).then((r) => r.data || {})))
                .then((ds) => inCacToDC(ds.map(baoTuPhieuDC)))
                .catch(() => toastr.error('Không đọc được phiếu để in.'));
        });
    </script>
@endpush
