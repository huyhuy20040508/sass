{{-- Ô lọc "Nhân viên" (nhóm + một người) của các tab báo cáo cuối ngày theo nhân
     viên — đặt trong @section('loc-giua'). Đi cùng trait
     Controllers\Concerns\LocNhanVien: cần $filters, $dsNhanVien.
     Chọn nhóm thì ô Tên chỉ còn người của nhóm đó, như v2. --}}
@push('nut-loc-them')
    @include('v2::partials.filter-button-mobile', [
        'dataBsTarget' => 'offcanvasBottomInMobile',
        'dataOffcanvasTarget' => 'filterStaff',
        'modalLabel' => 'Nhân viên',
    ])
@endpush

<div id="filterStaff" class="mb-3">
    <div class="inner-modal-in-mobile">
        <label class="form-label title_search">Nhân viên</label>
        <select name="area" class="form-select" data-loc data-tai-lai>
            <option value="">--Nhóm nhân viên--</option>
            @foreach (\App\Http\Controllers\StaffReportController::NHOM as $ma => $nhan)
                <option value="{{ $ma }}" {{ $filters['area'] === $ma ? 'selected' : '' }}>{{ $nhan }}</option>
            @endforeach
        </select>
        <div class="mt-2"></div>
        <select name="user_id" class="form-select" data-loc>
            <option value="">--Tên--</option>
            @foreach ($dsNhanVien as $nv)
                <option value="{{ $nv['id'] }}" {{ $filters['user_id'] === (string) $nv['id'] ? 'selected' : '' }}>{{ $nv['name'] }}</option>
            @endforeach
        </select>
    </div>
</div>

@push('scripts')
    <script>
        // Đổi nhóm thì bỏ người đang chọn — người đó có thể không thuộc nhóm mới.
        // Nạp lại cả trang để ô Tên chỉ còn người của nhóm vừa chọn.
        $(document).on('change', '.fillter-box [name="area"]', function () {
            const q = new URLSearchParams(location.search);
            this.value ? q.set('area', this.value) : q.delete('area');
            q.delete('user_id');
            location.href = location.pathname + '?' + q;
        });
    </script>
@endpush
