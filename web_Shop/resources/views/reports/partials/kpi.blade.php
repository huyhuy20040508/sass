{{--
    Một thẻ chỉ số. Bốn báo cáo dùng chung khuôn này để mọi thẻ trong nhóm có
    cùng bố cục: nhãn + icon ở trên, con số lớn ở giữa, mức tăng/giảm ở dưới,
    và (tuỳ chọn) vài dòng số phụ tách bằng đường kẻ.

    Tham số:
      - label   : nhãn viết hoa nhỏ ở đầu thẻ (bắt buộc)
      - value   : con số lớn, ĐÃ định dạng sẵn (bắt buộc)
      - tone    : blue|green|violet|amber|red|teal|grey — chỉ để TÁCH thẻ cho dễ
                  quét mắt, không mã hoá dữ liệu; chọn theo NGHĨA của chỉ số và
                  giữ nguyên khi đổi kỳ xem
      - icon    : nội dung path của SVG 24×24 (bắt buộc, không dùng emoji)
      - delta   : (tuỳ chọn) % tăng/giảm so với kỳ trước; null = kỳ trước chưa có gì
      - deltaNew: câu thay thế khi $delta === null (VD "Kỳ trước chưa có doanh thu")
      - bad     : true = chỉ số này TĂNG là xấu (đơn huỷ, tiền chưa thu) — đảo màu
                  chứ không đảo mũi tên: mũi tên nói hướng, màu nói tốt/xấu
      - note    : (tuỳ chọn) câu chú thích thay cho mức tăng/giảm
      - rows    : (tuỳ chọn) mảng [['label' => ..., 'value' => ...], ...]
      - spark   : (tuỳ chọn) kết quả Chart::spark()
--}}
@php
    $kpiTone = $tone ?? 'blue';
    $kpiDelta = $delta ?? false;   // false = không truyền, null = kỳ trước rỗng
    $kpiBad = $bad ?? false;
    $kpiRows = $rows ?? [];
    $kpiSpark = $spark ?? null;
@endphp

<div class="rp-kpi rp-kpi--{{ $kpiTone }}">
    <div class="rp-kpi-top">
        <span class="rp-kpi-label">{{ $label }}</span>
        <span class="rp-kpi-icon">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg>
        </span>
    </div>

    <div class="rp-kpi-main">
        <span class="rp-kpi-value">{{ $value }}</span>
        @if(!empty($kpiSpark['line']))
            <svg class="rp-spark" viewBox="0 0 {{ $kpiSpark['w'] }} {{ $kpiSpark['h'] }}" preserveAspectRatio="none" aria-hidden="true">
                <path d="{{ $kpiSpark['area'] }}" class="rp-spark-area"/>
                <path d="{{ $kpiSpark['line'] }}" class="rp-spark-line"/>
            </svg>
        @endif
    </div>

    <div class="rp-kpi-foot">
        @if($kpiDelta === null)
            <span class="rp-delta is-new">{{ $deltaNew ?? 'Kỳ trước chưa có số để so' }}</span>
        @elseif($kpiDelta !== false)
            @php
                $up = $kpiDelta >= 0;
                $cls = $kpiBad ? ($up ? 'is-bad-up' : 'is-bad-down') : ($up ? 'is-up' : 'is-down');
            @endphp
            <span class="rp-delta {{ $cls }}">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="{{ $up ? 'm5 15 7-7 7 7' : 'm5 9 7 7 7-7' }}"/>
                </svg>
                {{ \App\Support\Chart::pct(abs($kpiDelta)) }}
            </span>
            <span class="rp-kpi-note">so với kỳ trước</span>
        @endif

        @if(!empty($note))
            <span class="rp-kpi-note">{{ $note }}</span>
        @endif
    </div>

    @if($kpiRows)
        <ul class="rp-kpi-rows">
            @foreach($kpiRows as $row)
                <li><span>{{ $row['label'] }}</span><b>{{ $row['value'] }}</b></li>
            @endforeach
        </ul>
    @endif
</div>
