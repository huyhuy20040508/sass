{{-- Báo cáo → Báo cáo tổng hợp — dựng theo tab "Báo cáo tổng hợp" của báo cáo
     cuối ngày bản v2 (report/revenue-report/index + table/total + chart/summary).

     Khuôn trang, đúng như bản đang chạy:
       ┌ khung lọc trái: Chi nhánh · Thời gian (MỘT ngày) · Nguồn đơn
       └ nội dung phải: một CARD, đầu card là cặp nút Biểu đồ | Danh sách
           Biểu đồ : hai vòng tròn Thu/Chi và Tổng kết bán hàng, dưới là đường
                     Doanh thu theo giờ
           Danh sách: bảng 4 cột Thu/Chi · Tổng kết bán hàng · Tổng hàng hoá ·
                      Số món trả lại, hàng cuối là bốn con số tổng

     Khác v2 một chỗ: bảng và biểu đồ đọc CÙNG một nguồn số. Bản gốc tính hai
     đường riêng nên cùng một ngày mà tiền mặt ở bảng 94.500, ở biểu đồ 121.750.

     Dữ liệu do SummaryReportController::index đẩy sang: $so, $filters, $chiNhanh. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\SummaryReportController::TITLE)

@php
    $C = \App\Http\Controllers\SummaryReportController::class;
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = \Illuminate\Support\Carbon::parse($filters['date'])->format('d-m-Y');

    // Chi nhánh đang chọn để in lên ô lọc và hộp ngày: chưa khai là chi nhánh
    // đang làm việc, '0' là cả cửa hàng.
    $dangChon = $filters['shop_id'] === '' ? (string) (\App\Services\CurrentBranch::danhSach()['dangChon'] ?? '') : $filters['shop_id'];
    $tenChiNhanh = $dangChon === '0'
        ? 'Tất cả chi nhánh'
        : (collect($chiNhanh)->firstWhere('id', (int) $dangChon)['name'] ?? '');
@endphp

@push('styles')
    <link href="{{ asset('v2/css/chart-report.css') }}" rel="stylesheet">
    <style>
        #header_title_info { background-color: #BED5FF; border-radius: 12px; font-size: 14px; }

        /* Hai khối vòng tròn + khối đường, màu nền đúng bản v2. */
        .th-the { padding: 0 !important; overflow: hidden; height: 100%; }
        .th-the .th-dau { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; gap: 8px; }
        .th-the .chart-title { margin: 0; font-size: 17px; }
        .th-the .badge_info { background: #f1f3f5; border-radius: 999px; font-weight: 600; font-size: 13px; white-space: nowrap; }
        .th-ngoai-loc { margin: 0; padding: 0 10px 8px; font-size: 12.5px; color: #8a6d3b; }
        .th-nen { padding: 18px 22px; }
        .th-nen .th-ve { position: relative; height: 300px; }
        .th-rong { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #8c8c8c; }

        /* Bảng 4 cột của dạng Danh sách — khuôn table/total của v2: mỗi ô là
           một lưới 2 cột nhãn / số, hàng cuối là bốn con số tổng. */
        table.th-bang { width: 100%; border-collapse: collapse; text-align: center; }
        table.th-bang th { background: #e2e3e5; padding: 10px; font-weight: 600; white-space: nowrap; border: 1px solid #dee2e6; }
        table.th-bang td { border: 1px solid #dee2e6; vertical-align: middle; padding: 8px; }
        .th-luoi { display: grid; grid-template-columns: auto auto; column-gap: 12px; row-gap: 8px; text-align: left; align-items: end; }
        .th-luoi span { border-bottom: 1px solid #d5d5d5; padding: 2px 0; white-space: nowrap; }
        .th-luoi span.nhan { color: #1a2a57; font-weight: 700; }
        .th-luoi span.so { text-align: center; }
        table.th-bang td.th-tong { font-size: 14px; }
        table.th-bang td.th-tong b { display: block; font-size: 18px; }
    </style>
@endpush

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @foreach (['filterBranch' => __('message.branch'), 'filterTime' => __('message.time'), 'filterChannel' => 'Nguồn đơn'] as $oLoc => $nhan)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => $oLoc,
                    'modalLabel' => $nhan,
                ])
            @endforeach
        </div>
    </div>

    <div class="row index-end-day-report-page">
        {{-- ===================== Khung lọc trái ===================== --}}
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <div id="filterBranch" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.branch') }}</label>
                                <select name="shop_id" class="form-select">
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}" {{ $dangChon === (string) $cn['id'] ? 'selected' : '' }}>{{ $cn['name'] }}</option>
                                    @endforeach
                                    <option value="0" {{ $dangChon === '0' ? 'selected' : '' }}>Tất cả chi nhánh</option>
                                </select>
                            </div>
                        </div>

                        {{-- MỘT ngày, như v2 — báo cáo cuối ngày. --}}
                        <div id="filterTime" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.time') }}</label>
                                <input type="text" name="date" id="th-ngay" class="form-control" autocomplete="off"
                                    value="{{ $ngayVN }}" placeholder="dd-mm-yyyy">
                            </div>
                        </div>

                        {{-- Nguồn đơn: tích cả hai = mọi nguồn. Không cho bỏ tích hết —
                             "không nguồn nào" là một báo cáo rỗng chẳng ai cần. --}}
                        <div id="filterChannel" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search">Nguồn đơn</span>
                                <div class="form-check mt-2">
                                    <input class="me-2 form-check-input th-nguon-het" type="checkbox" id="th_nguon_all"
                                        {{ $filters['channel'] === '' ? 'checked' : '' }}>
                                    <label class="form-check-label label-custom" for="th_nguon_all">{{ __('message.all') }}</label>
                                </div>
                                @foreach ($C::NGUON as $ma => $nhan)
                                    <div class="form-check">
                                        <input class="me-2 form-check-input th-nguon" type="checkbox" value="{{ $ma }}" id="th_nguon_{{ $ma }}"
                                            {{ $filters['channel'] === '' || $filters['channel'] === $ma ? 'checked' : '' }}>
                                        <label class="form-check-label label-custom" for="th_nguon_{{ $ma }}">{{ $nhan }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== Nội dung phải ===================== --}}
        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="card">
                <div class="card-header card-header-primary header_search">
                    <div class="d-flex flex-wrap gap-3">
                        <div>
                            <input class="me-2 form-check-input" value="chart" type="radio" name="show" id="show-chart"
                                {{ $filters['show'] === 'chart' ? 'checked' : '' }}>
                            <label class="form-check-label" for="show-chart">{{ __('message.chart') }}</label>
                        </div>
                        <div>
                            <input class="me-2 form-check-input" value="table" type="radio" name="show" id="show-table"
                                {{ $filters['show'] === 'table' ? 'checked' : '' }}>
                            <label class="form-check-label" for="show-table">Danh sách</label>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center mb-3">
                        <div>
                            <h1 class="fw-bold tieu-de-trang mb-0">{{ $C::TITLE }}</h1>
                            <div class="text-sm text-secondary mt-2">Tổng hợp doanh thu, chi phí &amp; hàng hoá trong ngày làm việc.</div>
                        </div>
                        <div id="header_title_info" class="p-2 px-3 my-2 my-sm-0 text-center">
                            Ngày {{ $ngayVN }}@if ($tenChiNhanh !== '') · ({{ $tenChiNhanh }})@endif
                        </div>
                    </div>

                    {{-- ---------- Dạng BIỂU ĐỒ ---------- --}}
                    <div id="th-bieu-do" class="row {{ $filters['show'] === 'chart' ? '' : 'd-none' }}">
                        <div class="col-12 col-xxl-6 mb-4">
                            <div class="chart-container th-the">
                                <div class="th-dau">
                                    <div class="chart-title">Thu / Chi (<span class="text-success">{{ $tien($so['quy_thu']) }} / {{ $tien($so['quy_chi']) }} VND</span>)</div>
                                    <div class="px-3 py-2 badge_info">Quỹ tiền mặt</div>
                                </div>
                                @include('v2::reports._thu-chi-ngoai-loc', ['channel' => $filters['channel']])
                                <div class="th-nen" style="background: rgba(255, 244, 251, 1);">
                                    <div class="th-ve"><canvas id="thThuChi"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-xxl-6 mb-4">
                            <div class="chart-container th-the">
                                <div class="th-dau">
                                    <div class="chart-title">Tổng kết bán hàng (<span class="text-success">{{ $tien($so['doanh_thu']) }} VND</span>)</div>
                                    <div class="px-3 py-2 badge_info">Số hoá đơn ({{ $so['so_don'] }} đơn hàng)</div>
                                </div>
                                <div class="th-nen" style="background: rgba(255, 251, 235, 1);">
                                    <div class="th-ve"><canvas id="thBanHang"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 mb-4">
                            <div class="chart-container th-the">
                                <div class="th-dau" style="padding: 16px 20px;">
                                    <div class="chart-title">Doanh thu theo giờ</div>
                                    <div class="px-3 py-2 badge_info">Doanh thu theo giờ</div>
                                </div>
                                <div class="th-nen" style="background: rgba(255, 244, 254, 1);">
                                    <div class="th-ve"><canvas id="thTheoGio"></canvas></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ---------- Dạng DANH SÁCH ---------- --}}
                    <div id="th-danh-sach" class="table-responsive {{ $filters['show'] === 'table' ? '' : 'd-none' }}">
                        <table class="th-bang">
                            <thead>
                                <tr>
                                    <th>Thu/Chi</th>
                                    <th>Tổng kết bán hàng</th>
                                    <th>Tổng hàng hoá</th>
                                    <th>Số món trả lại</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div class="th-luoi">
                                            <span class="nhan">Thu</span><span class="so">{{ $so['phieu_thu'] }}</span>
                                            <span class="nhan">Chi</span><span class="so">{{ $so['phieu_chi'] }}</span>
                                        </div>
                                        @include('v2::reports._thu-chi-ngoai-loc', ['channel' => $filters['channel']])
                                    </td>
                                    <td>
                                        <div class="th-luoi">
                                            <span class="nhan">Tổng số hoá đơn</span><span class="so">{{ $so['so_don'] }}</span>
                                            @foreach ($so['hinh_thuc'] as $ht)
                                                <span class="nhan">{{ $ht['nhan'] }}</span><span class="so">{{ $tien($ht['tien']) }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        <div class="th-luoi">
                                            <span class="nhan">Tổng số mặt hàng</span><span class="so">{{ $so['mat_hang'] }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="th-luoi">
                                            <span class="nhan">Số món trả lại</span><span class="so">{{ $so['tra_dong'] }}</span>
                                            <span class="nhan">Số lượng món trả lại</span><span class="so">{{ $so['tra_so_luong'] }}</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="th-tong">Tổng thu chi<b>{{ $tien($so['thu_chi']) }}đ</b></td>
                                    <td class="th-tong">Tổng kết bán hàng<b>{{ $tien($so['doanh_thu']) }}đ</b></td>
                                    <td class="th-tong">Tổng số lượng hàng hoá<b>{{ $tien($so['so_luong']) }} Hàng hoá</b></td>
                                    <td class="th-tong">Tổng tiền trả hàng<b>{{ $tien($so['tra_tien']) }}đ</b></td>
                                </tr>
                            </tbody>
                        </table>
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
            const SO = @json($so);
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(v || 0));

            // ================= Bộ lọc: đổi là nạp lại trang =================
            // Không dùng V2.napLai: trang này là biểu đồ, thay ruột một khối là
            // phải dựng lại cả ba biểu đồ — nạp lại cả trang gọn và chắc hơn.
            function locLai(doi) {
                const q = new URLSearchParams(location.search);
                Object.entries(doi).forEach(([k, v]) => (v === '' || v === null ? q.delete(k) : q.set(k, v)));
                location.href = location.pathname + '?' + q;
            }

            $(document).on('change', '.fillter-box [name="shop_id"]', function () { locLai({ shop_id: this.value }); });
            $('#th-ngay').daterangepicker({
                singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
                maxDate: moment(), locale: V2.lichVN(),
            }, function (start) {
                $('#th-ngay').val(start.format('DD-MM-YYYY'));
                locLai({ date: start.format('DD-MM-YYYY') });
            });

            function nguonDangChon() {
                const chon = $('.th-nguon:checked').map((i, e) => e.value).get();
                return chon.length === 1 ? chon[0] : '';
            }
            $(document).on('change', '.th-nguon-het', function () {
                // Bỏ tích "Tất cả" không có nghĩa gì — giữ nguyên, như không bấm.
                if (!this.checked) { this.checked = true; return; }
                $('.th-nguon').prop('checked', true);
                locLai({ channel: '' });
            });
            $(document).on('change', '.th-nguon', function () {
                if (!$('.th-nguon:checked').length) { this.checked = true; return; }
                locLai({ channel: nguonDangChon() });
            });

            // ================= Biểu đồ | Danh sách =================
            // Đổi dạng xem không gọi lại máy chủ — cùng một bộ số, chỉ ghi lên URL
            // để F5 hay gửi link vẫn mở đúng dạng.
            let daVe = false;
            function hienDang(dang) {
                $('#th-bieu-do').toggleClass('d-none', dang !== 'chart');
                $('#th-danh-sach').toggleClass('d-none', dang !== 'table');
                const q = new URLSearchParams(location.search);
                dang === 'chart' ? q.delete('show') : q.set('show', dang);
                history.replaceState(history.state, '', location.pathname + (q.toString() ? '?' + q : ''));
                // Chart.js đo khung lúc dựng — dựng khi khung đang ẩn là ra biểu đồ 0×0.
                if (dang === 'chart' && !daVe) { veHet(); daVe = true; }
            }
            $(document).on('change', 'input[name="show"]', function () { hienDang(this.value); });

            // ================= Ba biểu đồ =================
            const MAU = ['#8b7cf6', '#fb9b8f', '#40c4df', '#ffcd56', '#4bc0c0', '#c9cbcf', '#2ecc71'];

            // Chữ số tổng ở giữa vòng tròn, như bản v2.
            const chuGiua = {
                id: 'chuGiua',
                afterDraw(c, _a, o) {
                    if (!o || !o.text) return;
                    const { ctx, chartArea: k } = c;
                    ctx.save();
                    ctx.font = '700 22px Inter, sans-serif';
                    ctx.fillStyle = '#111';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(o.text, (k.left + k.right) / 2, (k.top + k.bottom) / 2);
                    ctx.restore();
                },
            };

            function tron(id, nhan, so, giua) {
                const o = document.getElementById(id);
                if (!o) return;
                if (!so.some((v) => v > 0)) {
                    o.insertAdjacentHTML('afterend', '<div class="th-rong">Ngày này chưa có số liệu</div>');
                    return;
                }
                new Chart(o, {
                    type: 'doughnut',
                    data: { labels: nhan, datasets: [{ data: so, backgroundColor: MAU, borderWidth: 1 }] },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '55%',
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                            tooltip: {
                                callbacks: {
                                    label: (x) => {
                                        const tong = x.dataset.data.reduce((a, b) => a + b, 0);
                                        return `${x.label}: ${tien(x.parsed)} đ (${(x.parsed * 100 / tong).toFixed(2)}%)`;
                                    },
                                },
                            },
                            chuGiua: { text: giua },
                        },
                    },
                    plugins: [chuGiua],
                });
            }

            function veHet() {
                tron('thThuChi', ['Phiếu thu', 'Phiếu chi'], [SO.quy_thu, SO.quy_chi], tien(SO.quy_thu - SO.quy_chi));

                // Hình thức không có tiền thì không vẽ lát — lát 0 chỉ làm rối chú thích.
                const ht = Object.values(SO.hinh_thuc).filter((h) => h.tien > 0);
                tron('thBanHang', ht.map((h) => h.nhan), ht.map((h) => h.tien), tien(SO.doanh_thu));

                const o = document.getElementById('thTheoGio');
                if (!o) return;
                new Chart(o, {
                    type: 'line',
                    data: {
                        labels: SO.gio.map((_g, h) => h + 'H'),
                        datasets: [{
                            label: 'Doanh thu', data: SO.gio.map((g) => g.tien),
                            borderColor: '#36a2eb', backgroundColor: '#fff', pointRadius: 4, pointBorderWidth: 2, tension: 0,
                        }],
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (x) => `${tien(x.parsed.y)} đ · ${SO.gio[x.dataIndex].don} đơn`,
                                },
                            },
                        },
                        scales: { y: { beginAtZero: true, ticks: { callback: (v) => tien(v) } } },
                    },
                });
            }

            if ($('input[name="show"]:checked').val() === 'chart') { veHet(); daVe = true; }
        })();
    </script>
@endpush
