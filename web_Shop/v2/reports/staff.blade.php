{{-- Báo cáo → Báo cáo cuối ngày, tab Báo cáo ca — tab `employee` của báo cáo cuối
     ngày v2 (HTML bản đang chạy trả về cho end-day/list?type=employee và chart
     tab=staff). Khung trang ở v2::reports.end-day.

     Phần riêng của tab:
       - ô lọc Nhân viên: nhóm + một người (chọn nhóm thì ô người chỉ còn người
         của nhóm đó);
       - Danh sách: ô tìm tên/mã, mỗi dòng (ngày × ca × nhân viên), 7 cột có sắp
         xếp, chân bảng "Tổng cộng";
       - Biểu đồ: doanh thu theo nhân viên (cột) + giá trị đơn TB (đường).

     Khác v2:
       - Ca bên mình không có mã: cột "Mã ca" in "Ca #số"; đơn quầy bán lúc chưa
         ai mở ca in "Ngoài ca" (v2 bỏ hẳn những đơn này), đơn web nhân viên lập
         in "Online" như v2.
       - Nhóm nhân viên là CỬA VÀO của tài khoản (Quản lý / Thu ngân).
       - Ô "Giá trị TB" ở chân bảng là tổng tiền / tổng đơn — v2 cộng dồn giá trị
         TB của từng dòng, con số đó không có nghĩa gì.

     Dữ liệu do StaffReportController::index đẩy sang: $dong, $tong, $theoNguoi,
     $filters, $columns, $chiNhanh, $dsNhanVien. --}}
@extends('v2::reports.end-day')

@php
    $tabDangXem = 'employee';
    $C = \App\Http\Controllers\StaffReportController::class;
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $an = fn ($c) => $columns['show_'.$c] ? '' : 'hide';
    $dangBieuDo = $filters['show'] === 'chart';

    $nutSap = function (string $cot) use ($filters) {
        $dang = $filters['sort_field'] === $cot;
        $chieu = $dang && $filters['sort_type'] === 'desc' ? 'asc' : 'desc';
        $icon = $dang ? ($filters['sort_type'] === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';

        return '<a class="btn_sort_table'.($dang ? ' dang-sap' : '').'" href="'
            .e(request()->fullUrlWithQuery(['sort_field' => $cot, 'sort_type' => $chieu]))
            .'"><i class="fa-solid '.$icon.'"></i></a>';
    };
@endphp

@push('styles')
    <style>
        .dt-the { padding: 0 !important; overflow: hidden; height: 100%; }
        .dt-the .dt-dau { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; gap: 8px; }
        .dt-the .chart-title { margin: 0; font-size: 17px; white-space: nowrap; font-weight: 600; }
        .dt-the .dt-ve { position: relative; height: 360px; padding: 18px 22px; }
        .nv-nut { display: flex; align-items: center; gap: 8px; }
        .nv-nut .nv-vien { background: #f1f3f5; border-radius: 999px; font-weight: 600; border: 0; padding: 6px 14px; white-space: nowrap; }
        .nv-nut button.nv-vien { color: #486a7f; }
        table.bang-ca .ca-khac { color: #8c8c8c; font-style: italic; }
    </style>
    <link href="{{ asset('v2/css/chart-report.css') }}" rel="stylesheet">
@endpush

@section('co-bieu-do', '1')

@section('loc-giua')
    @include('v2::reports.end-day-loc-nhan-vien')
@endsection

@section('noi-dung')
    <div class="row mt-3 list-by-nav">
        {{-- Hàng công cụ nằm NGOÀI `.list`: V2.napLai thay khối đó mỗi lượt lọc,
             ô tìm ở trong là gõ tới đâu mất chữ tới đó. --}}
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start {{ $dangBieuDo ? 'd-none' : '' }}" data-dang="table">
            <div class="col-12 col-md-4 search-bar-container">
                <input type="text" class="form-control search-bar mb-2" name="keyword" data-loc
                    value="{{ $filters['keyword'] }}" autocomplete="off" placeholder="Tìm theo tên/mã nhân viên">
            </div>
            <div class="d-flex settting-col-and-file-export-container">
                <a class="btn btn-sm d-flex align-items-center btn-export" id="tk-xuat"
                    href="{{ route('admin.reports.staff', array_merge(request()->query(), ['xuat' => 'excel'])) }}">
                    <i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất Excel
                </a>
                <div class="dropup ms-sm-1">
                    <button type="button" class="btn active dropbtn setting-col">
                        <i class="fa fa-sliders" aria-hidden="true"></i>
                        <div class="dropup-content">
                            <div class="list_filter">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="show_all" {{ in_array(0, $columns, true) ? '' : 'checked' }}>
                                    <label for="show_all">{{ __('message.all') }}</label>
                                </div>
                                @foreach (['stt' => 'STT'] + $C::COT as $khoa => $nhan)
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

        {{-- `list`: khối V2.napLai thay mỗi lượt lọc — bảng, biểu đồ và dữ liệu vẽ
             biểu đồ cùng nằm trong, để lọc xong cả hai dạng đều mới. --}}
        <div class="mx-0 list">
            @include('v2::reports.end-day-ky')
            <script type="application/json" id="nv-du-lieu">@json($theoNguoi)</script>

            <div class="table-responsive {{ $dangBieuDo ? 'd-none' : '' }}" data-dang="table">
                <table class="table table-bordered mt-2 bang-cuoi-ngay bang-ca">
                    <thead>
                        <tr>
                            <th class="show_stt {{ $an('stt') }}">STT</th>
                            @foreach ($C::COT as $khoa => $nhan)
                                <th class="show_{{ $khoa }} {{ $an($khoa) }}">{{ $nhan }} {!! $nutSap($khoa) !!}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dong as $i => $d)
                            <tr class="item">
                                <td class="show_stt {{ $an('stt') }}">{{ $i + 1 }}</td>
                                <td class="text-left show_date {{ $an('date') }}">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d-m-Y') }}</td>
                                <td class="text-left show_shift {{ ($d['kind'] ?? '') === 'shift' ? '' : 'ca-khac' }} {{ $an('shift') }}"
                                    @if (($d['kind'] ?? '') === 'none') title="Đơn bán tại quầy lúc không có ca nào mở" @endif>{{ $d['shift'] }}</td>
                                <td class="text-left show_employee_code {{ $an('employee_code') }}">{{ ($d['employee_code'] ?? '') !== '' ? $d['employee_code'] : '-' }}</td>
                                <td class="text-left show_name {{ $an('name') }}">{{ ($d['name'] ?? '') !== '' ? $d['name'] : '—' }}</td>
                                <td class="text-right show_order_count {{ $an('order_count') }}">{{ $tien($d['order_count'] ?? 0) }}</td>
                                <td class="text-right show_revenue {{ $an('revenue') }}">{{ $tien($d['revenue'] ?? 0) }}</td>
                                <td class="text-right show_avg_order {{ $an('avg_order') }}">{{ $tien($d['avg_order'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="tk-rong">Kỳ này chưa có doanh thu theo ca.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($dong)
                        <tfoot>
                            <tr>
                                {{-- Năm ô đầu (STT → Tên) gộp thành nhãn, trừ đi cột đang ẩn. --}}
                                <th colspan="{{ max(1, collect(['stt', 'date', 'shift', 'employee_code', 'name'])->filter(fn ($c) => $columns['show_'.$c])->count()) }}">Tổng cộng</th>
                                <th class="text-right show_order_count {{ $an('order_count') }}">{{ $tien($tong['order_count'] ?? 0) }}</th>
                                <th class="text-right show_revenue {{ $an('revenue') }}">{{ $tien($tong['revenue'] ?? 0) }}</th>
                                <th class="text-right show_avg_order {{ $an('avg_order') }}">{{ $tien($tong['avg_order'] ?? 0) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- Một biểu đồ của tab staff (v2). --}}
            <div class="row {{ $dangBieuDo ? '' : 'd-none' }}" data-dang="chart">
                <div class="col-12 mb-4">
                    <div class="chart-container dt-the">
                        <div class="dt-dau">
                            <div class="chart-title">Báo cáo doanh thu nhân viên</div>
                            <div class="nv-nut">
                                <button type="button" class="nv-vien" id="nvDao" title="Đảo thứ tự: nhiều tiền trước / ít tiền trước">
                                    <i class="fa-solid fa-arrow-down-wide-short"></i>
                                </button>
                                <div class="nv-vien">Tổng số đơn hàng ({{ $tien($tong['order_count'] ?? 0) }})</div>
                            </div>
                        </div>
                        <div class="dt-ve" style="background: rgba(255, 240, 240, 1);"><canvas id="nvCot"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0));
            let bieuDo = null;
            // Nút đảo chỉ đổi thứ tự vẽ, không hỏi lại máy chủ.
            let itTruoc = false;

            function ve() {
                const o = document.getElementById('nvCot');
                if (!o) return;
                let ds = JSON.parse(document.getElementById('nv-du-lieu')?.textContent || '[]');
                if (itTruoc) ds = ds.slice().reverse();

                if (bieuDo) bieuDo.destroy();
                bieuDo = new Chart(o, {
                    data: {
                        labels: ds.map((n) => n.name || '—'),
                        datasets: [
                            {
                                type: 'bar', label: 'Doanh thu thực', data: ds.map((n) => n.revenue), yAxisID: 'y',
                                backgroundColor: 'rgba(124, 110, 246, 0.6)', borderRadius: 4, maxBarThickness: 70, order: 2,
                            },
                            {
                                type: 'line', label: 'Giá trị đơn TB', data: ds.map((n) => n.avg_order), yAxisID: 'y1',
                                borderColor: '#ff8a80', backgroundColor: '#ff8a80', borderWidth: 2, pointRadius: 4, order: 1,
                            },
                        ],
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12 } },
                            tooltip: { callbacks: {
                                label: (x) => `${x.dataset.label}: ${tien(x.parsed.y)} đ`,
                                afterBody: (x) => `Số đơn: ${tien(ds[x[0].dataIndex].order_count)}`,
                            } },
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: (v) => tien(v) } },
                            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: (v) => tien(v) } },
                        },
                    },
                });
            }

            $(document).on('click', '#nvDao', function () {
                itTruoc = !itTruoc;
                $(this).find('i').attr('class', 'fa-solid ' + (itTruoc ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-wide-short'));
                ve();
            });

            const dangBieuDo = () => $('input[name="show"]:checked').val() === 'chart';
            $(function () { if (dangBieuDo()) ve(); });
            // Chart.js đo khung lúc dựng — dựng khi khung đang ẩn là ra 0×0, nên
            // chỉ vẽ khi dạng Biểu đồ đang hiện.
            $(document).on('tk:dang', (e, dang) => { if (dang === 'chart') ve(); });
            $(document).on('v2:da-nap', () => { if (dangBieuDo()) { $('[data-dang="chart"]').removeClass('d-none'); $('[data-dang="table"]').addClass('d-none'); itTruoc = false; ve(); } });
        })();
    </script>
@endpush
