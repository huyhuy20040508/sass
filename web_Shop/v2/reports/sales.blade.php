{{-- Báo cáo → Báo cáo cuối ngày, tab Doanh thu — tab `sales` của báo cáo cuối
     ngày v2 (HTML bản đang chạy trả về cho end-day/list?type=sales, chart
     tab=revenue và load-data-modal). Khung trang ở v2::reports.end-day.

     Phần riêng của tab:
       - ô lọc Phương thức thanh toán (QR tự động / Quẹt thẻ / Chuyển khoản / Tiền mặt);
       - Danh sách: mỗi NGÀY một dòng, 10 cột tiền có sắp xếp, chân bảng
         "Tổng cộng (n)" nền vàng; bấm vào ngày → hộp "Chi tiết báo cáo bán hàng";
       - Biểu đồ: doanh thu theo ngày / giờ / thứ / tháng.

     Khác v2:
       - Tiền trả hàng để cột riêng, KHÔNG trừ vào hai cột doanh thu — mọi báo cáo
         khác của khu này cũng không trừ, trừ ở đây là hai trang hai số.
       - Ô Chuyển khoản / Quẹt thẻ / QR của v2 bấm được để xem theo từng tài khoản
         ngân hàng; bên mình chưa quản lý tài khoản ngân hàng nên không bấm.
       - Biểu đồ theo ngày trải theo từng NGÀY của kỳ (v2 gộp theo số ngày trong
         tháng 1…31 nên kỳ dài hai tháng là hai ngày mồng 1 cộng dồn vào nhau).
       - Tiền in dấu chấm ngăn nghìn như mọi màn khác của khu này.

     Dữ liệu do SalesReportController::index đẩy sang: $ngay, $tong, $bieuDo,
     $filters, $columns, $chiNhanh. --}}
@extends('v2::reports.end-day')

@php
    $tabDangXem = 'sales';
    $C = \App\Http\Controllers\SalesReportController::class;
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

    // Cột "Khác" (cod / vnpay / momo của đơn web) chỉ bày khi kỳ có tiền ấy —
    // không có nó thì các cột hình thức cộng lại không ra "Đã thanh toán".
    $coKhac = (float) ($tong['other'] ?? 0) > 0;
    $tienCot = array_slice(array_keys($C::COT), 1);
@endphp

@push('styles')
    <style>
        .handle-data-modal { cursor: pointer; font-weight: bold; color: rgb(82, 128, 189); }
        .dt-the { padding: 0 !important; overflow: hidden; height: 100%; }
        .dt-the .dt-dau { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; gap: 8px; }
        /* Tiêu đề kèm tổng tiền giữ MỘT dòng như v2 — bẻ đôi thì "VND)" rơi xuống dòng dưới. */
        .dt-the .chart-title { margin: 0; font-size: 17px; white-space: nowrap; }
        .dt-the .dt-vien { background: #f1f3f5; border-radius: 999px; font-weight: 600; min-width: 90px; text-align: center; }
        .dt-the .dt-ve { position: relative; height: 300px; padding: 18px 22px; }
        #modalChiTietNgay .modal-dialog { max-width: 1300px; }
        #modalChiTietNgay thead th { background-color: #d3daeb !important; white-space: nowrap; }
        #modalChiTietNgay tr.tr-first td { background-color: #DAFFE2 !important; font-weight: bold; }
        #modalChiTietNgay td { white-space: nowrap; }
    </style>
    <link href="{{ asset('v2/css/chart-report.css') }}" rel="stylesheet">
@endpush

@section('co-bieu-do', '1')

@push('nut-loc-them')
    @include('v2::partials.filter-button-mobile', [
        'dataBsTarget' => 'offcanvasBottomInMobile',
        'dataOffcanvasTarget' => 'filterPaymentMethod',
        'modalLabel' => 'Phương thức thanh toán',
    ])
@endpush

@section('loc-cuoi')
    {{-- Phương thức thanh toán: tích hết = mọi hình thức (kể cả thu hộ của đơn web). --}}
    <div id="filterPaymentMethod" class="mb-3" data-nhom="methods">
        <div class="inner-modal-in-mobile">
            <span class="title_search">Phương thức thanh toán</span>
            <div class="form-check mt-2">
                <input class="me-2 form-check-input nhom-het" type="checkbox" id="ht_all" {{ $filters['methods'] === '' ? 'checked' : '' }}>
                <label class="form-check-label label-custom" for="ht_all">{{ __('message.all') }}</label>
            </div>
            @php($daChon = array_filter(explode(',', $filters['methods'])))
            @foreach ($C::HINH_THUC as $ma => $nhan)
                <div class="form-check">
                    <input class="me-2 form-check-input nhom-muc" type="checkbox" value="{{ $ma }}" id="ht_{{ $ma }}"
                        {{ ! $daChon || in_array($ma, $daChon, true) ? 'checked' : '' }}>
                    <label class="form-check-label label-custom" for="ht_{{ $ma }}">{{ $nhan }}</label>
                </div>
            @endforeach
        </div>
    </div>
@endsection

@section('noi-dung')
    <div class="row mt-3 list-by-nav">
        <div class="d-flex justify-content-end flex-wrap gap-2 align-items-start {{ $dangBieuDo ? 'd-none' : '' }}" data-dang="table">
            <div class="d-flex settting-col-and-file-export-container">
                <a class="btn btn-sm d-flex align-items-center btn-export" id="tk-xuat"
                    href="{{ route('admin.reports.sales', array_merge(request()->query(), ['xuat' => 'excel'])) }}">
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
            <script type="application/json" id="dt-du-lieu">@json($bieuDo)</script>

            <div class="table-responsive {{ $dangBieuDo ? 'd-none' : '' }}" data-dang="table">
                <table class="table table-bordered mt-2 bang-cuoi-ngay bang-doanh-thu">
                    <thead>
                        <tr>
                            <th class="show_stt {{ $an('stt') }}">STT</th>
                            @foreach ($C::COT as $khoa => $nhan)
                                <th class="show_{{ $khoa }} {{ $an($khoa) }}">{{ $nhan }} {!! $nutSap($khoa) !!}</th>
                            @endforeach
                            @if ($coKhac)
                                <th title="Thu hộ (COD) / VNPay / MoMo của đơn web">Khác</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ngay as $i => $d)
                            <tr class="item">
                                <td class="show_stt {{ $an('stt') }}">{{ $i + 1 }}</td>
                                <td class="show_date {{ $an('date') }}">
                                    <div class="handle-data-modal" data-date="{{ $d['date'] }}">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d-m-Y') }}</div>
                                </td>
                                @foreach ($tienCot as $khoa)
                                    <td class="text-right show_{{ $khoa }} {{ $C::MAU[$khoa] ?? '' }} {{ $an($khoa) }}">{{ $tien($d[$khoa] ?? 0) }}</td>
                                @endforeach
                                @if ($coKhac)
                                    <td class="text-right">{{ $tien($d['other'] ?? 0) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="tk-rong">Kỳ này chưa có doanh thu.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($ngay)
                        <tfoot>
                            <tr>
                                {{-- Hai ô đầu (STT, Thời gian) gộp thành nhãn, trừ đi cột đang ẩn. --}}
                                <th colspan="{{ max(1, collect(['stt', 'date'])->filter(fn ($c) => $columns['show_'.$c])->count()) }}">Tổng cộng ({{ count($ngay) }})</th>
                                @foreach ($tienCot as $khoa)
                                    <th class="text-right show_{{ $khoa }} {{ $C::MAU[$khoa] ?? '' }} {{ $an($khoa) }}">{{ $tien($tong[$khoa] ?? 0) }}</th>
                                @endforeach
                                @if ($coKhac)
                                    <th class="text-right">{{ $tien($tong['other'] ?? 0) }}</th>
                                @endif
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- Bốn biểu đồ của tab revenue (v2), cùng màu nền từng khung.

                 Nhãn có "(VAT)" vì cột vẽ ra là SUM(orders.total_amount) — tiền ĐÃ
                 gồm thuế (xem Buckets bên API). Khu báo cáo này gọi tên nhất quán:
                 "Doanh thu" là chưa thuế, "Doanh thu (VAT)" là đã gồm. Nhãn cũ ghi
                 "Doanh thu theo ngày" nên cột 29/09 ra 37.884.000 trong khi cột
                 "Tổng Doanh Thu" của bảng ghi 34.440.000 — hai chỗ cùng tên mà hai
                 số, người xem đối chiếu chỉ biết là một trong hai sai.

                 Sửa nhãn chứ không đổi số vẽ: con số trong tiêu đề vốn đã là
                 $tong['revenue_vat'], đúng bằng tổng các cột. --}}
            <div class="row {{ $dangBieuDo ? '' : 'd-none' }}" data-dang="chart">
                @foreach ([
                    ['dtNgay', 'Doanh thu (VAT) theo ngày', 'rgba(255, 244, 244, 1)'],
                    ['dtGio', 'Doanh thu (VAT) theo giờ', 'rgba(255, 251, 235, 1)'],
                    ['dtThu', 'Doanh thu (VAT) theo thứ', 'rgba(255, 244, 251, 1)'],
                    ['dtThang', 'Doanh thu (VAT) theo tháng', 'rgba(244, 240, 255, 1)'],
                ] as [$id, $tieuDe, $nen])
                    <div class="col-12 col-xxl-6 mb-4">
                        <div class="chart-container dt-the">
                            <div class="dt-dau">
                                <div class="chart-title">{{ $tieuDe }} (<span class="text-success">{{ $tien($tong['revenue_vat'] ?? 0) }} VND</span>)</div>
                                <div class="px-3 py-2 dt-vien">-</div>
                            </div>
                            <div class="dt-ve" style="background: {{ $nen }};"><canvas id="{{ $id }}"></canvas></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Hộp "Chi tiết báo cáo bán hàng" — khuôn load-data-modal của v2. --}}
    <div class="modal fade" id="modalChiTietNgay" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="message-time-wrapper p-2 mb-0" id="ctnTieuDe"></h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead>
                                <tr>
                                    @foreach (['STT', 'Mã hóa đơn', 'Nguồn đơn', 'Phương thức thanh toán', 'Số lượng Sp', 'Tạm tính',
                                        'Phụ thu', 'Giảm giá', 'Doanh thu', 'VAT', 'Doanh thu (VAT)', 'Đã thanh toán', 'Còn nợ',
                                        'Trả hàng', 'Mã khách hàng', 'Tên khách hàng', 'Số điện thoại'] as $nhan)
                                        <th>{{ $nhan }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody id="ctnThan"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function () {
            const URL_CHI_TIET = @json(route('admin.reports.sales.orders'));
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0));
            const thoat = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            })[c]);

            // ================= Bốn biểu đồ =================
            const bieuDo = {};
            const THU = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ nhật'];

            function ve(id, kieu, nhan, so, mau, chuThich) {
                const o = document.getElementById(id);
                if (!o) return;
                if (bieuDo[id]) bieuDo[id].destroy();
                const duong = kieu === 'line';
                bieuDo[id] = new Chart(o, {
                    type: kieu,
                    data: {
                        labels: nhan,
                        datasets: [{
                            data: so.map((m) => m.revenue), borderColor: mau, borderWidth: 2,
                            backgroundColor: duong ? mau + '33' : mau + 'aa', fill: id === 'dtGio',
                            tension: duong ? 0.35 : 0, pointRadius: duong ? 3 : 0, borderRadius: 6,
                        }],
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: {
                                title: (x) => chuThich ? chuThich(x[0].dataIndex) : x[0].label,
                                label: (x) => `${tien(x.parsed.y)} đ · ${so[x.dataIndex].orders} đơn`,
                            } },
                        },
                        scales: { y: { beginAtZero: true, ticks: { callback: (v) => tien(v) } } },
                    },
                });
            }

            function veHet() {
                const du = JSON.parse(document.getElementById('dt-du-lieu')?.textContent || '{}');
                const ngay = du.ngay || [];
                const dmy = (k) => k.slice(8, 10) + '/' + k.slice(5, 7);
                ve('dtNgay', 'line', ngay.map((m) => dmy(m.key)), ngay, '#52c41a', (i) => ngay[i].key.split('-').reverse().join('-'));
                ve('dtGio', 'line', (du.gio || []).map((m) => m.key), du.gio || [], '#fa8c16', (i) => i + 'h');
                ve('dtThu', 'bar', THU, du.thu || [], '#5cc97b');
                ve('dtThang', 'bar', (du.thang || []).map((m) => 'T' + m.key), du.thang || [], '#c9b46b');
            }

            const dangBieuDo = () => $('input[name="show"]:checked').val() === 'chart';
            $(function () { if (dangBieuDo()) veHet(); });
            // Chart.js đo khung lúc dựng — dựng khi khung đang ẩn là ra 0×0, nên
            // chỉ vẽ khi dạng Biểu đồ đang hiện.
            $(document).on('tk:dang', (e, dang) => { if (dang === 'chart') veHet(); });
            $(document).on('v2:da-nap', () => { if (dangBieuDo()) { $('[data-dang="chart"]').removeClass('d-none'); $('[data-dang="table"]').addClass('d-none'); veHet(); } });

            // ================= Hộp chi tiết của một ngày =================
            $(document).on('click', '.handle-data-modal', function () {
                const q = new URLSearchParams(location.search);
                ['hide', 'sort_field', 'sort_type', 'show', 'xuat'].forEach((k) => q.delete(k));
                q.set('date', $(this).data('date'));

                $('#ctnTieuDe').text('Đang tải…');
                $('#ctnThan').html('<tr><td colspan="17" class="text-center py-4">Đang tải…</td></tr>');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChiTietNgay')).show();

                $.ajax({ url: URL_CHI_TIET + '?' + q, headers: { Accept: 'application/json' } })
                    .done(function (r) {
                        const ds = r.data || [];
                        $('#ctnTieuDe').html(thoat(r.tieu_de) + (r.chi_nhanh ? ' - <strong>' + thoat(r.chi_nhanh) + '</strong>' : ''));
                        const cong = (k) => ds.reduce((s, h) => s + (Number(h[k]) || 0), 0);
                        let html = '<tr class="tr-first"><td colspan="4">Tổng cộng: ' + ds.length + ' hóa đơn</td>'
                            + '<td>' + tien(cong('units')) + '</td>'
                            + ['subtotal', 'surcharge', 'discount', 'revenue', 'vat', 'total', 'paid']
                                .map((k) => '<td class="text-right">' + tien(cong(k)) + '</td>').join('')
                            + '<td class="text-right text-danger">' + tien(cong('debt')) + '</td>'
                            + '<td class="text-right text-danger">' + tien(cong('returns')) + '</td>'
                            + '<td></td><td></td><td></td></tr>';
                        ds.forEach(function (h, i) {
                            html += '<tr><td>' + (i + 1) + '</td><td><b>' + thoat(h.code) + '</b></td>'
                                + '<td>' + thoat(h.nguon) + '</td><td>' + thoat(h.hinh_thuc) + '</td>'
                                + '<td>' + tien(h.units) + '</td>'
                                + ['subtotal', 'surcharge', 'discount', 'revenue', 'vat', 'total', 'paid']
                                    .map((k) => '<td class="text-right">' + tien(h[k]) + '</td>').join('')
                                + '<td class="text-right text-danger">' + tien(h.debt) + '</td>'
                                + '<td class="text-right text-danger">' + tien(h.returns) + '</td>'
                                + '<td>' + thoat(h.customer_code || '-') + '</td><td>' + thoat(h.customer_name || '-')
                                + '</td><td>' + thoat(h.phone || '-') + '</td></tr>';
                        });
                        if (!ds.length) html = '<tr><td colspan="17" class="text-center py-4">Ngày này không có hoá đơn nào khớp bộ lọc.</td></tr>';
                        $('#ctnThan').html(html);
                    })
                    .fail(function (xhr) {
                        const cau = (xhr.responseJSON && xhr.responseJSON.message) || 'Không tải được hoá đơn của ngày.';
                        $('#ctnTieuDe').text('Chi tiết báo cáo bán hàng');
                        $('#ctnThan').html('<tr><td colspan="17" class="text-center text-danger py-4">' + thoat(cau) + '</td></tr>');
                    });
            });
        })();
    </script>
@endpush
