{{--
    Component phân trang dùng chung cho mọi trang danh sách (server-side render).
    Tham số truyền vào qua @include:
      - meta            : mảng ['page','total_pages','total','page_size']  (bắt buộc)
      - noun            : danh từ đếm, vd 'đơn hàng' / 'sản phẩm'          (mặc định 'mục')
      - perPageName     : tên query param số dòng/trang: 'page_size'|'per_page' (mặc định 'page_size')
      - perPageOptions  : mảng số dòng cho ô chọn                          (mặc định [20,50,100])

    Cơ chế: hoàn toàn dựa trên URL (GET) — đổi số dòng hoặc trang chỉ là điều hướng
    tới cùng URL kèm query mới, giữ nguyên mọi bộ lọc hiện tại. Không cần JS riêng.
--}}
@php
    $pgMeta = $meta ?? [];
    $pgPage = (int) ($pgMeta['page'] ?? 1);
    $pgLast = (int) ($pgMeta['total_pages'] ?? 1);
    $pgTotal = (int) ($pgMeta['total'] ?? 0);
    $pgSize = (int) ($pgMeta['page_size'] ?? 20);
    $pgFirst = ($pgPage - 1) * $pgSize;
    $pgNoun = $noun ?? 'mục';
    $pgParam = $perPageName ?? 'page_size';
    $pgOptions = $perPageOptions ?? [20, 50, 100];

    // Cửa sổ số trang: 1 … (cur-1 cur cur+1) … last, tối đa gọn gàng.
    $pgWindow = [];
    if ($pgLast <= 7) {
        $pgWindow = range(1, $pgLast);
    } else {
        $pgWindow[] = 1;
        if ($pgPage > 3) $pgWindow[] = '...';
        for ($i = max(2, $pgPage - 1); $i <= min($pgLast - 1, $pgPage + 1); $i++) $pgWindow[] = $i;
        if ($pgPage < $pgLast - 2) $pgWindow[] = '...';
        $pgWindow[] = $pgLast;
    }
@endphp

@if($pgTotal > 0)
    <div class="pg">
        <select class="pg-size" aria-label="Số dòng mỗi trang"
                onchange="if(this.value){window.location.href=this.value;}">
            @foreach($pgOptions as $n)
                <option value="{{ request()->fullUrlWithQuery([$pgParam => $n, 'page' => 1]) }}"
                        {{ $pgSize === (int) $n ? 'selected' : '' }}>Hiển thị {{ $n }}</option>
            @endforeach
        </select>

        <div class="pg-right">
            <span class="pg-info">
                <b>{{ number_format($pgFirst + 1, 0, ',', '.') }}–{{ number_format(min($pgFirst + $pgSize, $pgTotal), 0, ',', '.') }}</b>
                / {{ number_format($pgTotal, 0, ',', '.') }} {{ $pgNoun }}
            </span>

            @if($pgLast > 1)
                <nav class="pg-nav" aria-label="Điều hướng trang">
                    <a class="pg-btn {{ $pgPage <= 1 ? 'is-disabled' : '' }}"
                       @if($pgPage > 1) href="{{ request()->fullUrlWithQuery(['page' => $pgPage - 1]) }}" @endif
                       aria-label="Trang trước" rel="prev">‹</a>
                    @foreach($pgWindow as $p)
                        @if($p === '...')
                            <span class="pg-gap">…</span>
                        @else
                            <a class="pg-btn {{ $p === $pgPage ? 'is-active' : '' }}"
                               href="{{ request()->fullUrlWithQuery(['page' => $p]) }}"
                               @if($p === $pgPage) aria-current="page" @endif>{{ $p }}</a>
                        @endif
                    @endforeach
                    <a class="pg-btn {{ $pgPage >= $pgLast ? 'is-disabled' : '' }}"
                       @if($pgPage < $pgLast) href="{{ request()->fullUrlWithQuery(['page' => $pgPage + 1]) }}" @endif
                       aria-label="Trang sau" rel="next">›</a>
                </nav>
            @endif
        </div>
    </div>
@endif
