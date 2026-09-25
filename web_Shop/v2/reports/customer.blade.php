{{-- Thống kê → Khách hàng — dựng theo trang báo cáo của v2
     (report/revenue-report/index.blade.php + RevenueReportController::handleTableCustomer).

     Khuôn trang, đúng như bản gốc:
       ┌ khung lọc trái: Chi nhánh · Thời gian · Khách hàng · Nguồn đơn
       │                 rồi HAI NÚT "Xem báo cáo" / "Xuất báo cáo" ở cuối khung
       └ nội dung phải: một CARD
           card-header trong suốt, chỉ chứa cặp nút Biểu đồ | Danh sách
           card-body: tiêu đề + phụ đề, hộp kỳ báo cáo nền xanh bên phải,
                      DÃY 9 TAB ngay trong trang, rồi bảng ở .list-by-nav

     Dữ liệu do ReportController::customers đẩy sang: $rows, $tong, $filters, $columns. --}}
@extends('v2::layouts.master')

@section('title', 'Thống kê khách hàng')

@push('styles')
    <style>
        /* Dãy 9 tab trong trang — khuôn `cus-nav-tabs` của v2. */
        .cus-nav-tabs { border-bottom: 1px solid #dee2e6; flex-wrap: wrap; gap: 2px; }
        .cus-nav-tabs .nav-link {
            border: 1px solid transparent; border-radius: 6px 6px 0 0; padding: 6px 14px;
            font-size: 13px; color: #486a7f; cursor: pointer; text-decoration: none;
        }
        .cus-nav-tabs .nav-link:hover { background: #f2f5fa; }
        .cus-nav-tabs .nav-link.active {
            background: #fff; border-color: #dee2e6 #dee2e6 #fff; color: #212529; font-weight: 600;
        }
        /* Tab của màn CHƯA DỰNG: vẫn bày cho đủ khuôn v2 nhưng không bấm được —
           bấm vào rồi bị đá về trang khác thì tưởng bấm nhầm. */
        .cus-nav-tabs .nav-link.chua-dung { color: #b6bcc7; cursor: not-allowed; }

        /* Hộp kỳ báo cáo bên phải tiêu đề. */
        #header_title_info { background-color: #BED5FF; border-radius: 12px; font-size: 13px; }

        /* Nút xem nhanh của khối Thời gian — hai cột, nút đang chọn tô đậm. */
        .tk-nhanh .form-check-input { display: none; }
        .tk-nhanh label {
            display: block; text-align: center; padding: 5px 4px; border: 1px solid #d9d9d9;
            border-radius: 4px; cursor: pointer; font-size: 13px; background: #fff; margin-bottom: 0;
        }
        .tk-nhanh input:checked + label { background: #1890ff; border-color: #1890ff; color: #fff; }

        /* Bảng 11 cột chia theo %, cộng đúng 100. Không cắt chữ: chữ dài xuống dòng.
           Hàng tiêu đề gọn — chữ nhỏ hơn ô dữ liệu một nhịp, đệm dọc mỏng, đệm ngang
           rộng cho các tiêu đề không dính nhau. */
        .list-by-nav .table-responsive { overflow-x: auto; }
        table.table-tk-khach { width: 100%; table-layout: fixed; }
        /* Cùng cỡ với hàng tiêu đề của trang Khách hàng: cao 32px — chữ 13,5px
           đậm, dòng 20px, đệm dọc 6px. */
        table.table-tk-khach th {
            white-space: nowrap; font-size: 13.5px; font-weight: 600; line-height: 20px;
            padding: 6px 10px; vertical-align: middle;
        }
        table.table-tk-khach td {
            white-space: normal; word-break: break-word; vertical-align: middle; padding: 8px;
        }
        table.table-tk-khach td.la-tien { white-space: nowrap; }
        table.table-tk-khach th .sort-icons { margin-left: 2px !important; }
        table.table-tk-khach th .sort-icons i { font-size: 12px !important; }
        /* Chia % theo bề rộng thật của từng nhãn (đo ở khung ~1182px) để mọi tiêu đề
           nằm gọn MỘT DÒNG — đó là cách giữ hàng tiêu đề thấp.
           STT 3.5 · Mã 9.5 · Tên 13.5 · Nhóm 10.5 · Hạng 5 · Tổng chi tiêu 10
           · Giá trị TB 11 · Điểm 9 · Đã thanh toán 10.5 · Còn nợ 8 · Số đơn 9.5 = 100 */
        table.table-tk-khach th:first-child { width: 3.5%; }
        table.table-tk-khach th.show_code { width: 9.5%; }
        table.table-tk-khach th.show_name { width: 13.5%; }
        table.table-tk-khach th.show_name_group { width: 10.5%; }
        table.table-tk-khach th.show_rank { width: 5%; }
        table.table-tk-khach th.show_total_expense { width: 10%; }
        table.table-tk-khach th.show_price_avg { width: 11%; }
        table.table-tk-khach th.show_accumulated_points { width: 9%; }
        table.table-tk-khach th.show_payment { width: 10.5%; }
        table.table-tk-khach th.show_debt { width: 8%; }
        table.table-tk-khach th.show_total_order { width: 9.5%; }

        table.table-tk-khach tr.dong-tong td { font-weight: 700; background: #fafafa; }
        .tk-cho-api { font-size: 12px; color: #fa8c16; font-weight: normal; }
        .tk-rong { padding: 28px 0; text-align: center; color: #8c8c8c; }
    </style>
@endpush

@php
    $C = \App\Http\Controllers\ReportController::class;
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($s) => \Illuminate\Support\Carbon::parse($s)->format('d/m/Y');

    /** Nhãn cột bấm được để sắp xếp. */
    $nutSap = function (string $khoa, string $nhan) use ($filters) {
        $dang = $filters['sort_field'] === $khoa;
        $tang = $dang && $filters['sort_type'] === 'asc';

        return view('v2::partials.sort-link', [
            'url' => request()->fullUrlWithQuery([
                'sort_field' => $khoa,
                'sort_type' => $dang && ! $tang ? 'asc' : 'desc',
            ]),
            'nhan' => $nhan,
            'moTang' => $dang ? ($tang ? 1 : 0.25) : 0.4,
            'moGiam' => $dang ? ($tang ? 0.25 : 1) : 0.4,
        ])->render();
    };
@endphp

@section('content')
    {{-- Dãy nút mở bộ lọc, CHỈ hiện trên điện thoại. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterBranch',
                'modalLabel' => __('message.branch'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterTimeMultiple',
                'modalLabel' => __('message.time'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterCustomer',
                'modalLabel' => __('message.customer'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterOrderOriginChannel',
                'modalLabel' => __('message.order-origin'),
            ])
        </div>
    </div>

    <div class="row index-end-day-report-page">
        {{-- ===================== Khung lọc trái ===================== --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block fillter-box-container pe-0">
            <div class="fillter-box" id="sidebar_filter">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <form action="{{ route('admin.reports.customers') }}" method="GET" id="search-form">
                            {{-- Chi nhánh --}}
                            <div id="filterBranch" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <label class="form-label title_search">{{ __('message.branch') }}</label>
                                    <select name="shop_id" class="form-control">
                                        <option value="">Chi nhánh đang làm việc</option>
                                        <option value="0" {{ $filters['shop_id'] === '0' ? 'selected' : '' }}>{{ __('message.all') }}</option>
                                        @foreach ($chiNhanh as $cn)
                                            <option value="{{ $cn['id'] }}" {{ $filters['shop_id'] === (string) $cn['id'] ? 'selected' : '' }}>
                                                {{ $cn['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Thời gian: sáu nút xem nhanh + hai ô ngày --}}
                            <div id="filterTimeMultiple" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <label class="form-label title_search">{{ __('message.time') }}</label>
                                    <div class="row g-1 tk-nhanh">
                                        @foreach ($C::KY_NHANH as $ma => $nhan)
                                            <div class="col-6">
                                                <input class="form-check-input chon-ky" type="radio" name="range"
                                                    value="{{ $ma }}" id="ky_{{ $ma }}"
                                                    {{ $filters['quick'] === $ma ? 'checked' : '' }}>
                                                <label for="ky_{{ $ma }}">{{ $nhan }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-2">
                                        <input type="text" name="from_date" value="{{ $filters['from_date'] }}"
                                            class="form-control date_from mb-2" id="fromDate" autocomplete="off"
                                            placeholder="{{ __('message.from_date') }}">
                                        <input type="text" name="to_date" value="{{ $filters['to_date'] }}"
                                            class="form-control date_to" id="toDate" autocomplete="off"
                                            placeholder="{{ __('message.to_date') }}">
                                    </div>
                                </div>
                            </div>

                            {{-- Khách hàng: nhóm + tên, đúng khối #filterCustomer của v2 --}}
                            <div id="filterCustomer" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <label class="form-label title_search">{{ __('message.customer') }}</label>
                                    <select class="form-control mb-2" disabled>
                                        <option>--{{ __('message.customer_group') }}--</option>
                                    </select>
                                    <div class="tk-cho-api mb-2">Nhóm khách hàng chờ API</div>
                                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                        class="form-control" autocomplete="off"
                                        placeholder="--{{ __('message.customer') }}--">
                                </div>
                            </div>

                            {{-- Nguồn đơn --}}
                            <div id="filterOrderOriginChannel" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.order-origin') }}
                                        <span class="tk-cho-api">(chờ API)</span></span>
                                    <div class="form-check mt-2">
                                        <input class="me-2 form-check-input" type="checkbox" id="kenh_all" checked disabled>
                                        <label class="form-check-label label-custom" for="kenh_all">{{ __('message.all') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="me-2 form-check-input" type="checkbox" id="kenh_pos" checked disabled>
                                        <label class="form-check-label label-custom" for="kenh_pos">{{ __('message.sales') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="me-2 form-check-input" type="checkbox" id="kenh_web" checked disabled>
                                        <label class="form-check-label label-custom" for="kenh_web">{{ __('message.online_order') }}</label>
                                    </div>
                                </div>
                            </div>

                            {{-- Số dòng của bảng xếp hạng — API cắt sẵn, không phân trang. --}}
                            <div class="mb-3">
                                <label class="form-label title_search">Số dòng</label>
                                <select name="limit" class="form-control">
                                    @foreach ($C::LIMITS as $muc)
                                        <option value="{{ $muc }}" {{ $filters['limit'] === $muc ? 'selected' : '' }}>
                                            {{ __('message.display', ['name' => $muc]) }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Hai nút ở CUỐI khung lọc, đúng khối #btnAction của v2. --}}
                            <div id="btnAction" class="d-flex justify-content-between">
                                <button type="submit" class="btn btn-primary btn-sm btn_see_report">{{ __('message.see_report') }}</button>
                                <a class="btn btn-sm d-flex align-items-center btn-export"
                                    href="{{ route('admin.reports.customers', array_merge(request()->query(), ['xuat' => 'excel'])) }}">
                                    <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export_report') }}
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== Nội dung phải ===================== --}}
        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="card">
                {{-- Card-header trong suốt, chỉ để đỡ cặp nút Biểu đồ | Danh sách. --}}
                <div class="card-header card-header-primary header_search d-none d-lg-block"
                    style="color: transparent !important; min-height: 35px;">
                    <div id="filterShow" class="col-md-12" style="color: initial;">
                        <div class="d-flex flex-wrap gap-2">
                            <div class="px-1">
                                {{-- v2 khoá tab Khách hàng về dạng BẢNG (lockShowToTable), nên
                                     nút biểu đồ ở đây cũng không bấm được. --}}
                                <input class="me-2 form-check-input" value="chart" type="radio" name="show"
                                    id="show-chart" disabled>
                                <label class="form-check-label text-muted" for="show-chart"
                                    title="Tab Khách hàng chỉ có dạng bảng">{{ __('message.chart') }}</label>
                            </div>
                            <div class="px-1">
                                <input class="me-2 form-check-input" value="table" checked type="radio" name="show" id="show-table">
                                <label class="form-check-label" for="show-table">{{ __('message.list') }}</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center">
                        <div>
                            {{-- h1 chứ không phải div: đây là tiêu đề cấp 1 của trang. --}}
                            <h1 class="fw-bold tieu-de-trang mb-0">{{ __('message.report_customer') }}</h1>
                            <div class="text-sm text-secondary mt-2">{{ __('message.revenue_report_workday_summary') }}</div>
                        </div>
                        <div id="header_title_info" class="p-2 px-3 my-2 my-sm-0 text-center">
                            {{ $ngayVN($filters['from_date']) }} — {{ $ngayVN($filters['to_date']) }}
                            <div class="fw-bold">{{ $filters['days'] }} ngày</div>
                        </div>
                    </div>

                    {{-- Dãy 9 tab của module Thống kê, đúng $reportTypes của v2. --}}
                    <div class="d-flex justify-content-between align-items-end mt-2">
                        <ul class="nav nav-tabs cus-nav-tabs flex-grow-1">
                            @foreach ($C::TAB_THONG_KE as $ma => $nhan)
                                <li class="nav-item">
                                    @if ($ma === 'customer')
                                        <a class="nav-link active" data-type="{{ $ma }}">{{ __('message.'.$nhan) }}</a>
                                    @else
                                        <a class="nav-link chua-dung" data-type="{{ $ma }}"
                                            title="Màn này chưa dựng">{{ __('message.'.$nhan) }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        {{-- Chọn cột: bỏ tick là thêm cột vào ?hide=, tải lại giữ nguyên lựa chọn. --}}
                        <div class="dropup ms-2">
                            <button type="button" class="btn active dropbtn setting-col" href="#">
                                <i class="fa fa-sliders" aria-hidden="true"></i>
                                <div class="dropup-content">
                                    <div class="list_filter">
                                        <div class="form-check">
                                            <input class="form-check-input" data-col="show_all" type="checkbox" id="show_all"
                                                {{ in_array(0, $columns, true) ? '' : 'checked' }}>
                                            <label for="show_all">{{ __('message.all') }}</label>
                                        </div>
                                        @foreach ($C::COT_KHACH as $khoa => $nhan)
                                            <div class="form-check">
                                                <input class="form-check-input show_col" data-col="show_{{ $khoa }}"
                                                    type="checkbox" id="show_{{ $khoa }}"
                                                    {{ $columns['show_'.$khoa] ? 'checked' : '' }}>
                                                <label for="show_{{ $khoa }}">{{ $nhan }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>

                    <div class="row mt-3 list-by-nav">
                        <div class="table-responsive table-border-style">
                            <table class="table-striped table-tk-khach">
                                <tr class="header-table-list">
                                    <th class="text-center">{{ __('message.stt') }}</th>
                                    <th class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ __('message.customer-code') }}</th>
                                    <th class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">{{ __('message.customer-name') }}</th>
                                    <th class="text-left show_name_group {{ $columns['show_name_group'] ? '' : 'hide' }}">{{ __('message.name-group') }}</th>
                                    <th class="text-left show_rank {{ $columns['show_rank'] ? '' : 'hide' }}">{{ __('message.rank') }}</th>
                                    <th class="text-right show_total_expense {{ $columns['show_total_expense'] ? '' : 'hide' }}">
                                        {!! $nutSap('total_expense', __('message.total_expense')) !!}
                                    </th>
                                    <th class="text-right show_price_avg {{ $columns['show_price_avg'] ? '' : 'hide' }}">
                                        {!! $nutSap('price_avg', __('message.price_avg')) !!}
                                    </th>
                                    <th class="text-right show_accumulated_points {{ $columns['show_accumulated_points'] ? '' : 'hide' }}">{{ __('message.accumulated_points') }}</th>
                                    <th class="text-right show_payment {{ $columns['show_payment'] ? '' : 'hide' }}">
                                        {!! $nutSap('payment', __('message.payment')) !!}
                                    </th>
                                    <th class="text-right show_debt {{ $columns['show_debt'] ? '' : 'hide' }}">{{ __('message.debt') }}</th>
                                    <th class="text-center show_total_order {{ $columns['show_total_order'] ? '' : 'hide' }}">
                                        {!! $nutSap('total_order', __('message.total_order')) !!}
                                    </th>
                                </tr>

                                @forelse ($rows as $i => $r)
                                    <tr class="item">
                                        <td class="text-center">{{ $i + 1 }}</td>
                                        <td class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ $r['code'] }}</td>
                                        <td class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">{{ $r['name'] }}</td>
                                        <td class="text-left show_name_group {{ $columns['show_name_group'] ? '' : 'hide' }}">{{ $r['name_group'] }}</td>
                                        <td class="text-left show_rank {{ $columns['show_rank'] ? '' : 'hide' }}">{{ $r['rank'] }}</td>
                                        <td class="text-right la-tien show_total_expense {{ $columns['show_total_expense'] ? '' : 'hide' }}">{{ $tien($r['total_expense']) }} đ</td>
                                        <td class="text-right la-tien show_price_avg {{ $columns['show_price_avg'] ? '' : 'hide' }}">{{ $tien($r['price_avg']) }} đ</td>
                                        <td class="text-right show_accumulated_points {{ $columns['show_accumulated_points'] ? '' : 'hide' }}">{{ $tien($r['accumulated_points']) }}</td>
                                        <td class="text-right la-tien show_payment {{ $columns['show_payment'] ? '' : 'hide' }}">{{ $tien($r['payment']) }} đ</td>
                                        <td class="text-right la-tien show_debt {{ $columns['show_debt'] ? '' : 'hide' }}">{{ $tien($r['debt']) }} đ</td>
                                        <td class="text-center show_total_order {{ $columns['show_total_order'] ? '' : 'hide' }}">{{ $r['total_order'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="tk-rong">Kỳ này chưa có khách nào mua hàng.</td>
                                    </tr>
                                @endforelse

                                @if ($rows)
                                    {{-- Dòng tổng: cộng đúng phần bảng đang bày. --}}
                                    <tr class="dong-tong">
                                        <td class="text-center" colspan="2">Tổng</td>
                                        <td class="text-left show_name {{ $columns['show_name'] ? '' : 'hide' }}">{{ count($rows) }} khách</td>
                                        <td class="show_name_group {{ $columns['show_name_group'] ? '' : 'hide' }}"></td>
                                        <td class="show_rank {{ $columns['show_rank'] ? '' : 'hide' }}"></td>
                                        <td class="text-right la-tien show_total_expense {{ $columns['show_total_expense'] ? '' : 'hide' }}">{{ $tien($tong['total_expense']) }} đ</td>
                                        <td class="text-right la-tien show_price_avg {{ $columns['show_price_avg'] ? '' : 'hide' }}">{{ $tien($tong['price_avg']) }} đ</td>
                                        <td class="text-right show_accumulated_points {{ $columns['show_accumulated_points'] ? '' : 'hide' }}">{{ $tien($tong['accumulated_points']) }}</td>
                                        <td class="text-right la-tien show_payment {{ $columns['show_payment'] ? '' : 'hide' }}">{{ $tien($tong['payment']) }} đ</td>
                                        <td class="text-right la-tien show_debt {{ $columns['show_debt'] ? '' : 'hide' }}">{{ $tien($tong['debt']) }} đ</td>
                                        <td class="text-center show_total_order {{ $columns['show_total_order'] ? '' : 'hide' }}">{{ $tong['total_order'] }}</td>
                                    </tr>
                                @endif
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // ---------- Chọn cột: cột đang tắt ghi vào ?hide= rồi tải lại ----------
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

        // Tab của màn chưa dựng: chặn hẳn lượt bấm.
        $(document).on('click', '.cus-nav-tabs .chua-dung', (e) => e.preventDefault());

        // ---------- Bộ lọc ----------
        // Ô chọn thì đọc lại số ngay; hai ô ngày thì chờ bấm "Xem báo cáo" —
        // đúng như v2, vì chọn xong ngày bắt đầu mà đã chạy thì kỳ đang dở dang.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai(bo) {
            const q = new URLSearchParams();
            ['range', 'from_date', 'to_date', 'keyword', 'shop_id', 'limit'].forEach((ten) => {
                if (bo && bo.includes(ten)) return;
                const $o = oLoc(ten);
                const v = String(($o.is(':radio') ? $o.filter(':checked') : $o).val() || '').trim();
                if (v) q.set(ten, v);
            });

            const cu = new URLSearchParams(location.search);
            ['hide', 'sort_field', 'sort_type'].forEach((ten) => { if (cu.get(ten)) q.set(ten, cu.get(ten)); });

            V2.napLai(location.pathname + '?' + q);
        }

        let timerTim = null;
        $(document).on('change', '.fillter-box select', () => locLai());
        $(document).on('input', '.fillter-box input[name="keyword"]', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(() => locLai(), 400);
        });
        // Bấm nút xem nhanh: dùng preset, BỎ khoảng ngày đang giữ trên URL —
        // không thì hai thứ chọi nhau và khoảng cũ thắng.
        $(document).on('change', '.chon-ky', () => locLai(['from_date', 'to_date']));
        $(document).on('submit', '#search-form', function (e) {
            e.preventDefault();
            locLai(['range']);
        });

        // Hai ô ngày dùng lịch một ngày của v2, khuôn YYYY-MM-DD để gửi thẳng lên.
        $('#fromDate, #toDate').each(function () {
            $(this).daterangepicker({
                singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
                locale: V2.lichVN(),
            }, function (start) {
                $(this.element).val(start.format('YYYY-MM-DD'));
                // Tự chọn ngày là bỏ nút xem nhanh đang sáng.
                $('.chon-ky').prop('checked', false);
            });
        });
    </script>
@endpush
