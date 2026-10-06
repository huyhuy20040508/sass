{{-- Tổng quan — dựng lại theo màn cùng tên của bản v2 (dashboard), giữ nguyên
     bố cục cũ (khung lọc trái, sáu ô KPI, hàng ca / doanh thu / bán chạy, bốn
     thẻ Top) và bồi thêm: so kỳ trước, lợi nhuận gộp, mục tiêu tháng, giờ cao
     điểm, thẻ cần chú ý, bấm số để mở màn chi tiết.

     Khác bản gốc một điểm có chủ ý: trang này dựng SẴN ở máy chủ chứ không để
     trống rồi gọi AJAX lấp vào. Mọi màn v2 đã port trong dự án đều lọc bằng
     tham số trên URL — một màn riêng chạy kiểu khác là hai lối đi cho cùng một
     việc, và bộ kiểm cũng không soi được nội dung bảng.

     Biểu đồ vẫn cần Chart.js, nạp riêng ở đây: layout v2 cố ý không nạp sẵn. --}}
@extends('v2::layouts.master')

@section('title', 'Tổng quan')

@push('styles')
    <link href="{{ asset('v2/css/custom-dashboard.css') }}" rel="stylesheet">
@endpush

@php
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $dmy = fn ($ymd) => date('d-m-Y', strtotime($ymd));

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
    $phanTram = fn ($p) => rtrim(rtrim(number_format(abs($p), 1, ',', '.'), '0'), ',').'%';

    $soSanh = function (?array $so) use ($phanTram) {
        if ($so === null) {
            return null;
        }
        if ($so['moi']) {
            return ['chu' => '▲ Mới · kỳ trước 0', 'lop' => 'db-up'];
        }

        return $so['pct'] >= 0
            ? ['chu' => '▲ '.$phanTram($so['pct']).' so với kỳ trước', 'lop' => 'db-up']
            : ['chu' => '▼ '.$phanTram($so['pct']).' so với kỳ trước', 'lop' => 'db-down'];
    };

    $ky = ['from_date' => $dmy($filters['from']), 'to_date' => $dmy($filters['to'])];

    $oKpi = [
        [
            'nhan' => __('message.gross-revenue'), 'so' => $tienGon($kpi['gross']).' đ', 'day_du' => $tien($kpi['gross']).' đ',
            'tip' => __('message.gross_revenue_formula'), 'so_sanh' => $soSanh($kpi['so']['gross']), 'phu' => null,
            'icon' => 'ic_revenue.png', 'mau' => '#3b82f6', 'link' => route('admin.reports.sales', $ky),
        ],
        [
            'nhan' => __('message.net-revenue'), 'so' => $tienGon($kpi['net']).' đ', 'day_du' => $tien($kpi['net']).' đ',
            'tip' => __('message.net_revenue_formula'), 'so_sanh' => $soSanh($kpi['so']['net']),
            'phu' => 'Giảm giá −'.$tien($kpi['gross'] - $kpi['net']),
            'icon' => 'ic_net_revenue.png', 'mau' => '#6366f1', 'link' => route('admin.reports.sales', $ky),
        ],
        [
            'nhan' => 'Lợi nhuận gộp', 'so' => $tienGon($kpi['profit']).' đ', 'day_du' => $tien($kpi['profit']).' đ',
            'tip' => 'Lợi nhuận gộp = Tiền hàng − Giảm giá − Giá vốn hàng đã bán (không tính phí vận chuyển)',
            'so_sanh' => $soSanh($kpi['so']['profit']),
            'phu' => $kpi['margin'] !== null ? 'Biên lãi '.$phanTram($kpi['margin']) : null,
            'icon' => 'ic_estimated_revenue.svg', 'mau' => '#16a34a', 'link' => route('admin.reports.profit', $ky),
        ],
        [
            'nhan' => __('message.sales-orders'), 'so' => $tien($kpi['orders']), 'day_du' => $tien($kpi['orders']).' đơn',
            'tip' => __('message.includes_unpaid_credit_partial_orders'), 'so_sanh' => $soSanh($kpi['so']['orders']),
            'phu' => $kpi['orders'] > 0 ? 'TB '.$tien($kpi['aov']).' đ/đơn' : null,
            'icon' => 'ic_order.png', 'mau' => '#0ea5e9', 'link' => route('admin.orders.index', $ky),
        ],
        [
            'nhan' => __('message.cost-of-goods-purchased'), 'so' => $tienGon($kpi['purchase_cost']).' đ', 'day_du' => $tien($kpi['purchase_cost']).' đ',
            'tip' => __('message.includes_unpaid_and_paid_vouchers').($kpi['purchase_sampled'] ? ' — kỳ này nhiều phiếu, số đang tính trên '.\App\Http\Controllers\DashboardController::MAX_PURCHASE_PAGES.' trang đầu' : ''),
            'so_sanh' => null, 'phu' => 'Phiếu mua đã duyệt',
            'icon' => 'ic_trading.png', 'mau' => '#ef4444', 'link' => route('admin.phieu-mua-hang.index', $ky),
        ],
        [
            'nhan' => __('message.quantity_in_stock'), 'so' => $tien($kpi['purchase_qty']), 'day_du' => $tien($kpi['purchase_qty']),
            'tip' => __('message.stock_in_quantity_formula'), 'so_sanh' => null, 'phu' => 'sản phẩm',
            'icon' => 'ic_inventory.png', 'mau' => '#10b981', 'link' => route('admin.phieu-mua-hang.index', $ky),
        ],
    ];

    $PAY = \App\Http\Controllers\OrderController::PAYMENT_METHODS;
    $CHANNEL = \App\Http\Controllers\OrderController::CHANNELS ?? [];

    // Nhãn của một "lát" báo cáo: API trả khoá máy (cash, bank_transfer…), bảng
    // phải in chữ người đọc được. Không tra ra thì in nguyên khoá còn hơn bỏ trống.
    $nhanThanhToan = fn ($k) => $PAY[$k] ?? ($k !== '' ? $k : __('message.other'));
    $nhanNguon = fn ($k) => $CHANNEL[$k] ?? ($k !== '' ? $k : __('message.other'));

    $MAU = ['#3b82f6', '#f97316', '#2bb5b5', '#a855f7', '#eab308', '#ef4444', '#64748b', '#10b981', '#ec4899', '#0ea5e9', '#84cc16', '#f43f5e', '#8b5cf6', '#14b8a6', '#f59e0b'];
    $vong = function (array $ds, string $khoa, callable $nhan, callable $giaTri) use ($MAU, $phanTram) {
        $tong = array_sum(array_map(fn ($r) => (float) ($r[$khoa] ?? 0), $ds));

        return array_map(fn ($r, $i) => [
            'nhan' => $nhan($r['nhan']),
            'gia_tri' => (float) ($r[$khoa] ?? 0),
            'chu' => $giaTri($r),
            'pt' => $tong > 0 ? $phanTram((float) ($r[$khoa] ?? 0) / $tong * 100) : '',
            'mau' => $MAU[$i % count($MAU)],
        ], $ds, array_keys($ds));
    };
    $vongThanhToan = $vong($theoThanhToan, 'revenue', $nhanThanhToan, fn ($r) => $tien($r['revenue'] ?? 0).' đ');
    $vongNguon = $vong($theoNguon, 'orders', $nhanNguon, fn ($r) => $tien($r['orders'] ?? 0).' đơn');
    $maxChiNhanh = max([1, ...array_map(fn ($r) => (float) ($r['revenue'] ?? 0), $theoChiNhanh)]);
    $maxGio = max([1, ...array_column($gioCaoDiem['gio'], 'don')]);

    // Số liệu đổ sang JS. Dựng ở đây rồi @json một biến trần: @json với một mảng
    // viết thẳng nhiều dòng làm Blade cắt nhầm biểu thức và cả trang không biên
    // dịch được (cùng cách làm với màn Thu chi).
    $duLieuJs = [
        'chart' => $chart,
        'thanhToan' => $vongThanhToan,
        'nguon' => $vongNguon,
        'linkDon' => route('admin.orders.index'),
        'chu' => [
            'gop' => __('message.gross-revenue'),
            'thuan' => __('message.net-revenue'),
            'von' => 'Giá vốn',
        ],
    ];

    $topSelect = function (string $id, string $param) use ($filters, $topChoices) {
        return ['id' => $id, 'param' => $param, 'chon' => $filters[$param], 'ds' => $topChoices];
    };

    $theVong = [
        ['id' => 'myChart2', 'tieu_de' => __('message.top_payment_methods'), 'sel' => $topSelect('byPaymentMethod', 'top_payment'), 'ds' => $vongThanhToan, 'giua' => $tien($kpi['orders']), 'giua_phu' => 'đơn'],
        ['id' => 'myChart3', 'tieu_de' => __('message.top_order_sources'), 'sel' => $topSelect('byOrigin', 'top_origin'), 'ds' => $vongNguon, 'giua' => $tien(array_sum(array_column($vongNguon, 'gia_tri'))), 'giua_phu' => 'đơn'],
    ];
@endphp

@section('content')
    {{-- Nút mở khung lọc trên điện thoại — khung trái ẩn ở khổ hẹp. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @if (count($chiNhanh['ds']) > 1)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => 'branchDashboardTarget',
                    'modalLabel' => __('message.branch'),
                ])
            @endif
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterDashboardTarget',
                'modalLabel' => __('message.time'),
            ])
        </div>
    </div>

    <div class="row">
        {{-- ====================== KHUNG LỌC BÊN TRÁI ====================== --}}
        <div class="col-12 col-md-2 pe-lg-0 fillter-box-container">
            {{-- CHI NHÁNH — bộ lọc riêng của màn này (xem DashboardController::filters),
                 KHÔNG đổi chi nhánh làm việc của tab. Cửa hàng một chi nhánh thì
                 không bày: ô một lựa chọn không lọc được gì. --}}
            @if (count($chiNhanh['ds']) > 1)
                <div id="branchDashboardTarget" class="fillter-box">
                    <div class="card inner-modal-in-mobile db-loc">
                        <div class="card-header header_search">{{ __('message.branch') }}</div>
                        <div class="card-body px-2">
                            <select class="form-control form-select w-100" id="db-branch">
                                <option value="0" {{ $filters['branch'] === 0 ? 'selected' : '' }}>{{ __('message.all') }}</option>
                                @foreach ($chiNhanh['ds'] as $cn)
                                    <option value="{{ $cn['id'] }}"
                                        {{ $filters['branch'] === (int) $cn['id'] ? 'selected' : '' }}>
                                        {{ $cn['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            <div id="filterDashboardTarget" class="fillter-box">
                <div class="card inner-modal-in-mobile db-loc">
                    <div class="card-header header_search d-flex justify-content-between align-items-center">
                        <span>{{ __('message.time') }}</span>
                        {{-- Nhớ kỳ: mở lại Tổng quan vẫn đứng ở kỳ đã chọn. Giữ ở trình
                             duyệt (localStorage) — là thói quen của từng máy, không phải
                             cấu hình của cửa hàng. --}}
                        <label class="db-nho-ky" title="Mở lại Tổng quan vẫn giữ kỳ và chi nhánh đang chọn">
                            <input type="checkbox" class="form-check-input m-0" id="db-nho-ky"> Nhớ kỳ
                        </label>
                    </div>
                    <div class="card-body px-2">
                        {{-- Form GET: chọn kỳ là nạp lại trang với ?range= hoặc ?from=&to=.
                             Hai ô ngày và nhóm nút loại trừ nhau — gửi kèm cả hai thì
                             controller không biết người dùng vừa đổi cái nào. --}}
                        <form method="GET" action="{{ route('admin.dashboard') }}" id="db-filter">
                            <input type="hidden" name="branch" value="{{ $filters['branch'] }}">
                            @foreach (['top_products', 'top_payment', 'top_origin', 'top_promo', 'top_branch'] as $o)
                                <input type="hidden" name="{{ $o }}" value="{{ $filters[$o] }}">
                            @endforeach

                            {{-- Ô chữ + daterangepicker, KHÔNG phải input[type=date]: lịch
                                 của trình duyệt in theo ngôn ngữ máy khách nên cùng một
                                 trang tiếng Việt lại hiện 09/26/2026. --}}
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <input class="form-check-input mt-2 flex-shrink-0" type="radio" id="db-range-custom"
                                    aria-label="{{ __('message.from_date') }} – {{ __('message.to_date') }}"
                                    {{ $filters['range'] === null ? 'checked' : '' }}>
                                <div class="d-flex flex-column gap-1 flex-grow-1">
                                    <input type="text" class="form-control form-control-sm" name="from" id="db-from"
                                        autocomplete="off" value="{{ $dmy($filters['from']) }}"
                                        placeholder="{{ __('message.from_date') }}"
                                        aria-label="{{ __('message.from_date') }}">
                                    <input type="text" class="form-control form-control-sm" name="to" id="db-to"
                                        autocomplete="off" value="{{ $dmy($filters['to']) }}"
                                        placeholder="{{ __('message.to_date') }}"
                                        aria-label="{{ __('message.to_date') }}">
                                </div>
                            </div>

                            @foreach ($rangeGroups as $tieuDe => $maDS)
                                <div class="mb-2">
                                    <span class="title_search">{{ $tieuDe }}</span>
                                    @foreach ($maDS as $ma)
                                        <label class="db-opt {{ $filters['range'] === $ma ? 'is-active' : '' }}" for="db-range-{{ $ma }}">
                                            <input class="form-check-input m-0 db-range" type="radio" name="range"
                                                value="{{ $ma }}" id="db-range-{{ $ma }}"
                                                {{ $filters['range'] === $ma ? 'checked' : '' }}>
                                            <span>{{ $rangeLabels[$ma] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach
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
                <div class="db-title">
                    <h1 class="tieu-de-trang mb-0">{{ __('message.overview') }}</h1>
                    <div class="db-title-meta">
                        <span>{{ $filters['describe'] }}</span>
                        <span class="db-sep"></span>
                        <span>Cập nhật lúc <b id="db-cap-nhat">{{ $capNhatLuc }}</b> · tự làm mới mỗi 5 phút</span>
                        <button type="button" class="db-refresh" id="db-lam-moi" aria-label="Làm mới" title="Làm mới">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- ---------- Sáu ô KPI: bấm vào để mở màn chi tiết đúng kỳ ---------- --}}
                <div class="db-kpis">
                    @foreach ($oKpi as $o)
                        <a href="{{ $o['link'] }}" class="db-kpi" style="--db-mau: {{ $o['mau'] }}">
                            <div class="db-kpi-head">
                                <h3>{{ $o['nhan'] }}</h3>
                                <span class="db-info" data-bs-toggle="tooltip" data-bs-placement="left" title="{{ $o['tip'] }}">
                                    <i class="bi bi-info-circle-fill"></i>
                                </span>
                            </div>
                            <div class="db-kpi-body">
                                <div class="db-kpi-text">
                                    <span class="db-kpi-so" title="{{ $o['day_du'] }}">{{ $o['so'] }}</span>
                                    @if ($o['so_sanh'])
                                        <span class="db-kpi-phu {{ $o['so_sanh']['lop'] }}"
                                            title="{{ $kyTruoc ? 'Kỳ trước: '.$kyTruoc : '' }}">{{ $o['so_sanh']['chu'] }}</span>
                                    @endif
                                    @if ($o['phu'])
                                        <span class="db-kpi-phu">{{ $o['phu'] }}</span>
                                    @endif
                                </div>
                                <img src="{{ asset('v2/images/'.$o['icon']) }}" alt="" width="46" height="46">
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- ---------- Mục tiêu doanh thu tháng ---------- --}}
                @if ($mucTieu)
                    <div class="db-goal">
                        <div class="db-goal-label">
                            <b>Mục tiêu doanh thu tháng {{ $mucTieu['thang'] }}</b>
                            <span>{{ $mucTieu['muc_tieu'] > 0 ? $tien($mucTieu['muc_tieu']).' đ (doanh thu thuần)' : 'Chưa đặt mục tiêu' }}</span>
                        </div>
                        @if ($mucTieu['pct'] !== null)
                            <div class="db-goal-bar">
                                <div class="db-goal-track"><div style="width: {{ min(100, $mucTieu['pct']) }}%"></div></div>
                                <div class="db-goal-note">
                                    <span>Đã đạt {{ $tien($mucTieu['dat']) }} đ</span>
                                    <span>{{ $mucTieu['dat'] >= $mucTieu['muc_tieu'] ? 'Vượt '.$tien($mucTieu['dat'] - $mucTieu['muc_tieu']).' đ' : 'Còn thiếu '.$tien($mucTieu['muc_tieu'] - $mucTieu['dat']).' đ' }}</span>
                                </div>
                            </div>
                            <span class="db-goal-pct">{{ $phanTram($mucTieu['pct']) }}</span>
                        @else
                            <div class="db-goal-bar text-muted small">Đặt mục tiêu để theo dõi tiến độ doanh thu thuần trong tháng.</div>
                        @endif
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="db-goal-sua">
                            {{ $mucTieu['muc_tieu'] > 0 ? 'Sửa mục tiêu' : 'Đặt mục tiêu' }}
                        </button>
                        <form method="POST" action="{{ route('admin.dashboard.muc-tieu') }}" class="db-goal-form d-none" id="db-goal-form">
                            @csrf
                            <label for="db-goal-input" class="small text-muted">Mục tiêu mỗi tháng (đ)</label>
                            <input type="text" inputmode="numeric" class="form-control form-control-sm" id="db-goal-input"
                                name="muc_tieu" value="{{ $mucTieu['muc_tieu'] > 0 ? $tien($mucTieu['muc_tieu']) : '' }}">
                            <button type="submit" class="btn btn-sm btn-primary">Lưu</button>
                        </form>
                    </div>
                @endif

                <div class="card-body px-0 pb-0">
                    {{-- ---------- Hàng 1: ca · doanh thu · bán chạy ---------- --}}
                    <div class="row g-2 mb-2">
                        <div class="col-12 col-md-3 mb-3 mb-md-0">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold">{{ __('message.current_shift') }}</h6>
                                    @if ($caMo)
                                        <span class="db-pill db-pill--green">{{ count($caMo) > 1 ? count($caMo).' ca đang mở' : 'Đang mở' }}</span>
                                    @endif
                                </div>
                                <div class="chart-body p-2 db-shift-body">
                                    <div id="list-shift-details" class="w-100">
                                        @forelse ($caMo as $ca)
                                            <div class="list-history-shift active db-shift">
                                                <dl class="db-shift-rows">
                                                    <dt>{{ __('message.branch') }}</dt><dd>{{ $ca['chi_nhanh'] !== '' ? $ca['chi_nhanh'] : '—' }}</dd>
                                                    <dt>{{ __('message.opened_by') }}</dt><dd>{{ $ca['nguoi_mo'] !== '' ? $ca['nguoi_mo'] : '—' }}</dd>
                                                    <dt>{{ __('message.shift_code') }}</dt><dd>{{ $ca['ma'] }}</dd>
                                                    <dt>{{ __('message.shift_open_time') }}</dt><dd>{{ $ca['gio_mo'] ? date('d-m-Y H:i', strtotime($ca['gio_mo'])) : '—' }}</dd>
                                                    <dt>Đơn tiền mặt</dt><dd>{{ $tien($ca['so_don']) }}</dd>
                                                </dl>
                                                <div class="db-cash">
                                                    <span>{{ __('message.total_cash') }}</span>
                                                    <b>{{ $tien($ca['tien_mat']) }} đ</b>
                                                    <small>{{ $tien($ca['dau_ca']) }} đầu ca + {{ $tien($ca['thu']) }} thu − {{ $tien($ca['chi']) }} chi</small>
                                                </div>
                                            </div>
                                        @empty
                                            <p class="text-center noti-error-shift fw-bold alert alert-danger mb-0">
                                                {{ __('message.no_shift_selected') }}
                                            </p>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 mb-3 mb-md-0">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center gap-2 flex-wrap">
                                    <h6 class="mb-0 fw-bold me-auto">{{ __('message.sales_revenue') }}</h6>
                                    <div class="db-seg" role="group" aria-label="Chia trục theo">
                                        @foreach ($groups as $ma => $nhan)
                                            <a href="{{ request()->fullUrlWithQuery(['group' => $ma]) }}"
                                                class="{{ $filters['group'] === $ma ? 'is-active' : '' }}"
                                                aria-current="{{ $filters['group'] === $ma ? 'true' : 'false' }}">{{ $nhan }}</a>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="chart-body p-2">
                                    <div class="db-chart-box"><canvas id="myChart1"></canvas></div>
                                    <div class="db-hint">Bấm vào cột để mở danh sách đơn của mốc đó</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-3 mb-3 mb-md-0">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_best_selling_products') }}</h6>
                                    @include('v2::dashboard._top-select', $topSelect('quantityBestSeller', 'top_products'))
                                </div>
                                <div class="chart-body p-0">
                                    <div class="table-responsive db-top-scroll">
                                        <table class="table table-sm border-0 mb-0 db-table">
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
                                                        <td class="db-c-stt"><span class="db-rank db-rank--{{ $i + 1 }}">{{ $i + 1 }}</span></td>
                                                        <td class="text-start db-c-name" title="{{ $sp['name'] ?? '' }}">
                                                            @if (! empty($sp['product_id']))
                                                                <a href="{{ route('admin.reports.goods', $ky + ['product_id' => $sp['product_id']]) }}">{{ $sp['name'] ?? '—' }}</a>
                                                            @else
                                                                {{ $sp['name'] ?? '—' }}
                                                            @endif
                                                        </td>
                                                        <td class="text-end fw-bold db-c-val">{{ $tien($sp['units'] ?? 0) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr>
                                                        <td colspan="3" class="text-center text-muted py-3">{{ __('message.no_data') }}</td>
                                                    </tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ---------- Hàng 2: bốn thẻ Top ---------- --}}
                    <div class="row g-2 mb-2">
                        @foreach ($theVong as $the)
                            <div class="col-12 col-md-6 col-xl-3">
                                <div class="div-chart h-100">
                                    <div class="chart-header d-flex align-items-center">
                                        <h6 class="mb-0 fw-bold flex-grow-1">{{ $the['tieu_de'] }}</h6>
                                        @include('v2::dashboard._top-select', $the['sel'])
                                    </div>
                                    <div class="chart-body p-2">
                                        @if (count($the['ds']))
                                            <div class="db-donut">
                                                <div class="db-donut-ring">
                                                    <canvas id="{{ $the['id'] }}" width="130" height="130"></canvas>
                                                    <div class="db-donut-center"><b>{{ $the['giua'] }}</b><span>{{ $the['giua_phu'] }}</span></div>
                                                </div>
                                                <ul class="db-legend">
                                                    @foreach ($the['ds'] as $l)
                                                        <li>
                                                            <span class="db-dot" style="background: {{ $l['mau'] }}"></span>
                                                            <span class="db-legend-name">{{ $l['nhan'] }}</span>
                                                            <b>{{ $l['pt'] }}</b>
                                                            <small>{{ $l['chu'] }}</small>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @else
                                            <p class="text-center text-muted my-4">{{ __('message.no_data') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_promotions') }}</h6>
                                    @include('v2::dashboard._top-select', $topSelect('byPromo', 'top_promo'))
                                </div>
                                <div class="chart-body p-2">
                                    {{-- API chưa có sổ đếm lượt dùng từng chương trình khuyến
                                         mại, nên thẻ này nói thẳng là chưa có số thay vì vẽ
                                         một biểu đồ rỗng trông như "tháng này không ai dùng". --}}
                                    <div class="db-empty">
                                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M20 12l-8 8-9-9V3h8z"></path><circle cx="7.5" cy="7.5" r="1.5"></circle>
                                        </svg>
                                        <span>Chưa có số liệu lượt dùng khuyến mại</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex align-items-center">
                                    <h6 class="mb-0 fw-bold flex-grow-1">{{ __('message.top_gross_revenue_by_branch') }}</h6>
                                    @include('v2::dashboard._top-select', $topSelect('byBranch', 'top_branch'))
                                </div>
                                <div class="chart-body p-2">
                                    @forelse ($theoChiNhanh as $i => $cn)
                                        <div class="db-branch-row">
                                            <div><span>{{ $i + 1 }}. {{ $cn['nhan'] }}</span><b title="{{ $tien($cn['revenue'] ?? 0) }} đ">{{ $tienGon($cn['revenue'] ?? 0) }}</b></div>
                                            <div class="db-bar"><div style="width: {{ round((float) ($cn['revenue'] ?? 0) / $maxChiNhanh * 100, 1) }}%"></div></div>
                                        </div>
                                    @empty
                                        <p class="text-center text-muted my-4">{{ __('message.no_data') }}</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ---------- Hàng 3: giờ cao điểm · cần chú ý ---------- --}}
                    <div class="row g-2">
                        <div class="col-12 col-xl-8">
                            <div class="div-chart h-100">
                                <div class="chart-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 fw-bold">Giờ cao điểm</h6>
                                    <span class="small text-muted">
                                        @if ($gioCaoDiem['dinh'] !== null)
                                            Số đơn theo giờ · đông nhất {{ $gioCaoDiem['dinh'] }}h
                                        @else
                                            Số đơn theo giờ
                                        @endif
                                    </span>
                                </div>
                                <div class="chart-body p-2">
                                    <div class="db-hours">
                                        @foreach ($gioCaoDiem['gio'] as $g)
                                            <div class="db-hour" title="{{ $g['h'] }}h: {{ $g['don'] }} đơn">
                                                <span>{{ $g['don'] ?: '' }}</span>
                                                <div class="{{ $g['h'] === $gioCaoDiem['dinh'] ? 'is-peak' : '' }} {{ $g['don'] ? '' : 'is-empty' }}"
                                                    style="height: {{ $g['don'] ? max(4, round($g['don'] / $maxGio * 76)) : 3 }}px"></div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="db-hours db-hours--label">
                                        @foreach ($gioCaoDiem['gio'] as $g)
                                            <div>{{ $g['h'] }}h</div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-xl-4">
                            <div class="div-chart h-100 db-alert">
                                <div class="chart-header">
                                    <h6 class="mb-0 fw-bold">Cần chú ý</h6>
                                </div>
                                <div class="chart-body p-2">
                                    @forelse ($canhBao as $cb)
                                        <a href="{{ $cb['link'] }}" class="db-alert-row"><span class="db-dot"></span>{{ $cb['chu'] }} →</a>
                                    @empty
                                        <p class="text-muted small mb-0 px-1">Không có việc gì cần chú ý.</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .db-loc .header_search { font-weight: 600; }
        .db-nho-ky { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 500; cursor: pointer; margin: 0; }
        .db-opt { display: flex; align-items: center; gap: 8px; padding: 4px 8px; margin: 1px 0; border-radius: 6px; cursor: pointer; }
        .db-opt:hover { background: #f1f5f9; }
        .db-opt.is-active { background: #e8eefb; color: #1d4ed8; font-weight: 700; }
        #db-from, #db-to { font-size: 12px; padding-left: 6px; padding-right: 6px; }

        .db-title { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 16px; margin-bottom: 10px; }
        .db-title-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; font-size: 13px; color: #475569; }
        .db-sep { width: 1px; height: 16px; background: #cbd5e1; }
        .db-refresh { width: 32px; height: 32px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1d4ed8; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .db-refresh:hover { background: #eff6ff; }

        .db-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
        /* Sáu ô một hàng chỉ khi đủ rộng: dưới ~1700px mỗi ô còn chưa tới 180px,
           số tiền và dòng so kỳ trước tràn ra mép (đo ở 1440 / 1536). */
        @media (min-width: 1700px) { .db-kpis { grid-template-columns: repeat(6, minmax(0, 1fr)); } }
        .db-kpi { display: flex; flex-direction: column; gap: 8px; min-height: 108px; padding: 10px 12px; background: #fff;
                  border: 1px solid #e2e8f0; border-top: 3px solid var(--db-mau); border-radius: 10px; color: #0f172a; text-decoration: none; transition: box-shadow .15s; }
        .db-kpi:hover { color: #0f172a; box-shadow: 0 4px 14px rgba(15, 23, 42, .08); }
        .db-kpi-head { display: flex; justify-content: space-between; gap: 6px; }
        .db-kpi-head h3 { font-size: 13px; font-weight: 600; color: #334155; margin: 0; }
        .db-info { color: #94a3b8; font-size: 13px; }
        .db-kpi-body { flex: 1; display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; }
        .db-kpi-text { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .db-kpi-so { font-size: 20px; font-weight: 800; white-space: nowrap; }
        .db-kpi-phu { font-size: 11.5px; color: #64748b; white-space: nowrap; }
        .db-kpi-phu.db-up { color: #15803d; font-weight: 600; }
        .db-kpi-phu.db-down { color: #b91c1c; font-weight: 600; }
        .db-kpi img { flex: 0 0 auto; width: 44px; height: 44px; align-self: flex-end; }

        .db-goal { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding: 10px 14px; margin-bottom: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
        .db-goal-label { display: flex; flex-direction: column; min-width: 200px; font-size: 13px; }
        .db-goal-label span { font-size: 12px; color: #475569; }
        .db-goal-bar { flex: 1 1 240px; }
        .db-goal-track { height: 12px; background: #eef2f7; border-radius: 999px; overflow: hidden; }
        .db-goal-track div { height: 12px; background: #1d4ed8; border-radius: 999px; }
        .db-goal-note { display: flex; justify-content: space-between; gap: 8px; margin-top: 4px; font-size: 12px; color: #475569; }
        .db-goal-pct { font-size: 22px; font-weight: 800; color: #1d4ed8; }
        .db-goal-form { display: flex; align-items: center; gap: 8px; flex-basis: 100%; }
        .db-goal-form input { max-width: 200px; }

        .db-pill { font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
        .db-pill--green { background: #dcfce7; color: #14532d; }
        .db-shift-body { overflow-y: auto; max-height: 290px; }
        .db-shift + .db-shift { margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; }
        .db-shift-rows { display: grid; grid-template-columns: auto 1fr; gap: 6px 10px; margin: 0 0 8px; font-size: 13px; }
        .db-shift-rows dt { font-weight: 400; color: #475569; }
        .db-shift-rows dd { margin: 0; text-align: right; font-weight: 600; }
        .db-cash { display: flex; flex-direction: column; padding: 8px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534; font-size: 12px; }
        .db-cash b { font-size: 18px; color: #14532d; }

        .db-seg { display: inline-flex; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; }
        .db-seg a { padding: 3px 10px; font-size: 12px; color: #0f172a; text-decoration: none; }
        .db-seg a + a { border-left: 1px solid #cbd5e1; }
        .db-seg a.is-active { background: #1d4ed8; color: #fff; font-weight: 600; }
        .db-chart-box { position: relative; height: 250px; }
        .db-hint { font-size: 12px; color: #64748b; margin-top: 4px; }

        .db-top-scroll { max-height: 290px; min-height: 250px; overflow-y: auto; }
        /* Bảng trong thẻ hẹp (thẻ chỉ rộng ~260px ở khổ 1366): chia phần trăm đủ
           100 và cắt "…" ở cột tên, KHÔNG để `auto` tự nới rồi tràn ra ngoài thẻ. */
        .db-table { table-layout: fixed; width: 100%; }
        .db-table th, .db-table td { white-space: nowrap; vertical-align: middle; padding: 7px 6px; font-size: 13px; }
        .db-table td { overflow: hidden; text-overflow: ellipsis; }
        .db-table th { font-size: 11px; padding-left: 4px; padding-right: 4px; }
        .db-table .db-c-stt { width: 15%; }
        .db-table .db-c-name { width: 50%; }
        .db-table .db-c-val { width: 35%; }
        .db-table tbody tr:nth-child(even) { background: #fafbfd; }
        .db-table a { color: #0f172a; text-decoration: none; }
        .db-table a:hover { color: #1d4ed8; text-decoration: underline; }
        .db-rank { display: inline-flex; width: 22px; height: 22px; border-radius: 999px; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; background: #e2e8f0; color: #334155; }
        .db-rank--1 { background: #f59e0b; color: #fff; }
        .db-rank--2 { background: #94a3b8; color: #fff; }
        .db-rank--3 { background: #c2773a; color: #fff; }

        .db-donut { display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .db-donut-ring { position: relative; width: 130px; height: 130px; }
        .db-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
        .db-donut-center b { font-size: 18px; }
        .db-donut-center span { font-size: 11px; color: #475569; }
        .db-legend { list-style: none; margin: 0; padding: 0; width: 100%; display: flex; flex-direction: column; gap: 6px; }
        .db-legend li { display: grid; grid-template-columns: 10px 1fr auto; column-gap: 6px; align-items: center; font-size: 13px; }
        .db-legend small { grid-column: 2 / 4; color: #475569; }
        .db-dot { width: 10px; height: 10px; border-radius: 999px; display: inline-block; flex: 0 0 auto; }
        .db-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; min-height: 200px; color: #64748b; font-size: 13px; text-align: center; }

        .db-branch-row { display: flex; flex-direction: column; gap: 4px; font-size: 13px; padding: 4px 2px; }
        .db-branch-row > div:first-child { display: flex; justify-content: space-between; gap: 8px; white-space: nowrap; }
        .db-bar { height: 8px; background: #eef2f7; border-radius: 4px; overflow: hidden; }
        .db-bar div { height: 8px; background: #1d4ed8; }

        .db-hours { display: flex; gap: 6px; }
        .db-hours:not(.db-hours--label) { height: 100px; align-items: flex-end; border-bottom: 1px solid #cbd5e1; }
        .db-hour { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 2px; }
        .db-hour span { font-size: 11px; font-weight: 700; color: #1e3a8a; }
        .db-hour div { width: 100%; border-radius: 3px 3px 0 0; background: #3b82f6; }
        .db-hour div.is-peak { background: #f97316; }
        .db-hour div.is-empty { background: #e2e8f0; }
        .db-hours--label div { flex: 1; text-align: center; font-size: 11px; color: #64748b; }

        .db-alert { background: #fff7ed; border-color: #fed7aa; }
        .db-alert .chart-header { color: #7c2d12; }
        .db-alert-row { display: flex; gap: 8px; align-items: baseline; padding: 4px; font-size: 13px; color: #0f172a; text-decoration: none; }
        .db-alert-row:hover { color: #c2410c; }
        .db-alert-row .db-dot { width: 8px; height: 8px; background: #c2410c; }

        @media (max-width: 767px) { .db-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        /* Ô KPI trên điện thoại chỉ ~165px: icon minh hoạ nhường chỗ cho số và dòng so kỳ trước. */
        @media (max-width: 575px) {
            .db-kpi img { display: none; }
            .db-kpi-phu { white-space: normal; }
        }
    </style>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const soLieu = @json($duLieuJs);
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(v);

            const ve = (id, cau) => {
                const o = document.getElementById(id);
                return o ? new Chart(o.getContext('2d'), cau) : null;
            };

            // Cột ngày/tuần/tháng: bấm vào là mở Quản lý đơn hàng đúng khoảng
            // ngày của cột đó (máy chủ đã cắt khoảng trong kỳ đang xem).
            const c = soLieu.chart;
            const moDon = (i) => {
                const r = c.ranges[i];
                if (!r) return;
                const u = new URL(soLieu.linkDon, window.location.origin);
                u.searchParams.set('from_date', r[0]);
                u.searchParams.set('to_date', r[1]);
                window.location.href = u.toString();
            };
            const cot = c.labels.length <= 45;
            ve('myChart1', {
                type: cot ? 'bar' : 'line',
                data: {
                    labels: c.labels,
                    datasets: [
                        { label: soLieu.chu.gop, data: c.gross, borderColor: '#2bb5b5', backgroundColor: '#2bb5b5', tension: .3, borderRadius: 3 },
                        { label: soLieu.chu.thuan, data: c.net, borderColor: '#3b82f6', backgroundColor: '#3b82f6', tension: .3, borderRadius: 3 },
                        { label: soLieu.chu.von, data: c.cost, borderColor: '#f97316', backgroundColor: '#f97316', tension: .3, borderRadius: 3 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                        tooltip: { callbacks: { label: (x) => `${x.dataset.label}: ${tien(x.parsed.y)} đ` } },
                    },
                    scales: { y: { beginAtZero: true, ticks: { callback: (v) => tien(v) + ' đ' } } },
                    onClick: (e, els, chart) => {
                        const p = chart.getElementsAtEventForMode(e, 'index', { intersect: false }, false);
                        if (p.length) moDon(p[0].index);
                    },
                    onHover: (e, els, chart) => { chart.canvas.style.cursor = els.length ? 'pointer' : 'default'; },
                },
            });

            const tron = (id, ds) => {
                if (!ds.length) return;
                ve(id, {
                    type: 'doughnut',
                    data: {
                        labels: ds.map((r) => r.nhan),
                        datasets: [{ data: ds.map((r) => r.gia_tri), backgroundColor: ds.map((r) => r.mau), borderWidth: 2 }],
                    },
                    options: {
                        responsive: false, cutout: '68%',
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: (x) => `${x.label}: ${ds[x.dataIndex].chu}` } },
                        },
                    },
                });
            };
            tron('myChart2', soLieu.thanhToan);
            tron('myChart3', soLieu.nguon);

            // ---------- Nhớ kỳ ----------
            // Bật thì mỗi lần mở trang lưu lại tham số đang xem; mở Tổng quan không
            // kèm tham số (bấm tab, bấm nút THỐNG KÊ) là quay về đúng chỗ ấy.
            const KHOA_BAT = 'db_nho_ky', KHOA_KY = 'db_ky';
            const doc = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };
            const ghi = (k, v) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) {} };
            const nhoKy = document.getElementById('db-nho-ky');
            const dangNho = doc(KHOA_BAT) === '1';
            nhoKy.checked = dangNho;
            if (dangNho) {
                const daLuu = doc(KHOA_KY);
                if (!window.location.search && daLuu) {
                    window.location.replace(window.location.pathname + daLuu);
                    return;
                }
                if (window.location.search) ghi(KHOA_KY, window.location.search);
            }
            nhoKy.addEventListener('change', () => {
                ghi(KHOA_BAT, nhoKy.checked ? '1' : null);
                ghi(KHOA_KY, nhoKy.checked && window.location.search ? window.location.search : null);
            });

            // ---------- Làm mới ----------
            // Màn này hay để mở cả ngày ở quầy: 5 phút nạp lại một lần, nhưng chỉ
            // khi tab đang được nhìn và không có hộp nào đang mở dở.
            const NAM_PHUT = 5 * 60 * 1000;
            const moLuc = Date.now();
            const dangBan = () => document.querySelector('.daterangepicker[style*="block"], .offcanvas.show, .modal.show, #db-goal-form:not(.d-none)');
            setInterval(() => {
                if (Date.now() - moLuc >= NAM_PHUT && document.visibilityState === 'visible' && !dangBan()) {
                    window.location.reload();
                }
            }, 30000);
            document.getElementById('db-lam-moi').addEventListener('click', () => window.location.reload());

            // ---------- Mục tiêu tháng ----------
            const nutMucTieu = document.getElementById('db-goal-sua');
            if (nutMucTieu) {
                const formMucTieu = document.getElementById('db-goal-form');
                const oMucTieu = document.getElementById('db-goal-input');
                nutMucTieu.addEventListener('click', () => {
                    formMucTieu.classList.toggle('d-none');
                    if (!formMucTieu.classList.contains('d-none')) oMucTieu.focus();
                });
                oMucTieu.addEventListener('input', () => {
                    const so = oMucTieu.value.replace(/\D/g, '');
                    oMucTieu.value = so ? tien(Number(so)) : '';
                });
            }

            // ---------- Bộ lọc ----------
            // Đổi kỳ / đổi Top là nạp lại trang: mọi màn v2 khác lọc bằng tham số
            // trên URL, giữ nguyên cách đó thì bấm F5 hay gửi link đều ra cùng một trang.
            const form = document.getElementById('db-filter');
            document.querySelectorAll('.db-range').forEach((o) => o.addEventListener('change', () => {
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

            // Đủ hai ngày thì lọc luôn, không chờ nút — như v2.
            const tuChon = document.getElementById('db-range-custom');
            const tuChonDuNgay = () => /^\d{2}-\d{2}-\d{4}$/.test(document.getElementById('db-from').value)
                && /^\d{2}-\d{2}-\d{4}$/.test(document.getElementById('db-to').value);
            ['db-from', 'db-to'].forEach((id) => document.getElementById(id).addEventListener('change', () => {
                document.querySelectorAll('.db-range').forEach((r) => (r.checked = false));
                document.querySelectorAll('.db-opt').forEach((l) => l.classList.remove('is-active'));
                tuChon.checked = true;
                if (tuChonDuNgay()) form.submit();
            }));
            tuChon.addEventListener('change', () => document.getElementById('db-from').focus());

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
                        $o.val(start.format('DD-MM-YYYY'))[0].dispatchEvent(new Event('change'));
                    });
                });
            }

            const cn = document.getElementById('db-branch');
            if (cn) cn.addEventListener('change', () => {
                const u = new URL(window.location.href);
                u.searchParams.set('branch', cn.value);
                window.location.href = u.toString();
            });
        })();
    </script>
@endpush
