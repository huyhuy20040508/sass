@extends('layouts.app')

@section('title', 'Báo cáo đơn hàng')

@section('content')
    {{--
        Báo cáo đơn hàng — đơn ra vào thế nào, hỏng ở đâu, đến từ đâu.

        Khác báo cáo doanh thu ở MẪU SỐ: ở đây "tổng đơn" đếm cả đơn huỷ và đơn
        hoàn, vì tỷ lệ huỷ chỉ có nghĩa khi mẫu số là tổng đơn đã đặt. Mọi con số
        TIỀN thì vẫn chỉ tính trên đơn còn hiệu lực, giống hệt báo cáo doanh thu.
    --}}
    @php
        use App\Support\Chart;
        use App\Http\Controllers\OrderController;
        use App\Http\Controllers\ReportController;

        $STATUSES = OrderController::STATUSES;
        $TONES = OrderController::STATUS_TONES;
        $CHANNELS = ReportController::CHANNELS;
        $CHANNEL_COLORS = ReportController::CHANNEL_COLORS;
        $WEEKDAYS = ReportController::WEEKDAYS;

        $buckets = $report['buckets'] ?? [];
        $totals = $report['totals'] ?? [];
        $prev = $report['prev'] ?? [];
        $group = $filters['group_by'];
        $get = fn ($arr, $key) => (float) ($arr[$key] ?? 0);

        $ordSeries = array_map(fn ($b) => (float) ($b['orders'] ?? 0), $buckets);
        $revSeries = array_map(fn ($b) => (float) ($b['revenue'] ?? 0), $buckets);

        // ----- Trạng thái: nhóm ĐANG CHẠY và nhóm ĐÃ CHẾT tách nhau bằng một đường
        //       kẻ, vì hai nhóm này đọc theo hai nghĩa hoàn toàn khác nhau.
        $byStatus = collect($report['by_status'] ?? [])->keyBy('key');
        $statusMax = max(1, (int) collect($report['by_status'] ?? [])->max('orders'));
        $flow = ['pending', 'confirmed', 'processing', 'shipping', 'delivered', 'completed'];
        $statusRows = [];
        foreach (['pending', 'confirmed', 'processing', 'shipping', 'delivered', 'completed', 'cancelled', 'returned'] as $key) {
            $orders = (int) ($byStatus[$key]['orders'] ?? 0);
            $statusRows[] = [
                'label' => $STATUSES[$key] ?? $key,
                'tone' => $TONES[$key] ?? null,
                'value' => Chart::int($orders),
                'extra' => Chart::pct(Chart::share($orders, $get($totals, 'total'))),
                'ratio' => Chart::share($orders, $statusMax),
                'dead' => ! in_array($key, $flow, true),
                'split' => $key === 'cancelled',
            ];
        }

        // ----- Khung giờ + thứ trong tuần: giờ nào / thứ nào khách đặt nhiều nhất.
        $hourRows = collect($report['by_hour'] ?? [])->map(fn ($h) => [
            'label' => ReportController::hourLabel($h['key']),
            'value' => (int) ($h['orders'] ?? 0),
            'title' => ReportController::hourLabel($h['key']).'–'.(((int) $h['key'] + 1) % 24).'h: '.Chart::int($h['orders'] ?? 0).' đơn',
        ])->all();
        $peakHour = collect($report['by_hour'] ?? [])->sortByDesc('orders')->first();

        $weekdayMax = max(1, (int) collect($report['by_weekday'] ?? [])->max('orders'));
        $weekdayRows = collect($report['by_weekday'] ?? [])->map(fn ($d) => [
            'label' => $WEEKDAYS[(int) $d['key']] ?? $d['key'],
            'value' => Chart::int($d['orders'] ?? 0),
            'extra' => Chart::money($d['revenue'] ?? 0),
            'ratio' => Chart::share($d['orders'] ?? 0, $weekdayMax),
        ])->all();

        // ----- Khu vực nhận hàng.
        $provinceMax = max(1, (int) collect($report['by_province'] ?? [])->max('orders'));
        $provinceRows = collect($report['by_province'] ?? [])->map(fn ($p) => [
            'label' => $p['key'] !== '' ? $p['key'] : 'Chưa khai tỉnh/thành',
            'value' => Chart::int($p['orders'] ?? 0),
            'extra' => Chart::money($p['revenue'] ?? 0),
            'ratio' => Chart::share($p['orders'] ?? 0, $provinceMax),
        ])->all();

        // ----- Kênh bán: khách có tài khoản và khách vãng lai.
        $channelTotal = collect($report['by_channel'] ?? [])->sum('orders');
        $channelRows = collect($report['by_channel'] ?? [])->map(fn ($c) => [
            'label' => $CHANNELS[$c['key']] ?? $c['key'],
            'value' => Chart::int($c['orders'] ?? 0).' đơn',
            'share' => Chart::share($c['orders'] ?? 0, $channelTotal),
            'color' => $CHANNEL_COLORS[$c['key']] ?? '#8c8c8c',
        ])->all();

        // ----- Hình thức vận chuyển. Đơn chưa khai thì gộp thành một dòng có tên,
        //       không để nhãn trống — dòng trống trong bảng đọc như lỗi hiển thị.
        $shipMax = max(1, (int) collect($report['by_shipping'] ?? [])->max('orders'));
        $shipNames = ['standard' => 'Giao tiêu chuẩn', 'express' => 'Giao nhanh', '' => 'Chưa chọn hình thức'];
        $shipRows = collect($report['by_shipping'] ?? [])->map(fn ($s) => [
            'label' => $shipNames[$s['key']] ?? $s['key'],
            'value' => Chart::int($s['orders'] ?? 0),
            'extra' => Chart::money($s['revenue'] ?? 0),
            'ratio' => Chart::share($s['orders'] ?? 0, $shipMax),
            'dead' => $s['key'] === '',
        ])->all();
    @endphp

    <div class="rp">
        @include('reports.partials.head', [
            'subtitle' => 'Đơn phát sinh '.\App\Support\Period::describe($filters['from_date'], $filters['to_date'],
                \App\Http\Controllers\ReportController::QUICK_CODES).'. Số đơn tính CẢ đơn huỷ/hoàn; số tiền thì không.',
        ])

        {{-- ===== Hàng chỉ số ===== --}}
        <div class="rp-kpis">
            @include('reports.partials.kpi', [
                'label' => 'Tổng đơn đã đặt', 'tone' => 'blue',
                'icon' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
                'value' => Chart::int($get($totals, 'total')),
                'delta' => Chart::delta($get($totals, 'total'), $get($prev, 'total')),
                'deltaNew' => 'Kỳ trước chưa có đơn',
                'spark' => Chart::spark($ordSeries),
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::int($get($prev, 'total'))],
                    ['label' => 'Trung bình mỗi ngày', 'value' => Chart::dec($get($totals, 'total') / max(1, (int) $filters['days']), 1)],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Đơn còn hiệu lực', 'tone' => 'green',
                'icon' => '<path d="M20 6 9 17l-5-5"/>',
                'value' => Chart::int($get($totals, 'net')),
                'delta' => Chart::delta($get($totals, 'net'), $get($prev, 'net')),
                'rows' => [
                    ['label' => 'Doanh thu', 'value' => Chart::money($get($totals, 'revenue'))],
                    ['label' => 'Trung bình mỗi đơn', 'value' => Chart::money($get($totals, 'aov'))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Đơn huỷ & hoàn', 'tone' => 'red', 'bad' => true,
                'icon' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
                'value' => Chart::int($get($totals, 'cancelled') + $get($totals, 'returned')),
                'delta' => Chart::delta($get($totals, 'cancelled') + $get($totals, 'returned'), $get($prev, 'cancelled') + $get($prev, 'returned')),
                'deltaNew' => 'Kỳ trước không có đơn hỏng',
                'rows' => [
                    ['label' => 'Tỷ lệ trên tổng đơn', 'value' => Chart::pct($get($totals, 'dead_rate'))],
                    ['label' => 'Kỳ trước', 'value' => Chart::pct($get($prev, 'dead_rate'))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Số món đã bán', 'tone' => 'violet',
                'icon' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
                'value' => Chart::int($get($totals, 'units')),
                'delta' => Chart::delta($get($totals, 'units'), $get($prev, 'units')),
                'rows' => [
                    ['label' => 'Số món mỗi đơn', 'value' => Chart::dec($get($totals, 'units_per_order'), 2)],
                    ['label' => 'Kỳ trước', 'value' => Chart::dec($get($prev, 'units_per_order'), 2)],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Chưa thu được tiền', 'tone' => 'amber', 'bad' => true,
                'icon' => '<circle cx="12" cy="12" r="10"/><path d="M12 7v5l3 2"/>',
                'value' => Chart::money($get($totals, 'unpaid_amount')),
                'delta' => Chart::delta($get($totals, 'unpaid_amount'), $get($prev, 'unpaid_amount')),
                'deltaNew' => 'Kỳ trước thu đủ',
                'rows' => [
                    ['label' => 'Số đơn chưa thu', 'value' => Chart::int($get($totals, 'unpaid_orders'))],
                    ['label' => 'Tỷ lệ trên doanh thu', 'value' => Chart::pct(Chart::share($get($totals, 'unpaid_amount'), $get($totals, 'revenue')))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Giờ đông đơn nhất', 'tone' => 'teal',
                'icon' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
                'value' => $peakHour && ($peakHour['orders'] ?? 0) > 0 ? ReportController::hourLabel($peakHour['key']) : '—',
                'note' => $peakHour && ($peakHour['orders'] ?? 0) > 0
                    ? Chart::int($peakHour['orders']).' đơn đặt trong khung giờ này'
                    : 'Kỳ này chưa có đơn nào',
                'rows' => [
                    ['label' => 'Khu vực nhiều đơn nhất', 'value' => $provinceRows ? $provinceRows[0]['label'] : '—'],
                    ['label' => 'Số tỉnh/thành có đơn', 'value' => Chart::int(count($report['by_province'] ?? []))],
                ],
            ])
        </div>

        {{-- ===== Biểu đồ chính ===== --}}
        @include('reports.partials.linechart', [
            'id' => 'ord',
            'title' => 'Số đơn theo thời gian',
            'sub' => 'Chỉ đếm đơn còn hiệu lực — đơn huỷ và hoàn nằm ở bảng trạng thái bên dưới.',
            'groupBy' => $group,
            'buckets' => $buckets,
            'series' => [
                ['key' => 'orders', 'label' => 'Số đơn', 'values' => $ordSeries, 'money' => false],
                ['key' => 'revenue', 'label' => 'Doanh thu', 'values' => $revSeries, 'money' => true],
            ],
        ])

        {{-- ===== Trạng thái + kênh bán ===== --}}
        <div class="rp-grid rp-grid--2-1">
            @include('reports.partials.bars', [
                'title' => 'Đơn theo trạng thái',
                'sub' => 'Sáu trạng thái trên là luồng xử lý bình thường; hai dòng dưới đường kẻ là đơn không thành.',
                'rows' => $statusRows,
                'empty' => 'Kỳ này chưa có đơn nào.',
            ])

            @include('reports.partials.donut', [
                'title' => 'Kênh đặt hàng',
                'sub' => 'Khách vãng lai không có tài khoản nên không theo dõi được lần mua sau.',
                'rows' => $channelRows,
                'centerValue' => Chart::int($channelTotal),
                'centerCap' => 'đơn',
                'empty' => 'Kỳ này chưa có đơn nào.',
            ])
        </div>

        {{-- ===== Thời điểm đặt hàng ===== --}}
        @include('reports.partials.columns', [
            'title' => 'Khách đặt hàng vào khung giờ nào',
            'sub' => $peakHour && ($peakHour['orders'] ?? 0) > 0
                ? 'Đông nhất lúc '.ReportController::hourLabel($peakHour['key']).' với '.Chart::int($peakHour['orders']).' đơn.'
                : null,
            'rows' => $hourRows,
            'empty' => 'Kỳ này chưa có đơn nào để chia theo khung giờ.',
        ])

        {{-- ===== Ba lát cắt còn lại ===== --}}
        <div class="rp-grid rp-grid--3">
            @include('reports.partials.bars', [
                'title' => 'Đơn theo thứ trong tuần',
                'sub' => 'Số đơn và doanh thu cộng dồn cả kỳ.',
                'rows' => $weekdayRows,
                'empty' => 'Kỳ này chưa có đơn nào.',
            ])

            @include('reports.partials.bars', [
                'title' => 'Khu vực nhận hàng',
                'sub' => 'Tối đa 12 tỉnh/thành nhiều đơn nhất.',
                'rows' => $provinceRows,
                'empty' => 'Kỳ này chưa có đơn nào.',
            ])

            @include('reports.partials.bars', [
                'title' => 'Hình thức vận chuyển',
                'sub' => 'Đơn chưa chọn hình thức được gộp riêng một dòng.',
                'rows' => $shipRows,
                'empty' => 'Kỳ này chưa có đơn nào.',
            ])
        </div>
    </div>

    @include('reports.partials.style')
    @include('reports.partials.script')
@endsection
