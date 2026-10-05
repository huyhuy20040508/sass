{{-- Báo cáo → Báo cáo kết ca — dựng theo bản v2
     (report/end-shift-report/index.blade.php + list.blade.php).

     Khuôn trang, đúng như bản đang chạy:
       ┌ khung lọc trái: Chi nhánh · Thời gian (6 mốc nhanh + từ/đến) · Nhân viên
       └ nội dung phải: tiêu đề + ô tìm mã/tên nhân sự + Xuất Excel + chọn cột,
                        bảng 19 cột, phân trang, dòng "Tổng tiền" cuối bảng.

     Khác v2 một chỗ: "Tổng tiền" cộng CẢ KỲ đang lọc (API gộp sẵn), không phải
     riêng trang đang xem — bản gốc cộng trên trang hiện tại nên sang trang 2 là
     con số tổng đổi theo.

     Dữ liệu do ShiftReportController::index đẩy sang: $rows, $meta, $filters,
     $chiNhanh, $nhanVien. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\ShiftReportController::TITLE)

@php
    $C = \App\Http\Controllers\ShiftReportController::class;
    $R = \App\Http\Controllers\ReportController::class;

    // Cột đang tắt nằm ở ?hide= — giữ được sau khi đổi trang / đổi bộ lọc.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $an = fn ($c) => in_array($c, $cotTat, true) ? 'hide' : '';

    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $tien = fn ($n) => $n === null ? '' : number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '';

    // API chưa trả tổng cả kỳ thì cộng tạm trên trang đang xem, như v2.
    $tongTien = $meta['tong']['total_revenue'] ?? array_sum(array_column($rows, 'total_revenue'));

    $coLoc = $filters['keyword'] !== '' || $filters['user_id'] !== '';
@endphp

@push('styles')
    <style>
        /* Bảng co theo nội dung và cuộn ngang trong thẻ, như bản gốc: 19 cột ép
           vào bề ngang cố định thì cột nào cũng hụt. Nhãn và ô giữ MỘT DÒNG. */
        table.table-ket-ca.none_mobile { width: 100%; }
        table.table-ket-ca th, table.table-ket-ca td { white-space: nowrap; }
        /* Ghi chú là chữ tự do, có thể dài — riêng hai cột này được xuống dòng. */
        table.table-ket-ca td.la-ghi-chu { white-space: normal; min-width: 160px; max-width: 280px; }
        table.table-ket-ca .ca-dang-mo { color: #e00; font-weight: 700; }
        table.table-ket-ca .am { color: #e00; }

        .btn_top_content { gap: 8px; align-items: center; }
        .btn_top_content .btn-export,
        .btn_top_content .setting-col {
            height: 34px; min-height: 34px; display: inline-flex; align-items: center;
            justify-content: center; border-radius: 4px; margin: 0;
        }
        .btn_top_content .btn-export { padding: 0 12px !important; }
        .btn_top_content .setting-col { width: 34px; padding: 0 !important; }
        .btn_top_content .dropup { display: inline-flex; }
        .search-bar-shift { min-width: 260px; }

        /* Sáu mốc nhanh: hai cột nút tròn như v2. */
        .kc-nhanh .form-check { display: flex; align-items: center; gap: 6px; padding-left: 0; margin-bottom: 4px; }
        .kc-nhanh .form-check-input { float: none; margin: 0; flex-shrink: 0; }
        .kc-nhanh .form-check-label { font-size: 13px; letter-spacing: -0.3px; white-space: nowrap; }

        .kc-tong { display: flex; justify-content: flex-end; gap: 24px; padding: 8px 16px; font-weight: 700; }
        .kc-rong { padding: 28px 0; text-align: center; color: #8c8c8c; }
    </style>
@endpush

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @foreach (['filterBranch' => __('message.branch'), 'filterTime' => __('message.time'), 'filterEmployee' => __('message.employee')] as $oLoc => $nhan)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => $oLoc,
                    'modalLabel' => $nhan,
                ])
            @endforeach
        </div>
    </div>

    <div class="row index-end-shift-report-page">
        {{-- ===================== Khung lọc trái ===================== --}}
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không bọc <form>: trên điện thoại vỏ v2 bưng từng khối lọc sang
                             offcanvas, submit lúc đó đánh rơi các ô còn lại. JS tự dựng URL. --}}
                        <div id="filterBranch" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.branch') }}</label>
                                <select name="shop_id" class="form-select">
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}" {{ (int) $filters['shop_id'] === (int) $cn['id'] ? 'selected' : '' }}>
                                            {{ $cn['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterTime" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.time') }}</label>
                                <div class="row g-0 kc-nhanh">
                                    @foreach ($R::KY_NHANH as $ma => $nhan)
                                        <div class="col-6">
                                            <div class="form-check">
                                                <input class="form-check-input chon-ky" type="radio" name="range"
                                                    value="{{ $ma }}" id="ky_{{ $ma }}"
                                                    {{ $filters['quick'] === $ma ? 'checked' : '' }}>
                                                <label class="form-check-label" for="ky_{{ $ma }}">{{ $nhan }}</label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex flex-lg-column gap-2 mt-2">
                                    <input type="text" name="from_date" id="kc-from-date" autocomplete="off"
                                        value="{{ $ngayVN($filters['from_date']) }}" class="form-control"
                                        placeholder="{{ __('message.from_date') }}">
                                    <input type="text" name="to_date" id="kc-to-date" autocomplete="off"
                                        value="{{ $ngayVN($filters['to_date']) }}" class="form-control"
                                        placeholder="{{ __('message.to_date') }}">
                                </div>
                            </div>
                        </div>

                        <div id="filterEmployee" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.employee') }}</label>
                                <select name="user_id" class="form-select">
                                    <option value="">{{ __('message.select_staff') }}</option>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}" {{ (string) $filters['user_id'] === (string) $nv['id'] ? 'selected' : '' }}>
                                            {{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===================== Nội dung phải ===================== --}}
        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle mt-md-2 mt-lg-0">
            <div class="content_midd">
                {{-- Hàng tiêu đề nằm NGOÀI .list: V2.napLai thay ruột .list mỗi lượt
                     lọc, ô tìm nằm trong đó thì đang gõ dở là mất con trỏ. --}}
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">{{ $C::TITLE }}</h1>

                    <div class="d-flex justify-content-end">
                        <div class="btn_top_content d-flex">
                            <div class="d-none d-lg-flex input-group search-bar-shift">
                                <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                    class="form-control ip_search" autocomplete="off"
                                    placeholder="Mã nhân sự, Tên nhân sự">
                                <button class="btn btn-search-product" type="button" id="kc-tim">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                </button>
                            </div>
                            {{-- Link dựng lúc bấm (xem JS): hàng này không được nạp lại
                                 nên in sẵn href là xuất theo bộ lọc lúc mở trang. --}}
                            <a class="btn btn-sm d-flex align-items-center btn-export text-nowrap" id="kc-xuat"
                                href="{{ route('admin.shift-report.export', request()->query()) }}">
                                <i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất Excel
                            </a>
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="show_all"
                                                    {{ count($cotTat) ? '' : 'checked' }}>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($C::COT_BANG as $cot => $chu)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="{{ $cot }}"
                                                        type="checkbox" id="show_{{ $cot }}"
                                                        {{ $an($cot) ? '' : 'checked' }}>
                                                    <label for="show_{{ $cot }}">{{ $chu }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv list-report-shift">
                    {{-- Kỳ THẬT server đang xem. Khung lọc trái không được nạp lại,
                         nên sau mỗi lượt nạp JS đọc thẻ này để điền lại hai ô ngày. --}}
                    <span id="kc-ky" hidden data-from="{{ $ngayVN($filters['from_date']) }}"
                        data-to="{{ $ngayVN($filters['to_date']) }}" data-quick="{{ $filters['quick'] }}"></span>
                    <div class="table-responsive table-border-style">
                        <table class="table-ket-ca none_mobile">
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center">{{ __('message.stt') }}</th>
                                    @foreach ($C::COT_BANG as $cot => $chu)
                                        <th class="show_{{ $cot }} {{ in_array($cot, $C::COT_TIEN, true) || in_array($cot, ['open_time', 'close_time', 'total_order'], true) ? 'text-right' : 'text-left' }} {{ $an($cot) }}">{{ $chu }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $i => $r)
                                    <tr class="item" data-id="{{ $r['id'] }}">
                                        <td class="text-center">{{ $stt + $i + 1 }}</td>
                                        <td class="text-left show_employee_code {{ $an('employee_code') }}">{{ $r['employee_code'] }}</td>
                                        <td class="text-left show_employee_name {{ $an('employee_name') }}">{{ $r['employee_name'] }}</td>
                                        <td class="text-right show_open_time {{ $an('open_time') }}">{{ $r['open_time'] }}</td>
                                        <td class="text-right show_close_time {{ $an('close_time') }}">
                                            @if ($r['dang_mo'])
                                                <span class="ca-dang-mo">Đang mở</span>
                                            @else
                                                {{ $r['close_time'] }}
                                            @endif
                                        </td>
                                        <td class="text-right show_total_order {{ $an('total_order') }}">{{ $r['total_order'] }}</td>
                                        @foreach (['cash', 'transfer', 'swipe_card', 'auto_qr', 'opening_cash', 'closing_cash', 'cash_delivery'] as $cot)
                                            <td class="text-right show_{{ $cot }} {{ $an($cot) }}">{{ $tien($r[$cot]) }}</td>
                                        @endforeach
                                        <td class="text-right show_cash_difference {{ $an('cash_difference') }} {{ ($r['cash_difference'] ?? 0) < 0 ? 'am' : '' }}">{{ $tien($r['cash_difference']) }}</td>
                                        <td class="text-right show_total_revenue {{ $an('total_revenue') }}">{{ $tien($r['total_revenue']) }}</td>
                                        <td class="text-right show_date {{ $an('date') }}">{{ $r['date'] }}</td>
                                        <td class="text-left show_opener {{ $an('opener') }}">{{ $r['opener'] }}</td>
                                        <td class="text-left la-ghi-chu show_open_note {{ $an('open_note') }}">{{ $r['open_note'] }}</td>
                                        <td class="text-left show_closer {{ $an('closer') }}">{{ $r['closer'] }}</td>
                                        <td class="text-left la-ghi-chu show_close_note {{ $an('close_note') }}">{{ $r['close_note'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($C::COT_BANG) + 1 }}" class="kc-rong">
                                            {{ $coLoc ? 'Không có ca nào khớp bộ lọc đang bật.' : 'Kỳ này chưa có ca nào.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        {{-- BẢN THẺ CHO ĐIỆN THOẠI — dưới 992px vỏ v2 giấu hẳn bảng. --}}
                        <div class="list none_desktop">
                            <div class="d-flex align-items-center gap-1 p-2 border">
                                <div class="fw-bold" style="flex: 1">Ca</div>
                                <div class="fw-bold">Tổng doanh thu</div>
                            </div>
                            @foreach ($rows as $r)
                                <div class="item d-flex align-items-center gap-2 p-2 border-bottom" data-id="{{ $r['id'] }}">
                                    <div class="d-flex flex-column" style="flex: 1">
                                        <span class="fw-semibold">{{ $r['employee_name'] }}</span>
                                        <small class="text-secondary">
                                            {{ $r['date'] }} · {{ $r['open_time'] }} –
                                            @if ($r['dang_mo'])
                                                <span class="text-danger fw-bold">Đang mở</span>
                                            @else
                                                {{ $r['close_time'] }}
                                            @endif
                                            · {{ $r['total_order'] }} đơn
                                        </small>
                                    </div>
                                    <b>{{ $tien($r['total_revenue']) }}</b>
                                </div>
                            @endforeach
                            @if (! count($rows))
                                <div class="kc-rong">{{ $coLoc ? 'Không có ca nào khớp bộ lọc đang bật.' : 'Kỳ này chưa có ca nào.' }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>

                    <div class="kc-tong">
                        <span>{{ __('message.total_money') }}:</span>
                        <span>{{ $tien($tongTien) }} đ</span>
                    </div>
                </div>

                <select class="form-control item-per-page select-width {{ count($rows) ? '' : 'd-none' }}"
                    data-param="page_size">
                    @foreach ($C::PAGE_SIZES as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // ================= Bộ lọc =================
        const oLoc = (ten) => $('[name="' + ten + '"]');

        /** Dựng URL từ các ô lọc rồi nạp lại. `bo` = tên ô bỏ qua lần này. */
        function locLai(bo) {
            const q = new URLSearchParams();
            // Đang sáng một mốc nhanh thì hai ô ngày chỉ là HỆ QUẢ của mốc đó —
            // gửi kèm là chúng thắng mốc nhanh và kéo kỳ về lại khoảng cũ.
            const coMoc = $('.chon-ky:checked').length > 0;
            ['shop_id', 'range', 'from_date', 'to_date', 'user_id', 'keyword'].forEach(function (ten) {
                if (bo && bo.includes(ten)) return;
                if (coMoc && (ten === 'from_date' || ten === 'to_date')) return;
                const $o = oLoc(ten);
                const v = String(($o.is(':radio') ? $o.filter(':checked') : $o).val() || '').trim();
                if (v) q.set(ten, v);
            });

            // Cột ẩn và cỡ trang chép lại từ URL cũ. Cố ý không mang `page`:
            // lọc lại thì trang cũ hết nghĩa.
            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size'].forEach(function (ten) { if (cu.get(ten)) q.set(ten, cu.get(ten)); });

            V2.napLai(location.pathname + '?' + q);
        }

        $(document).on('change', '.fillter-box select', function () { locLai(); });
        $(document).on('change', '.chon-ky', function () { locLai(); });
        // Tự chọn ngày: tắt mốc nhanh đang sáng rồi mới lọc.
        $(document).on('change', '#kc-from-date, #kc-to-date', function () {
            $('.chon-ky').prop('checked', false);
            locLai(['range']);
        });

        // Sau mỗi lượt nạp: hai ô ngày và mốc nhanh theo đúng kỳ server vừa xem.
        $(document).on('v2:da-nap', function () {
            const $ky = $('#kc-ky');
            if (!$ky.length) return;
            $('#kc-from-date').val($ky.data('from'));
            $('#kc-to-date').val($ky.data('to'));
            const moc = $ky.data('quick');
            $('.chon-ky').prop('checked', false);
            if (moc) $('#ky_' + moc).prop('checked', true);
        });

        let timerTim = null;
        $(document).on('input', '.search-bar-shift [name="keyword"]', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(function () { locLai(); }, 400);
        });
        $(document).on('click', '#kc-tim', function () { clearTimeout(timerTim); locLai(); });

        $(document).on('click', '#kc-xuat', function () {
            this.href = @json(route('admin.shift-report.export')) + location.search;
        });

        // ================= Chọn cột =================
        function apDungCot() {
            const tat = $('.show_col').filter(function (i, el) { return !el.checked; })
                .map(function (i, el) { return $(el).data('col'); }).get();
            const q = new URLSearchParams(location.search);
            tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            apDungCot();
        });

        // ================= Lịch cho hai ô ngày =================
        function ganLich() {
            ['#kc-from-date', '#kc-to-date'].forEach(function (sel) {
                const $o = $(sel);
                if (!$o.length || $o.data('daterangepicker')) return;
                $o.daterangepicker({
                    singleDatePicker: true, showDropdowns: true, locale: V2.lichVN(),
                    autoUpdateInput: false, autoApply: true,
                }, function (start) {
                    $o.val(start.format('DD-MM-YYYY')).trigger('change');
                });
            });
        }
        $(ganLich);
        $(document).on('v2:da-nap', ganLich);
    </script>
@endpush
