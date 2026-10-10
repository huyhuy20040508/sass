{{-- Tổng quan — làm lại theo bản thiết kế design/html/tong-quan.html (duyệt
     11/10/2026), vẫn trên khuôn màn cùng tên của bản v2: khung lọc trái, sáu
     ô KPI, biểu đồ doanh thu, ca, bán chạy, các thẻ cơ cấu.

     So với bản trước: ô KPI có đường xu hướng và số của kỳ trước; biểu đồ
     doanh thu một chuỗi cột + đường lợi nhuận + nét đứt kỳ trước; "Cần chú ý"
     và ca lên cạnh biểu đồ; bán chạy đổi Số lượng / Doanh thu; khuyến mại lấy số
     thật từ báo cáo khuyến mại.

     Trang dựng SẴN ở máy chủ (không AJAX) như mọi màn v2 khác: lọc bằng tham
     số trên URL. Chart.js nạp riêng ở đây — layout v2 cố ý không nạp sẵn. --}}
@extends('v2::layouts.master')

@section('title', 'Tổng quan')

@push('styles')
    <link href="{{ asset('v2/css/custom-dashboard.css') }}" rel="stylesheet">
@endpush

@php
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $dmy = fn ($ymd) => date('d-m-Y', strtotime($ymd));
    $phanTram = fn ($p) => rtrim(rtrim(number_format(abs($p), 1, ',', '.'), '0'), ',').'%';

    // Số trong ô KPI: dưới một tỷ in đủ, từ một tỷ in "5,86 tỷ" (rê chuột ra số đủ).
    $tienKpi = function ($n) {
        $n = (float) $n;
        if (abs($n) < 1e9) {
            return number_format($n, 0, ',', '.');
        }

        return ($n < 0 ? '-' : '').rtrim(rtrim(number_format(abs($n) / 1e9, 2, ',', '.'), '0'), ',').' '.__('message.billion');
    };
    // Số phụ gọn: từ một triệu in "803,4 tr".
    $gon = function ($n) use ($tien) {
        $n = (float) $n;
        if (abs($n) >= 1e9) {
            return rtrim(rtrim(number_format($n / 1e9, 2, ',', '.'), '0'), ',').' tỷ';
        }
        if (abs($n) >= 1e6) {
            return rtrim(rtrim(number_format($n / 1e6, 1, ',', '.'), '0'), ',').' tr';
        }

        return $tien($n);
    };

    // Nhãn so kỳ trước: ▲/▼ % — kỳ trước bằng 0 thì nói thẳng như vậy.
    $chip = function (?array $so) use ($phanTram) {
        if ($so === null) {
            return null;
        }
        if ($so['moi']) {
            return ['chu' => 'Kỳ trước chưa phát sinh', 'lop' => 'db-chip--trung'];
        }

        return $so['pct'] >= 0
            ? ['chu' => '▲ '.$phanTram($so['pct']), 'lop' => 'db-chip--len']
            : ['chu' => '▼ '.$phanTram($so['pct']), 'lop' => 'db-chip--xuong'];
    };

    $ky = ['from_date' => $dmy($filters['from']), 'to_date' => $dmy($filters['to'])];
    // "<b>30 ngày qua</b> · 12/09 → 11/10/2026" — tên mốc đậm, rồi khoảng ngày.
    $tenKy = $filters['range'] !== null ? \Illuminate\Support\Str::ucfirst(\App\Support\Period::PRESETS[$filters['range']]['phrase'] ?? $rangeLabels[$filters['range']]) : null;
    $soNgayKy = (int) round((strtotime($filters['to']) - strtotime($filters['from'])) / 86400) + 1;
    $khoangKy = date('d/m', strtotime($filters['from'])).' → '.date('d/m/Y', strtotime($filters['to']))
        .($tenKy === null ? ' ('.$soNgayKy.' ngày)' : '');
    $truoc = $kpi['prev'] ?? [];
    $kyTruocNgan = $kyTruoc ? preg_replace('#/\d{4}$#', '', $kyTruoc) : null;
    $tipKy = $kyTruoc ? 'Kỳ trước: '.$kyTruoc : '';

    $oKpi = [
        [
            'nhan' => __('message.gross-revenue'), 'so' => $tienKpi($kpi['gross']).' đ', 'day_du' => $tien($kpi['gross']).' đ',
            'tip' => __('message.gross_revenue_formula'), 'chip' => $chip($kpi['so']['gross']),
            'phu' => ($truoc['gross'] ?? 0) > 0 ? 'kỳ trước '.$gon($truoc['gross']) : null, 'phu2' => null,
            'mau' => '#2bb5b5', 'link' => route('admin.reports.sales', $ky), 'spark' => 'gross', 'icon' => 'ic_revenue.png',
        ],
        [
            'nhan' => __('message.net-revenue'), 'so' => $tienKpi($kpi['net']).' đ', 'day_du' => $tien($kpi['net']).' đ',
            'tip' => __('message.net_revenue_formula'), 'chip' => $chip($kpi['so']['net']),
            'phu' => $kpi['gross'] - $kpi['net'] > 0 ? 'Giảm giá −'.$gon($kpi['gross'] - $kpi['net']) : null, 'phu2' => null,
            'mau' => '#3b82f6', 'link' => route('admin.reports.sales', $ky), 'spark' => 'net', 'icon' => 'ic_net_revenue.png',
        ],
        [
            'nhan' => 'Lợi nhuận gộp', 'so' => $tienKpi($kpi['profit']).' đ', 'day_du' => $tien($kpi['profit']).' đ',
            'tip' => 'Lợi nhuận gộp = Tiền hàng − Giảm giá − Giá vốn hàng đã bán (không tính phí vận chuyển)', 'chip' => $chip($kpi['so']['profit']),
            'phu' => $kpi['margin'] !== null ? 'Biên lãi '.$phanTram($kpi['margin']) : null, 'phu2' => null,
            'mau' => '#16a34a', 'link' => route('admin.reports.profit', $ky), 'spark' => 'profit', 'icon' => 'ic_estimated_revenue.svg',
        ],
        [
            'nhan' => __('message.sales-orders'), 'so' => $tien($kpi['orders']), 'day_du' => $tien($kpi['orders']).' đơn',
            'tip' => __('message.includes_unpaid_credit_partial_orders'), 'chip' => $chip($kpi['so']['orders']),
            'phu' => null, 'phu2' => $kpi['orders'] > 0 ? 'TB '.$tien($kpi['aov']).' đ / đơn' : null,
            'mau' => '#8b5cf6', 'link' => route('admin.orders.index', $ky), 'spark' => 'orders', 'icon' => 'ic_order.png',
        ],
        [
            'nhan' => __('message.cost-of-goods-purchased'), 'so' => $tienKpi($kpi['purchase_cost']).' đ', 'day_du' => $tien($kpi['purchase_cost']).' đ',
            'tip' => __('message.includes_unpaid_and_paid_vouchers').($kpi['purchase_sampled'] ? ' — kỳ này nhiều phiếu, số đang tính trên '.\App\Http\Controllers\DashboardController::MAX_PURCHASE_PAGES.' trang đầu' : ''),
            'chip' => ['chu' => $tien($kpi['purchase_count']).' phiếu mua', 'lop' => 'db-chip--trung'],
            'phu' => $kpi['purchase_count'] > 0 ? 'đã trả '.$gon($kpi['purchase_paid']) : null, 'phu2' => null,
            'mau' => '#ef4444', 'link' => route('admin.phieu-mua-hang.index', $ky), 'spark' => 'mua', 'icon' => 'ic_trading.png',
        ],
        [
            'nhan' => __('message.quantity_in_stock'), 'so' => $tien($kpi['purchase_qty']), 'day_du' => $tien($kpi['purchase_qty']),
            'tip' => __('message.stock_in_quantity_formula'),
            'chip' => match (true) {
                ($kpi['so']['purchase_qty'] ?? null) === null => null,
                $kpi['so']['purchase_qty']['moi'] => ['chu' => 'Kỳ trước chưa phát sinh', 'lop' => 'db-chip--trung'],
                default => ['chu' => ($kpi['so']['purchase_qty']['chenh'] >= 0 ? '▲ ' : '▼ ').$tien(abs($kpi['so']['purchase_qty']['chenh'])),
                    'lop' => $kpi['so']['purchase_qty']['chenh'] >= 0 ? 'db-chip--len' : 'db-chip--xuong'],
            },
            'phu' => 'sản phẩm · '.$tien($kpi['purchase_items']).' mặt hàng', 'phu2' => null,
            'mau' => '#f59e0b', 'link' => route('admin.phieu-mua-hang.index', $ky), 'spark' => 'nhap', 'icon' => 'ic_inventory.png',
        ],
    ];

    $PAY = \App\Http\Controllers\OrderController::PAYMENT_METHODS;
    $CHANNEL = \App\Http\Controllers\OrderController::CHANNELS ?? [];
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
    $vongThanhToan = $vong($theoThanhToan, 'revenue', $nhanThanhToan, fn ($r) => $tien($r['revenue'] ?? 0).' đ · '.$tien($r['orders'] ?? 0).' đơn');
    $vongNguon = $vong($theoNguon, 'orders', $nhanNguon, fn ($r) => $tien($r['revenue'] ?? 0).' đ · '.$tien($r['orders'] ?? 0).' đơn');
    $maxChiNhanh = max([1, ...array_map(fn ($r) => (float) ($r['revenue'] ?? 0), $theoChiNhanh)]);
    $maxGio = max([1, ...array_column($gioCaoDiem['gio'], 'don')]);
    $maxThu = max([1, ...array_column($theoThu, 'don')]);
    $maxKm = max([1, ...array_column($khuyenMai['ds'], 'giam')]);

    $duLieuJs = [
        'chart' => $chart,
        'spark' => [
            'gross' => $chart['gross'], 'net' => $chart['net'], 'profit' => $chart['profit'], 'orders' => $chart['orders'],
            'mua' => $muaTheoMoc['tien'], 'nhap' => $muaTheoMoc['soLuong'],
        ],
        'thanhToan' => $vongThanhToan,
        'nguon' => $vongNguon,
        'linkDon' => route('admin.orders.index'),
        'chu' => ['thuan' => __('message.net-revenue'), 'lai' => 'Lợi nhuận gộp', 'truoc' => 'Kỳ trước'],
    ];

    $topSelect = function (string $id, string $param) use ($filters, $topChoices) {
        return ['id' => $id, 'param' => $param, 'chon' => $filters[$param], 'ds' => $topChoices];
    };
    $hangBan = fn (array $sp) => ! empty($sp['product_id'])
        ? '<a href="'.e(route('admin.reports.goods', $ky + ['product_id' => $sp['product_id']])).'">'.e($sp['name'] ?? '—').'</a>'
        : e($sp['name'] ?? '—');
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
                 KHÔNG đổi chi nhánh làm việc của tab. Một chi nhánh thì không bày. --}}
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
                        {{-- Nhớ kỳ: thói quen của từng máy (localStorage), không phải cấu hình cửa hàng. --}}
                        <label class="db-nho-ky" title="Mở lại Tổng quan vẫn giữ kỳ và chi nhánh đang chọn">
                            <input type="checkbox" class="form-check-input m-0" id="db-nho-ky"> Nhớ kỳ
                        </label>
                    </div>
                    <div class="card-body px-2">
                        {{-- Form GET: chọn kỳ là nạp lại trang với ?range= hoặc ?from=&to=. Hai ô
                             ngày và nhóm mốc loại trừ nhau. Mốc là radio ẩn trong nhãn dạng chip. --}}
                        <form method="GET" action="{{ route('admin.dashboard') }}" id="db-filter">
                            <input type="hidden" name="branch" value="{{ $filters['branch'] }}">
                            <input type="hidden" name="top_products" value="{{ $filters['top_products'] }}">

                            @foreach ($rangeGroups as $tieuDe => $maDS)
                                <div class="ky-nhom">
                                    <span>{{ \Illuminate\Support\Str::after($tieuDe, 'Theo ') }}</span>
                                    <div class="ky-chip">
                                        @foreach ($maDS as $ma)
                                            <label class="db-opt {{ $filters['range'] === $ma ? 'is-active' : '' }}" for="db-range-{{ $ma }}">
                                                <input class="db-range" type="radio" name="range"
                                                    value="{{ $ma }}" id="db-range-{{ $ma }}"
                                                    {{ $filters['range'] === $ma ? 'checked' : '' }}>
                                                <span>{{ $rangeLabels[$ma] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            {{-- Ô chữ + daterangepicker, KHÔNG phải input[type=date]: lịch của
                                 trình duyệt in theo ngôn ngữ máy nên trang tiếng Việt lại hiện 09/26/2026. --}}
                            <input class="d-none" type="radio" id="db-range-custom" aria-hidden="true"
                                {{ $filters['range'] === null ? 'checked' : '' }}>
                            <span class="title_search d-block mt-2 mb-1">Tùy chỉnh</span>
                            <div class="d-flex flex-column gap-1">
                                <input type="text" class="form-control form-control-sm" name="from" id="db-from"
                                    autocomplete="off" value="{{ $dmy($filters['from']) }}"
                                    placeholder="{{ __('message.from_date') }}" aria-label="{{ __('message.from_date') }}">
                                <input type="text" class="form-control form-control-sm" name="to" id="db-to"
                                    autocomplete="off" value="{{ $dmy($filters['to']) }}"
                                    placeholder="{{ __('message.to_date') }}" aria-label="{{ __('message.to_date') }}">
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- ====================== NỘI DUNG ====================== --}}
        <div class="col-12 col-lg-10 wrapper-content-dashboard-middle">
            <div class="content_dashboard_mid">
                {{-- Tiêu đề trang: lớp .tieu-de-trang dùng chung mọi màn v2 (xem V2PageTitleTest). --}}
                <div class="db-title">
                    <h1 class="tieu-de-trang mb-0">{{ __('message.overview') }}</h1>
                    <div class="db-title-meta">
                        <span>@if ($tenKy)<b>{{ $tenKy }}</b> · @endif{{ $khoangKy }}</span>
                        @if ($kyTruocNgan)
                            <span class="db-sep"></span>
                            <span title="Kỳ trước cùng độ dài, dùng để so sánh: {{ $kyTruoc }}">So với {{ $kyTruocNgan }}</span>
                        @endif
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

                {{-- ---------- Sáu ô KPI + đường xu hướng trong kỳ ---------- --}}
                <div class="db-kpis">
                    @foreach ($oKpi as $o)
                        <a href="{{ $o['link'] }}" class="db-kpi" style="--db-mau: {{ $o['mau'] }}">
                            <div class="db-kpi-tren">
                                <div class="db-kpi-text">
                                    <div class="db-kpi-head">
                                        <h3>{{ $o['nhan'] }}</h3>
                                        <span class="db-info" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $o['tip'] }}">
                                            <i class="bi bi-info-circle-fill"></i>
                                        </span>
                                    </div>
                                    <span class="db-kpi-so" title="{{ $o['day_du'] }}">{{ $o['so'] }}</span>
                                    @if ($o['chip'] || $o['phu'])
                                        <div class="db-kpi-chan">
                                            @if ($o['chip'])
                                                <span class="db-chip {{ $o['chip']['lop'] }}" title="{{ $tipKy }}">{{ $o['chip']['chu'] }}</span>
                                            @endif
                                            @if ($o['phu'])
                                                <span class="db-kpi-phu">{{ $o['phu'] }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($o['phu2'])
                                        <span class="db-kpi-phu">{{ $o['phu2'] }}</span>
                                    @endif
                                </div>
                                <span class="db-kpi-icon"><img src="{{ asset('v2/images/'.$o['icon']) }}" alt="" width="64" height="64"></span>
                            </div>
                            <div class="db-spark"><canvas data-spark="{{ $o['spark'] }}"></canvas></div>
                        </a>
                    @endforeach
                </div>

                {{-- ---------- Mục tiêu doanh thu tháng ---------- --}}
                @if ($mucTieu)
                    <div class="db-goal">
                        <div class="db-goal-label">
                            <b>Mục tiêu doanh thu tháng {{ $mucTieu['thang'] }}</b>
                            <span>
                                {{ $mucTieu['muc_tieu'] > 0 ? $tien($mucTieu['muc_tieu']).' đ (doanh thu thuần)' : 'Chưa đặt mục tiêu' }}
                                @if ($mucTieu['con_ngay'])
                                    · còn {{ $mucTieu['con_ngay'] }} ngày
                                @endif
                            </span>
                        </div>
                        @if ($mucTieu['pct'] !== null)
                            <div class="db-goal-bar">
                                <div class="db-goal-track">
                                    <div style="width: {{ min(100, $mucTieu['pct']) }}%"></div>
                                    @if ($mucTieu['can_dat_pct'] !== null)
                                        <i style="left: {{ round($mucTieu['can_dat_pct'], 1) }}%" title="Tiến độ cần đạt tới hôm nay"></i>
                                    @endif
                                </div>
                                <div class="db-goal-note">
                                    <span>Đã đạt {{ $tien($mucTieu['dat']) }} đ</span>
                                    <span>
                                        {{ $mucTieu['dat'] >= $mucTieu['muc_tieu'] ? 'Vượt '.$tien($mucTieu['dat'] - $mucTieu['muc_tieu']).' đ' : 'Còn thiếu '.$tien($mucTieu['muc_tieu'] - $mucTieu['dat']).' đ' }}
                                        @if ($mucTieu['moi_ngay'])
                                            · cần {{ $gon($mucTieu['moi_ngay']) }} đ / ngày
                                        @endif
                                    </span>
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
                            {{-- Bỏ mục tiêu là NÚT RIÊNG, không phải hệ quả của ô rỗng. --}}
                            @if ($mucTieu['muc_tieu'] > 0)
                                <button type="submit" name="bo_muc_tieu" value="1" class="btn btn-sm btn-outline-danger">Bỏ mục tiêu</button>
                            @endif
                        </form>
                    </div>
                @endif

                {{-- ---------- Hàng 1: doanh thu · cần chú ý + ca ---------- --}}
                <div class="row g-2 mb-2">
                    <div class="col-12 col-xl-8">
                        <div class="div-chart h-100">
                            <div class="chart-header d-flex align-items-center gap-2 flex-wrap">
                                <h6 class="mb-0 fw-bold me-auto"><i class="fa-solid fa-chart-column db-h-ic" style="--ic: #3b82f6" aria-hidden="true"></i>{{ __('message.sales_revenue') }}</h6>
                                <label class="db-so-sanh"><input type="checkbox" class="form-check-input m-0" id="db-ky-truoc" checked> So với kỳ trước</label>
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
                                <div class="db-hint">Cột: doanh thu thuần · Đường xanh: lợi nhuận gộp · Nét đứt: doanh thu thuần kỳ trước · bấm vào cột để mở danh sách đơn của mốc đó</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-4 d-flex flex-column gap-2">
                        <div class="div-chart db-alert">
                            <div class="chart-header d-flex align-items-center">
                                <h6 class="mb-0 fw-bold flex-grow-1"><i class="fa-solid fa-triangle-exclamation db-h-ic" style="--ic: #ea580c" aria-hidden="true"></i>Cần chú ý</h6>
                                @if (count($canhBao))
                                    <span class="db-pill db-pill--cam">{{ count($canhBao) }}</span>
                                @endif
                            </div>
                            <div class="chart-body p-2">
                                @forelse ($canhBao as $cb)
                                    <a href="{{ $cb['link'] }}" class="db-alert-row"><span class="db-dot"></span><span>{!! $cb['chu'] !!}</span><i class="fa-solid fa-chevron-right"></i></a>
                                @empty
                                    <p class="text-muted small mb-0 px-1">Không có việc gì cần chú ý.</p>
                                @endforelse
                            </div>
                        </div>
                        <div class="div-chart flex-grow-1">
                            <div class="chart-header d-flex align-items-center">
                                <h6 class="mb-0 fw-bold flex-grow-1"><i class="fa-solid fa-cash-register db-h-ic" style="--ic: #16a34a" aria-hidden="true"></i>Ca đang mở</h6>
                                @if ($caMo)
                                    <span class="db-pill db-pill--xanh">{{ count($caMo) }} ca</span>
                                @endif
                            </div>
                            <div class="chart-body p-2 db-shift-body">
                                @forelse ($caMo as $ca)
                                    <div class="db-ca">
                                        <div class="db-ca-dau">
                                            <b title="Ca {{ $ca['ma'] }}">{{ $ca['chi_nhanh'] !== '' ? $ca['chi_nhanh'] : 'Ca '.$ca['ma'] }}</b>
                                            <span>{{ $ca['nguoi_mo'] !== '' ? $ca['nguoi_mo'] : '—' }} · mở {{ $ca['gio_mo'] ? date('d/m H:i', strtotime($ca['gio_mo'])) : '—' }}</span>
                                        </div>
                                        <div class="db-ca-tien">
                                            <span>Tiền mặt trong két</span>
                                            <b>{{ $tien($ca['tien_mat']) }} đ</b>
                                            <small>{{ $tien($ca['dau_ca']) }} đầu ca + {{ $tien($ca['thu']) }} thu − {{ $tien($ca['chi']) }} chi · {{ $tien($ca['so_don']) }} đơn tiền mặt</small>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-center noti-error-shift fw-bold alert alert-danger mb-0">{{ __('message.no_shift_selected') }}</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---------- Hàng 2: bán chạy · giờ cao điểm ---------- --}}
                <div class="row g-2 mb-2">
                    <div class="col-12 col-xl-6">
                        <div class="div-chart h-100">
                            <div class="chart-header d-flex align-items-center gap-2">
                                <h6 class="mb-0 fw-bold flex-grow-1"><i class="fa-solid fa-fire db-h-ic" style="--ic: #ef4444" aria-hidden="true"></i>Sản phẩm bán chạy</h6>
                                <div class="db-seg" role="group" aria-label="Xếp theo">
                                    <a href="#" class="is-active" data-ban="sl">Số lượng</a><a href="#" data-ban="dt">Doanh thu</a>
                                </div>
                                @include('v2::dashboard._top-select', $topSelect('quantityBestSeller', 'top_products'))
                            </div>
                            <div class="chart-body p-2">
                                @foreach (['sl' => $banChay['sl'], 'dt' => $banChay['dt']] as $loai => $ds)
                                    @php
                                        $max = max([1, ...array_map(fn ($sp) => (float) ($loai === 'sl' ? ($sp['units'] ?? 0) : ($sp['revenue'] ?? 0)), $ds)]);
                                    @endphp
                                    <div data-ban-ds="{{ $loai }}" class="db-cuon {{ $loai === 'dt' ? 'd-none' : '' }}">
                                        @forelse ($ds as $i => $sp)
                                            <div class="db-ban">
                                                <span class="db-rank db-rank--{{ $i + 1 }}">{{ $i + 1 }}</span>
                                                <span class="ten" title="{{ $sp['name'] ?? '' }}">{!! $hangBan($sp) !!}</span>
                                                <span class="so">
                                                    @if ($loai === 'sl')
                                                        {{ $tien($sp['units'] ?? 0) }}<small>{{ $gon($sp['revenue'] ?? 0) }} đ</small>
                                                    @else
                                                        {{ $gon($sp['revenue'] ?? 0) }} đ<small>{{ $tien($sp['units'] ?? 0) }} cái</small>
                                                    @endif
                                                </span>
                                                <div class="db-bar"><div style="width: {{ round((float) ($loai === 'sl' ? ($sp['units'] ?? 0) : ($sp['revenue'] ?? 0)) / $max * 100, 1) }}%"></div></div>
                                            </div>
                                        @empty
                                            <p class="text-center text-muted my-4">{{ __('message.no_data') }}</p>
                                        @endforelse
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-6">
                        <div class="div-chart h-100">
                            <div class="chart-header d-flex justify-content-between align-items-center gap-2">
                                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-clock db-h-ic" style="--ic: #8b5cf6" aria-hidden="true"></i>Giờ cao điểm</h6>
                                <span class="small text-muted text-end">
                                    @if ($gioCaoDiem['dinh'] !== null)
                                        Số đơn theo giờ · đông nhất <b>{{ $gioCaoDiem['dinh'] }}h</b> ({{ collect($gioCaoDiem['gio'])->firstWhere('h', $gioCaoDiem['dinh'])['don'] ?? 0 }} đơn)
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
                                                style="height: {{ $g['don'] ? max(4, round($g['don'] / $maxGio * 120)) : 3 }}px"></div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="db-hours db-hours--label">
                                    @foreach ($gioCaoDiem['gio'] as $g)
                                        <div class="{{ $g['h'] % 2 ? 'gio-le' : '' }}">{{ $g['h'] }}h</div>
                                    @endforeach
                                </div>
                                <div class="db-thu" title="Số đơn theo thứ trong tuần">
                                    @foreach ($theoThu as $t)
                                        <div title="{{ $t['nhan'] }}: {{ $t['don'] }} đơn"><b style="opacity: {{ round(0.15 + $t['don'] / $maxThu * 0.85, 2) }}"></b>{{ $t['nhan'] }} · {{ $t['don'] }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---------- Hàng 3: bốn thẻ cơ cấu ---------- --}}
                <div class="row g-2">
                    @foreach ([['id' => 'myChart2', 'tieu_de' => 'Phương thức thanh toán', 'ds' => $vongThanhToan, 'icon' => 'fa-credit-card', 'mau_ic' => '#0ea5e9'], ['id' => 'myChart3', 'tieu_de' => 'Nguồn đơn hàng', 'ds' => $vongNguon, 'icon' => 'fa-store', 'mau_ic' => '#2bb5b5']] as $the)
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="div-chart h-100">
                                <div class="chart-header"><h6 class="mb-0 fw-bold"><i class="fa-solid {{ $the['icon'] }} db-h-ic" style="--ic: {{ $the['mau_ic'] }}" aria-hidden="true"></i>{{ $the['tieu_de'] }}</h6></div>
                                <div class="chart-body p-2">
                                    @if (count($the['ds']))
                                        <div class="db-donut">
                                            <div class="db-donut-ring">
                                                <canvas id="{{ $the['id'] }}" width="130" height="130"></canvas>
                                                <div class="db-donut-center"><b>{{ $tien($kpi['orders']) }}</b><span>đơn</span></div>
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
                            <div class="chart-header d-flex align-items-center gap-2">
                                <h6 class="mb-0 fw-bold flex-grow-1 text-nowrap"><i class="fa-solid fa-tags db-h-ic" style="--ic: #e11d48" aria-hidden="true"></i>Khuyến mại được dùng</h6>
                                <a href="{{ route('admin.reports.promotions', $ky + ['tab' => $khuyenMai['tab'], 'shop_id' => $filters['branch']]) }}" class="db-xem" title="Xem báo cáo khuyến mại"><span class="db-xem-chu">Báo cáo </span>→</a>
                            </div>
                            <div class="chart-body p-2">
                                @if (count($khuyenMai['ds']))
                                    <div class="db-phu">Tiền giảm trong kỳ · {{ $tien($khuyenMai['tong']) }} đ trên {{ $tien($khuyenMai['luot']) }} lượt dùng</div>
                                    <div class="db-cuon">
                                    @foreach ($khuyenMai['ds'] as $km)
                                        <div class="db-dong">
                                            <span title="{{ $km['ten'] }}">{{ $km['ten'] }}</span>
                                            <b>{{ $gon($km['giam']) }} đ<small>{{ $km['luot'] }} đơn</small></b>
                                            <div class="db-bar"><div style="width: {{ round($km['giam'] / $maxKm * 100, 1) }}%; background: #e11d48"></div></div>
                                        </div>
                                    @endforeach
                                    </div>
                                @else
                                    <div class="db-empty">
                                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <path d="M20 12l-8 8-9-9V3h8z"></path><circle cx="7.5" cy="7.5" r="1.5"></circle>
                                        </svg>
                                        <span>Kỳ này chưa đơn nào dùng khuyến mại</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="div-chart h-100">
                            <div class="chart-header"><h6 class="mb-0 fw-bold"><i class="fa-solid fa-shop db-h-ic" style="--ic: #1d4ed8" aria-hidden="true"></i>Doanh thu theo chi nhánh</h6></div>
                            <div class="chart-body p-2">
                                @if (count($theoChiNhanh))
                                    <div class="db-phu">Doanh thu gộp · {{ count($theoChiNhanh) }} chi nhánh</div>
                                @endif
                                <div class="db-cuon">
                                @forelse ($theoChiNhanh as $i => $cn)
                                    <div class="db-dong">
                                        <span>{{ $cn['nhan'] }}</span>
                                        <b title="{{ $tien($cn['revenue'] ?? 0) }} đ">{{ $gon($cn['revenue'] ?? 0) }} đ<small>{{ $tien($cn['orders'] ?? 0) }} đơn</small></b>
                                        <div class="db-bar"><div style="width: {{ round((float) ($cn['revenue'] ?? 0) / $maxChiNhanh * 100, 1) }}%; background: #1d4ed8"></div></div>
                                    </div>
                                @empty
                                    <p class="text-center text-muted my-4">{{ __('message.no_data') }}</p>
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
        /* ---------- Khung lọc ---------- */
        .db-loc .header_search { font-weight: 600; }
        .fillter-box-container .fillter-box + .fillter-box { margin-top: 10px; }
        .db-nho-ky { display: flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 500; cursor: pointer; margin: 0; }
        .ky-nhom { margin-bottom: 8px; }
        .ky-nhom > span { display: block; font-size: 11px; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; color: #64748b; margin: 0 2px 4px; }
        .ky-chip { display: grid; grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 4px; }
        .ky-chip .db-opt { display: block; margin: 0; padding: 4px 2px; border: 1px solid #dbe2ec; border-radius: 6px; font-size: 12.5px; text-align: center; color: #0f172a; background: #fff; cursor: pointer; white-space: nowrap; }
        .ky-chip .db-opt input { position: absolute; opacity: 0; pointer-events: none; }
        .ky-chip .db-opt:hover { border-color: #93c5fd; background: #f1f6ff; }
        .ky-chip .db-opt.is-active { background: #1d4ed8; border-color: #1d4ed8; color: #fff; font-weight: 700; }
        .ky-chip .db-opt:focus-within { outline: 2px solid #93c5fd; outline-offset: 1px; }
        #db-from, #db-to { font-size: 12px; padding-left: 6px; padding-right: 6px; }

        /* ---------- Tiêu đề ---------- */
        .db-title { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 16px; margin-bottom: 10px; }
        .db-title-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; font-size: 13px; color: #475569; }
        .db-sep { width: 1px; height: 16px; background: #cbd5e1; }
        .db-refresh { width: 32px; height: 32px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; color: #1d4ed8; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; }
        .db-refresh:hover { background: #eff6ff; }

        /* ---------- KPI ---------- */
        .db-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
        /* Sáu ô một hàng chỉ khi đủ rộng: dưới ~1700px mỗi ô chưa tới 180px. */
        @media (min-width: 1700px) { .db-kpis { grid-template-columns: repeat(6, minmax(0, 1fr)); } }
        .db-kpi { display: flex; flex-direction: column; gap: 4px; min-height: 128px; padding: 10px 12px 6px; background: #fff; overflow: hidden;
                  border: 1px solid #e2e8f0; border-top: 3px solid var(--db-mau); border-radius: 10px; color: #0f172a; text-decoration: none; transition: box-shadow .15s; }
        .db-kpi:hover { color: #0f172a; box-shadow: 0 4px 14px rgba(15, 23, 42, .08); }
        .db-kpi-tren { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
        .db-kpi-text { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .db-kpi-head { display: flex; align-items: center; gap: 5px; }
        .db-kpi-head h3 { font-size: 13px; font-weight: 600; color: #334155; margin: 0; white-space: nowrap; }
        .db-info { color: #cbd5e1; font-size: 12px; line-height: 1; }
        .db-kpi:hover .db-info { color: #94a3b8; }
        /* Icon của bản v2 cũ, nâng cấp: nằm trong ô vuông bo góc nhuốm màu của ô KPI. */
        .db-kpi-icon { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px;
                       border-radius: 14px; background: color-mix(in srgb, var(--db-mau) 13%, #fff);
                       box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--db-mau) 22%, #fff); transition: transform .15s; }
        .db-kpi-icon img { width: 34px; height: 34px; object-fit: contain; }
        .db-kpi:hover .db-kpi-icon { transform: translateY(-2px) scale(1.04); }
        /* Sáu ô một hàng (≥1700px) mỗi ô chỉ ~230px: icon nhỏ lại cho số đủ chỗ. */
        @media (min-width: 1700px) { .db-kpi-icon { width: 42px; height: 42px; border-radius: 12px; } .db-kpi-icon img { width: 28px; height: 28px; } }
        /* 1700–1899px là lúc ô hẹp nhất (~210px): số 9 chữ số + icon chạm nhau, nén cả hai. */
        @media (min-width: 1700px) and (max-width: 1899.98px) {
            .db-kpi { padding-left: 10px; padding-right: 10px; }
            .db-kpi-so { font-size: 18px; }
            .db-kpi-icon { width: 36px; height: 36px; border-radius: 10px; }
            .db-kpi-icon img { width: 24px; height: 24px; }
        }
        .db-h-ic { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; margin-right: 8px; border-radius: 8px;
                   font-size: 12px; vertical-align: middle; color: var(--ic); background: color-mix(in srgb, var(--ic) 13%, #fff); }
        .db-kpi-so { font-size: 21px; font-weight: 800; white-space: nowrap; letter-spacing: -.2px; }
        /* Nhãn so sánh và dòng phụ: hết chỗ thì dòng phụ xuống hàng dưới, không cắt chữ. */
        .db-kpi-chan { display: flex; flex-wrap: wrap; align-items: center; gap: 2px 6px; min-width: 0; }
        .db-kpi-phu { font-size: 11.5px; color: #64748b; white-space: nowrap; }
        .db-chip { font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; }
        .db-chip--len { background: #dcfce7; color: #166534; }
        .db-chip--xuong { background: #fee2e2; color: #991b1b; }
        .db-chip--trung { background: #eef2f7; color: #475569; font-weight: 600; }
        /* Khung cao cố định: Chart.js co theo khung cha, không có khung thì canvas cao mãi. */
        .db-spark { position: relative; height: 34px; margin-top: auto; }

        /* ---------- Mục tiêu ---------- */
        .db-goal { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; padding: 10px 14px; margin-bottom: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; }
        .db-goal-label { display: flex; flex-direction: column; min-width: 200px; font-size: 13px; }
        .db-goal-label span { font-size: 12px; color: #475569; }
        .db-goal-bar { flex: 1 1 240px; }
        .db-goal-track { position: relative; height: 12px; background: #eef2f7; border-radius: 999px; }
        .db-goal-track div { height: 12px; background: #1d4ed8; border-radius: 999px; }
        .db-goal-track i { position: absolute; top: -3px; width: 2px; height: 18px; background: #f97316; }
        .db-goal-note { display: flex; justify-content: space-between; gap: 8px; margin-top: 4px; font-size: 12px; color: #475569; }
        .db-goal-pct { font-size: 22px; font-weight: 800; color: #1d4ed8; }
        .db-goal-form { display: flex; align-items: center; gap: 8px; flex-basis: 100%; }
        .db-goal-form input { max-width: 200px; }

        /* ---------- Thẻ ---------- */
        .db-seg { display: inline-flex; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; }
        .db-seg a { padding: 3px 10px; font-size: 12px; color: #0f172a; text-decoration: none; white-space: nowrap; }
        .db-seg a + a { border-left: 1px solid #cbd5e1; }
        .db-seg a.is-active { background: #1d4ed8; color: #fff; font-weight: 600; }
        .db-so-sanh { display: flex; align-items: center; gap: 5px; font-size: 12px; color: #334155; margin: 0; cursor: pointer; white-space: nowrap; }
        .db-chart-box { position: relative; height: 290px; }
        .db-hint { font-size: 12px; color: #64748b; margin-top: 4px; }
        .db-phu { font-size: 12px; color: #64748b; margin: 0 2px 8px; }
        .db-xem { font-size: 12px; text-decoration: none; white-space: nowrap; }
        /* Thẻ hẹp (dưới 1500px, kèm icon đầu thẻ): link chỉ còn mũi tên, rê chuột ra chữ. */
        @media (max-width: 1499.98px) { .db-xem-chu { display: none; } }
        .db-pill { font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
        .db-pill--xanh { background: #dcfce7; color: #14532d; }
        .db-pill--cam { background: #fed7aa; color: #9a3412; }
        .db-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; min-height: 180px; color: #64748b; font-size: 13px; text-align: center; }

        .db-alert { background: #fff7ed; border-color: #fed7aa; }
        .db-alert .chart-header { color: #7c2d12; }
        .db-alert-row { display: grid; grid-template-columns: 8px 1fr 10px; gap: 8px; align-items: center; padding: 6px 4px; font-size: 13px; color: #0f172a; text-decoration: none; border-radius: 6px; }
        .db-alert-row:hover { background: #ffedd5; color: #0f172a; }
        .db-alert-row .db-dot { width: 8px; height: 8px; background: #c2410c; }
        .db-alert-row i { font-size: 10px; color: #c2410c; }

        .db-shift-body { overflow-y: auto; max-height: 320px; }
        .db-ca + .db-ca { margin-top: 10px; padding-top: 10px; border-top: 1px solid #e2e8f0; }
        .db-ca-dau { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; font-size: 13px; margin-bottom: 6px; }
        .db-ca-dau small { font-weight: 400; color: #64748b; }
        .db-ca-dau span { font-size: 12px; color: #64748b; white-space: nowrap; }
        .db-ca-tien { display: flex; flex-direction: column; padding: 6px 10px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534; font-size: 12px; }
        .db-ca-tien b { font-size: 17px; color: #14532d; }
        .db-ca-tien small { color: #15803d; }

        .db-cuon { overflow-x: auto; }
        .db-ban { display: grid; grid-template-columns: 26px minmax(max-content, 1fr) auto; gap: 4px 10px; align-items: center; padding: 6px 2px; font-size: 13px; }
        .db-ban + .db-ban { border-top: 1px dashed #e2e8f0; }
        /* Luật bảng v2: tên MỘT dòng, KHÔNG cắt "…" — cột tên rộng ít nhất bằng chữ
           (max-content), hẹp quá thì danh sách cuộn ngang trong thẻ (.db-cuon). */
        .db-ban .ten { white-space: nowrap; }
        .db-ban .ten a { color: #0f172a; text-decoration: none; }
        .db-ban .ten a:hover { color: #1d4ed8; text-decoration: underline; }
        .db-ban .so { font-weight: 700; white-space: nowrap; text-align: right; }
        .db-ban .so small { display: block; font-weight: 400; color: #64748b; font-size: 11.5px; }
        .db-ban .db-bar { grid-column: 2 / 3; }
        .db-rank { display: inline-flex; width: 22px; height: 22px; border-radius: 999px; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; background: #e2e8f0; color: #334155; }
        .db-rank--1 { background: #f59e0b; color: #fff; }
        .db-rank--2 { background: #94a3b8; color: #fff; }
        .db-rank--3 { background: #c2773a; color: #fff; }
        .db-bar { height: 6px; background: #eef2f7; border-radius: 4px; overflow: hidden; }
        .db-bar div { height: 6px; border-radius: 4px; background: #3b82f6; }

        .db-hours { display: flex; gap: 5px; }
        .db-hours:not(.db-hours--label) { height: 150px; align-items: flex-end; border-bottom: 1px solid #cbd5e1; }
        /* min-width 0: cột flex không chịu co nhỏ hơn chữ nhãn của nó, 24 cột ở
           khổ điện thoại thì đẩy thẻ tràn ngang. */
        .db-hours > div { min-width: 0; }
        .db-hour { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 2px; }
        .db-hour span { font-size: 11px; font-weight: 700; color: #1e3a8a; }
        .db-hour div { width: 100%; border-radius: 3px 3px 0 0; background: #3b82f6; }
        .db-hour div.is-peak { background: #f97316; }
        .db-hour div.is-empty { background: #e2e8f0; }
        /* Nhãn chỉ in giờ chẵn: 24 nhãn "20h" không vừa cột ở mọi khổ dưới 1920
           (rê chuột lên cột vẫn ra giờ + số đơn). */
        .db-hours--label div { flex: 1; text-align: center; font-size: 10.5px; color: #64748b; white-space: nowrap; }
        .db-hours--label .gio-le { visibility: hidden; }
        .db-thu { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; margin-top: 14px; }
        .db-thu div { text-align: center; font-size: 11.5px; color: #475569; white-space: nowrap; }
        .db-thu b { display: block; height: 28px; border-radius: 6px; margin-bottom: 3px; background: #3b82f6; }

        .db-donut { display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .db-donut-ring { position: relative; width: 130px; height: 130px; flex: 0 0 auto; }
        .db-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
        .db-donut-center b { font-size: 18px; }
        .db-donut-center span { font-size: 11px; color: #475569; }
        .db-legend { list-style: none; margin: 0; padding: 0; width: 100%; display: flex; flex-direction: column; gap: 6px; }
        .db-legend li { display: grid; grid-template-columns: 10px 1fr auto; column-gap: 6px; align-items: center; font-size: 13px; }
        .db-legend small { grid-column: 2 / 4; color: #475569; }
        .db-dot { width: 10px; height: 10px; border-radius: 999px; display: inline-block; flex: 0 0 auto; }

        .db-dong { display: grid; grid-template-columns: minmax(max-content, 1fr) auto; gap: 3px 10px; align-items: center; font-size: 13px; padding: 5px 2px; }
        .db-dong > span { white-space: nowrap; }
        .db-dong > b { white-space: nowrap; }
        .db-dong > b small { font-weight: 400; color: #64748b; margin-left: 4px; }
        .db-dong .db-bar { grid-column: 1 / 3; height: 8px; }
        .db-dong .db-bar div { height: 8px; }

        @media (max-width: 767px) { .db-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .db-hours:not(.db-hours--label) { height: 110px; } }
        /* Điện thoại: 16–24 cột giờ trong ~330px — khe hẹp lại, nhãn giờ chỉ in giờ chẵn. */
        @media (max-width: 575px) {
            .db-hours { gap: 2px; }
            .db-hour span { font-size: 9px; }
            .db-hours--label div { font-size: 9px; }
            .db-thu { gap: 3px; }
            .db-thu div { font-size: 10px; white-space: normal; }
        }
        /* Điện thoại: ô KPI chỉ ~165px — icon nhường chỗ cho số, như bản v2. */
        @media (max-width: 575px) { .db-kpi-chan { flex-wrap: wrap; } .db-kpi-so { font-size: 18px; } .db-kpi-phu { white-space: normal; } .db-kpi-icon { display: none; } .db-kpi-head h3 { white-space: normal; } }
    </style>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const soLieu = @json($duLieuJs);
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(v));
            const gon = (v) => Math.abs(v) >= 1e9 ? (v / 1e9).toFixed(2).replace('.', ',') + ' tỷ'
                : Math.abs(v) >= 1e6 ? (v / 1e6).toFixed(1).replace('.', ',') + ' tr' : tien(v);
            const ve = (o, cau) => (o ? new Chart(o.getContext('2d'), cau) : null);

            // ---------- Doanh thu: cột thuần + đường lợi nhuận + nét đứt kỳ trước ----------
            // Bấm vào cột là mở Quản lý đơn hàng đúng khoảng ngày của cột đó.
            const c = soLieu.chart;
            const moDon = (i) => {
                const r = c.ranges[i];
                if (!r) return;
                const u = new URL(soLieu.linkDon, window.location.origin);
                u.searchParams.set('from_date', r[0]);
                u.searchParams.set('to_date', r[1]);
                window.location.href = u.toString();
            };
            const doanhThu = ve(document.getElementById('myChart1'), {
                data: {
                    labels: c.labels,
                    datasets: [
                        { type: 'line', label: soLieu.chu.lai, data: c.profit, borderColor: '#16a34a', backgroundColor: '#16a34a', cubicInterpolationMode: 'monotone', pointRadius: c.labels.length > 1 ? 0 : 4, borderWidth: 2, order: 0 },
                        { type: 'line', label: soLieu.chu.truoc, data: c.prev, borderColor: '#94a3b8', backgroundColor: '#94a3b8', borderDash: [5, 4], cubicInterpolationMode: 'monotone', pointRadius: 0, borderWidth: 1.5, order: 1, spanGaps: true },
                        { type: 'bar', label: soLieu.chu.thuan, data: c.net, backgroundColor: '#3b82f6', borderRadius: 3, maxBarThickness: 28, order: 2 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8 } },
                        tooltip: { callbacks: { label: (x) => `${x.dataset.label}: ${tien(x.parsed.y)} đ` } },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
                        y: { beginAtZero: true, ticks: { callback: (v) => gon(v) } },
                    },
                    onClick: (e, els, chart) => {
                        const p = chart.getElementsAtEventForMode(e, 'index', { intersect: false }, false);
                        if (p.length) moDon(p[0].index);
                    },
                    onHover: (e, els, chart) => { chart.canvas.style.cursor = els.length ? 'pointer' : 'default'; },
                },
            });
            document.getElementById('db-ky-truoc').addEventListener('change', (e) => {
                if (!doanhThu) return;
                doanhThu.data.datasets[1].hidden = !e.target.checked;
                doanhThu.update();
            });

            // ---------- Đường xu hướng trong ô KPI (ẩn khi kỳ chỉ có một mốc) ----------
            document.querySelectorAll('[data-spark]').forEach((o) => {
                const ds = soLieu.spark[o.dataset.spark] || [];
                if (ds.length < 2) { o.parentElement.remove(); return; }
                const mau = getComputedStyle(o.closest('.db-kpi')).getPropertyValue('--db-mau').trim();
                ve(o, {
                    type: 'line',
                    data: { labels: ds.map((_, i) => i), datasets: [{ data: ds, borderColor: mau, backgroundColor: mau + '22', fill: true, tension: .35, pointRadius: 0, borderWidth: 2 }] },
                    options: { responsive: true, maintainAspectRatio: false, animation: false, events: [],
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                        scales: { x: { display: false }, y: { display: false, beginAtZero: true } } },
                });
            });

            // ---------- Vòng ----------
            const tron = (id, ds) => {
                if (!ds.length) return;
                ve(document.getElementById(id), {
                    type: 'doughnut',
                    data: { labels: ds.map((r) => r.nhan), datasets: [{ data: ds.map((r) => r.gia_tri), backgroundColor: ds.map((r) => r.mau), borderWidth: 2 }] },
                    options: {
                        responsive: false, cutout: '68%',
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (x) => `${x.label}: ${ds[x.dataIndex].chu}` } } },
                    },
                });
            };
            tron('myChart2', soLieu.thanhToan);
            tron('myChart3', soLieu.nguon);

            // ---------- Bán chạy: Số lượng | Doanh thu ----------
            document.querySelectorAll('[data-ban]').forEach((a) => a.addEventListener('click', (e) => {
                e.preventDefault();
                document.querySelectorAll('[data-ban]').forEach((b) => b.classList.toggle('is-active', b === a));
                document.querySelectorAll('[data-ban-ds]').forEach((d) => d.classList.toggle('d-none', d.dataset.banDs !== a.dataset.ban));
            }));

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
            // Đổi kỳ / đổi Top là nạp lại trang: lọc bằng tham số trên URL như mọi màn v2.
            const form = document.getElementById('db-filter');
            document.querySelectorAll('.db-range').forEach((o) => o.addEventListener('change', () => {
                // `disabled` chứ không chỉ xoá giá trị: ô rỗng vẫn được gửi và để lại `?from=&to=`.
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
                        singleDatePicker: true, showDropdowns: true, locale: V2.lichVN(), autoUpdateInput: false, autoApply: true,
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
