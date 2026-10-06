{{-- Báo cáo → Báo cáo cuối ngày, tab Hàng hóa — tab `products` của báo cáo cuối
     ngày v2 (HTML bản đang chạy trả về cho end-day/list?type=products, chart
     tab=product và load-data-modal). Khung trang ở v2::reports.end-day.

     Phần riêng của tab:
       - ô lọc Hàng hóa: nhóm hàng + một mặt hàng (ô mặt hàng chỉ mở khi đã chọn
         nhóm, và chỉ còn hàng của nhóm đó — như v2);
       - Danh sách: ô tìm tên/mã, mỗi MẶT HÀNG một dòng, 5 cột có sắp xếp, chân
         bảng "Tổng cộng" nền vàng; bấm vào mã → hộp "Chi tiết hàng hóa";
       - Biểu đồ: top bán chạy theo số lượng (Top 5/10/15, đảo chiều được), số lượng
         theo giờ, theo thứ (từng món bán nhiều nhất), theo tháng.

     Khác v2:
       - Cột "Phòng/bàn" của hộp chi tiết thành "Nguồn đơn" — bên mình không có bàn.
       - Tiền in dấu chấm ngăn nghìn như mọi màn khác của khu này.

     Dữ liệu do GoodsReportController::index đẩy sang: $dong, $tong, $bieuDo,
     $filters, $columns, $chiNhanh, $nhomHang, $dsHang. --}}
@extends('v2::reports.end-day')

@php
    $tabDangXem = 'products';
    $C = \App\Http\Controllers\GoodsReportController::class;
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

    $tongTop = collect($bieuDo['top'])->sum('quantity');
@endphp

@push('styles')
    <style>
        .handle-data-modal { cursor: pointer; font-weight: bold; color: rgb(82, 128, 189); }
        .dt-the { padding: 0 !important; overflow: hidden; height: 100%; }
        .dt-the .dt-dau { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; gap: 8px; }
        .dt-the .chart-title { margin: 0; font-size: 17px; white-space: nowrap; }
        .dt-the .dt-vien { background: #f1f3f5; border-radius: 999px; font-weight: 600; min-width: 90px; text-align: center; }
        .dt-the .dt-ve { position: relative; height: 300px; padding: 18px 22px; }
        /* Pill "Top n" của v2: nút đảo chiều + ô chọn số món, không viền. */
        .hh-top { display: flex; align-items: center; gap: 4px; padding: 2px 10px !important; white-space: nowrap; }
        .hh-top select { border: 0; background: transparent; font-weight: 600; padding: 4px 2px; cursor: pointer; }
        .hh-top .hh-dao { border: 0; background: transparent; padding: 4px; color: #486a7f; }
        #modalChiTietHang .modal-dialog { max-width: 1100px; }
        #modalChiTietHang thead th { background-color: #d3daeb !important; white-space: nowrap; text-align: center; }
        #modalChiTietHang td { white-space: nowrap; }
        #modalChiTietHang tfoot td { font-weight: bold; }
    </style>
    <link href="{{ asset('v2/css/chart-report.css') }}" rel="stylesheet">
@endpush

@section('co-bieu-do', '1')

@section('loc-giua')
    @include('v2::reports.end-day-loc-hang')
@endsection

@section('noi-dung')
    <div class="row mt-3 list-by-nav">
        {{-- Hàng công cụ nằm NGOÀI `.list`: V2.napLai thay khối đó mỗi lượt lọc,
             ô tìm ở trong là gõ tới đâu mất chữ tới đó. --}}
        <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start {{ $dangBieuDo ? 'd-none' : '' }}" data-dang="table">
            <div class="col-12 col-md-4 search-bar-container">
                <input type="text" class="form-control search-bar mb-2" name="keyword" data-loc
                    value="{{ $filters['keyword'] }}" autocomplete="off" placeholder="Tìm theo tên/mã hàng hóa">
            </div>
            <div class="d-flex settting-col-and-file-export-container">
                <a class="btn btn-sm d-flex align-items-center btn-export" id="tk-xuat"
                    href="{{ route('admin.reports.goods', array_merge(request()->query(), ['xuat' => 'excel'])) }}">
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
            <script type="application/json" id="hh-du-lieu">@json($bieuDo)</script>

            <div class="table-responsive {{ $dangBieuDo ? 'd-none' : '' }}" data-dang="table">
                <table class="table table-bordered mt-2 bang-cuoi-ngay bang-hang-hoa">
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
                                <td class="text-left show_code {{ $an('code') }}">
                                    @if (($d['product_id'] ?? 0) > 0)
                                        <div class="handle-data-modal" data-id="{{ $d['product_id'] }}" data-name="{{ $d['name'] }}">{{ $d['code'] !== '' ? $d['code'] : '-' }}</div>
                                    @else
                                        {{-- Hàng đã xoá hẳn khỏi danh mục: không còn id để mở chi tiết. --}}
                                        <span class="text-muted" title="Mặt hàng đã bị xoá">{{ $d['code'] !== '' ? $d['code'] : '-' }}</span>
                                    @endif
                                </td>
                                <td class="text-left show_name {{ $an('name') }}">{{ $d['name'] }}</td>
                                <td class="text-right show_quantity {{ $an('quantity') }}">{{ $tien($d['quantity'] ?? 0) }}</td>
                                <td class="text-right show_amount {{ $an('amount') }}">{{ $tien($d['amount'] ?? 0) }}</td>
                                <td class="text-right show_total {{ $an('total') }}">{{ $tien($d['total'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="tk-rong">Kỳ này chưa bán mặt hàng nào.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($dong)
                        <tfoot>
                            <tr>
                                {{-- Ba ô đầu (STT, Mã, Tên) gộp thành nhãn, trừ đi cột đang ẩn. --}}
                                <th colspan="{{ max(1, collect(['stt', 'code', 'name'])->filter(fn ($c) => $columns['show_'.$c])->count()) }}">Tổng cộng</th>
                                <th class="text-right show_quantity {{ $an('quantity') }}">{{ $tien($tong['quantity'] ?? 0) }}</th>
                                <th class="text-right show_amount {{ $an('amount') }}">{{ $tien($tong['amount'] ?? 0) }}</th>
                                <th class="text-right show_total {{ $an('total') }}">{{ $tien($tong['total'] ?? 0) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- Bốn biểu đồ của tab product (v2), cùng màu nền từng khung. --}}
            <div class="row {{ $dangBieuDo ? '' : 'd-none' }}" data-dang="chart">
                <div class="col-12 col-xxl-6 mb-4">
                    <div class="chart-container dt-the">
                        <div class="dt-dau">
                            <div class="chart-title">Top hàng hóa bán chạy (<span class="text-success">{{ $tien($tongTop) }} sp</span>)</div>
                            <div class="px-3 py-2 dt-vien hh-top">
                                <input type="hidden" name="top_sort" value="{{ $filters['top_sort'] }}" data-loc>
                                <button type="button" class="hh-dao" id="hhDaoTop"
                                    title="{{ $filters['top_sort'] === 'desc' ? 'Đang xem bán chạy nhất — bấm để xem bán chậm nhất' : 'Đang xem bán chậm nhất — bấm để xem bán chạy nhất' }}">
                                    <i class="fa-solid {{ $filters['top_sort'] === 'desc' ? 'fa-arrow-down-wide-short' : 'fa-arrow-up-short-wide' }}"></i>
                                </button>
                                <select name="top" data-loc aria-label="Số món">
                                    @foreach ($C::MUC_TOP as $n)
                                        <option value="{{ $n }}" {{ $filters['top'] === (string) $n ? 'selected' : '' }}>Top {{ $n }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="dt-ve" style="background: rgba(252, 251, 226, 1);"><canvas id="hhTop"></canvas></div>
                    </div>
                </div>
                <div class="col-12 col-xxl-6 mb-4">
                    <div class="chart-container dt-the">
                        <div class="dt-dau">
                            <div class="chart-title">Số lượng bán theo giờ</div>
                            <div class="px-3 py-2 dt-vien">-</div>
                        </div>
                        <div class="dt-ve" style="background: rgba(255, 244, 244, 1);"><canvas id="hhGio"></canvas></div>
                    </div>
                </div>
                <div class="col-12 col-xxl-6 mb-4">
                    <div class="chart-container dt-the">
                        <div class="dt-dau">
                            <div class="chart-title">Số lượng bán theo thứ</div>
                            <div class="px-3 py-2 dt-vien hh-top">
                                <select name="top_weekday" data-loc aria-label="Số món">
                                    @foreach ($C::MUC_TOP as $n)
                                        <option value="{{ $n }}" {{ $filters['top_weekday'] === (string) $n ? 'selected' : '' }}>Top {{ $n }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="dt-ve" style="background: rgba(244, 248, 255, 1);"><canvas id="hhThu"></canvas></div>
                    </div>
                </div>
                <div class="col-12 col-xxl-6 mb-4">
                    <div class="chart-container dt-the">
                        <div class="dt-dau">
                            <div class="chart-title">Số lượng bán theo tháng</div>
                            <div class="px-3 py-2 dt-vien">-</div>
                        </div>
                        <div class="dt-ve" style="background: rgba(240, 250, 250, 1);"><canvas id="hhThang"></canvas></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Hộp "Chi tiết hàng hóa" — khuôn load-data-modal của v2. --}}
    <div class="modal fade" id="modalChiTietHang" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-title" id="cthTieuDe"></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="table-responsive">
                        <table class="table table-bordered mb-0">
                            <thead>
                                <tr>
                                    @foreach (['STT', 'Mã hóa đơn', 'Nguồn đơn', 'Mã hàng hóa', 'Tên hàng hóa', 'Số lượng Sp', 'Thành tiền'] as $nhan)
                                        <th>{{ $nhan }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody id="cthThan"></tbody>
                            <tfoot id="cthChan"></tfoot>
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
            const URL_CHI_TIET = @json(route('admin.reports.goods.orders'));
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0));
            const thoat = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            })[c]);

            // Hai ô Top và nút đảo chiều nằm trong khối biểu đồ (được nạp lại),
            // nên gắn qua document.
            $(document).on('change', '.hh-top select', () => locLaiCuoiNgay());
            $(document).on('click', '#hhDaoTop', function () {
                const $o = $('input[name="top_sort"]');
                $o.val($o.val() === 'asc' ? 'desc' : 'asc');
                locLaiCuoiNgay();
            });

            // ================= Bốn biểu đồ =================
            const bieuDo = {};
            const MAU = ['#7b61ff', '#f5697a', '#36b3a8', '#f0a33a', '#52c41a', '#1890ff', '#eb2f96', '#a0d911',
                '#13c2c2', '#fa541c', '#2f54eb', '#faad14', '#722ed1', '#8c8c8c', '#389e0d'];

            function ve(id, cauHinh) {
                const o = document.getElementById(id);
                if (!o) return;
                if (bieuDo[id]) bieuDo[id].destroy();
                bieuDo[id] = new Chart(o, cauHinh);
            }

            const khungSo = (dinhDang) => ({
                responsive: true, maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { precision: 0, callback: (v) => dinhDang(v) } } },
            });

            function duong(nhan, so, mau) {
                return {
                    type: 'line',
                    data: { labels: nhan, datasets: [{
                        data: so, borderColor: mau, backgroundColor: mau + '33', borderWidth: 2,
                        fill: true, cubicInterpolationMode: 'monotone', pointRadius: 3,
                    }] },
                    options: Object.assign(khungSo(tien), {
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: (x) => tien(x.parsed.y) + ' sp' } } },
                    }),
                };
            }

            function veHet() {
                const du = JSON.parse(document.getElementById('hh-du-lieu')?.textContent || '{}');

                const top = du.top || [];
                ve('hhTop', {
                    type: 'bar',
                    data: { labels: top.map((r) => r.name), datasets: [{
                        data: top.map((r) => r.quantity), backgroundColor: 'rgba(214, 226, 105, 0.85)', borderRadius: 4,
                    }] },
                    options: Object.assign(khungSo(tien), {
                        plugins: { legend: { display: false }, tooltip: { callbacks: {
                            label: (x) => `${tien(x.parsed.y)} sp · ${tien(top[x.dataIndex].total)} đ`,
                        } } },
                    }),
                });

                ve('hhGio', duong((du.gio || []).map((m) => m.key), (du.gio || []).map((m) => m.units), '#52c41a'));

                const thu = du.thu || [];
                ve('hhThu', {
                    type: 'line',
                    data: {
                        labels: ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'],
                        datasets: thu.map((s, i) => ({
                            label: s.name, data: s.data, borderColor: MAU[i % MAU.length],
                            backgroundColor: MAU[i % MAU.length], borderWidth: 2, cubicInterpolationMode: 'monotone', pointRadius: 3,
                        })),
                    },
                    options: Object.assign(khungSo(tien), {
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                            tooltip: { callbacks: { label: (x) => `${x.dataset.label}: ${tien(x.parsed.y)} sp` } },
                        },
                    }),
                });

                ve('hhThang', duong((du.thang || []).map((m) => 'T' + m.key), (du.thang || []).map((m) => m.units), '#7b61ff'));
            }

            const dangBieuDo = () => $('input[name="show"]:checked').val() === 'chart';
            $(function () { if (dangBieuDo()) veHet(); });
            // Chart.js đo khung lúc dựng — dựng khi khung đang ẩn là ra 0×0, nên
            // chỉ vẽ khi dạng Biểu đồ đang hiện.
            $(document).on('tk:dang', (e, dang) => { if (dang === 'chart') veHet(); });
            $(document).on('v2:da-nap', () => { if (dangBieuDo()) { $('[data-dang="chart"]').removeClass('d-none'); $('[data-dang="table"]').addClass('d-none'); veHet(); } });

            // ================= Hộp chi tiết của một mặt hàng =================
            $(document).on('click', '.handle-data-modal', function () {
                const q = new URLSearchParams(location.search);
                ['hide', 'sort_field', 'sort_type', 'show', 'xuat', 'keyword', 'top', 'top_sort', 'top_weekday'].forEach((k) => q.delete(k));
                q.set('product_id', $(this).data('id'));
                q.set('name', $(this).data('name'));

                $('#cthTieuDe').text('Đang tải…');
                $('#cthChan').empty();
                $('#cthThan').html('<tr><td colspan="7" class="text-center py-4">Đang tải…</td></tr>');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalChiTietHang')).show();

                $.ajax({ url: URL_CHI_TIET + '?' + q, headers: { Accept: 'application/json' } })
                    .done(function (r) {
                        const ds = r.data || [];
                        $('#cthTieuDe').text(r.tieu_de || 'Chi tiết hàng hóa');
                        if (!ds.length) {
                            $('#cthThan').html('<tr><td colspan="7" class="text-center py-4">Không có hoá đơn nào khớp bộ lọc.</td></tr>');
                            return;
                        }
                        $('#cthThan').html(ds.map((h, i) => '<tr><td class="text-center">' + (i + 1) + '</td>'
                            + '<td class="text-center"><b>' + thoat(h.code) + '</b></td>'
                            + '<td class="text-center">' + thoat(h.nguon) + '</td>'
                            + '<td class="text-center">' + thoat(h.item_code || '-') + '</td>'
                            + '<td class="text-center">' + thoat(h.item_name) + '</td>'
                            + '<td class="text-right">' + tien(h.quantity) + '</td>'
                            + '<td class="text-right">' + tien(h.total) + '</td></tr>').join(''));
                        const cong = (k) => ds.reduce((s, h) => s + (Number(h[k]) || 0), 0);
                        $('#cthChan').html('<tr><td colspan="5" class="text-center">Tổng cộng: ' + ds.length + ' hóa đơn</td>'
                            + '<td class="text-right">' + tien(cong('quantity')) + '</td>'
                            + '<td class="text-right">' + tien(cong('total')) + '</td></tr>');
                    })
                    .fail(function (xhr) {
                        const cau = (xhr.responseJSON && xhr.responseJSON.message) || 'Không tải được hoá đơn của mặt hàng.';
                        $('#cthTieuDe').text('Chi tiết hàng hóa');
                        $('#cthThan').html('<tr><td colspan="7" class="text-center text-danger py-4">' + thoat(cau) + '</td></tr>');
                    });
            });
        })();
    </script>
@endpush
