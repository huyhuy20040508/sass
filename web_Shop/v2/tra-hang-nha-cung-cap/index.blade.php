{{-- Màn Trả hàng nhà cung cấp dựng theo khuôn v2 (purchase/supplier-return-order:
     index + list + create/edit/view gộp vào một hộp thoại).
     Dữ liệu do TraHangNhaCungCapController đẩy sang: $list, $filters, $meta,
     $thongKe, $nhaCungCap, $nhanVien, $chiNhanh. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\TraHangNhaCungCapController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\TraHangNhaCungCapController::class;
    $stt = ($meta['page'] - 1) * $meta['page_size'];

    $tien = fn ($n) => number_format((float) $n, 0, ',', '.') . '₫';
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngay = function ($v) {
        $t = $v ? strtotime($v) : false;

        return $t ? date('d-m-Y', $t) : '';
    };

    $trangThaiChon = array_filter(explode(',', $filters['status']));

    // Bản v2 xếp "Đã duyệt" lên trước "Lưu tạm" trong khối lọc; hằng số của
    // controller thì theo vòng đời (lưu tạm → duyệt). Chỉ khối lọc đảo thứ tự.
    $thuTuLoc = ['approved', 'draft'];
@endphp

@push('styles')
    <style>
        /* Lấy khối style của purchase/supplier-return-order bản v2, bỏ phần khung
           v2 đã lo sẵn. */
        body { overflow-x: hidden }
        li { list-style-type: none; }
        .select2-container { width: 100% !important; }
        /* Select2 trong hộp thoại phải nổi trên lớp phủ của modal. */
        #modalCreate .select2-container--open, #modalCreate .select2-dropdown { z-index: 1065 !important; }
        #content_create label.form-label { text-align: left !important }
        .table-lines td, .table-lines th { vertical-align: middle; }

        /* ============ HỘP LẬP / SỬA PHIẾU ============
           Bản v2 của màn này xếp thông tin phiếu thành HAI cột, mỗi ô là một
           hàng "nhãn bên trái · ô nhập bên phải" (khác màn Phiếu mua hàng bốn
           cột xếp dọc). Giữ đúng hình đó, chỉ chốt lại chiều cao cho mọi ô bằng
           nhau — bản gốc để Bootstrap tự lo nên hàng nào có select2 thì lùn hơn
           hàng bên cạnh. */
        #content_create { --thn-o: 34px; }

        /* Thanh có tên: cụm nút lưu ở trên cùng của hộp thoại.
           Bên v2 đây là `card-header` của thẻ đầu tiên, tức padding `.5rem 1rem`
           của Bootstrap rồi mới tới `card-body` padding `1rem`. Bỏ padding ngang
           (thân hộp thoại đã có lề riêng), giữ nguyên khoảng dọc: 8px trên/dưới
           thanh, 16px xuống tới khối thông tin phiếu. */
        .thn-thanh {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px; flex-wrap: wrap;
            padding: 8px 0; border-bottom: 1px solid #e9ecef; margin-bottom: 16px;
        }
        /* margin-left:auto chứ không trông vào `space-between`: lập mới thì thẻ
           mã phiếu rỗng nên ẩn, còn mỗi cụm nút, và `space-between` với một
           phần tử sẽ đẩy nó về TRÁI. */
        .thn-thanh-nut { display: flex; gap: 8px; margin-left: auto; }
        .thn-thanh-nut .bt { margin: 0; }
        .thn-thanh-nut .bt i { margin-right: 4px; }
        .thn-ma-phieu { margin: 0; font-size: 16px; font-weight: 600; color: #1a2b58; }
        .thn-ma-phieu:empty { display: none; }
        /* style.css của vỏ v2 chỉ có luật :hover cho .btn-print, thiếu màu lúc
           thường nên nút In ra trắng trơn. Bù đúng tông xanh của v2. */
        .thn-thanh-nut .btn-print {
            background: #026b97; border: 1px solid #026b97; color: #fff;
        }
        /* Vỏ v2 chỉ đặt `div.btn_top_content { display: flex }`, không có gap. */
        .btn_top_content { gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn_top_content > * { margin: 0; }

        /* Hai cột thông tin phiếu — hai `col-12 col-lg-6` của v2, nên khoảng
           giữa chúng là đúng một máng lưới Bootstrap: 24px. */
        .thn-form {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0 24px; margin-bottom: 8px;
        }
        .thn-cot { min-width: 0; }

        /* MỘT ô: nhãn bên trái, ô nhập bên phải — đúng `col-4 / col-8` của v2, và
           mỗi hàng cách nhau `mb-2` = 8px như bên đó. Nhãn dài thì cắt bằng "…"
           chứ KHÔNG xuống dòng: xuống dòng là hàng đó cao hơn hàng cùng thứ tự ở
           cột kia và hai cột lệch nhau từ đó trở đi. */
        .thn-o {
            display: grid; grid-template-columns: 33.3333% 1fr; align-items: center;
            gap: 0 24px; margin-bottom: 8px;
        }
        .thn-o > .form-label {
            margin: 0; font-size: 13px; font-weight: 500; color: #1a2b58;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .thn-o .form-control,
        .thn-o .select2-container--default .select2-selection--single {
            height: var(--thn-o); min-height: var(--thn-o);
        }
        .thn-o .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(var(--thn-o) - 2px);
        }
        .thn-o .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(var(--thn-o) - 2px);
        }
        /* Ô khoá: tô nền xám cho thấy ngay là chỉ để đọc. CSS của v2 ép màu chữ
           mọi input nên chỉ dựa vào thuộc tính disabled thì nhìn y hệt ô gõ được. */
        .thn-o .form-control:disabled { background-color: #f4f6f8; color: #6c757d !important; }

        /* Đơn vị nằm TRONG khung ô, không đứng rời bên ngoài. */
        .thn-o-donvi { position: relative; }
        .thn-o-donvi .form-control { padding-right: 42px; }
        .thn-o-donvi-chu {
            position: absolute; right: 1px; top: 1px; bottom: 1px;
            display: flex; align-items: center; padding: 0 10px;
            border-left: 1px solid #dee2e6; border-radius: 0 4px 4px 0;
            background: #f4f6f8; color: #6c757d; font-size: 13px; pointer-events: none;
        }

        @media (max-width: 991px) {
            .thn-form { grid-template-columns: minmax(0, 1fr); gap: 0; }
        }
        @media (max-width: 575px) {
            .thn-o { grid-template-columns: minmax(0, 1fr); gap: 4px; }
        }

        /* Bộ công cụ của khối hàng hoá — ô chọn phiếu mua + dropdown Nâng cao.
           `min-width: 0` là chỗ mấu chốt: phần tử của flex mặc định không co
           xuống dưới bề rộng nội dung, mà "nội dung" của ô chọn là dòng option
           dài nhất — nên nó đội thân hộp thoại rộng ra trên màn hẹp. */
        .thn-hang-cong-cu { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
        .thn-hang-cong-cu > * { min-width: 0; }
        .thn-hang-cong-cu .select-purchase-slip:not(.select2-hidden-accessible) {
            width: 320px; flex: 0 1 320px; max-width: 100%;
        }
        .thn-hang-cong-cu .select2-container { width: 320px !important; flex: 0 1 320px; max-width: 100%; }
        .thn-hang-cong-cu .form-control,
        .thn-hang-cong-cu .bt,
        .thn-hang-cong-cu .select2-container--default .select2-selection--single {
            height: var(--thn-o); min-height: var(--thn-o);
        }
        .thn-hang-cong-cu .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(var(--thn-o) - 2px);
        }
        .thn-hang-cong-cu .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(var(--thn-o) - 2px);
        }
        .thn-hang-cong-cu .bt { display: inline-flex; align-items: center; margin: 0; }
        /* Bên v2 khối hàng hoá là một thẻ riêng mang `mt-3`, nên nó cách khối
           thông tin phiếu đúng 16px. */
        .content_midd_title.thn-thanh-hang {
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px; flex-wrap: wrap; margin-top: 16px;
        }
        .content_midd_title.thn-thanh-hang > h4 { margin: 0; }
        /* custom.css của vỏ v2 giấu `div.content_midd_title h4` dưới 992px — luật
           dành cho tiêu đề TRANG, nhưng nó ăn luôn tiêu đề khối hàng hoá trong
           hộp thoại. Giành lại bằng độ ưu tiên của #modalCreate. */
        #modalCreate .content_midd_title h4 { display: block; }
        @media (max-width: 767px) {
            .thn-hang-cong-cu { width: 100%; }
            .thn-hang-cong-cu .select-purchase-slip:not(.select2-hidden-accessible),
            .thn-hang-cong-cu .select2-container { width: 100% !important; }
        }

        /* Khối tiền: bên v2 là `col-12 mt-3 mb-3`, hai hàng bên trong mang `mx-2`.
           Giữ nguyên bộ lề ấy trong khuôn dưới; ở đây chỉ chặn `.row` cuối cùng
           đẩy thêm lề dưới, vì thân hộp thoại đã có lề riêng. */
        .wrapper-money-into > .row:last-child { margin-bottom: 0 !important; }

        /* Lưới hàng cuộn ngang trong khung của nó, không đẩy cả hộp thoại rộng ra. */
        .thn-luoi { overflow-x: auto; }

        /* ---- Canh lưới hàng ----
           style.css của v2 ép `th, td { text-align: center !important }` cho MỌI
           bảng. Với lưới nhập liệu thì hỏng: con số canh giữa không so được với
           nhau. Ba lớp dưới đây kê lại, và phải !important mới thắng nổi. */
        .table-lines th.la-chu, .table-lines td.la-chu { text-align: left !important; }
        .table-lines th.la-so, .table-lines td.la-so { text-align: right !important; }
        .table-lines th.la-giua, .table-lines td.la-giua { text-align: center !important; }

        /* ---- BỀ RỘNG CỘT ----
           TIÊU ĐỀ CỘT MỖI CÁI MỘT DÒNG, đúng như bản v2 cũ. Trước đây tôi ép
           `table-layout: fixed` rồi chia phần trăm: cột bị bó lại nên "Thành
           tiền (chưa VAT)" hay "Số lượng còn lại" gãy thành hai ba hàng chữ, và
           hàng tiêu đề cao gấp đôi hàng dữ liệu — nhìn nặng hẳn so với v2.

           Nay để trình duyệt tự chia theo nội dung như v2: chỉ `width: 100%`,
           KHÔNG `min-width`. Đặt min-width là tự ép bảng rộng hơn khung rồi bắt
           cuộn ngang — bản v2 cũ không hề cuộn, bảng vừa khít chỗ nó có. Bề rộng
           khai bằng phần trăm ở dưới chỉ là GỢI Ý tỉ lệ; cột nào cần rộng hơn để
           tiêu đề nằm gọn một dòng thì tự lấy thêm, và chỉ khi cộng lại thật sự
           không vừa thì khung mới cuộn (`.thn-luoi` có `overflow-x: auto`, y như
           `<div style="overflow-x:auto">` bọc bảng bên v2).

           KHOẢNG ĐỆM trong ô để nguyên `padding: 8px` của v2 (style.css khai cho
           mọi `th, td`) — trước tôi nới thành `8px 10px` nên lưới rộng hơn bản cũ
           một nhịp ở mỗi cột, cộng mười bốn cột lại là lệch hẳn. */
        .table-lines { width: 100%; }
        .table-lines th { white-space: nowrap; vertical-align: bottom; }
        /* Hai cột chữ dài phải cắt bằng "…", không thì mã hàng dài đội cả bảng
           rộng ra; chữ đủ nằm ở thuộc tính title của ô. */
        .table-lines td.la-chu { max-width: 220px; overflow: hidden; text-overflow: ellipsis; }

        .table-lines th.c-stt { width: 3%; }
        .table-lines th.c-ma { width: 9%; }
        .table-lines th.c-ten { width: 13%; }
        .table-lines th.c-dv { width: 6%; }
        .table-lines th.c-sl { width: 8%; }
        .table-lines th.c-slnhap { width: 7%; }
        .table-lines th.c-conlai { width: 8%; }
        .table-lines th.c-gia { width: 7%; }
        .table-lines th.c-tien { width: 10%; }
        .table-lines th.c-vat { width: 4%; }
        .table-lines th.c-tong { width: 10%; }
        .table-lines th.c-lo { width: 6%; }
        .table-lines th.c-han { width: 6%; }
        .table-lines th.c-xoa { width: 3%; }

        /* Viền và nền khai THẲNG ở đây, không dựa vào `.form-control` của Bootstrap:
           v2 nạp năm tệp CSS sau Bootstrap và đã có tiền lệ đè lên nó. Ô nhập mất
           viền thì nhìn y như chữ thường, không ai biết chỗ nào gõ được.

           BỀ RỘNG cố định 90px, không `width: 100%`: bên v2 ô số lượng cũng khai
           cứng (`style="width: 75px"`), và từ khi bảng bỏ `table-layout: fixed`
           thì một ô nhập 100% khiến cột phải rộng theo bề ngang mặc định của thẻ
           input — cột Số lượng phình gấp đôi mấy cột số bên cạnh. 90px chứ không
           75px vì số ở đây có chấm phân cách hàng nghìn. */
        .table-lines .ip-line {
            width: 90px; height: 32px; padding: 2px 8px; font-size: 13px; text-align: right;
            border: 1px solid #dee2e6; border-radius: 4px; background-color: #fff; color: #212529;
        }
        .table-lines .ip-line:focus { border-color: #86b7fe; outline: 0; }
        .table-lines .ip-line:disabled { background-color: #f4f6f8; color: #6c757d !important; }

        /* Dòng hết phần trả được: tô nền hồng nhạt và khoá ô nhập. Trước đây còn
           một dòng chữ "tối đa N" ngay dưới ô, nhưng nó làm mỗi hàng cao hai
           tầng và ô nhập lệch khỏi cột số bên cạnh — con số ấy nay nằm ở thuộc
           tính title của ô, rê chuột vào là thấy. */
        .table-lines tr.is-het td { background-color: #fff9f9; }

        /* Dải thống kê dưới tiêu đề trang. */
        .thn-sum { font-size: 13px; color: #595959; }
        .thn-sum b { color: #262626; }
        .thn-sum em { font-style: normal; color: #bfbfbf; font-size: 12px; }
        .money-no { color: #d4380d; }

        /* Bảng danh sách: KHÔNG `min-width`, y như bản v2 cũ — bên đó `table` chỉ
           có `width: 100%` nên bảng vừa khít khung và trang KHÔNG cuộn ngang.
           `.table-responsive` vẫn để `overflow-x: auto` làm đường lùi cho lúc nội
           dung thật sự không vừa (tên nhà cung cấp dài, bật đủ mười bốn cột trên
           màn hẹp), đúng như v2 cũng bọc bảng trong `.table-responsive`.

           Bề rộng phần trăm là gợi ý tỉ lệ chứ không phải khuôn cứng: bảng chạy
           `table-layout: auto` nên cột nào cần rộng hơn để tiêu đề khỏi gãy dòng
           thì tự lấy thêm, và tắt bớt cột thì phần còn lại tự giãn ra. */
        .list .table-responsive { overflow-x: auto; }
        table.table-return.none_mobile { width: 100%; }
        table.table-return.none_mobile th { white-space: nowrap; }
        /* Bốn cột chữ tự do phải cắt bằng "…": v2 để `white-space: nowrap` cho mọi
           ô, nên một tên nhà cung cấp hay ghi chú dài sẽ đội cả bảng rộng ra và
           kéo theo thanh cuộn ngang — đúng thứ vừa phải bỏ. Chữ đủ nằm ở thuộc
           tính title của ô. */
        table.table-return.none_mobile td.col-supplier,
        table.table-return.none_mobile td.col-branch,
        table.table-return.none_mobile td.col-creator,
        table.table-return.none_mobile td.col-note {
            max-width: 220px; overflow: hidden; text-overflow: ellipsis;
        }
        table.table-return th.col-check { width: 3%; }
        table.table-return th.col-stt { width: 3%; }
        table.table-return th.col-code { width: 8%; }
        table.table-return th.col-suppliercode { width: 7%; }
        table.table-return th.col-supplier { width: 13%; }
        table.table-return th.col-docdate { width: 7%; }
        table.table-return th.col-branch { width: 8%; }
        table.table-return th.col-items { width: 8%; }
        table.table-return th.col-total { width: 8%; }
        table.table-return th.col-status { width: 7%; }
        table.table-return th.col-stock { width: 7%; }
        table.table-return th.col-creator { width: 8%; }
        table.table-return th.col-note { width: 7%; }
        table.table-return th.col-act { width: 6%; }
    </style>
    {{-- Cột đang tắt ở ô "chọn cột". Là CSS nên nạp lại danh sách bằng AJAX vẫn giữ. --}}
    <style id="cotAnCss"></style>
@endpush

@section('content')
    {{-- Nút mở bộ lọc dạng offcanvas, chỉ hiện trên điện thoại — đúng ba nút của v2. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterSearchKey">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterCreateAt">
                <p class="open-modal-label">{{ __('message.order-created-at') }}</p>
                <div class="icon-for-cta"><i class="fa-regular fa-calendar"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterOrderStatus">
                <p class="open-modal-label">{{ __('message.order_status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-filter"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-supplier-return-order-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2.
             d-none d-lg-block: trên điện thoại các khối này đã đi vào offcanvas. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không có nút "Lọc": gõ hay đổi ô nào là lọc lại ngay. --}}
                        <form action="{{ route('admin.tra-hang-nha-cung-cap.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterSearchKey" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.search') }}</span>
                                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                        class="form-control mt-1 keyword" autocomplete="off"
                                        placeholder="{{ __('message.search_by_name_or_code') }}">
                                </div>
                            </div>

                            <div id="filterCreateAt" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.order-created-at') }}</span>
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

                            <div id="filterOrderStatus" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.order_status') }}</span>
                                    @foreach ($thuTuLoc as $ma)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input status" type="checkbox" name="status[]"
                                                value="{{ $ma }}" id="order_status_{{ $ma }}"
                                                {{ in_array($ma, $trangThaiChon, true) ? 'checked' : '' }}>
                                            <label class="form-check-label ms-2 {{ $C::CHU_TRANG_THAI[$ma] ?? '' }}"
                                                style="font-weight: bold"
                                                for="order_status_{{ $ma }}">{{ $C::TRANG_THAI[$ma] }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div id="filterSupplier" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.supplier') }}</span>
                                    <select name="supplier_id" class="form-control form-select mt-1">
                                        <option value="">{{ __('message.all') }}</option>
                                        @foreach ($nhaCungCap as $ncc)
                                            <option value="{{ $ncc['id'] }}"
                                                {{ $filters['supplier_id'] === (int) $ncc['id'] ? 'selected' : '' }}>
                                                {{ ($ncc['code'] ?? '') ? $ncc['code'] . ' - ' : '' }}{{ $ncc['name'] ?? '' }}
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
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang. --}}
                    <h1 class="tieu-de-trang">{{ __('message.supplier_return_slip') }}</h1>

                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green btn_create">{{ __('message.create_new') }}</a>
                            <a type="button" class="bt btn_red mass-delete">{{ __('message.delete') }}</a>

                            <div class="dropdown dropdown_advanced">
                                <button class="bt btn_advanced dropdown-toggle py-1" type="button" data-bs-toggle="dropdown"
                                    aria-expanded="false">{{ __('message.advanced') }}</button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item"
                                            href="{{ route('admin.tra-hang-nha-cung-cap.export', request()->query()) }}">{{ __('message.export-excel') }}</a>
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

                {{-- Dải thống kê của CẢ SỔ, không phải của trang đang xem. --}}
                <span class="thn-sum d-block m-2">
                    {{ $C::TRANG_THAI['draft'] }}: <b>{{ $so($thongKe['draft']) }}</b> ·
                    {{ $C::TRANG_THAI['approved'] }}: <b>{{ $so($thongKe['approved']) }}</b> ·
                    Đã trả lại: <b class="money-no">{{ $tien($thongKe['returned_amount']) }}</b>
                    <em>(chưa tính phiếu lưu tạm)</em>
                </span>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-return none_mobile">
                            <tr>
                                <th class="text-center not-export col-check">
                                    <input class="form-check-input item-select-all" type="checkbox">
                                </th>
                                <th class="text-center col-stt">{{ __('message.stt') }}</th>
                                <th class="text-left col-code">{{ __('message.receipt-code') }}</th>
                                <th class="text-left col-suppliercode">{{ __('message.supplier-code') }}</th>
                                <th class="text-left col-supplier">{{ __('message.supplier') }}</th>
                                <th class="text-center col-docdate">{{ __('message.document_date') }}</th>
                                <th class="text-left col-branch">{{ __('message.branch') }}</th>
                                <th class="text-right col-items">{{ __('message.goods-total-money') }}</th>
                                <th class="text-right col-total">{{ __('message.total_money') }} ( + {{ __('message.vat') }})</th>
                                <th class="text-center col-status">{{ __('message.order-status') }}</th>
                                <th class="text-center col-stock">{{ __('message.warehouse_status') }}</th>
                                <th class="text-left col-creator">{{ __('message.creator') }}</th>
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
                                        {{-- Bấm mã phiếu là mở phiếu, đúng lối của v2 — lưu tạm thì sửa được,
                                             đã duyệt thì chỉ xem. --}}
                                        <a type="button" data-id="{{ $id }}"
                                            class="edit_bt detail-item text-decoration-none" title="{{ __('message.detail') }}">
                                            {{ ($p['return_code'] ?? '') ?: '—' }}
                                        </a>
                                    </td>
                                    <td class="text-left col-suppliercode">{{ $p['supplier_code'] ?? '' }}</td>
                                    <td class="text-left col-supplier" title="{{ $p['supplier_name'] ?? '' }}">
                                        {{ $p['supplier_name'] ?? '' }}
                                    </td>
                                    <td class="text-center col-docdate">{{ $ngay($p['document_date'] ?? null) ?: '-' }}</td>
                                    <td class="text-left col-branch" title="{{ $p['branch_name'] ?? '' }}">{{ $p['branch_name'] ?? '' }}</td>
                                    <td class="text-right col-items">{{ $tien($p['items_amount'] ?? 0) }}</td>
                                    <td class="text-right col-total"><b>{{ $tien($p['total_amount'] ?? 0) }}</b></td>
                                    <td class="text-center col-status">
                                        <b class="{{ $C::CHU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                    </td>
                                    <td class="text-center col-stock">
                                        <b class="{{ $C::CHU_TRANG_THAI_KHO[$tt] ?? '' }}">{{ $C::TRANG_THAI_KHO[$tt] ?? '' }}</b>
                                    </td>
                                    <td class="text-left col-creator" title="{{ $p['creator_name'] ?? '' }}">{{ $p['creator_name'] ?? '' }}</td>
                                    <td class="text-left col-note" title="{{ $p['note'] ?? '' }}">{{ $p['note'] ?? '' }}</td>
                                    {{-- Con mắt mở phiếu. Xoá chỉ hiện ở phiếu LƯU TẠM — y như v2, bên đó
                                         cột này cũng chỉ bày nút xoá khi `$item->status == 0`. --}}
                                    <td class="action not-export col-act">
                                        <a class="detail-item" type="button" title="{{ __('message.detail') }}"><i class="fa fa-eye"></i></a>
                                        @if ($nhap)
                                            <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="text-center py-4">{{ $C::EMPTY_TEXT }}</td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai — đúng như v2. --}}
                        <div class="table-return none_desktop">
                            <div class="d-flex align-items-center justify-content-between gap-1 p-2 border">
                                <input class="form-check-input item-select-all" type="checkbox">
                                <div class="fw-bold" style="flex: 1">{{ __('message.supplier') }}</div>
                                <div class="fw-bold">{{ __('message.goods-total-money') }}</div>
                            </div>
                            @foreach ($list as $p)
                                @php
                                    $id = (int) ($p['id'] ?? 0);
                                    $tt = $p['status'] ?? 'draft';
                                @endphp
                                <div class="item" data-id="{{ $id }}" data-status="{{ $tt }}">
                                    <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    <div class="d-flex flex-column detail-item" role="button" style="flex: 1">
                                        <span class="fw-semibold">{{ $p['supplier_name'] ?? '' }}</span>
                                        <span style="font-size: 14px">{{ $p['return_code'] ?? '' }}</span>
                                    </div>
                                    <div class="d-flex text-right show_quantity gap-2">{{ $tien($p['items_amount'] ?? 0) }}</div>
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
         Bốn khối xếp dọc, đúng thứ tự của màn create bên v2:
           1. Hàng nút Lưu tạm / Duyệt nằm TRÊN CÙNG, canh phải.
           2. Thông tin phiếu: 2 cột, mỗi ô là một hàng nhãn · ô nhập.
           3. Thông tin hàng hoá: chọn phiếu mua rồi lưới hàng tự đổ ra.
           4. Khối tiền canh phải: trước thuế · tiền thuế · tổng tiền.

         Hàng KHÔNG chọn lẻ được: v2 chỉ cho trả những dòng đã có trên phiếu mua,
         vì số lượng trả phải kẹp theo số đã mua của đúng dòng đó. --}}
    <div class="modal" id="modalCreate" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-auto" style="min-width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.create') }}/{{ __('message.edit') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body pt-0" id="content_create">
                    {{-- 1. Thanh thao tác — đúng `card-header` của v2: mã phiếu và tên bên
                         bán nằm trái, cụm nút nằm phải. Lập mới thì bên trái để trống.

                         Nút nào hiện là do moPhieu() quyết theo cờ API trả về: cùng một
                         hộp thoại dùng cho ba cảnh — lập mới, sửa phiếu lưu tạm, xem
                         phiếu đã duyệt. --}}
                    <div class="thn-thanh">
                        <h4 class="thn-ma-phieu"></h4>
                        <div class="thn-thanh-nut">
                            {{-- HAI bộ nút, đúng như v2: bên đó `create.blade.php` và
                                 `edit.blade.php` là hai khuôn khác nhau nên thanh nút cũng
                                 khác. Lập mới thì Lưu tạm / Duyệt; mở phiếu đã lưu thì
                                 Lưu / In / Duyệt. Xoá không nằm đây — v2 để ở cột Hành động. --}}
                            <button type="button" class="bt btn_gray save-order thn-nut-moi" data-duyet="0">
                                {{ __('message.status-temporary') }}
                            </button>
                            <button type="button" class="bt btn_green save-order thn-nut-moi" data-duyet="1">
                                {{ __('message.approve') }}
                            </button>

                            <button type="button" class="bt btn btn-success save-order thn-nut-sua d-none" data-duyet="0">
                                {{ __('message.save') }}
                            </button>
                            <button type="button" class="bt btn btn-print thn-in d-none">
                                <i class="fa-solid fa-print"></i> {{ __('message.print') }}
                            </button>
                            <button type="button" class="bt btn btn-primary save-order thn-nut-sua d-none" data-duyet="1">
                                {{ __('message.approve') }}
                            </button>
                        </div>
                    </div>

                    {{-- 2. Thông tin phiếu: 2 cột như v2. Ô nào bên đó `disabled` thì ở
                         đây cũng khoá — hồ sơ bên bán tự điền theo nhà cung cấp, còn
                         thông tin phiếu do hệ thống đặt. --}}
                    <div class="thn-form">
                        {{-- Cột trái — bên bán và ngày tháng của chứng từ. --}}
                        <div class="thn-cot">
                            <div class="thn-o">
                                <label class="form-label" for="thnNCC">
                                    {{ __('message.supplier') }} <span class="required">*</span>
                                </label>
                                <select id="thnNCC" name="supplier_id" class="form-control supplier_id">
                                    <option value="0">{{ __('message.select-supplier') }}</option>
                                    @foreach ($nhaCungCap as $ncc)
                                        <option value="{{ $ncc['id'] }}"
                                            data-name="{{ $ncc['name'] ?? '' }}"
                                            data-phone="{{ $ncc['phone'] ?? '' }}"
                                            data-address="{{ $ncc['address'] ?? '' }}"
                                            data-address-2="{{ $ncc['address_line2'] ?? '' }}"
                                            data-rep-phone="{{ $ncc['representative_phone'] ?? '' }}">
                                            {{ ($ncc['code'] ?? '') ? $ncc['code'] . ' - ' : '' }}{{ $ncc['name'] ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.address') }}</label>
                                <input type="text" name="address" class="form-control" disabled>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.address_2') }}</label>
                                <input type="text" name="address_line2" class="form-control" disabled>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.contact_phone') }}</label>
                                <input type="text" name="contact_person_phone" class="form-control" disabled>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.document_date') }}</label>
                                {{-- Ngày chứng từ do hệ thống đặt, y hệt v2 — khoá lại. --}}
                                <input type="text" name="document_date" class="form-control" disabled>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.expiry_date') }}</label>
                                <input type="text" readonly name="expired_date" class="form-control ip-ngay">
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.note') }}</label>
                                <input type="text" maxlength="200" name="note" class="form-control note">
                            </div>
                        </div>

                        {{-- Cột phải — người phụ trách, thuế và thông tin phiếu. --}}
                        <div class="thn-cot">
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.purchase_staff') }}</label>
                                <select name="purchaser_id" class="form-control purchaser_id">
                                    <option value="0">— Chưa phân công —</option>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}">
                                            {{ ($nv['code'] ?? '') ? $nv['code'] . ' - ' : '' }}{{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.receiver_delivery_note') }}</label>
                                <input type="text" maxlength="50" name="receiver_delivery_note" class="form-control">
                            </div>
                            <div class="thn-o">
                                <label class="form-label">VAT ({{ __('message.order_value') }})</label>
                                {{-- VAT lấy theo phiếu mua đang chọn, không gõ tay — như v2. --}}
                                <div class="thn-o-donvi">
                                    <input type="text" name="vat" class="form-control vat" value="0" disabled>
                                    <span class="thn-o-donvi-chu">%</span>
                                </div>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.branch') }}</label>
                                <input type="text" name="branch" class="form-control" disabled
                                    value="{{ $chiNhanh['name'] }}">
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.created_po_by') }}</label>
                                <input type="text" name="created_by_name" class="form-control" disabled
                                    value="{{ session('api.user.full_name') ?? '' }}">
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.created_po_at') }}</label>
                                <input type="text" name="created_date" class="form-control" disabled>
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.document_type') }}</label>
                                <input type="text" class="form-control" disabled value="{{ $C::LOAI_CHUNG_TU }}">
                            </div>
                            <div class="thn-o">
                                <label class="form-label">{{ __('message.status') }}</label>
                                <input type="text" name="return_status" class="form-control" disabled
                                    value="{{ __('message.create-btn') }}">
                            </div>
                        </div>
                    </div>

                    {{-- 3. Thông tin hàng hoá — tiêu đề bên trái, ô chọn phiếu mua bên
                         phải, cùng một hàng và cùng chiều cao với nhau. --}}
                    <div class="content_midd_title thn-thanh-hang">
                        <h4>{{ __('message.goods-information') }}</h4>
                        <div class="thn-hang-cong-cu">
                            <select class="form-control select-purchase-slip" name="purchase_order_id" disabled>
                                <option value="0">Chọn nhà cung cấp trước</option>
                            </select>
                            <div class="dropdown menu-advanced-dropdown">
                                <button class="bt btn_advanced dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" aria-expanded="false">{{ __('message.advanced') }}</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item export-line" type="button">{{ __('message.export-excel') }}</a></li>
                                    <li><a class="dropdown-item reset-line" type="button">Đổ lại từ phiếu mua</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <p id="line_error" class="text-danger mb-2" style="display:none;"></p>

                    <div class="thn-luoi">
                        <table class="table-lines">
                            <tbody>
                                <tr>
                                    {{-- Nhãn cột phải canh CÙNG BÊN với nội dung bên dưới nó:
                                         tiêu đề canh giữa mà con số canh phải thì mỗi cột lệch
                                         một kiểu, đọc cả hàng như răng cưa. --}}
                                    <th class="la-giua c-stt">{{ __('message.stt') }}</th>
                                    <th class="la-chu c-ma">{{ __('message.menu-code') }}</th>
                                    <th class="la-chu c-ten">{{ __('message.menu-name') }}</th>
                                    <th class="la-chu c-dv">{{ __('message.unit_of_measure') }}</th>
                                    <th class="la-so c-sl">{{ __('message.quantity') }}</th>
                                    <th class="la-so c-slnhap">{{ __('message.quantity_in') }}</th>
                                    <th class="la-so c-conlai">{{ __('message.remaining_quantity') }}</th>
                                    <th class="la-so c-gia">{{ __('message.import_price') }}</th>
                                    <th class="la-so c-tien">{{ __('message.subtotal_before_vat') }}</th>
                                    <th class="la-so c-vat">{{ __('message.vat') }}</th>
                                    <th class="la-so c-tong">{{ __('message.total_amount_after_vat') }}</th>
                                    <th class="la-chu c-lo">{{ __('message.batch_number') }}</th>
                                    <th class="la-giua c-han">{{ __('message.expiry_date') }}</th>
                                    <th class="la-giua c-xoa not-export"></th>
                                </tr>
                            </tbody>
                            <tbody class="list-menu"></tbody>
                        </table>
                    </div>
                    <p class="text-center text-secondary py-3 mb-0 lines-empty">
                        Chọn nhà cung cấp rồi chọn phiếu mua ở trên — hàng của phiếu đó sẽ hiện ra tại đây.
                    </p>

                    {{-- 4. Khối tiền dựng ĐÚNG HÌNH của v2 (`wrapper-money-into`): hai
                         hàng canh phải, mỗi ô là một nhãn đậm bên trái và một ô CHỈ ĐỌC
                         bên phải. Hàng trên hai ô — chưa VAT và tiền thuế; hàng dưới một
                         ô Tổng tiền đứng riêng. --}}
                    <div class="col-12 mt-3 mb-3 wrapper-money-into">
                        <div class="row justify-content-end mx-2">
                            <div class="col-12 col-lg-5">
                                <div class="row">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label mt-2"><b>{{ __('message.total_subtotal_before_vat') }}</b></label>
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <input type="text" class="form-control tong-tien-hang" value="0₫" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-4">
                                <div class="row">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label mt-2"><b>{{ __('message.total_vat_amount') }}</b></label>
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <input type="text" class="form-control tong-thue" value="0₫" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row justify-content-end my-3 mx-2">
                            <div class="col-12 col-lg-4">
                                <div class="row">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label mt-2"><b>{{ __('message.total_money') }}</b></label>
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <input type="text" class="form-control tong-cong" value="0₫" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
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
                        {{ __('message.delete') }} {{ Str::lower(__('message.return-order-note')) }}
                        {{ Str::lower(__('message.supplier')) }}?
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
        const CSRF = '{{ csrf_token() }}';
        const URL_BASE = @json(url('/admin/supplier-returns'));
        const URL_STORE = @json(route('admin.tra-hang-nha-cung-cap.store'));
        const URL_PHIEU_MUA = @json(route('admin.tra-hang-nha-cung-cap.phieuMua'));
        const URL_DONG_PHIEU = @json(route('admin.tra-hang-nha-cung-cap.dongPhieuMua'));
        const URL_BULK_DEL = @json(route('admin.tra-hang-nha-cung-cap.bulkDestroy'));

        const NHAN_TRANG_THAI = @json(\App\Http\Controllers\TraHangNhaCungCapController::TRANG_THAI);
        const LOAI_CT = @json(\App\Http\Controllers\TraHangNhaCungCapController::LOAI_CHUNG_TU);
        const TEN_CHI_NHANH = @json($chiNhanh['name']);

        // Cả bản ghi của từng dòng — hộp thoại và nút Xoá đọc thẳng ở đây, khỏi rải
        // hơn chục data-* lên mỗi <tr>. Đọc lại sau mỗi lượt nạp danh sách bằng
        // AJAX, không thì dữ liệu là của bảng đã bị thay đi.
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
        const soN = (v) => {
            const n = Number(String(v == null ? '' : v).replace(/[^\d-]/g, ''));
            return Number.isFinite(n) ? n : 0;
        };
        // Số lượng trả là số NGUYÊN: sổ kho của hệ thống này đếm nguyên, nhận 0,5
        // rồi làm tròn lúc ghi kho là mỗi phiếu lệch một ít.
        const soNguyen = (v) => Math.max(0, Math.round(Number(v) || 0));
        const gioNgay = (s) => {
            if (!s) return '';
            const d = new Date(String(s).replace(' ', 'T'));
            return isNaN(d) ? String(s) : d.toLocaleString('vi-VN', {
                day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
            });
        };

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

        // =====================================================================
        //  Bộ lọc — đổi ô nào là lọc lại ngay, gõ thì chờ 400ms
        // =====================================================================
        //
        // Tự dựng URL thay vì submit form: trên điện thoại khung v2 BƯNG khối lọc
        // sang tấm offcanvas, và mỗi lượt chỉ bưng MỘT khối, nên submit lúc đó sẽ
        // đánh rơi mấy ô còn lại.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai() {
            const q = new URLSearchParams();

            ['keyword', 'supplier_id'].forEach((ten) => {
                const v = String(oLoc(ten).val() || '').trim();
                if (v) q.set(ten, v);
            });

            // Hai ô ngày LUÔN được gửi, kể cả khi rỗng: máy chủ mặc định lọc tháng
            // này khi KHÔNG thấy tham số, nên bỏ qua ô rỗng là xoá ngày xong bảng
            // vẫn chỉ có tháng này — xem TraHangNhaCungCapController::filters.
            ['from_date', 'to_date'].forEach((ten) => {
                q.set(ten, String(oLoc(ten).val() || '').trim());
            });
            oLoc('status[]').filter(':checked').each(function () { q.append('status[]', this.value); });

            // Cỡ trang và kiểu sắp xếp không có ô trong khung lọc nhưng phải giữ.
            // Cố ý KHÔNG mang `page`: trang 5 của bộ lọc cũ không còn nghĩa gì.
            const cu = new URLSearchParams(location.search);
            ['page_size', 'sort'].forEach((ten) => {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            V2.napLai(location.pathname + '?' + q);
        }

        let timerTim = null;
        $(document).on('change', '.fillter-box select, .fillter-box input[type="checkbox"]', locLai);
        $(document).on('input', '.fillter-box input[name="keyword"]', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(locLai, 400);
        });

        $(document).on('submit', '#search-form', function (e) {
            e.preventDefault();
            locLai();
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

        /**
         * Kẹp hai ô vào nhau: "từ" không quá "đến", "đến" không dưới "từ", và cả
         * hai không quá hôm nay. Bản v2 cũng gán chéo như vậy nhưng chỉ gán MỘT
         * LẦN trong lượt chọn, nên ranh giới lúc ăn lúc không.
         */
        function ketNgay() {
            const tu = docNgay($('#from_date'));
            const den = docNgay($('#to_date'));

            // Khoảng ngược (chỉ tới được bằng đường dẫn gõ tay): KHÔNG kẹp chéo.
            // Kẹp thì hai lịch có minDate lớn hơn maxDate và mọi ngày đều xám.
            const nguoc = tu && den && tu.isAfter(den, 'day');

            const pTu = lich('#from_date');
            const pDen = lich('#to_date');
            if (pTu) pTu.maxDate = (den && !nguoc) ? den.clone().endOf('day') : homNay.clone();
            if (pDen) {
                pDen.minDate = (tu && !nguoc) ? tu.clone().startOf('day') : null;
                pDen.maxDate = homNay.clone();
            }
        }

        $(document).on('change', '#from_date, #to_date', function () {
            const v = String(this.value || '').trim();
            if (v && !moment(v, KHUON_NGAY, true).isValid()) {
                this.value = '';
                toastr.error('Ngày phải gõ theo khuôn ' + KHUON_NGAY + '.');
            }
            ketNgay();
            locLai();
        });

        $('#from_date, #to_date').each(function () {
            $(this).daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                autoUpdateInput: false,
                autoApply: true,
                // Không phiếu nào lập vào ngày mai.
                maxDate: homNay,
                locale: V2.lichVN(),
            }, function (start) {
                const laTu = this.element.attr('id') === 'from_date';
                $(this.element).val(start.format(KHUON_NGAY));

                // Chọn ra một khoảng ngược thì KÉO ô kia theo: bảng rỗng mà hai ô
                // vẫn nói một khoảng có vẻ hợp lệ là kiểu bí nhất.
                const tu = docNgay($('#from_date'));
                const den = docNgay($('#to_date'));
                if (tu && den && tu.isAfter(den, 'day')) {
                    $(laTu ? '#to_date' : '#from_date').val(start.format(KHUON_NGAY));
                }

                ketNgay();
                locLai();
            });
        });

        ketNgay();

        // =====================================================================
        //  Chọn cột — giữ ở localStorage, tắt bằng CSS nên nạp lại AJAX vẫn còn
        // =====================================================================
        const COT_KEY = 'thn-v2-cot-an';

        function apDungCot() {
            const $cbs = $('.show_col');
            const an = $cbs.filter((i, el) => !el.checked).map((i, el) => String($(el).data('col'))).get();

            document.getElementById('cotAnCss').textContent = an.length
                ? an.map((c) => '.table-return .col-' + c).join(',') + '{display:none}'
                : '';

            $('#show_all')
                .prop('checked', an.length === 0)
                .prop('indeterminate', an.length > 0 && an.length < $cbs.length);

            try { localStorage.setItem(COT_KEY, JSON.stringify(an)); } catch (e) { /* chế độ riêng tư */ }
        }

        (function napCotDaLuu() {
            let an = [];
            try { an = JSON.parse(localStorage.getItem(COT_KEY) || '[]'); } catch (e) { an = []; }
            $('.show_col').each(function () { this.checked = an.indexOf(String($(this).data('col'))) === -1; });
            apDungCot();
        })();

        $(document).on('change', '.show_col', function () {
            // Giữ lại ít nhất một cột: tắt hết thì bảng chỉ còn STT và nút thao tác.
            if ($('.show_col').filter(':checked').length === 0) { this.checked = true; return; }
            apDungCot();
        });
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            if (!this.checked) $('.show_col').first().prop('checked', true);
            apDungCot();
        });

        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        // Bảng vừa thay ruột: đọc lại dữ liệu dòng và áp lại cột đang tắt.
        $(document).on('v2:da-nap', function () {
            ROWS = docDongHienCo();
            apDungCot();
        });

        const idsDaChon = () => $('.item-select:checked').map((i, el) => Number(el.value)).get()
            .filter((v, i, a) => v && a.indexOf(v) === i);

        // =====================================================================
        //  Ô NGÀY TRONG HỘP THOẠI
        // =====================================================================
        //
        // TRONG BỘ NHỚ ngày luôn là YYYY-MM-DD (khuôn API nhận); trên màn hình là
        // DD-MM-YYYY. Hai hàm dưới là chỗ DUY NHẤT đổi qua lại.

        /** YYYY-MM-DD → DD-MM-YYYY để bày lên ô. Rỗng/sai khuôn → rỗng. */
        function ngayVN(iso) {
            const m = moment(String(iso || '').slice(0, 10), 'YYYY-MM-DD', true);

            return m.isValid() ? m.format(KHUON_NGAY) : '';
        }

        /** DD-MM-YYYY → YYYY-MM-DD để gửi đi. Rỗng/sai khuôn → rỗng. */
        function ngayISO(vn) {
            const m = moment(String(vn || '').trim(), KHUON_NGAY, true);

            return m.isValid() ? m.format('YYYY-MM-DD') : '';
        }

        /** Lịch của MỘT ô mở về phía nào, đo theo chỗ trống thật quanh ô. */
        function huongLichCuaO($o) {
            const o = $o[0].getBoundingClientRect();
            // Một tấm lịch đơn của daterangepicker rộng ~300px, cao ~300px.
            const ROI = 300;

            return {
                opens: o.right + ROI > window.innerWidth ? 'left' : 'right',
                drops: o.bottom + ROI > window.innerHeight && o.top > ROI ? 'up' : 'down',
            };
        }

        $(document).on('click', '#modalCreate .ip-ngay', function () {
            const $o = $(this);
            if ($o.prop('disabled') || $o.data('daterangepicker')) return;

            $o.daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                autoUpdateInput: false,
                autoApply: true,
                ...huongLichCuaO($o),
                locale: V2.lichVN(),
            }, function (start) {
                $o.val(start.format(KHUON_NGAY)).trigger('change');
            });
            $o.data('daterangepicker').show();
        });

        // Lịch treo dưới <body> chứ không nằm trong hộp thoại, nên cuộn thân hộp là
        // nó đứng nguyên một chỗ và trỏ vào khoảng không. Đóng lại là xong.
        $(document).on('scroll', '#modalCreate .modal-body', function () {
            $('#modalCreate .ip-ngay').each(function () {
                const l = $(this).data('daterangepicker');
                if (l) l.hide();
            });
        });

        $(document).on('mousedown focusin', '#from_date, #to_date', function () {
            const l = $(this).data('daterangepicker');
            if (l) Object.assign(l, huongLichCuaO($(this)));
        });

        // =====================================================================
        //  Lưới hàng của phiếu
        // =====================================================================
        const $mc = () => $('#modalCreate');

        /** Mỗi phần tử là một dòng hàng đang dựng trong hộp thoại. */
        let DONG = [];
        let dongSeq = 0;
        let VAT_PHIEU = 0;
        let SUA_ID = 0;
        // Mốc sửa của BẢN đang mở — xem chong_ghi_de.go bên API. Rỗng khi lập mới.
        let SUA_MOC = '';
        let CHI_XEM = false;
        // Câu mời ban đầu của lưới hàng — bỏ chọn phiếu mua thì quay lại câu này.
        const EMPTY_DAU = $('#modalCreate .lines-empty').text().trim();

        function baoLoiDong(cau) {
            $('#line_error').text(cau).toggle(!!cau);
        }

        /** Chọn nhà cung cấp thì bốn ô hồ sơ bên dưới tự điền. */
        function veHoSoNCC() {
            const $m = $mc();
            const d = $m.find('.supplier_id option:selected').data() || {};
            $m.find('[name="address"]').val(d.address || '');
            $m.find('[name="address_line2"]').val(d.address2 || d['address-2'] || '');
            $m.find('[name="contact_person_phone"]').val(d.repPhone || d['rep-phone'] || d.phone || '');

            return d;
        }

        /**
         * Đổi nhà cung cấp là đổi cả dây: nạp lại danh sách phiếu mua và bỏ lưới
         * hàng cũ — hàng của bên bán này không nằm trên phiếu của bên kia.
         */
        $(document).on('change', '#modalCreate .supplier_id', function () {
            veHoSoNCC();
            DONG = [];
            baoLoiDong('');
            $mc().find('.lines-empty').text(EMPTY_DAU);
            veLuoi();
            napPhieuMua(soN($(this).val()));
        });

        /** Ô "Chọn phiếu nhập" — API chỉ trả phiếu ĐÃ DUYỆT của nhà cung cấp ấy. */
        async function napPhieuMua(nccId, chonId) {
            const $o = $mc().find('.select-purchase-slip');
            const dat = (html, mo) => {
                $o.html(html).prop('disabled', !mo).trigger('change.select2');
            };

            dat('<option value="0">Đang đọc phiếu mua…</option>', false);
            if (nccId <= 0) {
                dat('<option value="0">Chọn nhà cung cấp trước</option>', false);

                return;
            }

            let ds = [];
            try {
                const res = await fetch(URL_PHIEU_MUA + '?supplier_id=' + nccId, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu mua.');
                ds = data.data || [];
            } catch (e) {
                dat('<option value="0">Không đọc được phiếu mua</option>', false);
                baoLoiDong(e.message || 'Không đọc được phiếu mua của nhà cung cấp.');

                return;
            }

            if (!ds.length) {
                dat('<option value="0">Nhà cung cấp này chưa có phiếu mua đã duyệt</option>', false);

                return;
            }

            dat('<option value="0">Chọn phiếu nhập</option>' + ds.map((p) =>
                '<option value="' + p.id + '">' + esc(p.po_code || ('#' + p.id))
                + ' — ' + esc(ngayVN(p.document_date)) + ' — ' + esc(tien(p.total_amount))
                + '</option>').join(''), !CHI_XEM);

            if (chonId) $o.val(String(chonId)).trigger('change.select2');
        }

        /**
         * Đổi phiếu mua thì lưới hàng đổ lại từ đầu, VÀ ô "Nhân viên mua hàng"
         * điền theo người ghi trên chính phiếu mua ấy.
         *
         * Chỉ điền ở ĐƯỜNG NÀY — tức lúc người dùng tự tay chọn phiếu mua. Mở
         * một phiếu trả đã lưu cũng chạy qua napDongPhieuMua, nhưng lúc đó phải
         * giữ nguyên người đã ghi trên phiếu trả: người mua lô hàng và người lập
         * phiếu trả không nhất thiết là một, và phiếu đã lưu là chứng từ chứ
         * không phải chỗ để đoán lại.
         */
        $(document).on('change', '#modalCreate .select-purchase-slip', async function () {
            const phieu = await napDongPhieuMua(soN($(this).val()));
            datNguoiMua(phieu ? phieu.purchaser_id : 0);
        });

        /**
         * Điền ô "Nhân viên mua hàng" theo phiếu mua — vẫn đổi tay được sau đó.
         *
         * Không thấy người ấy trong danh sách (đã nghỉ việc, mà ô chọn chỉ liệt
         * kê người ĐANG LÀM) thì GIỮ NGUYÊN ô: gán một giá trị không có option
         * nào khớp làm ô chọn trắng trơn, trông như phiếu chưa phân công ai.
         */
        function datNguoiMua(id) {
            const nv = Number(id) || 0;
            if (nv <= 0) return;

            const $o = $mc().find('.purchaser_id');
            if (!$o.find('option[value="' + nv + '"]').length) return;

            $o.val(String(nv)).trigger('change.select2');
        }

        /**
         * Đổ lưới hàng từ một phiếu mua.
         *
         * `daLuu` là bộ {purchase_item_id: số lượng} của phiếu ĐANG SỬA — đổ lại
         * từ phiếu mua rồi điền số cũ vào, để trần luôn là trần của hôm nay chứ
         * không phải của hôm lập phiếu.
         *
         * Trả về khối `phieu` của phiếu mua (hoặc null nếu không đọc được) để
         * lượt gọi biết mà điền tiếp mấy ô lấy theo phiếu mua.
         */
        async function napDongPhieuMua(id, daLuu) {
            const $m = $mc();
            baoLoiDong('');
            DONG = [];
            dongSeq = 0;

            if (id <= 0) {
                $m.find('.lines-empty').text(EMPTY_DAU);
                veLuoi();

                return null;
            }

            $m.find('.lines-empty').text('Đang đọc hàng của phiếu mua…');
            veLuoi();

            let data = null;
            try {
                const res = await fetch(URL_DONG_PHIEU + '?id=' + id, { headers: { Accept: 'application/json' } });
                data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu mua.');
            } catch (err) {
                baoLoiDong(err.message || 'Không đọc được phiếu mua.');
                $m.find('.lines-empty').text('Chưa đọc được hàng của phiếu mua.');
                veLuoi();

                return null;
            }

            VAT_PHIEU = Number(data.phieu && data.phieu.vat_percent || 0);
            $m.find('.vat').val(String(VAT_PHIEU));

            (data.data || []).forEach((ln) => themDong(ln, daLuu));

            $m.find('.lines-empty').text('Phiếu mua này không còn dòng hàng nào trả được.');
            veLuoi();

            return data.phieu || null;
        }

        /**
         * Một dòng của phiếu mua thành một dòng trả.
         *
         * Trần là `returnable` API tính sẵn = min(đã mua − đã trả, tồn còn lại).
         * Hết phần trả được thì ô nhập khoá lại.
         */
        function themDong(ln, daLuu) {
            const poi = Number(ln.purchase_item_id || 0);
            const tran = Math.max(0, Number(ln.returnable || 0));
            const cu = daLuu ? Number(daLuu[poi] || 0) : null;

            DONG.push({
                key: ++dongSeq,
                purchase_item_id: poi,
                variant_id: Number(ln.variant_id || 0),
                product_name: ln.product_name || '',
                variant_name: ln.variant_name || '',
                sku: ln.variant_sku || '',
                unit_id: Number(ln.unit_id || 0),
                unit_name: ln.unit_name || '',
                bought: Number(ln.quantity || 0),
                returned: Number(ln.returned || 0),
                stock: Number(ln.stock || 0),
                max: tran,
                // Sửa phiếu thì giữ số cũ; lập mới thì MỌI dòng bắt đầu từ 0 —
                // người lập tự gõ số cho những dòng thật sự trả. Điền sẵn 1 như
                // trước là mỗi lần chọn phiếu mua lại đẻ ra một phiếu trả đủ mặt
                // hàng, ai quên xoá dòng thừa là trả nhầm cả lô.
                quantity: cu !== null ? Math.min(cu, tran) : 0,
                unit_cost: Number(ln.unit_cost || 0),
                vat_percent: Number(ln.vat_percent == null ? VAT_PHIEU : ln.vat_percent),
                lot_number: ln.lot_number || '',
                expire_date: (ln.expire_date || '').slice(0, 10),
            });
        }

        function veLuoi() {
            const $m = $mc();
            $m.find('.lines-empty').toggle(DONG.length === 0);

            $m.find('.list-menu').html(DONG.map((d, i) => {
                const vat = Number(d.vat_percent || 0);
                const tienHang = Math.round(d.unit_cost * d.quantity);
                const thue = Math.round(tienHang * Math.max(0, vat) / 100);
                const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');
                const het = d.max <= 0;

                // Trần trả hàng nằm ở title của ô nhập chứ không in thành một dòng
                // chữ dưới nó: in ra thì mỗi hàng cao hai tầng và ô nhập không còn
                // thẳng cột với mấy cột số bên cạnh. Gõ quá trần vẫn bị kéo về và
                // báo bằng toast ngay lúc gõ, nên không ai mất số oan.
                const viSao = d.returned > 0
                    ? 'đã mua ' + nhomSo(d.bought) + ', đã trả ' + nhomSo(d.returned) + ', kho còn ' + nhomSo(d.stock)
                    : 'đã mua ' + nhomSo(d.bought) + ', kho còn ' + nhomSo(d.stock);
                const tran = het
                    ? 'Không còn trả được — ' + viSao
                    : 'Trả tối đa ' + nhomSo(d.max) + ' ' + (d.unit_name || '') + ' — ' + viSao;

                return '<tr data-key="' + d.key + '" class="' + (het ? 'is-het' : '') + '">'
                    + '<td class="la-giua">' + (i + 1) + '</td>'
                    + '<td class="la-chu" title="' + esc(d.sku) + '">' + esc(d.sku || '—') + '</td>'
                    + '<td class="la-chu" title="' + esc(ten) + '">' + esc(ten) + '</td>'
                    + '<td class="la-chu">' + esc(d.unit_name || '—') + '</td>'
                    + '<td class="la-so">'
                        + '<input type="text" class="ip-line" data-f="quantity" inputmode="numeric" value="'
                        + nhomSo(d.quantity) + '" title="' + esc(tran) + '"'
                        + (het || CHI_XEM ? ' disabled' : '') + '>'
                    + '</td>'
                    + '<td class="la-so">' + nhomSo(d.bought) + '</td>'
                    + '<td class="la-so">' + nhomSo(d.stock) + '</td>'
                    + '<td class="la-so">' + tien(d.unit_cost) + '</td>'
                    + '<td class="la-so">' + tien(tienHang) + '</td>'
                    + '<td class="la-so">' + tien(thue) + '</td>'
                    + '<td class="la-so"><b>' + tien(tienHang + thue) + '</b></td>'
                    + '<td class="la-chu">' + esc(d.lot_number || '—') + '</td>'
                    + '<td class="la-giua">' + (d.expire_date ? esc(ngayVN(d.expire_date)) : '—') + '</td>'
                    + '<td class="la-giua not-export">'
                        + (CHI_XEM ? '' : '<a class="dele_bt remove-line" type="button" title="{{ __('message.delete') }}">'
                            + '<i class="fa fa-times"></i></a>')
                    + '</td>'
                    + '</tr>';
            }).join(''));

            veTien();
        }

        function veTien() {
            let tienHang = 0;
            let thue = 0;
            DONG.forEach((d) => {
                const line = Math.round(d.unit_cost * d.quantity);
                tienHang += line;
                thue += Math.round(line * Math.max(0, Number(d.vat_percent || 0)) / 100);
            });

            const $m = $mc();
            $m.find('.tong-tien-hang').val(tien(tienHang));
            $m.find('.tong-thue').val(tien(thue));
            $m.find('.tong-cong').val(tien(tienHang + thue));

            // KHÔNG khoá nút Lưu khi chưa dòng nào có số lượng. Mặc định mọi dòng
            // là 0, nên khoá nút đồng nghĩa lần đầu mở phiếu ra là hai nút xám
            // ngắt — người dùng bấm không được mà chẳng ai nói vì sao. Để bấm
            // được rồi báo bằng toast (xem trình xử lý .save-order).
        }

        /**
         * Gõ số lượng trả.
         *
         * Vượt trần thì kéo về trần và NÓI RA bằng toast — im lặng sửa số người ta
         * vừa gõ là kiểu tệ nhất.
         */
        $(document).on('input', '#modalCreate .list-menu [data-f="quantity"]', function () {
            const key = Number($(this).closest('tr').data('key'));
            const d = DONG.find((x) => x.key === key);
            if (!d) return;

            let sl = soNguyen(soN(this.value));
            if (sl > d.max) {
                toastr.error('"' + d.product_name + '" chỉ trả được tối đa '
                    + nhomSo(d.max) + ' ' + (d.unit_name || '') + '.');
                sl = d.max;
            }
            d.quantity = sl;

            // Vẽ lại cả lưới cho tiền của dòng đổi theo, nhưng giữ con trỏ.
            const phai = viTriTuPhai(this);
            veLuoi();
            const lai = $mc().find('.list-menu tr[data-key="' + key + '"] [data-f="quantity"]')[0];
            if (lai) { lai.focus(); datConTro(lai, phai); }
        });

        $(document).on('click', '#modalCreate .remove-line', function () {
            const key = Number($(this).closest('tr').data('key'));
            DONG = DONG.filter((d) => d.key !== key);
            veLuoi();
        });

        // ---------- Nâng cao của khối hàng hoá ----------
        const COT_DONG = ['Mã hàng hóa', 'Tên hàng hóa', 'Đơn vị tính', 'Số lượng trả', 'Số lượng nhập',
            'Đã trả trước đó', 'Tồn còn lại', 'Giá nhập', 'Thành tiền trước VAT', 'Tiền VAT',
            'Tổng tiền sau VAT', 'Số lô', 'Hạn dùng'];

        /** Tải một chuỗi xuống máy dưới dạng CSV có BOM để Excel đọc đúng dấu. */
        function taiCSV(ten, dong) {
            const noi = '﻿' + dong.map((h) => h.map((o) => {
                const v = String(o == null ? '' : o);

                return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
            }).join(',')).join('\r\n');
            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([noi], { type: 'text/csv;charset=utf-8' }));
            a.download = ten;
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        }

        $(document).on('click', '#modalCreate .export-line', function () {
            if (!DONG.length) { toastr.error('Phiếu chưa có dòng hàng nào để xuất.'); return; }

            taiCSV('dong-hang-phieu-tra.csv', [COT_DONG].concat(DONG.map((d) => {
                const tienHang = Math.round(d.unit_cost * d.quantity);
                const thue = Math.round(tienHang * Math.max(0, Number(d.vat_percent || 0)) / 100);

                return [d.sku, [d.product_name, d.variant_name].filter(Boolean).join(' · '), d.unit_name,
                    d.quantity, d.bought, d.returned, d.stock, d.unit_cost, tienHang, thue,
                    tienHang + thue, d.lot_number, d.expire_date];
            })));
        });

        $(document).on('click', '#modalCreate .reset-line', function () {
            const id = soN($mc().find('.select-purchase-slip').val());
            if (id <= 0) { toastr.error('Chưa chọn phiếu mua nào để đổ lại.'); return; }
            napDongPhieuMua(id);
        });

        // =====================================================================
        //  Mở hộp lập / sửa / xem phiếu
        // =====================================================================

        // Ô nào khoá sẵn từ trong khuôn — hồ sơ bên bán, ngày tạo, người tạo — thì
        // đánh dấu MỘT LẦN, trước khi có lượt khoá / mở nào. Đánh dấu lúc khoá là
        // muộn: phiếu đầu tiên mở ra ở chế độ xem sẽ đánh dấu luôn cả những ô vốn
        // gõ được, và từ đó không ô nào mở lại được nữa.
        let daDanhDauKhoaSan = false;
        function danhDauKhoaSan() {
            if (daDanhDauKhoaSan) return;
            daDanhDauKhoaSan = true;
            $mc().find('.thn-form').find('input, select, textarea')
                .filter((i, el) => el.disabled).attr('data-khoa-san', '1');
        }

        function khoaPhieu(khoa) {
            const $m = $mc();
            danhDauKhoaSan();
            $m.find('.thn-form').find('input, select, textarea').each(function () {
                const $o = $(this);
                $o.prop('disabled', khoa || $o.attr('data-khoa-san') === '1');
            });
            // Ô chọn phiếu mua và dropdown Nâng cao gập hẳn khi chỉ xem — thêm hàng
            // vào phiếu đã xuất kho là sai từ gốc.
            $m.find('.thn-hang-cong-cu').toggleClass('d-none', khoa);
        }

        /** mode: `p` rỗng là lập mới, có id là mở phiếu đã lưu. */
        async function moPhieu(p) {
            const $m = $mc();
            const sua = !!(p && p.id);
            SUA_ID = sua ? Number(p.id) : 0;
            SUA_MOC = sua ? String(p.updated_at || '') : '';
            const d = p || {};

            // Phiếu nào KHÔNG sửa được nữa thì mở ở chế độ xem. Đọc theo cờ API trả
            // về chứ không tự suy từ trạng thái — luật nằm ở server, chép sang đây
            // là sớm muộn hai bên nói khác nhau.
            CHI_XEM = sua && d.can_edit === false;
            khoaPhieu(CHI_XEM);

            $m.find('.thn-ma-phieu').text(sua
                ? [d.return_code, d.supplier_name].filter(Boolean).join(' — ') : '');
            $m.find('.modal-title').text(CHI_XEM
                ? 'Chi tiết phiếu trả ' + (d.return_code || '')
                : (sua ? 'Sửa phiếu trả ' + (d.return_code || '') : 'Lập phiếu trả hàng nhà cung cấp'));

            // Lập mới lấy bộ nút của `create.blade.php`, phiếu đã lưu lấy bộ của
            // `edit.blade.php`. Xem thì chỉ còn nút In.
            $m.find('.thn-nut-moi').toggleClass('d-none', sua);
            $m.find('.thn-nut-sua').toggleClass('d-none', !sua || CHI_XEM);
            $m.find('.thn-in').toggleClass('d-none', !sua);
            $m.find('.thn-thanh-nut').data('phieu', d);

            $m.find('.supplier_id').val(String(d.supplier_id || 0)).trigger('change.select2');
            veHoSoNCC();
            // Phiếu cũ giữ NGUYÊN hồ sơ đã chụp, kể cả khi danh mục đã đổi.
            if (sua) {
                if (d.address) $m.find('[name="address"]').val(d.address);
                if (d.address_2) $m.find('[name="address_line2"]').val(d.address_2);
                if (d.contact_phone) $m.find('[name="contact_person_phone"]').val(d.contact_phone);
            }

            // Ngày chứng từ điền sẵn HÔM NAY khi lập mới: phiếu nào cũng có ngày, và
            // chín trên mười lần đó là hôm nay.
            $m.find('[name="document_date"]').val(sua ? ngayVN(d.document_date) : moment().format(KHUON_NGAY));
            $m.find('[name="expired_date"]').val(ngayVN(d.expired_date));
            $m.find('.purchaser_id').val(String(d.purchaser_id || 0)).trigger('change.select2');
            $m.find('[name="receiver_delivery_note"]').val(d.receiver_delivery_note || '');
            $m.find('.note').val(d.note || '');
            $m.find('[name="branch"]').val(d.branch_name || TEN_CHI_NHANH || '');
            $m.find('[name="created_date"]').val(sua ? gioNgay(d.created_at) : moment().format(KHUON_NGAY));
            if (sua && d.creator_name) $m.find('[name="created_by_name"]').val(d.creator_name);
            $m.find('[name="return_status"]').val(sua
                ? (NHAN_TRANG_THAI[d.status] || '') : '{{ __('message.create-btn') }}');

            VAT_PHIEU = Number(d.vat_percent || 0);
            $m.find('.vat').val(String(VAT_PHIEU));

            baoLoiDong('');
            $m.find('.lines-empty').text(EMPTY_DAU);
            DONG = [];
            dongSeq = 0;
            veLuoi();

            napSelect2();
            $m.modal('show');

            const poID = Number(d.purchase_order_id || 0);
            await napPhieuMua(Number(d.supplier_id || 0), poID);

            // Sửa phiếu: đổ lại lưới TỪ PHIẾU MUA rồi điền số cũ vào, chứ không dựng
            // thẳng từ dòng đã lưu. Trần trả hàng đổi theo ngày (phiếu khác duyệt xen
            // vào, kho hụt đi), nên nó phải là trần của HÔM NAY — dựng từ dòng cũ là
            // mở ra một ô nhập cho phép gõ con số mà lượt duyệt sẽ từ chối.
            if (sua && poID > 0) {
                const daLuu = {};
                (d.items || []).forEach((it) => {
                    daLuu[Number(it.purchase_order_item_id || 0)] = Number(it.quantity || 0);
                });
                await napDongPhieuMua(poID, daLuu);
            }
        }

        /** Ba ô chọn của hộp thoại chạy select2, treo dropdown vào chính hộp. */
        function napSelect2() {
            const $m = $mc();
            $m.find('.supplier_id, .purchaser_id, .select-purchase-slip').each(function () {
                if ($(this).hasClass('select2-hidden-accessible')) return;
                $(this).select2({ dropdownParent: $m, width: '100%' });
            });
        }

        // Dựng select2 ngay từ đầu chứ không đợi lượt mở hộp: hộp nằm sẵn trong
        // trang, mà dựng lúc hộp còn ẩn thì ô chọn hay ra sai bề ngang ở lượt đầu.
        $(napSelect2);

        $(document).on('click', '.btn_create', () => moPhieu(null));

        /** Đọc một phiếu đã lưu — hộp thoại và bản in cùng đi đường này. */
        async function docPhieu(id) {
            const res = await fetch(URL_BASE + '/' + id, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu trả hàng.');

            return data.data;
        }

        // Mở phiếu từ bảng: MỘT hộp thoại cho cả ba cảnh, đúng như v2 — bên đó con
        // mắt và mã phiếu đều nạp cùng một khuôn rồi tự khoá ô khi phiếu đã duyệt.
        $(document).on('click', '.detail-item', async function () {
            const id = $(this).closest('.item').data('id');
            try {
                await moPhieu(await docPhieu(id));
            } catch (err) {
                toastr.error(err.message || 'Không đọc được phiếu.');
            }
        });

        // =====================================================================
        //  Lưu phiếu
        //
        //  Controller trả về chuyển hướng kèm câu báo trong session (không phải
        //  JSON), nên lưu bằng form POST thật: trang tải lại và toast bắn ra từ đó.
        //  Lưu HỎNG thì controller `back()->withInput()`, và khối cuối tệp này mở
        //  lại hộp thoại kèm đúng những gì vừa gõ.
        // =====================================================================
        $(document).on('click', '#modalCreate .save-order', function () {
            // Thanh nút giữ CẢ HAI bộ trong khuôn, bộ nào không dùng thì mang d-none.
            // Nút đang ẩn mà vẫn nhận lượt bấm là một lượt lưu thứ hai không ai gọi.
            if ($(this).hasClass('d-none')) return;

            const $m = $mc();
            const nccId = soN($m.find('.supplier_id').val());
            if (nccId <= 0) { toastr.error('Chưa chọn nhà cung cấp.'); return; }

            const poId = soN($m.find('.select-purchase-slip').val());
            if (poId <= 0) { toastr.error('Chưa chọn phiếu mua để trả hàng.'); return; }

            // Dòng để 0 nghĩa là KHÔNG trả mặt hàng ấy — bỏ qua, không gửi đi.
            // Nhưng cả phiếu mà không dòng nào có số thì đó là phiếu rỗng.
            const hopLe = DONG.filter((d) => d.quantity > 0);
            if (!hopLe.length) {
                toastr.error('Số lượng trả không thể là 0 — gõ số cho ít nhất một dòng hàng.');

                return;
            }

            const qua = hopLe.find((d) => d.quantity > d.max);
            if (qua) {
                toastr.error('"' + qua.product_name + '" trả quá số cho phép — tối đa '
                    + nhomSo(qua.max) + ' ' + (qua.unit_name || '') + '.');

                return;
            }

            postForm(SUA_ID ? URL_BASE + '/' + SUA_ID : URL_STORE, SUA_ID ? 'PUT' : 'POST', {
                id: SUA_ID || '',
                updated_at: SUA_MOC,
                duyet: $(this).data('duyet'),
                supplier_id: nccId,
                purchase_order_id: poId,
                document_date: ngayISO($m.find('[name="document_date"]').val()),
                expired_date: ngayISO($m.find('[name="expired_date"]').val()),
                purchaser_id: $m.find('.purchaser_id').val() || 0,
                receiver_delivery_note: $m.find('[name="receiver_delivery_note"]').val() || '',
                note: $m.find('.note').val() || '',
                vat_percent: VAT_PHIEU,
                // Chỉ HAI khoá đi tới API: trả dòng nào, trả bao nhiêu. Giá nhập, đơn
                // vị, số lô, thuế suất API lấy lại từ dòng phiếu mua gốc — gửi từ
                // trình duyệt thì sổ kho ghi một con số không có gốc.
                items: JSON.stringify(hopLe.map((d) => ({
                    purchase_item_id: d.purchase_item_id,
                    quantity: d.quantity,
                }))),
                // Bản đầy đủ của lưới hàng chỉ để dựng lại hộp thoại khi lưu hỏng.
                items_meta: JSON.stringify(hopLe),
            });
        });

        // =====================================================================
        //  In phiếu
        //
        //  Hai đường vào, MỘT khuôn in: nút In trong hộp phiếu, và Nâng cao → In
        //  ngoài bảng (in những phiếu ĐÃ TICK, một hay nhiều đều được).
        //
        //  Không gọi thẳng window.print(): thế là in cả trang quản trị — thanh
        //  điều hướng, khung lọc, phân trang. Không ai cầm tờ ấy đi đối chiếu với
        //  nhà cung cấp được.
        // =====================================================================
        const KIEU_IN =
            'body{font:13px/1.5 system-ui,Arial,sans-serif;color:#1a2b58;padding:24px}'
            + '.to{page-break-after:always}.to:last-child{page-break-after:auto}'
            + 'h1{font-size:19px;margin:0 0 4px}.ph{color:#6c757d;margin:0 0 16px}'
            + '.ho{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 24px;margin-bottom:16px}'
            + '.ho b{display:inline-block;min-width:140px}'
            + 'table{width:100%;border-collapse:collapse}'
            + 'th,td{border:1px solid #cfd6e4;padding:5px 7px}'
            + 'th{background:#f4f6f8;font-size:12px}.p{text-align:right}.g{text-align:center}'
            + '.tg{margin-top:14px;width:auto;margin-left:auto}'
            + '.ky{display:flex;justify-content:space-between;margin-top:32px;text-align:center}'
            + '.ky div{width:45%}'
            + '@media print{body{padding:0}}';

        /** Một tờ phiếu. Nhiều phiếu thì mỗi tờ một trang giấy. */
        function toPhieu(p) {
            let tienHang = 0;
            let thue = 0;

            const hang = (p.items || []).map((it, i) => {
                const sl = Number(it.quantity || 0);
                const gia = Number(it.unit_cost || 0);
                const vat = Number(it.vat_percent || 0);
                const tHang = Math.round(gia * sl);
                const tThue = Math.round(tHang * Math.max(0, vat) / 100);
                tienHang += tHang;
                thue += tThue;

                return '<tr><td class="g">' + (i + 1) + '</td><td>' + esc(it.variant_sku || '') + '</td>'
                    + '<td>' + esc([it.product_name, it.variant_name].filter(Boolean).join(' · ')) + '</td>'
                    + '<td class="g">' + esc(it.unit_name || '') + '</td>'
                    + '<td class="p">' + nhomSo(sl) + '</td><td class="p">' + nhomSo(gia) + '</td>'
                    + '<td class="p">' + nhomSo(tHang) + '</td><td class="p">' + nhomSo(tThue) + '</td>'
                    + '<td class="p"><b>' + nhomSo(tHang + tThue) + '</b></td>'
                    + '<td class="g">' + esc(it.lot_number || '') + '</td>'
                    + '<td class="g">' + esc(ngayVN(it.expire_date)) + '</td></tr>';
            }).join('');

            return '<div class="to">'
                + '<h1>{{ __('message.supplier_return_slip') }} ' + esc(p.return_code || '') + '</h1>'
                + '<p class="ph">' + esc(NHAN_TRANG_THAI[p.status] || '') + '</p>'
                + '<div class="ho">'
                    + '<div><b>{{ __('message.supplier') }}:</b> ' + esc(p.supplier_name || '') + '</div>'
                    + '<div><b>{{ __('message.document_date') }}:</b> ' + esc(ngayVN(p.document_date)) + '</div>'
                    + '<div><b>{{ __('message.branch') }}:</b> ' + esc(p.branch_name || TEN_CHI_NHANH || '') + '</div>'
                    + '<div><b>Phiếu mua gốc:</b> ' + esc(p.purchase_order_code || '—') + '</div>'
                    + '<div><b>{{ __('message.address') }}:</b> ' + esc(p.address || '—') + '</div>'
                    + '<div><b>{{ __('message.creator') }}:</b> ' + esc(p.creator_name || '') + '</div>'
                    + '<div><b>{{ __('message.note') }}:</b> ' + esc(p.note || '—') + '</div>'
                    + '<div><b>{{ __('message.document_type') }}:</b> ' + esc(LOAI_CT) + '</div>'
                + '</div>'
                + '<table><thead><tr><th>#</th><th>{{ __('message.menu-code') }}</th>'
                    + '<th>{{ __('message.menu-name') }}</th><th>{{ __('message.unit_of_measure') }}</th>'
                    + '<th>{{ __('message.quantity') }}</th><th>{{ __('message.import_price') }}</th>'
                    + '<th>{{ __('message.subtotal_before_vat') }}</th><th>{{ __('message.vat') }}</th>'
                    + '<th>{{ __('message.total_amount_after_vat') }}</th>'
                    + '<th>{{ __('message.batch_number') }}</th><th>{{ __('message.expiry_date') }}</th>'
                + '</tr></thead><tbody>' + hang + '</tbody></table>'
                + '<table class="tg"><tbody>'
                    + '<tr><th>{{ __('message.total_subtotal_before_vat') }}</th>'
                        + '<td class="p">' + nhomSo(tienHang) + '</td></tr>'
                    + '<tr><th>{{ __('message.total_vat_amount') }}</th>'
                        + '<td class="p">' + nhomSo(thue) + '</td></tr>'
                    + '<tr><th>{{ __('message.total_money') }}</th>'
                        + '<td class="p"><b>' + nhomSo(tienHang + thue) + '</b></td></tr>'
                + '</tbody></table>'
                + '<div class="ky"><div>Người lập phiếu<br><i>(ký, ghi rõ họ tên)</i></div>'
                    + '<div>Người nhận hàng<br><i>(ký, ghi rõ họ tên)</i></div></div>'
                + '</div>';
        }

        /** Mở cửa sổ in với một hay nhiều tờ phiếu. */
        function inCacTo(ds) {
            if (!ds.length) { toastr.error('Không đọc được phiếu nào để in.'); return; }

            const w = window.open('', '_blank');
            if (!w) { toastr.error('Trình duyệt đang chặn cửa sổ in.'); return; }

            const ten = ds.length === 1
                ? '{{ __('message.supplier_return_slip') }} ' + (ds[0].return_code || '')
                : '{{ __('message.supplier_return_slip') }} (' + ds.length + ')';

            w.document.write('<!doctype html><html lang="vi"><head><meta charset="utf-8">'
                + '<title>' + esc(ten) + '</title><style>' + KIEU_IN + '</style></head><body>'
                + ds.map(toPhieu).join('')
                + '</body></html>');
            w.document.close();
            w.focus();
            w.print();
        }

        // In từ hộp phiếu: phiếu đang mở đã đọc đủ dòng hàng nên in thẳng.
        $(document).on('click', '#modalCreate .thn-in', function () {
            const p = $mc().find('.thn-thanh-nut').data('phieu');
            if (!p || !p.id) { toastr.error('Lưu phiếu rồi mới in được.'); return; }
            inCacTo([p]);
        });

        // Nâng cao → In: phải TICK dòng trước. In cả trang danh sách thì mỗi tờ in
        // ra là một bản chụp màn hình, không phải chứng từ.
        $(document).on('click', '.btn_print_list', function () {
            const ids = idsDaChon();
            if (!ids.length) { toastr.error('Chọn phiếu muốn in ở cột đầu bảng đã.'); return; }

            // Đọc theo ĐÚNG thứ tự đã tick, và chỉ in khi đọc được HẾT: in thiếu một
            // tờ giữa chừng mà không ai hay còn tệ hơn không in.
            Promise.all(ids.map((id) => docPhieu(id)))
                .then((ds) => inCacTo(ds))
                .catch(() => toastr.error('Không đọc được phiếu để in.'));
        });

        // =====================================================================
        //  Xoá
        // =====================================================================
        $(document).on('click', '.delete-item', function () {
            $('#deleteValue').val($(this).closest('.item').data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            // Chỉ phiếu LƯU TẠM: phiếu đã duyệt API từ chối, gửi lên chỉ tổ đếm hỏng.
            const ids = idsDaChon().filter((id) => (ROWS[id] || {}).status === 'draft');
            if (!ids.length) {
                toastr.error('Chọn phiếu lưu tạm muốn xoá ở cột đầu bảng đã — phiếu đã duyệt không xoá được.');

                return;
            }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.delete-value', function () {
            const ids = String($('#deleteValue').val() || '').split(',').filter(Boolean);
            if (!ids.length) return;

            $('#deleteItem').modal('hide');
            ids.length === 1
                ? postForm(URL_BASE + '/' + ids[0], 'DELETE', {})
                : postForm(URL_BULK_DEL, 'POST', { ids });
        });

        // =====================================================================
        //  Lưu hỏng thì mở lại hộp thoại kèm ĐÚNG những gì vừa gõ, cả lưới hàng.
        //  Bắt người ta chọn lại từng dòng chỉ vì server từ chối một ô là cách
        //  nhanh nhất để họ bỏ luôn phiếu đang lập.
        // =====================================================================
        @if (old('items'))
            $(async function () {
                const cu = @json(old());
                const id = Number(cu.id) || 0;

                await moPhieu(id ? { id, can_edit: true, supplier_id: Number(cu.supplier_id) || 0 } : null);

                const $m = $mc();
                $m.find('.supplier_id').val(String(cu.supplier_id || 0)).trigger('change.select2');
                veHoSoNCC();
                $m.find('[name="expired_date"]').val(ngayVN(cu.expired_date));
                $m.find('.purchaser_id').val(String(cu.purchaser_id || 0)).trigger('change.select2');
                $m.find('[name="receiver_delivery_note"]').val(cu.receiver_delivery_note || '');
                $m.find('.note').val(cu.note || '');
                $m.find('.vat').val(String(cu.vat_percent == null ? 0 : cu.vat_percent));

                // Lưới hàng đổ lại TỪ PHIẾU MUA rồi điền số vừa gõ vào — cùng lối với
                // lượt mở phiếu để sửa.
                try {
                    const dong = JSON.parse(cu.items_meta || '[]');
                    const daGo = {};
                    (Array.isArray(dong) ? dong : []).forEach((d) => {
                        daGo[Number(d.purchase_item_id || 0)] = Number(d.quantity || 0);
                    });
                    const po = Number(cu.purchase_order_id || 0);
                    if (po > 0) {
                        await napPhieuMua(Number(cu.supplier_id) || 0, po);
                        await napDongPhieuMua(po, daGo);
                    }
                } catch (e) { /* mất lưới hàng còn hơn mất cả trang */ }
            });
        @endif
    </script>
@endpush
