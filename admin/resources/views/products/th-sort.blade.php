{{--
    Tiêu đề cột bấm được để sắp xếp — bản cũ v2 sắp xếp bằng cách này chứ không
    có ô chọn "sắp xếp theo".

    Bấm lần đầu: tăng dần. Bấm lại cột đang sắp: đảo chiều. Mũi tên chỉ đậm ở
    cột đang sắp, các cột khác để mờ cho biết là bấm được.

    Nhận: $key (name | group | price), $label, và $filters từ view cha.
--}}
@php
    $cap = \App\Http\Controllers\ProductController::SORTABLE[$key] ?? null;
    [$tang, $giam] = $cap ?? [null, null];

    $dangTang = $filters['sort'] === $tang;
    $dangGiam = $filters['sort'] === $giam;
    // Đang tăng thì bấm nữa là giảm; còn lại (đang giảm hoặc chưa sắp) về tăng.
    $keTiep = $dangTang ? $giam : $tang;

    $q = array_merge(request()->query(), ['sort' => $keTiep, 'page' => 1]);
@endphp
<a href="{{ route('admin.products.index', $q) }}"
   class="prd-th-sort {{ $dangTang || $dangGiam ? 'is-on' : '' }}"
   title="Sắp xếp theo {{ mb_strtolower($label) }}">
    {{ $label }}
    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        @if($dangGiam)
            <path d="m6 9 6 6 6-6"/>
        @else
            <path d="m18 15-6-6-6 6"/>
        @endif
    </svg>
</a>
