{{-- Báo cáo → Báo cáo cuối ngày, tab Chi phí & lợi nhuận — tab `expense` của báo
     cáo cuối ngày v2 (HTML bản đang chạy trả về cho end-day/list?type=expense và
     chart tab=cost). Khung trang ở v2::reports.end-day.

     Phần riêng của tab:
       - ô lọc Hàng hóa (nhóm + một mặt hàng) dùng chung với tab Hàng hóa;
       - Danh sách: ô tìm tên/mã, mỗi MẶT HÀNG một dòng — kể cả hàng chưa bán được
         trong kỳ — 8 cột có sắp xếp, chân bảng hai dòng: "Tổng cộng" và "Tổng
         cộng Lợi nhuận";
       - Biểu đồ: giá bán / giá vốn / lợi nhuận theo mốc, chọn ngày / tuần / tháng,
         nút đảo thứ tự mốc.

     Khác v2:
       - Lợi nhuận và biên lãi ÂM được bày đúng số, chữ đỏ (v2 ép về 0 trên biểu đồ
         và luôn tô xanh) — bán lỗ là thứ người xem cần thấy nhất.
       - Mốc biểu đồ chỉ có ngày / tuần / tháng: kỳ dài nhất là hai năm, quý và
         năm không thêm được gì.
       - Tiền in dấu chấm ngăn nghìn như mọi màn khác của khu này.

     Dữ liệu do ProfitReportController::index đẩy sang: $dong, $tong, $bieuDo,
     $filters, $columns, $chiNhanh, $nhomHang, $dsHang. --}}
@extends('v2::reports.end-day')

@php
    $tabDangXem = 'expense';
    $C = \App\Http\Controllers\ProfitReportController::class;
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    // Biên lãi: tối đa hai số lẻ, bỏ số 0 thừa (30 chứ không 30,00).
    $phanTram = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $mau = fn ($n) => (float) $n < 0 ? 'text-danger' : 'text-success';
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

    $soCotHien = collect($columns)->filter()->count();
    $nhanGop = max(1, collect(['code', 'name', 'category_name'])->filter(fn ($c) => $columns['show_'.$c])->count());
@endphp

@push('styles')
    <style>
        .dt-the { padding: 0 !important; overflow: hidden; height: 100%; }
        .dt-the .dt-dau { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; gap: 8px; }
        .dt-the .chart-title { margin: 0; font-size: 17px; white-space: nowrap; text-transform: uppercase; font-weight: 600; }
        .dt-the .dt-ve { position: relative; height: 360px; padding: 18px 22px; }
        .ln-nut { display: flex; align-items: center; gap: 8px; }
        .ln-nut .ln-vien { background: #f1f3f5; border-radius: 999px; font-weight: 600; border: 0; padding: 6px 12px; }
        .ln-nut select.ln-vien { cursor: pointer; }
        .ln-nut button.ln-vien { color: #486a7f; }
        table.bang-loi-nhuan tfoot tr.ln-tong-lai th { font-weight: bold; }
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
                    href="{{ route('admin.reports.profit', array_merge(request()->query(), ['xuat' => 'excel'])) }}">
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
                                @foreach ($C::COT as $khoa => $nhan)
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
            <script type="application/json" id="ln-du-lieu">@json($bieuDo)</script>

            <div class="table-responsive {{ $dangBieuDo ? 'd-none' : '' }}" data-dang="table">
                <table class="table table-bordered mt-2 bang-cuoi-ngay bang-loi-nhuan">
                    <thead>
                        <tr>
                            @foreach ($C::COT as $khoa => $nhan)
                                <th class="show_{{ $khoa }} {{ $an($khoa) }}">{{ $nhan }} {!! $nutSap($khoa) !!}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($dong as $d)
                            <tr class="item">
                                <td class="text-left show_code {{ $an('code') }}">{{ ($d['code'] ?? '') !== '' ? $d['code'] : '-' }}</td>
                                <td class="text-left show_name {{ $an('name') }}">{{ $d['name'] ?? '' }}</td>
                                <td class="text-left show_category_name {{ $an('category_name') }}">{{ $d['category_name'] ?? '' }}</td>
                                <td class="text-right show_quantity {{ $an('quantity') }}">{{ $tien($d['quantity'] ?? 0) }}</td>
                                <td class="text-right show_revenue {{ $an('revenue') }}">{{ $tien($d['revenue'] ?? 0) }}</td>
                                <td class="text-right show_cost {{ $an('cost') }}">{{ $tien($d['cost'] ?? 0) }}</td>
                                <td class="text-right show_profit {{ (float) ($d['profit'] ?? 0) < 0 ? 'text-danger' : '' }} {{ $an('profit') }}">{{ $tien($d['profit'] ?? 0) }}</td>
                                <td class="text-right show_margin {{ $mau($d['margin'] ?? 0) }} {{ $an('margin') }}">{{ $phanTram($d['margin'] ?? 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="tk-rong">Không có mặt hàng nào khớp bộ lọc.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($dong)
                        <tfoot>
                            <tr>
                                {{-- Ba ô đầu (Mã, Tên, Danh mục) gộp thành nhãn, trừ đi cột đang ẩn. --}}
                                <th colspan="{{ $nhanGop }}">Tổng cộng</th>
                                <th class="text-right show_quantity {{ $an('quantity') }}">{{ $tien($tong['quantity'] ?? 0) }}</th>
                                <th class="text-right show_revenue {{ $an('revenue') }}">{{ $tien($tong['revenue'] ?? 0) }}</th>
                                <th class="text-right show_cost {{ $an('cost') }}">{{ $tien($tong['cost'] ?? 0) }}</th>
                                <th class="text-right show_profit {{ (float) ($tong['profit'] ?? 0) < 0 ? 'text-danger' : '' }} {{ $an('profit') }}">{{ $tien($tong['profit'] ?? 0) }}</th>
                                <th class="text-right show_margin {{ $mau($tong['margin'] ?? 0) }} {{ $an('margin') }}">{{ $phanTram($tong['margin'] ?? 0) }}</th>
                            </tr>
                            <tr class="ln-tong-lai">
                                <th colspan="{{ max(1, $soCotHien) }}" class="text-right">Tổng cộng Lợi nhuận: {{ $tien($tong['profit'] ?? 0) }}</th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            {{-- Một biểu đồ của tab cost (v2): ba cột bán / vốn / lãi mỗi mốc. --}}
            <div class="row {{ $dangBieuDo ? '' : 'd-none' }}" data-dang="chart">
                <div class="col-12 mb-4">
                    <div class="chart-container dt-the">
                        <div class="dt-dau">
                            <div class="chart-title">Báo cáo chi phí lợi nhuận hàng hóa (<span class="text-success">{{ $tien($tong['revenue'] ?? 0) }} VND</span>)</div>
                            <div class="ln-nut">
                                <button type="button" class="ln-vien" id="lnDao"
                                    title="{{ $filters['chart_sort'] === 'asc' ? 'Đang xem mốc cũ trước — bấm để xem mốc mới trước' : 'Đang xem mốc mới trước — bấm để xem mốc cũ trước' }}">
                                    <i class="fa-solid {{ $filters['chart_sort'] === 'asc' ? 'fa-arrow-up-short-wide' : 'fa-arrow-down-wide-short' }}"></i>
                                </button>
                                {{-- Chưa chọn mốc thì API tự chọn theo độ dài kỳ: ô chỉ
                                     BÀY mốc đó chứ không gửi lại (không data-loc), nếu
                                     không đổi từ "Tuần này" sang "Tháng trước" vẫn kẹt mốc cũ. --}}
                                <select name="group_by" class="ln-vien" aria-label="Mốc" {{ $filters['group_by'] !== '' ? 'data-loc' : '' }}>
                                    @foreach ($C::MOC as $ma => $nhan)
                                        <option value="{{ $ma }}" {{ $bieuDo['moc'] === $ma ? 'selected' : '' }}>{{ $nhan }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="dt-ve" style="background: rgba(252, 251, 226, 1);"><canvas id="lnCot"></canvas></div>
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

            // Nhãn mốc như v2: 05/05 (năm khác nhau thì 05/05/2026), W18/2026, T05/2026.
            function nhan(k, nhieuNam) {
                if (/^\d{4}-W\d{2}$/.test(k)) return 'W' + Number(k.slice(6)) + '/' + k.slice(0, 4);
                if (/^\d{4}-\d{2}$/.test(k)) return 'T' + k.slice(5, 7) + '/' + k.slice(0, 4);
                const ngay = k.slice(8, 10) + '/' + k.slice(5, 7);
                return nhieuNam ? ngay + '/' + k.slice(0, 4) : ngay;
            }

            function ve() {
                const o = document.getElementById('lnCot');
                if (!o) return;
                const du = JSON.parse(document.getElementById('ln-du-lieu')?.textContent || '{}');
                let cot = (du.cot || []).slice();
                if (new URLSearchParams(location.search).get('chart_sort') === 'desc') cot.reverse();
                const nhieuNam = new Set(cot.map((m) => m.key.slice(0, 4))).size > 1;

                if (bieuDo) bieuDo.destroy();
                bieuDo = new Chart(o, {
                    type: 'bar',
                    data: {
                        labels: cot.map((m) => nhan(m.key, nhieuNam)),
                        datasets: [
                            ['Tổng doanh thu', 'revenue', 'rgba(124, 110, 246, 0.85)'],
                            ['Giá vốn', 'cost', 'rgba(255, 145, 140, 0.85)'],
                            ['Lợi nhuận', 'profit', 'rgba(60, 195, 223, 0.85)'],
                        ].map(([ten, k, mau]) => ({
                            label: ten, data: cot.map((m) => m[k]), backgroundColor: mau,
                            borderRadius: 3, barPercentage: 0.8, categoryPercentage: 0.75, maxBarThickness: 28,
                        })),
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 12 } },
                            tooltip: { callbacks: { label: (x) => `${x.dataset.label}: ${tien(x.parsed.y)} đ` } },
                        },
                        scales: { y: { ticks: { callback: (v) => tien(v) } } },
                    },
                });
            }

            // Mốc: chọn tay là gửi đi và từ đó ô mang data-loc (giữ qua các lượt lọc sau).
            $(document).on('change', 'select[name="group_by"]', function () {
                locLaiCuoiNgay({ group_by: this.value });
            });

            // Nút đảo chỉ đổi thứ tự vẽ — không cần hỏi lại máy chủ.
            $(document).on('click', '#lnDao', function () {
                const q = new URLSearchParams(location.search);
                const moiTruoc = q.get('chart_sort') !== 'desc';
                moiTruoc ? q.set('chart_sort', 'desc') : q.delete('chart_sort');
                history.replaceState(history.state, '', location.pathname + '?' + q);
                $(this).find('i').attr('class', 'fa-solid ' + (moiTruoc ? 'fa-arrow-down-wide-short' : 'fa-arrow-up-short-wide'));
                ve();
            });

            const dangBieuDo = () => $('input[name="show"]:checked').val() === 'chart';
            $(function () { if (dangBieuDo()) ve(); });
            // Chart.js đo khung lúc dựng — dựng khi khung đang ẩn là ra 0×0, nên
            // chỉ vẽ khi dạng Biểu đồ đang hiện.
            $(document).on('tk:dang', (e, dang) => { if (dang === 'chart') ve(); });
            $(document).on('v2:da-nap', () => { if (dangBieuDo()) { $('[data-dang="chart"]').removeClass('d-none'); $('[data-dang="table"]').addClass('d-none'); ve(); } });
        })();
    </script>
@endpush
