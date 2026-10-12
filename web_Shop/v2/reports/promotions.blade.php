{{-- Báo cáo → Báo cáo chương trình khuyến mại — khuôn report/promotion-report
     của bản v2 (index + list-*.blade.php + modal show-by-*).

       ┌ khung lọc trái: Chi nhánh · Thời gian (mốc nhanh + Từ ngày / Đến ngày)
       └ nội dung phải: tiêu đề + ô "Mã, tên chương trình" + Xuất báo cáo,
                        dãy tab của v2 + hộp kỳ, bảng mỗi chương trình một dòng,
                        dòng tổng in đậm ở cuối như v2. Bấm tên chương trình →
                        hộp chi tiết (modal-xl) các hoá đơn đã dùng nó.

     Dữ liệu do PromotionReportController::index đẩy sang: $rows, $tong,
     $filters, $chiNhanh. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\PromotionReportController::TITLE)

@php
    $C = \App\Http\Controllers\PromotionReportController::class;
    $CR = \App\Http\Controllers\ReportController::class;

    $tab = $filters['tab'];
    $cot = $C::COT[$tab];
    $ngayVN = fn ($s) => \Illuminate\Support\Carbon::parse($s)->format('d-m-Y');
    $dangChonCN = $filters['shop_id'] !== '' ? $filters['shop_id'] : (string) (\App\Services\CurrentBranch::danhSach()['dangChon'] ?? '');

    // Câu "không có gì" dùng CHUNG cho bảng và bản thẻ. Trước đây bảng nói theo ô
    // tìm còn bản thẻ ghi cứng một câu, nên cùng một lượt tìm hụt mà màn to bảo
    // "không khớp ô tìm" còn màn nhỏ bảo "kỳ này không có chương trình nào" —
    // người xem trên điện thoại tưởng cả kỳ trống.
    $cauRong = $filters['keyword'] !== ''
        ? 'Không có chương trình nào khớp ô tìm.'
        : 'Kỳ này không có chương trình khuyến mại nào.';

    $laChu = fn ($k) => in_array($k, $C::COT_CHU, true);
    $o = function (array $d, string $k) {
        return match ($k) {
            'ti_le' => $d['code_count'] > 0 ? rtrim(rtrim(number_format($d[$k], 1, ',', '.'), '0'), ',').'%' : '-',
            'code', 'name', 'thoi_gian' => $d[$k],
            default => number_format($d[$k], 0, ',', '.'),
        };
    };
    // Dòng tổng: nhãn "Tổng cộng" phủ dãy cột chữ ĐẦU bảng, cột chữ phía sau để trống.
    $soCotDau = 1;
    foreach (array_keys($cot) as $k) {
        if (! $laChu($k)) {
            break;
        }
        $soCotDau++;
    }
@endphp

@push('styles')
    <style>
        /* Dãy tab trong trang — `cus-nav-tabs` của v2. Từ 992px: chữ đen, tab đang
           xem gạch chân xanh, năm tab một hàng (hẹp thì cuộn ngang, tab đang xem
           được cuộn vào tầm nhìn — xem JS). Dưới 992px để nguyên kiểu viên cam xếp
           chồng của vỏ v2 (responsive-manager.css). */
        .cus-nav-tabs { border: 0 !important; }
        @media (min-width: 992px) {
            .cus-nav-tabs { flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; scrollbar-width: none; min-width: 0; }
            .cus-nav-tabs::-webkit-scrollbar { display: none; }
            .cus-nav-tabs .nav-item { flex-shrink: 0; }
            .cus-nav-tabs .nav-item .nav-link { color: #000; padding: 8px 12px; border: 0; cursor: pointer; white-space: nowrap; }
            .cus-nav-tabs .nav-item .nav-link.active {
                border: 0 !important; border-bottom: 2px solid #0162df !important;
                color: #0162df !important; font-weight: bold; background: transparent;
            }
            .cus-nav-tabs .nav-item:hover { font-weight: bold; background-color: #fff; }
        }
        .cus-nav-tabs .nav-link { cursor: pointer; }

        /* Nhãn kỳ bám cuối dãy tab, HẾT CHỖ THÌ XUỐNG DÒNG chứ không cắt chữ và
           cũng không biến mất.

           Bản đầu vừa `d-none d-md-block` (dưới 768px mất hẳn) vừa
           `max-width: 50% + text-overflow: ellipsis` (800-1000px cắt thành
           "… 31-1…"). Đúng khổ mà bảng đã đổi sang bản thẻ thì nhãn kỳ càng
           cần, vì bản thẻ không có dòng nào khác nói đang xem kỳ nào.
           Hai màn cùng nhóm (Báo cáo cuối ngày, Tổng hợp) để #header_title_info
           hiện ở mọi khổ — màn này nay theo cho đồng bộ. */
        .km-thanh-tab { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; border-bottom: 1px solid #e3e7ef; margin-bottom: 10px; }
        .km-ky { background-color: #BED5FF; border-radius: 12px; padding: 4px 12px; margin-bottom: 6px; font-size: 13px; }

        .btn_top_content { gap: 8px; align-items: center; }
        .btn_top_content .search-bar-km { min-width: 280px; }
        /* Nút xuất màu của v2 (btn-export-promotion). */
        .btn-xuat-km {
            background-color: #3B9EC8 !important; border: 1px solid #4b93b3 !important; color: #fff !important;
            height: 34px; display: inline-flex; align-items: center; padding: 0 12px !important; border-radius: 4px; white-space: nowrap;
        }
        .btn-xuat-km:hover { background-color: #3189b0 !important; }

        /* Bảng: khuôn bảng danh sách v2 (tiêu đề nền #d3daeb, sọc), dòng 46px,
           chữ 14px; dưới 1600 nén chữ và đệm — đo ở 1366–1536 với tên 31 ký tự,
           tiền 8 chữ số là vừa khung. Không ô nào xuống dòng — tên dài hơn nữa
           thì bảng cuộn ngang trong thẻ. */
        table.table-km { width: 100%; table-layout: auto; }
        table.table-km th, table.table-km td { white-space: nowrap; vertical-align: middle; font-size: 14px; padding: 8px 10px; }
        table.table-km th { height: 48px; font-weight: 700; }
        table.table-km tbody tr { height: 46px; }
        table.table-km tbody tr:hover td { background-color: #eef4ff; }
        table.table-km .chu { text-align: left !important; }
        table.table-km .so { text-align: right !important; }
        table.table-km .stt { text-align: center !important; width: 48px; }
        table.table-km .ten-ct { cursor: pointer; font-weight: bold; color: rgb(82, 128, 189); text-decoration: none; }
        table.table-km .ten-ct:hover { text-decoration: underline; }
        table.table-km tr.chua-dung td { color: #9aa0aa; }
        table.table-km tr.dong-tong td { font-weight: 700; background-color: #F6FFCB !important; height: 46px; }
        table.table-km .tien-km { color: #d4380d; font-weight: 600; }
        table.table-km tr.dong-tong .tien-km { color: #d4380d; }
        @media (max-width: 1599.98px) {
            table.table-km th, table.table-km td { font-size: 12.5px; padding: 6px; }
        }
        .nhan-xoa { font-size: 11px; font-weight: 600; color: #a8071a; background: #fff1f0; border: 1px solid #ffa39e; border-radius: 4px; padding: 0 4px; margin-left: 6px; }
        .km-rong { padding: 36px 0 !important; text-align: center !important; color: #8c8c8c; }
        .km-rong i { display: block; font-size: 28px; color: #c9d1de; margin-bottom: 8px; }
        .km-ghi-chu { color: #6b7280; font-size: 12.5px; padding: 0 6px 8px; }

        /* Sáu mốc nhanh: hai cột nút tròn như Báo cáo kết ca. */
        .kc-nhanh .form-check { display: flex; align-items: center; gap: 6px; padding-left: 0; margin-bottom: 4px; }
        .kc-nhanh .form-check-input { float: none; margin: 0; flex-shrink: 0; }
        .kc-nhanh .form-check-label { font-size: 13px; letter-spacing: -0.3px; white-space: nowrap; }

        /* Bản điện thoại — khuôn `.main-table.none_desktop .item` của v2. */
        .km-the .item { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; border-bottom: 1px solid #eee; }
        .km-the .item.dau { background: #d3daeb; font-weight: 700; }
        .km-the .item.cuoi { font-weight: 700; background: #F6FFCB; }

        /* Hộp chi tiết — modal-xl của v2. */
        #modalDonKM .modal-header { padding: 10px 16px; }
        #modalDonKM .modal-title { font-size: 16px; font-weight: 600; color: #1a2b58; }
        #modalDonKM .km-hop-phu { font-size: 13px; color: #555; padding: 8px 16px 0; }
        #modalDonKM .km-hop-phu b { color: #1a2b58; }
        #modalDonKM .search-order { max-width: 300px; }
        #modalDonKM table th, #modalDonKM table td { white-space: nowrap; font-size: 13.5px; padding: 7px 10px; vertical-align: middle; }
        #modalDonKM table .chu { text-align: left !important; }
        #modalDonKM table .so { text-align: right !important; }
        #modalDonKM table tr.dong-tong td { font-weight: 700; background-color: #F6FFCB !important; }
        #modalDonKM .tien-km { color: #d4380d; font-weight: 600; }
    </style>
@endpush

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @foreach (['filterBranch' => __('message.branch'), 'filterTime' => __('message.time')] as $oLoc => $nhan)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => $oLoc,
                    'modalLabel' => $nhan,
                ])
            @endforeach
        </div>
    </div>

    <div class="row index-promotion-report-page">
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
                                <select name="shop_id" class="form-select" data-loc>
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}" {{ $dangChonCN === (string) $cn['id'] ? 'selected' : '' }}>{{ $cn['name'] }}</option>
                                    @endforeach
                                    <option value="0" {{ $dangChonCN === '0' ? 'selected' : '' }}>Tất cả chi nhánh</option>
                                </select>
                            </div>
                        </div>

                        <div id="filterTime" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <label class="form-label title_search">{{ __('message.time') }}</label>
                                <div class="row g-0 kc-nhanh">
                                    @foreach ($CR::KY_NHANH as $ma => $nhan)
                                        <div class="col-6">
                                            <div class="form-check">
                                                <input class="form-check-input chon-ky" type="radio" name="range" data-loc
                                                    value="{{ $ma }}" id="ky_{{ $ma }}" {{ $filters['quick'] === $ma ? 'checked' : '' }}>
                                                <label class="form-check-label" for="ky_{{ $ma }}">{{ $nhan }}</label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex flex-lg-column gap-2 mt-2">
                                    <input type="text" name="from_date" id="fromDate" autocomplete="off" data-loc
                                        value="{{ $ngayVN($filters['from_date']) }}" class="form-control"
                                        placeholder="{{ __('message.from_date') }}">
                                    <input type="text" name="to_date" id="toDate" autocomplete="off" data-loc
                                        value="{{ $ngayVN($filters['to_date']) }}" class="form-control"
                                        placeholder="{{ __('message.to_date') }}">
                                </div>
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
                    <div class="btn_top_content d-flex">
                        <div class="d-none d-lg-flex input-group search-bar-km">
                            <input type="text" name="keyword" value="{{ $filters['keyword'] }}" data-loc
                                class="form-control ip_search" autocomplete="off" placeholder="Mã, tên chương trình">
                            <button class="btn btn-search-product" type="button" id="km-tim">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                        {{-- Link dựng lúc bấm (xem JS): hàng này không được nạp lại. --}}
                        <a class="btn btn-sm btn-xuat-km" id="km-xuat"
                            href="{{ route('admin.reports.promotions.export', request()->query()) }}">
                            <i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất báo cáo
                        </a>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <span id="km-ky" hidden data-from="{{ $ngayVN($filters['from_date']) }}" data-to="{{ $ngayVN($filters['to_date']) }}"
                        data-quick="{{ $filters['quick'] }}"></span>

                    <div class="km-thanh-tab">
                        <ul class="nav nav-tabs cus-nav-tabs">
                            @foreach ($C::TAB as $ma => $nhan)
                                <li class="nav-item">
                                    <a class="nav-link {{ $ma === $tab ? 'active' : '' }}" data-tab="{{ $ma }}">{{ $nhan }}</a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="km-ky">{{ $CR::kyChu($filters, $chiNhanh) }}</div>
                    </div>

                    <div class="table-responsive table-border-style">
                        <table class="table-striped table-km none_mobile">
                            <thead>
                                <tr class="header-table-list">
                                    <th class="stt">{{ __('message.stt') }}</th>
                                    @foreach ($cot as $k => $nhan)
                                        <th class="{{ $laChu($k) ? 'chu' : 'so' }}">{{ $nhan }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $i => $r)
                                    <tr class="item {{ $r['order_count'] ? '' : 'chua-dung' }}">
                                        <td class="stt fw-bold">{{ $i + 1 }}</td>
                                        @foreach ($cot as $k => $nhan)
                                            @if ($k === 'name')
                                                <td class="chu">
                                                    @if ($r['order_count'])
                                                        <a class="ten-ct" data-id="{{ $r['program_id'] }}" data-ten="{{ $r['code'] }} - {{ $r['name'] }}">{{ $r['name'] }}</a>
                                                    @else
                                                        {{ $r['name'] }}
                                                    @endif
                                                    @if ($r['da_xoa'])
                                                        <span class="nhan-xoa">Đã xoá</span>
                                                    @endif
                                                </td>
                                            @else
                                                <td class="{{ $laChu($k) ? 'chu' : 'so' }} {{ $k === 'discount' && $r['discount'] ? 'tien-km' : '' }}">{{ $o($r, $k) }}</td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($cot) + 1 }}" class="km-rong">
                                            <i class="fa-solid fa-tags"></i>
                                            {{ $cauRong }}
                                        </td>
                                    </tr>
                                @endforelse
                                @if (count($rows))
                                    <tr class="dong-tong">
                                        <td colspan="{{ $soCotDau }}" class="chu">Tổng cộng</td>
                                        @foreach (array_slice(array_keys($cot), $soCotDau - 1) as $k)
                                            <td class="{{ $laChu($k) ? 'chu' : 'so' }} {{ $k === 'discount' ? 'tien-km' : '' }}">{{ $laChu($k) ? '' : $o($tong, $k) }}</td>
                                        @endforeach
                                    </tr>
                                @endif
                            </tbody>
                        </table>

                        {{-- BẢN THẺ CHO ĐIỆN THOẠI — dưới 992px vỏ v2 giấu hẳn bảng. --}}
                        <div class="km-the none_desktop">
                            <div class="item dau"><span>Tên chương trình</span><span>{{ $cot['discount'] }}</span></div>
                            @foreach ($rows as $r)
                                <div class="item">
                                    <div class="d-flex flex-column" style="min-width: 0">
                                        @if ($r['order_count'])
                                            <span class="fw-semibold ten-ct" data-id="{{ $r['program_id'] }}" data-ten="{{ $r['code'] }} - {{ $r['name'] }}">{{ $r['name'] }}</span>
                                        @else
                                            <span class="fw-semibold">{{ $r['name'] }}</span>
                                        @endif
                                        <small class="text-secondary">{{ $r['code'] }} · {{ $r['order_count'] }} hóa đơn</small>
                                    </div>
                                    <b class="text-nowrap">{{ $o($r, 'discount') }}</b>
                                </div>
                            @endforeach
                            @if (count($rows))
                                <div class="item cuoi"><span>Tổng cộng</span><span>{{ $o($tong, 'discount') }}</span></div>
                            @else
                                <div class="km-rong">{{ $cauRong }}</div>
                            @endif
                        </div>
                    </div>

                    <div class="km-ghi-chu mt-2">
                        Số liệu theo hóa đơn tạo trong kỳ, không tính hóa đơn hủy / hoàn. Dòng tổng đếm mỗi hóa đơn một lần.
                        @if ($tab === 'dong-gia')
                            Tiền giảm đồng giá = (giá gốc − giá đồng giá) × số lượng.
                        @elseif ($tab === 'voucher')
                            Đã sử dụng, còn lại và tỉ lệ sử dụng tính trên cả đời chương trình.
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Hộp chi tiết — khuôn #modalShowDetailData + show-by-*.blade.php của v2. --}}
    <div class="modal fade" id="modalDonKM" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-uppercase" id="dkmTieuDe"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="km-hop-phu"><b id="dkmTen"></b> <span id="dkmKy" class="ms-1"></span></div>
                <div class="modal-body p-3">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                        <input type="text" class="form-control search-order" placeholder="Tìm kiếm đơn hàng" autocomplete="off">
                        <a class="btn btn-sm btn-xuat-km" id="dkmXuat"><i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất Excel</a>
                    </div>
                    <div class="table-responsive table-border-style">
                        <table class="table-striped mb-0">
                            <thead><tr class="header-table-list" id="dkmDau"></tr></thead>
                            <tbody id="dkmThan"></tbody>
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
    <script>
        (function () {
            const URL_DON = @json(route('admin.reports.promotions.orders'));
            const URL_XUAT = @json(route('admin.reports.promotions.export'));
            const COT_DON = @json($C::COT_DON);
            const TIEU_DE = @json($C::TIEU_DE_HOP);
            const COT_CHU = @json($C::COT_CHU);
            const tien = (v) => new Intl.NumberFormat('vi-VN').format(Math.round(Number(v) || 0));
            const thoat = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            })[c]);
            const tabDangXem = () => $('.cus-nav-tabs .nav-link.active').data('tab') || '';

            // ================= Bộ lọc =================
            function locLai(doi) {
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
                if (tabDangXem()) q.set('tab', tabDangXem());
                Object.entries(doi || {}).forEach(([k, v]) => (v ? q.set(k, v) : q.delete(k)));
                V2.napLai(location.pathname + '?' + q);
            }

            $(document).on('change', '.fillter-box select[data-loc]', () => locLai());
            $(document).on('change', '.chon-ky', () => locLai());
            $(document).on('click', '.cus-nav-tabs .nav-link', function () {
                if (!$(this).hasClass('active')) locLai({ tab: $(this).data('tab') });
            });

            let timerTim = null;
            $(document).on('input', '.search-bar-km [name="keyword"]', function () {
                clearTimeout(timerTim);
                timerTim = setTimeout(() => locLai(), 400);
            });
            $(document).on('click', '#km-tim', () => { clearTimeout(timerTim); locLai(); });

            $(document).on('click', '#km-xuat', function () { this.href = URL_XUAT + location.search; });

            function hienTabDangXem() {
                const a = document.querySelector('.cus-nav-tabs .nav-link.active');
                if (a && window.innerWidth >= 992) a.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
            $(hienTabDangXem);
            $(document).on('v2:da-nap', hienTabDangXem);

            // Sau mỗi lượt nạp: hai ô ngày và mốc nhanh theo đúng kỳ server vừa xem.
            $(document).on('v2:da-nap', function () {
                const $ky = $('#km-ky');
                if (!$ky.length) return;
                $('#fromDate').val($ky.data('from'));
                $('#toDate').val($ky.data('to'));
                const moc = $ky.data('quick');
                $('.chon-ky').prop('checked', false);
                if (moc) $('#ky_' + moc).prop('checked', true);
            });

            $('#fromDate, #toDate').each(function () {
                $(this).daterangepicker({
                    singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
                    maxDate: moment(), locale: V2.lichVN(),
                }, function (start) {
                    $(this.element).val(start.format('DD-MM-YYYY'));
                    $('.chon-ky').prop('checked', false);
                    locLai({ range: '' });
                });
            });

            // ================= Hộp chi tiết của một chương trình =================
            let hop = { cot: [], don: [] };
            const laChu = (k) => COT_CHU.includes(k);

            function veHop(tu) {
                const kw = String(tu || '').trim().toLowerCase();
                const ds = kw ? hop.don.filter((d) => [d.code, d.khach, d.phone, d.voucher_code].some((v) => String(v || '').toLowerCase().includes(kw))) : hop.don;
                const n = hop.cot.length + 1;
                if (!ds.length) {
                    $('#dkmThan').html('<tr><td colspan="' + n + '" class="text-center py-4">'
                        + (kw ? 'Không có hóa đơn nào khớp ô tìm.' : 'Kỳ này không có hóa đơn nào dùng chương trình.') + '</td></tr>');
                    return;
                }
                const o = (d, k) => laChu(k) ? thoat(d[k] || '-') : tien(d[k]);
                let html = '';
                ds.forEach(function (d, i) {
                    html += '<tr><td class="fw-bold">' + (i + 1) + '</td>' + hop.cot.map(([k]) => '<td class="'
                        + (laChu(k) ? 'chu' : 'so') + (k === 'discount' ? ' tien-km' : '') + '">'
                        + (k === 'code' ? '<b>' + o(d, k) + '</b>' : o(d, k)) + '</td>').join('') + '</tr>';
                });
                // Dòng tổng ở CUỐI như v2: "Tổng cộng: N hóa đơn" phủ các cột chữ đầu bảng.
                const dau = hop.cot.findIndex(([k]) => !laChu(k));
                html += '<tr class="dong-tong"><td colspan="' + (dau + 1) + '" class="chu">Tổng cộng: ' + ds.length + ' hóa đơn</td>'
                    + hop.cot.slice(dau).map(([k]) => '<td class="' + (laChu(k) ? 'chu' : 'so') + (k === 'discount' ? ' tien-km' : '') + '">'
                        + (laChu(k) ? '' : tien(ds.reduce((s, d) => s + (Number(d[k]) || 0), 0))) + '</td>').join('') + '</tr>';
                $('#dkmThan').html(html);
            }

            $(document).on('click', '.ten-ct', function () {
                const tab = tabDangXem();
                hop = { cot: Object.entries(COT_DON[tab] || {}), don: [] };
                const q = new URLSearchParams(location.search);
                q.set('tab', tab);
                q.set('program_id', $(this).data('id'));

                $('#dkmTieuDe').text(TIEU_DE[tab] || 'Chi tiết');
                $('#dkmTen').text($(this).data('ten'));
                $('#dkmKy').text('');
                $('#dkmXuat').attr('href', URL_XUAT + '?' + q);
                $('#modalDonKM .search-order').val('');
                $('#dkmDau').html('<th>STT</th>' + hop.cot.map(([k, nhan]) => '<th class="' + (laChu(k) ? 'chu' : 'so') + '">' + thoat(nhan) + '</th>').join(''));
                $('#dkmThan').html('<tr><td colspan="' + (hop.cot.length + 1) + '" class="text-center py-4">Đang tải…</td></tr>');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDonKM')).show();

                $.ajax({ url: URL_DON + '?' + q, headers: { Accept: 'application/json' } })
                    .done(function (r) {
                        hop.don = r.data || [];
                        $('#dkmKy').text(r.ky ? '· ' + r.ky : '');
                        veHop('');
                    })
                    .fail(function (x) {
                        $('#dkmThan').html('<tr><td colspan="' + (hop.cot.length + 1) + '" class="text-center text-danger py-4">'
                            + thoat((x.responseJSON && x.responseJSON.message) || 'Không tải được hóa đơn.') + '</td></tr>');
                    });
            });

            let timerDon = null;
            $(document).on('input', '#modalDonKM .search-order', function () {
                clearTimeout(timerDon);
                const v = this.value;
                timerDon = setTimeout(() => veHop(v), 200);
            });
        })();
    </script>
@endpush
