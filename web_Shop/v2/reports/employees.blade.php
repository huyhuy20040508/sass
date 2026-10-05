{{-- Báo cáo → Báo cáo cuối ngày, tab Báo cáo nhân viên — tab `commissionEmployee`
     của báo cáo cuối ngày v2 (HTML bản đang chạy trả về cho
     end-day/list?type=commissionEmployee). Khung trang ở v2::reports.end-day.

     Phần riêng của tab:
       - ô lọc Nhân viên (nhóm + một người) dùng chung với tab Báo cáo ca;
       - ô tìm "nhân viên, hóa đơn" + Xuất Excel; bảng mỗi nhân viên một dòng,
         chân bảng "Tổng cộng";
       - bấm vào tên → hộp "Báo cáo nhân viên - Tên" liệt kê từng đơn.
       - Như v2: không có dạng Biểu đồ, không có nút chọn cột.

     Khác v2:
       - Hoa hồng = doanh thu tính hoa hồng × tỉ lệ % trên hồ sơ nhân sự (một
         người một tỉ lệ). v2 có chương trình hoa hồng theo món nên bỏ hai ô lọc
         "Hoa hồng" và "Hàng hóa" của v2.
       - Doanh thu tính hoa hồng = doanh thu (VAT) − tiền trả hàng.
       - Tiền in dấu chấm ngăn nghìn như mọi màn khác của khu này.

     Dữ liệu do EmployeeReportController::index đẩy sang: $dong, $tong,
     $donTheoNguoi, $filters, $chiNhanh, $dsNhanVien. --}}
@extends('v2::reports.end-day')

@php
    $tabDangXem = 'commissionEmployee';
    $C = \App\Http\Controllers\EmployeeReportController::class;
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $soCot = array_slice(array_keys($C::COT), 1);
@endphp

@push('styles')
    <style>
        .nv-ten { cursor: pointer; font-weight: bold; color: rgb(82, 128, 189); }
        #modalNhanVien .modal-dialog { max-width: 1300px; }
        #modalNhanVien .modal-body { max-height: 70vh; overflow-y: auto; }
        #modalNhanVien thead th { background-color: #d3daeb !important; white-space: nowrap; text-align: center; }
        #modalNhanVien td { white-space: nowrap; }
        #modalNhanVien td.nv-mon { white-space: normal; min-width: 220px; }
        #modalNhanVien .nv-dong-tong td { font-weight: bold; background-color: #F6FFCBFC; }
    </style>
@endpush

@section('loc-giua')
    @include('v2::reports.end-day-loc-nhan-vien')
@endsection

@section('noi-dung')
    <div class="row mt-3 list-by-nav">
        {{-- Hàng công cụ nằm NGOÀI `.list`: V2.napLai thay khối đó mỗi lượt lọc,
             ô tìm ở trong là gõ tới đâu mất chữ tới đó. --}}
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start">
            <div class="col-12 col-md-4 search-bar-container">
                <input type="text" class="form-control search-bar mb-2" name="keyword" data-loc
                    value="{{ $filters['keyword'] }}" autocomplete="off" placeholder="Tìm kiếm theo nhân viên, hóa đơn">
            </div>
            <a class="btn btn-sm d-flex align-items-center btn-export" id="tk-xuat"
                href="{{ route('admin.reports.employees', array_merge(request()->query(), ['xuat' => 'excel'])) }}">
                <i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất Excel
            </a>
        </div>

        {{-- `list`: khối V2.napLai thay mỗi lượt lọc — đơn của từng người cho hộp
             chi tiết nằm cùng trong, để lọc xong hộp cũng mới. --}}
        <div class="mx-0 list">
            @include('v2::reports.end-day-ky')
            <script type="application/json" id="nv-don">@json((object) $donTheoNguoi)</script>

            <div class="table-responsive">
                <table class="table table-bordered mt-2 bang-cuoi-ngay bang-nhan-vien">
                    <thead>
                        <tr>
                            <th>STT</th>
                            @foreach ($C::COT as $nhan)
                                <th>{{ $nhan }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dong as $i => $d)
                            <tr class="item">
                                <td>{{ $i + 1 }}</td>
                                <td class="text-left">
                                    <span class="nv-ten" data-id="{{ $d['user_id'] }}">{{ ($d['name'] ?? '') !== '' ? $d['name'] : '—' }}</span>
                                    @if (($d['employee_code'] ?? '') !== '')
                                        <span class="text-muted small ms-1">({{ $d['employee_code'] }})</span>
                                    @endif
                                </td>
                                @foreach ($soCot as $khoa)
                                    <td class="text-right col_{{ $khoa }}">{{ $tien($d[$khoa] ?? 0) }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="tk-rong">Không có dữ liệu</td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2">Tổng cộng</th>
                            @foreach ($soCot as $khoa)
                                <th class="text-right col_{{ $khoa }}">{{ $tien($tong[$khoa] ?? 0) }}</th>
                            @endforeach
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Hộp "Báo cáo nhân viên - Tên" — khuôn #commissionEmployeeModal của v2. --}}
    <div class="modal fade" id="modalNhanVien" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="nvTieuDe"></h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead>
                                <tr>
                                    @foreach (['STT', 'Mã đơn', 'Ngày', 'Khách hàng', 'Tên hàng hóa', 'Số lượng', 'Tổng Doanh Thu',
                                        'Tổng doanh thu (VAT)', 'Tổng tiền trả hàng', 'Doanh thu tính hoa hồng', 'Tỷ lệ hoa hồng', 'Hoa hồng'] as $nhan)
                                        <th>{{ $nhan }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody id="nvThan"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0));
            const phanTram = (v) => new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(Number(v) || 0) + '%';
            const thoat = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            })[c]);
            const TIEN = ['revenue', 'revenue_vat', 'returned', 'commission_base'];

            // Dữ liệu đọc lúc BẤM, không đọc một lần lúc mở trang: khối `.list`
            // được nạp lại sau mỗi lượt lọc.
            $(document).on('click', '.nv-ten', function () {
                const tatCa = JSON.parse(document.getElementById('nv-don')?.textContent || '{}');
                const nv = tatCa[String($(this).data('id'))];
                if (!nv) return;
                const ds = nv.orders || [];
                const cong = (k) => ds.reduce((t, o) => t + (Number(o[k]) || 0), 0);

                let html = ds.map((o, i) => '<tr>'
                    + '<td class="text-center">' + (i + 1) + '</td>'
                    + '<td class="text-left"><b>' + thoat(o.code) + '</b></td>'
                    + '<td class="text-center">' + (thoat(o.ngay) || '-') + '</td>'
                    + '<td class="text-left">' + (thoat(o.customer_name) || '-') + '</td>'
                    + '<td class="text-left nv-mon">' + thoat(o.products) + '</td>'
                    + '<td class="text-center">' + tien(o.quantity) + '</td>'
                    + TIEN.map((k) => '<td class="text-right">' + tien(o[k]) + '</td>').join('')
                    + '<td class="text-right">' + phanTram(nv.rate) + '</td>'
                    + '<td class="text-right">' + tien(o.commission) + '</td></tr>').join('');
                html += '<tr class="nv-dong-tong"><td colspan="5" class="text-end">Tổng cộng</td>'
                    + '<td class="text-center">' + tien(cong('quantity')) + '</td>'
                    + TIEN.map((k) => '<td class="text-right">' + tien(cong(k)) + '</td>').join('')
                    + '<td class="text-right">' + phanTram(nv.rate) + '</td>'
                    + '<td class="text-right">' + tien(cong('commission')) + '</td></tr>';

                $('#nvTieuDe').text('Báo cáo nhân viên - ' + (nv.name || '—'));
                $('#nvThan').html(html);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalNhanVien')).show();
            });
        })();
    </script>
@endpush
