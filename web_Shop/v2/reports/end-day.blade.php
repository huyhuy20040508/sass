{{-- KHUNG CHUNG của trang Báo cáo → Báo cáo cuối ngày — chép khuôn
     report/revenue-report/index.blade.php của v2. Mỗi tab trong trang là một
     view kế thừa khung này (reports.sales, reports.customer).

     Khung lo phần giống nhau ở mọi tab:
       ┌ khung lọc trái: Chi nhánh · Thời gian (6 nút + Tuỳ chỉnh) · Nguồn đơn,
       │                 cộng ô lọc riêng của tab qua hai chỗ trống:
       │                   @section('loc-giua') — giữa Thời gian và Nguồn đơn
       │                   @section('loc-cuoi') — dưới Nguồn đơn
       └ nội dung phải: cặp nút Biểu đồ | Danh sách (Biểu đồ chỉ bấm được khi tab
                        có @section('co-bieu-do')), tiêu đề, hộp kỳ, dãy tab,
                        rồi @section('noi-dung') của tab.

     Lọc: đổi ô nào là lọc ngay, như v2. JS gom mọi ô mang `data-loc` và mọi nhóm
     ô tích mang `data-nhom`, nên tab thêm ô lọc chỉ cần gắn đúng thuộc tính.

     View con phải đẩy sang: $tabDangXem, $filters (kết quả
     ReportController::locCuoiNgay + phần riêng), $chiNhanh — và đặt
     @include('v2::reports.end-day-ky') vào TRONG khối `.list` của mình: thẻ đó
     mang kỳ thật server vừa xem, để sau mỗi lượt V2.napLai JS điền lại hai ô
     ngày và hộp kỳ (khung lọc không nằm trong khối được nạp lại). --}}
@extends('v2::layouts.master')

@section('title', 'Báo cáo cuối ngày')

@push('styles')
    <style>
        /* Dãy tab trong trang — khuôn `cus-nav-tabs` của v2: chữ đen, tab đang
           xem gạch chân xanh, không viền. */
        .cus-nav-tabs { border: 0 !important; }
        .cus-nav-tabs .nav-item .nav-link { color: #000; padding-inline: 10px; border: 0; cursor: pointer; }
        .cus-nav-tabs .nav-item .nav-link.active {
            border: 0 !important; border-bottom: 2px solid #0162df !important;
            color: #0162df !important; font-weight: bold;
        }
        .cus-nav-tabs .nav-item:hover { font-weight: bold; background-color: #fff; }
        /* Tab của màn CHƯA DỰNG: vẫn bày cho đủ khuôn v2 nhưng không bấm được —
           bấm vào rồi bị đá về trang khác thì tưởng bấm nhầm. */
        .cus-nav-tabs .nav-item .nav-link.chua-dung { color: #b6bcc7; cursor: not-allowed; font-weight: normal; }

        #header_title_info {
            background-color: #BED5FF; border-radius: 12px;
            max-width: 100%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        /* Sáu nút thời gian — `.time-label` của v2. */
        .time-label {
            display: block; padding: 4px 2px; border: 1px solid #ddd; border-radius: 6px;
            cursor: pointer; text-align: center; transition: all .2s ease; letter-spacing: -.75px;
        }
        .selectTime:checked + .time-label { background-color: #486a7f; border-color: #486a7f; color: #fff; }

        /* Bảng: `table table-bordered` của v2, chân bảng nền vàng. Ô không bẻ dòng —
           bảng dài thì cuộn ngang trong khung. */
        table.bang-cuoi-ngay th, table.bang-cuoi-ngay td { white-space: nowrap; vertical-align: middle; }
        table.bang-cuoi-ngay thead th { background: #f2f2f2; }
        table.bang-cuoi-ngay .btn_sort_table { cursor: pointer; margin-left: 5px; color: inherit; text-decoration: none; }
        table.bang-cuoi-ngay .btn_sort_table.dang-sap { color: #0162df; }
        table.bang-cuoi-ngay tfoot th { background-color: #F6FFCBFC !important; padding: 10px; }
        .tk-rong { padding: 28px 0; text-align: center; color: #8c8c8c; }
    </style>
@endpush

@php
    $CR = \App\Http\Controllers\ReportController::class;
    $ngayVN = fn ($s) => \Illuminate\Support\Carbon::parse($s)->format('d-m-Y');

    $dangChonCN = $filters['shop_id'] !== '' ? $filters['shop_id'] : (string) (\App\Services\CurrentBranch::danhSach()['dangChon'] ?? '');

    $dangXem = ($filters['show'] ?? 'table') === 'chart' && View::hasSection('co-bieu-do') ? 'chart' : 'table';
@endphp

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @foreach (['filterBranch' => __('message.branch'), 'filterTimeMultiple' => __('message.time'),
                'filterOrderOriginChannel' => __('message.order-origin')] as $oLoc => $nhan)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => $oLoc,
                    'modalLabel' => $nhan,
                ])
            @endforeach
            @stack('nut-loc-them')
        </div>
    </div>

    <div class="row index-end-day-report-page">
        {{-- ===================== Khung lọc trái ===================== --}}
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box" id="sidebar_filter">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <div id="filterBranch" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.branch') }}</label>
                                <select name="shop_id" class="form-select" data-loc>
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}" {{ $dangChonCN === (string) $cn['id'] ? 'selected' : '' }}>{{ $cn['name'] }}</option>
                                    @endforeach
                                    <option value="0" {{ $dangChonCN === '0' ? 'selected' : '' }}>Tất cả chi nhánh</option>
                                </select>
                            </div>
                        </div>

                        {{-- Thời gian: sáu nút + Tuỳ chỉnh, đúng khối #filterTimeMultiple của v2. --}}
                        <div id="filterTimeMultiple" class="col-md-12 my-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.time') }}</label>
                                <div class="row px-xl-2 px-1">
                                    @foreach ($CR::KY_NHANH as $ma => $nhan)
                                        <div class="col-6 px-1">
                                            <div class="mb-2">
                                                <input class="form-check-input selectTime chon-ky d-none" type="radio" name="range"
                                                    value="{{ $ma }}" id="ky_{{ $ma }}" data-loc {{ $filters['quick'] === $ma ? 'checked' : '' }}>
                                                <label class="form-check-label time-label" for="ky_{{ $ma }}">{{ $nhan }}</label>
                                            </div>
                                        </div>
                                    @endforeach
                                    <span class="title_search">Tùy chỉnh</span>
                                    <div class="col-md-12 px-1">
                                        <div class="d-flex position-relative mb-2">
                                            <input type="text" name="from_date" value="{{ $ngayVN($filters['from_date']) }}"
                                                class="form-control date_from" id="fromDate" autocomplete="off" data-loc
                                                placeholder="{{ __('message.from_date') }}">
                                        </div>
                                        <div class="d-flex position-relative">
                                            <input type="text" name="to_date" value="{{ $ngayVN($filters['to_date']) }}"
                                                class="form-control date_to" id="toDate" autocomplete="off" data-loc
                                                placeholder="{{ __('message.to_date') }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        @yield('loc-giua')

                        {{-- Nguồn đơn: tích hết = mọi nguồn. Không cho bỏ tích hết —
                             "không nguồn nào" là một bảng rỗng chẳng ai cần. --}}
                        <div id="filterOrderOriginChannel" class="mb-3" data-nhom="channel">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search">{{ __('message.order-origin') }}</span>
                                <div class="form-check mt-2">
                                    <input class="me-2 form-check-input nhom-het" type="checkbox" id="kenh_all"
                                        {{ $filters['channel'] === '' ? 'checked' : '' }}>
                                    <label class="form-check-label label-custom" for="kenh_all">{{ __('message.all') }}</label>
                                </div>
                                @foreach ($CR::NGUON_DON as $ma => $nhan)
                                    <div class="form-check">
                                        <input class="me-2 form-check-input nhom-muc" type="checkbox" value="{{ $ma }}" id="kenh_{{ $ma }}"
                                            {{ $filters['channel'] === '' || $filters['channel'] === $ma ? 'checked' : '' }}>
                                        <label class="form-check-label label-custom" for="kenh_{{ $ma }}">{{ $nhan }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        @yield('loc-cuoi')
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== Nội dung phải ===================== --}}
        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="card">
                <div class="card-header card-header-primary header_search">
                    <div class="d-flex flex-wrap gap-2">
                        <div class="px-1">
                            @hasSection('co-bieu-do')
                                <input class="me-2 form-check-input" value="chart" type="radio" name="show" id="show-chart"
                                    {{ $dangXem === 'chart' ? 'checked' : '' }}>
                                <label class="form-check-label" for="show-chart">{{ __('message.chart') }}</label>
                            @else
                                {{-- Tab không có biểu đồ: v2 khoá về dạng BẢNG (lockShowToTable). --}}
                                <input class="me-2 form-check-input" value="chart" type="radio" name="show" id="show-chart" disabled>
                                <label class="form-check-label text-muted" for="show-chart">{{ __('message.chart') }}</label>
                            @endif
                        </div>
                        <div class="px-1">
                            <input class="me-2 form-check-input" value="table" type="radio" name="show" id="show-table"
                                {{ $dangXem === 'table' ? 'checked' : '' }}>
                            <label class="form-check-label" for="show-table">Danh sách</label>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="d-flex flex-column flex-sm-row justify-content-sm-between align-items-sm-center">
                        <div>
                            {{-- Tên lấy theo TAB ĐANG MỞ. Trước đây ghi cứng "Báo cáo
                                 doanh thu" — tên của tab đầu tiên — nên mọi tab khác đều
                                 bày sai tên mình, dù dãy tab bên dưới sáng đúng chỗ.

                                 Không đặt giá trị lùi: thiếu $tabDangXem thì phải đỏ ngay
                                 chứ không được âm thầm bày lại tên tab đầu, vì đó đúng là
                                 cái lỗi này. --}}
                            <h1 class="fw-bold tieu-de-trang mb-0">{{ $CR::TAB_THONG_KE[$tabDangXem] }}</h1>
                            <div class="text-sm text-secondary mt-2">Tổng hợp doanh thu, chi phí &amp; hàng hóa trong ngày làm việc.</div>
                        </div>
                        <div id="header_title_info" class="p-2 px-3 my-2 my-sm-0 text-center">{{ $CR::kyChu($filters, $chiNhanh) }}</div>
                    </div>

                    <ul class="nav nav-tabs cus-nav-tabs mt-2">
                        @foreach ($CR::TAB_THONG_KE as $ma => $nhan)
                            <li class="nav-item">
                                @if ($ma === $tabDangXem)
                                    <a class="nav-link active" data-type="{{ $ma }}">{{ $nhan }}</a>
                                @elseif (isset($CR::TAB_ROUTE[$ma]))
                                    <a class="nav-link" data-type="{{ $ma }}" href="{{ route($CR::TAB_ROUTE[$ma]) }}">{{ $nhan }}</a>
                                @else
                                    <a class="nav-link chua-dung" data-type="{{ $ma }}" title="Màn này chưa dựng">{{ $nhan }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @yield('noi-dung')
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (function () {
            // Tham số chung đi theo khi chuyển tab trong trang — như v2 giữ nguyên
            // bộ lọc khi bấm sang tab khác.
            const THAM_SO_CHUNG = ['range', 'from_date', 'to_date', 'shop_id', 'channel'];

            /** Dựng query từ mọi ô lọc đang có trên trang. */
            function thamSoLoc() {
                const q = new URLSearchParams();
                // Đang sáng một mốc nhanh thì hai ô ngày chỉ là HỆ QUẢ của mốc đó —
                // gửi kèm là chúng thắng mốc nhanh và kéo kỳ về lại khoảng cũ.
                const coMoc = $('.chon-ky:checked').length > 0;
                $('[data-loc]').each(function () {
                    const ten = this.name;
                    if (!ten || (coMoc && (ten === 'from_date' || ten === 'to_date'))) return;
                    if (this.type === 'radio' && !this.checked) return;
                    const v = String($(this).val() || '').trim();
                    if (v) q.set(ten, v);
                });
                // Nhóm ô tích: tích hết = không lọc; tích một phần thì gửi các ô đã tích.
                $('[data-nhom]').each(function () {
                    const $muc = $(this).find('.nhom-muc');
                    const chon = $muc.filter(':checked').map((i, e) => e.value).get();
                    if (chon.length && chon.length < $muc.length) q.set($(this).data('nhom'), chon.join(','));
                });

                const cu = new URLSearchParams(location.search);
                ['hide', 'sort_field', 'sort_type', 'show'].forEach((ten) => { if (cu.get(ten)) q.set(ten, cu.get(ten)); });
                return q;
            }

            window.locLaiCuoiNgay = function (doi) {
                const q = thamSoLoc();
                Object.entries(doi || {}).forEach(([k, v]) => (v ? q.set(k, v) : q.delete(k)));
                V2.napLai(location.pathname + '?' + q);
            };

            $(document).on('change', '.fillter-box select[data-loc]:not([data-tai-lai])', () => locLaiCuoiNgay());
            $(document).on('change', '.chon-ky', () => locLaiCuoiNgay());

            let timerTim = null;
            $(document).on('input', 'input[type="text"][data-loc].search-bar', function () {
                clearTimeout(timerTim);
                timerTim = setTimeout(() => locLaiCuoiNgay(), 400);
            });

            // Nhóm ô tích: "Tất cả" tích lại cả nhóm; bỏ tích "Tất cả" không có
            // nghĩa gì nên giữ nguyên. Không cho bỏ tích ô cuối cùng.
            $(document).on('change', '[data-nhom] .nhom-het', function () {
                if (!this.checked) { this.checked = true; return; }
                $(this).closest('[data-nhom]').find('.nhom-muc').prop('checked', true);
                locLaiCuoiNgay();
            });
            $(document).on('change', '[data-nhom] .nhom-muc', function () {
                const $nhom = $(this).closest('[data-nhom]');
                if (!$nhom.find('.nhom-muc:checked').length) { this.checked = true; return; }
                $nhom.find('.nhom-het').prop('checked', $nhom.find('.nhom-muc:not(:checked)').length === 0);
                locLaiCuoiNgay();
            });

            // Chọn cột: cột đang tắt ghi vào ?hide= rồi nạp lại.
            function apDungCot() {
                const tat = $('.show_col').filter((i, el) => !el.checked).map((i, el) => $(el).data('col')).get();
                const q = new URLSearchParams(location.search);
                tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
                V2.napLai(location.pathname + '?' + q);
            }
            $(document).on('change', '.show_col', apDungCot);
            $(document).on('change', '#show_all', function () {
                $('.show_col').prop('checked', this.checked);
                apDungCot();
            });

            // Nút sắp xếp đổi thứ tự bằng V2.napLai, không nạp lại cả trang.
            $(document).on('click', '.btn_sort_table', function (e) {
                e.preventDefault();
                V2.napLai(this.href);
            });

            // Tab trong trang: mang theo bộ lọc chung đang xem.
            $(document).on('click', '.cus-nav-tabs .chua-dung', (e) => e.preventDefault());
            $(document).on('click', '.cus-nav-tabs a.nav-link[href]', function (e) {
                e.preventDefault();
                const cu = new URLSearchParams(location.search);
                const q = new URLSearchParams();
                THAM_SO_CHUNG.forEach((ten) => { if (cu.get(ten)) q.set(ten, cu.get(ten)); });
                location.href = this.getAttribute('href') + (q.toString() ? '?' + q : '');
            });

            // Link xuất dựng lúc bấm: hàng nút không được nạp lại, in sẵn href là
            // xuất theo bộ lọc lúc mở trang.
            $(document).on('click', '#tk-xuat', function () {
                const q = new URLSearchParams(location.search);
                q.set('xuat', 'excel');
                this.href = location.pathname + '?' + q;
            });

            // Biểu đồ | Danh sách: đổi dạng không gọi lại máy chủ, chỉ ghi lên URL
            // để F5 hay gửi link vẫn mở đúng dạng. Tab tự vẽ khi nhận 'tk:dang'.
            $(document).on('change', 'input[name="show"]', function () {
                const q = new URLSearchParams(location.search);
                this.value === 'chart' ? q.set('show', 'chart') : q.delete('show');
                history.replaceState(history.state, '', location.pathname + (q.toString() ? '?' + q : ''));
                $('[data-dang]').each((i, el) => $(el).toggleClass('d-none', $(el).data('dang') !== this.value));
                $(document).trigger('tk:dang', this.value);
            });

            // Sau mỗi lượt nạp: hai ô ngày, mốc nhanh và hộp kỳ theo đúng kỳ server vừa xem.
            $(document).on('v2:da-nap', function () {
                const $ky = $('#tk-ky');
                if (!$ky.length) return;
                $('#fromDate').val($ky.data('from'));
                $('#toDate').val($ky.data('to'));
                $('#header_title_info').text($ky.data('chu'));
                const moc = $ky.data('quick');
                $('.chon-ky').prop('checked', false);
                if (moc) $('#ky_' + moc).prop('checked', true);
            });

            // Hai ô ngày "Tùy chỉnh": chọn xong một ô là lọc ngay, bỏ mốc nhanh đang sáng.
            $('#fromDate, #toDate').each(function () {
                $(this).daterangepicker({
                    singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
                    maxDate: moment(), locale: V2.lichVN(),
                }, function (start) {
                    $(this.element).val(start.format('DD-MM-YYYY'));
                    $('.chon-ky').prop('checked', false);
                    locLaiCuoiNgay({ range: '' });
                });
            });
        })();
    </script>
@endpush
