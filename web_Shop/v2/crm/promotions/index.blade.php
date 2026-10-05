{{-- CRM → Chương trình khuyến mãi → tab "Chương trình khuyến mại" — khuôn
     crm/promotion-program của bản v2 (index + list + modal).

     Bám v2: khung lọc bốn khối (Thời gian theo NGÀY TẠO, mặc định đầu tháng →
     hôm nay · Chi nhánh · Mã khuyến mãi · Trạng thái), tiêu đề "Danh sách các
     chương trình khuyến mại", nút Tạo mới + Xuất Excel + chọn cột; bảng Mã · Tên ·
     Duyệt · Trạng thái (công tắc) · Từ ngày · Đến ngày · Chi nhánh (mã) · nhân
     bản / xem / sửa / xoá; hộp Thêm/Sửa với Đóng · Lưu tạm · Duyệt, Điều kiện áp
     dụng (ngày, không giới hạn thời gian, thứ trong tuần), Chi nhánh áp dụng,
     Loại khuyến mại, thẻ Trạng thái, bảng bậc bên trái + hàng tặng của bậc đang
     chọn bên phải; hộp "Huỷ duyệt trước khi sửa", hộp nhân bản gốc → bản sao.

     Khác v2: loại "Nhóm menu / Danh sách menu" thành "Nhóm hàng / Danh sách hàng";
     bỏ loại "Số lượng khách hàng" (quầy shop không nhập số khách); mã chương trình
     do hệ thống cấp (chủ tiệm chốt); hàng tặng chọn thẳng biến thể; bậc sửa ngay
     tại chỗ thay cho vòng lưu-sửa từng dòng.

     Bảng KHÔNG xuống dòng (luật đã chốt cho mọi bảng v2). --}}
@extends('v2::layouts.master')

@section('title', 'Chương trình khuyến mại')

@php
    $C = \App\Http\Controllers\PromotionProgramController::class;
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $ngay = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '-';
    $maChiNhanh = collect($chiNhanh)->mapWithKeys(fn ($c) => [$c['id'] => ($c['code'] ?? '') ?: ($c['name'] ?? '')])->all();
    $bienThe = collect($sanPham)->flatMap(fn ($p) => $p['variants'])->values();

    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $columns = [];
    foreach (array_keys($C::COT) as $c) {
        $columns[$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }
    $an = fn ($c) => $columns[$c] ? '' : 'hide';
@endphp

@push('styles')
    <style>
        table.table-promotion.none_mobile { width: 100%; table-layout: auto; }
        table.table-promotion.none_mobile th,
        table.table-promotion.none_mobile td { white-space: nowrap; vertical-align: middle; padding: 6px 8px; }
        table.table-promotion tr.item { height: 55px; }
        table.table-promotion td.action a { padding: 0 3px; cursor: pointer; font-size: 13px; }
        @media (max-width: 1439.98px) {
            table.table-promotion.none_mobile th,
            table.table-promotion.none_mobile td { font-size: 12.5px; padding: 6px 5px; }
        }

        /* Bảng danh sách: dòng tiêu đề cao 55px, chữ 14px đậm — đo trên bản v2. */
        table.table-promotion.none_mobile th { height: 55px; font-size: 14px; font-weight: 700; padding: 8px; }
        table.table-promotion.none_mobile td { font-size: 14px; padding: 8px; }

        /* ---------- Hộp Thêm / Sửa / Xem — đo trên bản v2 (modal-xl, min-width 60%) ---------- */
        #modalCreatePromotion .modal-dialog { min-width: 60%; }
        #modalCreatePromotion .modal-header { padding: 7px 16px; }
        #modalCreatePromotion .modal-title { font-size: 16px; font-weight: 500; color: #1a2b58; }
        #modalCreatePromotion label.nhan { font-weight: bold; color: #1a2b58; }
        #modalCreatePromotion .form-control { font-size: 13px; border-radius: 6px; }
        #modalCreatePromotion .btn_header { padding: 5px 10px; }
        #modalCreatePromotion .btn_header.btn-success { min-width: 106px; }
        /* Viên thứ: 40px tròn; tắt = xám #a8a8a8 chữ đen viền #c2c2c2, bật = #4B9700 chữ trắng. */
        .list-day-of-week .content-day {
            width: 40px; height: 40px; border-radius: 50%; border: 1px solid #c2c2c2; background: #a8a8a8; color: #000;
            display: flex; align-items: center; justify-content: center; cursor: pointer; user-select: none; font-size: 14px;
        }
        .list-day-of-week .content-day.active { background: #4B9700; border-color: #458a01; color: #fff; }
        .km-khoa .content-day { cursor: not-allowed; }
        .title-header-content { display: inline-block; background: #D5E1F3; border-radius: 5px 5px 0 0; padding: 7px 10px; }
        .title-header-content .title-type { font-size: 15px; font-weight: 700; color: #1A2B58; }
        /* Bảng bậc + bảng hàng tặng: ô cao 55px, đệm 8px; loại 0 tiêu đề trắng, loại 2/3 tiêu đề xám. */
        .table-type-promotion th, .table-type-promotion td, .table-gift th, .table-gift td {
            height: 55px; padding: 8px; vertical-align: middle; text-align: center; font-size: 14px;
        }
        .table-type-promotion th, .table-gift th { font-weight: 700; vertical-align: bottom; }
        .table-type-promotion.table-custom th { background: #e9e9e9; }
        .table-type-promotion tr.tr-row { cursor: pointer; }
        .table-type-promotion tr.tr-row.active td { background: #D5E1F3; }
        .table-type-promotion .fa, .table-gift .fa { font-size: 14px; cursor: pointer; color: #4a6fa5; }
        .table-type-promotion .btn-add.tat, .table-gift .btn-add.tat { opacity: .35; pointer-events: none; }
        .table-type-promotion .form-control, .table-gift .form-control { font-size: 13px; }
        #modalCreatePromotion .select2-container { width: 100% !important; text-align: left; }
        #modalCreatePromotion .select2-container--open, #modalCreatePromotion .select2-dropdown { z-index: 1065 !important; }
        .km-nhan-ban { display: flex; align-items: center; gap: 16px; }
        .km-nhan-ban > div { flex: 1; border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; }
    </style>
@endpush

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @foreach (['filterTime' => __('message.time'), 'filterBranch' => __('message.branch'),
                'filterEnterCoupon' => 'Mã khuyến mãi', 'filterStatus' => __('message.status')] as $khoi => $nhan)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile', 'dataOffcanvasTarget' => $khoi, 'modalLabel' => $nhan,
                ])
            @endforeach
        </div>
    </div>

    <div class="row index-promotion-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">Bộ lọc</div>
                    <div class="card-body px-2">
                        <form action="{{ route('admin.crm.promotions.index') }}" method="GET" id="search-form">
                            <div id="filterTime" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.from_date') }}</span>
                                    <input type="text" name="from_date" class="form-control km-ngay-loc mb-2" autocomplete="off"
                                        value="{{ $filters['from_date'] ? date('d-m-Y', strtotime($filters['from_date'])) : '' }}" placeholder="{{ __('message.from_date') }}">
                                    <span class="title_search d-none d-lg-block">{{ __('message.to_date') }}</span>
                                    <input type="text" name="to_date" class="form-control km-ngay-loc" autocomplete="off"
                                        value="{{ $filters['to_date'] ? date('d-m-Y', strtotime($filters['to_date'])) : '' }}" placeholder="{{ __('message.to_date') }}">
                                </div>
                            </div>
                            <div id="filterBranch" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.branch') }}</span>
                                    <select name="shop_ids[]" class="form-control km-chon-nhieu" multiple data-placeholder="{{ __('message.all') }}">
                                        @foreach ($chiNhanh as $cn)
                                            <option value="{{ $cn['id'] }}" {{ in_array((int) $cn['id'], $filters['shop_ids'], true) ? 'selected' : '' }}>{{ $cn['name'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div id="filterEnterCoupon" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">Mã khuyến mãi</span>
                                    <select name="codes[]" class="form-control km-chon-nhieu" multiple data-placeholder="{{ __('message.all') }}">
                                        @foreach ($maCT as $ma)
                                            <option value="{{ $ma }}" {{ in_array($ma, $filters['codes'], true) ? 'selected' : '' }}>{{ $ma }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div id="filterStatus" class="mb-3">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search d-none d-lg-block">{{ __('message.status') }}</span>
                                    <select name="statuses[]" class="form-control km-chon-nhieu" multiple data-placeholder="Chọn trạng thái">
                                        @foreach (['0' => 'Không hoạt động', '1' => 'Hoạt động'] as $ma => $ten)
                                            <option value="{{ $ma }}" {{ in_array((string) $ma, $filters['statuses'], true) ? 'selected' : '' }}>{{ $ten }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="content_midd">
                @include('v2::crm.promotions._tabs')
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">Danh sách các chương trình khuyến mại</h1>
                    <div class="btn_top_content d-flex align-items-center gap-2">
                        {{-- Thiếu quyền thì GIẤU hẳn hai nút này: bày ra rồi bấm vào
                             lại ăn thêm một lỗi 403 nữa là bắt người dùng tự dò. --}}
                        @unless ($thieuQuyen ?? false)
                            <a type="button" class="bt btn_green add-item">Tạo mới</a>
                            <a class="btn btn-sm d-flex align-items-center btn-export" href="{{ route('admin.crm.promotions.export', request()->query()) }}">
                                <i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất Excel
                            </a>
                        @endunless
                        <div class="dropup">
                            <button type="button" class="btn active dropbtn setting-col">
                                <i class="fa fa-sliders" aria-hidden="true"></i>
                                <div class="dropup-content">
                                    <div class="list_filter">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="show_all" {{ count($cotTat) ? '' : 'checked' }}>
                                            <label for="show_all">{{ __('message.all') }}</label>
                                        </div>
                                        @foreach ($C::COT as $cot => $chu)
                                            <div class="form-check">
                                                <input class="form-check-input show_col" data-col="{{ $cot }}" type="checkbox" id="km-cot-{{ $cot }}" {{ $columns[$cot] ? 'checked' : '' }}>
                                                <label for="km-cot-{{ $cot }}">{{ $chu }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-striped table-promotion none_mobile">
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center">{{ __('message.stt') }}</th>
                                    <th class="text-left {{ $an('program_code') }}">Mã chương trình</th>
                                    <th class="text-left {{ $an('program_name') }}">Tên chương trình</th>
                                    <th class="text-left {{ $an('approve') }}">Duyệt</th>
                                    <th class="text-center {{ $an('status') }}">{{ __('message.status') }}</th>
                                    <th class="text-right {{ $an('from_date') }}">{{ __('message.from_date') }}</th>
                                    <th class="text-right {{ $an('to_date') }}">{{ __('message.to_date') }}</th>
                                    <th class="text-left {{ $an('branch') }}">{{ __('message.branch') }}</th>
                                    <th class="text-center">{{ __('message.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($list as $i => $p)
                                    <tr class="item" data-id="{{ (int) $p['id'] }}">
                                        <td class="text-center">{{ $stt + $i + 1 }}</td>
                                        <td class="text-left {{ $an('program_code') }}">{{ $p['code'] }}</td>
                                        <td class="text-left {{ $an('program_name') }}">{{ $p['name'] }}</td>
                                        <td class="text-left {{ $an('approve') }}">
                                            {!! ! empty($p['approved']) ? '<b class="text-success">Đã được duyệt</b>' : '<b class="text-secondary">Lưu tạm</b>' !!}
                                        </td>
                                        <td class="text-center {{ $an('status') }}">
                                            <input type="checkbox" class="switch_customer item-status" {{ ! empty($p['status']) ? 'checked' : '' }}>
                                        </td>
                                        <td class="text-right {{ $an('from_date') }}">{{ ! empty($p['no_time_limit']) ? '-' : $ngay($p['start_date']) }}</td>
                                        <td class="text-right {{ $an('to_date') }}">{{ ! empty($p['no_time_limit']) ? '-' : $ngay($p['end_date']) }}</td>
                                        <td class="text-left {{ $an('branch') }}">
                                            {{ ! empty($p['all_shops']) ? collect($maChiNhanh)->implode(',') : collect($p['shop_ids'])->map(fn ($id) => $maChiNhanh[$id] ?? '#'.$id)->implode(',') }}
                                        </td>
                                        <td class="text-center action">
                                            <a class="copy-item" title="Nhân bản"><i class="fa fa-copy"></i></a>
                                            <a class="view-item" title="Xem"><i class="fa fa-eye"></i></a>
                                            <a class="edit_bt edit-item" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                            <a class="dele_bt delete-item" title="{{ __('message.delete') }}"><i class="fa fa-trash"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    {{-- Bị CHẶN khác với CHƯA CÓ: nói đúng cái đang xảy ra,
                                         kèm chỗ đi xin quyền. --}}
                                    <tr><td colspan="9" class="text-center py-4">
                                        @if ($thieuQuyen ?? false)
                                            Bạn chưa được giao quyền xem chương trình khuyến mại.
                                            Nhờ chủ tiệm mở quyền ở màn Phân quyền.
                                        @else
                                            Không có chương trình khuyến mại nào.
                                        @endif
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>

                        <div class="table-promotion none_desktop">
                            @foreach ($list as $p)
                                <div class="item p-2 border-bottom" data-id="{{ (int) $p['id'] }}">
                                    <div class="d-flex justify-content-between"><b>{{ $p['code'] }}</b>
                                        <span>{!! ! empty($p['approved']) ? '<b class="text-success">Đã được duyệt</b>' : '<b class="text-secondary">Lưu tạm</b>' !!}</span></div>
                                    <div>{{ $p['name'] }}</div>
                                    <div class="d-flex justify-content-between">
                                        <small class="text-muted">{{ ! empty($p['no_time_limit']) ? '-' : $ngay($p['start_date']).' → '.$ngay($p['end_date']) }}</small>
                                        <span class="action"><a class="view-item px-1"><i class="fa fa-eye"></i></a><a class="edit-item px-1"><i class="fa fa-edit"></i></a></span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <script type="application/json" id="v2-rows">@json(collect($list)->keyBy('id'))</script>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width {{ count($list) ? '' : 'd-none' }}" data-param="page_size">
                    @foreach ($C::PAGE_SIZES as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>{{ __('message.display', ['name' => $muc]) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Thêm / Sửa / Xem — #modalCreatePromotion của v2 =====================
         Dựng theo HTML của bản v2 đang chạy: ✕ trên thanh tiêu đề, hàng nút Đóng ·
         Lưu tạm · Duyệt riêng một hàng (giấu khi Xem), hai cột đều nhau, rồi đường
         kẻ và phần chi tiết (bảng bậc trái, bảng hàng tặng của bậc đang chọn phải). --}}
    <div class="modal" id="modalCreatePromotion" data-id="">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="km-tieu-de">Thêm chương trình khuyến mại</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-end km-hang-nut">
                        <button type="button" class="btn btn-sm btn-danger btn_header mx-1" data-bs-dismiss="modal">Đóng</button>
                        <button type="button" class="btn btn-sm btn-primary btn_header mx-1 km-luu" data-duyet="0">Lưu tạm</button>
                        <button type="button" class="btn btn-sm btn-success btn_header mx-1 km-luu" data-duyet="1">Duyệt</button>
                    </div>
                    <div class="row">
                        <div class="col-12 col-md-6">
                            <div class="mb-2">
                                <label class="mb-1 nhan">Mã chương trình</label>
                                <input type="text" class="form-control" id="km-ma" disabled placeholder="Hệ thống tự sinh">
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Tên chương trình <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="km-ten" maxlength="100" autocomplete="off" placeholder="Tên chương trình">
                            </div>
                            <div class="mb-2" id="km-dieu-kien">
                                <label class="mb-1 nhan">Điều kiện áp dụng <span class="text-danger">*</span></label>
                                <div class="d-flex flex-column">
                                    <label class="mb-1">Ngày áp dụng <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control km-ngay mb-2" id="km-tu" placeholder="Từ ngày" autocomplete="off">
                                    <input type="text" class="form-control km-ngay mb-2" id="km-den" placeholder="Đến ngày" autocomplete="off">
                                    <div class="mb-2">
                                        <input type="checkbox" class="form-check-input mx-1" id="km-khong-han">
                                        <label for="km-khong-han">Không có giới hạn thời gian</label>
                                    </div>
                                    <div class="mb-2">
                                        <input type="checkbox" class="form-check-input mx-1" id="km-co-thu">
                                        <label for="km-co-thu">Thứ trong tuần</label>
                                    </div>
                                    <div class="list-day-of-week" id="km-thu" style="display:none">
                                        <div class="d-flex justify-content-lg-around mb-1">
                                            @foreach ($C::THU as $so => $ten)
                                                <div class="content-day mx-1" data-thu="{{ $so }}"><span>{{ $ten }}</span></div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="mb-2">
                                <label class="mb-1 nhan">Chi nhánh áp dụng <span class="text-danger">*</span></label>
                                <select class="form-control" id="km-chi-nhanh" multiple>
                                    <option value="all">Tất cả</option>
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}">{{ $cn['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Loại khuyến mại</label>
                                <select class="form-control" id="km-loai">
                                    @foreach ($C::LOAI as $ma => $ten)
                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="w-100 mb-2" style="background-color: #D5E1F3; border-radius: 16px; padding: 12px 14px;">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="d-flex flex-column">
                                        <div class="fw-bold mb-1" style="font-size: 16px; line-height: 1.2;">Trạng thái</div>
                                        <div class="text-muted" style="font-size: 12px; line-height: 1.2;">bật khi cho phép sử dụng ngay</div>
                                    </div>
                                    <input type="checkbox" class="switch_customer ms-1" id="km-bat">
                                </div>
                                <div class="bg-white border rounded p-2 d-flex gap-2 align-items-start mt-2">
                                    <i class="fa fa-info-circle" style="margin-top: 2px; font-size: 14px;"></i>
                                    <div class="small text-wrap">Hệ thống hiện tại đang cho phép gộp nhiều loại khuyến mại khi thanh toán cùng một hóa đơn.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <hr class="my-2">
                    <div class="title-header-content"><span class="title-type" id="km-ten-loai">Phiếu bán hàng</span></div>
                    <div class="d-flex flex-column flex-lg-row row w-100 mx-0">
                        <div class="col-12 col-lg-7 px-0">
                            <table class="table table-type-promotion mb-0" id="km-bac"><thead></thead><tbody></tbody></table>
                        </div>
                        <div class="col-12 col-lg-5 mt-3 mt-lg-0 ps-lg-4 pe-0" id="km-qua-boc"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Đang Duyệt thì phải Huỷ duyệt trước khi sửa — hộp #cancelApprove của v2. --}}
    <div class="modal" id="cancelApprove" data-id="">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title">Huỷ duyệt</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body text-center" style="font-size:16px">
                    "<b id="hd-ten"></b>" Đang tiến hành "<b>Duyệt</b>" Cần xác nhận "<b>Hủy duyệt</b>" Trước khi sửa
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="bt btn_green" id="hd-xac-nhan">Xác nhận</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Nhân bản — "Đối tượng gốc → Đối tượng nhân bản" như #modalDuplicateObject của v2. --}}
    <div class="modal" id="modalDuplicateObject" data-id="">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title">Nhân bản</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="km-nhan-ban">
                        <div><b class="d-block mb-1">Đối tượng gốc</b><div>Mã: <span id="nb-ma-goc"></span></div><div>Tên: <span id="nb-ten-goc"></span></div></div>
                        <i class="fa-solid fa-arrow-right" style="font-size:20px;color:#1A2B58"></i>
                        <div><b class="d-block mb-1">Đối tượng nhân bản</b><div>Mã: Hệ thống tự sinh</div><div>Tên: <span id="nb-ten-moi"></span></div></div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="bt btn_green" id="nb-xac-nhan">Xác nhận</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="modalDeleteObject" data-id="">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title">Xoá</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body text-center">Bạn có chắc muốn xóa?</div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" id="xoa-xac-nhan">Xác nhận</button>
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Hủy</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_KM = @json(url('/admin/promotion-programs'));
        const URL_KM_TAO = @json(route('admin.ctkm.store'));
        const CSRF_KM = '{{ csrf_token() }}';
        const KM_LOAI = @json($C::LOAI);
        const KM_NHOM = @json(collect($danhMuc)->map(fn ($c) => ['id' => $c['id'], 'text' => $c['name']])->values());
        const KM_SP = @json(collect($sanPham)->map(fn ($p) => ['id' => $p['id'], 'text' => $p['name']])->values());
        const KM_BT = @json($bienThe->map(fn ($v) => ['id' => $v['id'], 'text' => $v['name'], 'unit' => $v['unit'] ?? ''])->values());

        let KM = docDong();
        function docDong() {
            try { return JSON.parse(document.getElementById('v2-rows').textContent) || {}; } catch (e) { return {}; }
        }
        $(document).on('v2:da-nap', function () { KM = docDong(); ganLoc(); });

        const chuSo = (v) => String(v == null ? '' : v).replace(/[^0-9]/g, '');
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        // ================= Bộ lọc — đổi ô nào là tải lại trang 1 (như v2) =================
        function locLai() {
            const $f = $('#search-form');
            const q = new URLSearchParams();
            ['from_date', 'to_date'].forEach((ten) => q.set(ten, String($f.find('[name="' + ten + '"]').val() || '').trim()));
            ['shop_ids[]', 'codes[]'].forEach((ten) => ($f.find('[name="' + ten + '"]').val() || []).forEach((v) => q.append(ten, v)));
            const tt = $f.find('[name="statuses[]"]').val() || [];
            tt.length ? tt.forEach((v) => q.append('statuses[]', v)) : q.append('statuses[]', '');
            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size'].forEach((ten) => { if (cu.get(ten)) q.set(ten, cu.get(ten)); });
            V2.napLai(location.pathname + '?' + q);
        }
        function ganLoc() {
            $('#search-form .km-chon-nhieu').each(function () {
                if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ width: '100%', placeholder: $(this).data('placeholder') });
            });
            $('#search-form .km-ngay-loc').each(function () {
                if ($(this).data('daterangepicker')) return;
                $(this).daterangepicker({ singleDatePicker: true, autoUpdateInput: false, showDropdowns: true, maxDate: moment(),
                    locale: Object.assign(V2.lichVN(), { format: 'DD-MM-YYYY' }) }, function (d) {
                    $(this.element).val(d.format('DD-MM-YYYY'));
                    locLai();
                });
            });
        }
        ganLoc();
        $(document).on('change', '#search-form select', locLai);

        // ================= Chọn cột =================
        function apDungCot() {
            const tat = $('.show_col').filter((i, el) => !el.checked).map((i, el) => $(el).data('col')).get();
            const q = new URLSearchParams(location.search);
            tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () { $('.show_col').prop('checked', this.checked); apDungCot(); });

        function goiJson(url, method, fields) {
            const fd = new FormData();
            fd.append('_token', CSRF_KM);
            if (method !== 'POST') fd.append('_method', method);
            $.each(fields || {}, (k, v) => fd.append(k, v));
            return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => r.json().then((b) => ({ ok: r.ok, b })));
        }

        $(document).on('change', '.list .item-status', function () {
            const id = $(this).closest('.item').data('id');
            goiJson(URL_KM + '/' + id + '/status', 'PUT', { status: this.checked ? 1 : 0 }).then(function (r) {
                r.ok ? toastr.success(r.b.message) : toastr.error(r.b.message || 'Không đổi được trạng thái.');
                if (!r.ok) V2.napLai(location.href, false);
            });
        });

        // ================= Hộp Thêm / Sửa / Xem =================
        // Bảng bậc chạy như v2: dòng đã lưu là CHỮ (✎ sửa, ✕ xoá); bấm "+" ở dòng
        // cuối mở MỘT dòng ô nhập (💾 lưu, ✕ huỷ) — đang sửa dòng nào thì "+" khoá.
        // Bảng hàng tặng chỉ hiện khi BẤM vào một dòng đã lưu (tô xanh) — mở hộp hay
        // lưu dòng xong thì chưa chọn dòng nào, đúng như v2.
        const $hop = $('#modalCreatePromotion');
        let bac = [];        // [{total_apply, object_id, quantity, formality, value, max_value, gifts:[{product_variant_id, quantity}]}]
        let dangChon = -1;   // dòng đang chọn (xem hàng tặng)
        let dangSua = null;  // chỉ số dòng đang sửa; bac.length = dòng mới
        let quaSua = null;   // chỉ số hàng tặng đang sửa; gifts.length = dòng mới
        let chiXem = false;

        const soPhay = (v) => (v === '' || v == null ? '' : Number(v).toLocaleString('en-US')); // v2 in 500,000
        const tenCua = (ds, id) => (ds.find((o) => Number(o.id) === Number(id)) || {}).text || '';
        const donVi = (id) => (KM_BT.find((o) => Number(o.id) === Number(id)) || {}).unit || '';

        $('#km-chi-nhanh').select2({ width: '100%', dropdownParent: $hop });
        $('#km-loai').select2({ width: '100%', dropdownParent: $hop, minimumResultsForSearch: Infinity });
        // "Tất cả" không đi cùng chi nhánh cụ thể — như v2.
        $('#km-chi-nhanh').on('select2:select', function (e) {
            const ds = $(this).val() || [];
            $(this).val(e.params.data.id === 'all' ? ['all'] : ds.filter((x) => x !== 'all')).trigger('change.select2');
        });
        $('.km-ngay').each(function () {
            $(this).daterangepicker({ singleDatePicker: true, autoUpdateInput: false, showDropdowns: true, minDate: moment().startOf('day'),
                locale: Object.assign(V2.lichVN(), { format: 'DD-MM-YYYY' }) }, function (d) {
                $(this.element).val(d.format('DD-MM-YYYY'));
                const tu = moment($('#km-tu').val(), 'DD-MM-YYYY'), den = moment($('#km-den').val(), 'DD-MM-YYYY');
                if (tu.isValid() && den.isValid() && den.isBefore(tu)) $('#km-den').val($('#km-tu').val());
            });
        });
        $('#km-khong-han').on('change', function () {
            $('.km-ngay').prop('disabled', this.checked || chiXem);
            if (this.checked) $('.km-ngay').val('');
        });
        $('#km-co-thu').on('change', function () { $('#km-thu').toggle(this.checked); });
        $('#km-thu').on('click', '.content-day', function () { if (!chiXem) $(this).toggleClass('active'); });

        const loai = () => Number($('#km-loai').val());

        function oChon(ds, chon, lop) {
            return '<select class="form-control ' + lop + '"><option value=""></option>'
                + ds.map((o) => '<option value="' + o.id + '"' + (Number(chon) === Number(o.id) ? ' selected' : '') + '>' + esc(o.text) + '</option>').join('')
                + '</select>';
        }
        const oSo = (lop, v, goiY) => '<input type="text" class="form-control format-money ' + lop + '" placeholder="' + goiY + '" value="' + soPhay(v) + '">';

        // Cột theo loại — đúng các bảng promotion-order / promotion-group / promotion-menu của v2.
        function veBac() {
            const l = loai();
            $('#km-ten-loai').text(KM_LOAI[l]);
            $('#km-bac').attr('class', 'table table-type-promotion mb-0 ' + (l === 0 ? 'table-bordered' : 'table-striped table-custom'));
            const dau = l === 0 ? '<th style="width:25%">Tổng giá trị đơn hàng</th><th style="width:20%">Hình thức</th><th style="width:20%">Giá trị (% / VNĐ)</th><th style="width:20%">Giá trị tối đa (VNĐ)</th>'
                : '<th style="width:30%">' + (l === 2 ? 'Nhóm hàng' : 'Tên hàng hóa') + '</th><th style="width:10%">Số lượng</th><th style="width:15%">Hình thức</th><th style="width:15%">Giá trị (% / VNĐ)</th><th style="width:15%">Giá trị tối đa (VNĐ)</th>';
            $('#km-bac thead').html('<tr>' + dau + '<th style="width:15%"></th></tr>');

            const nguon = l === 2 ? KM_NHOM : KM_SP;
            const $b = $('#km-bac tbody').empty();
            const dongSua = (d) => {
                const tien = Number(d.formality) === 1;
                return '<tr class="tr-new-option">'
                    + (l === 0 ? '<td>' + oSo('km-tong', d.total_apply, 'Tổng giá trị đơn hàng') + '</td>'
                        : '<td>' + oChon(nguon, d.object_id, 'km-doi-tuong') + '</td><td><input type="text" class="form-control km-sl" value="' + (d.quantity || 1) + '"></td>')
                    + '<td><select class="form-control km-hinh-thuc"><option value="0"' + (tien ? '' : ' selected') + '>%</option><option value="1"' + (tien ? ' selected' : '') + '>$</option></select></td>'
                    + '<td>' + oSo('km-gia-tri', d.value || '', 'Giá trị') + '</td>'
                    + '<td>' + oSo('km-toi-da', tien ? d.value : (d.max_value || ''), 'Giá trị tối đa (VNĐ)').replace('>', tien ? ' disabled>' : '>') + '</td>'
                    + '<td><i class="fa fa-save km-luu-dong mx-1 p-1"></i><i class="fa fa-close km-huy-dong mx-1 p-1"></i></td></tr>';
            };
            bac.forEach(function (d, i) {
                if (i === dangSua) { $b.append(dongSua(d)); return; }
                const tien = Number(d.formality) === 1;
                $b.append('<tr class="tr-row' + (i === dangChon ? ' active' : '') + '" data-i="' + i + '">'
                    + (l === 0 ? '<td>' + soPhay(d.total_apply) + '</td>' : '<td>' + esc(tenCua(nguon, d.object_id)) + '</td><td>' + d.quantity + '</td>')
                    + '<td>' + (tien ? '$' : '%') + '</td><td>' + soPhay(d.value) + '</td><td>' + soPhay(tien ? d.value : d.max_value) + '</td>'
                    + '<td>' + (chiXem ? '' : '<i class="fa fa-edit km-sua-dong mx-1 p-1"></i><i class="fa fa-close km-xoa-dong mx-1 p-1"></i>') + '</td></tr>');
            });
            if (dangSua === bac.length) $b.append(dongSua({ total_apply: '', object_id: '', quantity: 1, formality: 0, value: '', max_value: '' }));
            if (!chiXem) {
                const trong = '<td></td>'.repeat(l === 0 ? 4 : 5);
                $b.append('<tr class="tr-add">' + trong + '<td><i class="fa fa-plus custom-icon p-1 btn-add km-them-dong' + (dangSua !== null ? ' tat' : '') + '"></i></td></tr>');
            }
            $b.find('.km-doi-tuong').select2({ width: '100%', dropdownParent: $hop, placeholder: 'Chọn' });
            veQua();
        }

        function veQua() {
            const $q = $('#km-qua-boc').empty();
            const d = bac[dangChon];
            if (!d || dangSua === dangChon) return;
            if (chiXem && !(d.gifts || []).length) return;
            let h = '<table class="table table-gift mb-0"><thead><tr><th style="width:40%;min-width:120px">Tên hàng hóa</th>'
                + '<th style="width:20%;min-width:80px">Số lượng</th><th style="width:20%;min-width:50px">Đơn vị</th><th style="width:10%;min-width:30px"></th></tr></thead><tbody>';
            (d.gifts || []).forEach(function (g, j) {
                if (j === quaSua) {
                    h += '<tr><td>' + oChon(KM_BT, g.product_variant_id, 'km-bien-the') + '</td><td><input type="text" class="form-control km-sl-qua" placeholder="Số lượng" value="' + (g.quantity || '') + '"></td>'
                        + '<td class="km-don-vi">' + esc(donVi(g.product_variant_id)) + '</td><td><i class="fa fa-save km-luu-qua mx-1 p-1"></i><i class="fa fa-close km-huy-qua mx-1 p-1"></i></td></tr>';
                } else {
                    h += '<tr data-j="' + j + '"><td>' + esc(tenCua(KM_BT, g.product_variant_id)) + '</td><td>' + g.quantity + '</td><td>' + esc(donVi(g.product_variant_id)) + '</td>'
                        + '<td>' + (chiXem ? '' : '<i class="fa fa-edit km-sua-qua mx-1 p-1"></i><i class="fa fa-close km-xoa-qua mx-1 p-1"></i>') + '</td></tr>';
                }
            });
            if (quaSua === (d.gifts || []).length) {
                h += '<tr><td>' + oChon(KM_BT, '', 'km-bien-the') + '</td><td><input type="text" class="form-control km-sl-qua" placeholder="Số lượng"></td>'
                    + '<td class="km-don-vi"></td><td><i class="fa fa-save km-luu-qua mx-1 p-1"></i><i class="fa fa-close km-huy-qua mx-1 p-1"></i></td></tr>';
            }
            if (!chiXem) h += '<tr><td></td><td></td><td></td><td><i class="fa fa-plus custom-icon p-1 btn-add km-them-qua' + (quaSua !== null ? ' tat' : '') + '"></i></td></tr>';
            $q.html(h + '</tbody></table>');
            $q.find('.km-bien-the').select2({ width: '100%', dropdownParent: $hop, placeholder: 'Chọn' });
        }

        // Đổi loại thì xoá hết bậc và hàng tặng — như v2.
        $('#km-loai').on('change', function () { bac = []; dangChon = -1; dangSua = null; quaSua = null; veBac(); });

        $('#km-bac').on('click', '.km-them-dong', function () { dangSua = bac.length; quaSua = null; veBac(); });
        $('#km-bac').on('click', '.km-sua-dong', function (e) { e.stopPropagation(); dangSua = Number($(this).closest('tr').data('i')); quaSua = null; veBac(); });
        $('#km-bac').on('click', '.km-huy-dong', function () { dangSua = null; veBac(); });
        $('#km-bac').on('click', '.km-xoa-dong', function (e) {
            e.stopPropagation();
            if (!confirm('Bạn có chắc muốn xóa?')) return;
            bac.splice(Number($(this).closest('tr').data('i')), 1);
            dangChon = -1;
            veBac();
        });
        $('#km-bac').on('click', 'tr.tr-row', function () {
            if (dangSua !== null) return;
            dangChon = Number($(this).data('i'));
            quaSua = null;
            veBac();
        });
        // Ô số: định dạng 500,000 như v2; % tối đa 100; "$" thì tối đa = giá trị và khoá ô tối đa.
        $('#km-bac').on('input', '.format-money', function () {
            const $tr = $(this).closest('tr');
            let v = chuSo(this.value).slice(0, 12);
            if ($(this).hasClass('km-gia-tri') && $tr.find('.km-hinh-thuc').val() === '0' && Number(v) > 100) {
                v = '100';
                toastr.warning('Mức giảm theo % tối đa là 100');
            }
            this.value = v === '' ? '' : soPhay(v);
            if ($(this).hasClass('km-gia-tri') && $tr.find('.km-hinh-thuc').val() === '1') $tr.find('.km-toi-da').val(this.value);
        });
        $('#km-bac').on('input', '.km-sl', function () { this.value = chuSo(this.value).slice(0, 5); });
        $('#km-bac').on('change', '.km-hinh-thuc', function () {
            const $tr = $(this).closest('tr'), tien = this.value === '1';
            $tr.find('.km-toi-da').prop('disabled', tien).val(tien ? $tr.find('.km-gia-tri').val() : '');
            if (!tien && Number(chuSo($tr.find('.km-gia-tri').val())) > 100) {
                $tr.find('.km-gia-tri').val('100');
                toastr.warning('Mức giảm theo % tối đa là 100');
            }
        });
        $('#km-bac').on('click', '.km-luu-dong', function () {
            const $tr = $(this).closest('tr'), l = loai();
            const d = {
                total_apply: Number(chuSo($tr.find('.km-tong').val()) || 0),
                object_id: $tr.find('.km-doi-tuong').val() || '',
                quantity: Number(chuSo($tr.find('.km-sl').val()) || 0),
                formality: Number($tr.find('.km-hinh-thuc').val()),
                value: Number(chuSo($tr.find('.km-gia-tri').val()) || 0),
                max_value: Number(chuSo($tr.find('.km-toi-da').val()) || 0),
                gifts: (bac[dangSua] || {}).gifts || [],
            };
            const du = d.value > 0 && d.max_value > 0 && (l === 0 ? d.total_apply > 0 : d.object_id && d.quantity > 0);
            if (!du) { toastr.error('Vui lòng nhập đủ các giá trị!'); return; }
            if (l === 0 && d.formality === 1 && d.value > d.total_apply) { toastr.error('Giá trị không được lớn hơn tổng giá trị đơn hàng!'); return; }
            if (l === 0 && d.max_value > d.total_apply) { toastr.error('Giá trị tối đa không được lớn hơn tổng giá trị đơn hàng!'); return; }
            if (d.formality === 1) d.max_value = d.value;
            bac[dangSua] = d;
            dangChon = -1;
            dangSua = null;
            veBac();
        });

        $('#km-qua-boc').on('click', '.km-them-qua', function () { quaSua = bac[dangChon].gifts.length; veQua(); });
        $('#km-qua-boc').on('click', '.km-sua-qua', function () { quaSua = Number($(this).closest('tr').data('j')); veQua(); });
        $('#km-qua-boc').on('click', '.km-huy-qua', function () { quaSua = null; veQua(); });
        $('#km-qua-boc').on('click', '.km-xoa-qua', function () {
            if (!confirm('Bạn có chắc muốn xóa?')) return;
            bac[dangChon].gifts.splice(Number($(this).closest('tr').data('j')), 1);
            veQua();
        });
        $('#km-qua-boc').on('change', '.km-bien-the', function () { $(this).closest('tr').find('.km-don-vi').text(donVi(this.value)); });
        $('#km-qua-boc').on('input', '.km-sl-qua', function () { this.value = chuSo(this.value).slice(0, 4); });
        $('#km-qua-boc').on('click', '.km-luu-qua', function () {
            const $tr = $(this).closest('tr');
            const g = { product_variant_id: $tr.find('.km-bien-the').val() || '', quantity: Number(chuSo($tr.find('.km-sl-qua').val()) || 0) };
            if (!g.product_variant_id || g.quantity < 1) { toastr.error('Vui lòng nhập đủ các giá trị!'); return; }
            bac[dangChon].gifts[quaSua] = g;
            quaSua = null;
            veQua();
        });

        const ngayHop = (s) => (s ? s.split('-').reverse().join('-') : '');

        function moHop(che, p) {
            chiXem = che === 'view';
            const sua = che === 'edit';
            $hop.attr('data-id', sua ? p.id : '');
            $('#km-tieu-de').text(chiXem ? 'Thông tin chương trình khuyến mại' : (sua ? 'Chỉnh sửa chương trình khuyến mại' : 'Thêm chương trình khuyến mại'));
            $('.km-hang-nut').toggleClass('d-none', chiXem);
            $('.km-luu[data-duyet="1"]').text(sua ? 'Lưu và duyệt' : 'Duyệt');
            $('#km-ma').val(p ? p.code : '');
            $('#km-ten').val(p ? p.name : '').prop('disabled', chiXem);
            const homNay = moment().format('DD-MM-YYYY');
            $('#km-khong-han').prop('checked', p ? p.no_time_limit : false).prop('disabled', chiXem);
            $('#km-tu').val(p ? ngayHop(p.start_date) : homNay);
            $('#km-den').val(p ? ngayHop(p.end_date) : homNay);
            $('#km-khong-han').trigger('change');
            // Thứ trong tuần: bật lên thì Thứ Hai → Thứ Sáu sáng sẵn (v2).
            const thu = p ? p.days_of_week : [1, 2, 3, 4, 5];
            const coThu = p ? thu.length < 7 : false;
            $('#km-co-thu').prop('checked', coThu).prop('disabled', chiXem).trigger('change');
            $('#km-thu .content-day').each(function () { $(this).toggleClass('active', (coThu ? thu : [1, 2, 3, 4, 5]).includes(Number($(this).data('thu')))); });
            $('#km-dieu-kien').toggleClass('km-khoa', chiXem);
            // Tạo mới: chi nhánh để TRỐNG như v2; sửa / xem "Tất cả" thì bày đủ từng chi nhánh.
            const tatCaCN = $('#km-chi-nhanh option').map((i, o) => o.value).get().filter((v) => v !== 'all');
            $('#km-chi-nhanh').val(p ? (p.all_shops ? tatCaCN : p.shop_ids.map(String)) : []).trigger('change.select2').prop('disabled', chiXem);
            $('#km-loai').val(p ? String(p.type) : '0').trigger('change.select2').prop('disabled', chiXem);
            // Trạng thái mặc định TẮT khi tạo mới — như v2.
            $('#km-bat').prop('checked', p ? p.status : false).prop('disabled', chiXem);
            bac = p ? p.details.map((d) => ({ total_apply: d.total_apply, object_id: d.object_id, quantity: d.quantity, formality: d.formality,
                value: d.value, max_value: d.max_value, gifts: d.gifts.map((g) => ({ product_variant_id: g.product_variant_id, quantity: g.quantity })) })) : [];
            dangChon = -1;
            dangSua = null;
            quaSua = null;
            veBac();
            $hop.modal('show');
        }

        $(document).on('click', '.add-item', () => moHop('add', null));
        $(document).on('click', '.view-item', function () { moHop('view', KM[$(this).closest('.item').data('id')]); });
        $(document).on('click', '.edit-item', function () {
            const p = KM[$(this).closest('.item').data('id')];
            if (p.approved) {
                $('#cancelApprove').attr('data-id', p.id);
                $('#hd-ten').text(p.name);
                $('#cancelApprove').modal('show');
                return;
            }
            moHop('edit', p);
        });
        $('#hd-xac-nhan').on('click', function () {
            const id = $('#cancelApprove').attr('data-id');
            goiJson(URL_KM + '/' + id + '/cancel-approve', 'POST', {}).then(function (r) {
                if (!r.ok) { toastr.error(r.b.message || 'Không huỷ duyệt được.'); return; }
                $('#cancelApprove').modal('hide');
                moHop('edit', Object.assign({}, KM[id], { approved: false }));
                V2.napLai(location.href, false);
            });
        });

        $('.km-luu').on('click', function () {
            const id = $hop.attr('data-id');
            if (dangSua !== null || quaSua !== null) { toastr.error('Có chi tiết khuyến mại chưa được lưu, vui lòng kiểm tra lại!'); return; }
            if (!$('#km-ten').val().trim()) { toastr.error('Nhập tên chương trình.'); return; }
            const khongHan = $('#km-khong-han').is(':checked');
            if (!khongHan && (!$('#km-tu').val() || !$('#km-den').val())) { toastr.error('Chọn ngày áp dụng.'); return; }
            const thu = $('#km-co-thu').is(':checked') ? $('#km-thu .content-day.active').map((i, el) => $(el).data('thu')).get() : [1, 2, 3, 4, 5, 6, 7];
            if (!thu.length) { toastr.error('Chọn ít nhất một ngày trong tuần.'); return; }
            const cn = $('#km-chi-nhanh').val() || [];
            if (!cn.length) { toastr.error('Chọn chi nhánh áp dụng.'); return; }
            if (!bac.length) { toastr.error('Chi tiết khuyến mãi không được để trống.'); return; }
            const tatCa = cn.includes('all') || cn.length === $('#km-chi-nhanh option').length - 1;
            V2.luuHop($hop, id ? URL_KM + '/' + id : URL_KM_TAO, id ? 'PUT' : 'POST', {
                name: $('#km-ten').val().trim(),
                type: loai(),
                approved: $(this).data('duyet'),
                status: $('#km-bat').is(':checked') ? 1 : 0,
                no_time_limit: khongHan ? 1 : 0,
                start_date: khongHan ? '' : $('#km-tu').val(),
                end_date: khongHan ? '' : $('#km-den').val(),
                'days_of_week[]': thu,
                all_shops: tatCa ? 1 : 0,
                'shop_ids[]': tatCa ? [] : cn,
                details: JSON.stringify(bac),
            }, $(this));
        });

        // ================= Nhân bản =================
        $(document).on('click', '.copy-item', function () {
            const p = KM[$(this).closest('.item').data('id')] || {};
            $('#modalDuplicateObject').attr('data-id', p.id);
            $('#nb-ma-goc').text(p.code || '');
            $('#nb-ten-goc').text(p.name || '');
            $('#nb-ten-moi').text((p.name || '') + 'COPY');
            $('#modalDuplicateObject').modal('show');
        });
        $('#nb-xac-nhan').on('click', function () {
            V2.luuHop('#modalDuplicateObject', URL_KM + '/' + $('#modalDuplicateObject').attr('data-id') + '/duplicate', 'POST', {}, $(this));
        });

        // ================= Xoá =================
        $(document).on('click', '.delete-item', function () {
            $('#modalDeleteObject').attr('data-id', $(this).closest('.item').data('id')).modal('show');
        });
        $('#xoa-xac-nhan').on('click', function () {
            V2.luuHop('#modalDeleteObject', URL_KM + '/' + $('#modalDeleteObject').attr('data-id'), 'DELETE', {}, $(this));
        });
    </script>
@endpush
