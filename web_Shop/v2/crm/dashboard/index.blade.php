{{-- CRM → Tổng quan — theo bản thiết kế design/html/crm-tong-quan.html (duyệt
     11/10/2026). Số liệu theo CrmDashboardService của v2; khuôn thẻ / ô KPI
     chép từ Thống kê → Tổng quan (v2::dashboard.index) để hai màn tổng quan
     nhìn như một.

     Dữ liệu do CrmDashboardController::index đẩy sang: $bc (GET /admin/reports/crm),
     $filters, $chiNhanh, $lienKet, $capNhatLuc. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\CrmDashboardController::TITLE)

@push('styles')
    <link href="{{ asset('v2/css/custom-dashboard.css') }}" rel="stylesheet">
@endpush

@php
    $C = \App\Http\Controllers\CrmDashboardController::class;
    $k = $bc['kpis'] ?? [];
    $so = fn ($v) => number_format((float) $v, 0, ',', '.');
    $pt = fn ($v) => rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ',').'%';
    $dmy = fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d-m-Y');

    // "▲ 12,4% so với tháng trước" — trước bằng 0 thì không có phần trăm để nói.
    $soSanh = function ($nay, $truoc, string $voi) use ($pt) {
        $nay = (float) $nay;
        $truoc = (float) $truoc;
        if ($truoc == 0.0) {
            return $nay == 0.0 ? null : ['chu' => 'Mới phát sinh so với '.$voi, 'lop' => 'db-up'];
        }
        $d = ($nay - $truoc) / $truoc * 100;

        return ['chu' => ($d >= 0 ? '▲ ' : '▼ ').$pt(abs($d)).' so với '.$voi, 'lop' => $d >= 0 ? 'db-up' : 'db-down'];
    };
    $kyTruoc = isset($bc['prev_from']) ? 'Kỳ trước: '.$dmy($bc['prev_from']).' → '.$dmy($bc['prev_to']) : '';

    $chenhDiem = (float) ($k['repeat_rate'] ?? 0) - (float) ($k['repeat_rate_prev'] ?? 0);
    $oKpi = [
        ['nhan' => 'Doanh thu', 'so' => $so($k['revenue'] ?? 0), 'mau' => '#2bb5b5', 'link' => $lienKet['doanh_thu'],
            'tip' => 'Tổng tiền các đơn còn hiệu lực (không huỷ / hoàn) tạo trong kỳ',
            'phu' => array_filter([$soSanh($k['revenue'] ?? 0, $k['revenue_last_month'] ?? 0, 'tháng trước'), $soSanh($k['revenue'] ?? 0, $k['revenue_last_year'] ?? 0, 'năm trước')])],
        ['nhan' => 'Tổng số đơn', 'so' => $so($k['orders'] ?? 0), 'mau' => '#3b82f6', 'link' => $lienKet['don'],
            'tip' => 'Đơn còn hiệu lực tạo trong kỳ, gồm cả đơn khách lẻ', 'phu' => array_filter([$soSanh($k['orders'] ?? 0, $k['orders_prev'] ?? 0, 'kỳ trước')])],
        ['nhan' => 'Giá trị TB / đơn', 'so' => $so($k['aov'] ?? 0), 'mau' => '#8b5cf6', 'link' => $lienKet['don'],
            'tip' => 'Doanh thu ÷ số đơn', 'phu' => array_filter([$soSanh($k['aov'] ?? 0, $k['aov_prev'] ?? 0, 'kỳ trước')])],
        ['nhan' => 'Khách mua hàng', 'so' => $so($k['buyers'] ?? 0), 'mau' => '#f97316', 'link' => $lienKet['khach_mua'],
            'tip' => 'Khách có hồ sơ mua ít nhất 1 đơn trong kỳ', 'phu' => [],
            'ghi' => 'Mua 1 lần: <b>'.$so($k['once'] ?? 0).'</b> · 2 lần: <b>'.$so($k['twice'] ?? 0).'</b>'],
        ['nhan' => 'Khách đăng ký mới', 'so' => $so($k['new_customers'] ?? 0), 'mau' => '#16a34a', 'link' => $lienKet['dang_ky'],
            'tip' => 'Hồ sơ khách tạo trong kỳ (cả cửa hàng — hồ sơ khách không thuộc chi nhánh nào)',
            'phu' => array_filter([$soSanh($k['new_customers'] ?? 0, $k['new_customers_prev'] ?? 0, 'kỳ trước')])],
        ['nhan' => 'Tỉ lệ quay lại', 'so' => $pt($k['repeat_rate'] ?? 0), 'mau' => '#e11d48', 'link' => $lienKet['khach_mua'],
            'tip' => 'Khách mua từ 2 đơn trở lên ÷ khách mua hàng',
            'phu' => ($k['buyers'] ?? 0) || ($k['buyers_prev'] ?? 0)
                ? [['chu' => ($chenhDiem >= 0 ? '▲ ' : '▼ ').rtrim(rtrim(number_format(abs($chenhDiem), 1, ',', '.'), '0'), ',').' điểm so với kỳ trước', 'lop' => $chenhDiem >= 0 ? 'db-up' : 'db-down']]
                : []],
    ];

    $tongMua = max(1, array_sum(array_column($bc['purchase_count'] ?? [], 'count')));
    $mauSoLan = ['#cbd5e1', '#3b82f6', '#2bb5b5', '#f97316'];
    $mauGioi = ['female' => '#e11d48', 'male' => '#3b82f6', 'other' => '#cbd5e1'];
    $mauQuayLai = ['#16a34a', '#2bb5b5', '#3b82f6', '#8b5cf6', '#cbd5e1'];

    $hang = array_values(array_filter($bc['ranks'] ?? [], fn ($r) => (int) $r['rank_id'] > 0));
    $chuaHang = collect($bc['ranks'] ?? [])->firstWhere('rank_id', 0) ?? ['existing' => 0, 'new' => 0, 'spend' => 0];

    $care = $bc['care'] ?? [];
    $tenLoai = $C::LOAI_KHACH[$filters['type']] === 'Tất cả' ? 'Tất cả khách' : 'Khách '.mb_strtolower($C::LOAI_KHACH[$filters['type']]);
    $tenCN = $filters['branch'] ? (collect($chiNhanh)->firstWhere('id', $filters['branch'])['name'] ?? '') : 'Tất cả chi nhánh';
    $moTaKy = ($filters['range'] ? $C::KY[$filters['range']].': ' : '').$dmy($filters['from']).' → '.$dmy($filters['to']);

    $namNay = (int) now()->year;
    $namMin = min($namNay, (int) ($bc['year_min'] ?? $namNay));
    $thangDK = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i));

    // Dữ liệu biểu đồ dựng sẵn bằng PHP rồi mới @json — @json nhiều dòng bên trong
    // <script> làm Blade cắt sai.
    $duLieuJs = [
        'growth' => $bc['growth'] ?? [],
        'orderValue' => $bc['order_value'] ?? [],
        'regDaily' => $bc['reg_daily'] ?? [],
        'ranks' => $hang,
        'purchase' => array_map(fn ($b, $i) => ['nhan' => $b['label'], 'gia_tri' => $b['count'], 'mau' => $mauSoLan[$i] ?? '#94a3b8'], $bc['purchase_count'] ?? [], array_keys($bc['purchase_count'] ?? [])),
        'gender' => array_map(fn ($b) => ['nhan' => $b['label'], 'gia_tri' => $b['count'], 'mau' => $mauGioi[$b['key']] ?? '#94a3b8'], $bc['gender'] ?? []),
        'orders' => (int) ($k['orders'] ?? 0),
        'chuaHang' => $chuaHang,
    ];

    $thanh = function (array $ds, $mau) use ($so, $pt) {
        $tong = max(1, array_sum(array_column($ds, 'count')));
        $max = max(1, ...array_map(fn ($b) => (int) $b['count'], $ds ?: [['count' => 1]]));

        return array_map(fn ($b, $i) => [
            'nhan' => $b['label'], 'so' => $so($b['count']), 'pt' => $pt($b['count'] * 100 / $tong),
            'rong' => round($b['count'] * 100 / $max, 1), 'mau' => is_array($mau) ? ($mau[$i] ?? '#94a3b8') : $mau,
        ], $ds, array_keys($ds));
    };
    $tongTuoi = array_sum(array_column($bc['age'] ?? [], 'count'));
    $tongTinh = array_sum(array_column($bc['provinces'] ?? [], 'count'));
    $tongGioi = array_sum(array_column($bc['gender'] ?? [], 'count'));
@endphp

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            {{-- Dưới 992px cột lọc ẩn: mỗi khối lọc phải có một nút mở riêng, thiếu nút
                 là khối đó không dùng được trên máy tính bảng / điện thoại. --}}
            @foreach (['crmLocChiNhanh' => __('message.branch'), 'crmLocLoai' => 'Loại khách', 'crmLocKy' => __('message.time')] as $oLoc => $nhan)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => $oLoc,
                    'modalLabel' => $nhan,
                ])
            @endforeach
        </div>
    </div>

    {{-- Form GET: đổi ô nào là nạp lại trang với bộ lọc mới. Hai ô ngày và nhóm
         mốc kỳ loại trừ nhau — gửi cả hai thì controller không biết vừa đổi cái nào. --}}
    <form method="GET" action="{{ route('admin.crm.dashboard') }}" id="crm-loc">
        <input type="hidden" name="branch" value="{{ $filters['branch'] }}">
        <input type="hidden" name="type" value="{{ $filters['type'] }}">
        <input type="hidden" name="range" value="{{ $filters['range'] }}">
        <input type="hidden" name="from" value="">
        <input type="hidden" name="to" value="">
        @if ($filters['year'])<input type="hidden" name="year" value="{{ $filters['year'] }}">@endif
        @if ($filters['reg_month'])<input type="hidden" name="reg_month" value="{{ $filters['reg_month'] }}">@endif
    </form>

    <div class="row">
        {{-- ====================== KHUNG LỌC TRÁI ====================== --}}
        <div class="col-12 col-md-2 pe-lg-0 fillter-box-container">
            @if (count($chiNhanh) > 1)
                <div id="crmLocChiNhanh" class="fillter-box">
                    <div class="card inner-modal-in-mobile db-loc">
                        <div class="card-header header_search">{{ __('message.branch') }}</div>
                        <div class="card-body px-2">
                            <select class="form-control form-select w-100" id="crm-branch">
                                <option value="0" {{ $filters['branch'] === 0 ? 'selected' : '' }}>{{ __('message.all') }}</option>
                                @foreach ($chiNhanh as $cn)
                                    <option value="{{ $cn['id'] }}" {{ $filters['branch'] === (int) $cn['id'] ? 'selected' : '' }}>{{ $cn['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            <div id="crmLocLoai" class="fillter-box">
                <div class="card inner-modal-in-mobile db-loc">
                    <div class="card-header header_search">Loại khách hàng</div>
                    <div class="card-body px-2">
                        @foreach ($C::LOAI_KHACH as $ma => $nhan)
                            <label class="db-opt {{ $filters['type'] === $ma ? 'is-active' : '' }}">
                                <input class="form-check-input m-0 crm-loai" type="radio" name="loai_khach" value="{{ $ma }}" {{ $filters['type'] === $ma ? 'checked' : '' }}>
                                <span>{{ $nhan }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            <div id="crmLocKy" class="fillter-box">
                <div class="card inner-modal-in-mobile db-loc">
                    <div class="card-header header_search">{{ __('message.time') }}</div>
                    <div class="card-body px-2">
                        @foreach ($C::KY as $ma => $nhan)
                            <label class="db-opt {{ $filters['range'] === (string) $ma ? 'is-active' : '' }}">
                                <input class="form-check-input m-0 crm-ky" type="radio" name="ky" value="{{ $ma }}" {{ $filters['range'] === (string) $ma ? 'checked' : '' }}>
                                <span>{{ $nhan }}</span>
                            </label>
                        @endforeach
                        <span class="db-tu-chon">Tùy chỉnh</span>
                        {{-- Ô chữ + daterangepicker, không phải input[type=date]: lịch của trình
                             duyệt in theo ngôn ngữ máy nên trang tiếng Việt lại hiện 10/11/2026. --}}
                        <div class="d-flex flex-column gap-1">
                            <input type="text" class="form-control form-control-sm" id="crm-from" autocomplete="off"
                                value="{{ $dmy($filters['from']) }}" aria-label="{{ __('message.from_date') }}" placeholder="{{ __('message.from_date') }}">
                            <input type="text" class="form-control form-control-sm" id="crm-to" autocomplete="off"
                                value="{{ $dmy($filters['to']) }}" aria-label="{{ __('message.to_date') }}" placeholder="{{ __('message.to_date') }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ====================== NỘI DUNG ====================== --}}
        <div class="col-12 col-lg-10 wrapper-content-dashboard-middle">
            <div class="content_dashboard_mid">
                <div class="db-title">
                    <h1 class="tieu-de-trang mb-0">{{ $C::TITLE }}</h1>
                    <div class="db-title-meta">
                        <span>{{ $moTaKy }}</span>
                        <span class="db-sep"></span>
                        <span>{{ $tenCN }} · {{ $tenLoai }}</span>
                        <span class="db-sep"></span>
                        <span>Cập nhật lúc <b>{{ $capNhatLuc }}</b></span>
                    </div>
                </div>

                {{-- ---------- Sáu ô KPI: bấm vào mở màn chi tiết đúng kỳ ---------- --}}
                <div class="db-kpis">
                    @foreach ($oKpi as $o)
                        <a href="{{ $o['link'] }}" class="db-kpi" style="--db-mau: {{ $o['mau'] }}">
                            <div class="db-kpi-head">
                                <h3>{{ $o['nhan'] }}</h3>
                                <span class="db-info" data-bs-toggle="tooltip" data-bs-placement="left" title="{{ $o['tip'] }}"><i class="bi bi-info-circle-fill"></i></span>
                            </div>
                            <div class="db-kpi-body">
                                <div class="db-kpi-text">
                                    <span class="db-kpi-so">{{ $o['so'] }}</span>
                                    @foreach ($o['phu'] as $p)
                                        <span class="db-kpi-phu {{ $p['lop'] }}" title="{{ $kyTruoc }}">{{ $p['chu'] }}</span>
                                    @endforeach
                                    @isset($o['ghi'])
                                        <span class="db-kpi-phu">{!! $o['ghi'] !!}</span>
                                    @endisset
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                {{-- ---------- Hàng 1: top khách · tăng trưởng ---------- --}}
                <div class="row g-2 mb-2">
                    <div class="col-12 col-xl-4">
                        <div class="div-chart h-100">
                            <div class="chart-header d-flex align-items-center gap-2">
                                <h6 class="mb-0 fw-bold flex-grow-1">Top 10 khách hàng</h6>
                                <div class="db-seg" role="group" aria-label="Xếp theo">
                                    <a href="#" class="is-active" data-top="spend">Chi tiêu</a><a href="#" data-top="debt">Công nợ</a>
                                </div>
                            </div>
                            <div class="chart-body p-0">
                                <div class="table-responsive db-top-scroll">
                                    <table class="table table-sm border-0 mb-0 db-table">
                                        <thead class="sticky-top z-0">
                                            <tr>
                                                <th class="db-c-stt">{{ __('message.stt') }}</th>
                                                <th class="text-start">Khách hàng</th>
                                                <th class="text-end db-c-val" id="crm-top-cot">Chi tiêu</th>
                                            </tr>
                                        </thead>
                                        @foreach (['spend' => $bc['top_spend'] ?? [], 'debt' => $bc['top_debt'] ?? []] as $loaiTop => $ds)
                                            <tbody data-top-ds="{{ $loaiTop }}" class="{{ $loaiTop === 'debt' ? 'd-none' : '' }}">
                                                @forelse ($ds as $i => $kh)
                                                    <tr>
                                                        <td class="db-c-stt"><span class="db-rank db-rank--{{ $i + 1 }}">{{ $i + 1 }}</span></td>
                                                        <td class="text-start">
                                                            @if (! empty($kh['deleted']))
                                                                {{ $kh['name'] ?: 'Khách #'.$kh['user_id'] }} <span class="crm-xoa">Đã xoá</span>
                                                            @else
                                                                <a href="{{ ($lienKet['khach_ma'])($kh['code'] ?: $kh['name']) }}">{{ $kh['name'] ?: 'Khách #'.$kh['user_id'] }}</a>
                                                            @endif
                                                            <small>{{ collect([$kh['code'], (int) $kh['customer_type'] === 1 ? 'Doanh nghiệp' : $kh['rank_name'], $kh['orders'].' đơn'])->filter()->implode(' · ') }}</small>
                                                        </td>
                                                        <td class="text-end fw-bold db-c-val {{ $loaiTop === 'debt' ? 'text-danger' : '' }}">{{ $so($kh['value']) }}</td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="3" class="text-center text-muted py-4">
                                                        {{ $loaiTop === 'debt' ? 'Không khách nào còn nợ đơn trong kỳ.' : 'Kỳ này chưa khách nào có hồ sơ mua hàng.' }}
                                                    </td></tr>
                                                @endforelse
                                            </tbody>
                                        @endforeach
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-8">
                        <div class="div-chart h-100">
                            <div class="chart-header d-flex align-items-center gap-2 flex-wrap">
                                <h6 class="mb-0 fw-bold me-auto">Tăng trưởng khách hàng</h6>
                                <select class="form-select form-select-sm crm-chon" data-param="year" aria-label="Năm">
                                    @for ($y = $namNay; $y >= $namMin; $y--)
                                        <option value="{{ $y }}" {{ (int) ($bc['year'] ?? $namNay) === $y ? 'selected' : '' }}>{{ $y }}</option>
                                    @endfor
                                </select>
                            </div>
                            <div class="chart-body p-2">
                                <div class="db-chart-box"><canvas id="crmTangTruong"></canvas></div>
                                <div class="db-hint">Cột: khách mới đăng ký trong tháng · Đường: tổng khách cuối tháng</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---------- Hàng 2: hành vi mua ---------- --}}
                <div class="row g-2 mb-2">
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="div-chart h-100">
                            <div class="chart-header"><h6 class="mb-0 fw-bold">Tần suất quay lại</h6></div>
                            <div class="chart-body p-2">
                                <div class="crm-phu">Khoảng cách từ lần mua đầu đến lần mua thứ hai trong kỳ · {{ $so($k['buyers'] ?? 0) }} khách</div>
                                @if ($k['buyers'] ?? 0)
                                    @foreach ($thanh($bc['visit_gap'] ?? [], $mauQuayLai) as $t)
                                        <div class="crm-thanh"><span>{{ $t['nhan'] }}</span><b>{{ $t['so'] }}<small>{{ $t['pt'] }}</small></b>
                                            <div class="db-bar"><div style="width: {{ $t['rong'] }}%; background: {{ $t['mau'] }}"></div></div></div>
                                    @endforeach
                                @else
                                    <div class="db-empty">Kỳ này chưa khách nào có hồ sơ mua hàng</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <div class="div-chart h-100">
                            <div class="chart-header"><h6 class="mb-0 fw-bold">Số lần mua trong kỳ</h6></div>
                            <div class="chart-body p-2">
                                @if ($k['buyers'] ?? 0)
                                    <div class="db-donut db-donut--ngang">
                                        <div class="db-donut-ring">
                                            <canvas id="crmSoLan" width="130" height="130"></canvas>
                                            <div class="db-donut-center"><b>{{ $so($k['buyers']) }}</b><span>khách</span></div>
                                        </div>
                                        <ul class="db-legend">
                                            @foreach ($bc['purchase_count'] ?? [] as $i => $b)
                                                <li><span class="db-dot" style="background: {{ $mauSoLan[$i] ?? '#94a3b8' }}"></span><span>{{ $b['label'] }}</span>
                                                    <b>{{ $pt($b['count'] * 100 / $tongMua) }}</b><small>{{ $so($b['count']) }} khách</small></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @else
                                    <div class="db-empty">Kỳ này chưa khách nào có hồ sơ mua hàng</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-12 col-xl-4">
                        <div class="div-chart h-100">
                            <div class="chart-header"><h6 class="mb-0 fw-bold">Phân bố giá trị hoá đơn</h6></div>
                            <div class="chart-body p-2">
                                @if ($k['orders'] ?? 0)
                                    <div class="db-chart-box db-chart-box--thap"><canvas id="crmChiTieu"></canvas></div>
                                @else
                                    <div class="db-empty">Kỳ này chưa có đơn</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---------- Hàng 3: đăng ký mới · hạng thành viên ---------- --}}
                <div class="row g-2 mb-2">
                    <div class="col-12 col-xl-6">
                        <div class="div-chart h-100">
                            <div class="chart-header d-flex align-items-center gap-2">
                                <h6 class="mb-0 fw-bold me-auto">Khách đăng ký mới theo ngày</h6>
                                <select class="form-select form-select-sm crm-chon" data-param="reg_month" aria-label="Tháng">
                                    @foreach ($thangDK as $t)
                                        <option value="{{ $t->format('Y-m') }}" {{ ($bc['reg_month'] ?? '') === $t->format('Y-m') ? 'selected' : '' }}>Tháng {{ $t->format('m/Y') }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="chart-body p-2">
                                <div class="db-chart-box"><canvas id="crmDangKy"></canvas></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-xl-6">
                        <div class="div-chart h-100">
                            <div class="chart-header d-flex align-items-center gap-2">
                                <h6 class="mb-0 fw-bold me-auto">Hạng thành viên</h6>
                                @if (count($hang))
                                    <div class="db-seg" role="group" aria-label="Xem theo">
                                        <a href="#" class="is-active" data-hang="sl">Số khách</a><a href="#" data-hang="ct">Chi tiêu</a>
                                    </div>
                                @endif
                            </div>
                            <div class="chart-body p-2">
                                @if (count($hang))
                                    <div class="db-chart-box"><canvas id="crmHang"></canvas></div>
                                    <div class="db-hint" id="crm-hang-chu"></div>
                                @else
                                    <div class="db-empty">
                                        Cửa hàng chưa cài hạng thành viên
                                        <a href="{{ $lienKet['hang'] }}">Cài hạng ở Thẻ thành viên →</a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ---------- Hàng 4: chân dung khách · cần chăm sóc ---------- --}}
                <div class="row g-2">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="div-chart h-100">
                            <div class="chart-header"><h6 class="mb-0 fw-bold">Giới tính</h6></div>
                            <div class="chart-body p-2">
                                @if ($tongGioi)
                                    <div class="db-donut">
                                        <div class="db-donut-ring">
                                            <canvas id="crmGioiTinh" width="130" height="130"></canvas>
                                            <div class="db-donut-center"><b>{{ $so($tongGioi) }}</b><span>khách mua</span></div>
                                        </div>
                                        <ul class="db-legend">
                                            @foreach ($bc['gender'] as $b)
                                                <li><span class="db-dot" style="background: {{ $mauGioi[$b['key']] ?? '#94a3b8' }}"></span><span>{{ $b['label'] }}</span>
                                                    <b>{{ $pt($b['count'] * 100 / $tongGioi) }}</b><small>{{ $so($b['count']) }} khách</small></li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @else
                                    <div class="db-empty">Kỳ này chưa khách nào có hồ sơ mua hàng</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="div-chart h-100">
                            <div class="chart-header"><h6 class="mb-0 fw-bold">Độ tuổi</h6></div>
                            <div class="chart-body p-2">
                                <div class="crm-phu">Khách cá nhân mua trong kỳ có ngày sinh · {{ $so($tongTuoi) }} khách</div>
                                @if ($tongTuoi)
                                    @foreach ($thanh($bc['age'], '#8b5cf6') as $t)
                                        <div class="crm-thanh"><span>{{ $t['nhan'] }}</span><b>{{ $t['so'] }}<small>{{ $t['pt'] }}</small></b>
                                            <div class="db-bar"><div style="width: {{ $t['rong'] }}%; background: {{ $t['mau'] }}"></div></div></div>
                                    @endforeach
                                @else
                                    <div class="db-empty">Chưa khách nào có ngày sinh</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="div-chart h-100">
                            <div class="chart-header"><h6 class="mb-0 fw-bold">Khách theo tỉnh / thành</h6></div>
                            <div class="chart-body p-2">
                                <div class="crm-phu">Đơn giao hàng (web) · theo địa chỉ nhận</div>
                                @if ($tongTinh)
                                    @foreach ($thanh($bc['provinces'], '#2bb5b5') as $t)
                                        <div class="crm-thanh"><span>{{ $t['nhan'] }}</span><b>{{ $t['so'] }}<small>{{ $t['pt'] }}</small></b>
                                            <div class="db-bar"><div style="width: {{ $t['rong'] }}%; background: {{ $t['mau'] }}"></div></div></div>
                                    @endforeach
                                @else
                                    <div class="db-empty">Kỳ này chưa có đơn giao hàng</div>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="div-chart h-100 db-alert">
                            <div class="chart-header"><h6 class="mb-0 fw-bold">Cần chăm sóc</h6></div>
                            <div class="chart-body p-2">
                                <a href="{{ $lienKet['sinh_nhat'] }}" class="crm-cham"><span class="crm-cham-so">{{ $so($care['birthdays_7d'] ?? 0) }}</span><span>khách có <b>sinh nhật trong 7 ngày tới</b></span><i class="fa-solid fa-chevron-right"></i></a>
                                <a href="{{ $lienKet['lau_chua_quay_lai'] }}" class="crm-cham"><span class="crm-cham-so">{{ $so($care['lapsed_60d'] ?? 0) }}</span><span>khách đã mua nhưng <b>chưa quay lại hơn 60 ngày</b></span><i class="fa-solid fa-chevron-right"></i></a>
                                <a href="{{ $lienKet['cong_no'] }}" class="crm-cham"><span class="crm-cham-so">{{ $so($care['debtors'] ?? 0) }}</span><span>khách đang <b>còn nợ</b>{{ ($care['debt_amount'] ?? 0) > 0 ? ' · '.$so($care['debt_amount']).' đ' : '' }}</span><i class="fa-solid fa-chevron-right"></i></a>
                                <a href="{{ $lienKet['hang'] }}" class="crm-cham"><span class="crm-cham-so">{{ $so($care['near_rank'] ?? 0) }}</span><span>khách <b>sắp lên hạng</b> (thiếu dưới 10% điểm)</span><i class="fa-solid fa-chevron-right"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Khuôn db-* chép từ Thống kê → Tổng quan (v2::dashboard.index). */
        .db-loc .header_search { font-weight: 600; }
        .fillter-box-container .fillter-box + .fillter-box { margin-top: 10px; }
        .db-opt { display: flex; align-items: center; gap: 8px; padding: 6px 8px; margin: 0 0 4px; border-radius: 6px; cursor: pointer; }
        /* Nhãn riêng, không dùng .title_search: vỏ v2 giấu .title_search trong khung
           lọc điện thoại, mất nhãn thì hai ô ngày dính sát mốc "Tháng trước". */
        .db-tu-chon { display: block; margin: 10px 2px 6px; font-size: 12px; font-weight: 600; color: #475569; }
        .db-opt:hover { background: #f1f5f9; }
        .db-opt.is-active { background: #e8eefb; color: #1d4ed8; font-weight: 700; }
        #crm-from, #crm-to { font-size: 12px; padding-left: 6px; padding-right: 6px; }

        .db-title { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 16px; margin-bottom: 10px; }
        .db-title-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; font-size: 13px; color: #475569; }
        .db-sep { width: 1px; height: 16px; background: #cbd5e1; }

        .db-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 10px; }
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

        .db-seg { display: inline-flex; border: 1px solid #cbd5e1; border-radius: 6px; overflow: hidden; }
        .db-seg a { padding: 3px 10px; font-size: 12px; color: #0f172a; text-decoration: none; white-space: nowrap; }
        .db-seg a + a { border-left: 1px solid #cbd5e1; }
        .db-seg a.is-active { background: #1d4ed8; color: #fff; font-weight: 600; }
        .db-chart-box { position: relative; height: 250px; }
        .db-chart-box--thap { height: 220px; }
        .db-hint { font-size: 12px; color: #64748b; margin-top: 4px; }

        .db-top-scroll { max-height: 300px; min-height: 270px; overflow-y: auto; }
        .db-table { table-layout: auto; width: 100%; }
        .db-table th, .db-table td { white-space: nowrap; vertical-align: middle; padding: 7px 6px; font-size: 13px; }
        .db-table th { font-size: 11px; padding-left: 4px; padding-right: 4px; }
        .db-table .db-c-stt, .db-table .db-c-val { width: 1%; }
        .db-table tbody tr:nth-child(even) { background: #fafbfd; }
        .db-table a { color: #0f172a; text-decoration: none; }
        .db-table a:hover { color: #1d4ed8; text-decoration: underline; }
        .db-table small { display: block; color: #64748b; font-size: 11.5px; }
        .db-rank { display: inline-flex; width: 22px; height: 22px; border-radius: 999px; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; background: #e2e8f0; color: #334155; }
        .db-rank--1 { background: #f59e0b; color: #fff; }
        .db-rank--2 { background: #94a3b8; color: #fff; }
        .db-rank--3 { background: #c2773a; color: #fff; }

        .db-donut { display: flex; flex-direction: column; align-items: center; gap: 10px; }
        .db-donut-ring { position: relative; width: 130px; height: 130px; flex: 0 0 auto; }
        .db-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; }
        .db-donut-center b { font-size: 18px; }
        .db-donut-center span { font-size: 11px; color: #475569; }
        .db-legend { list-style: none; margin: 0; padding: 0; width: 100%; display: flex; flex-direction: column; gap: 6px; }
        .db-legend li { display: grid; grid-template-columns: 10px 1fr auto; column-gap: 6px; align-items: center; font-size: 13px; }
        .db-legend small { grid-column: 2 / 4; color: #475569; }
        .db-dot { width: 10px; height: 10px; border-radius: 999px; display: inline-block; flex: 0 0 auto; }
        @media (min-width: 768px) { .db-donut--ngang { flex-direction: row; align-items: center; gap: 18px; } }
        .db-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; min-height: 180px; color: #64748b; font-size: 13px; text-align: center; }

        .db-bar { height: 8px; background: #eef2f7; border-radius: 4px; overflow: hidden; }
        .db-alert { background: #fff7ed; border-color: #fed7aa; }
        .db-alert .chart-header { color: #7c2d12; }

        /* Riêng màn CRM. */
        .crm-phu { font-size: 12px; color: #64748b; margin: 0 2px 8px; }
        .crm-xoa { font-size: 10.5px; font-weight: 600; color: #a8071a; background: #fff1f0; border: 1px solid #ffa39e; border-radius: 4px; padding: 0 4px; margin-left: 4px; }
        .crm-chon { width: auto; min-width: 96px; font-size: 12px; }
        .crm-thanh { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 3px 10px; align-items: center; font-size: 13px; padding: 4px 2px; }
        .crm-thanh > span { white-space: nowrap; overflow: hidden; }
        .crm-thanh > b { white-space: nowrap; }
        .crm-thanh > b small { font-weight: 400; color: #64748b; margin-left: 4px; }
        .crm-thanh .db-bar { grid-column: 1 / 3; }
        .crm-thanh .db-bar div { height: 8px; border-radius: 4px; }
        .crm-cham { display: grid; grid-template-columns: 44px 1fr 12px; gap: 10px; align-items: center; padding: 9px 6px; border-radius: 8px; color: #0f172a; text-decoration: none; font-size: 13px; }
        .crm-cham + .crm-cham { border-top: 1px dashed #fed7aa; }
        .crm-cham:hover { background: #ffedd5; color: #0f172a; }
        .crm-cham-so { display: inline-flex; align-items: center; justify-content: center; height: 32px; border-radius: 8px; background: #fff; border: 1px solid #fdba74; color: #c2410c; font-weight: 800; font-size: 15px; }
        .crm-cham i { color: #c2410c; font-size: 11px; }

        @media (max-width: 767px) { .db-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575px) { .db-kpi-phu { white-space: normal; } }
    </style>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const D = @json($duLieuJs);
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(v));
            const ve = (id, cau) => { const o = document.getElementById(id); return o ? new Chart(o.getContext('2d'), cau) : null; };
            const chuGon = { responsive: true, maintainAspectRatio: false };

            // ================= Bộ lọc =================
            const $f = $('#crm-loc');
            const gui = (doi) => {
                Object.entries(doi).forEach(([k, v]) => $f.find('[name="' + k + '"]').length
                    ? $f.find('[name="' + k + '"]').val(v)
                    : $f.append($('<input type="hidden">').attr('name', k).val(v)));
                $f.find('input').filter((i, el) => !el.value).prop('disabled', true);
                $f.trigger('submit');
            };
            $('#crm-branch').on('change', function () { gui({ branch: this.value }); });
            $('.crm-loai').on('change', function () { gui({ type: this.value }); });
            $('.crm-ky').on('change', function () { gui({ range: this.value, from: '', to: '' }); });
            $('.crm-chon').on('change', function () { gui({ [$(this).data('param')]: this.value }); });
            $('#crm-from, #crm-to').each(function () {
                $(this).daterangepicker({
                    singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
                    maxDate: moment(), locale: V2.lichVN(),
                }, (start) => {
                    $(this).val(start.format('DD-MM-YYYY'));
                    gui({ range: '', from: $('#crm-from').val(), to: $('#crm-to').val() });
                });
            });

            // ================= Top 10: Chi tiêu | Công nợ =================
            $('[data-top]').on('click', function (e) {
                e.preventDefault();
                const k = $(this).data('top');
                $('[data-top]').removeClass('is-active');
                $(this).addClass('is-active');
                $('[data-top-ds]').addClass('d-none').filter('[data-top-ds="' + k + '"]').removeClass('d-none');
                $('#crm-top-cot').text(k === 'debt' ? 'Còn nợ' : 'Chi tiêu');
            });

            // ================= Biểu đồ =================
            // Tăng trưởng: cột khách mới (trục trái) + đường tổng khách (trục phải) —
            // chồng hai thứ lên một cột thì phần khách mới mảnh như sợi chỉ.
            ve('crmTangTruong', {
                data: {
                    labels: D.growth.map((m) => 'T' + parseInt(m.month.slice(5), 10)),
                    datasets: [
                        { type: 'line', label: 'Tổng khách', data: D.growth.map((m) => m.total), borderColor: '#f97316', backgroundColor: '#f97316', tension: .3, pointRadius: 3, yAxisID: 'y1', order: 0 },
                        { type: 'bar', label: 'Khách mới', data: D.growth.map((m) => m.new), backgroundColor: '#3b82f6', borderRadius: 4, yAxisID: 'y', order: 1, maxBarThickness: 48 },
                    ],
                },
                options: {
                    ...chuGon, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                        tooltip: { callbacks: { label: (x) => x.dataset.label + ': ' + tien(x.parsed.y) + ' khách' } } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, title: { display: true, text: 'Khách mới' }, ticks: { precision: 0 } },
                        y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Tổng khách' }, ticks: { precision: 0, callback: (v) => tien(v) } },
                    },
                },
            });

            const vong = (id, ds) => ve(id, {
                type: 'doughnut',
                data: { labels: ds.map((r) => r.nhan), datasets: [{ data: ds.map((r) => r.gia_tri), backgroundColor: ds.map((r) => r.mau), borderWidth: 2 }] },
                options: { responsive: false, cutout: '68%', plugins: { legend: { display: false } } },
            });
            vong('crmSoLan', D.purchase);
            vong('crmGioiTinh', D.gender);

            ve('crmChiTieu', {
                type: 'bar',
                data: { labels: D.orderValue.map((b) => b.label), datasets: [{ label: 'Số đơn', data: D.orderValue.map((b) => b.count), backgroundColor: ['#bfd3f6', '#7aa7ef', '#3b82f6', '#1d4ed8'], borderRadius: 4, maxBarThickness: 64 }] },
                options: { ...chuGon, plugins: { legend: { display: false },
                    tooltip: { callbacks: { label: (x) => tien(x.parsed.y) + ' đơn · ' + (D.orders ? (x.parsed.y * 100 / D.orders).toFixed(1).replace('.', ',') : 0) + '%' } } },
                    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } },
            });

            ve('crmDangKy', {
                type: 'bar',
                data: { labels: D.regDaily.map((b) => b.label), datasets: [{ label: 'Khách đăng ký mới', data: D.regDaily.map((b) => b.count), backgroundColor: '#16a34a', borderRadius: 4, maxBarThickness: 28 }] },
                options: { ...chuGon, plugins: { legend: { display: false } },
                    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 } } } },
            });

            // Hạng thành viên: "Chưa có hạng" ghi bằng chữ — chung trục thì nó cao gấp
            // mấy lần các hạng khác và đè dẹt cả biểu đồ.
            const hang = ve('crmHang', { type: 'bar', data: { labels: D.ranks.map((r) => r.name), datasets: [] },
                options: { ...chuGon, interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } } },
                    scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0, callback: (v) => tien(v) } } } } });
            const MAU_HANG = ['#c2773a', '#94a3b8', '#f59e0b', '#2bb5b5', '#8b5cf6', '#e11d48'];
            function veHang(k) {
                if (!hang) return;
                const ch = D.chuaHang;
                hang.data.datasets = k === 'sl'
                    ? [{ label: 'Hồ sơ có từ trước kỳ', data: D.ranks.map((r) => r.existing), backgroundColor: '#bfd3f6', borderRadius: 3, stack: 'h', maxBarThickness: 56 },
                       { label: 'Khách mới trong kỳ', data: D.ranks.map((r) => r.new), backgroundColor: '#f97316', borderRadius: 3, stack: 'h', maxBarThickness: 56 }]
                    : [{ label: 'Chi tiêu trong kỳ (đ)', data: D.ranks.map((r) => r.spend), backgroundColor: D.ranks.map((r, i) => MAU_HANG[i % MAU_HANG.length]), borderRadius: 4, stack: 'h', maxBarThickness: 56 }];
                hang.options.plugins.legend.display = k === 'sl';
                hang.update();
                $('#crm-hang-chu').text(k === 'sl'
                    ? 'Chưa có hạng: ' + tien(ch.existing + ch.new) + ' khách (' + tien(ch.new) + ' khách mới trong kỳ) · xếp theo hạng hiện tại của khách'
                    : 'Tổng tiền đơn trong kỳ theo hạng hiện tại · Chưa có hạng: ' + tien(ch.spend) + ' đ');
            }
            $('[data-hang]').on('click', function (e) {
                e.preventDefault();
                $('[data-hang]').removeClass('is-active');
                $(this).addClass('is-active');
                veHang($(this).data('hang'));
            });
            veHang('sl');
        })();
    </script>
@endpush
