{{-- CRM → Chương trình khuyến mãi → tab "Voucher/Coupon" — dựng theo bản v2 đang
     chạy (crm/voucher-coupon: index + list + list-detail).

     Một chương trình phát ra nhiều mã (tiền tố + 5 ký tự ngẫu nhiên + hậu tố).
     "Lưu" = Chưa phát hành: xem · sửa · xoá, chưa có mã. "Phát hành" = sinh đủ
     mã, chỉ còn xem; bấm TÊN chương trình mở "Danh sách mã chi tiết" bên dưới
     bảng, mỗi mã có nút "Chi tiết" (các lượt đã dùng) và công tắc bật / tắt.

     Khác v2: mã chương trình do hệ thống tự sinh; "Nhập từ file" để mờ như bản
     đang chạy.

     Bảng KHÔNG xuống dòng (luật đã chốt cho mọi bảng v2). --}}
@extends('v2::layouts.master')

@section('title', 'Voucher/Coupon')

@php
    $C = \App\Http\Controllers\VoucherCouponController::class;
    $stt = ($meta['page'] - 1) * ($meta['page_size'] ?? 10);
    $maChiNhanh = collect($chiNhanh)->mapWithKeys(fn ($c) => [$c['id'] => ($c['code'] ?? '') ?: ($c['name'] ?? '')])->all();
    $an = fn ($c) => $columns[$c] ? '' : 'hide';
    $tatCa = $filters['statuses'] === [];
@endphp

@push('styles')
    <style>
        table.table-list-program { width: 100%; table-layout: auto; }
        table.table-list-program th, table.table-list-program td { white-space: nowrap; vertical-align: middle; padding: 8px; font-size: 14px; }
        table.table-list-program th { height: 55px; font-weight: 700; }
        table.table-list-program tr.item { height: 55px; }
        table.table-list-program .hide { display: none; }
        table.table-list-program td.action a { padding: 0 3px; cursor: pointer; }
        table.table-list-program tr.dang-xem td { background: #D5E1F3; }
        table.table-list-program tr.dang-xem .campaign-detail { color: #fd7e14; }
        @media (max-width: 1439.98px) {
            table.table-list-program th, table.table-list-program td { font-size: 12.5px; padding: 6px 5px; }
        }
        .vc-loc .form-check { display: flex; align-items: center; gap: 7px; padding-left: 0; }
        .vc-loc .form-check-input { margin: 0; }

        /* Danh sách mã chi tiết */
        .voucher-detail .vc-dau { background: #D5E1F3; }
        table.table-list-detail { width: 100%; }
        table.table-list-detail th, table.table-list-detail td { white-space: nowrap; vertical-align: middle; padding: 8px; font-size: 14px; }
        table.table-list-detail th { height: 55px; font-weight: 700; background: #D3DAEB; }
        table.table-list-detail tr { height: 55px; }

        /* ---------- Hộp Thêm / Sửa / Xem — đo trên bản v2 (min-width 95%) ---------- */
        #vcHop .modal-dialog { min-width: 95%; }
        #vcHop .modal-title { font-size: 20px; font-weight: 500; color: #1a2b58; }
        #vcHop .vc-khoi { border: 1px solid #e9e9e9; padding: 10px; height: 100%; }
        #vcHop .vc-khoi h6 { font-weight: bold; font-size: 18px; color: #444; }
        #vcHop label.form-label { font-size: 14px; font-weight: 500; color: #1a2b58; }
        #vcHop .form-control { font-size: 13px; }
        #vcHop .vc-cao { height: 44px; }
        /* Ô chọn % / VNĐ dính bên phải ô giá trị, nền xanh lá như v2. */
        #vcHop .vc-kieu { width: 50px; background: #00c800; color: #fff; border: 0; text-align: center; font-size: 13px;
            border-radius: 0 6px 6px 0; appearance: none; -webkit-appearance: none; cursor: pointer; }
        #vcHop .vc-kieu option { color: #000; background: #fff; }
        #vcHop .vc-kieu:disabled { background: #00c800; color: #fff; opacity: 1; }
        #vcHop .modal-footer { justify-content: center; }
        #vcHop .modal-footer .btn { min-width: 106px; }
        #vcHop .select2-container { width: 100% !important; }
        #vcHop .select2-container--open, #vcHop .select2-dropdown { z-index: 1065 !important; }
        #vcHop.xem .form-control, #vcHop.xem .vc-kieu { pointer-events: none; }
    </style>
@endpush

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile', 'dataOffcanvasTarget' => 'filterSearch', 'modalLabel' => __('message.search'),
            ])
        </div>
    </div>

    <div class="row index-crm.voucher-coupon-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2 vc-loc">
                        <form action="{{ route('admin.crm.promotions.voucher') }}" method="GET" id="search-form">
                            <div id="filterSearch">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.branch') }}</span>
                                    <div class="mt-1">
                                        <select name="shop_ids[]" class="form-control vc-chon-nhieu" multiple data-placeholder="{{ __('message.branch') }}">
                                            @foreach ($chiNhanh as $cn)
                                                <option value="{{ $cn['id'] }}" {{ in_array((int) $cn['id'], $filters['shop_ids'], true) ? 'selected' : '' }}>{{ $cn['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="mt-3">
                                        <span class="title_search">{{ __('message.status') }}</span>
                                        <div class="form-check mt-1">
                                            <input class="form-check-input vc-tt" type="checkbox" value="" id="vc-tt-all" {{ $tatCa ? 'checked' : '' }}>
                                            <label class="form-check-label text-secondary" for="vc-tt-all">{{ __('message.all') }}</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input vc-tt" type="checkbox" value="2" id="vc-tt-2" {{ in_array(2, $filters['statuses'], true) ? 'checked' : '' }}>
                                            <label class="form-check-label text-warning" for="vc-tt-2">Phát hành</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input vc-tt" type="checkbox" value="1" id="vc-tt-1" {{ in_array(1, $filters['statuses'], true) ? 'checked' : '' }}>
                                            <label class="form-check-label text-primary" for="vc-tt-1">Chưa phát hành</label>
                                        </div>
                                    </div>
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
                    <h1 class="tieu-de-trang">Danh sách chương trình</h1>
                    <div class="btn_top_content d-flex align-items-center gap-2">
                        {{-- Thiếu quyền thì giấu hai nút này. --}}
                        @unless ($thieuQuyen ?? false)
                            <a type="button" class="bt btn_green add-voucher">Tạo</a>
                            <a class="btn btn-sm d-flex align-items-center btn-export" href="{{ route('admin.voucher-coupon.export', request()->query()) }}">
                                <i class="fa-solid fa-file-export my-auto mx-1"></i> Xuất Excel
                            </a>
                        @endunless
                        <div class="dropup">
                            <button type="button" class="btn active dropbtn setting-col">
                                <i class="fa fa-sliders" aria-hidden="true"></i>
                                <div class="dropup-content">
                                    <div class="list_filter">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="show_all" {{ in_array(false, $columns, true) ? '' : 'checked' }}>
                                            <label for="show_all">{{ __('message.all') }}</label>
                                        </div>
                                        @foreach ($C::COT as $cot => [$chu])
                                            <div class="form-check">
                                                <input class="form-check-input show_col" data-col="{{ $cot }}" type="checkbox" id="vc-{{ $cot }}" {{ $columns[$cot] ? 'checked' : '' }}>
                                                <label for="vc-{{ $cot }}">{{ $chu }}</label>
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
                        <table class="table-striped table-list-program none_mobile">
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center">{{ __('message.stt') }}</th>
                                    <th class="text-left show_code {{ $an('show_code') }}">Mã chương trình</th>
                                    <th class="text-left show_program_name {{ $an('show_program_name') }}">Tên chương trình</th>
                                    <th class="text-left show_program_description {{ $an('show_program_description') }}">Mô tả chương trình</th>
                                    <th class="text-right show_min_order_value {{ $an('show_min_order_value') }}">Áp dụng cho hóa đơn trên</th>
                                    <th class="text-left show_branch {{ $an('show_branch') }}">Chi nhánh</th>
                                    <th class="text-right show_quantity {{ $an('show_quantity') }}">Số lượng</th>
                                    <th class="text-left show_promotion_type {{ $an('show_promotion_type') }}">Hình thức</th>
                                    <th class="text-right show_value {{ $an('show_value') }}">Giá trị (%, $)</th>
                                    <th class="text-left show_status {{ $an('show_status') }}">Trạng thái</th>
                                    <th class="text-right show_start_date {{ $an('show_start_date') }}">Từ ngày</th>
                                    <th class="text-right show_end_date {{ $an('show_end_date') }}">Đến ngày</th>
                                    <th class="text-left show_creator {{ $an('show_creator') }}">Người tạo</th>
                                    <th class="text-center">{{ __('message.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($list as $i => $p)
                                    <tr class="item" data-id="{{ (int) $p['id'] }}">
                                        <td class="text-center">{{ $stt + $i + 1 }}</td>
                                        <td class="text-left show_code {{ $an('show_code') }}">{{ $p['code'] }}</td>
                                        <td class="text-left show_program_name {{ $an('show_program_name') }}">
                                            @if ((int) $p['status'] === 2)
                                                <a href="#" class="campaign-detail text-decoration-none" title="{{ $p['name'] }}">{{ \Illuminate\Support\Str::limit($p['name'], 20) }}</a>
                                            @else
                                                {{ $p['name'] }}
                                            @endif
                                        </td>
                                        <td class="text-left show_program_description {{ $an('show_program_description') }}">{{ $p['description'] }}</td>
                                        <td class="text-right show_min_order_value {{ $an('show_min_order_value') }}">{{ number_format((float) $p['min_order_amount']) }}</td>
                                        <td class="text-left show_branch {{ $an('show_branch') }}">
                                            {{ ! empty($p['all_shops']) ? collect($maChiNhanh)->implode(', ') : collect($p['shop_ids'])->map(fn ($id) => $maChiNhanh[$id] ?? '#'.$id)->implode(', ') }}
                                        </td>
                                        <td class="text-right show_quantity {{ $an('show_quantity') }}">{{ $p['quantity'] }}</td>
                                        <td class="text-left show_promotion_type {{ $an('show_promotion_type') }}">{{ $C::hinhThuc($p) }}</td>
                                        <td class="text-right show_value {{ $an('show_value') }}">{{ $C::giaTri($p) }}</td>
                                        <td class="text-left show_status {{ $an('show_status') }}">{{ $C::TRANG_THAI[$p['status']] ?? '' }}</td>
                                        <td class="text-right show_start_date {{ $an('show_start_date') }}">{{ $C::ngay($p, 'start_date') }}</td>
                                        <td class="text-right show_end_date {{ $an('show_end_date') }}">{{ $C::ngay($p, 'end_date') }}</td>
                                        <td class="text-left show_creator {{ $an('show_creator') }}">{{ $p['created_by_name'] ?? '' }}</td>
                                        <td class="text-center action">
                                            <a class="view-item" title="Xem"><i class="fa fa-eye"></i></a>
                                            @if ((int) $p['status'] === 1)
                                                <a class="edit_bt edit-item" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                                <a class="dele_bt delete-item" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="14" class="text-center py-4">
                                            @if ($thieuQuyen ?? false)
                                                Bạn chưa được giao quyền xem voucher / coupon.
                                                Nhờ chủ tiệm mở quyền ở màn Phân quyền.
                                            @else
                                                Chưa có chương trình voucher/coupon nào. Bấm "Tạo" để lập chương trình đầu tiên.
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        <div class="table-list-program none_desktop">
                            @foreach ($list as $p)
                                <div class="item p-2 border-bottom" data-id="{{ (int) $p['id'] }}">
                                    <div class="d-flex justify-content-between"><b>{{ $p['code'] }}</b><span>{{ $C::TRANG_THAI[$p['status']] ?? '' }}</span></div>
                                    <div>{{ $p['name'] }} · {{ $C::giaTri($p) }}</div>
                                    <div class="text-end action"><a class="view-item px-1"><i class="fa fa-eye"></i></a></div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <script type="application/json" id="v2-rows">@json(collect($list)->keyBy('id'))</script>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                {{-- Danh sách mã chi tiết — hiện khi bấm tên một chương trình đã phát hành. --}}
                <div class="voucher-detail mt-3" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center vc-dau">
                        <h3 class="h5 my-1 mx-1 p-2">Danh sách mã chi tiết</h3>
                        <input type="text" style="width: 250px" id="search-voucher-code" class="form-control me-2" autocomplete="off"
                            placeholder="Tìm kiếm theo mã Voucher/Coupon">
                    </div>
                    <div class="table-responsive">
                        <table class="table-striped table-list-detail">
                            <thead>
                                <tr>
                                    <th class="text-center"></th>
                                    <th class="text-center">STT</th>
                                    <th class="text-left">Mã voucher/coupon</th>
                                    <th class="text-right">Ngày phát hành</th>
                                    <th class="text-right">Ngày hết hạn</th>
                                    <th class="text-right">Số lần áp dụng</th>
                                    <th class="text-right">Đã sử dụng</th>
                                    <th class="text-right">Giá trị (%, $)</th>
                                    <th class="text-center">Hành động</th>
                                </tr>
                            </thead>
                            <tbody id="vc-ma"></tbody>
                        </table>
                    </div>
                    <div class="form_pagi" id="vc-ma-trang"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Thêm / Sửa / Xem — #createVoucherModal của v2 ===================== --}}
    <div class="modal fade" id="vcHop" tabindex="-1" data-id="">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="vc-tieu-de">Thêm mới Voucher/Coupon</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="vc-khoi">
                                <h6 class="my-auto mb-3">1. Thông tin chung:</h6>
                                <div class="mt-4 mb-3 row justify-content-between align-items-center">
                                    <label class="form-label col-5">Mã chương trình</label>
                                    <div class="col-7"><input type="text" class="form-control" id="vc-ma-ct" disabled placeholder="Hệ thống tự sinh"></div>
                                </div>
                                <div class="mb-3 row justify-content-between">
                                    <label for="vc-ten" class="form-label col-5">Tên chương trình <span class="text-danger">*</span></label>
                                    <div class="col-7"><input type="text" class="form-control" id="vc-ten" maxlength="255" autocomplete="off" placeholder="Nhập tên chương trình"></div>
                                </div>
                                <div class="mb-3 row justify-content-between">
                                    <label for="vc-mo-ta" class="form-label col-5">Mô tả chương trình</label>
                                    <div class="col-7"><input type="text" class="form-control" id="vc-mo-ta" maxlength="255" autocomplete="off" placeholder="Nhập mô tả chương trình"></div>
                                </div>
                                <div class="mb-3 row justify-content-between">
                                    <label for="vc-gia-tri" class="form-label col-5">Hình thức khuyến mại <span class="text-danger">*</span></label>
                                    <div class="col-7">
                                        <div class="input-group" id="vc-nhom-gia-tri" data-bs-toggle="tooltip" data-bs-placement="top"
                                            data-bs-title="Bạn đang chọn hình thức phát thẻ coupon (%)">
                                            <input type="text" class="form-control vc-cao" id="vc-gia-tri" autocomplete="off" placeholder="Nhập giá trị khuyến mại">
                                            <select class="vc-kieu" id="vc-kieu">
                                                <option value="percentage">%</option>
                                                <option value="fixed">VNĐ</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3 row justify-content-between">
                                    <label for="vc-toi-thieu" class="form-label col-5">Áp dụng cho hóa đơn trên <span class="text-danger">*</span></label>
                                    <div class="col-7"><input type="text" class="form-control vc-cao vc-tien" id="vc-toi-thieu" autocomplete="off" placeholder="Nhập giá tiền tối thiểu để áp dụng"></div>
                                </div>
                                <div class="mb-3 row justify-content-between" id="vc-dong-toi-da">
                                    <label for="vc-toi-da" class="form-label col-5">Số tiền tối đa</label>
                                    <div class="col-7"><input type="text" class="form-control vc-cao vc-tien" id="vc-toi-da" autocomplete="off" placeholder="Nhập giá tiền tối đa để áp dụng"></div>
                                </div>
                                <div class="row align-items-center">
                                    <label class="form-label col-md-5">Chi nhánh áp dụng</label>
                                    <div class="col-md-7 d-flex">
                                        <div class="col-md-6"><input type="radio" id="vc-cn-all" name="vc-cn" value="all" checked> <label for="vc-cn-all">Toàn hệ thống</label></div>
                                        <div class="col-md-6 ms-3"><input type="radio" id="vc-cn-chon" name="vc-cn" value="specific"> <label for="vc-cn-chon">Theo chi nhánh</label></div>
                                    </div>
                                </div>
                                <div class="row" id="vc-dong-cn" style="display: none;">
                                    <div class="col-5"></div>
                                    <div class="col-7">
                                        <select id="vc-chi-nhanh" class="form-select" multiple data-placeholder="Chọn chi nhánh">
                                            @foreach ($chiNhanh as $cn)
                                                <option value="{{ $cn['id'] }}">{{ $cn['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-3 row">
                                    <label class="form-label col-5">Ngày áp dụng <span class="text-danger">*</span></label>
                                    <div class="mb-3 d-flex col-7">
                                        <input type="text" class="form-control me-2 vc-ngay" id="vc-tu" autocomplete="off" placeholder="Nhập ngày bắt đầu">
                                        <input type="text" class="form-control vc-ngay" id="vc-den" autocomplete="off" placeholder="Nhập ngày kết thúc">
                                    </div>
                                </div>
                                <div class="mb-3 row">
                                    <div class="col-5"></div>
                                    <div class="col-7"><input type="checkbox" id="vc-khong-han"> <label class="mx-2" for="vc-khong-han">Không giới hạn thời gian</label></div>
                                </div>
                                <div class="row mb-3">
                                    <label class="form-label col-md-5">Danh mục hàng hóa</label>
                                    <div class="col-md-7">
                                        <div class="row">
                                            <div class="col-md-6"><input type="radio" id="vc-dm-all" name="vc-dm" value="all" checked> <label for="vc-dm-all">Toàn hệ thống</label></div>
                                            <div class="col-md-6"><input type="radio" id="vc-dm-chon" name="vc-dm" value="specific"> <label for="vc-dm-chon">Theo danh mục</label></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row" id="vc-dong-dm" style="display: none;">
                                    <div class="col-5"></div>
                                    <div class="col-7">
                                        <select id="vc-danh-muc" class="form-select" multiple data-placeholder="Chọn danh mục">
                                            @foreach ($danhMuc as $dm)
                                                <option value="{{ $dm['id'] }}">{{ $dm['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mt-3 mt-md-0">
                            <div class="vc-khoi" style="height: auto;">
                                <h6 class="my-auto mb-3">2. Coupon/Voucher:</h6>
                                <div class="mb-3 d-flex mt-4 row">
                                    <div class="col-2"></div>
                                    <div class="col-md-5 d-flex"><input type="radio" id="vc-tu-sinh" name="vc-nguon" value="1" checked> <label for="vc-tu-sinh" class="mx-1">Hệ thống tự sinh</label></div>
                                    <div class="col-md-5 d-flex"><input type="radio" id="vc-tu-file" name="vc-nguon" value="2" disabled> <label for="vc-tu-file" class="mx-1">Nhập từ file</label></div>
                                </div>
                                <div class="mb-3 row justify-content-between">
                                    <label class="form-label col-4">Mã ưu đãi <span class="text-danger">*</span></label>
                                    <div class="col-8 d-flex">
                                        <input type="text" class="form-control" id="vc-tien-to" maxlength="20" autocomplete="off" placeholder="Tiền tố">
                                        <input type="text" class="form-control mx-2" value="-" readonly tabindex="-1">
                                        <input type="text" class="form-control" id="vc-hau-to" maxlength="20" autocomplete="off" placeholder="Hậu tố">
                                    </div>
                                </div>
                                <div class="mb-3 row justify-content-between">
                                    <label for="vc-so-luong" class="form-label col-4">Số lượng <span class="text-danger">*</span></label>
                                    <div class="col-8"><input type="number" min="1" max="200" class="form-control vc-cao" id="vc-so-luong" placeholder="Nhập số lượng"></div>
                                </div>
                                <div class="mb-3 row justify-content-between">
                                    <label for="vc-so-lan" class="form-label col-4">Số lần áp dụng <span class="text-danger">*</span></label>
                                    <div class="col-8"><input type="number" min="1" class="form-control" id="vc-so-lan" placeholder="Nhập số lần áp dụng"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="btn btn-success vc-luu" data-release="0">Lưu</button>
                    <button type="button" class="btn btn-primary vc-luu" data-release="1">Phát hành</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Các lượt đã dùng một mã — #voucherDetailModal của v2. --}}
    <div class="modal fade" id="vcLichSu" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Chi tiết sử dụng mã <b id="vc-ls-ma"></b></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="table table-bordered mb-0">
                        <thead><tr><th>STT</th><th>Nhân viên</th><th>Thời gian</th><th>Chi nhánh</th></tr></thead>
                        <tbody id="vc-ls"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="deleteItem" data-id="">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title">Xóa Chương trình khuyến mại ?</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body text-center"><label class="form-label" style="font-size: 17px; margin-bottom: 0;">Bạn có chắc muốn xóa?</label></div>
                <div class="modal-footer"><button type="button" class="btn btn-danger" id="xoa-xac-nhan" style="width: 70px">Xóa</button></div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_VC = @json(url('/admin/voucher-coupons'));
        const URL_VC_MA = @json(url('/admin/voucher-codes'));
        const URL_VC_TAO = @json(route('admin.voucher-coupon.store'));
        const CSRF_VC = '{{ csrf_token() }}';

        let VC = docDong();
        function docDong() {
            try { return JSON.parse(document.getElementById('v2-rows').textContent) || {}; } catch (e) { return {}; }
        }
        $(document).on('v2:da-nap', function () { VC = docDong(); ganLoc(); $('.voucher-detail').hide(); });

        const chuSo = (v) => String(v == null ? '' : v).replace(/[^0-9]/g, '');
        const soPhay = (v) => (chuSo(v) === '' ? '' : Number(chuSo(v)).toLocaleString('en-US'));
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const ngayVN = (s) => (s ? moment(s).format('DD-MM-YYYY') : '');

        // ================= Bộ lọc (như v2: đổi là tải lại) =================
        function locLai(them) {
            const $f = $('#search-form');
            const q = new URLSearchParams();
            const cn = $f.find('[name="shop_ids[]"]').val() || [];
            cn.length ? cn.forEach((v) => q.append('shop_ids[]', v)) : q.append('shop_ids[]', '');
            $('.vc-tt:checked').each(function () { if (this.value) q.append('statuses[]', this.value); });
            const cu = new URLSearchParams(location.search);
            if (cu.has('cols')) q.set('cols', cu.get('cols'));
            Object.entries(them || {}).forEach(([k, v]) => q.set(k, v));
            V2.napLai(location.pathname + '?' + q);
        }
        function ganLoc() {
            $('#search-form .vc-chon-nhieu').each(function () {
                if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ width: '100%', placeholder: $(this).data('placeholder') });
            });
        }
        ganLoc();
        $(document).on('change', '#search-form select', () => locLai());
        // "Tất cả" loại trừ hai ô kia; bỏ hết thì quay về "Tất cả" — như v2.
        $(document).on('change', '.vc-tt', function () {
            if (this.id === 'vc-tt-all') {
                if (this.checked) $('.vc-tt').not(this).prop('checked', false);
            } else {
                $('#vc-tt-all').prop('checked', false);
            }
            if (!$('.vc-tt:checked').length) $('#vc-tt-all').prop('checked', true);
            locLai();
        });

        // ================= Chọn cột =================
        function apDungCot() {
            const bat = $('.show_col:checked').map((i, el) => $(el).data('col')).get();
            const q = new URLSearchParams(location.search);
            q.set('cols', bat.join(','));
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () { $('.show_col').prop('checked', this.checked); apDungCot(); });

        function goiJson(url, method, fields) {
            const fd = new FormData();
            fd.append('_token', CSRF_VC);
            if (method !== 'POST') fd.append('_method', method);
            $.each(fields || {}, (k, v) => fd.append(k, v));
            return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => r.json().then((b) => ({ ok: r.ok, b })));
        }
        const docJson = (url) => fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json().then((b) => ({ ok: r.ok, b })));

        // ================= Danh sách mã chi tiết =================
        let maDangXem = 0, henTim = null;
        function giaTriMa(v) {
            return v.discount_type === 'percentage' ? (+v.discount_value) + '%' : Number(v.discount_value).toLocaleString('en-US') + ' đ';
        }
        function napMa(trang) {
            const q = new URLSearchParams({ page: trang || 1, keyword: $('#search-voucher-code').val().trim() });
            docJson(URL_VC + '/' + maDangXem + '/codes?' + q).then(function (r) {
                if (!r.ok) { toastr.error(r.b.message || 'Không tải được danh sách mã.'); return; }
                const meta = r.b.meta || { page: 1, page_size: 10, total_pages: 1 };
                const dau = (meta.page - 1) * meta.page_size;
                $('#vc-ma').html((r.b.data || []).map((v, i) => '<tr data-id="' + v.id + '" data-code="' + esc(v.code) + '">'
                    + '<td class="text-center"><button type="button" class="btn btn-primary btn-sm btn-voucher-detail">Chi tiết</button></td>'
                    + '<td class="text-center">' + (dau + i + 1) + '</td>'
                    + '<td class="text-left">' + esc(v.code) + '</td>'
                    + '<td class="text-right">' + (v.start_at ? ngayVN(v.start_at) : '∞') + '</td>'
                    + '<td class="text-right">' + (v.end_at ? ngayVN(v.end_at) : '∞') + '</td>'
                    + '<td class="text-right">' + (v.usage_limit ?? '∞') + '</td>'
                    + '<td class="text-right">' + v.used_count + '</td>'
                    + '<td class="text-right">' + giaTriMa(v) + '</td>'
                    + '<td class="text-center"><input type="checkbox" class="switch_customer vc-ma-bat"' + (v.is_active ? ' checked' : '') + '></td></tr>').join('')
                    || '<tr><td colspan="9" class="text-center py-3">Không có mã nào.</td></tr>');
                let trangHtml = '';
                if (meta.total_pages > 1) {
                    trangHtml = '<nav><ul class="pagination">';
                    for (let t = 1; t <= meta.total_pages; t++) {
                        trangHtml += '<li class="page-item' + (t === meta.page ? ' active' : '') + '"><a class="page-link vc-trang" href="#" data-trang="' + t + '">' + t + '</a></li>';
                    }
                    trangHtml += '</ul></nav>';
                }
                $('#vc-ma-trang').html(trangHtml);
            });
        }
        $(document).on('click', '.campaign-detail', function (e) {
            e.preventDefault();
            const $tr = $(this).closest('tr');
            maDangXem = $tr.data('id');
            $('.table-list-program tr').removeClass('dang-xem');
            $tr.addClass('dang-xem');
            $('#search-voucher-code').val('');
            $('.voucher-detail').show();
            napMa(1);
        });
        $(document).on('click', '.vc-trang', function (e) { e.preventDefault(); napMa($(this).data('trang')); });
        $('#search-voucher-code').on('input', function () { clearTimeout(henTim); henTim = setTimeout(() => napMa(1), 300); });
        $(document).on('change', '.vc-ma-bat', function () {
            const bat = this.checked, $o = $(this);
            goiJson(URL_VC_MA + '/' + $(this).closest('tr').data('id') + '/status', 'PUT', { is_active: bat ? 1 : 0 }).then(function (r) {
                if (!r.ok) { $o.prop('checked', !bat); toastr.error(r.b.message || 'Không đổi được trạng thái.'); return; }
                toastr.success(r.b.message);
            });
        });
        $(document).on('click', '.btn-voucher-detail', function () {
            const $tr = $(this).closest('tr');
            $('#vc-ls-ma').text($tr.data('code'));
            docJson(URL_VC_MA + '/' + $tr.data('id') + '/history').then(function (r) {
                if (!r.ok) { toastr.error(r.b.message || 'Không tải được lịch sử.'); return; }
                $('#vc-ls').html((r.b.data || []).map((u, i) => '<tr><td>' + (i + 1) + '</td><td>' + esc(u.staff) + '</td><td>'
                    + moment(u.used_at).format('DD-MM-YYYY HH:mm:ss') + '</td><td>' + esc(u.shop) + '</td></tr>').join('')
                    || '<tr><td colspan="4" class="text-center">Mã chưa được sử dụng.</td></tr>');
                $('#vcLichSu').modal('show');
            });
        });

        // ================= Hộp Thêm / Sửa / Xem =================
        const $hop = $('#vcHop');
        const tip = new bootstrap.Tooltip(document.getElementById('vc-nhom-gia-tri'));
        $('#vc-chi-nhanh').select2({ width: '100%', dropdownParent: $hop, placeholder: 'Chọn chi nhánh' });
        $('#vc-danh-muc').select2({ width: '100%', dropdownParent: $hop, placeholder: 'Chọn danh mục' });
        $('.vc-ngay').each(function () {
            $(this).daterangepicker({ singleDatePicker: true, autoUpdateInput: false, autoApply: true, showDropdowns: true, minDate: moment().startOf('day'),
                locale: Object.assign(V2.lichVN(), { format: 'DD-MM-YYYY' }) }, function (d) {
                $(this.element).val(d.format('DD-MM-YYYY'));
                $('#vc-khong-han').prop('checked', false);
                const tu = moment($('#vc-tu').val(), 'DD-MM-YYYY'), den = moment($('#vc-den').val(), 'DD-MM-YYYY');
                if (tu.isValid() && den.isValid() && den.isBefore(tu)) $('#vc-den').val($('#vc-tu').val());
            });
        });
        $('input[name="vc-cn"]').on('change', () => $('#vc-dong-cn').toggle($('#vc-cn-chon').is(':checked')));
        $('input[name="vc-dm"]').on('change', () => $('#vc-dong-dm').toggle($('#vc-dm-chon').is(':checked')));
        $('#vc-khong-han').on('change', function () {
            if (this.checked) $('.vc-ngay').val('');
            $('.vc-ngay').prop('disabled', this.checked);
        });
        // % thì tối đa 100 và có "Số tiền tối đa"; VNĐ thì bỏ ô tối đa — như v2.
        function doiKieu(baoTip) {
            const phanTram = $('#vc-kieu').val() === 'percentage';
            $('#vc-dong-toi-da').toggle(phanTram);
            const chu = phanTram ? 'Bạn đang chọn hình thức phát thẻ coupon (%)' : 'Bạn đang chọn hình thức phát thẻ Voucher (VN)';
            tip.setContent({ '.tooltip-inner': chu });
            if (baoTip) tip.show();
        }
        $('#vc-kieu').on('change', function () { $('#vc-gia-tri').val(''); doiKieu(true); });
        $('#vc-nhom-gia-tri').on('click', () => tip.hide());
        $('#vc-gia-tri').on('input', function () {
            let v = chuSo(this.value).slice(0, 12);
            if ($('#vc-kieu').val() === 'percentage' && Number(v) > 100) { v = '100'; toastr.error('Giá trị không được vượt quá 100%'); }
            this.value = soPhay(v);
        });
        $hop.on('input', '.vc-tien', function () { this.value = soPhay(chuSo(this.value).slice(0, 12)); });
        $('#vc-tien-to, #vc-hau-to').on('input', function () { this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, ''); });
        $('#vc-so-luong').on('input', function () {
            if (Number(this.value) > 200) { alert('Tối đa 200'); this.value = 200; }
        });
        $hop.on('hidden.bs.modal', () => tip.hide());

        const ngayHop = (s) => (s ? moment(s).format('DD-MM-YYYY') : '');

        function moHop(che, p) {
            const xem = che === 'view', sua = che === 'edit';
            $hop.attr('data-id', sua ? p.id : '').toggleClass('xem', xem);
            $('#vc-tieu-de').text(xem ? 'Thông tin chi tiết' : (sua ? 'Chỉnh sửa Voucher/Coupon' : 'Thêm mới Voucher/Coupon'));
            $('#vc-ma-ct').val(p ? p.code : '');
            $('#vc-ten').val(p ? p.name : '');
            $('#vc-mo-ta').val(p ? p.description : '');
            $('#vc-kieu').val(p ? p.discount_type : 'percentage');
            doiKieu(false);
            $('#vc-gia-tri').val(p ? soPhay(Math.round(p.discount_value)) : '');
            $('#vc-toi-thieu').val(p ? soPhay(Math.round(p.min_order_amount)) : '');
            $('#vc-toi-da').val(p && p.max_discount_amount ? soPhay(Math.round(p.max_discount_amount)) : (p ? '0' : ''));
            $(p && !p.all_shops ? '#vc-cn-chon' : '#vc-cn-all').prop('checked', true).trigger('change');
            $('#vc-chi-nhanh').val(p && !p.all_shops ? p.shop_ids.map(String) : []).trigger('change.select2');
            $(p && !p.all_categories ? '#vc-dm-chon' : '#vc-dm-all').prop('checked', true).trigger('change');
            $('#vc-danh-muc').val(p && !p.all_categories ? p.category_ids.map(String) : []).trigger('change.select2');
            // Tạo mới: từ hôm nay tới cuối tháng, như v2.
            const khongHan = p ? !!p.no_time_limit : false;
            $('#vc-khong-han').prop('checked', khongHan);
            $('#vc-tu').val(p ? ngayHop(p.start_date) : moment().format('DD-MM-YYYY'));
            $('#vc-den').val(p ? ngayHop(p.end_date) : moment().endOf('month').format('DD-MM-YYYY'));
            $('.vc-ngay').prop('disabled', khongHan || xem);
            $('#vc-tien-to').val(p ? p.prefix : '');
            $('#vc-hau-to').val(p ? p.suffix : '');
            $('#vc-so-luong').val(p ? p.quantity : '');
            $('#vc-so-lan').val(p ? p.usage_limit : '');

            $hop.find('input:not(#vc-ma-ct):not(#vc-tu-file):not(.vc-ngay), select').prop('disabled', xem);
            $('#vc-chi-nhanh, #vc-danh-muc').prop('disabled', xem);
            $hop.find('.vc-luu').toggle(!xem);
            $hop.modal('show');
        }

        $(document).on('click', '.add-voucher', () => moHop('add', null));
        $(document).on('click', '.view-item', function () { moHop('view', VC[$(this).closest('.item').data('id')]); });
        $(document).on('click', '.edit-item', function () { moHop('edit', VC[$(this).closest('.item').data('id')]); });

        $('.vc-luu').on('click', function () {
            const id = $hop.attr('data-id');
            const khongHan = $('#vc-khong-han').is(':checked');
            const theoCN = $('#vc-cn-chon').is(':checked'), theoDM = $('#vc-dm-chon').is(':checked');
            V2.luuHop($hop, id ? URL_VC + '/' + id : URL_VC_TAO, id ? 'PUT' : 'POST', {
                name: $('#vc-ten').val().trim(),
                description: $('#vc-mo-ta').val().trim(),
                discount_type: $('#vc-kieu').val(),
                discount_value: chuSo($('#vc-gia-tri').val()),
                min_order_amount: chuSo($('#vc-toi-thieu').val()),
                max_discount_amount: $('#vc-kieu').val() === 'percentage' ? chuSo($('#vc-toi-da').val()) : '',
                all_shops: theoCN ? 0 : 1,
                'shop_ids[]': theoCN ? ($('#vc-chi-nhanh').val() || []) : [],
                all_categories: theoDM ? 0 : 1,
                'category_ids[]': theoDM ? ($('#vc-danh-muc').val() || []) : [],
                no_time_limit: khongHan ? 1 : 0,
                start_date: khongHan ? '' : $('#vc-tu').val(),
                end_date: khongHan ? '' : $('#vc-den').val(),
                prefix: $('#vc-tien-to').val(),
                suffix: $('#vc-hau-to').val(),
                quantity: $('#vc-so-luong').val(),
                usage_limit: $('#vc-so-lan').val(),
                release: $(this).data('release'),
            }, $(this));
        });

        // ================= Xoá =================
        $(document).on('click', '.delete-item', function () {
            $('#deleteItem').attr('data-id', $(this).closest('.item').data('id')).modal('show');
        });
        $('#xoa-xac-nhan').on('click', function () {
            V2.luuHop('#deleteItem', URL_VC + '/' + $('#deleteItem').attr('data-id'), 'DELETE', {}, $(this));
        });
    </script>
@endpush
