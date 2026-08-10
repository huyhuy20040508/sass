@extends('layouts.app')

@section('title', 'Báo cáo sản phẩm')

@section('content')
    {{--
        Báo cáo sản phẩm — mặt hàng nào kéo doanh thu, mặt hàng nào nằm im.

        LƯU Ý về con số: "doanh thu" ở trang này là TIỀN HÀNG (tổng tiền các dòng
        đơn), không gồm phí vận chuyển và chưa trừ giảm giá cấp đơn — nên nó KHÔNG
        bằng doanh thu ở báo cáo doanh thu. Đó là chủ ý: giảm giá của cả đơn không
        chia được về từng dòng hàng một cách trung thực.
    --}}
    @php
        use App\Support\Chart;
        use App\Http\Controllers\ReportController;

        $SORTS = ReportController::PRODUCT_SORTS;
        $LIMITS = ReportController::LIMITS;

        $totals = $report['totals'] ?? [];
        $prev = $report['prev'] ?? [];
        $items = $report['items'] ?? [];
        $get = fn ($arr, $key) => (float) ($arr[$key] ?? 0);

        $catMax = max(1, (float) collect($report['by_category'] ?? [])->max('revenue'));
        $catRows = collect($report['by_category'] ?? [])->map(fn ($c) => [
            'label' => $c['label'] !== '' ? $c['label'] : 'Không rõ danh mục',
            'value' => Chart::money($c['revenue'] ?? 0),
            'extra' => Chart::int($c['units'] ?? 0).' món',
            'ratio' => Chart::share($c['revenue'] ?? 0, $catMax),
        ])->all();

        $brandMax = max(1, (float) collect($report['by_brand'] ?? [])->max('revenue'));
        $brandRows = collect($report['by_brand'] ?? [])->map(fn ($b) => [
            'label' => $b['label'] !== '' ? $b['label'] : 'Không rõ thương hiệu',
            'value' => Chart::money($b['revenue'] ?? 0),
            'extra' => Chart::int($b['units'] ?? 0).' món',
            'ratio' => Chart::share($b['revenue'] ?? 0, $brandMax),
        ])->all();

        // Size xếp theo SỐ MÓN chứ không theo tiền: cần biết nên nhập nhiều size
        // nào, mà size đắt tiền không đồng nghĩa với size bán chạy.
        $sizes = collect($report['by_size'] ?? [])->sortByDesc('units')->values();
        $sizeMax = max(1, (int) $sizes->max('units'));
        $sizeTotal = $sizes->sum('units');
        $sizeRows = $sizes->map(fn ($s) => [
            'label' => $s['label'] !== '' ? $s['label'] : 'Không rõ size',
            'value' => Chart::int($s['units'] ?? 0),
            'extra' => Chart::pct(Chart::share($s['units'] ?? 0, $sizeTotal)),
            'ratio' => Chart::share($s['units'] ?? 0, $sizeMax),
        ])->all();

        $noCost = $get($totals, 'cost') <= 0 && $get($totals, 'units') > 0;

        // Hai ô lọc riêng của trang này. Dựng ở đây rồi truyền vào partial đầu
        // trang để hàng bộ lọc của cả bốn báo cáo vẫn là MỘT hàng thống nhất.
        $extraFilters = view('reports.partials.product-filters', [
            'filters' => $filters, 'sorts' => $SORTS, 'limits' => $LIMITS,
        ])->render();
    @endphp

    <div class="rp">
        @include('reports.partials.head', [
            'subtitle' => 'Hàng bán ra '.\App\Support\Period::describe($filters['from_date'], $filters['to_date'],
                \App\Http\Controllers\ReportController::QUICK_CODES).'. Tiền hàng chưa gồm phí ship và giảm giá cấp đơn.',
            'showGroup' => false,
            'skip' => ['sort', 'limit'],
            'extra' => $extraFilters,
        ])

        @if($noCost)
            <p class="rp-note">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                Chưa sản phẩm nào bán ra trong kỳ được khai giá vốn, nên cột "Lãi gộp" đang bằng đúng cột "Tiền hàng"
                và biên lãi hiện 100%. Khai giá vốn ở trang Tồn kho thì bảng này mới xếp hạng được theo lãi thật.
            </p>
        @endif

        {{-- ===== Hàng chỉ số ===== --}}
        <div class="rp-kpis">
            @include('reports.partials.kpi', [
                'label' => 'Số món đã bán', 'tone' => 'blue',
                'icon' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
                'value' => Chart::int($get($totals, 'units')),
                'delta' => Chart::delta($get($totals, 'units'), $get($prev, 'units')),
                'deltaNew' => 'Kỳ trước chưa bán được món nào',
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::int($get($prev, 'units'))],
                    ['label' => 'Có mặt trong', 'value' => Chart::int($get($totals, 'orders')).' đơn'],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Tiền hàng', 'tone' => 'green',
                'icon' => '<path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
                'value' => Chart::money($get($totals, 'revenue')),
                'delta' => Chart::delta($get($totals, 'revenue'), $get($prev, 'revenue')),
                'deltaNew' => 'Kỳ trước chưa có doanh thu hàng',
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::money($get($prev, 'revenue'))],
                    ['label' => 'Trung bình mỗi món', 'value' => $get($totals, 'units') > 0
                        ? Chart::money($get($totals, 'revenue') / $get($totals, 'units')) : Chart::money(0)],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Giá vốn hàng bán', 'tone' => 'grey',
                'icon' => '<path d="M20.4 11.4V7.6H3.6V20a1 1 0 0 0 1 1h15.8V11.4Z"/><path d="M3.6 7.6 6.3 3.5h11.4l2.7 4.1"/><path d="M12 7.6V21"/>',
                'value' => Chart::money($get($totals, 'cost')),
                'delta' => Chart::delta($get($totals, 'cost'), $get($prev, 'cost')),
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::money($get($prev, 'cost'))],
                    ['label' => 'Tỷ lệ trên tiền hàng', 'value' => Chart::pct(Chart::share($get($totals, 'cost'), $get($totals, 'revenue')))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Lãi gộp', 'tone' => 'teal',
                'icon' => '<path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/>',
                'value' => Chart::money($get($totals, 'profit')),
                'delta' => Chart::delta($get($totals, 'profit'), $get($prev, 'profit')),
                'rows' => [
                    ['label' => 'Biên lãi gộp', 'value' => Chart::pct($get($totals, 'margin'))],
                    ['label' => 'Kỳ trước', 'value' => Chart::pct($get($prev, 'margin'))],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Sản phẩm bán được', 'tone' => 'violet',
                'icon' => '<path d="M20 6 9 17l-5-5"/>',
                'value' => Chart::int($get($totals, 'products_sold')),
                'delta' => Chart::delta($get($totals, 'products_sold'), $get($prev, 'products_sold')),
                'rows' => [
                    ['label' => 'Kỳ trước', 'value' => Chart::int($get($prev, 'products_sold'))],
                    ['label' => 'Số món / sản phẩm', 'value' => $get($totals, 'products_sold') > 0
                        ? Chart::dec($get($totals, 'units') / $get($totals, 'products_sold'), 1) : '0'],
                ],
            ])

            @include('reports.partials.kpi', [
                'label' => 'Sản phẩm không bán được', 'tone' => 'amber', 'bad' => true,
                'icon' => '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/>',
                'value' => Chart::int($report['unsold_products'] ?? 0),
                'note' => 'Đang bày bán mà cả kỳ không bán được món nào',
                'rows' => [
                    ['label' => 'Xem trang Sản phẩm', 'value' => '→'],
                    ['label' => 'Xem trang Tồn kho', 'value' => '→'],
                ],
            ])
        </div>

        {{-- ===== Bảng xếp hạng ===== --}}
        <section class="rp-card">
            <div class="rp-card-head">
                <div>
                    <h2 class="rp-card-title">Xếp hạng theo {{ mb_strtolower($SORTS[$filters['sort']]) }}</h2>
                    <p class="rp-card-sub">
                        Tối đa {{ $filters['limit'] }} sản phẩm. Cột "Tồn" là tồn kho HIỆN TẠI, không phải tồn lúc bán —
                        đọc cùng cột "Đã bán" để thấy mặt hàng chạy mà sắp hết.
                    </p>
                </div>
            </div>

            @if($items)
                <div class="rp-table-wrap">
                    <table class="rp-table">
                        <thead>
                            <tr>
                                <th class="num">#</th>
                                <th>Sản phẩm</th>
                                <th>Thương hiệu</th>
                                <th class="num">Số đơn</th>
                                <th class="num">Đã bán</th>
                                <th class="num">Tiền hàng</th>
                                <th class="num">Giá vốn</th>
                                <th class="num">Lãi gộp</th>
                                <th class="num">Biên lãi</th>
                                <th class="num">Tồn</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($items as $i => $item)
                                <tr>
                                    <td class="num"><span class="rp-rank {{ $i === 0 ? 'is-first' : '' }}">{{ $i + 1 }}</span></td>
                                    <td>
                                        <div class="rp-prod">
                                            @if(!empty($item['thumbnail']))
                                                <img class="rp-prod-img" src="{{ $item['thumbnail'] }}" alt="" loading="lazy">
                                            @else
                                                <span class="rp-prod-img"></span>
                                            @endif
                                            <span class="rp-prod-main">
                                                <span class="rp-prod-name" title="{{ $item['name'] }}">{{ $item['name'] }}</span>
                                                <span class="rp-prod-sub">{{ $item['sku'] ?: '—' }}{{ !empty($item['category_name']) ? ' · '.$item['category_name'] : '' }}</span>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="rp-muted">{{ $item['brand_name'] ?: '—' }}</td>
                                    <td class="num">{{ Chart::int($item['orders'] ?? 0) }}</td>
                                    <td class="num"><b>{{ Chart::int($item['units'] ?? 0) }}</b></td>
                                    <td class="num">{{ Chart::money($item['revenue'] ?? 0) }}</td>
                                    <td class="num rp-muted">{{ Chart::money($item['cost'] ?? 0) }}</td>
                                    <td class="num {{ ($item['profit'] ?? 0) >= 0 ? 'rp-pos' : 'rp-neg' }}">{{ Chart::money($item['profit'] ?? 0) }}</td>
                                    <td class="num rp-muted">{{ Chart::pct($item['margin'] ?? 0) }}</td>
                                    <td class="num">
                                        @if((int) ($item['stock'] ?? 0) <= 0)
                                            <span class="rp-badge tone-stop">Hết hàng</span>
                                        @elseif((int) $item['stock'] < (int) ($item['units'] ?? 0))
                                            <span class="rp-badge tone-wait">{{ Chart::int($item['stock']) }}</span>
                                        @else
                                            {{ Chart::int($item['stock']) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="num"></td>
                                <td>Tổng cả kỳ (mọi sản phẩm)</td>
                                <td></td>
                                <td class="num">{{ Chart::int($get($totals, 'orders')) }}</td>
                                <td class="num">{{ Chart::int($get($totals, 'units')) }}</td>
                                <td class="num">{{ Chart::money($get($totals, 'revenue')) }}</td>
                                <td class="num">{{ Chart::money($get($totals, 'cost')) }}</td>
                                <td class="num {{ $get($totals, 'profit') >= 0 ? 'rp-pos' : 'rp-neg' }}">{{ Chart::money($get($totals, 'profit')) }}</td>
                                <td class="num">{{ Chart::pct($get($totals, 'margin')) }}</td>
                                <td class="num"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="rp-foot-note">
                    Dòng tổng tính trên TOÀN BỘ hàng bán ra trong kỳ, không chỉ {{ count($items) }} sản phẩm đang hiện.
                </p>
            @else
                <p class="rp-empty">Kỳ này chưa bán được sản phẩm nào.</p>
            @endif
        </section>

        {{-- ===== Cơ cấu ===== --}}
        <div class="rp-grid rp-grid--3">
            @include('reports.partials.bars', [
                'title' => 'Theo danh mục',
                'sub' => 'Tối đa 12 danh mục có doanh thu cao nhất.',
                'rows' => $catRows,
                'empty' => 'Kỳ này chưa bán được món nào.',
            ])

            @include('reports.partials.bars', [
                'title' => 'Theo thương hiệu',
                'sub' => 'Tối đa 12 thương hiệu có doanh thu cao nhất.',
                'rows' => $brandRows,
                'empty' => 'Kỳ này chưa bán được món nào.',
            ])

            @include('reports.partials.bars', [
                'title' => 'Theo size',
                'sub' => 'Xếp theo SỐ MÓN — đây là con số dùng để quyết định nhập size nào.',
                'rows' => $sizeRows,
                'empty' => 'Kỳ này chưa bán được món nào.',
            ])
        </div>
    </div>

    @include('reports.partials.style')
    @include('reports.partials.script')
@endsection
