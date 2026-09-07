{{-- Thống kê → Quản lý đơn hàng — chép khuôn từ bản v2 cũ
     (ordertable/resources/views/manager-order/index + list + modal-view).

     Giữ đúng dáng của v2: khung lọc bên trái, bảng chọn được cột bên phải, hộp
     xem chi tiết hai cột (bảng hàng bên trái, khối thanh toán bên phải).

     Bốn chỗ khác v2, đều vì đây là cửa hàng chứ không phải quán ăn:
     - Cột "Bàn" và ô lọc "Tại bàn / Mang về" đổi thành "Khách hàng" và "Kênh
       bán" (đơn giao hàng / bán tại quầy) — shop không có bàn.
     - Bốn cột tiền theo phương thức của v2 (mặt / CK / thẻ / QR) gom còn ba:
       Tiền mặt (cash, COD) · Chuyển khoản (bank_transfer, SePay) · Thẻ/Ví
       (VNPay, MoMo, PayOS). Đơn ở đây chỉ mang MỘT phương thức, không tách
       total_payment_1/2 như v2.
     - v2 gộp trạng thái đơn và trạng thái tiền vào một cột; bên này tách hai,
       vì đơn giao hàng còn đi qua sáu bước sau khi đã thu tiền.
     - Ba lỗi sẵn của v2 không bê sang: lọc "Người tạo" và "Hoá đơn điện tử" gửi
       sai tên tham số nên không ăn, còn lựa chọn cột ghi nhầm page nên F5 là
       mất. Bên này cột nằm ở ?hide= và mọi ô lọc đều đúng tên API đọc. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\OrderController::TITLE)

@php
    $C = \App\Http\Controllers\OrderController::class;

    // Cột đang tắt nằm ở ?hide= — giữ được sau khi đổi trang mà không cần bảng riêng.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $columns = [];
    foreach (array_keys($C::COT_BANG) as $c) {
        $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }

    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '';
    $gioNgay = fn ($v) => $v ? date('H:i d-m-Y', strtotime($v)) : '';

    // Cùng nguồn với dropdown ba gạch trên thanh đầu trang.
    $chiNhanh = \App\Services\ChiNhanhDangLam::danhSach();

    // Trạng thái đơn chọn được NHIỀU (API nhận chuỗi ngăn bởi dấu phẩy); ba ô
    // còn lại chỉ nhận một giá trị nên để ô chọn đơn kèm dòng "Tất cả".
    // 'all' là "không lọc", không phải một trạng thái — lọc bỏ để ô thả xuống
    // không tick nhầm và để câu "bảng rỗng" không đổ tại bộ lọc.
    $trangThaiChon = array_values(array_filter(
        explode(',', (string) $filters['status']),
        fn ($s) => $s !== '' && $s !== 'all'
    ));

    $coLoc = collect($filters)
        ->only(['keyword', 'payment_status', 'payment_method', 'channel'])
        ->contains(fn ($v) => $v !== '' && $v !== null && $v !== 'all')
        || count($trangThaiChon) > 0;

    // Màu chữ của hai cột trạng thái — đúng bảng màu v2 dùng cho danh sách đơn.
    $mauTrangThai = [
        'wait' => 'text-warning', 'info' => 'text-primary', 'move' => 'text-info',
        'done' => 'text-success', 'stop' => 'text-danger',
    ];
    $mauTien = [
        'paid' => 'text-success', 'pending' => '', 'failed' => 'text-danger',
        'refunded' => 'text-warning',
    ];
@endphp

@push('styles')
    <style>
        /* ---------- BẢNG ----------
           Cùng luật với các màn v2 khác: TIÊU ĐỀ luôn một dòng, ô dữ liệu dài thì
           cắt bằng "…" (chữ đủ vẫn còn ở `title` và trong hộp chi tiết).

           14 cột nên bảng có min-width; màn rộng thì vừa khít, màn hẹp thì
           `.table-responsive` cho cuộn ngang — không bóp chữ lại. */
        table.table-don-hang.none_mobile {
            width: 100%;
            /* 1240 = bề rộng nhỏ nhất để cả 14 tiêu đề nằm gọn một dòng ở cỡ chữ
               13px của vỏ v2. Nâng lên là bảng trượt ngang ngay ở màn 1536. */
            min-width: 1240px;
            table-layout: fixed;
        }
        table.table-don-hang.none_mobile th { white-space: nowrap; }
        table.table-don-hang.none_mobile th,
        table.table-don-hang.none_mobile td { padding-left: 4px; padding-right: 4px; }
        table.table-don-hang.none_mobile td {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Chia % theo bề rộng THẬT của thứ nằm trong cột, tổng đúng 100.
           Khách hàng rộng nhất vì ô chứa hai dòng (tên + số điện thoại); ba cột
           tiền theo phương thức hẹp vì chỉ chứa một con số. */
        table.table-don-hang.none_mobile th:first-child { width: 3%; }
        table.table-don-hang.none_mobile th.show_code { width: 9.5%; }
        table.table-don-hang.none_mobile th.show_customer { width: 11%; }
        table.table-don-hang.none_mobile th.show_time { width: 8.5%; }
        table.table-don-hang.none_mobile th.show_discount { width: 6.5%; }
        table.table-don-hang.none_mobile th.show_shipping_fee { width: 6%; }
        table.table-don-hang.none_mobile th.show_cash { width: 6.5%; }
        table.table-don-hang.none_mobile th.show_transfer { width: 8.5%; }
        table.table-don-hang.none_mobile th.show_online { width: 6%; }
        table.table-don-hang.none_mobile th.show_debt { width: 6%; }
        table.table-don-hang.none_mobile th.show_total { width: 7%; }
        table.table-don-hang.none_mobile th.show_payment { width: 8%; }
        table.table-don-hang.none_mobile th.show_status { width: 7.5%; }
        table.table-don-hang.none_mobile th:last-child { width: 6%; }

        /* Ô Khách hàng: tên trên, số điện thoại nhỏ bên dưới — đúng cách v2 xếp
           hai mẩu thông tin dính nhau vào một cột. */
        .dh-ten { display: block; }
        .dh-phu { display: block; font-size: 11.5px; color: #8c8c8c; }

        /* Nhãn "Quầy" cạnh mã đơn. Chỉ đánh dấu đơn quầy: đơn giao hàng là mặc
           định và chiếm gần hết bảng, dán nhãn cả hai loại chỉ thêm chữ lặp. */
        .dh-kenh {
            display: inline-block; margin-left: 4px; padding: 0 5px;
            border-radius: 3px; background: #f0f5ff; color: #2f54eb;
            font-size: 10.5px; line-height: 16px; vertical-align: middle;
        }

        /* ---------- HỘP CHI TIẾT ----------
           style.css của vỏ v2 cho `.modal-content` chạy hoạt ảnh riêng trong khi
           Bootstrap 5 đang trượt `.modal-dialog` — hai hoạt ảnh chồng nhau thì
           hộp giật. Tắt cái của vỏ, đúng như màn Công nợ đã làm. */
        #modalOrderDetail .modal-dialog { max-width: 1100px; }
        #modalOrderDetail .modal-content { animation: none !important; }
        #modalOrderDetail .dh-bang-hang { width: 100%; }
        #modalOrderDetail .dh-bang-hang th {
            background: #e9ecef; font-size: 12.5px; padding: 6px 8px; white-space: nowrap;
        }
        #modalOrderDetail .dh-bang-hang td { padding: 6px 8px; vertical-align: middle; }
        #modalOrderDetail .dh-bang-hang tfoot td { font-weight: 700; background: #efefef; }
        #modalOrderDetail .dh-tt-tieude {
            background: #e7ebee; padding: 6px 8px; font-weight: 700;
            border-bottom: 1px dotted #dee2e6; margin-bottom: 8px;
        }
        #modalOrderDetail .dh-dong {
            display: flex; justify-content: space-between; gap: 12px; padding: 3px 4px;
        }
        #modalOrderDetail .dh-dong.dh-cong {
            border-top: 1px solid #dee2e6; margin-top: 6px; padding-top: 8px;
            font-weight: 700; color: #cf1322;
        }
        #modalOrderDetail .dh-nhan-nho { color: #8c8c8c; }
        /* Hàng nút chân hộp: canh giữa, không dạt phải. */
        #modalOrderDetail .modal-footer { justify-content: center; flex-wrap: wrap; gap: 6px; }
    </style>
@endpush

@section('content')
    {{-- Nút mở từng khối lọc trên điện thoại. Tám khối, đúng tám ô của khung trái. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterKeyword',
                'modalLabel' => __('message.search'),
            ])
            @if (count($chiNhanh['ds']) > 1)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => 'filterBranch',
                    'modalLabel' => __('message.branch'),
                ])
            @endif
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterTime',
                'modalLabel' => __('message.time'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterStatus',
                'modalLabel' => __('message.status'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterPaymentStatus',
                'modalLabel' => 'Thanh toán',
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterPaymentMethod',
                'modalLabel' => __('message.payment-method-short'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterChannel',
                'modalLabel' => __('message.channel'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterSort',
                'modalLabel' => 'Sắp xếp',
            ])
        </div>
    </div>

    <div class="row index-order-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">
                        {{ __('message.filter') }}
                    </div>
                    <div class="card-body px-2">
                        {{-- Không bọc <form>: JS tự dựng URL rồi gọi V2.napLai. Trên điện
                             thoại vỏ v2 BƯNG từng khối lọc sang tấm offcanvas, mỗi lượt
                             một khối — submit lúc đó sẽ đánh rơi các ô còn lại. --}}

                        {{-- MỘT ô tìm chung cho mã đơn / tên / SĐT / email, không tách
                             "Mã hoá đơn" và "Khách hàng" thành hai ô như v2: API chỉ có
                             một tham số `keyword` và nó đã dò cả bốn cột ấy. Tách làm hai
                             ô là bày ra hai đường lọc mà chỉ một cái ăn. --}}
                        <div id="filterKeyword" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.search') }}</span>
                                <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                    class="form-control mt-1" id="dh-keyword" autocomplete="off"
                                    placeholder="Mã đơn, tên hoặc SĐT khách">
                            </div>
                        </div>

                        {{-- CHI NHÁNH — chính là chi nhánh đang làm việc của TAB, cùng thứ
                             dropdown ba gạch trên thanh đầu trang đổi. Cửa hàng một chi
                             nhánh thì không bày: ô chỉ có một lựa chọn không lọc được gì. --}}
                        @if (count($chiNhanh['ds']) > 1)
                            <div id="filterBranch" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.branch') }}</span>
                                    <select class="form-control form-select mt-1" id="dh-branch">
                                        @foreach ($chiNhanh['ds'] as $cn)
                                            <option value="{{ $cn['id'] }}"
                                                {{ (int) $chiNhanh['dangChon'] === (int) $cn['id'] ? 'selected' : '' }}>
                                                {{ $cn['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif

                        <div id="filterTime" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.time') }}</span>
                                {{-- Sáu mốc nhanh của v2. Bấm mốc nào thì điền luôn hai ô ngày
                                     bên dưới rồi lọc — hai ô vẫn là nguồn sự thật. --}}
                                <div class="d-flex flex-wrap">
                                    @foreach ([
                                        'today' => __('message.today'),
                                        'yesterday' => __('message.yesterday'),
                                        'thisWeek' => __('message.this-week'),
                                        'lastWeek' => __('message.last-week'),
                                        'thisMonth' => __('message.this-month'),
                                        'lastMonth' => __('message.last-month'),
                                    ] as $ma => $ten)
                                        <div class="col-6">
                                            <div class="form-check gap-0">
                                                <input class="me-1 form-check-input dh-moc-thoi-gian" type="radio"
                                                    name="dh_moc" value="{{ $ma }}" id="dh_moc_{{ $ma }}">
                                                <label class="form-check-label" for="dh_moc_{{ $ma }}">{{ $ten }}</label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex flex-lg-column gap-2 gap-lg-1 mt-1">
                                    <input type="text" name="from_date" autocomplete="off"
                                        value="{{ $ngayVN($filters['from_date']) }}" class="form-control"
                                        id="dh-from-date" placeholder="{{ __('message.from_date') }}">
                                    <input type="text" name="to_date" autocomplete="off"
                                        value="{{ $ngayVN($filters['to_date']) }}" class="form-control"
                                        id="dh-to-date" placeholder="{{ __('message.to_date') }}">
                                </div>
                            </div>
                        </div>

                        {{-- Trạng thái chọn được NHIỀU, đúng như dãy checkbox của v2 — chỉ
                             khác là dùng ô thả xuống cho hợp với các màn v2 còn lại. --}}
                        <div id="filterStatus" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.status') }}</span>
                                <select class="form-control form-select mt-1" id="dh-status" name="status" multiple>
                                    @foreach ($C::STATUSES as $ma => $ten)
                                        <option value="{{ $ma }}"
                                            {{ in_array($ma, $trangThaiChon, true) ? 'selected' : '' }}>{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Ba ô dưới chỉ nhận MỘT giá trị: API so bằng dấu "=", không phải
                             IN như trạng thái. Nên mỗi ô có sẵn dòng "Tất cả" để bỏ lọc. --}}
                        <div id="filterPaymentStatus" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">Thanh toán</span>
                                <select class="form-control form-select mt-1" name="payment_status">
                                    <option value="all">{{ __('message.all') }}</option>
                                    @foreach ($C::PAYMENT_STATUSES as $ma => $ten)
                                        <option value="{{ $ma }}"
                                            {{ $filters['payment_status'] === $ma ? 'selected' : '' }}>{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterPaymentMethod" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.payment-method-short') }}</span>
                                <select class="form-control form-select mt-1" name="payment_method">
                                    <option value="all">{{ __('message.all') }}</option>
                                    @foreach ($C::PAYMENT_METHODS as $ma => $ten)
                                        <option value="{{ $ma }}"
                                            {{ $filters['payment_method'] === $ma ? 'selected' : '' }}>{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- KÊNH BÁN thay cho ô "Tại bàn / Mang về" của v2: cùng vai trò —
                             nói đơn phát sinh ở đâu và vận hành theo luồng nào. --}}
                        <div id="filterChannel" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.channel') }}</span>
                                <select class="form-control form-select mt-1" name="channel">
                                    <option value="all">{{ __('message.all') }}</option>
                                    @foreach ($C::CHANNELS as $ma => $ten)
                                        <option value="{{ $ma }}"
                                            {{ $filters['channel'] === $ma ? 'selected' : '' }}>{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterSort" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">Sắp xếp</span>
                                <select class="form-control form-select mt-1" name="sort">
                                    @foreach ($C::SORTS as $ma => $ten)
                                        <option value="{{ $ma }}"
                                            {{ $filters['sort'] === $ma ? 'selected' : '' }}>{{ $ten }}</option>
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
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">{{ $C::TITLE }}</h1>

                    <div class="justify-content-end">
                        <div class="btn_top_content d-flex align-items-center">
                            {{-- KHÔNG có nút "Thêm": đơn hàng sinh ra từ quầy thu ngân và từ
                                 website, màn này chỉ để tra và xử lý — đúng như v2. --}}
                            <a class="btn btn-sm d-flex align-items-center btn-export"
                                href="{{ route('admin.orders.export', request()->query()) }}">
                                <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export_report') }}
                            </a>

                            {{-- Chọn cột: bỏ tick là thêm cột vào ?hide=, tải lại giữ nguyên. --}}
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col" href="#">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" data-col="show_all" type="checkbox"
                                                    id="show_all" {{ count($cotTat) ? '' : 'checked' }}>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($C::COT_BANG as $cot => $chu)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="show_{{ $cot }}"
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

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-don-hang none_mobile">
                            <tr>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ __('message.order_code') }}</th>
                                <th class="text-left show_customer {{ $columns['show_customer'] ? '' : 'hide' }}">{{ __('message.customer') }}</th>
                                <th class="text-center show_time {{ $columns['show_time'] ? '' : 'hide' }}">{{ __('message.time') }}</th>
                                <th class="text-right show_discount {{ $columns['show_discount'] ? '' : 'hide' }}">Giảm giá</th>
                                <th class="text-right show_shipping_fee {{ $columns['show_shipping_fee'] ? '' : 'hide' }}">Phí giao</th>
                                <th class="text-right show_cash {{ $columns['show_cash'] ? '' : 'hide' }}">Tiền mặt</th>
                                <th class="text-right show_transfer {{ $columns['show_transfer'] ? '' : 'hide' }}">Chuyển khoản</th>
                                <th class="text-right show_online {{ $columns['show_online'] ? '' : 'hide' }}">Thẻ/Ví</th>
                                <th class="text-center show_debt {{ $columns['show_debt'] ? '' : 'hide' }}">{{ __('message.debt') }}</th>
                                <th class="text-right show_total {{ $columns['show_total'] ? '' : 'hide' }}">{{ __('message.total_money') }}</th>
                                <th class="text-left show_payment {{ $columns['show_payment'] ? '' : 'hide' }}">Thanh toán</th>
                                <th class="text-left show_status {{ $columns['show_status'] ? '' : 'hide' }}">{{ __('message.status') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($orders as $i => $o)
                                @php
                                    $id = (int) ($o['id'] ?? 0);
                                    $tt = (string) ($o['status'] ?? 'pending');
                                    $ttTien = (string) ($o['payment_status'] ?? 'pending');
                                    $pt = (string) ($o['payment_method'] ?? '');
                                    $nhom = $C::NHOM_TIEN[$pt] ?? '';
                                    // Tiền chỉ rơi vào cột phương thức khi đơn ĐÃ THU. Đơn chưa
                                    // thu mà vẫn in số vào cột "Tiền mặt" là báo cáo nói dối.
                                    $daThu = $ttTien === 'paid';
                                    $oTien = fn ($k) => $daThu && $nhom === $k ? $tien($o['total_amount'] ?? 0) : '0';
                                    $conNo = in_array($ttTien, ['pending', 'failed'], true);
                                @endphp
                                <tr class="item" data-id="{{ $id }}" data-code="{{ $o['order_code'] ?? '' }}">
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    {{-- Mã đơn là CHỮ TRẦN, không phải liên kết: cửa xem chi tiết là
                                         con mắt ở cột Hành động. Để trần thì bôi đen chép lại được. --}}
                                    <td class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}"
                                        title="{{ $o['order_code'] ?? '' }}">
                                        {{ $o['order_code'] ?? '' }}
                                        @if (($o['channel'] ?? 'web') === 'pos')
                                            <span class="dh-kenh" title="Đơn bán tại quầy">Quầy</span>
                                        @endif
                                    </td>
                                    <td class="text-left show_customer {{ $columns['show_customer'] ? '' : 'hide' }}"
                                        title="{{ trim(($o['recipient_name'] ?? '').' '.($o['recipient_phone'] ?? '')) }}">
                                        <span class="dh-ten">{{ $o['recipient_name'] ?? '' }}</span>
                                        <span class="dh-phu">{{ $o['recipient_phone'] ?? '' }}</span>
                                    </td>
                                    <td class="text-center show_time {{ $columns['show_time'] ? '' : 'hide' }}"
                                        title="{{ $gioNgay($o['created_at'] ?? '') }}">{{ $ngayVN($o['created_at'] ?? '') }}</td>
                                    <td class="text-right show_discount {{ $columns['show_discount'] ? '' : 'hide' }}">{{ $tien($o['discount_amount'] ?? 0) }}</td>
                                    <td class="text-right show_shipping_fee {{ $columns['show_shipping_fee'] ? '' : 'hide' }}">{{ $tien($o['shipping_fee'] ?? 0) }}</td>
                                    <td class="text-right show_cash {{ $columns['show_cash'] ? '' : 'hide' }}">{{ $oTien('cash') }}</td>
                                    <td class="text-right show_transfer {{ $columns['show_transfer'] ? '' : 'hide' }}">{{ $oTien('transfer') }}</td>
                                    <td class="text-right show_online {{ $columns['show_online'] ? '' : 'hide' }}">{{ $oTien('online') }}</td>
                                    <td class="text-center show_debt {{ $columns['show_debt'] ? '' : 'hide' }}">
                                        <span class="text-danger">{{ $conNo ? 'Có' : '-' }}</span>
                                    </td>
                                    <td class="text-right show_total {{ $columns['show_total'] ? '' : 'hide' }}">{{ $tien($o['total_amount'] ?? 0) }}</td>
                                    <td class="text-left show_payment {{ $columns['show_payment'] ? '' : 'hide' }}">
                                        <b class="{{ $mauTien[$ttTien] ?? '' }}">{{ $C::PAYMENT_STATUSES[$ttTien] ?? '' }}</b>
                                    </td>
                                    <td class="text-left show_status {{ $columns['show_status'] ? '' : 'hide' }}">
                                        <b class="{{ $mauTrangThai[$C::STATUS_TONES[$tt] ?? 'info'] ?? '' }}">{{ $C::STATUSES[$tt] ?? '' }}</b>
                                    </td>
                                    <td class="text-center action not-export">
                                        <a class="detail-item" type="button" title="{{ __('message.view-detail') }}"><i class="fa fa-eye"></i></a>
                                        <a href="{{ route('admin.orders.print', $id) }}" target="_blank" rel="noopener"
                                            title="In đơn hàng"><i class="fa fa-print"></i></a>
                                        <a href="{{ route('admin.orders.label', $id) }}" target="_blank" rel="noopener"
                                            title="In tem giao hàng"><i class="fa fa-tag"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="text-center py-4">
                                        {{ $coLoc
                                            ? 'Không có đơn hàng nào khớp bộ lọc đang bật.'
                                            : 'Chưa có đơn hàng nào. Đơn sẽ hiện ở đây khi khách đặt trên website hoặc khi thu ngân bán tại quầy.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- BẢN THẺ CHO ĐIỆN THOẠI. Dưới 992px vỏ v2 giấu hẳn bảng, không có
                             khối này thì màn trống trơn. Cùng class .item và cùng data-id nên
                             con mắt xem chi tiết dùng chung một đoạn JS. --}}
                        <div class="table-don-hang list none_desktop">
                            <div class="d-flex align-items-center gap-1 p-2 border">
                                <div class="fw-bold" style="flex: 1">{{ __('message.order_code') }}</div>
                                <div class="fw-bold">{{ __('message.total_money') }}</div>
                            </div>
                            @foreach ($orders as $o)
                                @php $tt = (string) ($o['status'] ?? 'pending'); @endphp
                                <div class="item" data-id="{{ (int) ($o['id'] ?? 0) }}">
                                    <div class="d-flex flex-column" style="flex: 1">
                                        <span class="fw-semibold">{{ $o['order_code'] ?? '' }}</span>
                                        <small class="{{ $mauTrangThai[$C::STATUS_TONES[$tt] ?? 'info'] ?? '' }}">
                                            {{ $C::STATUSES[$tt] ?? '' }} · {{ $o['recipient_name'] ?? '' }}
                                        </small>
                                    </div>
                                    <div class="d-flex justify-content-end text-right gap-2" style="min-width: 110px">
                                        <b>{{ $tien($o['total_amount'] ?? 0) }}</b>
                                    </div>
                                </div>
                            @endforeach
                            @if (! count($orders))
                                <div class="text-center py-4">
                                    {{ $coLoc ? 'Không có đơn hàng nào khớp bộ lọc đang bật.' : 'Chưa có đơn hàng nào.' }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width {{ count($orders) ? '' : 'd-none' }}"
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

    {{-- ===================== Hộp xem chi tiết =====================
         Khuôn của v2 (manager-order/modal-view): tiêu đề "Đơn hàng - <mã>", hàng
         thông tin khách, bảng hàng bên trái, khối "Thông tin thanh toán" bên
         phải, hàng nút ở chân. --}}
    <div class="modal" id="modalOrderDetail" data-id="" style="padding-inline: 0 !important">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-auto">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">
                        Đơn hàng - <span class="text-orange" id="dh-title-code"></span>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>

                <div class="modal-body">
                    <div class="row px-2 mb-2">
                        <div class="col-12 col-xl-8 d-flex flex-wrap gap-3">
                            <div>
                                <i class="fa fa-user me-1" aria-hidden="true"></i>
                                <b id="dh-v-name"></b>
                                <span class="dh-nhan-nho" id="dh-v-phone"></span>
                            </div>
                            <div id="dh-v-addr-wrap">
                                <i class="fa fa-map-marker-alt me-1" aria-hidden="true"></i>
                                <span id="dh-v-addr"></span>
                            </div>
                        </div>
                        <div class="col-12 col-xl-4 d-flex justify-content-xl-end gap-3">
                            <span id="dh-v-status"></span>
                            <span id="dh-v-created"></span>
                        </div>
                    </div>

                    <div class="row p-2">
                        <div class="col-12 col-lg-7 col-xl-8" style="overflow: auto;">
                            <table class="dh-bang-hang">
                                <thead>
                                    <tr>
                                        <th class="text-center">{{ __('message.stt') }}</th>
                                        <th class="text-left">Hàng hoá</th>
                                        <th class="text-right">Đơn giá</th>
                                        <th class="text-right">{{ __('message.quantity') }}</th>
                                        <th class="text-right">Thành tiền</th>
                                    </tr>
                                </thead>
                                <tbody id="dh-v-items"></tbody>
                            </table>
                        </div>

                        <div class="col-12 col-lg-5 col-xl-4 mt-3 mt-lg-0">
                            <div class="dh-tt-tieude">Thông tin thanh toán</div>
                            <div class="dh-dong"><span>Tiền hàng</span><span id="dh-v-subtotal"></span></div>
                            <div class="dh-dong"><span>Giảm giá</span><span id="dh-v-discount"></span></div>
                            <div class="dh-dong" id="dh-v-voucher-wrap">
                                <span class="dh-nhan-nho">Mã giảm giá</span><span id="dh-v-voucher"></span>
                            </div>
                            <div class="dh-dong"><span>Phí giao hàng</span><span id="dh-v-ship"></span></div>
                            <div class="dh-dong dh-cong"><span>Tổng thanh toán</span><span id="dh-v-total"></span></div>
                            <div class="dh-dong"><span>{{ __('message.payment-method-short') }}</span><span id="dh-v-method"></span></div>
                            <div class="dh-dong"><span>Trạng thái tiền</span><span id="dh-v-paystatus"></span></div>
                            <div class="dh-dong" id="dh-v-note-wrap">
                                <span class="dh-nhan-nho">Khách ghi chú</span><span id="dh-v-note"></span>
                            </div>
                            <div class="dh-dong" id="dh-v-etax-wrap">
                                <span class="dh-nhan-nho">Hoá đơn điện tử</span><span id="dh-v-etax"></span>
                            </div>

                            {{-- Ba ô SỬA ĐƯỢC, thứ duy nhất trong hộp không phải chỉ để đọc.
                                 v2 không có khối này vì quán ăn không giao hàng; shop thì
                                 đơn giao đi mà không ghi được mã vận đơn là mất dấu hàng. --}}
                            <div id="dh-v-sua" class="mt-3">
                                <div class="dh-tt-tieude">Vận chuyển &amp; ghi chú nội bộ</div>
                                <div id="dh-v-ship-fields">
                                    <label class="form-label mb-1">Đơn vị vận chuyển</label>
                                    <input type="text" class="form-control mb-2" id="dh-v-shipmethod" maxlength="100"
                                        placeholder="VD: GHN, GHTK">
                                    <label class="form-label mb-1">Mã vận đơn</label>
                                    <input type="text" class="form-control mb-2" id="dh-v-tracking" maxlength="100">
                                </div>
                                <label class="form-label mb-1">Ghi chú nội bộ</label>
                                <textarea class="form-control" id="dh-v-adminnote" rows="2" maxlength="500"></textarea>
                                <div class="text-center mt-2">
                                    <button type="button" class="bt btn_green" id="dh-v-luu">{{ __('message.save') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Hàng nút canh giữa. Nút chuyển trạng thái do JS dựng theo đúng
                     `next_statuses` API trả về — bày nút rồi báo lỗi lúc bấm là bẫy
                     người dùng. Nút bỏ đi (huỷ / trả hàng) luôn đỏ, nút đồng ý xanh. --}}
                <div class="modal-footer">
                    <span id="dh-v-actions" class="d-flex flex-wrap gap-2 justify-content-center"></span>
                    <a class="bt btn_advanced" id="dh-v-print" target="_blank" rel="noopener">In đơn</a>
                    <a class="bt btn_advanced" id="dh-v-label" target="_blank" rel="noopener">In tem</a>
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Hộp hỏi lý do — huỷ đơn bắt buộc có lý do (API và controller cùng chặn). --}}
    <div class="modal" id="modalOrderReason">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="dh-reason-title"></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Lý do <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="dh-reason-note" rows="3" maxlength="255"></textarea>
                    <input type="hidden" id="dh-reason-status">
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">Huỷ</button>
                    <button type="button" class="bt btn_green" id="dh-reason-ok">Xác nhận</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_DH = @json(url('/admin/orders'));

        const DH_STATUSES = @json(\App\Http\Controllers\OrderController::STATUSES);
        const DH_TONES = @json(\App\Http\Controllers\OrderController::STATUS_TONES);
        const DH_PAY_STATUSES = @json(\App\Http\Controllers\OrderController::PAYMENT_STATUSES);
        const DH_PAY_METHODS = @json(\App\Http\Controllers\OrderController::PAYMENT_METHODS);
        const DH_MAU_TONE = { wait: 'text-warning', info: 'text-primary', move: 'text-info', done: 'text-success', stop: 'text-danger' };
        const DH_MAU_TIEN = { paid: 'text-success', pending: '', failed: 'text-danger', refunded: 'text-warning' };

        const tienVN = (n) => Number(n || 0).toLocaleString('vi-VN');
        const thoat = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);

        // ================= Bộ lọc =================
        // Tự dựng URL thay vì submit form: trên điện thoại vỏ v2 BƯNG từng khối
        // lọc sang tấm offcanvas, mỗi lượt một khối, nên submit lúc đó đánh rơi
        // các ô còn lại. Ô lọc nằm ở đâu cũng tìm ra — khung trái và tấm
        // offcanvas cùng mang class .fillter-box.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai() {
            const q = new URLSearchParams();

            const kw = String(oLoc('keyword').val() || '').trim();
            if (kw) q.set('keyword', kw);

            // Trạng thái chọn nhiều: gộp thành chuỗi ngăn bởi dấu phẩy — đúng cái
            // API đọc (status IN (...)).
            const tt = [].concat(oLoc('status').val() || []);
            if (tt.length) q.set('status', tt.join(','));

            // Ba ô một-giá-trị: 'all' nghĩa là không lọc, không cần gửi.
            ['payment_status', 'payment_method', 'channel'].forEach(function (ten) {
                const v = String(oLoc(ten).val() || 'all');
                if (v && v !== 'all') q.set(ten, v);
            });

            const sap = String(oLoc('sort').val() || '');
            if (sap && sap !== 'newest') q.set('sort', sap);

            // Hai ô ngày LUÔN gửi, kể cả khi trống: bỏ trống là "không giới hạn",
            // không gửi thì mất nghĩa đó ở lượt lọc sau.
            ['from_date', 'to_date'].forEach(function (ten) {
                q.set(ten, String(oLoc(ten).val() || '').trim());
            });

            // Tham số không có ô trong khung lọc thì chép lại từ URL cũ, không thì
            // đổi bộ lọc một cái là mất luôn cột đang ẩn và cỡ trang.
            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size'].forEach(function (ten) {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            // Cố ý không mang `page`: lọc lại thì trang 5 của bộ lọc cũ hết nghĩa.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerLoc = null;
        $(document).on('input', '.fillter-box [name="keyword"]', function () {
            clearTimeout(timerLoc);
            timerLoc = setTimeout(locLai, 300);
        });
        $(document).on('change',
            '.fillter-box [name="status"], .fillter-box [name="payment_status"], '
            + '.fillter-box [name="payment_method"], .fillter-box [name="channel"], '
            + '.fillter-box [name="sort"], .fillter-box [name="from_date"], .fillter-box [name="to_date"]',
            locLai);

        // Sáu mốc nhanh: điền hai ô ngày rồi lọc. Hai ô vẫn là nguồn sự thật nên
        // người dùng sửa tay sau đó cũng không chọi với mốc đang tick.
        $(document).on('change', '.dh-moc-thoi-gian', function () {
            const nay = moment();
            let tu, den;
            switch (this.value) {
                case 'today': tu = nay.clone().startOf('day'); den = nay.clone(); break;
                case 'yesterday': tu = nay.clone().subtract(1, 'days'); den = tu.clone(); break;
                case 'thisWeek': tu = nay.clone().startOf('isoWeek'); den = nay.clone(); break;
                case 'lastWeek': tu = nay.clone().subtract(1, 'weeks').startOf('isoWeek'); den = tu.clone().endOf('isoWeek'); break;
                case 'thisMonth': tu = nay.clone().startOf('month'); den = nay.clone(); break;
                case 'lastMonth': tu = nay.clone().subtract(1, 'months').startOf('month'); den = tu.clone().endOf('month'); break;
                default: return;
            }
            oLoc('from_date').val(tu.format('DD-MM-YYYY'));
            oLoc('to_date').val(den.format('DD-MM-YYYY'));
            locLai();
        });

        // Ô Chi nhánh KHÔNG đi qua locLai(): đây là chi nhánh đang làm việc của
        // tab, đổi nó là đổi cả phiên chứ không phải thêm một điều kiện lọc.
        $(document).on('change', '#dh-branch', function () {
            V2.doiChiNhanhTab(this.value);
        });

        // ================= Chọn cột =================
        function apDungCot() {
            const tat = $('.show_col').filter(function (i, el) { return !el.checked; })
                .map(function (i, el) { return $(el).data('col').replace('show_', ''); }).get();
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
            ['#dh-from-date', '#dh-to-date'].forEach(function (sel) {
                const $o = $(sel);
                if (!$o.length || $o.data('daterangepicker')) return;
                $o.daterangepicker({
                    singleDatePicker: true,
                    showDropdowns: true,
                    locale: V2.lichVN(),
                    autoUpdateInput: false,
                    autoApply: true,
                }, function (start) {
                    $o.val(start.format('DD-MM-YYYY')).trigger('change');
                });
            });
        }
        $(ganLich);
        $(document).on('v2:da-nap', ganLich);

        // ================= Hộp xem chi tiết =================
        let donDangXem = null;

        function veHangHoa(items) {
            const ds = items || [];
            if (!ds.length) {
                $('#dh-v-items').html('<tr><td colspan="5" class="text-center py-3">Đơn không có dòng hàng nào.</td></tr>');
                return;
            }

            let tongHang = 0;
            const dong = ds.map(function (d, i) {
                const thanhTien = Number(d.unit_price || 0) * Number(d.quantity || 0) - Number(d.discount_amount || 0);
                tongHang += thanhTien;
                const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');

                return '<tr>'
                    + '<td class="text-center">' + (i + 1) + '</td>'
                    + '<td class="text-left">' + thoat(ten)
                    + (d.variant_sku ? '<div class="dh-nhan-nho">' + thoat(d.variant_sku) + '</div>' : '')
                    + '</td>'
                    + '<td class="text-right">' + tienVN(d.unit_price) + 'đ</td>'
                    + '<td class="text-right">' + tienVN(d.quantity) + '</td>'
                    + '<td class="text-right">' + tienVN(thanhTien) + 'đ</td>'
                    + '</tr>';
            }).join('');

            $('#dh-v-items').html(dong
                + '<tr><td colspan="4" class="text-end"><b>Tổng tiền hàng</b></td>'
                + '<td class="text-right"><b>' + tienVN(tongHang) + 'đ</b></td></tr>');
        }

        /** Bày/giấu một dòng của khối thanh toán theo việc nó có giá trị hay không. */
        function dongCoDieuKien(idBoc, idChu, giaTri) {
            $(idBoc).toggle(Boolean(giaTri));
            $(idChu).text(giaTri || '');
        }

        function veHopChiTiet(o, hoaDon) {
            donDangXem = o;

            $('#modalOrderDetail').attr('data-id', o.id);
            $('#dh-title-code').text(o.order_code || '');

            $('#dh-v-name').text(o.recipient_name || '—');
            $('#dh-v-phone').text(o.recipient_phone ? ' · ' + o.recipient_phone : '');

            const diaChi = [o.shipping_address, o.shipping_ward, o.shipping_district, o.shipping_province]
                .filter(Boolean).join(', ');
            // Đơn quầy không có địa chỉ giao — giấu hẳn dòng thay vì in một gạch ngang.
            $('#dh-v-addr-wrap').toggle(Boolean(diaChi));
            $('#dh-v-addr').text(diaChi);

            $('#dh-v-status').html('<b class="' + (DH_MAU_TONE[DH_TONES[o.status]] || '') + '">'
                + thoat(DH_STATUSES[o.status] || o.status || '') + '</b>');
            $('#dh-v-created').text(o.created_at ? moment(o.created_at).format('HH:mm DD-MM-YYYY') : '');

            veHangHoa(o.items);

            $('#dh-v-subtotal').text(tienVN(o.subtotal_amount) + 'đ');
            $('#dh-v-discount').text((Number(o.discount_amount || 0) > 0 ? '-' : '') + tienVN(o.discount_amount) + 'đ');
            $('#dh-v-ship').text(tienVN(o.shipping_fee) + 'đ');
            $('#dh-v-total').text(tienVN(o.total_amount) + 'đ');
            $('#dh-v-method').text(DH_PAY_METHODS[o.payment_method] || o.payment_method || '—');
            $('#dh-v-paystatus').html('<b class="' + (DH_MAU_TIEN[o.payment_status] || '') + '">'
                + thoat(DH_PAY_STATUSES[o.payment_status] || '') + '</b>');

            dongCoDieuKien('#dh-v-voucher-wrap', '#dh-v-voucher', o.voucher_code);
            dongCoDieuKien('#dh-v-note-wrap', '#dh-v-note', o.note);

            // Đơn quầy giao ngay tại chỗ nên không có gì để vận chuyển — giấu hai
            // ô ấy đi, chỉ để lại ghi chú nội bộ.
            $('#dh-v-ship-fields').toggle((o.channel || 'web') !== 'pos');
            $('#dh-v-shipmethod').val(o.shipping_method || '');
            $('#dh-v-tracking').val(o.tracking_number || '');
            $('#dh-v-adminnote').val(o.admin_note || '');
            // Hộp dùng lại cho mọi đơn nên phải mở khoá nút Lưu ở mỗi lượt mở.
            $('#dh-v-luu').prop('disabled', false);

            veHoaDon(o, hoaDon);
            veNutThaoTac(o);

            $('#dh-v-print').attr('href', URL_DH + '/' + o.id + '/print');
            $('#dh-v-label').attr('href', URL_DH + '/' + o.id + '/label');

            $('#modalOrderDetail').modal('show');
        }

        /** Dòng hoá đơn điện tử. Chưa nối cổng thì API trả null — giấu hẳn dòng. */
        function veHoaDon(o, hd) {
            if (!hd) {
                $('#dh-v-etax-wrap').hide();
                return;
            }

            const ten = {
                draft: 'Nháp — chưa ký',
                sent: 'Đã gửi — chờ cấp mã',
                issued: 'Đã cấp mã' + (hd.invoice_no ? ' · số ' + hd.invoice_no : ''),
                failed: 'Cổng từ chối',
            }[hd.status] || hd.status || '';

            let chu = thoat(ten);
            if (hd.status === 'issued') {
                chu += ' <a href="' + URL_DH + '/' + o.id + '/etax/pdf" target="_blank" rel="noopener">PDF</a>'
                    + ' · <a href="' + URL_DH + '/' + o.id + '/etax/xml" target="_blank" rel="noopener">XML</a>';
            }

            $('#dh-v-etax-wrap').show();
            $('#dh-v-etax').html(chu);
        }

        /** Nút chuyển trạng thái + đánh dấu tiền, dựng theo đúng `next_statuses`
         *  API trả về. Không tự đoán luồng ở đây: hai nơi đoán khác nhau là bày ra
         *  nút bấm vào chỉ nhận lỗi. */
        function veNutThaoTac(o) {
            const tiep = o.next_statuses || [];
            const ketThuc = ['cancelled', 'returned'].indexOf(o.status) !== -1;

            let html = tiep.map(function (st) {
                // Nút bỏ đi luôn đỏ, nút đồng ý luôn xanh.
                const bo = st === 'cancelled' || st === 'returned';

                return '<button type="button" class="bt ' + (bo ? 'btn_red' : 'btn_green') + '"'
                    + ' data-status="' + st + '"' + (bo ? ' data-reason="1"' : '')
                    + '>' + thoat(DH_STATUSES[st] || st) + '</button>';
            }).join('');

            if (o.payment_status !== 'paid' && !ketThuc) {
                html += '<button type="button" class="bt btn_advanced" data-payment="paid">Đánh dấu đã thanh toán</button>';
            } else if (o.payment_status === 'paid') {
                html += '<button type="button" class="bt btn_advanced" data-payment="refunded">Đánh dấu hoàn tiền</button>';
            }

            // Cổng hoá đơn chỉ nhận đơn ĐÃ THU tiền — ẩn nút thay vì để bấm rồi báo lỗi.
            if (o.payment_status === 'paid' && !o.etax_issued) {
                html += '<button type="button" class="bt btn_advanced" data-etax="1">Phát hành hoá đơn</button>';
            }

            $('#dh-v-actions').html(html
                || '<span class="dh-nhan-nho">Đơn đã ở trạng thái cuối, không đổi tiếp được.</span>');
        }

        $(document).on('click', '.detail-item', function () {
            const id = $(this).data('id') || $(this).closest('.item').data('id');
            if (!id) return;

            fetch(URL_DH + '/' + id + '/detail', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);

                    return r.json();
                })
                .then(function (d) {
                    const hd = d.etax || null;
                    const o = d.data || {};
                    // Cờ để veNutThaoTac biết đã có tờ hoá đơn còn hiệu lực chưa.
                    o.etax_issued = Boolean(hd && hd.status !== 'failed');
                    veHopChiTiet(o, hd);
                })
                .catch(function () { toastr.error('Không tải được chi tiết đơn hàng.'); });
        });

        // ---------- Chuyển trạng thái ----------
        $(document).on('click', '#dh-v-actions [data-status]', function () {
            const st = $(this).data('status');
            const id = $('#modalOrderDetail').attr('data-id');
            if (!id) return;

            // Huỷ / trả hàng phải có lý do — API và controller cùng chặn, nên hỏi
            // ngay ở đây thay vì để người dùng bấm xong nhận toast đỏ.
            if ($(this).data('reason')) {
                $('#dh-reason-title').text(DH_STATUSES[st] || st);
                $('#dh-reason-status').val(st);
                $('#dh-reason-note').val('');
                $('#modalOrderDetail').modal('hide');
                $('#modalOrderReason').modal('show');

                return;
            }

            $('#modalOrderDetail').modal('hide');
            V2.ghi(URL_DH + '/' + id + '/status', 'PUT', { status: st });
        });

        $(document).on('click', '#dh-reason-ok', function () {
            const note = String($('#dh-reason-note').val() || '').trim();
            if (!note) {
                toastr.error('Vui lòng nhập lý do.');

                return;
            }

            const id = $('#modalOrderDetail').attr('data-id');
            $('#modalOrderReason').modal('hide');
            V2.ghi(URL_DH + '/' + id + '/status', 'PUT', { status: $('#dh-reason-status').val(), note: note });
        });

        // ---------- Đánh dấu thanh toán ----------
        $(document).on('click', '#dh-v-actions [data-payment]', function () {
            const id = $('#modalOrderDetail').attr('data-id');
            if (!id) return;

            $('#modalOrderDetail').modal('hide');
            V2.ghi(URL_DH + '/' + id + '/payment', 'PUT', { payment_status: $(this).data('payment') });
        });

        // ---------- Lưu vận chuyển + ghi chú nội bộ ----------
        //
        // Hai đường ghi khác nhau nhưng người dùng chỉ bấm MỘT nút, nên không dùng
        // được V2.ghi (nó nạp lại trang ngay sau lượt đầu). Gửi tay lần lượt, chỉ
        // gửi cái nào thật sự đổi, rồi nhặt câu báo ở phản hồi cuối và nạp lại.
        function guiPut(url, fields) {
            const fd = new FormData();
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
            fd.append('_method', 'PUT');
            fd.append('return', location.pathname + location.search);
            if (V2.chiNhanhTab) fd.append('chi_nhanh', String(V2.chiNhanhTab));
            Object.keys(fields).forEach(function (k) { fd.append(k, fields[k] == null ? '' : fields[k]); });

            return fetch(url, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }).then(function (r) { return r.text(); });
        }

        $(document).on('click', '#dh-v-luu', function () {
            const o = donDangXem;
            if (!o) return;

            const $nut = $(this).prop('disabled', true);
            const ship = String($('#dh-v-shipmethod').val() || '');
            const track = String($('#dh-v-tracking').val() || '');
            const ghiChu = String($('#dh-v-adminnote').val() || '');

            const viec = [];
            if (ship !== (o.shipping_method || '') || track !== (o.tracking_number || '')) {
                viec.push(function () {
                    return guiPut(URL_DH + '/' + o.id + '/shipping', { shipping_method: ship, tracking_number: track });
                });
            }
            if (ghiChu !== (o.admin_note || '')) {
                viec.push(function () {
                    return guiPut(URL_DH + '/' + o.id + '/note', { admin_note: ghiChu });
                });
            }

            if (!viec.length) {
                toastr.info('Chưa có gì thay đổi.');
                $nut.prop('disabled', false);

                return;
            }

            viec.reduce(function (truoc, lam) { return truoc.then(lam); }, Promise.resolve())
                .then(function (html) {
                    V2.toastTu(new DOMParser().parseFromString(html, 'text/html'));
                    $('#modalOrderDetail').modal('hide');
                    V2.napLai(location.href, false);
                })
                .catch(function () {
                    toastr.error('Không lưu được. Vui lòng thử lại.');
                    $nut.prop('disabled', false);
                });
        });

        // ---------- Phát hành hoá đơn điện tử ----------
        // Trả JSON chứ không chuyển hướng như hai thao tác trên, nên gọi thẳng
        // fetch rồi tự bắn toast và nạp lại bảng.
        $(document).on('click', '#dh-v-actions [data-etax]', function () {
            const id = $('#modalOrderDetail').attr('data-id');
            const $nut = $(this).prop('disabled', true);

            const fd = new FormData();
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));

            fetch(URL_DH + '/' + id + '/etax', {
                method: 'POST',
                body: fd,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (kq) {
                    kq.ok ? toastr.success(kq.j.message || 'Đã phát hành hoá đơn.')
                        : toastr.error(kq.j.message || 'Phát hành hoá đơn không thành công.');
                    $('#modalOrderDetail').modal('hide');
                    V2.napLai(location.href, false);
                })
                .catch(function () {
                    toastr.error('Không kết nối được cổng hoá đơn.');
                    $nut.prop('disabled', false);
                });
        });
    </script>
@endpush
