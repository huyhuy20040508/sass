@extends('layouts.app')

@section('title', 'Tổng quan')

@section('content')
    {{--
    Trang tổng quan — cùng ngôn ngữ thị giác với các trang danh sách (nền trắng,
    viền #f0f0f0, chữ #262626/#8c8c8c, xanh #1890ff). Nội dung TRẢI HẾT BỀ NGANG
    màn hình, không chặn ở một bề rộng cố định.

    Nguyên tắc khi vẽ số liệu ở đây:
      · Biểu đồ một chuỗi -> một màu, không cần chú giải; tiêu đề đã nói rõ đang
        vẽ gì. Chỉ vòng tròn cơ cấu thanh toán mới dùng nhiều màu, và nó luôn đi
        kèm chú giải có SỐ (màu ở cỡ đó không đủ tương phản để đọc một mình).
      · Màu gắn với ĐỐI TƯỢNG chứ không theo thứ hạng: COD luôn xanh dương, VNPay
        luôn tím… đổi kỳ xem thì màu không nhảy chỗ.
      · Mọi con số trong biểu đồ đều đọc được ở dạng bảng / chú giải, không khoá
        sau tooltip.
    --}}
    @php
        $RANGES = \App\Support\Period::buttons(\App\Http\Controllers\DashboardController::RANGE_CODES);
        // Ngưỡng sắp hết lấy từ cấu hình hệ thống (controller truyền xuống), không
        // đọc hằng số nữa — nếu không phụ đề sẽ nói một số, bảng lại lọc theo số khác.
        $LOW_STOCK = $lowStockThreshold;
        $ORDER_STATUSES = \App\Http\Controllers\OrderController::STATUSES;
        $ORDER_TONES = \App\Http\Controllers\OrderController::STATUS_TONES;
        $PAY_METHODS = \App\Http\Controllers\OrderController::PAYMENT_METHODS;

        /** Tên phương thức thanh toán bản ngắn — cột hẹp không chứa nổi phần trong ngoặc. */
        $shortMethod = fn ($key) => preg_replace('/\s*\(.*\)$/u', '', $PAY_METHODS[$key] ?? (string) $key);

        $money = fn ($v) => number_format((float) $v, 0, ',', '.') . '₫';
        $int = fn ($v) => number_format((float) $v, 0, ',', '.');
        $pct1 = fn ($v) => number_format((float) $v, 1, ',', '.') . '%';

        /** Rút gọn tiền cho nhãn trục / thẻ hẹp: 1,2tr — trục chỉ cần độ lớn. */
        $shortMoney = function ($v) {
            $v = (float) $v;
            if ($v >= 1000000) { return rtrim(rtrim(number_format($v / 1000000, 1, ',', '.'), '0'), ',') . 'tr'; }
            if ($v >= 1000) { return round($v / 1000) . 'k'; }
            return (string) round($v);
        };

        /** Mức tăng/giảm so với kỳ trước. null = kỳ trước không có gì để so. */
        $delta = function ($cur, $prev) {
            $cur = (float) $cur; $prev = (float) $prev;
            if ($prev <= 0) { return $cur > 0 ? null : 0.0; }
            return ($cur - $prev) / $prev * 100;
        };

        $points = $revenue['points'] ?? [];
        $n = count($points);
        $revSeries = array_map(fn ($p) => (float) ($p['revenue'] ?? 0), $points);
        $ordSeries = array_map(fn ($p) => (float) ($p['orders'] ?? 0), $points);

        $revDelta = $delta($revenue['total_revenue'] ?? 0, $revenue['prev_revenue'] ?? 0);
        $ordDelta = $delta($revenue['total_orders'] ?? 0, $revenue['prev_orders'] ?? 0);

        // ----- Hình học biểu đồ (tính sẵn ở PHP để SVG chỉ còn việc vẽ) -----
        // Chữ trong SVG phóng theo viewBox, nên bề ngang khung vẽ quyết định cỡ
        // chữ trục thực tế. Thẻ biểu đồ chiếm 2/3 bề ngang trang (đã trải hết màn),
        // khoảng 780–1180px; chọn 1000 để hệ số phóng nằm quanh 0,8–1,2.
        $VB_W = 1000; $VB_H = 260;
        $PAD_L = 66; $PAD_R = 22; $PAD_T = 16; $PAD_B = 34;
        $plotW = $VB_W - $PAD_L - $PAD_R;
        $plotH = $VB_H - $PAD_T - $PAD_B;
        $baseY = $PAD_T + $plotH;

        /** Làm tròn trần trục lên số đẹp (1 / 2 / 2,5 / 5 × 10^k). */
        $niceCeil = function ($max) {
            $max = (float) $max;
            if ($max <= 0) { return 1.0; }
            $pow = 10 ** floor(log10($max));
            foreach ([1, 2, 2.5, 5, 10] as $m) {
                if ($max <= $m * $pow) { return $m * $pow; }
            }
            return 10 * $pow;
        };

        /** Dựng đường + vùng tô cho một chuỗi giá trị. */
        $buildLine = function (array $vals, ?float $forceTop = null) use ($PAD_L, $plotW, $plotH, $baseY, $niceCeil) {
            $n = count($vals);
            $top = $forceTop ?: $niceCeil($n ? max($vals) : 0);
            $coords = [];
            foreach ($vals as $i => $v) {
                $x = $n > 1 ? $PAD_L + $plotW * $i / ($n - 1) : $PAD_L + $plotW / 2;
                $coords[] = [round($x, 2), round($baseY - ($v / $top) * $plotH, 2)];
            }
            $line = '';
            foreach ($coords as $i => $c) { $line .= ($i ? ' L' : 'M') . $c[0] . ',' . $c[1]; }
            $area = $coords
                ? 'M' . $coords[0][0] . ',' . $baseY
                  . ' L' . implode(' L', array_map(fn ($c) => $c[0] . ',' . $c[1], $coords))
                  . ' L' . end($coords)[0] . ',' . $baseY . ' Z'
                : '';
            $avg = $n ? array_sum($vals) / $n : 0;
            return [
                'top' => $top, 'coords' => $coords, 'line' => $line, 'area' => $area,
                'avg' => $avg, 'avgY' => round($baseY - ($avg / $top) * $plotH, 2),
            ];
        };

        $chartRev = $buildLine($revSeries);
        // Trục "số đơn" đếm bằng cái: ép trần chia hết cho 4 để 5 vạch lưới đều ra
        // số nguyên (24/18/12/6/0) thay vì 25/18,75/12,5…
        $ordTop = max(4, (int) (ceil(($ordSeries ? max($ordSeries) : 0) / 4) * 4));
        $chartOrd = $buildLine($ordSeries, (float) $ordTop);

        // Nhãn trục X: thưa (tối đa 7 mốc) để chữ không chồng nhau.
        $everyX = $n > 1 ? max(1, (int) ceil($n / 7)) : 1;
        $xAt = fn ($i) => $n > 1 ? round($PAD_L + $plotW * $i / ($n - 1), 2) : $PAD_L + $plotW / 2;

        // ----- Đường nhỏ trong thẻ chỉ số -----
        $spark = function (array $vals) {
            $w = 132; $h = 34; $n = count($vals);
            if ($n < 2) { return ['line' => '', 'area' => '']; }
            $max = max($vals); $min = min($vals);
            $span = $max - $min ?: 1;
            $coords = [];
            foreach ($vals as $i => $v) {
                $coords[] = [
                    round($w * $i / ($n - 1), 2),
                    round($h - 3 - (($v - $min) / $span) * ($h - 6), 2),
                ];
            }
            $line = '';
            foreach ($coords as $i => $c) { $line .= ($i ? ' L' : 'M') . $c[0] . ',' . $c[1]; }
            return [
                'w' => $w, 'h' => $h, 'line' => $line,
                'area' => 'M0,' . $h . ' L' . implode(' L', array_map(fn ($c) => $c[0] . ',' . $c[1], $coords)) . ' L' . $w . ',' . $h . ' Z',
            ];
        };
        $sparkRev = $spark($revSeries);
        $sparkOrd = $spark($ordSeries);

        // ----- Cơ cấu thanh toán (vòng tròn) -----
        // Màu gắn cứng theo phương thức, KHÔNG theo thứ hạng trong kỳ.
        $METHOD_COLORS = ['cod' => '#1890ff', 'vnpay' => '#722ed1', 'momo' => '#13c2c2', 'bank_transfer' => '#fa8c16', 'payos' => '#52c41a', 'sepay' => '#eb2f96'];
        $methodRows = [];
        $methodTotal = 0;
        foreach (($breakdown['methods'] ?? []) as $key => $m) {
            $methodTotal += (int) $m['orders'];
        }
        foreach (($breakdown['methods'] ?? []) as $key => $m) {
            $methodRows[] = [
                'key' => $key,
                'label' => $shortMethod($key),
                'orders' => (int) $m['orders'],
                'revenue' => (float) $m['revenue'],
                'share' => $methodTotal > 0 ? $m['orders'] / $methodTotal * 100 : 0,
                'color' => $METHOD_COLORS[$key] ?? '#8c8c8c',
            ];
        }

        $DONUT_R = 52; $DONUT_SW = 22;
        $DONUT_C = 2 * M_PI * $DONUT_R;
        $donutOffset = 0.0;
        foreach ($methodRows as $i => $row) {
            $len = $DONUT_C * $row['share'] / 100;
            $methodRows[$i]['dash'] = round(max(0, $len - 2), 2);   // chừa 2px hở giữa hai lát
            $methodRows[$i]['gap'] = round($DONUT_C - max(0, $len - 2), 2);
            $methodRows[$i]['offset'] = round(-$donutOffset, 2);
            $donutOffset += $len;
        }

        // ----- Trạng thái đơn trong kỳ (theo luồng xử lý) -----
        $flow = ['pending', 'confirmed', 'processing', 'shipping', 'delivered', 'completed'];
        $statusRows = [];
        foreach ($flow as $s) {
            $statusRows[] = [
                'key' => $s,
                'label' => $ORDER_STATUSES[$s] ?? $s,
                'tone' => $ORDER_TONES[$s] ?? 'info',
                'value' => (int) ($breakdown['statuses'][$s] ?? 0),
            ];
        }
        $deadRows = [
            ['key' => 'cancelled', 'label' => $ORDER_STATUSES['cancelled'], 'tone' => 'stop', 'value' => (int) ($breakdown['statuses']['cancelled'] ?? 0)],
            ['key' => 'returned', 'label' => $ORDER_STATUSES['returned'], 'tone' => 'stop', 'value' => (int) ($breakdown['statuses']['returned'] ?? 0)],
        ];
        $statusMax = max(1, max(array_merge(array_column($statusRows, 'value'), array_column($deadRows, 'value'))));

        // ----- Khung giờ đặt hàng -----
        $hours = $breakdown['hours'] ?? array_fill(0, 24, 0);
        $hourMax = max(1, max($hours));
        $peakHour = array_search(max($hours), $hours, true);
        $hourTotal = array_sum($hours);

        // ----- Tỉnh/thành -----
        $provinces = $breakdown['provinces'] ?? [];
        $provinceMax = max(1, max(array_merge([1], array_map(fn ($p) => (int) $p['orders'], $provinces))));

        // ----- Chỉ số phái sinh -----
        $scanned = (int) ($breakdown['scanned'] ?? 0);
        $deadRate = $scanned > 0 ? ($breakdown['dead_orders'] ?? 0) / $scanned * 100 : 0;
        $needAction = (int) ($orderStats['pending'] ?? 0) + (int) ($returnStats['pending'] ?? 0);
        $returnOpen = (int) ($returnStats['pending'] ?? 0) + (int) ($returnStats['approved'] ?? 0) + (int) ($returnStats['received'] ?? 0);
        $perDayRev = $days > 0 ? ($revenue['total_revenue'] ?? 0) / $days : 0;
        $perDayOrd = $days > 0 ? ($revenue['total_orders'] ?? 0) / $days : 0;
        $paidAmount = max(0, ($breakdown['net_revenue'] ?? 0) - ($breakdown['unpaid_amount'] ?? 0));
    @endphp

    {{-- Trang này thường mở suốt ngày trên máy ở quầy. Mọi kỳ xem ở đây đều neo
         vào hôm nay, nên qua nửa đêm là tự nạp lại: nếu không, tiêu đề vẫn ghi
         "hôm nay" trong khi con số là của ngày hôm trước. --}}
    @include('partials.day-rollover', ['date' => $today, 'anchored' => true])

    <div class="db">
        {{-- Đầu trang: tiêu đề + bộ lọc khoảng thời gian.
             Bộ lọc đặt MỘT hàng phía trên mọi thứ nó chi phối, không nhét vào trong thẻ biểu đồ. --}}
        <header class="db-head">
            <div class="db-head-main">
                <h1 class="db-title">Tổng quan</h1>
                <p class="db-sub">
                    {{-- Kỳ đã đóng sổ thì nói rõ nó là số CHỐT, không đổi nữa; kỳ còn
                         chạy tới nửa đêm thì nói mốc giờ đã tính tới, vì mở lại sau
                         một tiếng là con số sẽ khác. --}}
                    Chào {{ data_get($user, 'full_name', 'bạn') }} —
                    @if($range === 'yesterday')
                        số liệu đã chốt của ngày {{ \Illuminate\Support\Carbon::parse($window['from'])->format('d/m/Y') }}.
                    @else
                        số liệu tính tới {{ now()->format('H:i') }} ngày {{ now()->format('d/m/Y') }}.
                    @endif
                    @if(!$apiOnline)
                        <span class="db-offline">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                            Mất kết nối API — số liệu bên dưới có thể chưa cập nhật
                        </span>
                    @endif
                </p>
            </div>
            <div class="db-head-tools">
                <div class="db-range" role="group" aria-label="Khoảng thời gian">
                    @foreach($RANGES as $preset)
                        <a href="{{ route('admin.dashboard', ['range' => $preset['code']]) }}"
                           class="db-range-btn {{ $range === $preset['code'] ? 'is-active' : '' }}"
                           @if($range === $preset['code']) aria-current="true" @endif>{{ $preset['label'] }}</a>
                    @endforeach
                </div>
                <a class="db-ghostbtn" href="{{ route('admin.dashboard', ['range' => $range]) }}">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
                    Làm mới
                </a>
                <a class="db-ghostbtn" href="{{ route('admin.orders.export') }}">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                    Xuất đơn hàng
                </a>
            </div>
        </header>

        {{-- ===== Hàng chỉ số =====
             Mỗi thẻ một sắc: dải màu bên trái + nền chuyển sắc rất nhạt. Màu ở đây
             chỉ để TÁCH thẻ cho dễ quét mắt, không mã hoá dữ liệu — nên chọn theo
             nghĩa của chỉ số (tiền chưa thu = cam, huỷ/hoàn = đỏ) và giữ nguyên khi
             đổi kỳ xem. Con số vẫn đen, mức tăng/giảm vẫn xanh lá / đỏ như cũ. --}}
        <div class="db-kpis">
            {{-- Doanh thu kỳ đang xem --}}
            <div class="db-kpi db-kpi--blue">
                <div class="db-kpi-top">
                    <span class="db-kpi-label">Doanh thu {{ $periodLabel }}</span>
                    <span class="db-kpi-icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </span>
                </div>
                <div class="db-kpi-main">
                    <span class="db-kpi-value">{{ $money($revenue['total_revenue'] ?? 0) }}</span>
                    @if(!empty($sparkRev['line']))
                        <svg class="db-spark" viewBox="0 0 {{ $sparkRev['w'] }} {{ $sparkRev['h'] }}" preserveAspectRatio="none" aria-hidden="true">
                            <path d="{{ $sparkRev['area'] }}" class="db-spark-area"/>
                            <path d="{{ $sparkRev['line'] }}" class="db-spark-line"/>
                        </svg>
                    @endif
                </div>
                <div class="db-kpi-foot">
                    @if($revDelta === null)
                        <span class="db-delta is-new">Kỳ trước chưa có doanh thu</span>
                    @else
                        <span class="db-delta {{ $revDelta >= 0 ? 'is-up' : 'is-down' }}">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="{{ $revDelta >= 0 ? 'm5 15 7-7 7 7' : 'm5 9 7 7 7-7' }}"/>
                            </svg>
                            {{ $pct1(abs($revDelta)) }}
                        </span>
                        <span class="db-kpi-note">so với kỳ trước</span>
                    @endif
                </div>
                <ul class="db-kpi-rows">
                    <li><span>Kỳ trước</span><b>{{ $money($revenue['prev_revenue'] ?? 0) }}</b></li>
                    <li><span>Trung bình mỗi ngày</span><b>{{ $money($perDayRev) }}</b></li>
                </ul>
            </div>

            {{-- Số đơn kỳ đang xem --}}
            <div class="db-kpi db-kpi--green">
                <div class="db-kpi-top">
                    <span class="db-kpi-label">Đơn hàng {{ $periodLabel }}</span>
                    <span class="db-kpi-icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                    </span>
                </div>
                <div class="db-kpi-main">
                    <span class="db-kpi-value">{{ $int($revenue['total_orders'] ?? 0) }}</span>
                    @if(!empty($sparkOrd['line']))
                        <svg class="db-spark" viewBox="0 0 {{ $sparkOrd['w'] }} {{ $sparkOrd['h'] }}" preserveAspectRatio="none" aria-hidden="true">
                            <path d="{{ $sparkOrd['area'] }}" class="db-spark-area"/>
                            <path d="{{ $sparkOrd['line'] }}" class="db-spark-line"/>
                        </svg>
                    @endif
                </div>
                <div class="db-kpi-foot">
                    @if($ordDelta === null)
                        <span class="db-delta is-new">Kỳ trước chưa có đơn</span>
                    @else
                        <span class="db-delta {{ $ordDelta >= 0 ? 'is-up' : 'is-down' }}">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="{{ $ordDelta >= 0 ? 'm5 15 7-7 7 7' : 'm5 9 7 7 7-7' }}"/>
                            </svg>
                            {{ $pct1(abs($ordDelta)) }}
                        </span>
                        <span class="db-kpi-note">so với kỳ trước</span>
                    @endif
                </div>
                <ul class="db-kpi-rows">
                    <li><span>Kỳ trước</span><b>{{ $int($revenue['prev_orders'] ?? 0) }}</b></li>
                    <li><span>Trung bình mỗi ngày</span><b>{{ number_format($perDayOrd, 1, ',', '.') }}</b></li>
                </ul>
            </div>

            {{-- Giá trị trung bình mỗi đơn --}}
            <div class="db-kpi db-kpi--violet">
                <div class="db-kpi-top">
                    <span class="db-kpi-label">Giá trị trung bình / đơn</span>
                    <span class="db-kpi-icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 4-4 3 3 5-6"/></svg>
                    </span>
                </div>
                <div class="db-kpi-main">
                    <span class="db-kpi-value">{{ $money($breakdown['aov'] ?? 0) }}</span>
                </div>
                <div class="db-kpi-foot">
                    <span class="db-kpi-note">Tính trên {{ $int($breakdown['net_orders'] ?? 0) }} đơn còn hiệu lực</span>
                </div>
                <ul class="db-kpi-rows">
                    <li><span>Tổng thu trong kỳ</span><b>{{ $money($breakdown['net_revenue'] ?? 0) }}</b></li>
                    <li><span>Đơn khách vãng lai</span><b>{{ $int($breakdown['guest_orders'] ?? 0) }}</b></li>
                </ul>
            </div>

            {{-- Tiền chưa thu --}}
            <div class="db-kpi db-kpi--amber">
                <div class="db-kpi-top">
                    <span class="db-kpi-label">Chưa thu tiền</span>
                    <span class="db-kpi-icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                    </span>
                </div>
                <div class="db-kpi-main">
                    <span class="db-kpi-value">{{ $money($breakdown['unpaid_amount'] ?? 0) }}</span>
                </div>
                <div class="db-kpi-foot">
                    <span class="db-kpi-note">Phần lớn là đơn COD chưa giao xong</span>
                </div>
                <ul class="db-kpi-rows">
                    <li>
                        <a href="{{ route('admin.orders.index', ['payment_status' => 'pending']) }}">Đơn chưa thanh toán</a>
                        <b>{{ $int($breakdown['unpaid_orders'] ?? 0) }}</b>
                    </li>
                    <li><span>Đã thu trong kỳ</span><b>{{ $money($paidAmount) }}</b></li>
                </ul>
            </div>

            {{-- Tỷ lệ huỷ / hoàn --}}
            <div class="db-kpi db-kpi--red">
                <div class="db-kpi-top">
                    <span class="db-kpi-label">Tỷ lệ huỷ / hoàn</span>
                    <span class="db-kpi-icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5"/><path d="M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l3 2"/></svg>
                    </span>
                </div>
                <div class="db-kpi-main">
                    <span class="db-kpi-value {{ $deadRate >= 15 ? 'is-warn' : '' }}">{{ $pct1($deadRate) }}</span>
                </div>
                <div class="db-kpi-foot">
                    <span class="db-kpi-note">{{ $int($breakdown['dead_orders'] ?? 0) }} / {{ $int($scanned) }} đơn trong kỳ</span>
                </div>
                <ul class="db-kpi-rows">
                    <li>
                        <a href="{{ route('admin.orders.index', ['status' => 'cancelled']) }}">Đơn đã huỷ</a>
                        <b>{{ $int($breakdown['statuses']['cancelled'] ?? 0) }}</b>
                    </li>
                    <li>
                        <a href="{{ route('admin.orders.index', ['status' => 'returned']) }}">Đơn trả hàng</a>
                        <b>{{ $int($breakdown['statuses']['returned'] ?? 0) }}</b>
                    </li>
                </ul>
            </div>

            {{-- Việc cần làm --}}
            <div class="db-kpi db-kpi--teal {{ $needAction > 0 ? 'is-alert' : '' }}">
                <div class="db-kpi-top">
                    <span class="db-kpi-label">Cần xử lý ngay</span>
                    <span class="db-kpi-icon">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2z"/><path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/></svg>
                    </span>
                </div>
                <div class="db-kpi-main">
                    <span class="db-kpi-value">{{ $int($needAction) }}</span>
                </div>
                <div class="db-kpi-foot">
                    <span class="db-kpi-note">
                        {{ $needAction > 0 ? 'Xử lý sớm để đơn không trễ hẹn giao' : 'Không còn việc nào tồn lại' }}
                    </span>
                </div>
                <ul class="db-kpi-rows">
                    <li>
                        <a href="{{ route('admin.orders.index', ['status' => 'pending']) }}">Đơn chờ xác nhận</a>
                        <b>{{ $int($orderStats['pending'] ?? 0) }}</b>
                    </li>
                    <li>
                        <a href="{{ route('admin.returns.index', ['status' => 'pending']) }}">Phiếu trả chờ duyệt</a>
                        <b>{{ $int($returnStats['pending'] ?? 0) }}</b>
                    </li>
                </ul>
            </div>
        </div>

        @if(!empty($breakdown['sampled']))
            <p class="db-notice">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                Kỳ này có {{ $int($breakdown['total']) }} đơn — các mặt cắt bên dưới (giá trị trung bình, thanh toán,
                khu vực, khung giờ) tính trên {{ $int($scanned) }} đơn mới nhất.
            </p>
        @endif

        {{-- ===== Biểu đồ doanh thu + trạng thái đơn ===== --}}
        <div class="db-grid db-grid--2-1">
            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Diễn biến theo ngày</h3>
                        <p class="db-card-sub">{{ $periodLabel }} · không tính đơn huỷ và đơn hoàn hàng</p>
                    </div>
                    <div class="db-card-tools">
                        <div class="db-tabs" role="tablist" aria-label="Chỉ số">
                            <button type="button" class="db-tab is-active" data-tab="revenue" role="tab" aria-selected="true">Doanh thu</button>
                            <button type="button" class="db-tab" data-tab="orders" role="tab" aria-selected="false">Số đơn</button>
                        </div>
                        <button type="button" class="db-linkbtn" id="dbTableToggle" aria-expanded="false" aria-controls="dbTable">
                            Xem dạng bảng
                        </button>
                    </div>
                </div>

                @if($n === 0)
                    <p class="db-empty">Chưa có dữ liệu trong khoảng này.</p>
                @else
                    @foreach([
                        ['metric' => 'revenue', 'chart' => $chartRev, 'label' => 'doanh thu'],
                        ['metric' => 'orders', 'chart' => $chartOrd, 'label' => 'số đơn'],
                    ] as $pane)
                        @php $c = $pane['chart']; @endphp
                        <div class="db-plot" data-metric="{{ $pane['metric'] }}"
                             @if($pane['metric'] !== 'revenue') hidden @endif
                             data-points="{{ json_encode(array_map(fn ($p) => [
                                 'd' => $p['date'] ?? '', 'r' => (float) ($p['revenue'] ?? 0), 'o' => (int) ($p['orders'] ?? 0),
                             ], $points), JSON_UNESCAPED_UNICODE) }}"
                             data-geo="{{ json_encode(['padL' => $PAD_L, 'padR' => $PAD_R, 'padT' => $PAD_T, 'baseY' => $baseY, 'w' => $VB_W, 'h' => $VB_H, 'top' => $c['top']]) }}">
                            <svg viewBox="0 0 {{ $VB_W }} {{ $VB_H }}" class="db-svg" role="img"
                                 aria-label="Biểu đồ {{ $pane['label'] }} {{ $periodLabel }}">
                                <defs>
                                    <linearGradient id="dbFill{{ $pane['metric'] }}" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#1890ff" stop-opacity=".18"/>
                                        <stop offset="100%" stop-color="#1890ff" stop-opacity="0"/>
                                    </linearGradient>
                                </defs>

                                {{-- Lưới + nhãn trục Y: nét mảnh liền, lùi hẳn về sau --}}
                                @for($t = 0; $t <= 4; $t++)
                                    @php
                                        $gy = round($PAD_T + $plotH * $t / 4, 2);
                                        $gv = $c['top'] * (4 - $t) / 4;
                                    @endphp
                                    <line x1="{{ $PAD_L }}" y1="{{ $gy }}" x2="{{ $VB_W - $PAD_R }}" y2="{{ $gy }}" class="db-gridline"/>
                                    <text x="{{ $PAD_L - 10 }}" y="{{ $gy + 4 }}" class="db-tick db-tick--y">
                                        {{ $pane['metric'] === 'revenue' ? $shortMoney($gv) : round($gv) }}
                                    </text>
                                @endfor

                                {{-- Đường trung bình kỳ: mốc so sánh cho mọi ngày --}}
                                <line x1="{{ $PAD_L }}" y1="{{ $c['avgY'] }}" x2="{{ $VB_W - $PAD_R }}" y2="{{ $c['avgY'] }}" class="db-avgline"/>
                                {{-- Nhãn nằm DƯỚI đường trung bình: phía trên nó là vùng đường dữ
                                     liệu hay chạy qua, đặt lên trên là chồng chữ vào đường. --}}
                                <text x="{{ $VB_W - $PAD_R }}" y="{{ min($baseY - 4, $c['avgY'] + 13) }}" class="db-tick db-tick--avg" text-anchor="end">
                                    TB {{ $pane['metric'] === 'revenue' ? $shortMoney($c['avg']) : number_format($c['avg'], 1, ',', '.') }}
                                </text>

                                <path d="{{ $c['area'] }}" fill="url(#dbFill{{ $pane['metric'] }})"/>
                                <path d="{{ $c['line'] }}" class="db-line"/>

                                {{-- Nhãn trục X thưa. Mốc đầu/cuối phải neo theo mép (start/end),
                                     để giữa thì nửa chữ tràn ra ngoài viewBox và bị cắt mất. --}}
                                @foreach($points as $i => $p)
                                    @if($i % $everyX === 0 || $i === $n - 1)
                                        <text x="{{ $xAt($i) }}" y="{{ $VB_H - 12 }}" class="db-tick"
                                              text-anchor="{{ $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') }}">
                                            {{ \Illuminate\Support\Carbon::parse($p['date'])->format('d/m') }}
                                        </text>
                                    @endif
                                @endforeach

                                {{-- Chấm cuối chuỗi: mốc duy nhất được gắn nhãn trực tiếp --}}
                                @if($c['coords'])
                                    <circle cx="{{ end($c['coords'])[0] }}" cy="{{ end($c['coords'])[1] }}" r="4.5" class="db-dot-end"/>
                                @endif

                                {{-- Lớp rê chuột: đường dóng + chấm, JS điều khiển --}}
                                <line class="db-cross" x1="0" y1="{{ $PAD_T }}" x2="0" y2="{{ $baseY }}" hidden/>
                                <circle class="db-dot-hover" r="5" hidden/>
                                <rect class="db-hit" x="{{ $PAD_L }}" y="{{ $PAD_T }}" width="{{ $plotW }}" height="{{ $plotH }}" fill="transparent"/>
                            </svg>
                            <div class="db-tip" hidden></div>
                        </div>
                    @endforeach

                    {{-- Bảng số liệu: mọi giá trị đều đọc được mà không cần rê chuột --}}
                    <div class="db-table-wrap db-table-wrap--tall" id="dbTable" hidden>
                        <table class="db-table">
                            <thead>
                                <tr><th>Ngày</th><th class="num">Số đơn</th><th class="num">Doanh thu</th></tr>
                            </thead>
                            <tbody>
                                @foreach(array_reverse($points) as $p)
                                    <tr>
                                        <td>{{ \Illuminate\Support\Carbon::parse($p['date'])->format('d/m/Y') }}</td>
                                        <td class="num">{{ $int($p['orders'] ?? 0) }}</td>
                                        <td class="num">{{ $money($p['revenue'] ?? 0) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Đơn theo trạng thái</h3>
                        <p class="db-card-sub">{{ $int($scanned) }} đơn của {{ $periodLabel }}</p>
                    </div>
                </div>

                @if($scanned === 0)
                    <p class="db-empty">Chưa có đơn nào trong khoảng này.</p>
                @else
                    <ul class="db-bars">
                        @foreach($statusRows as $row)
                            <li class="db-bar-row">
                                <a class="db-bar-label" href="{{ route('admin.orders.index', ['status' => $row['key']]) }}">
                                    <span class="db-dot tone-{{ $row['tone'] }}"></span>{{ $row['label'] }}
                                </a>
                                <span class="db-bar-track">
                                    <span class="db-bar-fill" style="width: {{ $row['value'] > 0 ? max(2, round($row['value'] / $statusMax * 100)) : 0 }}%"></span>
                                </span>
                                <span class="db-bar-value">{{ $int($row['value']) }}</span>
                            </li>
                        @endforeach

                        <li class="db-bars-split" aria-hidden="true"></li>

                        @foreach($deadRows as $row)
                            <li class="db-bar-row">
                                <a class="db-bar-label" href="{{ route('admin.orders.index', ['status' => $row['key']]) }}">
                                    <span class="db-dot tone-stop"></span>{{ $row['label'] }}
                                </a>
                                <span class="db-bar-track">
                                    <span class="db-bar-fill is-dead" style="width: {{ $row['value'] > 0 ? max(2, round($row['value'] / $statusMax * 100)) : 0 }}%"></span>
                                </span>
                                <span class="db-bar-value">{{ $int($row['value']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="db-mini">
                    <div class="db-mini-item">
                        <span class="db-mini-label">Phiếu trả đang xử lý</span>
                        <a class="db-mini-value" href="{{ route('admin.returns.index', ['status' => 'pending']) }}">{{ $int($returnOpen) }}</a>
                    </div>
                    <div class="db-mini-item">
                        <span class="db-mini-label">Đã hoàn tiền</span>
                        <span class="db-mini-value">{{ $money($returnStats['refunded_amount'] ?? 0) }}</span>
                    </div>
                    <div class="db-mini-item">
                        <span class="db-mini-label">Khách vãng lai</span>
                        <span class="db-mini-value">{{ $int($breakdown['guest_orders'] ?? 0) }}</span>
                    </div>
                </div>
            </section>
        </div>

        {{-- ===== Cơ cấu thanh toán · khu vực · khung giờ ===== --}}
        <div class="db-grid db-grid--3">
            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Cơ cấu thanh toán</h3>
                        <p class="db-card-sub">Theo số đơn còn hiệu lực trong kỳ</p>
                    </div>
                </div>

                @if(empty($methodRows))
                    <p class="db-empty">Chưa có dữ liệu thanh toán.</p>
                @else
                    <div class="db-donut-wrap">
                        <svg viewBox="0 0 140 140" class="db-donut" role="img" aria-label="Tỷ trọng đơn theo phương thức thanh toán">
                            <g transform="translate(70,70) rotate(-90)">
                                <circle r="{{ $DONUT_R }}" fill="none" stroke="#f0f2f5" stroke-width="{{ $DONUT_SW }}"/>
                                @foreach($methodRows as $row)
                                    <circle r="{{ $DONUT_R }}" fill="none" stroke="{{ $row['color'] }}" stroke-width="{{ $DONUT_SW }}"
                                            stroke-dasharray="{{ $row['dash'] }} {{ $row['gap'] }}"
                                            stroke-dashoffset="{{ $row['offset'] }}">
                                        <title>{{ $row['label'] }}: {{ $int($row['orders']) }} đơn ({{ $pct1($row['share']) }})</title>
                                    </circle>
                                @endforeach
                            </g>
                            <text x="70" y="66" class="db-donut-num" text-anchor="middle">{{ $int($methodTotal) }}</text>
                            <text x="70" y="82" class="db-donut-cap" text-anchor="middle">đơn</text>
                        </svg>

                        {{-- Chú giải mang SỐ: màu ở cỡ lát bánh không đủ tương phản để đọc một mình. --}}
                        <ul class="db-legend">
                            @foreach($methodRows as $row)
                                <li class="db-legend-item">
                                    <span class="db-legend-swatch" style="background: {{ $row['color'] }}"></span>
                                    <a class="db-legend-name" href="{{ route('admin.orders.index', ['payment_method' => $row['key']]) }}">{{ $row['label'] }}</a>
                                    <span class="db-legend-val">{{ $int($row['orders']) }}</span>
                                    <span class="db-legend-pct">{{ $pct1($row['share']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Khu vực giao hàng</h3>
                        <p class="db-card-sub">{{ count($provinces) }} tỉnh/thành nhiều đơn nhất trong kỳ</p>
                    </div>
                </div>

                @if(empty($provinces))
                    <p class="db-empty">Chưa có dữ liệu địa chỉ giao hàng.</p>
                @else
                    <ul class="db-bars">
                        @foreach($provinces as $name => $p)
                            <li class="db-bar-row db-bar-row--wide">
                                <span class="db-bar-label db-ellip" title="{{ $name }}">{{ $name }}</span>
                                <span class="db-bar-track">
                                    <span class="db-bar-fill" style="width: {{ max(2, round($p['orders'] / $provinceMax * 100)) }}%"></span>
                                </span>
                                <span class="db-bar-value">{{ $int($p['orders']) }}</span>
                                <span class="db-bar-extra">{{ $shortMoney($p['revenue']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Khung giờ đặt hàng</h3>
                        <p class="db-card-sub">
                            @if($hourTotal > 0)
                                Cao điểm {{ str_pad((string) $peakHour, 2, '0', STR_PAD_LEFT) }}:00–{{ str_pad((string) (($peakHour + 1) % 24), 2, '0', STR_PAD_LEFT) }}:00 · {{ $int(max($hours)) }} đơn
                            @else
                                Chưa có đơn trong kỳ
                            @endif
                        </p>
                    </div>
                </div>

                @if($hourTotal === 0)
                    <p class="db-empty">Chưa có dữ liệu khung giờ.</p>
                @else
                    {{-- viewBox chọn theo TỶ LỆ thật của thẻ (≈3,7:1) để hình căng hết bề
                         ngang mà chiều cao vẫn vừa mắt; 24 cột chia đều bước 23,33. --}}
                    <svg viewBox="0 0 560 150" class="db-hours" role="img" aria-label="Số đơn theo giờ trong ngày">
                        @for($t = 0; $t <= 2; $t++)
                            @php $gy = round(14 + 100 * $t / 2, 2); @endphp
                            <line x1="0" y1="{{ $gy }}" x2="560" y2="{{ $gy }}" class="db-gridline"/>
                        @endfor
                        @foreach($hours as $h => $v)
                            @php
                                $x = round($h * 23.33 + 4, 2);
                                $bh = $v > 0 ? max(3, round($v / $hourMax * 100, 2)) : 0;
                            @endphp
                            @if($bh > 0)
                                <rect x="{{ $x }}" y="{{ round(114 - $bh, 2) }}" width="15.3" height="{{ $bh }}" rx="3"
                                      class="db-hbar {{ $h === $peakHour ? 'is-peak' : '' }}">
                                    <title>{{ str_pad((string) $h, 2, '0', STR_PAD_LEFT) }}:00 — {{ $int($v) }} đơn</title>
                                </rect>
                            @endif
                        @endforeach
                        @foreach([0, 6, 12, 18, 23] as $h)
                            <text x="{{ round($h * 23.33 + 11.6, 2) }}" y="133" class="db-tick"
                                  text-anchor="{{ $h === 0 ? 'start' : ($h === 23 ? 'end' : 'middle') }}">{{ str_pad((string) $h, 2, '0', STR_PAD_LEFT) }}h</text>
                        @endforeach
                    </svg>
                    <p class="db-foot-note">Cột cao nhất là khung giờ nên trực đơn và chạy quảng cáo.</p>
                @endif
            </section>
        </div>

        {{-- ===== Đơn gần đây + sản phẩm bán chạy ===== --}}
        <div class="db-grid db-grid--2-1">
            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Đơn hàng gần đây</h3>
                        <p class="db-card-sub">{{ count($recentOrders) }} đơn mới nhất</p>
                    </div>
                    <a class="db-linkbtn" href="{{ route('admin.orders.index') }}">Xem tất cả →</a>
                </div>

                @if(empty($recentOrders))
                    <p class="db-empty">Chưa có đơn hàng nào.</p>
                @else
                    <div class="db-table-wrap">
                        <table class="db-table">
                            <thead>
                                <tr>
                                    <th>Mã đơn</th><th>Người nhận</th><th>Khu vực</th>
                                    <th class="num">Tổng tiền</th><th>Thanh toán</th>
                                    <th>Trạng thái</th><th class="num">Ngày đặt</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentOrders as $o)
                                    @php $st = $o['status'] ?? 'pending'; $ps = $o['payment_status'] ?? 'pending'; @endphp
                                    <tr>
                                        <td>
                                            <a class="db-code" href="{{ route('admin.orders.index', ['keyword' => $o['order_code'] ?? '']) }}">
                                                {{ $o['order_code'] ?? '—' }}
                                            </a>
                                        </td>
                                        <td class="db-ellip">{{ $o['recipient_name'] ?? '—' }}</td>
                                        <td class="db-ellip db-muted">{{ ($o['shipping_province'] ?? '') ?: '—' }}</td>
                                        <td class="num">{{ $money($o['total_amount'] ?? 0) }}</td>
                                        <td>
                                            <span class="db-paytag {{ $ps === 'paid' ? 'is-paid' : '' }}">
                                                {{ $ps === 'paid' ? 'Đã thu' : 'Chưa thu' }}
                                            </span>
                                            <span class="db-muted">{{ $shortMethod($o['payment_method'] ?? '') }}</span>
                                        </td>
                                        <td><span class="db-badge tone-{{ $ORDER_TONES[$st] ?? 'info' }}">{{ $ORDER_STATUSES[$st] ?? $st }}</span></td>
                                        <td class="num db-muted">
                                            {{ !empty($o['created_at']) ? \Illuminate\Support\Carbon::parse($o['created_at'])->format('d/m H:i') : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Bán chạy nhất</h3>
                        <p class="db-card-sub">Theo tổng lượt bán</p>
                    </div>
                    <a class="db-linkbtn" href="{{ route('admin.products.index') }}">Sản phẩm →</a>
                </div>

                @if(empty($topProducts))
                    <p class="db-empty">Chưa có dữ liệu bán hàng.</p>
                @else
                    @php $topMax = max(1, max(array_map(fn ($p) => (int) ($p['sold_count'] ?? 0), $topProducts))); @endphp
                    <ol class="db-top">
                        @foreach($topProducts as $i => $p)
                            <li class="db-top-item">
                                <span class="db-top-rank {{ $i === 0 ? 'is-first' : '' }}">{{ $i + 1 }}</span>
                                <span class="db-top-main">
                                    <span class="db-top-name" title="{{ $p['name'] ?? '' }}">{{ $p['name'] ?? '—' }}</span>
                                    <span class="db-bar-track db-bar-track--sm">
                                        <span class="db-bar-fill" style="width: {{ max(2, round(((int) ($p['sold_count'] ?? 0)) / $topMax * 100)) }}%"></span>
                                    </span>
                                </span>
                                <span class="db-top-value">{{ $int($p['sold_count'] ?? 0) }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        {{-- ===== Khách hàng + tồn kho ===== --}}
        <div class="db-grid db-grid--1-2">
            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Khách chi tiêu nhiều nhất</h3>
                        <p class="db-card-sub">
                            {{ $int($customerStats['total'] ?? 0) }} khách ·
                            {{ $int($customerStats['active'] ?? 0) }} đang hoạt động
                        </p>
                    </div>
                    <a class="db-linkbtn" href="{{ route('admin.customers.index') }}">Khách hàng →</a>
                </div>

                @if(empty($topCustomers))
                    <p class="db-empty">Chưa có dữ liệu khách hàng.</p>
                @else
                    <ul class="db-people">
                        @foreach($topCustomers as $cus)
                            <li class="db-person">
                                <span class="db-avatar">{{ mb_substr(trim($cus['full_name'] ?? '?'), 0, 1) }}</span>
                                <span class="db-person-main">
                                    <span class="db-person-name db-ellip">{{ $cus['full_name'] ?? '—' }}</span>
                                    <span class="db-person-sub">{{ $int($cus['total_orders'] ?? 0) }} đơn · {{ ($cus['phone'] ?? '') ?: (($cus['email'] ?? '') ?: '—') }}</span>
                                </span>
                                <span class="db-person-value">{{ $money($cus['total_spent'] ?? 0) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="db-card">
                <div class="db-card-head">
                    <div>
                        <h3 class="db-card-title">Sắp hết hàng</h3>
                        {{-- Thẻ này gọi đúng endpoint tồn kho nên nó đã ăn theo chi nhánh
                             đang làm việc. Phải NÓI RA: cùng một mặt hàng có thể sắp hết ở
                             kho này mà đầy ở kho kia, và người đọc cần biết mình đang nhìn
                             kho nào trước khi quyết định nhập thêm. --}}
                        @php($khoDangXem = \App\Services\ChiNhanhDangLam::ten())
                        <p class="db-card-sub">
                            Biến thể đang bán còn tối đa {{ $LOW_STOCK }} sản phẩm
                            @if(count(\App\Services\ChiNhanhDangLam::danhSach()['ds']) > 1)
                                · <b>{{ $khoDangXem === null ? 'gộp mọi chi nhánh' : 'kho '.$khoDangXem }}</b>
                            @endif
                        </p>
                    </div>
                    <a class="db-linkbtn" href="{{ route('admin.inventory.index', ['is_active' => 1]) }}">Quản lý kho →</a>
                </div>

                @if(empty($lowStock))
                    <p class="db-empty">Không có biến thể nào sắp hết hàng.</p>
                @else
                    <div class="db-table-wrap">
                        <table class="db-table">
                            <thead>
                                <tr><th>Sản phẩm</th><th>Phiên bản</th><th>SKU</th><th class="num">Còn lại</th></tr>
                            </thead>
                            <tbody>
                                @foreach($lowStock as $row)
                                    <tr>
                                        <td class="db-ellip">{{ $row['product'] }}</td>
                                        <td class="db-muted">{{ $row['variant'] !== '' ? $row['variant'] : '—' }}</td>
                                        <td class="db-muted">{{ $row['sku'] !== '' ? $row['sku'] : '—' }}</td>
                                        <td class="num">
                                            <span class="db-badge {{ $row['stock'] === 0 ? 'tone-stop' : 'tone-wait' }}">
                                                {{ $row['stock'] === 0 ? 'Hết hàng' : $row['stock'] . ' cái' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>

    <style>
        .db {
            /* Bù padding p-4 của <main> để nền trải hết bề ngang màn hình. */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            padding: 20px 24px 32px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626;
            background: #f5f6fa;
            display: flex; flex-direction: column; gap: 16px;
        }

        /* Đầu trang */
        .db-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .db-head-main { min-width: 0; }
        .db-head-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .db-title { margin: 0; font-size: 21px; font-weight: 700; letter-spacing: -.01em; }
        .db-sub { margin: 4px 0 0; font-size: 13px; color: #8c8c8c; }
        .db-offline { display: inline-flex; align-items: center; gap: 5px; margin-left: 8px; color: #cf1322; font-weight: 500; }
        .db-offline svg { flex-shrink: 0; }

        .db-range { display: inline-flex; border: 1px solid #e6e6e6; border-radius: 6px; background: #fff; overflow: hidden; }
        .db-range-btn {
            padding: 7px 14px; font-size: 13px; color: #595959; text-decoration: none;
            border-right: 1px solid #f0f0f0; transition: background .15s, color .15s;
        }
        .db-range-btn:last-child { border-right: 0; }
        .db-range-btn:hover { background: #f5f7fa; color: #1890ff; }
        .db-range-btn.is-active { background: #1890ff; color: #fff; }

        .db-ghostbtn {
            display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px;
            border: 1px solid #e6e6e6; border-radius: 6px; background: #fff;
            font-size: 13px; color: #595959; text-decoration: none; transition: border-color .15s, color .15s;
        }
        .db-ghostbtn:hover { border-color: #1890ff; color: #1890ff; }

        .db-notice {
            display: flex; align-items: center; gap: 7px; margin: 0; padding: 9px 13px;
            background: #fffbe6; border: 1px solid #ffe58f; border-radius: 8px; font-size: 12.5px; color: #874d00;
        }
        .db-notice svg { flex-shrink: 0; }

        /* Thẻ chỉ số — mỗi thẻ một sắc, đặt qua biến để phần thân dùng chung.
           --kpi: màu nhận diện (dải trái + icon) · --kpi-bg: nền chuyển sắc rất nhạt. */
        .db-kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; }
        .db-kpi {
            --kpi: #1890ff; --kpi-bg: #eaf3ff; --kpi-soft: #f0f7ff;
            position: relative; overflow: hidden;
            display: flex; flex-direction: column; gap: 7px; padding: 15px 16px 14px 19px; min-width: 0;
            border: 1px solid #f0f0f0; border-radius: 10px; text-decoration: none; color: inherit;
            background: linear-gradient(118deg, var(--kpi-bg) 0%, #fff 66%);
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        /* Dải màu bám mép trái, cao hết thẻ */
        .db-kpi::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--kpi); }
        .db-kpi--blue   { --kpi: #1890ff; --kpi-bg: #ddeeff; --kpi-soft: #f0f7ff; }
        .db-kpi--green  { --kpi: #52c41a; --kpi-bg: #e3f8d6; --kpi-soft: #f4fded; }
        .db-kpi--violet { --kpi: #722ed1; --kpi-bg: #ede1ff; --kpi-soft: #f7f1ff; }
        .db-kpi--amber  { --kpi: #fa8c16; --kpi-bg: #ffedd2; --kpi-soft: #fff7e9; }
        .db-kpi--red    { --kpi: #cf1322; --kpi-bg: #ffe2de; --kpi-soft: #fff2f0; }
        .db-kpi--teal   { --kpi: #13c2c2; --kpi-bg: #d6f7f5; --kpi-soft: #edfbfb; }
        /* Còn việc tồn: cả thẻ chuyển sang sắc cảnh báo, không để dải màu một đằng
           con số một nẻo. */
        .db-kpi--teal.is-alert { --kpi: #d4380d; --kpi-bg: #ffe7d9; --kpi-soft: #fff2e8; }

        .db-kpi-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .db-kpi-label { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c; }
        .db-kpi-icon {
            flex-shrink: 0; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; background: var(--kpi-soft); color: var(--kpi); border: 1px solid #fff;
        }
        .db-kpi-main { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0; }
        /* Số lớn dùng chữ số tỉ lệ (không tabular-nums): ở cỡ này chữ số đều bề
           ngang làm con số trông rời rạc. Con số giữ màu chữ, KHÔNG mượn màu thẻ —
           màu thẻ chỉ để tách khối. */
        .db-kpi-value { font-size: 23px; font-weight: 700; line-height: 1.15; color: #262626; letter-spacing: -.02em; }
        .db-kpi-value.is-warn { color: #d46b08; }
        .db-kpi-foot { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; min-height: 18px; }
        .db-kpi-note { font-size: 12px; color: #8c8c8c; }
        .db-delta { display: inline-flex; align-items: center; gap: 3px; font-size: 12.5px; font-weight: 600; }
        .db-delta.is-up { color: #389e0d; }
        .db-delta.is-down { color: #cf1322; }
        .db-delta.is-new { color: #8c8c8c; font-weight: 400; }
        .db-spark { width: 74px; height: 28px; flex-shrink: 0; opacity: .9; }
        .db-spark-area { fill: var(--kpi); opacity: .14; }
        .db-spark-line { fill: none; stroke: var(--kpi); stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; }

        /* Hai dòng số phụ dưới mỗi thẻ */
        .db-kpi-rows {
            list-style: none; margin: 11px 0 0; padding: 10px 0 0; border-top: 1px solid rgba(0, 0, 0, .06);
            display: flex; flex-direction: column; gap: 6px;
        }
        .db-kpi-rows li { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; font-size: 12.5px; }
        .db-kpi-rows span, .db-kpi-rows a { color: #8c8c8c; text-decoration: none; min-width: 0; }
        .db-kpi-rows a:hover { color: var(--kpi); text-decoration: underline; }
        .db-kpi-rows b { font-weight: 600; color: #262626; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .db-kpi.is-alert .db-kpi-value { color: #d4380d; }

        /* Lưới thẻ — các thẻ CÙNG MỘT HÀNG luôn bằng chiều cao nhau (stretch), phần
           thân thẻ giãn ra cho hết chỗ thay vì dồn lên trên để hở một mảng trắng. */
        .db-grid { display: grid; gap: 16px; align-items: stretch; }
        .db-grid--2-1 { grid-template-columns: 2fr 1fr; }
        .db-grid--1-2 { grid-template-columns: 1fr 2fr; }
        .db-grid--3 { grid-template-columns: repeat(3, 1fr); }
        .db-card {
            display: flex; flex-direction: column;
            background: #fff; border: 1px solid #f0f0f0; border-radius: 10px; padding: 16px 18px; min-width: 0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        .db-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .db-card-tools { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .db-card-title { margin: 0; font-size: 14.5px; font-weight: 700; }
        .db-card-sub { margin: 3px 0 0; font-size: 12px; color: #8c8c8c; }
        .db-linkbtn {
            border: 0; background: none; padding: 0; font-size: 12.5px; font-weight: 600; color: #1890ff;
            cursor: pointer; text-decoration: none; white-space: nowrap;
        }
        .db-linkbtn:hover { text-decoration: underline; }
        /* Ô "chưa có dữ liệu" chiếm hết phần thân còn lại và nằm giữa thẻ, để thẻ
           trống trông có chủ đích chứ không như bị cắt cụt. */
        .db-empty {
            flex: 1; margin: 0; padding: 26px 0; min-height: 90px;
            display: flex; align-items: center; justify-content: center;
            text-align: center; font-size: 13px; color: #bfbfbf;
        }
        .db-foot-note { margin: 10px 0 0; font-size: 11.5px; color: #bfbfbf; }

        /* Tab chỉ số của biểu đồ chính */
        .db-tabs { display: inline-flex; padding: 2px; background: #f5f6fa; border-radius: 7px; }
        .db-tab {
            border: 0; background: none; padding: 5px 12px; border-radius: 5px;
            font-size: 12.5px; font-weight: 600; color: #8c8c8c; cursor: pointer; transition: background .15s, color .15s;
        }
        .db-tab:hover { color: #262626; }
        .db-tab.is-active { background: #fff; color: #1890ff; box-shadow: 0 1px 2px rgba(0, 0, 0, .08); }

        /* Biểu đồ đường */
        .db-plot { position: relative; }
        .db-plot[hidden] { display: none; }
        /* KHÔNG đặt max-height cho SVG có viewBox: chạm trần thì trình duyệt thu nhỏ
           cả khung vẽ rồi canh giữa, biểu đồ co lại và hở hai bên thẻ. Chiều cao cứ
           để chạy theo tỷ lệ viewBox thì hình luôn căng hết bề ngang thẻ. */
        .db-svg { display: block; width: 100%; height: auto; }
        .db-gridline { stroke: #f0f0f0; stroke-width: 1; }
        .db-avgline { stroke: #bfbfbf; stroke-width: 1; stroke-dasharray: 4 4; vector-effect: non-scaling-stroke; }
        .db-line { fill: none; stroke: #1890ff; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; }
        .db-dot-end { fill: #1890ff; stroke: #fff; stroke-width: 2; }
        .db-cross { stroke: #bfbfbf; stroke-width: 1; vector-effect: non-scaling-stroke; }
        .db-dot-hover { fill: #1890ff; stroke: #fff; stroke-width: 2; }
        /* Chữ trong biểu đồ dùng màu chữ, không mượn màu của đường dữ liệu. */
        .db-tick { fill: #8c8c8c; font-size: 11px; font-family: inherit; font-variant-numeric: tabular-nums; }
        .db-tick--y { text-anchor: end; }
        .db-tick--avg { fill: #bfbfbf; font-size: 10px; }
        .db-hit { cursor: crosshair; }

        .db-tip {
            position: absolute; z-index: 5; min-width: 148px; padding: 8px 10px; pointer-events: none;
            background: #262626; color: #fff; border-radius: 6px; font-size: 12px; line-height: 1.55;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .18); transform: translate(-50%, -100%);
        }
        .db-tip[hidden] { display: none; }
        .db-tip b { display: block; font-size: 13px; margin-bottom: 2px; }
        .db-tip span { color: #d9d9d9; }

        /* Cột ngang dùng chung (trạng thái, khu vực, bán chạy) */
        .db-bars { flex: 1; list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; justify-content: center; gap: 11px; }
        .db-bar-row { display: grid; grid-template-columns: 124px 1fr 42px; align-items: center; gap: 10px; }
        .db-bar-row--wide { grid-template-columns: minmax(0, 150px) 1fr 34px 56px; }
        .db-bars-split { height: 1px; background: #f0f0f0; margin: 2px 0; }
        .db-bar-label { display: flex; align-items: center; gap: 7px; font-size: 13px; color: #595959; text-decoration: none; min-width: 0; }
        a.db-bar-label:hover { color: #1890ff; }
        .db-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; background: #bfbfbf; }
        .db-dot.tone-wait { background: #fa8c16; }
        .db-dot.tone-info { background: #1890ff; }
        .db-dot.tone-move { background: #722ed1; }
        .db-dot.tone-done { background: #52c41a; }
        .db-dot.tone-stop { background: #cf1322; }
        .db-bar-track { height: 8px; border-radius: 9999px; background: #f0f2f5; overflow: hidden; }
        .db-bar-track--sm { height: 6px; margin-top: 5px; }
        .db-bar-fill { display: block; height: 100%; border-radius: 9999px; background: #1890ff; }
        .db-bar-fill.is-dead { background: #d9d9d9; }
        .db-bar-value { font-size: 13px; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }
        .db-bar-extra { font-size: 12px; color: #8c8c8c; text-align: right; font-variant-numeric: tabular-nums; }

        .db-mini { display: flex; gap: 12px; margin-top: auto; padding-top: 14px; border-top: 1px solid #f0f0f0; }
        .db-mini-item { flex: 1; display: flex; flex-direction: column; gap: 3px; min-width: 0; }
        .db-mini-label { font-size: 11.5px; color: #8c8c8c; }
        .db-mini-value { font-size: 15px; font-weight: 700; color: #262626; text-decoration: none; }
        a.db-mini-value:hover { color: #1890ff; }

        /* Vòng tròn cơ cấu thanh toán */
        .db-donut-wrap { flex: 1; display: flex; align-items: center; justify-content: center; gap: 20px; flex-wrap: wrap; }
        .db-donut { width: 140px; height: 140px; flex-shrink: 0; }
        .db-donut-num { font-size: 22px; font-weight: 700; fill: #262626; font-family: inherit; }
        .db-donut-cap { font-size: 11px; fill: #8c8c8c; font-family: inherit; }
        .db-legend { list-style: none; margin: 0; padding: 0; flex: 1; min-width: 170px; display: flex; flex-direction: column; gap: 9px; }
        .db-legend-item { display: grid; grid-template-columns: 10px 1fr auto auto; align-items: center; gap: 8px; font-size: 13px; }
        .db-legend-swatch { width: 10px; height: 10px; border-radius: 3px; }
        .db-legend-name { color: #595959; text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .db-legend-name:hover { color: #1890ff; }
        .db-legend-val { font-weight: 600; font-variant-numeric: tabular-nums; }
        .db-legend-pct { min-width: 46px; text-align: right; font-size: 12px; color: #8c8c8c; font-variant-numeric: tabular-nums; }

        /* Cột theo giờ */
        .db-hours { display: block; width: 100%; height: auto; margin-block: auto; }
        .db-hbar { fill: #69b1ff; }
        .db-hbar.is-peak { fill: #1890ff; }

        /* Bảng */
        .db-table-wrap { flex: 1; width: 100%; overflow-x: auto; scrollbar-width: thin; }
        .db-table-wrap--tall { max-height: 320px; overflow-y: auto; margin-top: 12px; }
        .db-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .db-table th, .db-table td { padding: 9px 10px; text-align: left; border-bottom: 1px solid #f5f5f5; white-space: nowrap; }
        .db-table th {
            position: sticky; top: 0; background: #fff; z-index: 1;
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
            color: #8c8c8c; border-bottom-color: #f0f0f0;
        }
        .db-table tbody tr:hover td { background: #fafcff; }
        .db-table tbody tr:last-child td { border-bottom: 0; }
        .db-table .num { text-align: right; font-variant-numeric: tabular-nums; }
        .db-table th.num { text-align: right; }
        .db-ellip { max-width: 210px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .db-muted { color: #8c8c8c; }
        .db-code { font-weight: 600; color: #262626; text-decoration: none; }
        .db-code:hover { color: #1890ff; text-decoration: underline; }

        .db-badge {
            display: inline-block; padding: 2px 9px; border-radius: 9999px; font-size: 11.5px; font-weight: 500;
            border: 1px solid #d9d9d9; color: #595959; background: #fafafa;
        }
        .db-badge.tone-wait { border-color: #ffd591; color: #d46b08; background: #fff7e6; }
        .db-badge.tone-info { border-color: #91d5ff; color: #096dd9; background: #e6f7ff; }
        .db-badge.tone-move { border-color: #b37feb; color: #531dab; background: #f9f0ff; }
        .db-badge.tone-done { border-color: #b7eb8f; color: #389e0d; background: #f6ffed; }
        .db-badge.tone-stop { border-color: #ffa39e; color: #cf1322; background: #fff1f0; }

        .db-paytag { font-size: 12px; font-weight: 600; color: #d46b08; margin-right: 5px; }
        .db-paytag.is-paid { color: #389e0d; }

        /* Bán chạy */
        .db-top { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 13px; }
        .db-top-item { display: grid; grid-template-columns: 22px 1fr 44px; align-items: center; gap: 10px; }
        .db-top-rank {
            width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 6px; background: #f0f2f5; font-size: 11.5px; font-weight: 700; color: #8c8c8c;
        }
        .db-top-rank.is-first { background: #e6f7ff; color: #096dd9; }
        .db-top-main { min-width: 0; }
        .db-top-name { display: block; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .db-top-value { font-size: 13px; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }

        /* Danh sách khách hàng */
        .db-people { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
        .db-person { display: grid; grid-template-columns: 34px 1fr auto; align-items: center; gap: 11px; }
        .db-avatar {
            width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 50%; background: #f0f7ff; color: #096dd9; font-size: 14px; font-weight: 700; text-transform: uppercase;
        }
        .db-person-main { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .db-person-name { font-size: 13px; font-weight: 500; }
        .db-person-sub { font-size: 11.5px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .db-person-value { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }

        @media (max-width: 1600px) {
            .db-kpis { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1200px) {
            .db-grid--2-1, .db-grid--1-2, .db-grid--3 { grid-template-columns: 1fr; }
            .db-kpis { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            .db { padding: 16px 14px 24px; }
            .db-kpis { grid-template-columns: 1fr; }
            .db-bar-row { grid-template-columns: 100px 1fr 40px; }
            .db-bar-row--wide { grid-template-columns: minmax(0, 110px) 1fr 30px 48px; }
        }
    </style>

    <script>
        (function () {
            // ---- Tab chỉ số: hai biểu đồ dựng sẵn, chỉ đổi cái đang hiện ----
            var tabs = document.querySelectorAll('.db-tab');
            var plots = document.querySelectorAll('.db-plot');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    tabs.forEach(function (t) {
                        var on = t === tab;
                        t.classList.toggle('is-active', on);
                        t.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    plots.forEach(function (p) { p.hidden = p.dataset.metric !== tab.dataset.tab; });
                });
            });

            // ---- Bảng số liệu thay cho biểu đồ (không khoá giá trị sau tooltip) ----
            var toggle = document.getElementById('dbTableToggle');
            var table = document.getElementById('dbTable');
            if (toggle && table) {
                toggle.addEventListener('click', function () {
                    var open = table.hidden;
                    table.hidden = !open;
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    toggle.textContent = open ? 'Ẩn bảng' : 'Xem dạng bảng';
                });
            }

            // ---- Rê chuột trên biểu đồ: đường dóng + chấm + tooltip ----
            var money = function (v) { return (Number(v) || 0).toLocaleString('vi-VN') + '₫'; };

            plots.forEach(function (box) {
                var pts, geo;
                try {
                    pts = JSON.parse(box.dataset.points);
                    geo = JSON.parse(box.dataset.geo);
                } catch (e) { return; }
                if (!pts.length) return;

                var metric = box.dataset.metric;
                var svg = box.querySelector('.db-svg');
                var cross = box.querySelector('.db-cross');
                var dot = box.querySelector('.db-dot-hover');
                var tip = box.querySelector('.db-tip');
                var hit = box.querySelector('.db-hit');
                var plotW = geo.w - geo.padL - geo.padR;

                function show(e) {
                    var r = svg.getBoundingClientRect();
                    var vx = ((e.clientX - r.left) / r.width) * geo.w;   // toạ độ trong viewBox
                    var i = Math.round(((vx - geo.padL) / plotW) * (pts.length - 1));
                    if (i < 0) i = 0;
                    if (i > pts.length - 1) i = pts.length - 1;

                    var p = pts[i];
                    var val = metric === 'revenue' ? p.r : p.o;
                    var x = pts.length > 1 ? geo.padL + plotW * i / (pts.length - 1) : geo.padL + plotW / 2;
                    // Dùng ĐÚNG trần trục Y mà PHP đã làm tròn đẹp để vẽ, nếu tự tính
                    // lại từ giá trị lớn nhất thì chấm sẽ lệch khỏi đường.
                    var y = geo.baseY - (val / (geo.top || 1)) * (geo.baseY - geo.padT);

                    cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.hidden = false;
                    dot.setAttribute('cx', x); dot.setAttribute('cy', y); dot.hidden = false;

                    var d = new Date(p.d);
                    tip.innerHTML = '<b>' + (isNaN(d) ? p.d : d.toLocaleDateString('vi-VN')) + '</b>'
                        + money(p.r) + '<br><span>' + p.o + ' đơn</span>';
                    tip.style.left = ((x / geo.w) * 100) + '%';
                    tip.style.top = ((y / geo.h) * 100) + '%';
                    tip.style.marginTop = '-12px';
                    tip.hidden = false;
                }

                function hide() { cross.hidden = true; dot.hidden = true; tip.hidden = true; }

                hit.addEventListener('mousemove', show);
                hit.addEventListener('mouseleave', hide);
                box.addEventListener('touchmove', function (e) {
                    if (e.touches && e.touches[0]) show(e.touches[0]);
                }, { passive: true });
            });
        })();
    </script>
@endsection
