{{-- Ô lọc "Hàng hóa" (nhóm + một mặt hàng) của các tab báo cáo cuối ngày theo
     mặt hàng — đặt trong @section('loc-giua'). Đi cùng trait
     Controllers\Concerns\LocHangHoa: cần $filters, $nhomHang, $dsHang.
     Ô mặt hàng khoá tới khi chọn nhóm và chỉ còn hàng của nhóm đó, như v2. --}}
@push('nut-loc-them')
    @include('v2::partials.filter-button-mobile', [
        'dataBsTarget' => 'offcanvasBottomInMobile',
        'dataOffcanvasTarget' => 'filterGoods',
        'modalLabel' => 'Hàng hóa',
    ])
@endpush

<div id="filterGoods" class="mb-3">
    <div class="inner-modal-in-mobile">
        <label class="form-label title_search">Hàng hóa</label>
        <select name="category_id" class="form-select" data-loc data-tai-lai>
            <option value="">Nhóm hàng hóa</option>
            @foreach ($nhomHang as $nhom)
                <option value="{{ $nhom['id'] }}" {{ $filters['category_id'] === (string) $nhom['id'] ? 'selected' : '' }}>{{ str_repeat('— ', $nhom['cap']).$nhom['name'] }}</option>
            @endforeach
        </select>
        <div class="mt-2"></div>
        <select name="product_id" class="form-select" data-loc {{ $filters['category_id'] === '' ? 'disabled' : '' }}>
            <option value="">Hàng hóa</option>
            @foreach ($dsHang as $h)
                <option value="{{ $h['id'] }}" {{ $filters['product_id'] === (string) $h['id'] ? 'selected' : '' }}>{{ $h['name'] }}</option>
            @endforeach
        </select>
    </div>
</div>

@push('scripts')
    <script>
        // Đổi nhóm thì bỏ mặt hàng đang chọn — nó có thể không thuộc nhóm mới.
        // Nạp lại cả trang để ô Hàng hóa chỉ còn hàng của nhóm vừa chọn.
        $(document).on('change', '.fillter-box [name="category_id"]', function () {
            const q = new URLSearchParams(location.search);
            this.value ? q.set('category_id', this.value) : q.delete('category_id');
            q.delete('product_id');
            location.href = location.pathname + '?' + q;
        });
    </script>
@endpush
