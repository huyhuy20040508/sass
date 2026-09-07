{{-- Màn Công nợ — chép khuôn từ bản v2 cũ
     (ordertable/v2/resources/views/cashbook/debt/index.blade.php + list + edit).

     Bám đúng dáng v2, từng khối một:
       · khung lọc trái, đúng thứ tự ô của bên đó;
       · hàng tiêu đề: tên màn bên trái, BỐN NÚT ĐẾM SỐ rồi nút chọn cột bên phải
         — cả cụm nằm CÙNG MỘT HÀNG như v2, không tách ra hàng riêng;
       · bảng chọn được cột, thứ tự cột y v2 (kể cả ba cột tiền xếp
         Chưa thanh toán → Đã thanh toán → Tổng tiền);
       · hộp "Chi tiết công nợ" hai tầng: khối thông tin khoá cứng ở trên, bảng
         chi tiết thanh toán ở dưới, nút Thanh toán ở chân;
       · hộp "Chi tiết thanh toán" để ghi một lượt trả;
       · bản thẻ cho điện thoại + tấm trượt chi tiết.

     TÁM CHỖ CỐ Ý KHÁC V2 — xem thêm đầu CongNoController:
     - Bỏ khối lọc "Đối tượng" và cột "Đối tượng": bên này chưa có bảng đơn bán
       nên chưa có nợ khách, mọi dòng đều là nhà cung cấp.
     - Cột "Trạng thái" GỘP vào "Hạn còn" thành một ô hai dòng: trạng thái ở
       trên (tô màu), số ngày ở dưới. v2 để ba cột cùng nói về một chuyện, mà
       cột thứ ba lại suy thẳng ra được từ hai cột tiền ngay bên trái.
     - Ô lọc trạng thái còn HAI mục: trả đủ là khoản nợ rời sổ, nên mục "Đã thanh
       toán" sẽ không bao giờ trả về dòng nào (xem TRANG_THAI_LOC).
     - Cột Trạng thái của v2 kiểm nhầm `$columns['show_paid']` thay vì
       `show_status`, nên tắt cột "Đã thanh toán" là cột "Trạng thái" biến mất
       theo, còn tắt "Trạng thái" thì không có tác dụng gì.
     - Bốn con số trên hàng nút theo mọi bộ lọc đang bật; v2 clone câu truy vấn
       TRƯỚC cả bộ lọc trạng thái nên đổi ô trạng thái xong bốn con số đứng im.
     - "Hạn còn" do máy chủ đếm (`days_left`); v2 để blade tự diffInDays theo giờ
       máy người dùng, hai người hai múi giờ đọc ra hai con số khác nhau.
     - Số tiền trả bị chặn quá số nợ ở CẢ máy chủ; v2 chỉ chặn bằng JS trong ô.
     - Hàng nút chân hộp canh giữa, nút bỏ đi màu đỏ / nút đồng ý màu xanh —
       quy chuẩn của hệ thống này; v2 để nút Lưu màu đỏ và dạt phải. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\CongNoController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\CongNoController::class;

    // Cột đang tắt nằm ở ?hide= chứ không phải trong CSDL như v2 — giữ được sau
    // khi đổi trang mà không cần bảng user_selected_columns.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $columns = [];
    foreach (array_keys($C::COT_BANG) as $c) {
        $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }

    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '';

    // Bộ lọc đang bật — để câu "bảng rỗng" nói đúng lý do.
    $coLoc = collect($filters)
        ->only(['supplier_name', 'code', 'supplier_id', 'created_by', 'status'])
        ->contains(fn ($v) => $v !== '' && $v !== null) || $filters['due'] !== 'all';

    $chonNhieu = fn (string $khoa) => array_filter(explode(',', (string) $filters[$khoa]));
    $nhaCungCapChon = $chonNhieu('supplier_id');
    $nguoiTaoChon = $chonNhieu('created_by');
    // Ô trạng thái: KHÔNG lọc gì = tick hết, đúng như trang mở lần đầu.
    // Hai mục chứ không ba — xem CongNoController::TRANG_THAI_LOC.
    $trangThaiChon = $chonNhieu('status') ?: array_keys($C::TRANG_THAI_LOC);

    // Cùng nguồn với dropdown ba gạch trên thanh đầu trang, để hai chỗ không bao
    // giờ bày hai danh sách khác nhau. Trả về ['ds' => [...], 'dangChon' => int].
    $chiNhanh = \App\Services\ChiNhanhDangLam::danhSach();

    // Bốn nút đếm — nhãn lấy từ MOC_HAN để thứ tự và câu chữ chỉ khai một chỗ.
    $demTheoMoc = [
        'all' => $dem['count_all'],
        'near' => $dem['count_near'],
        'over' => $dem['count_over'],
        'today' => $dem['count_today'],
    ];
@endphp

@push('styles')
    <style>
        /* Bảng ép vừa khung: bề rộng do hàng tiêu đề quyết, không do nội dung ô.
           Mọi cột đặt %, tổng đúng 100 — bỏ trống một cột là bảng hở khoảng chết. */
        /* SÀN BỀ RỘNG.
           Chỉ `width: 100%` thì khung hẹp lại là mọi cột co theo, và tiêu đề dài
           nhất ("Chưa thanh toán") không còn chỗ. `.table-responsive` bọc ngoài
           đã có `overflow-x: auto`, nên đặt sàn ở đây là dưới sàn thì bảng TRƯỢT
           NGANG — người dùng kéo một cái là đọc được, còn hơn chữ bị bóp.

           960 = tổng bề rộng nhỏ nhất để cả 11 tiêu đề nằm gọn một dòng, đo thật
           theo hàng tiêu đề chung của v2 (13px, đệm dọc 6px — xem v2::layouts.master).
           Con số cũ là 1320, tính theo cỡ chữ 14px trước kia, nên ở màn 1536
           (khung 1197px) bảng trượt ngang và cột Hành động rơi ra ngoài. */
        table.table-cong-no.none_mobile { width: 100%; min-width: 960px; table-layout: fixed; }

        /* CHỈ ô DỮ LIỆU mới ép một dòng. Nội dung ô cắt bớt vẫn đọc được — mã
           phiếu, tên nhà cung cấp đều có `title` để rê chuột xem đủ. */
        table.table-cong-no.none_mobile td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* TIÊU ĐỀ: MỘT DÒNG, KHÔNG CẮT, KHÔNG NGẮT TỪ.
           Tên cột bị cắt cụt thì không có `title` để rê chuột và cũng không đoán
           ra được; còn cho nó xuống dòng thì "Chưa thanh toán" gãy thành "Chưa
           thanh / toán" — đọc vẫn vướng. Nên cách duy nhất đúng là CẤP ĐỦ CHỖ:
           bề rộng từng cột dưới đây tính từ chính độ dài tiêu đề của nó, và cái
           sàn 1280px ở trên bảo đảm chỗ ấy luôn có. */
        table.table-cong-no.none_mobile th {
            white-space: nowrap;
            vertical-align: middle;
        }
        table.table-cong-no.none_mobile th:first-child { width: 4.67%; }
        table.table-cong-no.none_mobile th.show_code { width: 13.7%; }
        table.table-cong-no.none_mobile th.show_supplier { width: 9.77%; }
        table.table-cong-no.none_mobile th.show_branch { width: 7.84%; }
        table.table-cong-no.none_mobile th.show_remaining { width: 11.19%; }
        table.table-cong-no.none_mobile th.show_paid { width: 9.85%; }
        table.table-cong-no.none_mobile th.show_total_amount { width: 8.76%; }
        table.table-cong-no.none_mobile th.show_remaining_term { width: 6.93%; }
        table.table-cong-no.none_mobile th.show_due_date { width: 9.77%; }
        table.table-cong-no.none_mobile th.show_creator { width: 9.26%; }
        table.table-cong-no.none_mobile th:last-child { width: 8.26%; }

        /* Ô "Hạn còn" hai dòng: trạng thái trên, số ngày dưới. Cởi `nowrap` +
           `text-overflow` của luật chung cho riêng ô này — hai dòng mà vẫn ép một
           dòng thì dòng dưới bị nuốt mất. */
        table.table-cong-no.none_mobile td.show_remaining_term {
            white-space: normal;
            line-height: 1.25;
            padding-top: 6px;
            padding-bottom: 6px;
        }
        table.table-cong-no.none_mobile td.show_remaining_term .so-ngay {
            display: block;
            font-size: 12px;
            color: #6c757d;
            font-weight: 400;
        }

        /* BỐN NÚT LỌC THEO HẠN — chép nguyên bộ màu của v2 (nền #E5E9F7, chữ
           #1C3B58, đang chọn nền #6F89BA chữ trắng). Đây là dấu hiệu người dùng
           cũ nhận ra màn này ngay, nên giữ đúng con số màu chứ không "gần giống". */
        .btn-filter {
            border: 0;
            border-radius: 5px;
            height: 32px;
            padding: 0 12px;
            background-color: #e5e9f7;
            color: #1c3b58;
            font-size: 13px;
            font-weight: 450;
            white-space: nowrap;
        }
        .btn-filter:hover { background-color: #ccc; }
        .btn-filter.active { background-color: #6f89ba; color: #fff; }

        /* Hàng tiêu đề: tên màn trái, cụm nút phải — đúng bố cục
           `.content_midd_title > h4 + .d-flex.justify-content-end.gap-3` của v2.
           Vỏ v2 chỉ đặt `div.btn_top_content { display: flex }`, không có gap nên
           thiếu dòng dưới là bốn nút dính liền thành một khối. */
        .btn_top_content { gap: 6px; align-items: center; flex-wrap: wrap; }
        .btn_top_content > * { margin: 0; }
        .cong-no-thanh-dau { gap: 12px; align-items: center; flex-wrap: wrap; }

        /* Nút Xuất Excel ăn `.btn { padding: 5px 10px !important }` còn nút chọn
           cột ăn thêm `.setting-col { border: 2px }` — mỗi nút một chiều cao,
           đứng cạnh bốn nút lọc 32px là thấy so le ngay. Ghim cả về 32px. */
        .cong-no-thanh-dau .btn-export,
        .cong-no-thanh-dau .setting-col {
            height: 32px;
            padding: 0 12px !important;
            border-radius: 6px;
            cursor: pointer;
        }
        /* Nút chọn cột chỉ có mỗi icon — để ô vuông cho cân. `.dropup-content`
           neo vào `.dropup` (thẻ position:relative bọc ngoài) chứ không vào nút,
           nên bóp nút lại không kéo menu đi đâu cả. */
        .cong-no-thanh-dau .setting-col {
            width: 32px;
            padding: 0 !important;
            justify-content: center;
        }

        /* ===================== HỘP THOẠI =====================
           1. BỀ RỘNG CÓ CHẶN TRÊN. v2 đặt `min-width: 95%`, mà min-width THẮNG
              max-width của Bootstrap — màn 27 inch thì hộp phình gần hết bề ngang,
              bảng chi tiết thanh toán có mấy dòng mà kéo dài cả gang tay.
           2. HOẠT ẢNH. style.css của vỏ v2 cho `.modal-content` chạy `animatetop`
              trong khi Bootstrap 5 đang tự trượt `.modal-dialog`; hai hoạt ảnh
              chồng nhau nên hộp nhảy một nhịp và viền ô nhập 1px biến mất hẳn. */
        #modalCrud .modal-dialog,
        #modalDetailItem .modal-dialog {
            min-width: 0;
            width: 94%;
            margin-left: auto;
            margin-right: auto;
        }
        #modalCrud .modal-dialog { max-width: 1100px; }
        #modalDetailItem .modal-dialog { max-width: 640px; }

        #modalCrud .modal-content,
        #modalDetailItem .modal-content { animation: none !important; }

        /* Khối thông tin của v2: ô khoá cứng, nền xám nhạt để phân biệt với ô
           nhập được ở hộp thanh toán. */
        #modalCrud .info-debt .form-control { background-color: #f6f7f9; }
        #modalCrud .info-payment table { width: 100%; }
        #modalCrud .info-payment th { white-space: nowrap; }

        #modalDetailItem .custom-file-wrapper { height: 34px; max-width: none; }

        @media (max-width: 991px) { .dropup .dropbtn { display: none !important; } }
    </style>
@endpush

@section('content')
    {{-- Nút mở từng khối lọc trên điện thoại — đúng các khối của khung trái. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterObjectName',
                'modalLabel' => __('message.object_name'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterObjectCode',
                'modalLabel' => __('message.order_code'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterBranche',
                'modalLabel' => __('message.branch'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterSupplier',
                'modalLabel' => __('message.supplier'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterCreator',
                'modalLabel' => __('message.creator'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterPaymentStatus',
                'modalLabel' => __('message.status'),
            ])
        </div>
    </div>

    <div class="row index-debt-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">
                        {{ __('message.filter') }}
                    </div>
                    <div class="card-body px-2">
                        {{-- Không bọc <form>: JS tự dựng URL rồi gọi V2.napLai. Trên điện
                             thoại vỏ v2 BƯNG từng khối lọc sang tấm offcanvas, mỗi lượt
                             một khối — submit lúc đó sẽ đánh rơi các ô còn lại.

                             KHỐI ĐẦU TIÊN CỦA V2 LÀ "ĐỐI TƯỢNG" (hai ô tick Khách hàng /
                             Nhà cung cấp) — bỏ hẳn. Chưa có bảng đơn bán nên chưa có nợ
                             khách; bỏ tick "Nhà cung cấp" là bảng rỗng mà chẳng có gì nói
                             vì sao. Làm bán hàng rồi thì thêm lại khối này vào đúng đây. --}}

                        {{-- Hai ô tìm RIÊNG, đúng như v2: tên đối tượng và mã. Gộp thành
                             một ô thì gõ tên nhà cung cấp cũng quét cả cột mã, và người
                             dùng cũ mất chỗ quen tay. --}}
                        <div id="filterObjectName" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.object_name') }}</span>
                                <input type="text" name="supplier_name" value="{{ $filters['supplier_name'] }}"
                                    class="form-control mt-1" id="cn-supplier-name" autocomplete="off"
                                    placeholder="{{ __('message.name') }}">
                            </div>
                        </div>

                        <div id="filterObjectCode" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.order_code') }}</span>
                                <input type="text" name="code" value="{{ $filters['code'] }}"
                                    class="form-control mt-1" id="cn-code" autocomplete="off"
                                    placeholder="{{ __('message.code') }}">
                            </div>
                        </div>

                        {{-- CHI NHÁNH.
                             v2 để đây là ô chọn NHIỀU chi nhánh. Bên này nó là chi nhánh
                             ĐANG LÀM VIỆC của tab — cùng thứ mà dropdown ba gạch trên
                             thanh đầu trang đổi. Dựng thêm một tham số `branch_id` riêng
                             là hai chỗ cùng nói một chuyện rồi cãi nhau; mọi lượt gọi API
                             đều lấy chi nhánh từ `chi_nhanh` (middleware ChiNhanhTheoTab). --}}
                        <div id="filterBranche" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.branch') }}</span>
                                <select class="form-control form-select mt-1" id="cn-branch">
                                    @foreach ($chiNhanh['ds'] as $cn)
                                        <option value="{{ $cn['id'] }}"
                                            {{ (int) $chiNhanh['dangChon'] === (int) $cn['id'] ? 'selected' : '' }}>
                                            {{ $cn['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Ô này v2 KHÔNG có, và nó thế chỗ đúng một nửa của khối "Đối
                             tượng" vừa bỏ: bên đó lọc theo LOẠI đối tượng, bên này chỉ có
                             một loại nên lọc thẳng theo TỪNG nhà cung cấp mới có nghĩa. --}}
                        <div id="filterSupplier" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.supplier') }}</span>
                                <select class="form-control form-select mt-1" id="cn-supplier"
                                    name="supplier_id" multiple>
                                    @foreach ($nhaCungCap as $ncc)
                                        <option value="{{ $ncc['id'] }}"
                                            {{ in_array((string) $ncc['id'], $nhaCungCapChon, true) ? 'selected' : '' }}>
                                            {{ $ncc['name'] ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterCreator" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.creator') }}</span>
                                <select class="form-control form-select mt-1" id="cn-created-by"
                                    name="created_by" multiple>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}"
                                            {{ in_array((string) $nv['id'], $nguoiTaoChon, true) ? 'selected' : '' }}>
                                            {{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterPaymentStatus" class="input-group mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.status') }}</span>
                                @foreach ($C::TRANG_THAI_LOC as $ma => $chu)
                                    <div class="form-check mb-1 mt-1">
                                        <input type="checkbox" class="form-check-input cn-status"
                                            id="cn_status_{{ $ma }}" value="{{ $ma }}"
                                            {{ in_array($ma, $trangThaiChon, true) ? 'checked' : '' }}>
                                        <label for="cn_status_{{ $ma }}" class="ms-2">{{ __('message.'.$chu) }}</label>
                                    </div>
                                @endforeach
                                {{-- HAI mục, v2 có ba: trả đủ là API dọn luôn cờ ghi nợ nên
                                     khoản ấy rời sổ — bày mục "Đã thanh toán" là bày một ô
                                     tick không bao giờ trả về dòng nào. Xem TRANG_THAI_LOC. --}}
                            </div>
                        </div>

                        {{-- v2 còn một khối lọc theo khoảng thời gian nhưng ĐÃ COMMENT LẠI
                             bên đó, tuy JS vẫn gửi from_date / to_date lên cho một
                             controller không hề đọc tới. Không bê sang. --}}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle mt-md-2 mt-lg-0">
            <div class="content_midd">
                {{-- Nạp lại chỉ thay ruột `.list`, nên HÀNG TIÊU ĐỀ nằm TRONG đó: bốn
                     con số đếm phải đổi theo bộ lọc, để ngoài là lọc xong số cũ vẫn
                     nằm nguyên trên đầu bảng. v2 cũng đặt khối này trong list.blade. --}}
                <div class="list scrollDiv">
                    <div class="content_midd_title">
                        <h1 class="tieu-de-trang">{{ __('message.debt') }}</h1>

                        <div class="d-flex justify-content-end cong-no-thanh-dau">
                            <div class="justify-content-start">
                                <div class="btn_top_content d-flex">
                                    @foreach ($C::MOC_HAN as $ma => $chu)
                                        <button type="button"
                                            class="btn btn-filter cn-moc-han {{ $filters['due'] === $ma ? 'active' : '' }}"
                                            data-due="{{ $ma }}">
                                            {{ __('message.'.$chu) }} ({{ $demTheoMoc[$ma] }})
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            {{-- v2 KHÔNG có nút này. Thêm vào vì mọi màn danh sách khác của
                                 khu này đều xuất được, và sổ nợ là thứ người ta hay mang ra
                                 ngoài để đối chiếu với nhà cung cấp. --}}
                            <a class="btn btn-sm d-flex align-items-center btn-export"
                                href="{{ route('admin.cong-no.export', request()->query()) }}">
                                <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export_report') }}
                            </a>

                            {{-- Chọn cột: bỏ tick là thêm cột vào ?hide=, tải lại giữ nguyên. --}}
                            <div class="dropup d-none d-lg-block">
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
                                                    <label for="show_{{ $cot }}">{{ __('message.'.$chu) }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- KHÔNG có nút "Thêm": khoản nợ không khai tay được, nó sinh ra khi
                         duyệt một phiếu mua có ghi nợ. v2 cũng không có nút ấy. --}}

                    <div class="table-responsive table-border-style">
                        <table class="table-cong-no none_mobile">
                            <tr>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ __('message.order_code') }}</th>
                                <th class="text-left show_supplier {{ $columns['show_supplier'] ? '' : 'hide' }}">{{ __('message.object_name') }}</th>
                                <th class="text-left show_branch {{ $columns['show_branch'] ? '' : 'hide' }}">{{ __('message.branch') }}</th>
                                <th class="text-right show_remaining {{ $columns['show_remaining'] ? '' : 'hide' }}">{{ __('message.not_paid') }}</th>
                                <th class="text-right show_paid {{ $columns['show_paid'] ? '' : 'hide' }}">{{ __('message.paid') }}</th>
                                <th class="text-right show_total_amount {{ $columns['show_total_amount'] ? '' : 'hide' }}">{{ __('message.total_money') }}</th>
                                <th class="text-center show_remaining_term {{ $columns['show_remaining_term'] ? '' : 'hide' }}">{{ __('message.remaining_term') }}</th>
                                <th class="text-center show_due_date {{ $columns['show_due_date'] ? '' : 'hide' }}">{{ __('message.due_date') }}</th>
                                <th class="text-left show_creator {{ $columns['show_creator'] ? '' : 'hide' }}">{{ __('message.creator') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($list as $i => $item)
                                @php
                                    $tt = (string) ($item['status'] ?? 'unpaid');
                                    $conNo = (float) ($item['remaining'] ?? 0);
                                @endphp
                                <tr class="item" data-id="{{ (int) ($item['id'] ?? 0) }}"
                                    data-code="{{ $item['code'] ?? '' }}"
                                    data-supplier="{{ $item['supplier_name'] ?? '' }}"
                                    data-branch="{{ $item['branch_name'] ?? '' }}"
                                    data-total="{{ (float) ($item['total_amount'] ?? 0) }}"
                                    data-paid="{{ (float) ($item['paid_amount'] ?? 0) }}"
                                    data-remaining="{{ $conNo }}"
                                    data-due-date="{{ $ngayVN($item['due_date'] ?? '') }}"
                                    data-term="{{ $C::chuHan($item) }}"
                                    data-status="{{ $tt }}"
                                    data-status-name="{{ __('message.'.($C::TRANG_THAI[$tt] ?? 'not_paid')) }}"
                                    data-contact="{{ $item['contact_name'] ?? '' }}"
                                    data-phone="{{ $item['contact_phone'] ?? '' }}"
                                    data-creator="{{ $item['created_by_name'] ?? '' }}"
                                    data-created-at="{{ $ngayVN($item['created_at'] ?? '') }}">
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    <td class="text-left item-code show_code {{ $columns['show_code'] ? '' : 'hide' }}">
                                        <a type="button" class="edit_bt detail-item text-decoration-none"
                                            title="{{ __('message.view-detail') }}">{{ $item['code'] ?? '' }}</a>
                                    </td>
                                    <td class="text-left show_supplier {{ $columns['show_supplier'] ? '' : 'hide' }}"
                                        title="{{ $item['supplier_name'] ?? '' }}">{{ $item['supplier_name'] ?? '' }}</td>
                                    <td class="text-left show_branch {{ $columns['show_branch'] ? '' : 'hide' }}">{{ $item['branch_name'] ?? '' }}</td>
                                    <td class="text-right show_remaining {{ $columns['show_remaining'] ? '' : 'hide' }}"><b>{{ $tien($conNo) }}</b></td>
                                    <td class="text-right show_paid {{ $columns['show_paid'] ? '' : 'hide' }}">{{ $tien($item['paid_amount'] ?? 0) }}</td>
                                    <td class="text-right show_total_amount {{ $columns['show_total_amount'] ? '' : 'hide' }}">{{ $tien($item['total_amount'] ?? 0) }}</td>
                                    {{-- Cột này v2 tô màu thẳng bằng style inline (xanh lá / đỏ /
                                         cam / đen). Bên này dùng lớp màu của vỏ để một ngày đổi
                                         bảng màu thì đổi một chỗ. --}}
                                    <td class="text-center show_remaining_term {{ $columns['show_remaining_term'] ? '' : 'hide' }}">
                                        <b class="{{ $C::mauHan($item) }}">{{ $C::hanTrangThai($item) }}</b>
                                        @if ($soNgay = $C::hanSoNgay($item))
                                            <span class="so-ngay">{{ $soNgay }}</span>
                                        @endif
                                    </td>
                                    <td class="text-center show_due_date {{ $columns['show_due_date'] ? '' : 'hide' }}">{{ $ngayVN($item['due_date'] ?? '') ?: '-' }}</td>
                                    <td class="text-left show_creator {{ $columns['show_creator'] ? '' : 'hide' }}"
                                        title="{{ $item['created_by_name'] ?? '' }}">{{ $item['created_by_name'] ?? '' }}</td>
                                    <td class="text-center action not-export">
                                        {{-- v2 bày nút bút chì mở lại chính hộp chi tiết. Ở đây nút
                                             nói đúng việc nó làm: ghi một lượt trả. Trả hết rồi thì
                                             không bày — bày nút rồi báo lỗi lúc bấm là bẫy người dùng. --}}
                                        @if ($conNo > 0.005)
                                            <a class="edit_bt pay-item" type="button" title="{{ __('message.debt_payment') }}"><i class="fa fa-money-bill"></i></a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center py-4">
                                        {{ $coLoc ? 'Không có khoản nợ nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                                    </td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- BẢN THẺ CHO ĐIỆN THOẠI — v2 giấu hẳn bảng dưới 992px và bày
                             đúng khối này: một hàng tiêu đề hai cột, rồi mỗi dòng là tên
                             đối tượng (đậm) + mã ở dưới, số tiền bên phải. Cùng class
                             .item và cùng bộ data-* với dòng bảng nên dùng chung JS.

                             Khác v2 một chỗ: cột phải in CÒN NỢ chứ không phải tổng tiền.
                             Màn này mở ra để biết còn phải trả bao nhiêu; in tổng tiền thì
                             một phiếu đã trả gần hết vẫn hiện con số to như lúc chưa trả. --}}
                        <div class="table-cong-no list none_desktop">
                            <div class="d-flex align-items-center gap-1 p-2 border">
                                <div class="fw-bold" style="flex: 1">{{ __('message.object_name') }}</div>
                                <div class="fw-bold">{{ __('message.not_paid') }}</div>
                            </div>
                            @foreach ($list as $item)
                                @php
                                    $tt = (string) ($item['status'] ?? 'unpaid');
                                    $conNo = (float) ($item['remaining'] ?? 0);
                                @endphp
                                <div class="item" data-id="{{ (int) ($item['id'] ?? 0) }}"
                                    data-code="{{ $item['code'] ?? '' }}"
                                    data-supplier="{{ $item['supplier_name'] ?? '' }}"
                                    data-branch="{{ $item['branch_name'] ?? '' }}"
                                    data-total="{{ (float) ($item['total_amount'] ?? 0) }}"
                                    data-paid="{{ (float) ($item['paid_amount'] ?? 0) }}"
                                    data-remaining="{{ $conNo }}"
                                    data-due-date="{{ $ngayVN($item['due_date'] ?? '') }}"
                                    data-term="{{ $C::chuHan($item) }}"
                                    data-status="{{ $tt }}"
                                    data-status-name="{{ __('message.'.($C::TRANG_THAI[$tt] ?? 'not_paid')) }}"
                                    data-contact="{{ $item['contact_name'] ?? '' }}"
                                    data-phone="{{ $item['contact_phone'] ?? '' }}"
                                    data-creator="{{ $item['created_by_name'] ?? '' }}"
                                    data-created-at="{{ $ngayVN($item['created_at'] ?? '') }}">
                                    <div class="d-flex flex-column" style="flex: 1">
                                        <span style="font-weight: 600;">{{ $item['supplier_name'] ?? '' }}</span>
                                        <span style="font-size: 12px;">{{ $item['code'] ?? '' }}</span>
                                        <span style="font-size: 12px;" class="{{ $C::mauHan($item) }}">{{ $C::chuHan($item) }}</span>
                                    </div>
                                    <div class="d-flex justify-content-end text-right gap-2" style="min-width: 90px">
                                        <b>{{ $tien($conNo) }}</b>
                                    </div>
                                </div>
                            @endforeach
                            @if (! count($list))
                                <div class="text-center py-4">
                                    {{ $coLoc ? 'Không có khoản nợ nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width {{ count($list) ? '' : 'd-none' }}"
                    data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Chi tiết công nợ =====================
         v2 đặt id #modalCrud, tiêu đề "Chi tiết công nợ #<mã>", khối ô col-md-3
         khoá cứng ở trên, bảng chi tiết thanh toán ở dưới, nút Thanh toán ở chân.
         Giữ nguyên bố cục đó. --}}
    <div class="modal" id="modalCrud" data-id="" style="padding-inline: 0 !important">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-auto">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.debt_details') }} #<span class="code_debt_title"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="px-1 mb-3 info-debt">
                        <div class="row">
                            <div class="col-md-3 mt-2">
                                <label class="mb-1">{{ __('message.order_code') }}:</label>
                                <input type="text" class="form-control fw-bold code_debt" disabled>
                            </div>
                            {{-- Hai ô hằng số, và chúng có mặt vì đúng lý do của v2: người
                                 đọc phiếu in ra phải thấy khoản này là nợ AI và nợ vì VIỆC
                                 GÌ, không phải suy ra từ chỗ mình đang đứng. --}}
                            <div class="col-md-3 mt-2">
                                <label class="mb-1">{{ __('message.object') }}:</label>
                                <input type="text" class="form-control type_debt" value="{{ __('message.supplier') }}" disabled>
                            </div>
                            <div class="col-md-3 mt-2">
                                <label class="mb-1">{{ __('message.object_name') }}:</label>
                                <input type="text" class="form-control object_name_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-2">
                                <label class="mb-1">{{ __('message.debt_type') }}:</label>
                                <input type="text" class="form-control type_object_debt" value="{{ __('message.purchase') }}" disabled>
                            </div>

                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.not_paid') }}:</label>
                                <input type="text" class="form-control fw-bold remaining_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.paid') }}:</label>
                                <input type="text" class="form-control paid_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.total_money') }}:</label>
                                <input type="text" class="form-control total_amount_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.branch') }}:</label>
                                <input type="text" class="form-control branch_debt" disabled>
                            </div>

                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.due_date') }}:</label>
                                <input type="text" class="form-control exprired_date_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.remaining_term') }}:</label>
                                <input type="text" class="form-control term_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.status') }}:</label>
                                <input type="text" class="form-control status_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.creator') }}:</label>
                                <input type="text" class="form-control creator_debt" disabled>
                            </div>

                            {{-- Hai ô này v2 để trong TỪNG DÒNG của bảng thanh toán (cột
                                 "Khách hàng"). Bên này người đại diện gắn với KHOẢN NỢ chứ
                                 không với từng lượt trả — đó là người phải gọi khi tới hạn,
                                 và một khoản nợ chỉ có một người như thế. --}}
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.representative') }}:</label>
                                <input type="text" class="form-control contact_debt" disabled>
                            </div>
                            <div class="col-md-3 mt-3">
                                <label class="mb-1">{{ __('message.phone') }}:</label>
                                <input type="text" class="form-control phone_debt" disabled>
                            </div>
                        </div>
                    </div>

                    <div class="info-payment">
                        <div class="table-responsive">
                            <table>
                                <thead class="bg-light">
                                    <tr>
                                        <th class="text-center">{{ __('message.stt') }}</th>
                                        <th class="text-center">{{ __('message.payment-method') }}</th>
                                        <th class="text-center">{{ __('message.payment-at') }}</th>
                                        <th class="text-center">{{ __('message.payment-paid') }}</th>
                                        <th class="text-center">{{ __('message.amount_due') }}</th>
                                        <th class="text-center">{{ __('message.attachment') }}</th>
                                        <th class="text-center">{{ __('message.creator') }}</th>
                                        <th class="text-center">{{ __('message.note') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="debt-details"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Hàng nút canh GIỮA, nút bỏ đi đỏ / nút đồng ý xanh — quy chuẩn của
                     hệ thống này. v2 để Đóng đỏ, Thanh toán xanh dương và dạt phải. --}}
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green payment-debt">{{ __('message.payment') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Chi tiết thanh toán =====================
         v2 đặt id #modalDetailItem — hộp ghi MỘT lượt trả. --}}
    <div class="modal" id="modalDetailItem" data-id="" style="padding-inline: 0 !important">
        <div class="modal-dialog modal-dialog-centered mx-auto">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.payment_details') }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="payment-method">
                                {{ __('message.payment-method') }} <span class="required" style="color:red">*</span>
                            </label>
                            <select class="form-select" id="payment-method">
                                @foreach (\App\Http\Controllers\CongNoController::PHUONG_THUC as $ma => $ten)
                                    <option value="{{ $ma }}">{{ $ten }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- CÒN THIẾU so với v2: ô "Tài khoản ngân hàng / thiết bị" đi kèm
                             hình thức chuyển khoản. Chưa có API nào trả danh sách tài khoản
                             nhận tiền, mà bày một ô không bao giờ điền được thì thà chưa
                             bày. Có API rồi thì thêm vào ngay đây. --}}

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="payment_at">{{ __('message.payment-at') }}</label>
                            <input type="text" class="form-control" id="payment_at" disabled>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="customer_name">{{ __('message.representative') }}</label>
                            <input type="text" class="form-control" id="customer_name" maxlength="150" autocomplete="off">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="customer_phone">{{ __('message.phone') }}</label>
                            <input type="text" class="form-control" id="customer_phone" maxlength="30" autocomplete="off">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="paid">
                                {{ __('message.payment_amount') }} <span class="required" style="color:red">*</span>
                            </label>
                            <input type="text" class="form-control" id="paid" inputmode="numeric" autocomplete="off" placeholder="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="total_amount">{{ __('message.not_paid') }}</label>
                            <input type="text" class="form-control fw-bold" id="total_amount" disabled>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">{{ __('message.attachment') }}</label>
                            {{-- Ô chọn tệp chép khuôn v2, nhưng ảnh đi lên TRƯỚC qua đường ảnh
                                 của phiếu mua và chỉ ĐƯỜNG DẪN mới theo lượt lưu — nhờ vậy
                                 lượt lưu vẫn là một payload phẳng. --}}
                            <div class="custom-file-wrapper">
                                <input type="file" id="attachment" accept="image/*" hidden>
                                <button type="button" id="custom-button">{{ __('message.choose_file') }}</button>
                                <span id="file-name">{{ __('message.no_file_chosen') }}</span>
                            </div>
                            <input type="hidden" id="attachment_url">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="note">{{ __('message.note') }}</label>
                            <input type="text" class="form-control" id="note" maxlength="500" autocomplete="off">
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save_debt_detail">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Tấm trượt chi tiết cho điện thoại ===================== --}}
    <div class="offcanvas offcanvas-end offcanvas-custom" tabindex="-1" id="offcanvasDetail">
        <div class="offcanvas-header">
            <a type="button" aria-label="{{ __('message.close') }}" class="btn-back" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-arrow-left" style="font-size: 22px;"></i>
            </a>
            <div class="d-flex" style="flex: 1;">
                <h5 class="offcanvas-title">{{ __('message.debt_details') }}</h5>
            </div>
            <div class="d-flex button-header" style="gap: 12px;">
                <a class="edit_bt pay-item-canvas" type="button" title="{{ __('message.debt_payment') }}"><i class="fa fa-money-bill"></i></a>
            </div>
        </div>
        <div class="offcanvas-body" style="height: calc(100vh - 58px); padding: 12px;">
            <div class="modal-view-materials"></div>
            <h6 class="mt-3">{{ __('message.payment_details') }}</h6>
            <div class="cn-lich-su-mobile"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_CN = @json(url('/admin/cashbook/debts'));
        const URL_CN_ANH = @json(route('admin.phieu-mua-hang.anh'));

        const CHU = {
            cash: @json(\App\Http\Controllers\CongNoController::PHUONG_THUC['cash']),
            transfer: @json(\App\Http\Controllers\CongNoController::PHUONG_THUC['transfer']),
            khongCo: @json(__('message.no_file_chosen')),
        };

        // ================= Bộ lọc =================
        // Tự dựng URL thay vì submit form, vì hai lẽ:
        //   - trên điện thoại vỏ v2 BƯNG khối lọc sang tấm offcanvas, mỗi lượt
        //     chỉ bưng MỘT khối, nên submit lúc đó đánh rơi các ô còn lại;
        //   - lựa chọn cột nằm ở ?hide=, không phải ô trong form.
        // Ô lọc nằm ở đâu cũng tìm ra: khung trái và tấm offcanvas cùng .fillter-box.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai(themVao) {
            const q = new URLSearchParams();

            ['supplier_name', 'code'].forEach(function (ten) {
                const v = String(oLoc(ten).val() || '').trim();
                if (v) q.set(ten, v);
            });

            // Ô chọn nhiều: gộp thành chuỗi ngăn bởi dấu phẩy, đúng cái controller đọc.
            ['supplier_id', 'created_by'].forEach(function (ten) {
                const g = oLoc(ten).val() || [];
                if (g.length) q.set(ten, [].concat(g).join(','));
            });

            // Tick hết = không lọc, nên không gửi tham số nào — để địa chỉ sạch,
            // người dùng chia sẻ đường dẫn cho nhau còn đọc được.
            const tt = $('.cn-status:checked').map(function () { return this.value; }).get();
            if (tt.length && tt.length < $('.cn-status').length) q.set('status', tt.join(','));

            // Mốc hạn đang bấm. 'all' là mặc định nên không cần nằm trên địa chỉ.
            const moc = (themVao && themVao.due) || $('.cn-moc-han.active').data('due') || 'all';
            if (moc !== 'all') q.set('due', moc);

            // Tham số không có ô trong khung lọc thì chép lại từ URL cũ, không là
            // đổi bộ lọc một cái mất luôn cột đang ẩn và cỡ trang.
            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size'].forEach(function (ten) {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            // Cố ý không mang `page`: lọc lại thì trang 5 của bộ lọc cũ hết nghĩa.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerLoc = null;
        $(document).on('input',
            '.fillter-box [name="supplier_name"], .fillter-box [name="code"]', function () {
                clearTimeout(timerLoc);
                timerLoc = setTimeout(locLai, 300);
            });
        $(document).on('change',
            '.fillter-box [name="supplier_id"], .fillter-box [name="created_by"]', locLai);

        // BỎ TICK Ô CUỐI CÙNG THÌ TICK LẠI.
        // "Không chọn trạng thái nào" không phải một câu hỏi ai đó định hỏi — nó
        // chỉ là cách để nhận một bảng rỗng vĩnh viễn mà không có gì giải thích.
        // v2 trong tình huống ấy chạy `whereRaw('1 = 0')` rồi im lặng.
        $(document).on('change', '.cn-status', function () {
            if (!$('.cn-status:checked').length) {
                $(this).prop('checked', true);
                toastr.info('Phải giữ ít nhất một trạng thái.');

                return;
            }
            locLai();
        });

        $(document).on('click', '.cn-moc-han', function () {
            const $n = $(this);
            if ($n.hasClass('active')) return;
            $('.cn-moc-han').removeClass('active');
            $n.addClass('active');
            locLai({ due: String($n.data('due')) });
        });

        // Ô Chi nhánh: KHÔNG đi qua locLai(). Đây là chi nhánh đang làm việc của
        // tab, đổi nó là đổi cả phiên làm việc chứ không phải thêm một điều kiện
        // lọc — `V2.doiChiNhanhTab` lo sessionStorage rồi nạp lại trang.
        $(document).on('change', '#cn-branch', function () {
            V2.doiChiNhanhTab(this.value);
        });

        // ================= Chọn cột =================
        // Cột đang tắt ghi vào ?hide= rồi tải lại, để giữ sau khi đổi trang.
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

        // ================= Tiện ích =================
        function soTu(o) { return String(o == null ? '' : o).replace(/[^0-9]/g, ''); }
        function tien(n) { return Number(n || 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 }); }
        function thoat(s) { return $('<div>').text(s == null ? '' : s).html(); }
        function ngayVN(v) {
            if (!v) return '';
            const d = new Date(v);

            return isNaN(d) ? '' : ('0' + d.getDate()).slice(-2) + '-'
                + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + d.getFullYear();
        }

        $(document).on('input', '#modalDetailItem #paid', function () {
            const raw = soTu(this.value).slice(0, 12);
            this.value = raw ? tien(raw) : '';
        });

        // ================= Chi tiết công nợ =================
        // Khối thông tin dựng từ data-* của chính dòng đang bấm — mọi thứ cần in
        // đã nằm sẵn trong bảng, gọi thêm một vòng mạng chỉ để lấy lại ngần ấy
        // chữ là thừa. Chỉ BẢNG THANH TOÁN mới phải gọi ngầm: nó không nằm trong
        // danh sách, mà kéo cả sổ trả của mọi dòng về cùng trang là tải một đống
        // chữ cho một dòng người dùng sẽ mở.
        function donThongTin($tr) {
            const d = function (k) { return $tr.attr('data-' + k) || ''; };
            const $h = $('#modalCrud');

            $h.attr('data-id', $tr.attr('data-id'));
            $h.attr('data-total', d('total'));
            $h.find('.code_debt_title').text(d('code'));
            $h.find('.code_debt').val(d('code'));
            $h.find('.object_name_debt').val(d('supplier'));
            $h.find('.remaining_debt').val(tien(d('remaining')));
            $h.find('.paid_debt').val(tien(d('paid')));
            $h.find('.total_amount_debt').val(tien(d('total')));
            $h.find('.branch_debt').val(d('branch'));
            $h.find('.exprired_date_debt').val(d('due-date') || '-');
            $h.find('.term_debt').val(d('term'));
            $h.find('.status_debt').val(d('status-name'));
            $h.find('.creator_debt').val(d('creator'));
            $h.find('.contact_debt').val(d('contact'));
            $h.find('.phone_debt').val(d('phone'));

            // Trả xong rồi thì giấu nút, cùng luật với cột Hành động của bảng.
            $h.find('.payment-debt').toggleClass('d-none', Number(d('remaining')) <= 0.005);
        }

        // Một lượt trả `amount` ÂM là lượt CHỮA lại con số đã ghi sai, không phải
        // lượt trả — nói thẳng ra chứ đừng in một số âm rồi để người đọc tự đoán.
        //
        // Cột "Còn phải trả" = tổng tiền − luỹ kế sau lượt ấy, đúng nghĩa cột
        // `not_paid` của v2: lúc đó còn nợ bao nhiêu.
        function hangLichSu(tong) {
            return function (p, i) {
                const chua = Number(p.amount || 0) < 0;
                const anh = p.attachment
                    ? '<a href="' + p.attachment + '" target="_blank" rel="noopener"><i class="fa fa-download"></i></a>'
                    : '';

                return '<tr>'
                    + '<td class="text-center">' + (i + 1) + '</td>'
                    + '<td class="text-center">' + (CHU[p.payment_method] || '') + '</td>'
                    + '<td class="text-center">' + ngayVN(p.created_at) + '</td>'
                    + '<td class="text-center"><b' + (chua ? ' class="text-warning"' : '') + '>'
                    + tien(p.amount) + (chua ? ' (chữa lại)' : '') + '</b></td>'
                    + '<td class="text-center">' + tien(tong - Number(p.paid_after || 0)) + '</td>'
                    + '<td class="text-center">' + anh + '</td>'
                    + '<td class="text-center">' + thoat(p.created_by_name) + '</td>'
                    + '<td class="text-center">' + thoat(p.note) + '</td>'
                    + '</tr>';
            };
        }

        function napLichSu(id, tong, $dich, dungThe) {
            $dich.html(dungThe
                ? '<div class="text-center py-3 text-muted">Đang tải…</div>'
                : '<tr><td colspan="8" class="text-center py-3 text-muted">Đang tải…</td></tr>');

            return $.ajax({ url: URL_CN + '/' + id + '/payments', method: 'GET', headers: { Accept: 'application/json' } })
                .done(function (r) {
                    const ds = (r && r.data) || [];
                    if (!ds.length) {
                        $dich.html(dungThe
                            ? '<div class="text-center py-3 text-muted">Chưa có lượt trả nào.</div>'
                            : '<tr><td colspan="8" class="text-center py-3 text-muted">Chưa có lượt trả nào.</td></tr>');

                        return;
                    }

                    if (dungThe) {
                        $dich.html(ds.map(function (p, i) {
                            return '<div class="d-flex align-items-center justify-content-between"'
                                + ' style="border-bottom:1px solid #ddd;padding:6px 4px">'
                                + '<span>' + (i + 1) + '. ' + ngayVN(p.created_at)
                                + ' — ' + (CHU[p.payment_method] || '') + '</span>'
                                + '<b>' + tien(p.amount) + '</b></div>';
                        }).join(''));

                        return;
                    }

                    $dich.html(ds.map(hangLichSu(Number(tong || 0))).join(''));
                })
                .fail(function (x) {
                    const cau = (x.responseJSON || {}).message || 'Không tải được lịch sử trả nợ.';
                    toastr.error(cau);
                    $dich.html(dungThe
                        ? '<div class="text-center py-3 text-danger">' + thoat(cau) + '</div>'
                        : '<tr><td colspan="8" class="text-center py-3 text-danger">' + thoat(cau) + '</td></tr>');
                });
        }

        $(document).on('click', '.detail-item', function (e) {
            e.stopPropagation();
            const $tr = $(this).closest('.item');
            donThongTin($tr);
            $('#modalCrud').modal('show');
            napLichSu($tr.attr('data-id'), $tr.attr('data-total'), $('#modalCrud .debt-details'), false);
        });

        // ================= Ghi một lượt trả =================
        function moHopTra($tr) {
            const conNo = Number($tr.attr('data-remaining') || 0);
            const $h = $('#modalDetailItem');

            $h.attr('data-id', $tr.attr('data-id'));
            $h.attr('data-remaining', conNo);
            $('#total_amount').val(tien(conNo));
            // Điền sẵn ĐÚNG số còn nợ: trả nốt cho xong là lượt hay gặp nhất, và
            // người trả một phần thì sửa lại con số vẫn nhanh hơn gõ từ đầu.
            $('#paid').val(tien(conNo));
            $('#payment-method').val('cash');
            $('#payment_at').val(ngayVN(new Date()));
            // Người đại diện lấy từ thoả thuận nợ đang có, sửa được: tới hạn mà
            // bên bán đổi người phụ trách thì đây là chỗ ghi lại số mới.
            $('#customer_name').val($tr.attr('data-contact') || '');
            $('#customer_phone').val($tr.attr('data-phone') || '');
            $('#note').val('');
            $('#attachment_url').val('');
            $('#attachment').val('');
            $('#file-name').text(CHU.khongCo);
            $h.modal('show');
        }

        $(document).on('click', '.pay-item', function (e) {
            e.stopPropagation();
            moHopTra($(this).closest('.item'));
        });

        // Bấm Thanh toán từ trong hộp chi tiết: đóng hộp ấy TRƯỚC rồi mới mở hộp
        // trả. Hai modal chồng nhau thì lớp phủ của cái dưới nằm TRÊN cái trên và
        // mọi ô nhập thành bấm không được.
        $(document).on('click', '.payment-debt', function () {
            const id = $('#modalCrud').attr('data-id');
            const $tr = $('.item[data-id="' + id + '"]').first();
            if (!$tr.length) return;

            $('#modalCrud').one('hidden.bs.modal', function () { moHopTra($tr); }).modal('hide');
        });

        $(document).on('click', '#custom-button', function () { $('#attachment').click(); });

        $(document).on('change', '#attachment', function () {
            const tep = this.files && this.files[0];
            if (!tep) { $('#file-name').text(CHU.khongCo); return; }

            const fd = new FormData();
            fd.append('anh', tep);
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
            $('#file-name').text('Đang tải lên…');
            $('.save_debt_detail').prop('disabled', true);

            $.ajax({
                url: URL_CN_ANH,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: { Accept: 'application/json' },
            })
                .done(function (r) {
                    $('#attachment_url').val(r.url || '');
                    $('#file-name').text(tep.name);
                })
                .fail(function (x) {
                    const b = x.responseJSON || {};
                    const theoO = Object.keys(b.errors || {})
                        .map(function (k) { return [].concat(b.errors[k]).join(' '); }).join(' ');
                    toastr.error(theoO || b.message || 'Không tải được ảnh lên.');
                    $('#attachment').val('');
                    $('#attachment_url').val('');
                    $('#file-name').text(CHU.khongCo);
                })
                .always(function () { $('.save_debt_detail').prop('disabled', false); });
        });

        $(document).on('click', '.save_debt_detail', function () {
            const $h = $('#modalDetailItem');
            const id = $h.attr('data-id');
            const conNo = Number($h.attr('data-remaining') || 0);
            const so = Number(soTu($('#paid').val()));

            if (!id) return;
            if (!so) { toastr.error('Nhập số tiền trả.'); return; }
            // Máy chủ chặn lại lần nữa — xem CongNoController::traNo. Chặn ở đây
            // chỉ để người dùng biết ngay tại ô vừa gõ.
            if (so > conNo + 0.005) { toastr.error('Số tiền trả lớn hơn số còn nợ.'); return; }

            V2.luuHop($h, URL_CN + '/' + id + '/payments', 'POST', {
                amount: so,
                payment_method: $('#payment-method').val(),
                contact_name: $('#customer_name').val(),
                contact_phone: $('#customer_phone').val(),
                payment_attachment: $('#attachment_url').val(),
                note: $('#note').val(),
            }, $(this));
        });

        // ================= Điện thoại =================
        // Thẻ hẹp, không đủ chỗ đặt nút — bấm cả thẻ để mở tấm trượt.
        function dongChiTiet(nhan, giaTri) {
            return '<div class="d-flex align-items-center justify-content-between"'
                + ' style="border-bottom:1px solid #ddd;padding:6px 4px">'
                + '<span class="text-muted">' + nhan + '</span>'
                + '<span class="text-end">' + (giaTri || '') + '</span></div>';
        }

        $(document).on('click', '.none_desktop .item', function () {
            const $tr = $(this);
            const d = function (k) { return $tr.attr('data-' + k) || ''; };

            let h = '';
            h += dongChiTiet(@json(__('message.order_code')), thoat(d('code')));
            h += dongChiTiet(@json(__('message.object_name')), thoat(d('supplier')));
            h += dongChiTiet(@json(__('message.branch')), thoat(d('branch')));
            h += dongChiTiet(@json(__('message.not_paid')), '<b>' + tien(d('remaining')) + '</b>');
            h += dongChiTiet(@json(__('message.paid')), tien(d('paid')));
            h += dongChiTiet(@json(__('message.total_money')), tien(d('total')));
            h += dongChiTiet(@json(__('message.remaining_term')), thoat(d('term')));
            h += dongChiTiet(@json(__('message.due_date')), thoat(d('due-date') || '-'));
            h += dongChiTiet(@json(__('message.status')), thoat(d('status-name')));
            h += dongChiTiet(@json(__('message.representative')), thoat(d('contact')));
            h += dongChiTiet(@json(__('message.phone')), thoat(d('phone')));
            h += dongChiTiet(@json(__('message.creator')), thoat(d('creator')));

            const $oc = $('#offcanvasDetail');
            $oc.attr('data-id', $tr.attr('data-id')).data('tr', $tr);
            $oc.find('.modal-view-materials').html(h);
            $oc.find('.button-header').toggleClass('d-none', Number(d('remaining')) <= 0.005);
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasDetail')).show();

            napLichSu($tr.attr('data-id'), d('total'), $oc.find('.cn-lich-su-mobile'), true);
        });

        $(document).on('click', '.pay-item-canvas', function () {
            const $tr = $('#offcanvasDetail').data('tr');
            if (!$tr) return;

            const el = document.getElementById('offcanvasDetail');
            const oc = bootstrap.Offcanvas.getInstance(el);
            if (oc) oc.hide();
            moHopTra($tr);
        });
    </script>
@endpush
