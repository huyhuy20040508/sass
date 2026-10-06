{{-- Báo cáo → Báo cáo cuối ngày, tab Khách hàng — tab `customer` của báo cáo
     cuối ngày v2 (HTML bản đang chạy trả về cho end-day/list?type=customer).
     Khung trang (lọc chung, tiêu đề, dãy tab) ở v2::reports.end-day.

     Phần riêng của tab:
       - ô lọc Khách hàng: nhóm + một khách (chọn nhóm thì ô khách chỉ còn khách
         của nhóm đó);
       - ô tìm tên/mã + Xuất Excel + chọn cột; bảng 11 cột có sắp xếp, dòng
         "Bán cho người tiêu dùng" đứng đầu, chân bảng "Tổng cộng" nền vàng.

     Dòng "Bán cho người tiêu dùng" GHIM ĐẦU BẢNG, không chạy theo sắp xếp — nó
     gộp mọi đơn khách vãng lai chứ không phải một khách, thả vào sắp chung thì
     nó thành "khách mua nhiều nhất". Nhưng ghim mà không nói thì người xem chỉ
     thấy một bảng sắp sai, nên dòng ấy có nền riêng, nhãn "(tổng khách lẻ)", và
     khi đang sắp xếp thì dưới bảng có một câu nói rõ.

     Hai chỗ khác v2:
       - Ô "Giá trị TB" ở chân bảng là tổng chi / tổng đơn. Bản gốc cộng dồn giá
         trị TB của từng dòng — con số đó không có nghĩa gì.
       - Tiền in dấu chấm ngăn nghìn như mọi màn khác của khu này (v2 in dấu phẩy).

     Dữ liệu do ReportController::customers đẩy sang: $rows, $khachLe, $tong,
     $filters, $columns, $chiNhanh, $nhomKhach, $dsKhach. --}}
@extends('v2::reports.end-day')

@php
    $tabDangXem = 'customer';
    $C = \App\Http\Controllers\ReportController::class;
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $an = fn ($c) => $columns['show_'.$c] ? '' : 'hide';

    // Nút sắp xếp cạnh nhãn cột, như `.btn_sort_table` của v2: bấm lần đầu là
    // giảm dần, bấm lại cột đang sắp thì đảo chiều.
    $nutSap = function (string $cot) use ($filters) {
        $dang = $filters['sort_field'] === $cot;
        $chieu = $dang && $filters['sort_type'] === 'desc' ? 'asc' : 'desc';
        $icon = $dang ? ($filters['sort_type'] === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';

        return '<a class="btn_sort_table'.($dang ? ' dang-sap' : '').'" href="'
            .e(request()->fullUrlWithQuery(['sort_field' => $cot, 'sort_type' => $chieu]))
            .'"><i class="fa-solid '.$icon.'"></i></a>';
    };

    $cacDong = array_merge($khachLe ? [$khachLe] : [], $rows);
@endphp

@push('styles')
    <style>
        /* Dòng gộp khách lẻ: nền riêng để đọc ra ngay đây không phải một khách.
           Khác hẳn vàng của chân bảng (tfoot) — hai thứ khác nhau, đừng cho
           giống nhau. */
        table.bang-khach tr.kh-gop td { background: #eef4fb; font-style: italic; }
        table.bang-khach tr.kh-gop .kh-gop-nhan { color: #5a6b7d; font-size: 12.5px; font-style: normal; }
        .kh-ghim-chu { margin: 6px 0 0; font-size: 12.5px; color: #8a6d3b; }
    </style>
@endpush

@push('nut-loc-them')
    @include('v2::partials.filter-button-mobile', [
        'dataBsTarget' => 'offcanvasBottomInMobile',
        'dataOffcanvasTarget' => 'filterCustomer',
        'modalLabel' => __('message.customer'),
    ])
@endpush

@section('loc-giua')
                        {{-- Khách hàng: nhóm + một khách, đúng khối #filterCustomer của v2.
                             Chọn nhóm thì ô khách chỉ còn khách của nhóm đó (data-group). --}}
                        <div id="filterCustomer" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.customer') }}</label>
                                <select name="customer_group_id" class="form-select" data-loc data-tai-lai>
                                    <option value="">--Nhóm khách hàng</option>
                                    @foreach ($nhomKhach as $nhom)
                                        <option value="{{ $nhom['id'] }}" {{ (string) $filters['customer_group_id'] === (string) $nhom['id'] ? 'selected' : '' }}>{{ $nhom['name'] }}</option>
                                    @endforeach
                                </select>
                                <div class="mt-2"></div>
                                <select name="user_id" class="form-select" data-loc>
                                    <option value="">--Khách hàng--</option>
                                    @foreach ($dsKhach as $k)
                                        @continue($filters['customer_group_id'] !== '' && (string) ($k['customer_group_id'] ?? '') !== (string) $filters['customer_group_id'])
                                        <option value="{{ $k['id'] }}" {{ (string) $filters['user_id'] === (string) $k['id'] ? 'selected' : '' }}>{{ $k['full_name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
@endsection

@section('noi-dung')
                    <div class="row mt-3 list-by-nav">
                        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start">
                            <div class="col-12 col-md-4 search-bar-container">
                                <input type="text" class="form-control search-bar mb-2" name="keyword" data-loc
                                    value="{{ $filters['keyword'] }}" autocomplete="off" placeholder="Tìm theo tên/mã khách hàng">
                            </div>
                            <div class="d-flex settting-col-and-file-export-container">
                                <a class="btn btn-sm d-flex align-items-center btn-export" id="tk-xuat"
                                    href="{{ route('admin.reports.customers', array_merge(request()->query(), ['xuat' => 'excel'])) }}">
                                    <i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất Excel
                                </a>
                                <div class="dropup ms-sm-1">
                                    <button type="button" class="btn active dropbtn setting-col">
                                        <i class="fa fa-sliders" aria-hidden="true"></i>
                                        <div class="dropup-content">
                                            <div class="list_filter">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="show_all"
                                                        {{ in_array(0, $columns, true) ? '' : 'checked' }}>
                                                    <label for="show_all">{{ __('message.all') }}</label>
                                                </div>
                                                @foreach (['stt' => 'STT'] + $C::COT_KHACH as $khoa => $nhan)
                                                    <div class="form-check">
                                                        <input class="form-check-input show_col" data-col="{{ $khoa }}"
                                                            type="checkbox" id="show_{{ $khoa }}" {{ $columns['show_'.$khoa] ? 'checked' : '' }}>
                                                        <label for="show_{{ $khoa }}">{{ $nhan }}</label>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- `list`: CHỈ khối bảng là thứ V2.napLai thay ruột mỗi lượt lọc.
                             Ô tìm và hàng nút để NGOÀI — nằm trong là đang gõ dở thì bị
                             thay mất ô, mất con trỏ. Thiếu hẳn lớp này thì lọc xong URL
                             đổi mà bảng đứng yên số cũ. --}}
                        <div class="table-responsive mx-0 list">
                            @include('v2::reports.end-day-ky')
                            <table class="table table-bordered mt-2 bang-cuoi-ngay bang-khach">
                                <thead>
                                    <tr>
                                        <th class="show_stt {{ $an('stt') }}">STT</th>
                                        @foreach ($C::COT_KHACH as $khoa => $nhan)
                                            <th class="show_{{ $khoa }} {{ $an($khoa) }}">{{ $nhan }} {!! $nutSap($khoa) !!}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    {{-- Dòng khách lẻ ("Bán cho người tiêu dùng") đứng đầu như v2, không
                                         chạy theo sắp xếp — nó không phải một khách. --}}
                                    @forelse ($cacDong as $i => $r)
                                        {{-- $cacDong ghép khách lẻ vào đầu ngay phía trên, nên
                                             dòng 0 là dòng gộp khi có khách lẻ. --}}
                                        @php $laGop = $khachLe && $i === 0; @endphp
                                        <tr class="item {{ $laGop ? 'kh-gop' : '' }}">
                                            <td class="show_stt {{ $an('stt') }}">{{ $i + 1 }}</td>
                                            <td class="text-left show_code {{ $an('code') }}">{{ $r['code'] !== '' ? $r['code'] : '-' }}</td>
                                            <td class="text-left show_name {{ $an('name') }}">{{ $r['name'] }}@if ($laGop) <span class="kh-gop-nhan">(tổng khách lẻ)</span>@endif</td>
                                            <td class="text-left show_name_group {{ $an('name_group') }}">{{ $r['name_group'] }}</td>
                                            <td class="text-left show_rank {{ $an('rank') }}">{{ $r['rank'] }}</td>
                                            <td class="text-right show_total_expense {{ $an('total_expense') }}">{{ $tien($r['total_expense']) }}</td>
                                            <td class="text-right show_price_avg {{ $an('price_avg') }}">{{ $tien($r['price_avg']) }}</td>
                                            <td class="text-right show_accumulated_points {{ $an('accumulated_points') }}">{{ $tien($r['accumulated_points']) }}</td>
                                            <td class="text-right show_payment {{ $an('payment') }}">{{ $tien($r['payment']) }}</td>
                                            {{-- Công nợ luôn chữ đỏ, không nợ thì gạch ngang — như v2. --}}
                                            <td class="text-right show_debt text-danger {{ $an('debt') }}">{{ $r['debt'] > 0 ? $tien($r['debt']) : '-' }}</td>
                                            <td class="text-right show_total_order {{ $an('total_order') }}">{{ $r['total_order'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="11" class="tk-rong">Kỳ này chưa có khách nào mua hàng.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                @if ($cacDong)
                                    <tfoot>
                                        <tr>
                                            {{-- Năm ô đầu (STT → Hạng) gộp thành nhãn, trừ đi các cột đang ẩn. --}}
                                            <th colspan="{{ max(1, collect(['stt', 'code', 'name', 'name_group', 'rank'])->filter(fn ($c) => $columns['show_'.$c])->count()) }}">Tổng cộng</th>
                                            <th class="text-right show_total_expense {{ $an('total_expense') }}">{{ $tien($tong['total_expense']) }}</th>
                                            <th class="text-right show_price_avg {{ $an('price_avg') }}">{{ $tien($tong['price_avg']) }}</th>
                                            <th class="text-right show_accumulated_points {{ $an('accumulated_points') }}">{{ $tien($tong['accumulated_points']) }}</th>
                                            <th class="text-right show_payment {{ $an('payment') }}">{{ $tien($tong['payment']) }}</th>
                                            <th class="text-right show_debt text-danger {{ $an('debt') }}">{{ $tong['debt'] > 0 ? $tien($tong['debt']) : '-' }}</th>
                                            <th class="text-right show_total_order {{ $an('total_order') }}">{{ $tong['total_order'] }}</th>
                                        </tr>
                                    </tfoot>
                                @endif
                            </table>
                            {{-- Chỉ nói khi ĐANG sắp xếp: lúc ấy người xem mới thấy một dòng
                                 nằm sai chỗ và tưởng cả bảng sắp hỏng. --}}
                            @if ($khachLe && $filters['sort_field'] !== '')
                                <p class="kh-ghim-chu">Dòng "Bán cho người tiêu dùng" gộp mọi đơn khách vãng lai nên luôn đứng đầu, không theo sắp xếp.</p>
                            @endif
                        </div>
                    </div>
@endsection

@push('scripts')
    <script>
        // Đổi nhóm thì bỏ khách đang chọn — khách đó có thể không thuộc nhóm mới.
        // Nạp lại cả trang để ô khách chỉ còn khách của nhóm vừa chọn.
        $(document).on('change', '.fillter-box [name="customer_group_id"]', function () {
            const q = new URLSearchParams(location.search);
            this.value ? q.set('customer_group_id', this.value) : q.delete('customer_group_id');
            q.delete('user_id');
            location.href = location.pathname + '?' + q;
        });
    </script>
@endpush
