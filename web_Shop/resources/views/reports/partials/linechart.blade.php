{{--
    Biểu đồ đường theo mốc thời gian, có tab đổi chỉ số.

    Mọi đường được dựng SẴN ở PHP; bấm tab chỉ đổi cái đang hiện, không vẽ lại —
    nhờ vậy trang không cần thư viện biểu đồ nào và vẫn đọc được khi tắt JS.

    Kèm một BẢNG SỐ bật/tắt ngay dưới biểu đồ: giá trị từng mốc phải đọc được mà
    không phải rê chuột, và phải sao chép được ra ngoài.

    Tham số:
      - id      : id duy nhất trong trang (bắt buộc)
      - title   : tiêu đề thẻ (bắt buộc)
      - sub     : (tuỳ chọn) câu phụ
      - groupBy : day|week|month — để dịch nhãn mốc
      - buckets : mảng mốc thô từ API (chỉ cần khoá `label`)
      - series  : mảng có thứ tự, mỗi phần tử:
                    key    : mã chỉ số
                    label  : nhãn trên tab
                    values : mảng giá trị cùng độ dài với $buckets
                    money  : true = trục và tooltip in ra tiền; false = đếm bằng cái
--}}
@php
    use App\Support\Chart;
    use App\Http\Controllers\ReportController;

    $BOX = ['w' => 1000, 'h' => 260, 'padL' => 66, 'padR' => 22, 'padT' => 16, 'padB' => 34];
    $n = count($buckets);
    $labels = array_map(fn ($b) => ReportController::bucketLabel($b['label'] ?? '', $groupBy, true), $buckets);
    $labelsFull = array_map(fn ($b) => ReportController::bucketLabel($b['label'] ?? '', $groupBy), $buckets);
    $labelAt = array_flip(Chart::labelIndices($n));

    $charts = [];
    foreach ($series as $s) {
        $values = array_map('floatval', $s['values']);
        // Trục đếm bằng cái ép trần chia hết cho 4 để 5 vạch lưới ra số nguyên;
        // trục tiền thì làm tròn lên số đẹp (1/2/2,5/5 × 10^k).
        $geo = Chart::line($values, $BOX, $s['money'] ? null : Chart::countTop($values));
        $charts[$s['key']] = $geo + ['series' => $s, 'values' => $values];
    }
    $first = $series[0]['key'];

    // Dữ liệu cho tooltip: mỗi mốc một dòng, kèm giá trị của TẤT CẢ chỉ số —
    // rê chuột một lần đọc được cả doanh thu lẫn số đơn của ngày đó.
    $points = [];
    foreach ($buckets as $i => $b) {
        $row = ['d' => $labelsFull[$i], 'v' => []];
        foreach ($series as $s) {
            $row['v'][$s['key']] = (float) ($s['values'][$i] ?? 0);
        }
        $points[] = $row;
    }
    $meta = collect($series)->mapWithKeys(fn ($s) => [$s['key'] => ['label' => $s['label'], 'money' => (bool) $s['money']]])->all();
@endphp

<section class="rp-card">
    <div class="rp-card-head">
        <div>
            <h2 class="rp-card-title">{{ $title }}</h2>
            @if(!empty($sub))
                <p class="rp-card-sub">{{ $sub }}</p>
            @endif
        </div>
        <div class="rp-card-tools">
            @if(count($series) > 1)
                <div class="rp-tabsmini" role="tablist" data-chart-tabs="{{ $id }}">
                    @foreach($series as $s)
                        <button type="button" role="tab" class="rp-tabmini {{ $s['key'] === $first ? 'is-active' : '' }}"
                                data-tab="{{ $s['key'] }}" aria-selected="{{ $s['key'] === $first ? 'true' : 'false' }}">{{ $s['label'] }}</button>
                    @endforeach
                </div>
            @endif
            <button type="button" class="rp-linkbtn" data-table-toggle="{{ $id }}" aria-expanded="false">Xem dạng bảng</button>
        </div>
    </div>

    @if($n === 0)
        <p class="rp-empty">Kỳ này chưa có mốc nào để vẽ.</p>
    @else
        @foreach($charts as $key => $c)
            @php $isMoney = $c['series']['money']; @endphp
            <div class="rp-plot" data-chart="{{ $id }}" data-metric="{{ $key }}"
                 data-points="{{ json_encode($points, JSON_UNESCAPED_UNICODE) }}"
                 data-meta="{{ json_encode($meta, JSON_UNESCAPED_UNICODE) }}"
                 data-geo="{{ json_encode(['w' => $BOX['w'], 'h' => $BOX['h'], 'padL' => $BOX['padL'], 'padR' => $BOX['padR'], 'padT' => $BOX['padT'], 'baseY' => $c['baseY'], 'top' => $c['top']]) }}"
                 @if($key !== $first) hidden @endif>
                <svg class="rp-svg" viewBox="0 0 {{ $BOX['w'] }} {{ $BOX['h'] }}" preserveAspectRatio="none" role="img"
                     aria-label="{{ $c['series']['label'] }} theo từng mốc trong kỳ">
                    {{-- 5 vạch lưới + nhãn trục Y --}}
                    @for($i = 0; $i <= 4; $i++)
                        @php
                            $y = $BOX['padT'] + ($c['plotH'] * $i / 4);
                            $value = $c['top'] * (1 - $i / 4);
                        @endphp
                        <line class="rp-gridline" x1="{{ $BOX['padL'] }}" y1="{{ round($y, 2) }}" x2="{{ $BOX['w'] - $BOX['padR'] }}" y2="{{ round($y, 2) }}"/>
                        <text class="rp-tick rp-tick--y" x="{{ $BOX['padL'] - 10 }}" y="{{ round($y + 4, 2) }}">{{ $isMoney ? Chart::shortMoney($value) : Chart::int($value) }}</text>
                    @endfor

                    <path class="rp-area" d="{{ $c['area'] }}"/>
                    <path class="rp-line" d="{{ $c['line'] }}"/>

                    {{-- Đường trung bình kỳ — cái mốc để biết một mốc là cao hay thấp --}}
                    <line class="rp-avgline" x1="{{ $BOX['padL'] }}" y1="{{ $c['avgY'] }}" x2="{{ $BOX['w'] - $BOX['padR'] }}" y2="{{ $c['avgY'] }}"/>
                    <text class="rp-tick rp-tick--avg" x="{{ $BOX['w'] - $BOX['padR'] }}" y="{{ $c['avgY'] - 5 }}" text-anchor="end">
                        TB {{ $isMoney ? Chart::shortMoney($c['avg']) : Chart::dec($c['avg']) }}
                    </text>

                    @if($c['coords'])
                        <circle class="rp-dot-end" cx="{{ end($c['coords'])[0] }}" cy="{{ end($c['coords'])[1] }}" r="3.5"/>
                    @endif

                    {{-- Nhãn trục X, thưa để chữ không chồng nhau --}}
                    @foreach($labels as $i => $text)
                        @if(isset($labelAt[$i]))
                            @php $x = $n > 1 ? $BOX['padL'] + $c['plotW'] * $i / ($n - 1) : $BOX['padL'] + $c['plotW'] / 2; @endphp
                            <text class="rp-tick" x="{{ round($x, 2) }}" y="{{ $BOX['h'] - 10 }}"
                                  text-anchor="{{ $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') }}">{{ $text }}</text>
                        @endif
                    @endforeach

                    <line class="rp-cross" y1="{{ $BOX['padT'] }}" y2="{{ $c['baseY'] }}" x1="0" x2="0" hidden/>
                    <circle class="rp-dot-hover" r="4" cx="0" cy="0" hidden/>
                    <rect class="rp-hit" x="{{ $BOX['padL'] }}" y="{{ $BOX['padT'] }}" width="{{ $c['plotW'] }}" height="{{ $c['plotH'] }}" fill="transparent"/>
                </svg>
                <div class="rp-tip" hidden></div>
            </div>
        @endforeach

        <div class="rp-table-wrap rp-table-wrap--tall" data-table="{{ $id }}" hidden>
            <table class="rp-table">
                <thead>
                    <tr>
                        <th>Mốc</th>
                        @foreach($series as $s)
                            <th class="num">{{ $s['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($buckets as $i => $b)
                        <tr>
                            <td>{{ $labelsFull[$i] }}</td>
                            @foreach($series as $s)
                                <td class="num">{{ $s['money'] ? Chart::money($s['values'][$i] ?? 0) : Chart::int($s['values'][$i] ?? 0) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
