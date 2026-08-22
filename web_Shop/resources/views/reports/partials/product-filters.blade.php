{{--
    Hai ô lọc riêng của báo cáo sản phẩm, chèn vào hàng bộ lọc chung.

    Nằm trong form #rpFilter của partial đầu trang nên đổi là submit ngay
    (data-auto-submit) — không có nút "Áp dụng", đúng quy ước chung.
--}}
<label class="rp-filter-label" for="rpSort">Xếp theo</label>
<select id="rpSort" name="sort" class="rp-select" data-auto-submit>
    @foreach($sorts as $code => $label)
        <option value="{{ $code }}" @selected($filters['sort'] === $code)>{{ $label }}</option>
    @endforeach
</select>

<label class="rp-filter-label" for="rpLimit">Số dòng</label>
<select id="rpLimit" name="limit" class="rp-select" data-auto-submit>
    @foreach($limits as $value)
        <option value="{{ $value }}" @selected((int) $filters['limit'] === (int) $value)>{{ $value }}</option>
    @endforeach
</select>
