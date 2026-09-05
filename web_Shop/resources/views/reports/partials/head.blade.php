{{--
    Đầu trang DÙNG CHUNG cho cả bốn báo cáo: tiêu đề + thanh chuyển báo cáo +
    hàng bộ lọc.

    Tham số:
      - page     : mã trang đang mở (revenue|orders|products|customers)
      - filters  : mảng bộ lọc đã chuẩn hoá từ ReportController::filters()
      - subtitle : câu mô tả riêng của từng báo cáo
      - showGroup: có hiện nút chọn cách chia trục thời gian không (mặc định có)
      - extra    : (tuỳ chọn) HTML các ô lọc riêng của trang, chèn vào cuối hàng lọc
      - skip     : (tuỳ chọn) tên các tham số mà trang TỰ dựng ô lọc trong `extra`
                   — bỏ ô ẩn tương ứng đi, nếu không form gửi lên hai giá trị cùng tên

    Bộ lọc chạy REALTIME đúng quy ước chung của khu quản trị: đổi select hay bấm
    preset là submit ngay, KHÔNG có nút "Áp dụng". Khoảng ngày dùng
    partials.date-range như mọi trang danh sách khác, không tự dựng hai ô date.
--}}
@php
    $PAGES = \App\Http\Controllers\ReportController::PAGES;
    $QUICK_CODES = \App\Http\Controllers\ReportController::QUICK_CODES;
    $QUICK = \App\Support\Period::buttons($QUICK_CODES);
    $GROUPS = \App\Http\Controllers\ReportController::GROUPS;
    $showGroup = $showGroup ?? true;

    $currentRoute = $PAGES[$page]['route'];

    /** Giữ nguyên mọi bộ lọc khác khi đổi một tham số. */
    $link = fn (array $overrides = []) => route($currentRoute, array_merge([
        'shop_id' => $filters['shop_id'] ?? '',
        'from_date' => $filters['from_date'],
        'to_date' => $filters['to_date'],
        'group_by' => $filters['group_by'],
        'sort' => $filters['sort'],
        'limit' => $filters['limit'],
    ], $overrides));

    /**
     * Nút xem nhanh: KHÔNG kèm from/to, chỉ gửi mã preset — để mỗi lần mở lại
     * đường dẫn này là kỳ được tính lại theo ngày hôm đó. Nếu nướng cứng ngày vào
     * link thì nút "Hôm qua" mà lưu vào dấu trang sẽ mãi trỏ về một ngày cũ.
     */
    $quickLink = fn (string $code) => route($currentRoute, [
        'range' => $code,
        'sort' => $filters['sort'],
        'limit' => $filters['limit'],
        // Bấm một khoảng xem nhanh không được làm mất chi nhánh đang chọn.
        'shop_id' => $filters['shop_id'] ?? '',
    ]);

@endphp

{{-- Đầu trang CHỈ có tiêu đề và phụ đề — không có thanh chuyển sang báo cáo khác.
     Điều hướng giữa các trang là việc của sidebar (dropdown "Báo cáo" đã liệt kê
     đủ bốn trang); đặt thêm một lối đi thứ hai ở đây thì cùng một việc lại có hai
     chỗ bấm, và người dùng phải nhớ trang nào có thanh đó trang nào không. --}}
<header class="rp-head">
    <div class="rp-head-main">
        <h1 class="rp-title">{{ \App\Http\Controllers\ReportController::TITLE }} {{ mb_strtolower($PAGES[$page]['label']) }}</h1>
        <p class="rp-sub">{{ $subtitle }}</p>
    </div>
</header>

<form id="rpFilter" method="GET" action="{{ route($currentRoute) }}" class="rp-filters">
    {{-- Hai ô ẩn giữ các tham số không nằm trong hàng lọc của trang này, để đổi
         khoảng ngày không làm mất kiểu xếp hạng / số dòng đang chọn. --}}
    @php $skipFields = $skip ?? []; @endphp
    @unless(in_array('sort', $skipFields, true))
        <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
    @endunless
    @unless(in_array('limit', $skipFields, true))
        <input type="hidden" name="limit" value="{{ $filters['limit'] }}">
    @endunless

    <span class="rp-filter-label">Kỳ xem</span>
    @include('partials.date-range', [
        'formId' => 'rpFilter',
        'from' => $filters['from_date'],
        'to' => $filters['to_date'],
        'title' => 'Chọn khoảng thời gian của báo cáo',
    ])

    <div class="rp-seg" role="group" aria-label="Khoảng xem nhanh">
        @foreach($QUICK as $preset)
            <a href="{{ $quickLink($preset['code']) }}"
               class="rp-seg-btn {{ $filters['quick'] === $preset['code'] ? 'is-active' : '' }}"
               @if($filters['quick'] === $preset['code']) aria-current="true" @endif>{{ $preset['label'] }}</a>
        @endforeach
    </div>

    <div class="rp-filters-right">
        {{-- Chi nhánh xem báo cáo. Chỉ hiện khi cửa hàng có từ hai kho trở lên —
             tiệm một điểm bán thì ô này không có gì để chọn.

             "Tất cả chi nhánh" gửi shop_id=0, một giá trị khai TƯỜNG MINH: bỏ
             trống nghĩa là "theo kho đang làm việc", nên hai thứ ấy không thể
             dùng chung một ô rỗng. --}}
        @if(count($chiNhanh ?? []) > 1)
            <select name="shop_id" class="rp-select" title="Chi nhánh xem báo cáo"
                onchange="this.form.submit()">
                <option value="" @selected(($filters['shop_id'] ?? '') === '')>Chi nhánh đang làm</option>
                <option value="0" @selected(($filters['shop_id'] ?? '') === '0')>Tất cả chi nhánh</option>
                @foreach($chiNhanh as $cn)
                    <option value="{{ $cn['id'] ?? 0 }}"
                        @selected(($filters['shop_id'] ?? '') === (string) ($cn['id'] ?? 0))>
                        {{ $cn['name'] ?? '' }}
                    </option>
                @endforeach
            </select>
        @endif

        {!! $extra ?? '' !!}

        @if($showGroup)
            <div class="rp-seg" role="group" aria-label="Cách chia trục thời gian">
                @foreach($GROUPS as $code => $label)
                    <a href="{{ $link(['group_by' => $code]) }}"
                       class="rp-seg-btn {{ $filters['group_by'] === $code ? 'is-active' : '' }}"
                       @if($filters['group_by'] === $code) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </div>
        @endif

        <a class="rp-ghostbtn" href="{{ $link() }}" title="Tải lại số liệu">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
            Làm mới
        </a>
    </div>
</form>

{{-- Đang xem một kỳ neo vào hôm nay thì qua nửa đêm tự nạp lại, để "hôm qua"
     luôn là ngày vừa đóng sổ chứ không phải ngày kia. --}}
@include('partials.day-rollover', ['date' => $filters['today'], 'anchored' => $filters['anchored']])

@if(!empty($error))
    <p class="rp-alert">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
        {{ $error }} — các con số bên dưới đang hiển thị 0, không phải kỳ này không có dữ liệu.
    </p>
@endif
