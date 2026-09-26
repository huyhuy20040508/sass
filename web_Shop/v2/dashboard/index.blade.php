{{-- Tổng quan — dựng lại theo màn cùng tên của bản v2 (dashboard).

     Khác bản gốc một điểm có chủ ý: trang này dựng SẴN ở máy chủ chứ không để
     trống rồi gọi bốn lượt AJAX lấp vào. Mọi màn v2 đã port trong dự án đều lọc
     bằng tham số trên URL — một màn riêng chạy kiểu khác là hai lối đi cho cùng
     một việc, và bộ kiểm cũng không soi được nội dung bảng.

     Biểu đồ vẫn cần Chart.js, nạp riêng ở đây: layout v2 cố ý không nạp sẵn vì
     tới giờ chưa màn nào vẽ biểu đồ (xem chú thích trong layouts/master). --}}
@extends('v2::layouts.master')

@section('title', 'Tổng quan')

@push('styles')
    <link href="{{ asset('v2/css/custom-dashboard.css') }}" rel="stylesheet">
@endpush

@php
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '';

    // Ô KPI rút gọn số từ một tỷ trở lên — cùng cách với bốn ô quỹ của màn Thu
    // chi, để hai màn không in cùng một con số theo hai kiểu.
    $tienGon = function ($n) {
        $n = (float) $n;
        if (abs($n) < 1e9) {
            return number_format($n, 0, ',', '.');
        }
        $so = rtrim(rtrim(number_format(abs($n) / 1e9, 3, ',', '.'), '0'), ',');

        return ($n < 0 ? '~ -' : '~ ').$so.' '.__('message.billion');
    };

    $PAY = \App\Http\Controllers\OrderController::PAYMENT_METHODS;
    $CHANNEL = \App\Http\Controllers\OrderController::CHANNELS ?? [];

    // Nhãn của một "lát" báo cáo: API trả khoá máy (cash, bank_transfer…), bảng
    // phải in chữ người đọc được. Không tra ra thì in nguyên khoá còn hơn bỏ trống.
    $nhanThanhToan = fn ($k) => $PAY[$k] ?? ($k !== '' ? $k : __('message.other'));
    $nhanNguon = fn ($k) => $CHANNEL[$k] ?? ($k !== '' ? $k : __('message.other'));

    // Số liệu đổ sang JS. Dựng ở đây rồi @json một biến trần: @json với một mảng
    // viết thẳng nhiều dòng làm Blade cắt nhầm biểu thức và cả trang không biên
    // dịch được (cùng cách làm với màn Thu chi).
    $duLieuJs = [
        'chart' => $chart,
        'thanhToan' => array_map(
            fn ($r) => ['nhan' => $nhanThanhToan($r['nhan']), 'tien' => (float) ($r['revenue'] ?? 0)],
            $theoThanhToan
        ),
        'nguon' => array_map(
            fn ($r) => ['nhan' => $nhanNguon($r['nhan']), 'don' => (int) ($r['orders'] ?? 0)],
            $theoNguon
        ),
        'chu' => [
            'gop' => __('message.gross-revenue'),
            'thuan' => __('message.net-revenue'),
            'von' => __('message.cost-of-goods-purchased'),
            'don' => __('message.order'),
        ],
    ];
@endphp

@section('content')
    <div class="row">
        {{-- ====================== KHUNG LỌC BÊN TRÁI ====================== --}}
        <div class="col-12 col-md-2 pe-lg-0 fillter-box-container">
            {{-- CHI NHÁNH — không phải bộ lọc riêng mà là chi nhánh đang làm việc
                 của TAB này, cùng thứ dropdown ba gạch trên thanh đầu trang đổi.
                 Cửa hàng một chi nhánh thì không bày: ô một lựa chọn không lọc
                 được gì (xem màn Thu chi, cùng lý do). --}}
            @if (count($chiNhanh['ds']) > 1)
                <div id="branchDashboardTarget" class="fillter-box">
                    <div class="card inner-modal-in-mobile">
                        <div class="card-header header_search">{{ __('message.branch') }}</div>
                        <div class="card-body px-2">
                            <select class="form-control form-select w-100" id="db-branch">
                                @foreach ($chiNhanh['ds'] as $cn)
                                    <option value="{{ $cn['id'] }}"
                                        {{ (int) $chiNhanh['dangChon'] === (int) $cn['id'] ? 'selected' : '' }}>
                                        {{ $cn['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            <div id="filterDashboardTarget" class="fillter-box">
                <div class="card inner-modal-in-mobile">
                    <div class="card-header header_search">{{ __('message.time') }}</div>
                    <div class="card-body px-2">
                        {{-- Form GET: chọn kỳ là nạp lại trang với ?range= hoặc ?from=&to=.
                             Hai ô ngày và nhóm nút loại trừ nhau — gửi kèm cả hai thì
                             controller không biết người dùng vừa đổi cái nào. --}}
                        <form method="GET" action="{{ route('admin.dashboard') }}" id="db-filter">
                            @foreach (['top_products', 'top_payment', 'top_origin', 'top_promo', 'top_branch'] as $o)
                                <input type="hidden" name="{{ $o }}" value="{{ $filters[$o] }}">
                            @endforeach

                            {{-- Ô chữ + daterangepicker, KHÔNG phải input[type=date]: lịch
                                 của trình duyệt in theo ngôn ngữ máy khách nên cùng một
                                 trang tiếng Việt lại hiện 09/26/2026. Vỏ v2 đã khai sẵn
                                 bộ chữ tiếng Việt (V2.lichVN) cho cả khu. --}}
                            <div class="row filter-form g-1 mb-2">
                                <div class="col-6">
                                    <input type="text" class="form-control form-control-sm" name="from" id="db-from"
                                        autocomplete="off" value="{{ $ngayVN($filters['from']) }}"
                                        placeholder="{{ __('message.from_date') }}"
                                        aria-label="{{ __('message.from_date') }}">
                                </div>
                                <div class="col-6">
                                    <input type="text" class="form-control form-control-sm" name="to" id="db-to"
                                        autocomplete="off" value="{{ $ngayVN($filters['to']) }}"
                                        placeholder="{{ __('message.to_date') }}"
                                        aria-label="{{ __('message.to_date') }}">
                                </div>
                            </div>

                            @foreach ($rangeGroups as $tieuDe => $maDS)
                                <div class="mb-2">
                                    <span class="title_search">{{ $tieuDe }}</span>
                                    @foreach ($maDS as $ma)
                                        <div class="form-check">
                                            <input class="form-check-input db-range" type="radio" name="range"
                                                value="{{ $ma }}" id="db-range-{{ $ma }}"
                                                {{ $filters['range'] === $ma ? 'checked' : '' }}>
                                            <label class="form-check-label" for="db-range-{{ $ma }}">
                                                {{ $rangeLabels[$ma] }}
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach

                            {{-- Không JS thì vẫn lọc được: nút này là đường đi duy nhất
                                 của bàn phím, JS chỉ làm nó thành thừa. --}}
                            <button type="submit" class="btn btn-sm btn-primary w-100 mt-1">
                                {{ __('message.search') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- ====================== NỘI DUNG ====================== --}}
        <div class="col-12 col-lg-10 wrapper-content-dashboard-middle">
            <div class="content_dashboard_mid">
                {{-- Tiêu đề trang: mọi màn v2 dùng chung lớp .tieu-de-trang để cỡ
                     chữ không lệch dần giữa các màn (xem V2PageTitleTest). --}}
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">{{ __('message.overview') }}</h1>
                    <span class="text-muted small">{{ $filters['describe'] }}</span>
                </div>

                <div class="card-header">
                    <div class="row g-2 mb-2">
                        <div class="col-6 col-md-2">
                            <div class="box_asset h-100 position-relative justify-content-start">
                                <span class="position-absolute top-0 end-0 mx-1 cursor-pointer" data-bs-toggle="tooltip"
                                    data-bs-placement="left" title="{{ __('message.gross_revenue_formula') }}">
                                    <i class="bi bi-info-circle-fill text-muted"></i>
                                </span>
                                <h3>{{ __('message.gross-revenue') }}</h3>
                                <div class="box_asset_midd">
                                    <span class="grossRevenue" title="{{ $tien($kpi['gross']) }} đ">{{ $tienGon($kpi['gross']) }} đ</span>
                                    <img src="{{ asset('v2/images/ic_revenue.png') }}" alt="" width="64" height="64">
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-2">
                            <div class="box_asset h-100 position-relative justify-content-start">
                                <span class="position-absolute top-0 end-0 mx-1 cursor-pointer" data-bs-toggle="tooltip"
                                    data-bs-placement="left" title="{{ __('message.net_revenue_formula') }}">
                                    <i class="bi bi-info-circle-fill text-muted"></i>
                                </span>
                                <h3>{{ __('message.net-revenue') }}</h3>
                                <div class="box_asset_midd">
                                    <span class="netRevenue" title="{{ $tien($kpi['net']) }} đ">{{ $tienGon($kpi['net']) }} đ</span>
                                    <img src="{{ asset('v2/images/ic_net_revenue.png') }}" alt="" width="64" height="64">
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-2">
                            <div class="box_asset h-100 position-relative justify-content-start">
                                <span class="position-absolute top-0 end-0 mx-1 cursor-pointer" data-bs-toggle="tooltip"
                                    data-bs-placement="left" title="{{ __('message.estimated_revenue_formula') }}">
                                    <i class="bi bi-info-circle-fill text-muted"></i>
                                </span>
                                <h3>{{ __('message.estimated-revenue') }}</h3>
                                <div class="box_asset_midd">
                                    <span class="estimatedRevenue" title="{{ $tien($kpi['estimated']) }} đ">{{ $tienGon($kpi['estimated']) }} đ</span>
                                    <img src="{{ asset('v2/images/ic_estimated_revenue.svg') }}" alt="" width="64" height="64">
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-2">
                            <div class="box_asset h-100 position-relative justify-content-start">
                                <span class="position-absolute top-0 end-0 mx-1 cursor-pointer" data-bs-toggle="tooltip"
                                    data-bs-placement="left"
                                    title="{{ __('message.includes_unpaid_credit_partial_orders') }}">
                                    <i class="bi bi-info-circle-fill text-muted"></i>
                                </span>
                                <h3>{{ __('message.sales-orders') }}</h3>
                                <div class="box_asset_midd">
                                    <span class="countOrder">{{ $tien($kpi['orders']) }}</span>
                                    <img src="{{ asset('v2/images/ic_order.png') }}" alt="" width="64" height="64">
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-2">
                            <div class="box_asset h-100 position-relative justify-content-start">
                                <span class="position-absolute top-0 end-0 mx-1 cursor-pointer" data-bs-toggle="tooltip"
                                    data-bs-placement="left"
                                    title="{{ __('message.includes_unpaid_and_paid_vouchers') }}{{ $kpi['purchase_sampled'] ? ' — kỳ này nhiều phiếu, số đang tính trên '.\App\Http\Controllers\DashboardController::MAX_PURCHASE_PAGES.' trang đầu' : '' }}">
                                    <i class="bi bi-info-circle-fill text-muted"></i>
                                </span>
                                <h3>{{ __('message.cost-of-goods-purchased') }}</h3>
                                <div class="box_asset_midd">
                                    <span class="revenuePchOrder" title="{{ $tien($kpi['purchase_cost']) }} đ">{{ $tienGon($kpi['purchase_cost']) }} đ</span>
                                    <img src="{{ asset('v2/images/ic_trading.png') }}" alt="" width="64" height="64">
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-2">
                            <div class="box_asset h-100 position-relative justify-content-start">
                                <span class="position-absolute top-0 end-0 mx-1 cursor-pointer" data-bs-toggle="tooltip"
                                    data-bs-placement="left" title="{{ __('message.stock_in_quantity_formula') }}">
                                    <i class="bi bi-info-circle-fill text-muted"></i>
                                </span>
                                <h3>{{ __('message.quantity_in_stock') }}</h3>
                                <div class="box_asset_midd">
                                    <span class="pchOrderCount">{{ $tien($kpi['purchase_qty']) }}</span>
                                    <img src="{{ asset('v2/images/ic_inventory.png') }}" alt="" width="64" height="64">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    {{-- ---------- Hàng đầu tiên ---------- --}}
                    <div class="row g-2 mb-2">
                        <div class="col-12 col-md-3 mb-3 mb-md-0">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex justify-content-between">
                                    <h6 class="mb-0 fw-bold">{{ __('message.current_shift') }}</h6>
                                    <span class="total_quantity">
                                        @if ($ca)
                                            {{ __('message.cash_orders') }}: {{ $tien($ca['so_don']) }}
                                        @endif
                                    </span>
                                </div>
                                <div class="chart-body d-flex p-2 db-shift-body">
                                    <div id="list-shift-details" class="w-100">
                                        @if ($ca)
                                            <div class="list-history-shift active mb-3 pb-2 border-bottom db-shift-row">
                                                <div><span class="fw-bold">{{ __('message.branch') }}:</span>
                                                    {{ $ca['chi_nhanh'] !== '' ? $ca['chi_nhanh'] : '—' }}</div>
                                                <div><span class="fw-bold">{{ __('message.opened_by') }}:</span>
                                                    {{ $ca['nguoi_mo'] !== '' ? $ca['nguoi_mo'] : '—' }}</div>
                                                <div><span class="fw-bold">{{ __('message.shift_code') }}:</span>
                                                    {{ $ca['ma'] }}</div>
                                                <div><span class="fw-bold">{{ __('message.shift_open_time') }}:</span>
                                                    {{ $ca['gio_mo'] ? date('d-m-Y H:i:s', strtotime($ca['gio_mo'])) : '—' }}</div>
                                                <div><span class="fw-bold">{{ __('message.shift_close_time') }}:</span>
                                                    @if ($ca['gio_dong'])
                                                        {{ date('d-m-Y H:i:s', strtotime($ca['gio_dong'])) }}
                                                    @else
                                                        <span class="fw-bold text_success">{{ __('message.open') }}</span>
                                                    @endif
                                                </div>
                                                <div><span class="fw-bold">{{ __('message.total_cash') }}:</span>
                                                    {{ $tien($ca['tien_mat']) }} đ</div>
                                            </div>
                                        @else
                                            <p class="text-center noti-error-shift fw-bold alert alert-danger mb-0">
                                                {{ __('message.no_shift_selected') }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 mb-3 mb-md-0">
                            <div class="div-chart h-100">
                                <div class="chart-header">
                                    <h6 class="mb-0 fw-bold">{{ __('message.sales_revenue') }}</h6>
                                </div>
                                <div class="chart-body p-2">
                                    <canvas id="myChart1" height="250"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-3 mb-3 mb-md-0">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_best_selling_products') }}</h6>
                                    <select class="form-select form-select-sm w-auto db-top" id="quantityBestSeller"
                                        data-param="top_products">
                                        @foreach ($topChoices as $n)
                                            <option value="{{ $n }}" {{ $filters['top_products'] === $n ? 'selected' : '' }}>
                                                Top {{ $n }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="chart-body p-0">
                                    <div class="table-responsive mb-2 overflow-y-auto db-top-scroll">
                                        <table class="table table-sm table-hover border-0 mb-0 db-table">
                                            <thead class="sticky-top z-0">
                                                <tr>
                                                    <th class="db-c-stt">{{ __('message.stt') }}</th>
                                                    <th class="text-start db-c-name">{{ __('message.goods_sold') }}</th>
                                                    <th class="text-end db-c-val">{{ __('message.quantity') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bestSell">
                                                @forelse ($banChay as $i => $sp)
                                                    <tr>
                                                        <td class="db-c-stt">{{ $i + 1 }}</td>
                                                        <td class="text-start db-c-name" title="{{ $sp['name'] ?? '' }}">
                                                            {{ $sp['name'] ?? '—' }}</td>
                                                        <td class="text-end db-c-val">{{ $tien($sp['units'] ?? 0) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="3" class="text-center text-muted py-3">
                                                            {{ __('message.no_data') }}</td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ---------- Hàng thứ hai ---------- --}}
                    <div class="row g-2">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_payment_methods') }}</h6>
                                    <select class="form-select form-select-sm w-auto db-top" id="byPaymentMethod"
                                        data-param="top_payment">
                                        @foreach ($topChoices as $n)
                                            <option value="{{ $n }}" {{ $filters['top_payment'] === $n ? 'selected' : '' }}>
                                                Top {{ $n }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="chart-body p-2">
                                    @if (count($theoThanhToan))
                                        <canvas id="myChart2" height="250"></canvas>
                                    @else
                                        <p class="text-center text-muted my-4">{{ __('message.no_data') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_order_sources') }}</h6>
                                    <select class="form-select form-select-sm w-auto db-top" id="byOrigin"
                                        data-param="top_origin">
                                        @foreach ($topChoices as $n)
                                            <option value="{{ $n }}" {{ $filters['top_origin'] === $n ? 'selected' : '' }}>
                                                Top {{ $n }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="chart-body p-2">
                                    @if (count($theoNguon))
                                        <canvas id="myChart3" height="250"></canvas>
                                    @else
                                        <p class="text-center text-muted my-4">{{ __('message.no_data') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_promotions') }}</h6>
                                    <select class="form-select form-select-sm w-auto db-top" id="byPromo"
                                        data-param="top_promo">
                                        @foreach ($topChoices as $n)
                                            <option value="{{ $n }}" {{ $filters['top_promo'] === $n ? 'selected' : '' }}>
                                                Top {{ $n }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="chart-body p-2">
                                    {{-- API chưa có sổ đếm lượt dùng từng chương trình khuyến
                                         mại, nên thẻ này nói thẳng là chưa có số thay vì vẽ
                                         một biểu đồ rỗng trông như "tháng này không ai dùng". --}}
                                    <p class="text-center text-muted my-4">{{ __('message.no_data') }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_gross_revenue_by_branch') }}</h6>
                                    <select class="form-select form-select-sm w-auto db-top" id="byBranch"
                                        data-param="top_branch">
                                        @foreach ($topChoices as $n)
                                            <option value="{{ $n }}" {{ $filters['top_branch'] === $n ? 'selected' : '' }}>
                                                Top {{ $n }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="chart-body p-0">
                                    <div class="table-responsive mb-2 overflow-y-auto db-top-scroll">
                                        <table class="table table-sm table-hover border-0 mb-0 db-table db-table--money">
                                            <thead class="sticky-top z-0">
                                                <tr>
                                                    <th class="db-c-stt">{{ __('message.stt') }}</th>
                                                    <th class="text-start db-c-name">{{ __('message.branch_name') }}</th>
                                                    <th class="text-end db-c-val">{{ __('message.revenue') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @forelse ($theoChiNhanh as $i => $cn)
                                                    <tr>
                                                        <td class="db-c-stt">{{ $i + 1 }}</td>
                                                        <td class="text-start db-c-name" title="{{ $cn['nhan'] }}">
                                                            {{ $cn['nhan'] }}</td>
                                                        <td class="text-end db-c-val"
                                                            title="{{ $tien($cn['revenue'] ?? 0) }} đ">
                                                            {{ $tienGon($cn['revenue'] ?? 0) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="3" class="text-center text-muted py-3">
                                                            {{ __('message.no_data') }}</td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Khối ca + hai bảng Top cao bằng nhau với ô biểu đồ bên cạnh (250px). */
        /* Số tiền KPI giữ trên MỘT dòng: "148.213.100" rớt xuống dòng thành
           "148.213.100 / đ" đọc như hai con số. Ô hẹp thì chữ nhỏ lại, và icon
           nhường chỗ cho con số chứ không phải ngược lại.

           Phải viết `div.box_asset_midd` cho đủ trọng số: luật gốc trong
           cashier-bundle.css cũng khai kèm `div`, khai mỏng hơn là không đè được. */
        div.box_asset_midd { gap: 6px; }
        div.box_asset_midd span { white-space: nowrap; font-size: clamp(14px, 1.15vw, 20px); }
        div.box_asset_midd img { flex: 0 0 auto; width: 48px; height: 48px; }

        .db-shift-body { flex-direction: column; overflow-y: auto; max-height: 250px; }
        .db-shift-row { font-size: 14px; display: flex; flex-direction: column; gap: 5px; }
        .db-top-scroll { max-height: 250px; min-height: 250px; }

        /* Cột lọc chỉ rộng ~150px: hai ô ngày cạnh nhau phải nhỏ chữ mới đủ chỗ
           cho khuôn dd-mm-yyyy, không thì ngày bị cắt mất phần năm. */
        #db-from, #db-to { font-size: 12px; padding-left: 6px; padding-right: 6px; }

        /* Bảng trong thẻ hẹp (thẻ chỉ rộng ~270px ở khổ 1366): chia phần trăm đủ
           100 và cắt "…" ở cột tên, KHÔNG để `auto` tự nới rồi tràn ra ngoài thẻ. */
        .db-table { table-layout: fixed; width: 100%; }
        .db-table td { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        /* Tiêu đề cột được xuống dòng, ô dữ liệu thì không: cắt "SỐ LƯỢNG" thành
           "SỐ LƯỢ…" là mất tên cột, còn thẻ chỉ rộng ~260px ở khổ 1366. */
        .db-table th { white-space: normal; line-height: 1.2; }
        .db-table .db-c-stt { width: 15%; }
        .db-table .db-c-name { width: 55%; }
        .db-table .db-c-val { width: 30%; }
        /* Bảng chi nhánh in TIỀN ở cột phải: cắt "…" giữa con số là đọc sai số,
           nên cột đó phải rộng hơn, đổi lại tên chi nhánh chịu cắt. */
        .db-table--money .db-c-name { width: 45%; }
        .db-table--money .db-c-val { width: 40%; }
    </style>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const soLieu = @json($duLieuJs);

            const MAU = ['#4bc0c0', '#36a2eb', '#ff6384', '#ffcd56', '#9966ff', '#c9cbcf', '#2ecc71'];
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(v);

            // Kỳ dài thì nhãn trục X dày đặc; Chart.js tự bỏ bớt nhãn chứ không
            // xoay chữ, nên chỉ cần nói nó giữ nguyên thứ tự mốc của máy chủ.
            const ve = (id, cau) => {
                const o = document.getElementById(id);
                if (o) new Chart(o.getContext('2d'), cau);
            };

            const c = soLieu.chart;
            ve('myChart1', {
                type: c.labels.length < 5 ? 'bar' : 'line',
                data: {
                    labels: c.labels,
                    datasets: [
                        { label: soLieu.chu.gop, data: c.gross, borderColor: MAU[0], backgroundColor: MAU[0], tension: .3 },
                        { label: soLieu.chu.thuan, data: c.net, borderColor: MAU[1], backgroundColor: MAU[1], tension: .3 },
                        { label: soLieu.chu.von, data: c.cost, borderColor: MAU[2], backgroundColor: MAU[2], tension: .3 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                        tooltip: { callbacks: { label: (x) => `${x.dataset.label}: ${tien(x.parsed.y)} đ` } },
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: (v) => tien(v) + ' đ' } } },
                },
            });

            const tron = (id, ds, khoa, dinhDang) => {
                if (!ds.length) return;
                ve(id, {
                    type: 'doughnut',
                    data: {
                        labels: ds.map((r) => r.nhan),
                        datasets: [{ data: ds.map((r) => r[khoa]), backgroundColor: MAU }],
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                            tooltip: { callbacks: { label: (x) => `${x.label}: ${dinhDang(x.parsed)}` } },
                        },
                    },
                });
            };

            tron('myChart2', soLieu.thanhToan, 'tien', (v) => tien(v) + ' đ');
            tron('myChart3', soLieu.nguon, 'don', (v) => tien(v) + ' ' + soLieu.chu.don);

            // Đổi kỳ / đổi Top là nạp lại trang: mọi màn v2 khác lọc bằng tham số
            // trên URL, giữ nguyên cách đó thì bấm F5 hay gửi link đều ra cùng một trang.
            const form = document.getElementById('db-filter');
            document.querySelectorAll('.db-range').forEach((o) => o.addEventListener('change', () => {
                // Chọn mốc thì bỏ khoảng tự chọn, nếu không hai thứ cùng gửi lên.
                // `disabled` chứ không chỉ xoá giá trị: ô rỗng vẫn được gửi và
                // để lại `?from=&to=` rỗng trên thanh địa chỉ.
                ['db-from', 'db-to'].forEach((id) => {
                    const o = document.getElementById(id);
                    o.value = '';
                    o.disabled = true;
                });
                form.submit();
            }));

            document.querySelectorAll('.db-top').forEach((o) => o.addEventListener('change', () => {
                const u = new URL(window.location.href);
                u.searchParams.set(o.dataset.param, o.value);
                window.location.href = u.toString();
            }));

            // Lịch tiếng Việt cho hai ô ngày, y như các màn v2 khác. Chọn ngày
            // xong thì bỏ mốc đang tích: hai thứ cùng gửi lên là controller
            // không biết người dùng vừa đổi cái nào.
            if (window.jQuery && $.fn.daterangepicker) {
                ['#db-from', '#db-to'].forEach(function (sel) {
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
                        document.querySelectorAll('.db-range').forEach((r) => (r.checked = false));
                    });
                });
            }

            const cn = document.getElementById('db-branch');
            if (cn) cn.addEventListener('change', () => window.V2 && V2.doiChiNhanhTab(cn.value));
        })();
    </script>
@endpush
