{{--
    NHÃN CỘT BẤM ĐƯỢC ĐỂ SẮP XẾP — chép từ components/table/sortable-label của
    bản v2 cũ: chữ cột kèm hai mũi tam giác xếp chồng, mũi nào đang dùng thì rõ,
    mũi kia mờ đi.

    Khác v2 đúng một chỗ: v2 để `href="#"` rồi JS bắt `.js-table-sort`; ở đây trỏ
    thẳng URL có `?sort=` — không JS vẫn sắp xếp được, và người dùng mở tab mới
    từ tiêu đề cột cũng ra đúng thứ tự.

    Nhận: $key (name | group | price), $label, và $filters của view cha.
--}}
@php
    $cap = \App\Http\Controllers\ProductController::SORTABLE[$key] ?? null;
    [$tang, $giam] = $cap ?? [null, null];

    $dangTang = ($filters['sort'] ?? '') === $tang;
    $dangGiam = ($filters['sort'] ?? '') === $giam;

    // Đang tăng thì bấm nữa là giảm; còn lại (đang giảm, hoặc chưa sắp cột này)
    // về tăng.
    $keTiep = $dangTang ? $giam : $tang;

    // Mờ 0.4 khi cột chưa được sắp; đã sắp thì mũi đang dùng rõ hẳn, mũi kia mờ
    // sâu hơn — đúng ba mức của v2.
    $moTang = $dangTang || $dangGiam ? ($dangGiam ? 0.25 : 1) : 0.4;
    $moGiam = $dangTang || $dangGiam ? ($dangTang ? 0.25 : 1) : 0.4;
@endphp

<a href="{{ request()->fullUrlWithQuery(['sort' => $keTiep, 'page' => 1]) }}"
    class="js-table-sort text-decoration-none" style="color: #212521;" data-sort-by="{{ $key }}"
    title="Sắp xếp theo {{ mb_strtolower($label) }}">
    {{ $label }}
    <span class="sort-icons"
        style="margin-left:6px; display:inline-flex; flex-direction:column; line-height:10px; vertical-align:middle;">
        <i class="fa fa-caret-up" style="font-size: 16px; opacity: {{ $moTang }}; overflow: hidden;"></i>
        <i class="fa fa-caret-down" style="font-size: 16px; margin-top:-2px; opacity: {{ $moGiam }}; overflow: hidden;"></i>
    </span>
</a>
