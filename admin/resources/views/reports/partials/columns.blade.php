{{--
    Cột dọc cho chuỗi NHIỀU MỐC NGẮN NHÃN (24 khung giờ).

    Dùng cột dọc thay vì cột ngang vì trục ở đây là thời gian trong ngày: đọc từ
    trái sang phải mới ra được hình dạng "sáng vắng, tối đông". Cột cao nhất được
    tô đậm hơn và gọi tên ở phụ đề — đó là con số người xem đi tìm.

    Tham số:
      - title / sub : tiêu đề thẻ
      - rows        : mảng ['label' => nhãn dưới cột, 'value' => số, 'title' => tooltip]
      - empty       : câu hiện khi tất cả bằng 0
      - every       : in nhãn mỗi N cột (mặc định 2) để chữ không chồng nhau
--}}
@php
    use App\Support\Chart;

    $colRows = array_values($rows ?? []);
    $n = count($colRows);
    $max = $n ? max(array_map(fn ($r) => (float) $r['value'], $colRows)) : 0;
    $every = $every ?? 2;

    $W = 720; $H = 190; $PAD_B = 26; $PAD_T = 8;
    $plotH = $H - $PAD_B - $PAD_T;
    $slot = $n > 0 ? $W / $n : $W;
    $barW = max(4, $slot * 0.58);
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

    @if($n > 0 && $max > 0)
        <svg class="rp-cols" viewBox="0 0 {{ $W }} {{ $H }}" preserveAspectRatio="none" role="img" aria-label="{{ $title }}">
            @foreach($colRows as $i => $row)
                @php
                    $value = (float) $row['value'];
                    $h = $value > 0 ? max(2, $plotH * $value / $max) : 0;
                    $x = $slot * $i + ($slot - $barW) / 2;
                @endphp
                @if($h > 0)
                    <rect class="rp-col {{ $value >= $max ? 'is-peak' : '' }}"
                          x="{{ round($x, 2) }}" y="{{ round($PAD_T + $plotH - $h, 2) }}"
                          width="{{ round($barW, 2) }}" height="{{ round($h, 2) }}" rx="2">
                        <title>{{ $row['title'] ?? ($row['label'].': '.Chart::int($value)) }}</title>
                    </rect>
                @endif
                @if($i % $every === 0)
                    <text class="rp-tick" x="{{ round($slot * $i + $slot / 2, 2) }}" y="{{ $H - 8 }}" text-anchor="middle">{{ $row['label'] }}</text>
                @endif
            @endforeach
        </svg>
    @else
        <p class="rp-empty">{{ $empty ?? 'Kỳ này chưa có số liệu.' }}</p>
    @endif
</section>
