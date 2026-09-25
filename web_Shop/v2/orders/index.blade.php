{{-- Thống kê → Quản lý đơn hàng — chép khuôn từ bản v2 cũ
     (ordertable/resources/views/manager-order/index + list + modal-view).

     Giữ đúng dáng của v2: khung lọc bên trái, bảng chọn được cột bên phải, hộp
     xem chi tiết hai cột (bảng hàng bên trái, khối thanh toán bên phải).

     KHUNG LỌC dựng theo đúng thứ tự bản v2: Chi nhánh · Thời gian · Mã đơn ·
     Kênh bán · Khách hàng · Phương thức thanh toán · Trạng thái · Thanh toán ·
     Người tạo. Bốn ô giữa là DÃY CHECKBOX như v2, không phải ô thả xuống.

     Năm chỗ khác v2, đều vì đây là cửa hàng chứ không phải quán ăn:
     - Cột "Bàn", ô lọc "Bàn" và ô "Sử dụng (Tại bàn / Mang về / Online)" của v2
       KHÔNG có ở đây: shop không có bàn ăn. Chỗ "Sử dụng" thay bằng "Kênh bán"
       (đơn giao hàng / bán tại quầy) — cùng vai trò, nói đơn phát sinh ở đâu.
     - Bốn cột tiền theo phương thức của v2 (mặt / CK / thẻ / QR) gom còn ba:
       Tiền mặt (cash, COD) · Chuyển khoản (bank_transfer, SePay) · Thẻ/Ví
       (VNPay, MoMo, PayOS). API chia sẵn theo phương thức của TỪNG lượt thu,
       đúng như v2 chia đơn công nợ theo cab_debt_details.
     - v2 gộp trạng thái đơn và trạng thái tiền vào MỘT dãy; bên này tách hai
       dãy, vì đơn giao hàng còn đi qua sáu bước sau khi đã thu tiền.
     - Bỏ tick sạch một dãy = KHÔNG lọc dãy đó. Bên v2 bỏ tick sạch thì bảng
       rỗng, mà "rỗng vì bạn vừa bỏ hết tick" là câu màn hình không nói ra được.

     Ba lỗi sẵn của v2 không bê sang: lọc "Người tạo" và "Hoá đơn điện tử" gửi
     sai tên tham số nên không ăn, còn lựa chọn cột ghi nhầm page nên F5 là mất.
     Bên này cột nằm ở ?hide= và mọi ô lọc đều đúng tên API đọc. --}}
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
    $chiNhanh = \App\Services\CurrentBranch::danhSach();

    // Năm ô lọc chọn-nhiều mang chuỗi ngăn bởi dấu phẩy. 'all' là "KHÔNG lọc",
    // không phải một lựa chọn — lọc bỏ để không ô nào tick nhầm và để câu "bảng
    // rỗng" không đổ oan cho bộ lọc.
    $daChon = fn (string $khoa) => array_values(array_filter(
        explode(',', (string) ($filters[$khoa] ?? '')),
        fn ($v) => $v !== '' && $v !== 'all'
    ));

    $coLoc = collect(['keyword', 'customer', 'status', 'payment_method', 'channel', 'created_by', 'etax'])
        ->contains(fn ($k) => ($filters[$k] ?? '') !== '' && ($filters[$k] ?? '') !== 'all');

    // Màu chữ của năm trạng thái sổ — giữ ở controller để dãy ô tick và cột
    // trong bảng không bao giờ tô hai kiểu khác nhau.
    $mauTrangThai = $C::MAU_TRANG_THAI_SO;
@endphp

@push('styles')
    <style>
        /* ---------- BẢNG ----------
           Cùng luật với các màn v2 khác: TIÊU ĐỀ luôn một dòng, ô dữ liệu dài thì
           cắt bằng "…" (chữ đủ vẫn còn ở `title` và trong hộp chi tiết).

           13 cột nên bảng có min-width; màn rộng thì vừa khít, màn hẹp thì
           `.table-responsive` cho cuộn ngang — không bóp chữ lại. */
        table.table-don-hang.none_mobile {
            width: 100%;
            /* 1040, KHÔNG phải 1320. Sàn cũ lớn hơn cả khung của màn 1536 (1212px)
               nên bảng tràn ngang ở mọi khổ dưới 1920 — thừa 28px ở 1536, 108px ở
               1440, 169px ở 1366 — và cột Hành động bị đẩy ra khỏi màn.
               Phần trăm cột bên dưới nay đo theo sàn của khổ 1366 (khung 1071px):
               nhãn cột nào cũng đủ chỗ từ 1366 trở lên, nên sàn chỉ còn để chặn
               lúc màn hẹp hơn thế. */
            min-width: 1040px;
            table-layout: fixed;
        }
        /* TIÊU ĐỀ luôn một dòng — bẻ đôi một tiêu đề là hàng đầu cao gấp rưỡi và
           mắt phải dừng lại đọc từng cột.

           Hết chỗ thì cắt "…" chứ KHÔNG tràn ra ngoài ô: luật chung ở
           v2::layouts.master lo phần cắt, và gắn `title` cho đúng nhãn nào bị cắt
           để rê chuột vẫn đọc đủ. Bản cũ khai `text-overflow: clip` cho chữ tràn
           ra "để nhìn thấy mà đi nới cột" — nhưng thứ nhìn thấy là chữ đè lên cột
           bên cạnh, và cột Hành động thì đẩy hẳn khỏi màn. */
        table.table-don-hang.none_mobile th { white-space: nowrap; }
        /* 13px thay cho 14px của vỏ: mười ba cột ở 14px thì riêng phần tiêu đề đã
           đòi hơn 1400px, tức là lúc nào cũng phải cuộn ngang. */
        table.table-don-hang.none_mobile th,
        table.table-don-hang.none_mobile td { padding: 6px 6px; font-size: 13px; }

        /* Ô DỮ LIỆU KHÔNG BAO GIỜ BỊ CẮT. Chữ dài thì xuống dòng, ô cao thêm một
           nhịp — còn hơn cắt bằng "…" rồi bắt người đọc rê chuột lên mới biết đủ.
           Đây là chỗ khác hẳn màn Thu chi: bên đó 13 cột chen trong khung hẹp nên
           đành cắt, bảng này bỏ được cột "Thanh toán" nên còn chỗ thở. */
        table.table-don-hang.none_mobile td {
            white-space: normal;
            word-break: break-word;
            vertical-align: middle;
        }
        /* Trừ ô mang SỐ: bẻ dòng giữa một con số thì đọc thành hai số khác nhau. */
        table.table-don-hang.none_mobile td.la-so { white-space: nowrap; }

        /* Chia % theo bề rộng THẬT của thứ nằm trong cột, tổng đúng 100.
           Khách hàng rộng nhất vì ô chứa hai dòng (tên + số điện thoại); ba cột
           tiền theo phương thức hẹp vì chỉ chứa một con số. */
        table.table-don-hang.none_mobile th:first-child { width: 4%; }
        table.table-don-hang.none_mobile th.show_code { width: 9.7%; }
        table.table-don-hang.none_mobile th.show_customer { width: 14.8%; }
        table.table-don-hang.none_mobile th.show_time { width: 7.2%; }
        table.table-don-hang.none_mobile th.show_discount { width: 6.3%; }
        table.table-don-hang.none_mobile th.show_shipping_fee { width: 6.0%; }
        table.table-don-hang.none_mobile th.show_cash { width: 7%; }
        table.table-don-hang.none_mobile th.show_transfer { width: 9.9%; }
        table.table-don-hang.none_mobile th.show_online { width: 5.2%; }
        table.table-don-hang.none_mobile th.show_debt { width: 6.3%; }
        table.table-don-hang.none_mobile th.show_total { width: 7%; }
        /* Trạng thái rộng nhất trong nhóm cuối: "Thanh toán một phần" là nhãn dài
           nhất của cả bảng, hẹp hơn là nó bị cắt bằng "…" ngay ở dòng đầu tiên. */
        table.table-don-hang.none_mobile th.show_status { width: 8.8%; }
        table.table-don-hang.none_mobile th:last-child { width: 7.8%; }

        /* ---------- KHUNG LỌC: SIẾT KHOẢNG CÁCH ----------

           Chín khối lọc, mỗi khối `mb-3` (16px), cộng thêm nhãn 4px và margin
           sẵn có của từng dòng `.form-check` — riêng phần khoảng trống đã ngốn
           gần 200px, nên ô Người tạo rơi hẳn xuống dưới màn và phải cuộn mới
           thấy. Bên v2 khung lọc gói gọn trong một màn.

           Siết ở ĐÂY chứ không đổi `mb-3` thành `mb-2` trên từng khối: một chỗ
           khai, một chỗ sửa, và thêm khối lọc mới thì nó tự theo nhịp chung.

           KHÔNG bọc trong `.index-order-page`: dưới 992px vỏ v2 BƯNG từng khối
           lọc sang tấm offcanvas nằm ngoài khung ấy, bọc vào là trên điện thoại
           khoảng cách giãn lại như cũ. Tệp style này chỉ nạp ở màn này nên
           `.fillter-box` trần đã đủ hẹp. */
        .fillter-box .card-body > div[id^="filter"] { margin-bottom: 6px !important; }
        .fillter-box .card-body > div[id^="filter"]:last-child { margin-bottom: 0 !important; }
        .fillter-box .title_search { margin-bottom: 5px; display: block; }
        /* DÒNG TICK — dựng lại hẳn thay vì vá từng thuộc tính.

           Vì sao ô tick dính sát chữ: Bootstrap cho `.form-check` đệm trái 1.5em
           rồi kéo ô tick ngược ra bằng `margin-left: -1.5em`, còn vỏ v2 lại đổi
           `.form-check` thành `display: flex`. Trong flex thì cặp đệm-âm ấy hết
           tác dụng như thiết kế: ô tick nằm sát mép, nhãn dính ngay sau nó, giữa
           hai thứ không còn khoảng nào.

           Nên bỏ luôn trò đệm-âm và khai một `gap` thật. Cách này không phụ thuộc
           vỏ khai gì: thêm hay bớt quy tắc ở style.css cũng không kéo hai thứ dính
           lại được nữa. */
        .fillter-box .form-check {
            display: flex;
            align-items: center;
            gap: 7px;
            padding-left: 0;
            min-height: 0;
            margin-bottom: 3px;
        }
        .fillter-box .form-check:last-child { margin-bottom: 0; }
        .fillter-box .form-check-input { margin: 0; flex: 0 0 auto; }
        .fillter-box .form-check-label { line-height: 20px; cursor: pointer; }
        /* Ô nhập/chọn đứng ngay dưới nhãn, không cần thêm nhịp nữa. */
        .fillter-box .mt-1 { margin-top: 1px !important; }
        /* Hai ô ngày sát nhau, không cần nhịp thở giữa chúng. */
        .fillter-box .gap-lg-1 { gap: 3px !important; }

        /* HAI NÚT Ở GÓC PHẢI TIÊU ĐỀ PHẢI BẰNG NHAU.

           Vỏ v2 cho chúng hai mức đệm và hai độ dày viền khác nhau — .btn-export
           đệm 3px viền 1px, .setting-col đệm 7px viền 2px — nên đứng cạnh nhau là
           một cái cao hơn cái kia đúng một nhịp, nhìn như xếp lệch.

           Ép về cùng chiều cao NGAY TRONG màn này, không sửa style.css chung: mấy
           màn v2 khác đang dựa vào hai mức đệm ấy. */
        .index-order-page .btn_top_content .btn-export,
        .index-order-page .btn_top_content .setting-col {
            height: 34px;
            min-height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            margin: 0;
        }
        .index-order-page .btn_top_content .btn-export { padding: 0 12px !important; }
        /* Nút chọn cột chỉ có một icon nên để vuông — cao bằng, rộng bằng cao. */
        .index-order-page .btn_top_content .setting-col { width: 34px; padding: 0 !important; }
        .index-order-page .btn_top_content .dropup { display: inline-flex; }
        /* `margin: 0` ở trên xoá luôn khoảng cách GIỮA hai nút nên chúng dính vào
           nhau. Trả lại bằng gap của chính hàng flex — một chỗ khai, không phải
           nhớ gắn margin-right cho nút bên trái. */
        .index-order-page .btn_top_content { gap: 8px; }

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
        /* Phiếu trả nằm chung sổ với đơn bán (đúng như v2 union hai bảng). Nhãn
           cam + nền nhạt để mắt tách được ngay: đó là tiền đi RA, đọc lướt mà
           cộng nhầm vào doanh thu là sai cả buổi đối soát. */
        .dh-kenh.dh-tra { background: #fff7e6; color: #d46b08; }
        tr.dong-tra-hang > td { background: #fffbf5; }
        /* Hai hàng tổng dưới chân bảng — nền xám, chữ đậm như hàng tổng của v2.
           Khai trên td chứ không trên tr: nền của tr bị nền td của vỏ đè mất. */
        tr.dong-tong > td { background: #EFEFEF; font-weight: bold; }

        /* ---------- HỘP CHI TIẾT ----------
           Ba lớp dưới đây v2 khai NGAY TRONG màn Quản lý đơn hàng của nó chứ
           không có trong style.css chung, nên phải chép sang thì hộp mới đúng
           dáng. */
        .border-bottom-dotted { border-bottom: 1px dotted #dee2e6; }
        .cus-bg-EFEFEF { background-color: #EFEFEF; }
        .cus-fw-bold { font-weight: bold; }

        #modalOrderDetail .modal-dialog { max-width: 1100px; }
        #modalOrderDetail .modal-content { animation: none !important; }
        /* Bảng hàng: v2 để `.order-section th` nền #e9ecef, đệm .5rem. */
        #modalOrderDetail table.bang-hang { width: 100%; }
        #modalOrderDetail table.bang-hang th {
            background: #e9ecef; padding: .5rem; white-space: nowrap; font-size: 12.5px;
        }
        #modalOrderDetail table.bang-hang td { padding: .5rem; vertical-align: middle; }
        /* Khối thanh toán bên phải — v2 xếp bằng d-flex justify-content-between,
           chỉ thêm nhịp thở giữa các dòng. */
        #modalOrderDetail .inftt > div { padding: 3px 4px; }
        #modalOrderDetail .dh-nhan-nho { color: #8c8c8c; }
    </style>
@endpush

@section('content')
    {{-- Nút mở từng khối lọc trên điện thoại. Tám khối, đúng tám ô của khung trái. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @foreach ([
                'filterBranch' => __('message.branch'),
                'filterTime' => __('message.time'),
                'filterEtax' => 'HĐĐT',
                'filterCode' => __('message.order_code'),
                'filterChannel' => __('message.channel'),
                'filterCustomer' => __('message.customer'),
                'filterPaymentMethod' => __('message.payment-method'),
                'filterStatus' => __('message.status'),
                'filterCreator' => __('message.creator'),
            ] as $oLoc => $nhan)
                @if ($oLoc !== 'filterBranch' || count($chiNhanh['ds']) > 1)
                    @include('v2::partials.filter-button-mobile', [
                        'dataBsTarget' => 'offcanvasBottomInMobile',
                        'dataOffcanvasTarget' => $oLoc,
                        'modalLabel' => $nhan,
                    ])
                @endif
            @endforeach
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

                        {{-- 1. CHI NHÁNH — chính là chi nhánh đang làm việc của TAB, cùng
                             thứ dropdown ba gạch trên thanh đầu trang đổi. Cửa hàng một
                             chi nhánh thì không bày: ô chỉ có một lựa chọn không lọc được
                             gì mà vẫn ăn nguyên một khoảng của cột. --}}
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

                        {{-- 2. THỜI GIAN — hai ô ngày, KHÔNG có dãy mốc nhanh: bản v2 chỉ
                             bày đúng hai ô này. --}}
                        <div id="filterTime" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.time') }}</span>
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

                        {{-- 3. HĐĐT — đơn đã xuất hoá đơn điện tử hay chưa.

                             Tick cả hai là KHÔNG lọc, đúng như v2 bày sẵn cả hai ô: mọi
                             đơn đều rơi vào một trong hai nhóm nên hỏi cả hai là hỏi tất
                             cả. Bật một bên thì phiếu trả rơi khỏi danh sách — nó không tự
                             có tờ hoá đơn nào, v2 cũng gạt nhánh phiếu trả đúng lúc này. --}}
                        <div id="filterEtax" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">HĐĐT</span>
                                @foreach ($C::HOA_DON_DIEN_TU as $ma => $ten)
                                    <div class="form-check">
                                        <input class="form-check-input dh-o-tick" type="checkbox" name="etax"
                                            value="{{ $ma }}" id="dh-hd-{{ $ma }}"
                                            {{ in_array($ma, $daChon('etax'), true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="dh-hd-{{ $ma }}">{{ $ten }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- 4. MÃ ĐƠN — chỗ v2 để "Mã hoá đơn". --}}
                        <div id="filterCode" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.order_code') }}</span>
                                <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                    class="form-control mt-1" autocomplete="off" placeholder="Nhập mã">
                            </div>
                        </div>

                        {{-- 5. KÊNH BÁN — chỗ v2 để "Sử dụng: Tại bàn / Mang về / Online".
                             Shop không có bàn; hai kênh thật là giao hàng và bán tại quầy. --}}
                        <div id="filterChannel" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.channel') }}</span>
                                @foreach ($C::CHANNELS as $ma => $ten)
                                    <div class="form-check">
                                        <input class="form-check-input dh-o-tick" type="checkbox" name="channel"
                                            value="{{ $ma }}" id="dh-kenh-{{ $ma }}"
                                            {{ in_array($ma, $daChon('channel'), true) ? 'checked' : '' }}>
                                        <label class="form-check-label" for="dh-kenh-{{ $ma }}">{{ $ten }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- 6. KHÁCH HÀNG — ô riêng, đúng như v2. Gửi tham số `customer`
                             chứ không dùng chung `keyword` với ô Mã đơn: chung một tham số
                             thì gõ ô sau là ô trước mất tác dụng. --}}
                        <div id="filterCustomer" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.customer') }}</span>
                                <input type="text" name="customer" value="{{ $filters['customer'] }}"
                                    class="form-control mt-1" autocomplete="off" placeholder="Tên hoặc SĐT khách">
                            </div>
                        </div>

                        {{-- 7. PHƯƠNG THỨC THANH TOÁN — v2 bày bốn ô chữ đậm (Tiền mặt /
                             Chuyển khoản / Thẻ / QR). Shop có bảy phương thức nên bày đủ
                             bảy, không gom: gom lại thì tick "Chuyển khoản" mà không biết
                             nó có kéo theo SePay hay không. --}}
                        <div id="filterPaymentMethod" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.payment-method') }}</span>
                                @foreach ($C::PAYMENT_METHODS as $ma => $ten)
                                    <div class="form-check">
                                        <input class="form-check-input dh-o-tick" type="checkbox" name="payment_method"
                                            value="{{ $ma }}" id="dh-ptt-{{ $ma }}"
                                            {{ in_array($ma, $daChon('payment_method'), true) ? 'checked' : '' }}>
                                        <label class="form-check-label fw-bold" for="dh-ptt-{{ $ma }}">{{ $ten }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- 8. TRẠNG THÁI — MỘT dãy, năm ô, đúng bộ và đúng thứ tự của v2.

                             Trước đây chỗ này là hai dãy: tám bước giao hàng và bốn trạng
                             thái tiền. Gộp lại theo v2 vì người đọc sổ hỏi đúng một câu —
                             "đơn này thu tiền chưa" — còn đơn đang đi tới đâu thì mở hộp
                             chi tiết ra xem, ở đó vẫn in đủ sáu bước. --}}
                        <div id="filterStatus" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.status') }}</span>
                                @foreach ($C::TRANG_THAI_SO as $ma => $ten)
                                    <div class="form-check">
                                        <input class="form-check-input dh-o-tick" type="checkbox" name="status"
                                            value="{{ $ma }}" id="dh-tt-{{ $ma }}"
                                            {{ in_array($ma, $daChon('status'), true) ? 'checked' : '' }}>
                                        <label class="form-check-label fw-bold {{ $mauTrangThai[$ma] ?? '' }}"
                                            for="dh-tt-{{ $ma }}">{{ $ten }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- 9. NGƯỜI TẠO — đọc orders.created_by (migration 0065). Đơn lập
                             trước migration ấy không có người tạo nên không lọt vào bất kỳ
                             lựa chọn nào; bỏ trống ô thì vẫn thấy đủ. --}}
                        <div id="filterCreator" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.creator') }}</span>
                                <select class="form-control form-select mt-1" name="created_by" multiple>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}"
                                            {{ in_array((string) $nv['id'], $daChon('created_by'), true) ? 'selected' : '' }}>
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
                                <th class="text-left show_status {{ $columns['show_status'] ? '' : 'hide' }}">{{ __('message.status') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($orders as $i => $o)
                                @php
                                    $id = (int) ($o['id'] ?? 0);
                                    $laTra = ($o['loai'] ?? 'don') === 'tra-hang';
                                    $tt = (string) ($o['trang_thai'] ?? '');
                                @endphp
                                <tr class="item {{ $laTra ? 'dong-tra-hang' : '' }}" data-id="{{ $id }}"
                                    data-loai="{{ $o['loai'] ?? 'don' }}" data-code="{{ $o['ma'] ?? '' }}">
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    {{-- Mã là CHỮ TRẦN, không phải liên kết: cửa xem chi tiết là
                                         con mắt ở cột Hành động. Để trần thì bôi đen chép lại được. --}}
                                    <td class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">
                                        {{ $o['ma'] ?? '' }}
                                        @if ($laTra)
                                            <span class="dh-kenh dh-tra" title="Phiếu trả hàng">Trả</span>
                                        @elseif (($o['kenh'] ?? 'web') === 'pos')
                                            <span class="dh-kenh" title="Đơn bán tại quầy">Quầy</span>
                                        @endif
                                    </td>
                                    <td class="text-left show_customer {{ $columns['show_customer'] ? '' : 'hide' }}">
                                        <span class="dh-ten">{{ $o['khach_hang'] ?? '' }}</span>
                                        <span class="dh-phu">{{ $o['so_dien_thoai'] ?? '' }}</span>
                                    </td>
                                    <td class="la-so text-center show_time {{ $columns['show_time'] ? '' : 'hide' }}"
                                        title="{{ $gioNgay($o['created_at'] ?? '') }}">{{ $ngayVN($o['created_at'] ?? '') }}</td>
                                    <td class="la-so text-right show_discount {{ $columns['show_discount'] ? '' : 'hide' }}">{{ $tien($o['giam_gia'] ?? 0) }}</td>
                                    <td class="la-so text-right show_shipping_fee {{ $columns['show_shipping_fee'] ? '' : 'hide' }}">{{ $tien($o['phi_giao'] ?? 0) }}</td>
                                    {{-- Ba cột tiền do API chia sẵn theo phương thức của từng
                                         lượt thu — bảng chỉ in ra. --}}
                                    <td class="la-so text-right show_cash {{ $columns['show_cash'] ? '' : 'hide' }}">{{ $tien($o['tien_mat'] ?? 0) }}</td>
                                    <td class="la-so text-right show_transfer {{ $columns['show_transfer'] ? '' : 'hide' }}">{{ $tien($o['chuyen_khoan'] ?? 0) }}</td>
                                    <td class="la-so text-right show_online {{ $columns['show_online'] ? '' : 'hide' }}">{{ $tien($o['the_vi'] ?? 0) }}</td>
                                    {{-- "Có" như `have_debt` của v2: đơn đã thu MỘT PHẦN và còn
                                         thiếu. Đơn chưa thu đồng nào (COD đang giao) không phải nợ. --}}
                                    <td class="la-so text-center show_debt {{ $columns['show_debt'] ? '' : 'hide' }}">
                                        <span class="text-danger">{{ ! empty($o['co_cong_no']) ? 'Có' : '-' }}</span>
                                    </td>
                                    <td class="la-so text-right show_total {{ $columns['show_total'] ? '' : 'hide' }}">{{ $tien($o['tong_tien'] ?? 0) }}</td>
                                    <td class="text-left show_status {{ $columns['show_status'] ? '' : 'hide' }}">
                                        <b class="{{ $mauTrangThai[$tt] ?? '' }}">{{ $C::TRANG_THAI_SO[$tt] ?? '' }}</b>
                                    </td>
                                    {{-- MỘT nút duy nhất, đúng như v2: con mắt xem chi tiết.

                                         Dòng PHIẾU TRẢ mở hộp của phiếu trả, bằng class RIÊNG:
                                         hộp đơn hàng đọc `/orders/{id}/detail`, mà id phiếu trả là
                                         id của bảng khác — đi chung đường là mở nhầm một đơn tình cờ
                                         trùng số. --}}
                                    <td class="text-center action not-export">
                                        @if ($laTra)
                                            <a class="detail-phieu-tra" type="button" title="{{ __('message.view-detail') }}"><i class="fa fa-eye"></i></a>
                                        @else
                                            <a class="detail-item" type="button" title="{{ __('message.view-detail') }}"><i class="fa fa-eye"></i></a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="text-center py-4">
                                        {{ $coLoc
                                            ? 'Không có đơn hàng nào khớp bộ lọc đang bật.'
                                            : 'Chưa có đơn hàng nào. Đơn sẽ hiện ở đây khi khách đặt trên website hoặc khi thu ngân bán tại quầy.' }}
                                    </td>
                                </tr>
                            @endforelse

                            {{-- HAI HÀNG TỔNG, đúng như v2: trang đang xem và toàn bộ dòng khớp
                                 bộ lọc. Hàng đầu cộng ngay tại đây; hàng sau API cộng sẵn
                                 (`meta.tong`) vì nó trải qua mọi trang.

                                 Mỗi ô mang đúng class show_* của cột mình, nên ẩn cột nào thì ô
                                 tổng của cột ấy ẩn theo — không dùng colspan, colspan lệch ngay
                                 khi có một cột bị tắt. --}}
                            @if (count($orders))
                                @php
                                    $tongAll = $meta['tong'] ?? [];
                                    $hangTong = [
                                        'Tổng trang này' => collect($orders),
                                        'Tổng tất cả' => null,
                                    ];
                                    $cong = fn ($ds, $k) => $ds ? $ds->sum(fn ($o) => (float) ($o[$k] ?? 0)) : (float) ($tongAll[$k] ?? 0);
                                @endphp
                                @foreach ($hangTong as $nhan => $ds)
                                    <tr class="dong-tong cus-bg-EFEFEF cus-fw-bold">
                                        <td></td>
                                        <td class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ $nhan }}</td>
                                        <td class="show_customer {{ $columns['show_customer'] ? '' : 'hide' }}"></td>
                                        <td class="show_time {{ $columns['show_time'] ? '' : 'hide' }}"></td>
                                        <td class="la-so text-right show_discount {{ $columns['show_discount'] ? '' : 'hide' }}">{{ $tien($cong($ds, 'giam_gia')) }}</td>
                                        <td class="la-so text-right show_shipping_fee {{ $columns['show_shipping_fee'] ? '' : 'hide' }}">{{ $tien($cong($ds, 'phi_giao')) }}</td>
                                        <td class="la-so text-right show_cash {{ $columns['show_cash'] ? '' : 'hide' }}">{{ $tien($cong($ds, 'tien_mat')) }}</td>
                                        <td class="la-so text-right show_transfer {{ $columns['show_transfer'] ? '' : 'hide' }}">{{ $tien($cong($ds, 'chuyen_khoan')) }}</td>
                                        <td class="la-so text-right show_online {{ $columns['show_online'] ? '' : 'hide' }}">{{ $tien($cong($ds, 'the_vi')) }}</td>
                                        <td class="show_debt {{ $columns['show_debt'] ? '' : 'hide' }}"></td>
                                        <td class="la-so text-right show_total {{ $columns['show_total'] ? '' : 'hide' }}">{{ $tien($cong($ds, 'tong_tien')) }}</td>
                                        <td class="show_status {{ $columns['show_status'] ? '' : 'hide' }}"></td>
                                        <td class="not-export"></td>
                                    </tr>
                                @endforeach
                            @endif
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
                                @php
                                    $tt = (string) ($o['trang_thai'] ?? '');
                                    $laTra = ($o['loai'] ?? 'don') === 'tra-hang';
                                @endphp
                                <div class="item {{ $laTra ? 'dong-tra-hang' : '' }}"
                                    data-id="{{ (int) ($o['id'] ?? 0) }}" data-loai="{{ $o['loai'] ?? 'don' }}">
                                    <div class="d-flex flex-column" style="flex: 1">
                                        <span class="fw-semibold">{{ $o['ma'] ?? '' }}</span>
                                        <small class="{{ $mauTrangThai[$tt] ?? '' }}">
                                            {{ $C::TRANG_THAI_SO[$tt] ?? '' }} · {{ $o['khach_hang'] ?? '' }}
                                        </small>
                                    </div>
                                    <div class="d-flex justify-content-end text-right gap-2" style="min-width: 110px">
                                        <b>{{ $tien($o['tong_tien'] ?? 0) }}</b>
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
         Chép khuôn manager-order/modal-view.blade.php của v2, từng khối một:

           header  : "Đơn hàng - <mã>" (mã tô cam)
           hàng đầu: trái là người mua, phải là mốc thời gian
           thân    : bảng hàng bên TRÁI (col-xl-8) + khối "Thông tin thanh toán"
                     bên PHẢI (col-xl-4, class .inftt) — đúng tỉ lệ của v2
           chân    : nút phát hành hoá đơn

         Ba chỗ v2 có mà shop không có dữ liệu để điền, nên đổi cho đúng thứ
         mình có (API trả về đơn hàng, không trả tên chi nhánh lẫn người tạo):
           - "- <chi nhánh>" sau mã đơn      → bỏ
           - "Số khách: N"                   → tên khách - số điện thoại
           - "Người tạo : X"                 → trạng thái đơn
         Và "Phụ thu" của quán ăn đổi thành "Phí giao hàng" của shop. --}}
    <div class="modal" id="modalOrderDetail" data-id="" style="padding-inline: 0 !important">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable mx-auto">
            <div class="modal-content">
                <div class="modal-header border-bottom d-flex justify-content-between align-items-center">
                    <h4 class="modal-title fs-6">
                        Đơn hàng - <span class="text-orange" id="dh-title-code"></span>
                    </h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>

                <div class="modal-body">
                    <div class="row px-2">
                        <div class="col-12 col-xl-8 d-flex mt-3 mt-md-0">
                            <div>
                                <div class="d-flex bg-blue-dark">
                                    <i class="fa fa-user my-auto" aria-hidden="true"
                                        style="color: #599FBD; font-size: 20px;"></i>
                                    <span class="my-auto mx-1"><strong id="dh-v-name"></strong></span>
                                </div>
                            </div>
                            <div class="mx-2" id="dh-v-addr-wrap">
                                <div class="bg-blue-dark" id="dh-v-addr"></div>
                            </div>
                        </div>
                        <div class="col-12 col-xl-4 d-flex justify-content-md-end justify-content-sm-start">
                            <div class="mx-md-2 mx-0">
                                <div class="bg-blue-dark" style="background: transparent">
                                    <div>Người tạo : <span id="dh-v-creator"></span></div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-center">
                                <div class="bg-blue-dark text-center" id="dh-v-created"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row p-2">
                        <div class="col-12 col-md-12 col-lg-7 col-xl-8" style="overflow: auto;">
                            <table class="bang-hang">
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
                                <tbody class="cus-bg-EFEFEF cus-fw-bold">
                                    <tr>
                                        <td class="text-end" colspan="4"><strong>Tổng tiền hàng</strong></td>
                                        <td class="text-right" id="dh-v-goods"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="col-12 col-md-12 col-lg-5 col-xl-4 inftt mt-3 mt-lg-0">
                            <div class="border-bottom-dotted bg-E7EBEE p-2 mb-2">
                                <strong>Thông tin thanh toán</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Tổng tiền hàng</span><span id="dh-v-subtotal"></span>
                            </div>
                            <div class="d-flex bg-E7EBEE justify-content-between">
                                <span>Phí giao hàng</span><span id="dh-v-ship"></span>
                            </div>
                            <div class="bg-E7EBEE mt-1 d-flex justify-content-between">
                                <span>Khuyến mãi</span><span id="dh-v-discount"></span>
                            </div>
                            <div class="mt-2" id="dh-v-voucher-wrap">
                                <span>Voucher/Coupon</span>
                                <div class="ps-2 d-flex justify-content-between">
                                    <span class="text-secondary" id="dh-v-voucher"></span>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Tổng sau giảm</span><span id="dh-v-after"></span>
                            </div>
                            <div class="d-flex justify-content-between text-red">
                                <strong>Tổng thanh toán</strong><strong id="dh-v-total"></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span id="dh-v-method"></span><span id="dh-v-paid"></span>
                            </div>
                            {{-- Các lần đã thu, cũ trước mới sau. Chỉ hiện khi đơn thu
                                 làm nhiều lần — đơn thu một lần thì dòng "phương thức |
                                 số tiền" ngay trên đã nói đủ. --}}
                            <div id="dh-v-luotthu-wrap" class="mt-1">
                                <span class="dh-nhan-nho">Các lần đã thu</span>
                                <div id="dh-v-luotthu"></div>
                            </div>
                            <div class="d-flex justify-content-between" id="dh-v-debt-wrap">
                                <span>Còn phải thu</span><span class="text-danger" id="dh-v-debt"></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Trạng thái đơn</span><span id="dh-v-status"></span>
                            </div>
                            <div class="d-flex justify-content-between" id="dh-v-note-wrap">
                                <span>{{ __('message.note') }}</span><span id="dh-v-note"></span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Chân hộp có ĐÚNG MỘT nút, và nó chỉ hiện khi đơn CÒN NỢ.

                     Hộp này vốn chỉ để đọc, nhưng ghi một lượt thu tiền thì phải bấm
                     ở đâu đó — và đây là chỗ duy nhất người dùng đang nhìn thấy số
                     "Còn phải thu". Đưa ra cột Hành động thì cột ấy hết là "chỉ xem
                     chi tiết", mà nút thu tiền đứng cạnh con mắt cũng dễ bấm nhầm.

                     Hoá đơn điện tử vẫn tách ra làm riêng sau, nên nút phát hành của
                     v2 không dựng ở đây. --}}
                <div class="modal-footer justify-content-center" id="dh-v-chan" hidden>
                    <button type="button" class="bt btn_green" id="dh-v-thu">Thu tiền</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Thu tiền =====================
         Một hộp nhỏ, ba ô. Số tiền điền sẵn ĐÚNG BẰNG phần còn nợ vì đó là con số
         người dùng gõ vào chín trên mười lần; sửa lại được khi khách trả góp.

         KHÔNG tự chặn "thu quá phần còn nợ" ở đây: chỉ API mới biết đơn đã thu tới
         đâu, và hai nơi cùng giữ một luật là hai nơi sẽ lệch. Gõ quá thì API trả
         422 kèm số còn nợ thật, hộp ở lại và hiện nguyên câu ấy. --}}
    <div class="modal" id="modalThuTien" data-id="">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Thu tiền đơn <span class="text-orange" id="tt-ma"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="dh-nhan-nho">Còn phải thu</span>
                        <strong class="text-danger" id="tt-con-no"></strong>
                    </div>
                    <label class="form-label mb-1">Số tiền thu <span class="text-danger">*</span></label>
                    <input type="text" class="form-control mb-2" id="tt-so-tien" inputmode="numeric" autocomplete="off">
                    <label class="form-label mb-1">{{ __('message.payment-method-short') }}</label>
                    <select class="form-control form-select mb-2" id="tt-phuong-thuc">
                        @foreach ($C::PAYMENT_METHODS as $ma => $ten)
                            <option value="{{ $ma }}">{{ $ten }}</option>
                        @endforeach
                    </select>
                    <label class="form-label mb-1">{{ __('message.note') }}</label>
                    <textarea class="form-control" id="tt-ghi-chu" rows="2" maxlength="255"></textarea>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green" id="tt-luu">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp xem PHIẾU TRẢ =====================
         Dòng "Trả hàng" của v2 mở được chi tiết ngay tại màn Quản lý đơn hàng
         (getData, type = return-order): bảng hàng trả bên trái, khối hoàn tiền
         bên phải. Chép đúng khuôn hộp đơn hàng ở trên để hai hộp đọc như một.

         Chỉ để XEM: duyệt / nhận hàng / hoàn tiền vẫn làm ở màn Trả hàng, nơi có
         đủ luồng chuyển trạng thái của phiếu. --}}
    <div class="modal" id="modalPhieuTra" style="padding-inline: 0 !important">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable mx-auto">
            <div class="modal-content">
                <div class="modal-header border-bottom d-flex justify-content-between align-items-center">
                    <h4 class="modal-title fs-6">
                        Phiếu trả hàng - <span class="text-orange" id="pt-ma"></span>
                    </h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>

                <div class="modal-body">
                    <div class="row px-2">
                        <div class="col-12 col-xl-8 d-flex mt-3 mt-md-0">
                            <div class="d-flex bg-blue-dark">
                                <i class="fa fa-user my-auto" aria-hidden="true" style="color: #599FBD; font-size: 20px;"></i>
                                <span class="my-auto mx-1"><strong id="pt-khach"></strong></span>
                            </div>
                        </div>
                        <div class="col-12 col-xl-4 d-flex justify-content-md-end justify-content-sm-start">
                            <div class="bg-blue-dark text-center" id="pt-luc"></div>
                        </div>
                    </div>

                    <div class="row p-2">
                        <div class="col-12 col-md-12 col-lg-7 col-xl-8" style="overflow: auto;">
                            <table class="bang-hang">
                                <thead>
                                    <tr>
                                        <th class="text-center">{{ __('message.stt') }}</th>
                                        <th class="text-left">Hàng hoá</th>
                                        <th class="text-right">Đơn giá</th>
                                        <th class="text-right">SL trả</th>
                                        <th class="text-right">Thành tiền</th>
                                    </tr>
                                </thead>
                                <tbody id="pt-hang"></tbody>
                            </table>
                        </div>

                        <div class="col-12 col-md-12 col-lg-5 col-xl-4 inftt mt-3 mt-lg-0">
                            <div class="border-bottom-dotted bg-E7EBEE p-2 mb-2">
                                <strong>Thông tin hoàn tiền</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Đơn gốc</span><span id="pt-don-goc"></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Tiền hàng trả</span><span id="pt-tien-hang"></span>
                            </div>
                            <div class="d-flex bg-E7EBEE justify-content-between">
                                <span>Phí giao hoàn</span><span id="pt-phi"></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Khấu trừ</span><span id="pt-khau-tru"></span>
                            </div>
                            <div class="d-flex justify-content-between text-red">
                                <strong>Tổng hoàn</strong><strong id="pt-tong"></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Phương thức hoàn</span><span id="pt-phuong-thuc"></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Trạng thái phiếu</span><span id="pt-trang-thai"></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Lý do trả</span><span class="text-end" id="pt-ly-do"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

@push('scripts')
    <script>
        const URL_DH = @json(url('/admin/orders'));
        const CHU_SO = (o) => String(o == null ? '' : o).replace(/[^0-9]/g, '');

        const DH_STATUSES = @json(\App\Http\Controllers\OrderController::STATUSES);
        const DH_TONES = @json(\App\Http\Controllers\OrderController::STATUS_TONES);
        const DH_PAY_METHODS = @json(\App\Http\Controllers\OrderController::PAYMENT_METHODS);
        const DH_MAU_TONE = { wait: 'text-warning', info: 'text-primary', move: 'text-info', done: 'text-success', stop: 'text-danger' };
        const URL_PT = @json(url('/admin/returns'));
        const PT_STATUSES = @json(\App\Http\Controllers\ReturnController::STATUSES);
        const PT_TONES = @json(\App\Http\Controllers\ReturnController::STATUS_TONES);
        const PT_REASONS = @json(\App\Http\Controllers\ReturnController::REASONS);
        const PT_REFUND_METHODS = @json(\App\Http\Controllers\ReturnController::REFUND_METHODS);

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

            // Hai ô gõ tay: mã đơn và khách hàng, mỗi ô một tham số riêng.
            ['keyword', 'customer'].forEach(function (ten) {
                const v = String(oLoc(ten).val() || '').trim();
                if (v) q.set(ten, v);
            });

            // Bốn dãy CHECKBOX: gộp những ô đang tick thành chuỗi ngăn bởi dấu
            // phẩy — đúng cái API đọc (locNhieu → cột IN (...)).
            //
            // Không tick ô nào = KHÔNG lọc, tức xem tất cả. Đó là điểm khác v2:
            // bên đó bỏ tick sạch thì bảng rỗng, mà "bảng rỗng vì bạn vừa bỏ hết
            // tick" là câu không màn nào nói ra được.
            ['status', 'payment_method', 'channel', 'etax'].forEach(function (ten) {
                const chon = $('.fillter-box [name="' + ten + '"]:checked')
                    .map(function () { return this.value; }).get();
                if (chon.length) q.set(ten, chon.join(','));
            });

            // Người tạo là ô thả xuống chọn nhiều, không phải checkbox.
            const nguoiTao = [].concat(oLoc('created_by').val() || []);
            if (nguoiTao.length) q.set('created_by', nguoiTao.join(','));

            // Hai ô ngày LUÔN gửi, kể cả khi trống: bỏ trống là "không giới hạn",
            // không gửi thì mất nghĩa đó ở lượt lọc sau.
            ['from_date', 'to_date'].forEach(function (ten) {
                q.set(ten, String(oLoc(ten).val() || '').trim());
            });

            // Tham số không có ô trong khung lọc thì chép lại từ URL cũ, không thì
            // đổi bộ lọc một cái là mất luôn cột đang ẩn và cỡ trang.
            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size', 'sort'].forEach(function (ten) {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            // Cố ý không mang `page`: lọc lại thì trang 5 của bộ lọc cũ hết nghĩa.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerLoc = null;
        $(document).on('input', '.fillter-box [name="keyword"], .fillter-box [name="customer"]', function () {
            clearTimeout(timerLoc);
            timerLoc = setTimeout(locLai, 300);
        });
        $(document).on('change',
            '.fillter-box .dh-o-tick, .fillter-box [name="created_by"], '
            + '.fillter-box [name="from_date"], .fillter-box [name="to_date"]',
            locLai);

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

        /** Vẽ các dòng hàng, trả về tổng tiền hàng cho hàng tổng nằm ở tbody riêng
         *  bên dưới — đúng cách v2 tách hai tbody. */
        function veHangHoa(items) {
            const ds = items || [];
            if (!ds.length) {
                $('#dh-v-items').html('<tr><td colspan="5" class="text-center py-3">Đơn không có dòng hàng nào.</td></tr>');

                return 0;
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

            $('#dh-v-items').html(dong);

            return tongHang;
        }

        /** Các lần đã thu. Đơn thu MỘT lần thì không bày: dòng "phương thức | số
         *  tiền" ngay trên đã nói đủ, thêm một danh sách một dòng chỉ tổ rườm. */
        function veLuotThu(ds) {
            const list = ds || [];
            $('#dh-v-luotthu-wrap').toggle(list.length > 1);
            if (list.length < 2) return;

            $('#dh-v-luotthu').html(list.map(function (p) {
                const luc = p.paid_at ? moment(p.paid_at).format('DD-MM-YYYY') : '';
                const ten = DH_PAY_METHODS[p.payment_method] || p.payment_method || '';

                return '<div class="ps-2 d-flex justify-content-between">'
                    + '<span class="text-secondary">' + thoat(luc + (ten ? ' · ' + ten : '')) + '</span>'
                    + '<span class="text-secondary">' + tienVN(p.amount) + 'đ</span>'
                    + '</div>';
            }).join(''));
        }

        /** Bày/giấu một dòng của khối thanh toán theo việc nó có giá trị hay không. */
        function dongCoDieuKien(idBoc, idChu, giaTri) {
            $(idBoc).toggle(Boolean(giaTri));
            $(idChu).text(giaTri || '');
        }

        function veHopChiTiet(o) {
            // Hộp Thu tiền mở ra SAU khi hộp này đóng, nên nó không còn đọc được
            // `o` nữa — gửi kèm hai con số nó cần qua data-*.
            $('#modalOrderDetail').attr('data-id', o.id)
                .data('con-no', Number(o.con_no || 0))
                .data('phuong-thuc', o.payment_method || 'cash');
            $('#dh-title-code').text(o.order_code || '');

            // Chỗ v2 in "Số khách: N" — shop không đếm khách, in thẳng người mua.
            $('#dh-v-name').text([o.recipient_name, o.recipient_phone].filter(Boolean).join(' - '));

            const diaChi = [o.shipping_address, o.shipping_ward, o.shipping_district, o.shipping_province]
                .filter(Boolean).join(', ');
            // Đơn quầy không có địa chỉ giao — giấu hẳn ô thay vì in một gạch ngang.
            $('#dh-v-addr-wrap').toggle(Boolean(diaChi));
            $('#dh-v-addr').text(diaChi);

            // Người tạo: `orders.created_by` (migration 0065). Đơn lập trước đó không
            // có người tạo — in "—", đừng đoán.
            $('#dh-v-creator').text(o.created_by_name || '—');
            $('#dh-v-created').text(o.created_at ? moment(o.created_at).format('DD-MM-YYYY HH:mm') : '');

            $('#dh-v-status').html('<b class="' + (DH_MAU_TONE[DH_TONES[o.status]] || '') + '">'
                + thoat(DH_STATUSES[o.status] || o.status || '') + '</b>');

            const tienHang = veHangHoa(o.items);
            $('#dh-v-goods').text(tienVN(tienHang) + 'đ');

            const giam = Number(o.discount_amount || 0);
            $('#dh-v-subtotal').text(tienVN(o.subtotal_amount) + 'đ');
            $('#dh-v-ship').text(tienVN(o.shipping_fee) + 'đ');
            $('#dh-v-discount').text(tienVN(giam) + 'đ');
            $('#dh-v-after').text(tienVN(Number(o.subtotal_amount || 0) - giam) + 'đ');
            $('#dh-v-total').text(tienVN(o.total_amount) + 'đ');

            // v2 in "tên phương thức | số tiền đã trả theo phương thức đó". Đơn của
            // shop chỉ mang MỘT phương thức, nên chưa thu thì số ấy là 0.
            $('#dh-v-method').text(DH_PAY_METHODS[o.payment_method] || o.payment_method || '—');
            $('#dh-v-paid').text(tienVN(o.da_thu) + 'đ');

            // Chỗ v2 để "Hạn nợ / Ghi chú" khi đơn còn nợ. Hai con số dưới đây do
            // API tính sẵn theo sổ thu tiền (migration 0066) — đừng suy lại từ
            // `payment_status` như trước, đơn thu một phần sẽ ra sai.
            const conNo = Number(o.con_no || 0);
            $('#dh-v-debt-wrap').toggle(conNo > 0);
            $('#dh-v-debt').text(tienVN(o.con_no) + 'đ');

            veLuotThu(o.luot_thu);

            // Nút Thu tiền chỉ có nghĩa khi đơn còn nợ. Bày nó trên một đơn đã thu
            // đủ thì bấm vào chỉ nhận lỗi từ API — bẫy người dùng.
            $('#dh-v-chan').prop('hidden', conNo <= 0);
            $('#dh-v-thu').prop('disabled', false);

            dongCoDieuKien('#dh-v-voucher-wrap', '#dh-v-voucher', o.voucher_code);
            dongCoDieuKien('#dh-v-note-wrap', '#dh-v-note', o.note);

            $('#modalOrderDetail').modal('show');
        }

        // ---------- Thu tiền ----------
        $(document).on('click', '#dh-v-thu', function () {
            const $chiTiet = $('#modalOrderDetail');
            const conNo = Number($chiTiet.data('con-no') || 0);

            $('#modalThuTien').attr('data-id', $chiTiet.attr('data-id'));
            $('#tt-ma').text($('#dh-title-code').text());
            $('#tt-con-no').text(tienVN(conNo) + 'đ');
            // Điền sẵn ĐÚNG phần còn nợ: chín trên mười lần khách trả nốt, và sửa
            // lại được khi họ trả góp.
            $('#tt-so-tien').val(Number(conNo).toLocaleString('vi-VN'));
            $('#tt-phuong-thuc').val($chiTiet.data('phuong-thuc') || 'cash');
            $('#tt-ghi-chu').val('');
            $('#tt-luu').prop('disabled', false);

            $chiTiet.modal('hide');
            $('#modalThuTien').modal('show');
        });

        $(document).on('input', '#tt-so-tien', function () {
            const raw = CHU_SO(this.value).slice(0, 12);
            this.value = raw ? Number(raw).toLocaleString('vi-VN') : '';
        });

        $(document).on('click', '#tt-luu', function () {
            const id = $('#modalThuTien').attr('data-id');
            const soTien = Number(CHU_SO($('#tt-so-tien').val()));
            if (!id || !soTien) {
                toastr.error('Nhập số tiền đã thu.');

                return;
            }

            // luuHop giữ hộp lại khi API từ chối (thu quá phần còn nợ, đơn đã thu
            // đủ) và in nguyên câu của API — số vừa gõ không mất.
            V2.luuHop('#modalThuTien', URL_DH + '/' + id + '/payments', 'POST', {
                amount: soTien,
                payment_method: $('#tt-phuong-thuc').val() || '',
                note: $('#tt-ghi-chu').val() || '',
            }, $(this));
        });

        $(document).on('click', '.detail-item', function () {
            const id = $(this).data('id') || $(this).closest('.item').data('id');
            if (!id) return;

            fetch(URL_DH + '/' + id + '/detail', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);

                    return r.json();
                })
                .then(function (d) { veHopChiTiet(d.data || {}); })
                .catch(function () { toastr.error('Không tải được chi tiết đơn hàng.'); });
        });

        // ================= Hộp xem phiếu trả =================
        function veHopPhieuTra(p) {
            const don = p.order || {};
            $('#pt-ma').text(p.return_code || '');
            $('#pt-khach').text([don.recipient_name, don.recipient_phone].filter(Boolean).join(' - '));
            $('#pt-luc').text(p.created_at ? moment(p.created_at).format('DD-MM-YYYY HH:mm') : '');

            const hang = p.items || [];
            $('#pt-hang').html(hang.length ? hang.map(function (d, i) {
                const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');

                return '<tr>'
                    + '<td class="text-center">' + (i + 1) + '</td>'
                    + '<td class="text-left">' + thoat(ten)
                    + (d.variant_sku ? '<div class="dh-nhan-nho">' + thoat(d.variant_sku) + '</div>' : '')
                    + '</td>'
                    + '<td class="text-right">' + tienVN(d.unit_price) + 'đ</td>'
                    + '<td class="text-right">' + tienVN(d.quantity) + '</td>'
                    + '<td class="text-right">' + tienVN(d.total_price) + 'đ</td>'
                    + '</tr>';
            }).join('') : '<tr><td colspan="5" class="text-center py-3">Phiếu không có dòng hàng nào.</td></tr>');

            $('#pt-don-goc').text(don.order_code || '');
            $('#pt-tien-hang').text(tienVN(p.items_amount) + 'đ');
            $('#pt-phi').text(tienVN(p.shipping_fee) + 'đ');
            $('#pt-khau-tru').text(tienVN(p.deduction) + 'đ');
            $('#pt-tong').text(tienVN(p.refund_amount) + 'đ');
            $('#pt-phuong-thuc').text(PT_REFUND_METHODS[p.refund_method] || p.refund_method || '—');
            $('#pt-trang-thai').html('<b class="' + (DH_MAU_TONE[PT_TONES[p.status]] || '') + '">'
                + thoat(PT_STATUSES[p.status] || p.status || '') + '</b>');
            $('#pt-ly-do').text([PT_REASONS[p.reason] || p.reason, p.reason_note].filter(Boolean).join(' — ') || '—');

            $('#modalPhieuTra').modal('show');
        }

        $(document).on('click', '.detail-phieu-tra', function () {
            const id = $(this).closest('.item').data('id');
            if (!id) return;

            fetch(URL_PT + '/' + id + '/detail', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);

                    return r.json();
                })
                .then(function (d) { veHopPhieuTra(d.data || {}); })
                .catch(function () { toastr.error('Không tải được chi tiết phiếu trả hàng.'); });
        });
    </script>
@endpush
