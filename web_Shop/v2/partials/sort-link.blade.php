{{--
    NHÃN CỘT BẤM ĐƯỢC ĐỂ SẮP XẾP — chữ cột kèm hai mũi tam giác xếp chồng, mũi
    nào đang dùng thì rõ, mũi kia mờ đi.

    Khác `sortable-label` ở chỗ: bản kia gắn chặt với bảng SORTABLE của
    ProductController, còn bản này chỉ nhận sẵn URL và độ mờ nên màn nào cũng
    dùng được.

    `js-table-sort` để khung v2 bắt lượt bấm mà nạp lại danh sách tại chỗ; href
    vẫn là URL thật nên không JS vẫn sắp xếp được, và mở tab mới cũng ra đúng.

    Nhận: $url, $nhan, $moTang, $moGiam.
--}}
<a href="{{ $url }}" class="js-table-sort text-decoration-none"
    style="color: #212521; white-space: nowrap;" title="Sắp xếp theo {{ mb_strtolower($nhan) }}">
    {{-- Bọc riêng phần chữ: cột hẹp thì CHỮ cắt "…", cặp mũi tên vẫn còn. --}}
    <span class="nhan-cot">{{ $nhan }}</span>
    <span class="sort-icons"
        style="margin-left:4px; display:inline-flex; flex-direction:column; line-height:7px; vertical-align:middle;">
        <i class="fa fa-caret-up" style="font-size: 14px; opacity: {{ $moTang }};"></i>
        <i class="fa fa-caret-down" style="font-size: 14px; opacity: {{ $moGiam }};"></i>
    </span>
</a>
