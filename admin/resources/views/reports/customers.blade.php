@extends('layouts.app')

@section('title', 'Báo cáo khách hàng')

@section('content')
    {{--
        Báo cáo khách hàng — ai đang mua: người mới hay người cũ, ở đâu, tiêu bao nhiêu.

        LƯU Ý về mẫu số: "khách mua" chỉ đếm người CÓ TÀI KHOẢN. Đơn khách vãng lai
        không gắn tài khoản nên không có cách nào biết hai đơn có phải cùng một
        người hay không — gộp bừa vào sẽ thổi phồng số khách và làm hỏng tỷ lệ quay
        lại. Phần vãng lai được đếm riêng, không bị bỏ qua.
    --}}
    @php
        use App\Support\Chart;
        use App\Http\Controllers\ReportController;

        $CHANNEL_COLORS = ReportController::CHANNEL_COLORS;

        $totals = $report['totals'] ?? [];
        $prev = $report['prev'] ?? [];
        $top = $report['top'] ?? [];
        $buckets = $report['buckets'] ?? [];
        $group = $filters['group_by'];
        $get = fn ($arr, $key) => (float) ($arr[$key] ?? 0);

        $newSeries = array_map(fn ($b) => (float) ($b['new_buyers'] ?? 0), $buckets);
        $backSeries = array_map(fn ($b) => (float) ($b['returning'] ?? 0), $buckets);
        $regSeries = array_map(fn ($b) => (float) ($b['registered'] ?? 0), $buckets);

        // Cột chồng: khách mới nằm dưới, khách quay lại nằm trên — tổng cột là số
        // khách mua trong mốc đó.
        $stackMax = 0;
        foreach ($buckets as $i => $b) {
            $stackMax = max($stackMax, ($newSeries[$i] ?? 0) + ($backSeries[$i] ?? 0));
        }

        // Hội viên / vãng lai chia theo DOANH THU (không theo số đơn): câu hỏi ở
        // trang khách hàng là tiền đến từ nhóm nào.
        $channelRows = [];
        if ($get($totals, 'revenue') > 0) {
            $channelRows = [
                [
                    'label' => 'Khách có tài khoản',
                    'value' => Chart::money($get($totals, 'member_revenue')),
                    'share' => Chart::share($get($totals, 'member_revenue'), $get($totals, 'revenue')),
                    'color' => $CHANNEL_COLORS['member'],
                ],
                [
                    'label' => 'Khách vãng lai',
                    'value' => Chart::money($get($totals, 'guest_revenue')),
                    'share' => Chart::share($get($totals, 'guest_revenue'), $get($totals, 'revenue')),
                    'color' => $CHANNEL_COLORS['guest'],
                ],
            ];
        }

        $provinceMax = max(1, (int) collect($report['by_province'] ?? [])->max('orders'));
        $provinceRows = collect($report['by_province'] ?? [])->map(fn ($p) => [
            'label' => $p['key'] !== '' ? $p['key'] : 'Chưa khai tỉnh/thành',
            'value' => Chart::int($p['orders'] ?? 0),
            'extra' => Chart::money($p['revenue'] ?? 0),
            'ratio' => Chart::share($p['orders'] ?? 0, $provinceMax),
        ])->all();

        // Khách mới / quay lại dạng cột ngang — cùng một con số với biểu đồ trên,
        // nhưng đọc được ngay tỷ lệ hai nhóm mà không phải cộng nhẩm từng mốc.
        $mixMax = max(1, $get($totals, 'buyers'));
        $mixRows = [
            [
                'label' => 'Khách mới', 'tone' => 'done',
                'value' => Chart::int($get($totals, 'new_buyers')),
                'extra' => Chart::pct(Chart::share($get($totals, 'new_buyers'), $get($totals, 'buyers'))),
                'ratio' => Chart::share($get($totals, 'new_buyers'), $mixMax),
            ],
            [
                'label' => 'Khách quay lại', 'tone' => 'info',
                'value' => Chart::int($get($totals, 'returning')),
                'extra' => Chart::pct($get($totals, 'repeat_rate')),
                'ratio' => Chart::share($get($totals, 'returning'), $mixMax),
            ],
        ];

        $extraFilters = view('reports.partials.limit-filter', [
            'filters' => $filters, 'limits' => ReportController::LIMITS,
        ])->render();

        $COL_W = 720; $COL_H = 190; $COL_PAD_B = 26; $COL_PAD_T = 8;
        $colPlotH = $COL_H - $COL_PAD_B - $COL_PAD_T;
        $colSlot = count($buckets) > 0 ? $COL_W / count($buckets) : $COL_W;
        $colBarW = max(3, $colSlot * 0.6);
        $colLabelAt = array_flip(Chart::labelIndices(count($buckets), 10));
    @endphp

    <div class="rp">
        @include('reports.partials.head', [
            'subtitle' => 'Người mua '.\App\Support\Period::describe($filters['from_date'], $filters['to_date'],
                \App\Http\Controllers\ReportController::QUICK_CODES).'. "Khách mua" chỉ đếm người có tài khoản.',
            'skip' => ['limit'],
            'extra' => $extraFilters,
        ])

        {{-- ===== Hàng chỉ số ===== --}}
        <div class="rp-kpis">
            @include('reports.partials.kpi', [
                'label' => 'Khách đã mua', 'tone' => 'blue',
                'icon' => '<circle cx="9" cy="7.6" r="4"/><path d="M2 21v-.8a7 7 0 0 1 14 0v.8"/><path d="M15.7 3.6a4 4 0 0 1 0 8"/><path d="M15.7 11.6a6.3 6.3 0 0 1 6.3 6.3v.9"/>',
                'value' => Chart::int($get($totals, 'buyers')),
                'delta' => Chart::delta($get($totals, 'buyers'), $get($prev, 'buyers')),
                'deltaNew' => 'Kỳ trước chưa có khách nào mua',
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::int($get($prev, 'buyers'))],
                    ['label' => 'Số đơn mỗi khách', 'value' => Chart::dec($get($totals, 'orders_per_buyer'), 2)],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Khách mua lần đầu', 'tone' => 'green',
                'icon' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
                'value' => Chart::int($get($totals, 'new_buyers')),
                'delta' => Chart::delta($get($totals, 'new_buyers'), $get($prev, 'new_buyers')),
                'deltaNew' => 'Kỳ trước chưa có khách mới',
                'spark' => Chart::spark($newSeries),
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::int($get($prev, 'new_buyers'))],
                    ['label' => 'Tỷ lệ trên khách đã mua', 'value' => Chart::pct(Chart::share($get($totals, 'new_buyers'), $get($totals, 'buyers')))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Khách quay lại', 'tone' => 'violet',
                'icon' => '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>',
                'value' => Chart::int($get($totals, 'returning')),
                'delta' => Chart::delta($get($totals, 'returning'), $get($prev, 'returning')),
                'deltaNew' => 'Kỳ trước chưa có khách quay lại',
                'spark' => Chart::spark($backSeries),
                'rows' => [
                    ['label' => 'Tỷ lệ quay lại', 'value' => Chart::pct($get($totals, 'repeat_rate'))],
                    ['label' => 'Kỳ trước', 'value' => Chart::pct($get($prev, 'repeat_rate'))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Tài khoản đăng ký mới', 'tone' => 'teal',
                'icon' => '<path d="M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="m17 11 2 2 4-4"/>',
                'value' => Chart::int($get($totals, 'registered')),
                'delta' => Chart::delta($get($totals, 'registered'), $get($prev, 'registered')),
                'deltaNew' => 'Kỳ trước chưa có ai đăng ký',
                'spark' => Chart::spark($regSeries),
                'rows' => [
                    ['label' => 'Trong đó đã mua hàng', 'value' => Chart::int($get($totals, 'new_buyers'))],
                    ['label' => 'Kỳ trước', 'value' => Chart::int($get($prev, 'registered'))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Chi tiêu mỗi khách', 'tone' => 'amber',
                'icon' => '<path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
                'value' => Chart::money($get($totals, 'revenue_per_buyer')),
                'delta' => Chart::delta($get($totals, 'revenue_per_buyer'), $get($prev, 'revenue_per_buyer')),
                'note' => 'Chỉ tính khách có tài khoản',
                'rows' => [
                    ['label' => 'Doanh thu từ khách có TK', 'value' => Chart::money($get($totals, 'member_revenue'))],
                    ['label' => 'Kỳ trước', 'value' => Chart::money($get($prev, 'revenue_per_buyer'))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Đơn khách vãng lai', 'tone' => 'grey',
                'icon' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/>',
                'value' => Chart::int($get($totals, 'guest_orders')),
                'delta' => Chart::delta($get($totals, 'guest_orders'), $get($prev, 'guest_orders')),
                'deltaNew' => 'Kỳ trước không có đơn vãng lai',
                'rows' => [
                    ['label' => 'Doanh thu', 'value' => Chart::money($get($totals, 'guest_revenue'))],
                    ['label' => 'Tỷ lệ trên tổng đơn', 'value' => Chart::pct(Chart::share($get($totals, 'guest_orders'), $get($totals, 'orders')))],
                ],
            ])
        </div>

        {{-- ===== Khách mới / quay lại theo thời gian =====
             Cột chồng chứ không phải hai đường: câu hỏi ở đây là "trong số khách
             mua mốc này, bao nhiêu là người mới" — tỷ lệ trong MỘT cột đọc ngay
             được, còn hai đường rời thì phải cộng nhẩm. --}}
        <section class="rp-card">
            <div class="rp-card-head">
                <div>
                    <h2 class="rp-card-title">Khách mới và khách quay lại theo thời gian</h2>
                    <p class="rp-card-sub">
                        <span class="rp-legend-swatch" style="display:inline-block;vertical-align:middle;background:#1890ff"></span>
                        Khách mới &nbsp;
                        <span class="rp-legend-swatch" style="display:inline-block;vertical-align:middle;background:#b7eb8f"></span>
                        Khách quay lại — chiều cao cả cột là số khách đã mua trong mốc đó.
                    </p>
                </div>
                <div class="rp-card-tools">
                    <button type="button" class="rp-linkbtn" data-table-toggle="cusMix" aria-expanded="false">Xem dạng bảng</button>
                </div>
            </div>

            @if($buckets && $stackMax > 0)
                <svg class="rp-cols" viewBox="0 0 {{ $COL_W }} {{ $COL_H }}" preserveAspectRatio="none" role="img"
                     aria-label="Khách mới và khách quay lại theo từng mốc">
                    @foreach($buckets as $i => $b)
                        @php
                            $new = $newSeries[$i] ?? 0;
                            $back = $backSeries[$i] ?? 0;
                            $hNew = $new > 0 ? max(2, $colPlotH * $new / $stackMax) : 0;
                            $hBack = $back > 0 ? max(2, $colPlotH * $back / $stackMax) : 0;
                            $x = $colSlot * $i + ($colSlot - $colBarW) / 2;
                            $baseY = $COL_PAD_T + $colPlotH;
                            $label = ReportController::bucketLabel($b['label'] ?? '', $group);
                        @endphp
                        @if($hNew > 0)
                            <rect class="rp-col--new" x="{{ round($x, 2) }}" y="{{ round($baseY - $hNew, 2) }}"
                                  width="{{ round($colBarW, 2) }}" height="{{ round($hNew, 2) }}" rx="2">
                                <title>{{ $label }}: {{ Chart::int($new) }} khách mới</title>
                            </rect>
                        @endif
                        @if($hBack > 0)
                            <rect class="rp-col--back" x="{{ round($x, 2) }}" y="{{ round($baseY - $hNew - $hBack, 2) }}"
                                  width="{{ round($colBarW, 2) }}" height="{{ round($hBack, 2) }}" rx="2">
                                <title>{{ $label }}: {{ Chart::int($back) }} khách quay lại</title>
                            </rect>
                        @endif
                        @if(isset($colLabelAt[$i]))
                            <text class="rp-tick" x="{{ round($colSlot * $i + $colSlot / 2, 2) }}" y="{{ $COL_H - 8 }}" text-anchor="middle">
                                {{ ReportController::bucketLabel($b['label'] ?? '', $group, true) }}
                            </text>
                        @endif
                    @endforeach
                </svg>

                <div class="rp-table-wrap rp-table-wrap--tall" data-table="cusMix" hidden>
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th>Mốc</th>
                                <th class="num">Khách mới</th>
                                <th class="num">Khách quay lại</th>
                                <th class="num">Tổng khách mua</th>
                                <th class="num">Đăng ký mới</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($buckets as $i => $b)
                                <tr>
                                    <td>{{ ReportController::bucketLabel($b['label'] ?? '', $group) }}</td>
                                    <td class="num">{{ Chart::int($newSeries[$i] ?? 0) }}</td>
                                    <td class="num">{{ Chart::int($backSeries[$i] ?? 0) }}</td>
                                    <td class="num"><b>{{ Chart::int(($newSeries[$i] ?? 0) + ($backSeries[$i] ?? 0)) }}</b></td>
                                    <td class="num rp-muted">{{ Chart::int($regSeries[$i] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="rp-empty">Kỳ này chưa có khách có tài khoản nào mua hàng.</p>
            @endif
        </section>

        {{-- ===== Cơ cấu ===== --}}
        <div class="rp-grid rp-grid--3">
            @include('reports.partials.bars', [
                'title' => 'Khách mới và khách cũ',
                'sub' => 'Cộng cả kỳ. Tỷ lệ tính trên số khách có tài khoản đã mua.',
                'rows' => $mixRows,
                'empty' => 'Kỳ này chưa có khách nào mua.',
            ])

            @include('reports.partials.donut', [
                'title' => 'Tiền đến từ nhóm nào',
                'sub' => 'Chia theo doanh thu, không theo số đơn.',
                'rows' => $channelRows,
                'centerValue' => Chart::shortMoney($get($totals, 'revenue')),
                'centerCap' => 'doanh thu kỳ',
                'empty' => 'Kỳ này chưa có doanh thu.',
            ])

            @include('reports.partials.bars', [
                'title' => 'Khách ở đâu',
                'sub' => 'Theo địa chỉ nhận hàng, tính CẢ đơn vãng lai. Tối đa 12 tỉnh/thành.',
                'rows' => $provinceRows,
                'empty' => 'Kỳ này chưa có đơn nào.',
            ])
        </div>

        {{-- ===== Bảng xếp hạng chi tiêu ===== --}}
        <section class="rp-card">
            <div class="rp-card-head">
                <div>
                    <h2 class="rp-card-title">Khách chi tiêu nhiều nhất</h2>
                    <p class="rp-card-sub">
                        Tối đa {{ $filters['limit'] }} khách, chỉ tính chi tiêu TRONG KỲ đang xem — không phải tổng chi tiêu từ trước tới nay.
                    </p>
                </div>
            </div>

            @if($top)
                <div class="rp-table-wrap">
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th class="num">#</th>
                                <th>Khách hàng</th>
                                <th>Liên hệ</th>
                                <th class="num">Số đơn</th>
                                <th class="num">Số món</th>
                                <th class="num">Chi tiêu</th>
                                <th class="num">Trung bình / đơn</th>
                                <th>Mua gần nhất</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($top as $i => $row)
                                <tr>
                                    <td class="num"><span class="rp-rank {{ $i === 0 ? 'is-first' : '' }}">{{ $i + 1 }}</span></td>
                                    <td>
                                        <div class="rp-prod">
                                            <span class="rp-avatar">{{ mb_substr(trim($row['name'] ?? '?'), 0, 1) }}</span>
                                            <span class="rp-prod-main">
                                                <span class="rp-prod-name">{{ $row['name'] ?: 'Không rõ tên' }}</span>
                                                <span class="rp-prod-sub">
                                                    @if(!empty($row['is_new']))
                                                        <span class="rp-badge tone-new">Khách mới</span>
                                                    @else
                                                        <span class="rp-badge tone-back">Khách quay lại</span>
                                                    @endif
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="rp-muted">{{ $row['email'] ?: '—' }}{{ !empty($row['phone']) ? ' · '.$row['phone'] : '' }}</td>
                                    <td class="num">{{ Chart::int($row['orders'] ?? 0) }}</td>
                                    <td class="num">{{ Chart::int($row['units'] ?? 0) }}</td>
                                    <td class="num"><b>{{ Chart::money($row['revenue'] ?? 0) }}</b></td>
                                    <td class="num rp-muted">{{ Chart::money($row['aov'] ?? 0) }}</td>
                                    <td class="rp-muted">
                                        {{ !empty($row['last_order_at']) ? \Illuminate\Support\Carbon::parse($row['last_order_at'])->format('d/m/Y H:i') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="rp-empty">Kỳ này chưa có khách có tài khoản nào mua hàng.</p>
            @endif
        </section>
    </div>

    @include('reports.partials.style')
    @include('reports.partials.script')
@endsection
