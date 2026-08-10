@extends('layouts.app')

@section('title', 'Báo cáo doanh thu')

@section('content')
    {{--
        Báo cáo doanh thu — tiền vào bao nhiêu, từ đâu, còn lại bao nhiêu.

        Quy ước số liệu (giống trang Tổng quan, đừng đổi lẻ ở đây):
          · KHÔNG tính đơn huỷ và đơn hoàn hàng.
          · Mốc thời gian là lúc khách ĐẶT đơn, không phải lúc giao xong.
          · Lãi gộp = tiền hàng − giảm giá − giá vốn. Phí vận chuyển là tiền thu
            hộ nhà xe nên không tính là lãi.
    --}}
    @php
        use App\Support\Chart;
        use App\Http\Controllers\OrderController;

        $PAY_METHODS = OrderController::PAYMENT_METHODS;
        $PAY_STATUSES = OrderController::PAYMENT_STATUSES;
        $COLORS = \App\Http\Controllers\ReportController::METHOD_COLORS;

        /** Tên phương thức bản ngắn — chú giải hẹp không chứa nổi phần trong ngoặc. */
        $shortMethod = fn ($key) => preg_replace('/\s*\(.*\)$/u', '', $PAY_METHODS[$key] ?? (string) $key);

        $buckets = $report['buckets'] ?? [];
        $totals = $report['totals'] ?? [];
        $prev = $report['prev'] ?? [];
        $group = $filters['group_by'];

        $get = fn ($arr, $key) => (float) ($arr[$key] ?? 0);

        $revSeries = array_map(fn ($b) => (float) ($b['revenue'] ?? 0), $buckets);
        $ordSeries = array_map(fn ($b) => (float) ($b['orders'] ?? 0), $buckets);
        $proSeries = array_map(fn ($b) => (float) ($b['profit'] ?? 0), $buckets);

        // Trung bình MỘT NGÀY, không phải một mốc: chia theo mốc thì con số nhảy
        // theo cách chia trục, cùng một kỳ mà xem theo tuần lại ra số khác.
        $days = max(1, (int) $filters['days']);
        $perDayRev = $get($totals, 'revenue') / $days;

        // Cơ cấu theo hình thức thanh toán.
        $methodTotal = collect($report['by_payment_method'] ?? [])->sum('orders');
        $methodRows = collect($report['by_payment_method'] ?? [])->map(fn ($m) => [
            'label' => $shortMethod($m['key']),
            'value' => Chart::money($m['revenue'] ?? 0),
            'share' => Chart::share($m['orders'] ?? 0, $methodTotal),
            'color' => $COLORS[$m['key']] ?? '#8c8c8c',
        ])->all();

        // Tình trạng thu tiền — đây là chỗ thấy phần doanh thu mới nằm trên giấy.
        $payTotal = collect($report['by_payment_status'] ?? [])->sum('revenue');
        $payTones = ['paid' => 'done', 'pending' => 'wait', 'failed' => 'stop', 'refunded' => 'stop'];
        $payRows = collect($report['by_payment_status'] ?? [])->map(fn ($s) => [
            'label' => $PAY_STATUSES[$s['key']] ?? $s['key'],
            'tone' => $payTones[$s['key']] ?? null,
            'value' => Chart::money($s['revenue'] ?? 0),
            'extra' => Chart::int($s['orders'] ?? 0).' đơn',
            'ratio' => Chart::share($s['revenue'] ?? 0, $payTotal),
            'dead' => in_array($s['key'], ['failed', 'refunded'], true),
        ])->all();

        // Mốc cao nhất / thấp nhất trong kỳ — hai câu này trả lời ngay "kỳ vừa rồi
        // được nhất hôm nào", thứ mà nhìn đường biểu đồ phải nheo mắt mới đoán ra.
        $best = null; $worst = null;
        foreach ($buckets as $b) {
            if ($best === null || ($b['revenue'] ?? 0) > ($best['revenue'] ?? 0)) { $best = $b; }
            if (($b['orders'] ?? 0) > 0 && ($worst === null || ($b['revenue'] ?? 0) < ($worst['revenue'] ?? 0))) { $worst = $b; }
        }

        $noCost = $get($totals, 'cost') <= 0 && $get($totals, 'units') > 0;
        $margin = Chart::share($get($totals, 'profit'), $get($totals, 'subtotal') - $get($totals, 'discount'));
    @endphp

    <div class="rp">
        @include('reports.partials.head', [
            'subtitle' => 'Tiền vào '.\App\Support\Period::describe($filters['from_date'], $filters['to_date'],
                \App\Http\Controllers\ReportController::QUICK_CODES).'. Không tính đơn đã huỷ và đơn hoàn hàng.',
        ])

        @if($noCost)
            <p class="rp-note">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                Chưa sản phẩm nào bán ra trong kỳ được khai giá vốn, nên lãi gộp đang bằng đúng doanh thu hàng.
                Khai giá vốn ở trang Tồn kho (hoặc trong form sản phẩm) thì con số này mới có nghĩa.
            </p>
        @endif

        {{-- ===== Hàng chỉ số ===== --}}
        <div class="rp-kpis">
            @include('reports.partials.kpi', [
                'label' => 'Doanh thu', 'tone' => 'blue',
                'icon' => '<path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
                'value' => Chart::money($get($totals, 'revenue')),
                'delta' => Chart::delta($get($totals, 'revenue'), $get($prev, 'revenue')),
                'deltaNew' => 'Kỳ trước chưa có doanh thu',
                'spark' => Chart::spark($revSeries),
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::money($get($prev, 'revenue'))],
                    ['label' => 'Trung bình mỗi ngày', 'value' => Chart::money($perDayRev)],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Số đơn', 'tone' => 'green',
                'icon' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
                'value' => Chart::int($get($totals, 'orders')),
                'delta' => Chart::delta($get($totals, 'orders'), $get($prev, 'orders')),
                'deltaNew' => 'Kỳ trước chưa có đơn',
                'spark' => Chart::spark($ordSeries),
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::int($get($prev, 'orders'))],
                    ['label' => 'Số món đã bán', 'value' => Chart::int($get($totals, 'units'))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Trung bình mỗi đơn', 'tone' => 'violet',
                'icon' => '<path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/>',
                'value' => Chart::money($get($totals, 'aov')),
                'delta' => Chart::delta($get($totals, 'aov'), $get($prev, 'aov')),
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::money($get($prev, 'aov'))],
                    ['label' => 'Số món / đơn', 'value' => $get($totals, 'orders') > 0
                        ? Chart::dec($get($totals, 'units') / $get($totals, 'orders'), 2) : '0'],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Lãi gộp', 'tone' => 'teal',
                'icon' => '<path d="M12 2a10 10 0 1 0 10 10h-10Z"/><path d="M12 2a10 10 0 0 1 10 10"/>',
                'value' => Chart::money($get($totals, 'profit')),
                'delta' => Chart::delta($get($totals, 'profit'), $get($prev, 'profit')),
                'spark' => Chart::spark($proSeries),
                'rows' => [
                    ['label' => 'Giá vốn hàng bán', 'value' => Chart::money($get($totals, 'cost'))],
                    ['label' => 'Biên lãi gộp', 'value' => Chart::pct($margin)],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Giảm giá đã dùng', 'tone' => 'amber', 'bad' => true,
                'icon' => '<path d="M9 15 15 9"/><circle cx="9.5" cy="9.5" r="1.5"/><circle cx="14.5" cy="14.5" r="1.5"/><path d="M4 12 12 4h8v8l-8 8-8-8Z"/>',
                'value' => Chart::money($get($totals, 'discount')),
                'delta' => Chart::delta($get($totals, 'discount'), $get($prev, 'discount')),
                'rows' => [
                    ['label' => 'Tiền hàng trước giảm', 'value' => Chart::money($get($totals, 'subtotal'))],
                    ['label' => 'Tỷ lệ trên tiền hàng', 'value' => Chart::pct(Chart::share($get($totals, 'discount'), $get($totals, 'subtotal')))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Phí vận chuyển', 'tone' => 'grey',
                'icon' => '<path d="M10 17h4V5H2v12h3"/><path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
                'value' => Chart::money($get($totals, 'shipping')),
                'delta' => Chart::delta($get($totals, 'shipping'), $get($prev, 'shipping')),
                'note' => '',
                'rows' => [
                    ['label' => 'Trung bình mỗi đơn', 'value' => $get($totals, 'orders') > 0
                        ? Chart::money($get($totals, 'shipping') / $get($totals, 'orders')) : Chart::money(0)],
                    ['label' => 'Không tính vào lãi gộp', 'value' => '—'],
                ],
            ])
        </div>

        {{-- ===== Biểu đồ chính ===== --}}
        @include('reports.partials.linechart', [
            'id' => 'rev',
            'title' => 'Doanh thu theo thời gian',
            'sub' => $best
                ? 'Cao nhất: '.\App\Http\Controllers\ReportController::bucketLabel($best['label'], $group).' — '.Chart::money($best['revenue'] ?? 0)
                    .($worst && $worst['label'] !== $best['label']
                        ? ' · Thấp nhất (có đơn): '.\App\Http\Controllers\ReportController::bucketLabel($worst['label'], $group).' — '.Chart::money($worst['revenue'] ?? 0)
                        : '')
                : null,
            'groupBy' => $group,
            'buckets' => $buckets,
            'series' => [
                ['key' => 'revenue', 'label' => 'Doanh thu', 'values' => $revSeries, 'money' => true],
                ['key' => 'profit', 'label' => 'Lãi gộp', 'values' => $proSeries, 'money' => true],
                ['key' => 'orders', 'label' => 'Số đơn', 'values' => $ordSeries, 'money' => false],
            ],
        ])

        {{-- ===== Cơ cấu ===== --}}
        <div class="rp-grid rp-grid--1-1">
            @include('reports.partials.donut', [
                'title' => 'Tiền vào theo hình thức thanh toán',
                'sub' => 'Vòng chia theo SỐ ĐƠN, con số bên cạnh là doanh thu.',
                'rows' => $methodRows,
                'centerValue' => Chart::int($methodTotal),
                'centerCap' => 'đơn',
                'empty' => 'Kỳ này chưa có đơn nào để chia theo hình thức thanh toán.',
            ])

            @include('reports.partials.bars', [
                'title' => 'Tình trạng thu tiền',
                'sub' => 'Phần "Chưa thanh toán" là doanh thu mới ghi nhận trên giấy, chưa về tài khoản.',
                'rows' => $payRows,
                'empty' => 'Kỳ này chưa có đơn nào.',
            ])
        </div>

        {{-- ===== Bảng chi tiết ===== --}}
        <section class="rp-card">
            <div class="rp-card-head">
                <div>
                    <h2 class="rp-card-title">Chi tiết theo {{ ['day' => 'ngày', 'week' => 'tuần', 'month' => 'tháng'][$group] }}</h2>
                    <p class="rp-card-sub">Doanh thu = tiền hàng − giảm giá + phí ship. Lãi gộp = tiền hàng − giảm giá − giá vốn.</p>
                </div>
            </div>

            @if($buckets)
                <div class="rp-table-wrap rp-table-wrap--tall">
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th>Mốc</th>
                                <th class="num">Số đơn</th>
                                <th class="num">Số món</th>
                                <th class="num">Tiền hàng</th>
                                <th class="num">Giảm giá</th>
                                <th class="num">Phí ship</th>
                                <th class="num">Doanh thu</th>
                                <th class="num">Giá vốn</th>
                                <th class="num">Lãi gộp</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($buckets as $b)
                                <tr>
                                    <td>{{ \App\Http\Controllers\ReportController::bucketLabel($b['label'], $group) }}</td>
                                    <td class="num">{{ Chart::int($b['orders'] ?? 0) }}</td>
                                    <td class="num">{{ Chart::int($b['units'] ?? 0) }}</td>
                                    <td class="num">{{ Chart::money($b['subtotal'] ?? 0) }}</td>
                                    <td class="num rp-muted">{{ Chart::money($b['discount'] ?? 0) }}</td>
                                    <td class="num rp-muted">{{ Chart::money($b['shipping'] ?? 0) }}</td>
                                    <td class="num"><b>{{ Chart::money($b['revenue'] ?? 0) }}</b></td>
                                    <td class="num rp-muted">{{ Chart::money($b['cost'] ?? 0) }}</td>
                                    <td class="num {{ ($b['profit'] ?? 0) >= 0 ? 'rp-pos' : 'rp-neg' }}">{{ Chart::money($b['profit'] ?? 0) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Tổng kỳ</td>
                                <td class="num">{{ Chart::int($get($totals, 'orders')) }}</td>
                                <td class="num">{{ Chart::int($get($totals, 'units')) }}</td>
                                <td class="num">{{ Chart::money($get($totals, 'subtotal')) }}</td>
                                <td class="num">{{ Chart::money($get($totals, 'discount')) }}</td>
                                <td class="num">{{ Chart::money($get($totals, 'shipping')) }}</td>
                                <td class="num">{{ Chart::money($get($totals, 'revenue')) }}</td>
                                <td class="num">{{ Chart::money($get($totals, 'cost')) }}</td>
                                <td class="num {{ $get($totals, 'profit') >= 0 ? 'rp-pos' : 'rp-neg' }}">{{ Chart::money($get($totals, 'profit')) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @else
                <p class="rp-empty">Kỳ này chưa có mốc nào.</p>
            @endif
        </section>
    </div>

    @include('reports.partials.style')
    @include('reports.partials.script')
@endsection
