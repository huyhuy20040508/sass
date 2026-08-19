{{--
    Phân trang kiểu bản cũ v2 — dùng cho các trang ĐÃ dựng lại.
    Trang chưa dựng lại vẫn dùng `partials/pagination.blade.php`; chuyển hết rồi
    thì xoá bản cũ đi. Kiểu dáng khai báo ở `layouts/app.blade.php` (.pgv2-*).

    Tham số truyền qua @include:
      - meta            : ['page','total_pages','total','page_size']   (bắt buộc)
      - noun            : danh từ đếm, vd 'người' / 'nhóm'             (mặc định 'mục')
      - perPageName     : tên query param số dòng/trang                (mặc định 'page_size')
      - perPageOptions  : mảng số dòng cho ô chọn                      (mặc định [10,20,30,40,50])

    Hoàn toàn dựa trên URL: đổi trang hay số dòng chỉ là điều hướng tới cùng URL kèm
    query mới nên giữ nguyên mọi bộ lọc đang bật. Không cần JS riêng.
--}}
@php
    $pgMeta = $meta ?? [];
    $pgPage = max(1, (int) ($pgMeta['page'] ?? 1));
    $pgLast = max(1, (int) ($pgMeta['total_pages'] ?? 1));
    $pgTotal = (int) ($pgMeta['total'] ?? 0);
    $pgSize = max(1, (int) ($pgMeta['page_size'] ?? 10));
    $pgFirst = ($pgPage - 1) * $pgSize;
    $pgNoun = $noun ?? 'mục';
    $pgParam = $perPageName ?? 'page_size';
    $pgOptions = $perPageOptions ?? [10, 20, 30, 40, 50];

    // Cửa sổ ±2 trang quanh trang hiện tại — đúng component phân trang của bản v2.
    $pgWindow = [];
    if ($pgLast <= 7) {
        $pgWindow = range(1, $pgLast);
    } else {
        $pgWindow[] = 1;
        if ($pgPage > 4) $pgWindow[] = '...';
        for ($i = max(2, $pgPage - 2); $i <= min($pgLast - 1, $pgPage + 2); $i++) $pgWindow[] = $i;
        if ($pgPage < $pgLast - 3) $pgWindow[] = '...';
        $pgWindow[] = $pgLast;
    }

    $pgChevron = fn ($d) => '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="'
        . ($d === 'left' ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6') . '"/></svg>';
@endphp

@if($pgTotal > 0)
    <div class="pgv2">
        <div class="pgv2-side">
            <select class="pgv2-size" aria-label="Số dòng mỗi trang"
                    onchange="if(this.value){window.location.href=this.value;}">
                @foreach($pgOptions as $n)
                    <option value="{{ request()->fullUrlWithQuery([$pgParam => $n, 'page' => 1]) }}"
                            {{ $pgSize === (int) $n ? 'selected' : '' }}>Hiển thị {{ $n }}</option>
                @endforeach
            </select>
        </div>

        {{-- Nút lùi/tiến chỉ hiện khi còn trang để đi, đúng như bản v2. --}}
        @if($pgLast > 1)
            <nav class="pgv2-nav" aria-label="Điều hướng trang">
                @if($pgPage > 1)
                    <a class="pgv2-item" rel="prev" aria-label="Trang trước"
                       href="{{ request()->fullUrlWithQuery(['page' => $pgPage - 1]) }}">{!! $pgChevron('left') !!}</a>
                @endif

                @foreach($pgWindow as $p)
                    @if($p === '...')
                        <span class="pgv2-item is-gap">…</span>
                    @else
                        <a class="pgv2-item {{ $p === $pgPage ? 'is-active' : '' }}"
                           href="{{ request()->fullUrlWithQuery(['page' => $p]) }}"
                           @if($p === $pgPage) aria-current="page" @endif>{{ $p }}</a>
                    @endif
                @endforeach

                @if($pgPage < $pgLast)
                    <a class="pgv2-item" rel="next" aria-label="Trang sau"
                       href="{{ request()->fullUrlWithQuery(['page' => $pgPage + 1]) }}">{!! $pgChevron('right') !!}</a>
                @endif
            </nav>
        @endif

        <div class="pgv2-side pgv2-side--right">
            <span class="pgv2-info">
                <b>{{ number_format($pgFirst + 1, 0, ',', '.') }}–{{ number_format(min($pgFirst + $pgSize, $pgTotal), 0, ',', '.') }}</b>
                / {{ number_format($pgTotal, 0, ',', '.') }} {{ $pgNoun }}
            </span>
        </div>
    </div>
@endif
