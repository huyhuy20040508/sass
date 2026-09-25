{{-- Thống kê → Hoá đơn điện tử — chép khuôn từ bản v2 cũ
     (ordertable/v2/resources/views/system/etax-invoice/index + list + modal).

     Giữ đúng dáng của v2: khung lọc bên trái (ký hiệu, số hoá đơn, tên khách,
     mã hoá đơn, ngày phát hành, người tạo), hàng nút trạng thái có số đếm trên
     đầu bảng, bảng chọn được cột có ô tick để đồng bộ hàng loạt, và ba nút ở
     cột Hành động: đồng bộ, xem, tải bản in.

     Ba chỗ khác v2:
     - Tám mã trạng thái của nhà cung cấp gom còn BỐN (đã cấp mã / chờ cơ quan
       thuế / nháp / lỗi) — đúng bốn trạng thái cổng của mình giữ, và mỗi cái là
       một việc phải làm khác nhau.
     - Ô "Mã hoá đơn" tìm cả mã đơn hàng lẫn mã cơ quan thuế: người bán cầm mã
       đơn trên tay nhiều hơn mã in trên tờ hoá đơn.
     - Hộp chi tiết có thêm nút KÝ cho tờ nháp và PHÁT HÀNH LẠI cho tờ lỗi. Bên
       v2 hai việc ấy làm ở quầy; bên này hộp chi tiết đơn hàng chưa có khối hoá
       đơn, nên không bày ở đây thì tờ nháp không còn chỗ nào để ký. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\EInvoiceController::TITLE)

@php
    $C = \App\Http\Controllers\EInvoiceController::class;

    // Cột đang tắt nằm ở ?hide= — giữ được sau khi đổi trang mà không cần bảng riêng.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $columns = [];
    foreach (array_keys($C::COT_BANG) as $c) {
        $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }
    $an = fn ($c) => $columns['show_'.$c] ? '' : 'hide';

    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '';

    $dem = $meta['dem'] ?? [];
    $daChonNguoiTao = array_values(array_filter(
        explode(',', (string) ($filters['created_by'] ?? '')),
        fn ($v) => $v !== '' && $v !== 'all'
    ));

    $coLoc = collect(['symbol', 'invoice_no', 'code', 'customer', 'from_date', 'to_date'])
        ->contains(fn ($k) => ($filters[$k] ?? '') !== '')
        || $filters['status'] !== 'all' || $daChonNguoiTao;
@endphp

@push('styles')
    <style>
        /* ---------- BẢNG ----------
           Mười bốn cột nên bảng có sàn bề rộng; màn hẹp hơn thì cuộn ngang trong
           khung chứ không bóp chữ lại. Chia % CỨNG, tổng đúng 100. */
        table.table-hoa-don.none_mobile { width: 100%; min-width: 1040px; table-layout: fixed; }
        /* Tiêu đề ĐƯỢC xuống dòng. Ép một dòng thì "Ngày phát hành" ngốn 104px
           trong khi ngày bên dưới chỉ cần 80px, và chỗ ấy lấy đúng vào phần của
           cột dữ liệu — hai tiêu đề dài là đủ để "Trạng thái CQT" và "Người tạo"
           cụt thành "…" ở mọi màn dưới 2K. */
        /* Nhãn ĐƯỢC xuống dòng. 14 nhãn giữ một dòng đòi ~1195px, quá cả khung
           của màn 1366 (1071px) — ép một dòng là bảng tràn khung. Dải nút ở cột
           Hành động vẫn một hàng, xem td.action bên dưới. */
        table.table-hoa-don.none_mobile th { white-space: normal; }
        /* Cột Hành động rộng theo DẢI NÚT, không theo nhãn: ba nút cần 98px mà
           nhãn "Hành động" chỉ 66px, nên chia theo nhãn là nút thứ ba rớt xuống
           hàng hai ở mọi khổ dưới 1536. Chỗ bù lấy từ Khách hàng và Email —
           hai cột mà nhãn rộng hơn hẳn dữ liệu bên dưới. */
        table.table-hoa-don.none_mobile td.action { white-space: nowrap; }

        /* % đo THẬT trong khung 1100px: mỗi cột lấy đúng bề ngang chữ dài nhất
           đang nằm trong nó (mã CQT, ngày, số tiền, tên người tạo) cộng đệm hai
           bên. Bản trước chia đều tay nên cột ngày và cột tiền hụt ~25px, còn
           "Số hoá đơn" với "Khách hàng" thì thừa. Tổng vẫn đúng 100. */
        table.table-hoa-don.none_mobile th:first-child { width: 4.02%; }
        table.table-hoa-don.none_mobile th:nth-child(2) { width: 4.58%; }
        table.table-hoa-don.none_mobile th.show_symbol { width: 5.23%; }
        table.table-hoa-don.none_mobile th.show_invoice_no { width: 5.98%; }
        table.table-hoa-don.none_mobile th.show_tax_code { width: 4.86%; }
        table.table-hoa-don.none_mobile th.show_order_code { width: 8.22%; }
        table.table-hoa-don.none_mobile th.show_status { width: 12.9%; }
        table.table-hoa-don.none_mobile th.show_issued_at { width: 9.35%; }
        table.table-hoa-don.none_mobile th.show_customer { width: 5.23%; }
        table.table-hoa-don.none_mobile th.show_email { width: 4.86%; }
        table.table-hoa-don.none_mobile th.show_vat { width: 7.85%; }
        table.table-hoa-don.none_mobile th.show_total { width: 8.98%; }
        table.table-hoa-don.none_mobile th.show_creator { width: 8.22%; }
        table.table-hoa-don.none_mobile th:last-child { width: 9.72%; }

        /* Loại tờ in nhỏ dưới trạng thái ("Bị thay thế"…), và câu lỗi của tờ hỏng. */
        .hd-phu { display: block; font-size: 11.5px; color: #8c8c8c; }
        .hd-ten { display: block; }
        .action .hd-nut { margin: 0 3px; cursor: pointer; }

        /* HÀNG NÚT TRẠNG THÁI — chép nguyên bộ màu của v2 (nền #E5E9F7, chữ
           #1C3B58, đang chọn nền #6F89BA chữ trắng), cùng bộ với màn Công nợ. */
        .btn-filter {
            border: 0; border-radius: 5px; height: 32px; padding: 0 12px;
            background-color: #e5e9f7; color: #1c3b58;
            font-size: 13px; font-weight: 450; white-space: nowrap;
        }
        .btn-filter:hover { background-color: #ccc; }
        .btn-filter.active { background-color: #6f89ba; color: #fff; }
        .hd-hang-nut { display: flex; flex-wrap: wrap; gap: 6px; justify-content: flex-end; margin: 6px 0 10px; }

        .btn_top_content { gap: 8px; align-items: center; }
        .btn_top_content .btn-export,
        .btn_top_content .setting-col {
            height: 34px; min-height: 34px; display: inline-flex; align-items: center;
            justify-content: center; border-radius: 4px; margin: 0;
        }
        .btn_top_content .btn-export { padding: 0 12px !important; }
        .btn_top_content .setting-col { width: 34px; padding: 0 !important; }
        .btn_top_content .dropup { display: inline-flex; }

        /* Khung lọc: siết khoảng cách như màn Đơn hàng — sáu khối phải nằm gọn
           trong một màn. */
        .fillter-box .card-body > div[id^="filter"] { margin-bottom: 6px !important; }
        .fillter-box .title_search { margin-bottom: 5px; display: block; }
        .fillter-box .gap-lg-1 { gap: 3px !important; }

        /* ---------- HỘP CHI TIẾT ----------
           Bố cục của modal.blade v2: lưới ô thông tin, khối bên bán / người mua,
           bảng hàng, khối tổng tiền bên phải. */
        #modalHoaDon .modal-dialog { max-width: 1100px; }
        #modalHoaDon .modal-content { animation: none !important; }
        #modalHoaDon .hd-o label { font-size: 12.5px; color: #6c757d; margin-bottom: 2px; }
        #modalHoaDon .hd-o .form-control { background: #f5f6f8; }
        #modalHoaDon .hd-khoi { margin-top: 12px; }
        #modalHoaDon .hd-khoi > h5 {
            font-size: 14px; font-weight: 600; padding: 6px 10px; margin: 0 0 6px;
            background: #b0c7d240; border-radius: 4px;
        }
        #modalHoaDon table.bang-hang { width: 100%; }
        #modalHoaDon table.bang-hang th { background: #e9ecef; padding: .5rem; white-space: nowrap; font-size: 12.5px; }
        #modalHoaDon table.bang-hang td { padding: .5rem; vertical-align: middle; }
        #modalHoaDon .hd-tong > div { display: flex; justify-content: space-between; padding: 3px 4px; }
        #modalHoaDon .hd-loi { background: #fff1f0; color: #cf1322; border-radius: 4px; padding: 8px 10px; }
    </style>
@endpush

@section('content')
    {{-- Nút mở từng khối lọc trên điện thoại — đúng sáu ô của khung trái. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @foreach ([
                'filterSymbol' => 'Ký hiệu',
                'filterNumber' => 'Số hoá đơn',
                'filterCustomer' => __('message.customer'),
                'filterCode' => 'Mã hoá đơn',
                'filterTime' => 'Ngày phát hành',
                'filterCreator' => __('message.creator'),
            ] as $oLoc => $nhan)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => $oLoc,
                    'modalLabel' => $nhan,
                ])
            @endforeach
        </div>
    </div>

    <div class="row index-order-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không bọc <form>: JS tự dựng URL rồi gọi V2.napLai — trên điện
                             thoại vỏ v2 bưng từng khối lọc sang offcanvas, submit lúc đó
                             đánh rơi các ô còn lại. --}}
                        @foreach ([
                            'filterSymbol' => ['symbol', 'Ký hiệu', 'Nhập ký hiệu'],
                            'filterNumber' => ['invoice_no', 'Số hoá đơn', 'Nhập số hoá đơn'],
                            'filterCustomer' => ['customer', __('message.customer-name'), 'Tên, SĐT hoặc email'],
                            'filterCode' => ['code', 'Mã hoá đơn', 'Mã đơn hoặc mã CQT'],
                        ] as $oLoc => [$ten, $nhan, $goiY])
                            <div id="{{ $oLoc }}" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ $nhan }}</span>
                                    <input type="text" name="{{ $ten }}" value="{{ $filters[$ten] }}"
                                        class="form-control mt-1" autocomplete="off" placeholder="{{ $goiY }}">
                                </div>
                            </div>
                        @endforeach

                        {{-- NGÀY PHÁT HÀNH — tờ chưa được cấp số thì tính theo lúc lập lượt
                             phát hành, để tờ nháp và tờ lỗi không biến mất khỏi bộ lọc. --}}
                        <div id="filterTime" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">Ngày phát hành</span>
                                <div class="d-flex flex-lg-column gap-2 gap-lg-1 mt-1">
                                    <input type="text" name="from_date" autocomplete="off" id="hd-from-date"
                                        value="{{ $ngayVN($filters['from_date']) }}" class="form-control"
                                        placeholder="{{ __('message.from_date') }}">
                                    <input type="text" name="to_date" autocomplete="off" id="hd-to-date"
                                        value="{{ $ngayVN($filters['to_date']) }}" class="form-control"
                                        placeholder="{{ __('message.to_date') }}">
                                </div>
                            </div>
                        </div>

                        {{-- NGƯỜI TẠO — người lập ĐƠN, đúng như cột "Người tạo" của v2. --}}
                        <div id="filterCreator" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.creator') }}</span>
                                <select class="form-control form-select mt-1" name="created_by" multiple>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}"
                                            {{ in_array((string) $nv['id'], $daChonNguoiTao, true) ? 'selected' : '' }}>
                                            {{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle mt-md-2 mt-lg-0">
            <div class="content_midd">
                {{-- Tiêu đề, cụm nút và hàng nút trạng thái nằm TRONG .list: số đếm đổi
                     theo bộ lọc, để ngoài là lọc xong số cũ vẫn nằm trên đầu bảng. --}}
                <div class="list scrollDiv">
                    <div class="content_midd_title">
                        <h1 class="tieu-de-trang">{{ $C::TITLE }}</h1>

                        <div class="justify-content-end">
                            <div class="btn_top_content d-flex">
                                {{-- Đồng bộ các tờ đang tick: hỏi lại cổng xem cơ quan thuế đã cấp
                                     mã chưa — đúng nút "Đồng bộ" của v2. --}}
                                <a class="btn btn-sm d-flex align-items-center btn-export" id="hd-dong-bo-loat">
                                    <i class="fa-solid fa-sync my-auto mx-1"></i> Đồng bộ
                                </a>
                                <a class="btn btn-sm d-flex align-items-center btn-export"
                                    href="{{ route('admin.hoa-don-dien-tu.export', request()->query()) }}">
                                    <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export_report') }}
                                </a>
                                <div class="dropup">
                                    <button type="button" class="btn active dropbtn setting-col">
                                        <i class="fa fa-sliders" aria-hidden="true"></i>
                                        <div class="dropup-content">
                                            <div class="list_filter">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="show_all"
                                                        {{ count($cotTat) ? '' : 'checked' }}>
                                                    <label for="show_all">{{ __('message.all') }}</label>
                                                </div>
                                                @foreach ($C::COT_BANG as $cot => $chu)
                                                    <div class="form-check">
                                                        <input class="form-check-input show_col" data-col="{{ $cot }}"
                                                            type="checkbox" id="show_{{ $cot }}"
                                                            {{ $columns['show_'.$cot] ? 'checked' : '' }}>
                                                        <label for="show_{{ $cot }}">{{ $chu }}</label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- HÀNG NÚT TRẠNG THÁI — chọn MỘT nhóm một lúc như v2. Số trong ngoặc
                         đếm trên bộ lọc bên trái, TRƯỚC khi áp nút đang chọn. --}}
                    <div class="hd-hang-nut">
                        <button type="button" class="btn btn-filter hd-tt {{ $filters['status'] === 'all' ? 'active' : '' }}"
                            data-status="all">{{ __('message.all') }} ({{ (int) ($dem['tat_ca'] ?? 0) }})</button>
                        @foreach ($C::TRANG_THAI as $ma => $ten)
                            <button type="button" class="btn btn-filter hd-tt {{ $filters['status'] === $ma ? 'active' : '' }}"
                                data-status="{{ $ma }}">{{ $ten }} ({{ (int) ($dem[$ma] ?? 0) }})</button>
                        @endforeach
                    </div>

                    <div class="table-responsive table-border-style">
                        <table class="table-hoa-don none_mobile">
                            <tr>
                                <th class="text-center not-export"><input class="form-check-input hd-tick-het" type="checkbox"></th>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_symbol {{ $an('symbol') }}">Ký hiệu</th>
                                <th class="text-left show_invoice_no {{ $an('invoice_no') }}">Số hoá đơn</th>
                                <th class="text-left show_tax_code {{ $an('tax_code') }}">Mã CQT</th>
                                <th class="text-left show_order_code {{ $an('order_code') }}">Mã đơn</th>
                                <th class="text-left show_status {{ $an('status') }}">Trạng thái CQT</th>
                                <th class="text-center show_issued_at {{ $an('issued_at') }}">Ngày phát hành</th>
                                <th class="text-left show_customer {{ $an('customer') }}">Khách hàng</th>
                                <th class="text-left show_email {{ $an('email') }}">Email</th>
                                <th class="text-right show_vat {{ $an('vat') }}">Tiền thuế</th>
                                <th class="text-right show_total {{ $an('total') }}">{{ __('message.total_money') }}</th>
                                <th class="text-left show_creator {{ $an('creator') }}">{{ __('message.creator') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($hoaDon as $i => $h)
                                @php
                                    $tt = (string) ($h['status'] ?? '');
                                    $loaiTo = $C::LOAI_TO[(int) ($h['doc_status'] ?? 0)] ?? '';
                                    // Đồng bộ chỉ có nghĩa với tờ còn chờ: nháp (có thể đã ký ở
                                    // màn nhà cung cấp) và đã gửi (chờ cấp mã). Tờ đã cấp mã thì
                                    // xong, tờ lỗi thì chưa có gì bên cổng để hỏi — như v2 giấu
                                    // nút đồng bộ ở tờ thành công và tờ bị từ chối.
                                    $dongBoDuoc = in_array($tt, ['draft', 'sent'], true);
                                    $coBanIn = ($h['invoice_id'] ?? '') !== '' && $tt !== 'failed';
                                @endphp
                                <tr class="item" data-id="{{ (int) $h['order_id'] }}" data-hd="{{ (int) $h['id'] }}"
                                    data-status="{{ $tt }}">
                                    <td class="text-center not-export">
                                        <input class="form-check-input hd-tick" type="checkbox">
                                    </td>
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    <td class="text-left show_symbol {{ $an('symbol') }}" title="{{ $h['symbol'] ?? '' }}">{{ $h['symbol'] ?? '' }}</td>
                                    <td class="text-left show_invoice_no {{ $an('invoice_no') }}">{{ $h['invoice_no'] ?? '' }}</td>
                                    <td class="text-left show_tax_code {{ $an('tax_code') }}" title="{{ $h['tax_auth_code'] ?? '' }}">{{ $h['tax_auth_code'] ?? '' }}</td>
                                    <td class="text-left show_order_code {{ $an('order_code') }}">{{ $h['order_code'] ?? '' }}</td>
                                    <td class="text-left show_status {{ $an('status') }}"
                                        title="{{ $tt === 'failed' ? ($h['error'] ?? '') : '' }}">
                                        <b class="{{ $C::MAU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                        @if ($loaiTo !== '')
                                            <span class="hd-phu">{{ $loaiTo }}</span>
                                        @endif
                                    </td>
                                    <td class="la-so text-center show_issued_at {{ $an('issued_at') }}">{{ $C::ngayPhatHanh($h, 'd-m-Y') }}</td>
                                    <td class="text-left show_customer {{ $an('customer') }}">
                                        <span class="hd-ten">{{ $h['customer_name'] ?? '' }}</span>
                                        <span class="hd-phu">{{ $h['customer_phone'] ?? '' }}</span>
                                    </td>
                                    <td class="text-left show_email {{ $an('email') }}" title="{{ $h['customer_email'] ?? '' }}">{{ $h['customer_email'] ?? '' }}</td>
                                    <td class="la-so text-right show_vat {{ $an('vat') }}">{{ $tien($h['vat_amount'] ?? 0) }}</td>
                                    <td class="la-so text-right show_total {{ $an('total') }}">{{ $tien($h['total_amount'] ?? 0) }}</td>
                                    <td class="text-left show_creator {{ $an('creator') }}">{{ ($h['nguoi_tao'] ?? '') ?: '—' }}</td>
                                    <td class="text-center action not-export">
                                        @if ($dongBoDuoc)
                                            <a class="hd-nut hd-dong-bo" title="Đồng bộ trạng thái"><i class="fa fa-sync"></i></a>
                                        @endif
                                        <a class="hd-nut detail-item" title="{{ __('message.view-detail') }}"><i class="fa fa-eye"></i></a>
                                        @if ($coBanIn)
                                            <a class="hd-nut" title="Tải bản in (PDF)" target="_blank" rel="noopener"
                                                href="{{ route('admin.orders.pdfHoaDon', (int) $h['order_id']) }}"><i class="fa fa-download"></i></a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="text-center py-4">
                                        {{ $coLoc
                                            ? 'Không có hoá đơn nào khớp bộ lọc đang bật.'
                                            : 'Chưa có hoá đơn điện tử nào. Hoá đơn hiện ở đây khi một đơn được phát hành hoá đơn — tự động lúc thu tiền, hoặc bấm phát hành ở quầy.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- BẢN THẺ CHO ĐIỆN THOẠI — dưới 992px vỏ v2 giấu hẳn bảng. --}}
                        <div class="table-hoa-don list none_desktop">
                            <div class="d-flex align-items-center gap-1 p-2 border">
                                <div class="fw-bold" style="flex: 1">Hoá đơn</div>
                                <div class="fw-bold">{{ __('message.total_money') }}</div>
                            </div>
                            @foreach ($hoaDon as $h)
                                @php $tt = (string) ($h['status'] ?? ''); @endphp
                                <div class="item" data-id="{{ (int) $h['order_id'] }}" data-status="{{ $tt }}">
                                    <div class="d-flex flex-column" style="flex: 1">
                                        <span class="fw-semibold">{{ trim(($h['symbol'] ?? '').' '.($h['invoice_no'] ?? '')) }}</span>
                                        <small class="{{ $C::MAU_TRANG_THAI[$tt] ?? '' }}">
                                            {{ $C::TRANG_THAI[$tt] ?? $tt }} · {{ $h['order_code'] ?? '' }}
                                        </small>
                                    </div>
                                    <div class="d-flex justify-content-end text-right gap-2" style="min-width: 110px">
                                        <b>{{ $tien($h['total_amount'] ?? 0) }}</b>
                                        <a class="detail-item" title="{{ __('message.view-detail') }}"><i class="fa fa-eye"></i></a>
                                    </div>
                                </div>
                            @endforeach
                            @if (! count($hoaDon))
                                <div class="text-center py-4">
                                    {{ $coLoc ? 'Không có hoá đơn nào khớp bộ lọc đang bật.' : 'Chưa có hoá đơn điện tử nào.' }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width {{ count($hoaDon) ? '' : 'd-none' }}"
                    data-param="page_size">
                    @foreach ($C::PAGE_SIZES as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp chi tiết hoá đơn =====================
         Chép khuôn system/etax-invoice/modal.blade của v2: lưới ô thông tin, khối
         người mua, bảng hàng, khối tổng tiền. Đọc qua /orders/{id}/detail — cùng
         đường với hộp chi tiết của màn Đơn hàng, trả luôn tờ hoá đơn đi kèm. --}}
    <div class="modal" id="modalHoaDon" data-id="" style="padding-inline: 0 !important">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable mx-auto">
            <div class="modal-content">
                <div class="modal-header border-bottom d-flex justify-content-between align-items-center">
                    <h4 class="modal-title fs-6">Thông tin hoá đơn - <span class="text-orange" id="hd-v-tieu-de"></span></h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-2">
                        @foreach ([
                            'hd-v-ky-hieu' => 'Ký hiệu',
                            'hd-v-so' => 'Số hoá đơn',
                            'hd-v-ma-cqt' => 'Mã CQT',
                            'hd-v-ma-tra-cuu' => 'Mã tra cứu',
                            'hd-v-trang-thai' => 'Trạng thái',
                            'hd-v-phuong-thuc' => __('message.payment-method'),
                            'hd-v-nguoi-tao' => __('message.creator'),
                            'hd-v-ngay' => 'Ngày phát hành',
                        ] as $idO => $nhan)
                            <div class="col-6 col-md-3 hd-o">
                                <label>{{ $nhan }}</label>
                                <input type="text" class="form-control" id="{{ $idO }}" disabled>
                            </div>
                        @endforeach
                    </div>

                    <div class="hd-khoi hd-loi mt-3" id="hd-v-loi" hidden></div>

                    <div class="hd-khoi">
                        <h5>Thông tin người mua</h5>
                        <div class="row g-2">
                            @foreach ([
                                'hd-v-khach' => __('message.customer-name'),
                                'hd-v-sdt' => __('message.phone-number'),
                                'hd-v-email' => 'Email',
                                'hd-v-ma-don' => 'Mã đơn',
                            ] as $idO => $nhan)
                                <div class="col-6 col-md-3 hd-o">
                                    <label>{{ $nhan }}</label>
                                    <input type="text" class="form-control" id="{{ $idO }}" disabled>
                                </div>
                            @endforeach
                            <div class="col-12 hd-o">
                                <label>{{ __('message.address') }}</label>
                                <input type="text" class="form-control" id="hd-v-dia-chi" disabled>
                            </div>
                        </div>
                    </div>

                    <div class="hd-khoi">
                        <h5>Hàng hoá, dịch vụ</h5>
                        <div class="row">
                            <div class="col-12 col-lg-8" style="overflow: auto;">
                                <table class="bang-hang">
                                    <thead>
                                        <tr>
                                            <th class="text-center">{{ __('message.stt') }}</th>
                                            <th class="text-left">Tên hàng hoá</th>
                                            <th class="text-right">{{ __('message.quantity') }}</th>
                                            <th class="text-right">Đơn giá</th>
                                            <th class="text-right">Thành tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody id="hd-v-hang"></tbody>
                                </table>
                            </div>
                            <div class="col-12 col-lg-4 hd-tong mt-3 mt-lg-0">
                                <div><span>Tổng tiền hàng</span><span id="hd-v-tien-hang"></span></div>
                                <div><span>Giảm giá</span><span id="hd-v-giam"></span></div>
                                <div><span>Phí giao hàng</span><span id="hd-v-phi"></span></div>
                                <div><span>Tiền thuế</span><span id="hd-v-thue"></span></div>
                                <div class="text-red"><strong>Tổng thanh toán</strong><strong id="hd-v-tong"></strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Nút theo trạng thái tờ: nháp thì ký, lỗi thì phát hành lại, còn
                     chờ thì đồng bộ; tờ đã ký thì tải được bản in và XML. --}}
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green hd-v-viec" data-viec="sign" hidden>Ký và gửi CQT</button>
                    <button type="button" class="bt btn_green hd-v-viec" data-viec="issue" hidden>Phát hành lại</button>
                    <button type="button" class="bt btn_green hd-v-viec" data-viec="sync" hidden>Đồng bộ</button>
                    <a class="bt btn_green" id="hd-v-pdf" target="_blank" rel="noopener" hidden>Tải PDF</a>
                    <a class="bt btn_green" id="hd-v-xml" hidden>Tải XML</a>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_DH = @json(url('/admin/orders'));
        const HD_TRANG_THAI = @json($C::TRANG_THAI);
        const HD_MAU = @json($C::MAU_TRANG_THAI);
        const HD_LOAI_TO = @json($C::LOAI_TO);
        const HD_PAY_METHODS = @json(\App\Http\Controllers\OrderController::PAYMENT_METHODS);
        const HD_VIEC = {
            sign: { duong: '/etax/sign', xong: 'Đã ký hoá đơn.' },
            issue: { duong: '/etax', xong: 'Đã phát hành hoá đơn.' },
            sync: { duong: '/etax/sync', xong: 'Đã đồng bộ trạng thái hoá đơn.' },
        };

        const tienVN = (n) => Number(n || 0).toLocaleString('vi-VN') + 'đ';
        const thoat = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        // ================= Bộ lọc =================
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai(thayDoi) {
            const q = new URLSearchParams();
            ['symbol', 'invoice_no', 'customer', 'code', 'from_date', 'to_date'].forEach(function (ten) {
                const v = String(oLoc(ten).val() || '').trim();
                if (v) q.set(ten, v);
            });
            const nguoiTao = [].concat(oLoc('created_by').val() || []);
            if (nguoiTao.length) q.set('created_by', nguoiTao.join(','));

            // Trạng thái đang chọn, cột ẩn và cỡ trang chép lại từ URL cũ.
            const cu = new URLSearchParams(location.search);
            ['status', 'hide', 'page_size'].forEach(function (ten) {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });
            $.each(thayDoi || {}, function (k, v) { v ? q.set(k, v) : q.delete(k); });

            // Cố ý không mang `page`: lọc lại thì trang cũ hết nghĩa.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerLoc = null;
        $(document).on('input', '.fillter-box input[type="text"]:not([name$="_date"])', function () {
            clearTimeout(timerLoc);
            timerLoc = setTimeout(locLai, 300);
        });
        $(document).on('change', '.fillter-box [name="created_by"], .fillter-box [name$="_date"]', function () { locLai(); });

        $(document).on('click', '.hd-tt', function () {
            const tt = $(this).data('status');
            locLai({ status: tt === 'all' ? '' : tt });
        });

        // ================= Chọn cột =================
        function apDungCot() {
            const tat = $('.show_col').filter(function (i, el) { return !el.checked; })
                .map(function (i, el) { return $(el).data('col'); }).get();
            const q = new URLSearchParams(location.search);
            tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            apDungCot();
        });

        // ================= Lịch cho hai ô ngày =================
        function ganLich() {
            ['#hd-from-date', '#hd-to-date'].forEach(function (sel) {
                const $o = $(sel);
                if (!$o.length || $o.data('daterangepicker')) return;
                $o.daterangepicker({
                    singleDatePicker: true, showDropdowns: true, locale: V2.lichVN(),
                    autoUpdateInput: false, autoApply: true,
                }, function (start) {
                    $o.val(start.format('DD-MM-YYYY')).trigger('change');
                });
            });
        }
        $(ganLich);
        $(document).on('v2:da-nap', ganLich);

        // ================= Thao tác trên một tờ =================
        /** Gọi một việc trên tờ hoá đơn của đơn `id` (ký / phát hành lại / đồng bộ).
         *  Đi qua $.ajax để mang CSRF và chi nhánh của tab (xem $.ajaxSetup). */
        function lamViec(id, viec) {
            return $.ajax({
                url: URL_DH + '/' + id + HD_VIEC[viec].duong,
                method: 'POST',
                headers: { Accept: 'application/json' },
            });
        }
        const cauLoi = (xhr, macDinh) => (xhr && xhr.responseJSON && xhr.responseJSON.message) || macDinh;

        $(document).on('click', '.hd-dong-bo', function () {
            const $nut = $(this);
            const id = $nut.closest('.item').data('id');
            if (!id || $nut.hasClass('disabled')) return;
            $nut.addClass('disabled');

            lamViec(id, 'sync')
                .done(function (r) {
                    toastr.success((r && r.message) || HD_VIEC.sync.xong);
                    V2.napLai(location.href, false);
                })
                .fail(function (xhr) { toastr.error(cauLoi(xhr, 'Chưa hỏi được cổng hoá đơn.')); })
                .always(function () { $nut.removeClass('disabled'); });
        });

        // Tick tất cả / tick lẻ.
        $(document).on('change', '.hd-tick-het', function () {
            $('.table-hoa-don .hd-tick').prop('checked', this.checked);
        });

        // Đồng bộ HÀNG LOẠT — lần lượt từng tờ chứ không bắn cùng lúc: cổng nhà
        // cung cấp giới hạn số lượt gọi, bắn hai chục lượt một lúc là nửa số ấy
        // bị từ chối và người dùng không biết tờ nào đã xong.
        $(document).on('click', '#hd-dong-bo-loat', function () {
            const $nut = $(this);
            if ($nut.hasClass('disabled')) return;

            const chon = $('.table-hoa-don .hd-tick:checked').closest('.item');
            if (!chon.length) {
                toastr.warning('Tick chọn ít nhất một hoá đơn để đồng bộ.');

                return;
            }
            // Tờ đã cấp mã hay tờ lỗi thì không có gì để hỏi lại — bỏ qua và nói rõ.
            const ids = chon.filter(function () { return ['draft', 'sent'].includes($(this).data('status')); })
                .map(function () { return $(this).data('id'); }).get();
            const boQua = chon.length - ids.length;
            if (!ids.length) {
                toastr.warning('Các hoá đơn đã chọn không cần đồng bộ (đã cấp mã hoặc đang lỗi).');

                return;
            }

            $nut.addClass('disabled');
            let xong = 0; let hong = 0;
            ids.reduce(function (truoc, id) {
                return truoc.then(function () {
                    return lamViec(id, 'sync').then(function () { xong++; }, function () { hong++; });
                });
            }, $.Deferred().resolve().promise()).always(function () {
                $nut.removeClass('disabled');
                const cau = 'Đã đồng bộ ' + xong + ' hoá đơn'
                    + (hong ? ', ' + hong + ' tờ chưa hỏi được cổng' : '')
                    + (boQua ? ', bỏ qua ' + boQua + ' tờ không cần đồng bộ' : '') + '.';
                hong ? toastr.warning(cau) : toastr.success(cau);
                V2.napLai(location.href, false);
            });
        });

        // ================= Hộp chi tiết =================
        function veHopHoaDon(o, hd) {
            hd = hd || {};
            const tt = hd.status || '';
            $('#modalHoaDon').attr('data-id', o.id);
            $('#hd-v-tieu-de').text([hd.symbol, hd.invoice_no].filter(Boolean).join(' số ') || o.order_code || '');

            const loaiTo = HD_LOAI_TO[hd.doc_status] ? ' (' + HD_LOAI_TO[hd.doc_status] + ')' : '';
            const moc = hd.issued_at || hd.created_at;
            $('#hd-v-ky-hieu').val(hd.symbol || '');
            $('#hd-v-so').val(hd.invoice_no || '');
            $('#hd-v-ma-cqt').val(hd.tax_auth_code || '');
            $('#hd-v-ma-tra-cuu').val(hd.lookup_code || '');
            $('#hd-v-trang-thai').val((HD_TRANG_THAI[tt] || tt) + loaiTo);
            $('#hd-v-phuong-thuc').val(HD_PAY_METHODS[o.payment_method] || o.payment_method || '');
            $('#hd-v-nguoi-tao').val(o.created_by_name || '—');
            $('#hd-v-ngay').val(moc ? moment(moc).format('DD-MM-YYYY HH:mm') : '');

            // Tờ lỗi: câu của cổng là thứ nói phải sửa gì trước khi phát hành lại.
            $('#hd-v-loi').prop('hidden', tt !== 'failed' || !hd.error).text(hd.error || '');

            $('#hd-v-khach').val(o.recipient_name || '');
            $('#hd-v-sdt').val(o.recipient_phone || '');
            $('#hd-v-email').val(o.recipient_email || '');
            $('#hd-v-ma-don').val(o.order_code || '');
            $('#hd-v-dia-chi').val([o.shipping_address, o.shipping_ward, o.shipping_district, o.shipping_province]
                .filter(Boolean).join(', '));

            const hang = o.items || [];
            let dong = hang.map(function (d, i) {
                const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');

                return '<tr><td class="text-center">' + (i + 1) + '</td>'
                    + '<td class="text-left">' + thoat(ten) + '</td>'
                    + '<td class="text-right">' + Number(d.quantity || 0).toLocaleString('vi-VN') + '</td>'
                    + '<td class="text-right">' + tienVN(d.unit_price) + '</td>'
                    + '<td class="text-right">' + tienVN(d.total_price) + '</td></tr>';
            }).join('');
            $('#hd-v-hang').html(dong || '<tr><td colspan="5" class="text-center py-3">Đơn không có dòng hàng nào.</td></tr>');

            $('#hd-v-tien-hang').text(tienVN(o.subtotal_amount));
            $('#hd-v-giam').text(tienVN(o.discount_amount));
            $('#hd-v-phi').text(tienVN(o.shipping_fee));
            $('#hd-v-thue').text(tienVN(hd.vat_amount));
            $('#hd-v-tong').text(tienVN(hd.total_amount || o.total_amount));

            // Nút theo trạng thái — bày đúng việc làm được, không bày rồi để API từ chối.
            $('.hd-v-viec[data-viec="sign"]').prop('hidden', tt !== 'draft');
            $('.hd-v-viec[data-viec="issue"]').prop('hidden', tt !== 'failed');
            $('.hd-v-viec[data-viec="sync"]').prop('hidden', !['draft', 'sent'].includes(tt));
            const daKy = ['sent', 'issued'].includes(tt);
            $('#hd-v-pdf').prop('hidden', !hd.invoice_id || tt === 'failed').attr('href', URL_DH + '/' + o.id + '/etax/pdf');
            $('#hd-v-xml').prop('hidden', !daKy).attr('href', URL_DH + '/' + o.id + '/etax/xml');

            $('#modalHoaDon').modal('show');
        }

        $(document).on('click', '.table-hoa-don .detail-item', function () {
            const id = $(this).closest('.item').data('id');
            if (!id) return;

            fetch(URL_DH + '/' + id + '/detail', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);

                    return r.json();
                })
                .then(function (d) { veHopHoaDon(d.data || {}, d.etax); })
                .catch(function () { toastr.error('Không tải được chi tiết hoá đơn.'); });
        });

        $(document).on('click', '.hd-v-viec', function () {
            const $nut = $(this);
            const viec = $nut.data('viec');
            const id = $('#modalHoaDon').attr('data-id');
            if (!id || $nut.prop('disabled')) return;
            $nut.prop('disabled', true);

            lamViec(id, viec)
                .done(function (r) {
                    toastr.success((r && r.message) || HD_VIEC[viec].xong);
                    $('#modalHoaDon').modal('hide');
                    V2.napLai(location.href, false);
                })
                // Hỏng thì GIỮ hộp lại và in nguyên câu của cổng: "chưa chọn ký
                // hiệu", "đã ký rồi" là những việc phải làm khác nhau.
                .fail(function (xhr) { toastr.error(cauLoi(xhr, 'Thao tác không thành công.')); })
                .always(function () { $nut.prop('disabled', false); });
        });
    </script>
@endpush
