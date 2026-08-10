{{--
    Thẻ "cột ngang" — dùng cho mọi bảng xếp hạng nhỏ trong nhóm Báo cáo (trạng
    thái đơn, tỉnh thành, danh mục, thương hiệu, size, kênh bán, hình thức giao).

    Con số LUÔN in ra bên cạnh cột, không khoá sau tooltip: cột chỉ để so độ dài
    bằng mắt, còn giá trị thật thì phải đọc được ngay.

    Tham số:
      - title : tiêu đề thẻ (bắt buộc)
      - sub   : (tuỳ chọn) câu phụ dưới tiêu đề
      - rows  : mảng các dòng, mỗi dòng:
                  label  : nhãn (bắt buộc)
                  value  : cột số chính, ĐÃ định dạng (bắt buộc)
                  extra  : (tuỳ chọn) cột số phụ bên phải, ĐÃ định dạng
                  ratio  : độ dài cột, 0–100 (bắt buộc)
                  tone   : (tuỳ chọn) wait|info|move|done|stop — hiện chấm màu trước nhãn
                  color  : (tuỳ chọn) mã màu riêng cho cột
                  dead   : (tuỳ chọn) true = tô xám (nhóm huỷ/hoàn)
                  split  : (tuỳ chọn) true = kẻ một đường ngăn TRƯỚC dòng này
      - empty : câu hiện khi không có dòng nào
--}}
@php
    $barRows = $rows ?? [];
    $hasExtra = collect($barRows)->contains(fn ($r) => isset($r['extra']));
@endphp

<section class="rp-card">
    <div class="rp-card-head">
        <div>
            <h2 class="rp-card-title">{{ $title }}</h2>
            @if(!empty($sub))
                <p class="rp-card-sub">{{ $sub }}</p>
            @endif
        </div>
        @if(!empty($tools))
            <div class="rp-card-tools">{!! $tools !!}</div>
        @endif
    </div>

    @if($barRows)
        <ul class="rp-bars">
            @foreach($barRows as $row)
                @if(!empty($row['split']))
                    <li class="rp-bars-split" aria-hidden="true"></li>
                @endif
                <li class="rp-bar-row {{ $hasExtra ? '' : 'rp-bar-row--slim' }}">
                    <span class="rp-bar-label" title="{{ $row['label'] }}">
                        @if(!empty($row['tone']))
                            <i class="rp-dot tone-{{ $row['tone'] }}"></i>
                        @endif
                        <span class="rp-bar-name">{{ $row['label'] }}</span>
                    </span>
                    <span class="rp-bar-track">
                        <span class="rp-bar-fill {{ !empty($row['dead']) ? 'is-dead' : '' }}"
                              style="width: {{ max(0, min(100, round($row['ratio'], 2))) }}%{{ !empty($row['color']) ? '; background: '.$row['color'] : '' }}"></span>
                    </span>
                    <span class="rp-bar-value">{{ $row['value'] }}</span>
                    @if($hasExtra)
                        <span class="rp-bar-extra">{{ $row['extra'] ?? '' }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="rp-empty">{{ $empty ?? 'Kỳ này chưa có số liệu.' }}</p>
    @endif
</section>
