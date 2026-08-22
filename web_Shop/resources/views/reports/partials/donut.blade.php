{{--
    Vòng tròn cơ cấu + chú giải CÓ SỐ.

    Đây là hình DUY NHẤT trong nhóm Báo cáo dùng nhiều màu, và vì thế nó luôn đi
    kèm chú giải có số: ở cỡ lát này màu không đủ tương phản để đọc một mình.
    Màu gắn cứng theo ĐỐI TƯỢNG (COD luôn xanh dương, VNPay luôn tím), không theo
    thứ hạng — đổi kỳ xem thì màu không nhảy chỗ.

    Tham số:
      - title / sub : tiêu đề thẻ
      - rows        : mảng, mỗi phần tử:
                        label   : nhãn (bắt buộc)
                        value   : số chính, ĐÃ định dạng (bắt buộc)
                        share   : % của lát, 0–100 (bắt buộc)
                        color   : mã màu (bắt buộc)
      - centerValue : con số lớn giữa vòng, ĐÃ định dạng
      - centerCap   : chú thích dưới con số giữa vòng
      - empty       : câu hiện khi không có lát nào
--}}
@php
    use App\Support\Chart;

    $R = 52;
    $SW = 22;
    $donutRows = Chart::donut($rows ?? [], $R);
@endphp

<section class="rp-card">
    <div class="rp-card-head">
        <div>
            <h2 class="rp-card-title">{{ $title }}</h2>
            @if(!empty($sub))
                <p class="rp-card-sub">{{ $sub }}</p>
            @endif
        </div>
    </div>

    @if($donutRows)
        <div class="rp-donut-wrap">
            <svg class="rp-donut" viewBox="0 0 140 140" role="img" aria-label="{{ $title }}">
                <g transform="rotate(-90 70 70)">
                    <circle cx="70" cy="70" r="{{ $R }}" fill="none" stroke="#f0f2f5" stroke-width="{{ $SW }}"/>
                    @foreach($donutRows as $row)
                        <circle cx="70" cy="70" r="{{ $R }}" fill="none"
                                stroke="{{ $row['color'] }}" stroke-width="{{ $SW }}"
                                stroke-dasharray="{{ $row['dash'] }} {{ $row['gap'] }}"
                                stroke-dashoffset="{{ $row['offset'] }}"/>
                    @endforeach
                </g>
                <text class="rp-donut-num" x="70" y="68" text-anchor="middle">{{ $centerValue }}</text>
                <text class="rp-donut-cap" x="70" y="85" text-anchor="middle">{{ $centerCap }}</text>
            </svg>

            <ul class="rp-legend">
                @foreach($donutRows as $row)
                    <li class="rp-legend-item">
                        <span class="rp-legend-swatch" style="background: {{ $row['color'] }}"></span>
                        <span class="rp-legend-name" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <span class="rp-legend-val">{{ $row['value'] }}</span>
                        <span class="rp-legend-pct">{{ Chart::pct($row['share']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <p class="rp-empty">{{ $empty ?? 'Kỳ này chưa có số liệu.' }}</p>
    @endif
</section>
