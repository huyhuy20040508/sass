{{-- CRM → Chương trình khuyến mãi → tab "Khuyến mại đồng giá" — dựng theo bản v2
     đang chạy (crm/fixed-price: index + list + modal-create + edit-by-id + show-by-id).

     "Giỏ có từ Q cái của nhóm hàng / hàng hoá X thì MỌI cái của X bán đúng giá P."
     Thu ngân chọn chương trình ở quầy (nút "Đồng giá").

     Như v2: hộp Thêm/Sửa chỉ có bảng dòng đồng giá — bảng hàng tặng bị giấu
     (list-gift d-none), còn hộp Xem ("Thông tin chi tiết") thì bày hàng tặng của
     dòng đang chọn. Dòng là hàng ô nhập: 💾 lưu thì khoá ô, ✎ mở lại, ✕ bỏ dòng;
     "+" chèn dòng mới lên ĐẦU bảng.

     Khác v2: mã do hệ thống tự sinh; "Nhóm menu / Danh sách menu" thành
     "Nhóm hàng / Danh sách hàng". Sửa chương trình cũ vẫn giữ nguyên hàng tặng
     đã có (hộp Sửa không bày nhưng không xoá).

     Bảng KHÔNG xuống dòng (luật đã chốt cho mọi bảng v2). --}}
@extends('v2::layouts.master')

@section('title', 'Khuyến mại đồng giá')

@php
    $C = \App\Http\Controllers\FixedPriceController::class;
    $THU = \App\Http\Controllers\PromotionProgramController::THU;
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $ngay = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '-';
    $maChiNhanh = collect($chiNhanh)->mapWithKeys(fn ($c) => [$c['id'] => ($c['code'] ?? '') ?: ($c['name'] ?? '')])->all();
    $coLoc = $filters['from_date'] !== '' || $filters['to_date'] !== '' || $filters['shop_ids'] !== [] || $filters['codes'] !== [];
    $bienThe = collect($sanPham)->flatMap(fn ($p) => $p['variants'])->values();
@endphp

@push('styles')
    <style>
        table.table-fixed-price.none_mobile { width: 100%; table-layout: auto; }
        table.table-fixed-price.none_mobile th,
        table.table-fixed-price.none_mobile td { white-space: nowrap; vertical-align: middle; padding: 8px; font-size: 14px; }
        table.table-fixed-price.none_mobile th { height: 55px; font-weight: 700; }
        table.table-fixed-price tr.item { height: 55px; }
        table.table-fixed-price .ma-ct { color: #0066FF; }
        table.table-fixed-price td.action i { cursor: pointer; font-size: 13px; }
        @media (max-width: 1439.98px) {
            table.table-fixed-price.none_mobile th,
            table.table-fixed-price.none_mobile td { font-size: 12.5px; padding: 6px 5px; }
        }

        /* ---------- Hộp Thêm / Sửa / Xem — đo trên bản v2 ---------- */
        .dg-hop .modal-dialog { min-width: 60%; }
        .dg-hop .modal-header { padding: 7px 16px; }
        .dg-hop .modal-title { font-size: 16px; font-weight: 500; color: #1a2b58; }
        .dg-hop label.nhan { font-weight: bold; color: #1a2b58; }
        .dg-hop .form-control { font-size: 13px; border-radius: 6px; }
        .dg-hop .btn_header { padding: 5px 10px; }
        .dg-hop .btn_header.btn-success { min-width: 106px; }
        /* Ô thứ của đồng giá: vuông bo 6px (khác viên tròn của chương trình khuyến mại). */
        .dg-hop .content-day {
            width: 40px; min-height: 38px; border-radius: 6px; border: 1px solid #c2c2c2; background: #a8a8a8; color: #000;
            display: flex; align-items: center; justify-content: center; text-align: center; line-height: 1.1;
            cursor: pointer; user-select: none; font-size: 14px; flex-shrink: 0;
        }
        .dg-hop .content-day.active { background: #4B9700; border-color: #458a01; color: #fff; }
        .dg-hop .dg-thu-khoa .content-day { cursor: default; }
        .dg-hop .content-detail { border-top: 1px solid #d8d8d8; padding-top: 15px; margin-top: 4px; }
        .dg-hop .title-header-content {
            display: inline-block; background: #D5E1F3; border: 1px solid #1a2a5859; border-bottom: 0;
            border-radius: 5px 5px 0 0; padding: 7px 10px;
        }
        .dg-hop .title-type { font-size: 15px; font-weight: 700; color: #1A2B58; }
        .dg-hop .dg-bang th, .dg-hop .dg-bang td { height: 55px; padding: 8px; vertical-align: middle; font-size: 14px; }
        .dg-hop .dg-bang th { font-weight: 700; text-align: center; vertical-align: bottom; background: #fff; }
        .dg-hop .dg-bang td { text-align: center; }
        .dg-hop .dg-bang .form-control { height: 30px; font-size: 13px; border-radius: 4px; padding: 4px 8px; }
        .dg-hop .dg-bang tr.active td { background: #D5E1F3; }
        .dg-hop .dg-bang tr.row-item, .dg-hop .table-show-detail tbody tr { cursor: pointer; }
        .dg-hop .dg-bang .fa { font-size: 14px; cursor: pointer; color: #4a6fa5; }
        /* Theme đè .form-check thành khối, không lề trái — trả về hai nút radio cùng hàng như v2. */
        .dg-hop .form-check-inline { display: inline-block !important; padding-left: 1.5em; margin-right: 1rem; }
        .dg-hop .form-check-inline .form-check-input { float: left; margin-left: -1.5em; }
        .dg-hop .select2-container { width: 100% !important; }
        .dg-hop .select2-container--open, .dg-hop .select2-dropdown { z-index: 1065 !important; }
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

    <div class="row index-fixed-price-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <form action="{{ route('admin.crm.promotions.dongGia') }}" method="GET" id="search-form">
                            <div id="filterSearch">
                                <div class="inner-modal-in-mobile">
                                    <div class="mb-3">
                                        <span class="title_search d-none d-lg-block">{{ __('message.from_date') }}</span>
                                        <input type="text" name="from_date" class="form-control dg-ngay-loc" autocomplete="off"
                                            value="{{ $filters['from_date'] ? date('d-m-Y', strtotime($filters['from_date'])) : '' }}"
                                            placeholder="{{ __('message.from_date') }}">
                                    </div>
                                    <div class="mb-3">
                                        <span class="title_search d-none d-lg-block">{{ __('message.to_date') }}</span>
                                        <input type="text" name="to_date" class="form-control dg-ngay-loc" autocomplete="off"
                                            value="{{ $filters['to_date'] ? date('d-m-Y', strtotime($filters['to_date'])) : '' }}"
                                            placeholder="{{ __('message.to_date') }}">
                                    </div>
                                    <div class="mb-3">
                                        <span class="title_search d-none d-lg-block">{{ __('message.branch') }}</span>
                                        <select name="shop_ids[]" class="form-control dg-chon-nhieu" multiple data-placeholder="{{ __('message.all') }}">
                                            @foreach ($chiNhanh as $cn)
                                                <option value="{{ $cn['id'] }}" {{ in_array((int) $cn['id'], $filters['shop_ids'], true) ? 'selected' : '' }}>{{ $cn['name'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <span class="title_search d-none d-lg-block">Mã khuyến mãi</span>
                                        <select name="codes[]" class="form-control dg-chon-nhieu" multiple data-placeholder="{{ __('message.all') }}">
                                            @foreach ($maCT as $ma)
                                                <option value="{{ $ma }}" {{ $filters['codes_all'] || in_array($ma, $filters['codes'], true) ? 'selected' : '' }}>{{ $ma }}</option>
                                            @endforeach
                                        </select>
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
                    <h1 class="tieu-de-trang">Danh sách các chương trình khuyến mại đồng giá</h1>
                    <div class="btn_top_content">
                        <a type="button" class="bt btn_green add-item">Tạo mới</a>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-striped table-fixed-price none_mobile">
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center">{{ __('message.stt') }}</th>
                                    <th class="text-left">Mã chương trình</th>
                                    <th class="text-left">Tên chương trình</th>
                                    <th class="text-center">{{ __('message.status') }}</th>
                                    <th class="text-left">Duyệt</th>
                                    <th class="text-center">{{ __('message.status') }}</th>
                                    <th class="text-right">{{ __('message.from_date') }}</th>
                                    <th class="text-right">{{ __('message.to_date') }}</th>
                                    <th class="text-left">{{ __('message.branch') }}</th>
                                    <th class="text-center">{{ __('message.action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($list as $i => $f)
                                    <tr class="item" data-id="{{ (int) $f['id'] }}">
                                        <td class="text-center">{{ $stt + $i + 1 }}</td>
                                        <td class="text-left ma-ct">{{ $f['code'] }}</td>
                                        <td class="text-left">{{ $f['name'] }}</td>
                                        <td class="text-left">
                                            {!! ! empty($f['status']) ? '<b class="text-success">'.__('message.active').'</b>' : '<b class="text-danger">'.__('message.inactive').'</b>' !!}
                                        </td>
                                        <td class="text-left">
                                            @if (! empty($f['approved']))
                                                <b class="text-success">Đã được duyệt</b>
                                            @else
                                                <b class="text-secondary">Lưu tạm</b>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <input type="checkbox" class="switch_customer item-status" {{ ! empty($f['status']) ? 'checked' : '' }}>
                                        </td>
                                        <td class="text-right">{{ ! empty($f['no_time_limit']) ? '-' : $ngay($f['start_date']) }}</td>
                                        <td class="text-right">{{ ! empty($f['no_time_limit']) ? '-' : $ngay($f['end_date']) }}</td>
                                        <td class="text-left">
                                            {{ ! empty($f['all_shops']) ? collect($maChiNhanh)->implode(', ') : collect($f['shop_ids'])->map(fn ($id) => $maChiNhanh[$id] ?? '#'.$id)->implode(', ') }}
                                        </td>
                                        <td class="action">
                                            <div class="d-flex">
                                                <i class="fa fa-eye view-item mx-1" title="Xem"></i>
                                                <i class="fa fa-edit edit-item mx-1" title="{{ __('message.edit') }}"></i>
                                                <i class="fa fa-trash delete-item mx-1" title="{{ __('message.delete') }}"></i>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center py-4">
                                            {{ $coLoc ? 'Không có chương trình nào khớp bộ lọc đang bật.' : 'Chưa có chương trình đồng giá nào. Bấm "Tạo mới" để lập chương trình đầu tiên.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        <div class="table-fixed-price none_desktop">
                            @foreach ($list as $f)
                                <div class="item p-2 border-bottom" data-id="{{ (int) $f['id'] }}">
                                    <div class="d-flex justify-content-between"><b class="ma-ct">{{ $f['code'] }}</b>
                                        <span>{!! ! empty($f['approved']) ? '<b class="text-success">Đã được duyệt</b>' : '<b class="text-secondary">Lưu tạm</b>' !!}</span></div>
                                    <div>{{ $f['name'] }}</div>
                                    <div class="text-end action"><i class="fa fa-eye view-item px-1"></i><i class="fa fa-edit edit-item px-1"></i></div>
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

    {{-- ===================== Hộp Thêm / Sửa — #modalCreateFixedPricePromotion của v2 ===================== --}}
    <div class="modal dg-hop" id="modalDongGia" data-id="">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title" id="dg-tieu-de">Thêm chương trình khuyến mại đồng giá</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-end">
                        <button type="button" class="btn btn-sm btn-danger btn_header mx-1" data-bs-dismiss="modal">Đóng</button>
                        <button type="button" class="btn btn-sm btn-primary btn_header mx-1 dg-luu" data-duyet="0">Lưu tạm</button>
                        <button type="button" class="btn btn-sm btn-success btn_header mx-1 dg-luu" data-duyet="1">Duyệt</button>
                    </div>
                    <div class="row">
                        <div class="col-12 col-lg-6">
                            <div class="mb-2">
                                <label class="mb-1 nhan">Mã chương trình</label>
                                <input type="text" class="form-control" id="dg-ma" disabled placeholder="Hệ thống tự sinh">
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Tên chương trình <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="dg-ten" maxlength="255" autocomplete="off" placeholder="Tên chương trình">
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Điều kiện áp dụng <span class="text-danger">*</span></label>
                                <div class="d-flex flex-column">
                                    <label class="mb-1">Ngày áp dụng <span class="text-danger">*</span></label>
                                    <div class="d-flex gap-2 mx-0">
                                        <input type="text" class="form-control dg-ngay" id="dg-tu" placeholder="Từ ngày" autocomplete="off">
                                        <input type="text" class="form-control dg-ngay" id="dg-den" placeholder="Đến ngày" autocomplete="off">
                                    </div>
                                    <div class="my-2">
                                        <input type="checkbox" class="form-check-input mx-1" id="dg-khong-han">
                                        <label for="dg-khong-han">Không có giới hạn thời gian</label>
                                    </div>
                                    <div class="list-day-of-week overflow-auto" id="dg-thu">
                                        <div class="d-flex justify-content-lg-around mb-1">
                                            <div class="content-day mx-1" data-thu="all"><span>Tất cả</span></div>
                                            @foreach ($THU as $so => $ten)
                                                <div class="content-day mx-1" data-thu="{{ $so }}"><span class="p-2">{{ $ten }}</span></div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="mb-2">
                                <label class="mb-1 nhan">Chi nhánh áp dụng <span class="text-danger">*</span></label>
                                <div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="dg-kieu-cn" id="dg-cn-all" value="all" checked>
                                        <label class="form-check-label" for="dg-cn-all">Toàn hệ thống</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="dg-kieu-cn" id="dg-cn-chon" value="select">
                                        <label class="form-check-label" for="dg-cn-chon">Theo chi nhánh</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <select class="form-control" id="dg-chi-nhanh" multiple>
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}">{{ $cn['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Loại khuyến mại</label>
                                <select class="form-control" id="dg-loai">
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
                                    <input type="checkbox" class="switch_customer ms-1" id="dg-bat" checked>
                                </div>
                                <div class="bg-white border rounded p-2 d-flex gap-2 align-items-start mt-2">
                                    <i class="fa fa-info-circle" style="margin-top: 2px; font-size: 14px;"></i>
                                    <div class="small text-wrap">Hệ thống hiện tại đang cho phép gộp nhiều loại khuyến mại khi thanh toán cùng một hóa đơn.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="content-detail">
                        <div class="title-header-content"><span class="title-type">Phiếu bán hàng</span></div>
                        <div class="row w-100 mx-0">
                            <div class="col-12 col-lg-7 px-0">
                                <table class="table table-bordered dg-bang mb-0" id="dg-dong">
                                    <thead>
                                        <tr>
                                            <th class="w-50">Ngành hàng</th>
                                            <th class="w-25">Số lượng</th>
                                            <th class="w-25">Giá trị đồng giá</th>
                                            <th style="width: 2rem;"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr class="tr-add">
                                            <td colspan="4"><button type="button" class="btn btn-outline-secondary add-row px-4">+</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Xem — #modalShowFixedPricePromotion (show-by-id) của v2 ===================== --}}
    <div class="modal dg-hop" id="modalXemDongGia">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Thông tin chi tiết</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12 col-lg-6">
                            <div class="mb-2">
                                <label class="mb-1 nhan">Mã chương trình <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="xem-ma" disabled>
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Tên chương trình <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="xem-ten" disabled>
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Mô tả chương trình</label>
                                <textarea class="form-control p-2" id="xem-mo-ta" rows="2" disabled style="height: 65px;"></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Điều kiện áp dụng <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2 mb-1">
                                    <input type="text" class="form-control" id="xem-tu" disabled>
                                    <input type="text" class="form-control" id="xem-den" disabled>
                                </div>
                                <div class="overflow-x-auto mt-2 dg-thu-khoa" id="xem-thu">
                                    <div class="d-flex justify-content-lg-around mb-1">
                                        <div class="content-day mx-1" data-thu="all"><span>Tất cả</span></div>
                                        @foreach ($THU as $so => $ten)
                                            <div class="content-day mx-1" data-thu="{{ $so }}"><span class="p-2">{{ $ten }}</span></div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="mb-2">
                                <label class="mb-1 nhan">Chi nhánh áp dụng <span class="text-danger">*</span></label>
                                <select class="form-control" id="xem-chi-nhanh" multiple disabled>
                                    @foreach ($chiNhanh as $cn)
                                        <option value="{{ $cn['id'] }}">{{ $cn['name'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Loại khuyến mại</label>
                                <input type="text" class="form-control" id="xem-loai" disabled>
                            </div>
                            <div class="mb-2">
                                <label class="mb-1 nhan">Trạng thái</label>
                                <input type="checkbox" class="switch_customer" id="xem-bat" disabled>
                            </div>
                        </div>
                    </div>
                    <div class="content-detail">
                        <div class="title-header-content"><span class="title-type">Phiếu bán hàng</span></div>
                        <div class="row">
                            <div class="col-12 col-lg-8 overflow-x-auto mb-3 mb-lg-0">
                                <table class="table table-bordered dg-bang table-show-detail mb-0">
                                    <thead>
                                        <tr><th class="w-50">Ngành hàng</th><th class="w-25">Số lượng</th><th class="w-25">Giá trị đồng giá</th></tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <div class="col-12 col-lg-4 overflow-x-auto">
                                <table class="table table-bordered dg-bang table-show-gifts mb-0">
                                    <thead>
                                        <tr><th class="w-25">Tên hàng hóa</th><th class="w-25">Số lượng</th><th class="w-25">ĐVT</th></tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Đã duyệt thì phải huỷ duyệt trước khi sửa — #modalFixedPriceWarning của v2. --}}
    <div class="modal dg-hop" id="modalHuyDuyet" data-id="">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Chỉnh sửa chương trình khuyến mại</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-center" style="font-size: 16px;">
                        <b id="hd-ten"></b> đang được <b>Duyệt</b>. Bạn phải <b>Hủy duyệt</b> trước khi sửa.
                    </p>
                    <div class="d-flex justify-content-center">
                        <button type="button" class="btn btn-sm btn-danger btn_header mx-1" data-bs-dismiss="modal">Đóng</button>
                        <button type="button" class="btn btn-sm btn-success btn_header mx-1" id="hd-xac-nhan">Hủy duyệt</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="deleteItem" data-id="">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 30vw">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title">Xóa</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body text-center"><b class="h6">Bạn có chắc muốn xóa?</b></div>
                <div class="modal-footer">
                    <button type="button" class="bt btn_red" id="xoa-xac-nhan">Xác nhận</button>
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Hủy</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_DG = @json(url('/admin/fixed-prices'));
        const URL_DG_TAO = @json(route('admin.dong-gia.store'));
        const CSRF_DG = '{{ csrf_token() }}';
        const DG_LOAI = @json($C::LOAI);
        const DG_NHOM = @json(collect($danhMuc)->map(fn ($c) => ['id' => $c['id'], 'text' => $c['name']])->values());
        const DG_SP = @json(collect($sanPham)->map(fn ($p) => ['id' => $p['id'], 'text' => $p['name']])->values());
        const DG_BT = @json($bienThe->map(fn ($v) => ['id' => $v['id'], 'text' => $v['name'], 'unit' => $v['unit'] ?? ''])->values());
        const TB = {
            thieu: @json(__('message.please_fill_all_required')),
            soSai: @json(__('message.quantity_price_must_be_valid_number')),
            trung: @json(__('message.item_or_category_duplicated')),
            chuaLuu: @json(__('message.unsaved_promotion_details_please_check')),
        };

        let DG = docDong();
        function docDong() {
            try { return JSON.parse(document.getElementById('v2-rows').textContent) || {}; } catch (e) { return {}; }
        }
        $(document).on('v2:da-nap', function () { DG = docDong(); ganLoc(); });

        const chuSo = (v) => String(v == null ? '' : v).replace(/[^0-9]/g, '');
        const soPhay = (v) => (chuSo(v) === '' ? '' : Number(chuSo(v)).toLocaleString('en-US')); // v2 in 99,000
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        // ================= Bộ lọc — đổi ô nào là tải lại trang 1 (như v2) =================
        function locLai() {
            const $f = $('#search-form');
            const q = new URLSearchParams();
            ['from_date', 'to_date'].forEach((ten) => q.set(ten, String($f.find('[name="' + ten + '"]').val() || '').trim()));
            const cn = $f.find('[name="shop_ids[]"]').val() || [];
            cn.length ? cn.forEach((v) => q.append('shop_ids[]', v)) : q.append('shop_ids[]', '');
            // Chọn đủ mọi mã = không lọc mã: bỏ hẳn tham số để lần sau vẫn chọn sẵn mã mới.
            const $ma = $f.find('[name="codes[]"]');
            const ma = $ma.val() || [];
            if (ma.length && ma.length < $ma.find('option').length) ma.forEach((v) => q.append('codes[]', v));
            const cu = new URLSearchParams(location.search);
            if (cu.get('page_size')) q.set('page_size', cu.get('page_size'));
            V2.napLai(location.pathname + '?' + q);
        }
        function ganLoc() {
            $('#search-form .dg-chon-nhieu').each(function () {
                if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ width: '100%', placeholder: $(this).data('placeholder') });
            });
            $('#search-form .dg-ngay-loc').each(function () {
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

        // ================= Gọi JSON (công tắc, huỷ duyệt) =================
        function goiJson(url, method, fields) {
            const fd = new FormData();
            fd.append('_token', CSRF_DG);
            if (method !== 'POST') fd.append('_method', method);
            $.each(fields || {}, (k, v) => fd.append(k, v));
            return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => r.json().then((b) => ({ ok: r.ok, b })));
        }

        $(document).on('change', '.list .item-status', function () {
            const id = $(this).closest('.item').data('id');
            goiJson(URL_DG + '/' + id + '/status', 'PUT', { status: this.checked ? 1 : 0 }).then(function (r) {
                r.ok ? toastr.success(r.b.message) : toastr.error(r.b.message || 'Không đổi được trạng thái.');
                V2.napLai(location.href, false);
            });
        });

        // ================= Viên thứ: "Tất cả" bật/tắt cả bảy, tự sáng khi đủ bảy =================
        function dongBoTatCa($khung) {
            const du = $khung.find('.content-day[data-thu!="all"].active').length === 7;
            $khung.find('.content-day[data-thu="all"]').toggleClass('active', du);
        }
        function datThu($khung, thu) {
            $khung.find('.content-day[data-thu!="all"]').each(function () { $(this).toggleClass('active', thu.includes(Number($(this).data('thu')))); });
            dongBoTatCa($khung);
        }
        $('#dg-thu').on('click', '.content-day', function () {
            if ($(this).data('thu') === 'all') {
                const bat = !$(this).hasClass('active');
                $('#dg-thu .content-day').toggleClass('active', bat);
            } else {
                $(this).toggleClass('active');
                dongBoTatCa($('#dg-thu'));
            }
        });

        // ================= Hộp Thêm / Sửa =================
        const $hop = $('#modalDongGia');
        let moTaCu = ''; // hộp Sửa không bày Mô tả (v2 để d-none) nhưng không được làm mất nó

        $('#dg-chi-nhanh').select2({ width: '100%', dropdownParent: $hop });
        $('.dg-ngay').each(function () {
            $(this).daterangepicker({ singleDatePicker: true, autoUpdateInput: false, showDropdowns: true, minDate: moment().startOf('day'),
                locale: Object.assign(V2.lichVN(), { format: 'DD-MM-YYYY' }) }, function (d) {
                $(this.element).val(d.format('DD-MM-YYYY'));
                const tu = moment($('#dg-tu').val(), 'DD-MM-YYYY'), den = moment($('#dg-den').val(), 'DD-MM-YYYY');
                if (tu.isValid() && den.isValid() && den.isBefore(tu)) $('#dg-den').val($('#dg-tu').val());
            });
        });
        function doiKieuChiNhanh() {
            const theoCN = $('#dg-cn-chon').is(':checked');
            $('#dg-chi-nhanh').prop('disabled', !theoCN);
            if (!theoCN) $('#dg-chi-nhanh').val([]).trigger('change.select2');
        }
        $('input[name="dg-kieu-cn"]').on('change', doiKieuChiNhanh);
        // v2 chỉ khoá hai ô ngày, không xoá ngày đang có.
        $('#dg-khong-han').on('change', function () { $('.dg-ngay').prop('disabled', this.checked); });

        const loai = () => $('#dg-loai').val();
        const nguon = () => (loai() === '1' ? DG_NHOM : DG_SP);

        // Một dòng: ô chọn + số lượng + giá; sua = đang mở (💾), không thì ô khoá (✎).
        function dongMoi(d, sua) {
            const $tr = $('<tr class="row-item">'
                + '<td><select class="form-control form-control-sm select-item"><option value="">' + (loai() === '1' ? 'Chọn nhóm hàng' : 'Chọn hàng hóa') + '</option>'
                + nguon().map((o) => '<option value="' + o.id + '"' + (Number(d.object_id) === Number(o.id) ? ' selected' : '') + '>' + esc(o.text) + '</option>').join('')
                + '</select></td>'
                + '<td><input type="text" class="form-control form-control-sm text-center quantity" value="' + (d.quantity ?? 0) + '"></td>'
                + '<td><input type="text" class="form-control form-control-sm text-center price" value="' + (soPhay(Math.round(Number(d.price) || 0)) || 0) + '"></td>'
                + '<td class="text-center align-middle text-nowrap">'
                + '<i class="fa fa-save mx-1 p-1 handle-save' + (sua ? '' : ' d-none') + '"></i>'
                + '<i class="fa fa-edit mx-1 p-1 handle-edit' + (sua ? ' d-none' : '') + '"></i>'
                + '<i class="fa fa-close mx-1 p-1 handle-delete"></i></td></tr>');
            $tr.data('gifts', d.gifts || []);
            $tr.find('input, select').prop('disabled', !sua);
            return $tr;
        }
        const themDongTrong = () => $('#dg-dong tbody').prepend(dongMoi({ object_id: '', quantity: 0, price: 0 }, true));

        // Đổi loại thì xoá hết dòng và mở sẵn một dòng trống — như v2.
        $('#dg-loai').on('change', function () {
            $('#dg-dong tbody tr.row-item').remove();
            themDongTrong();
        });
        $hop.on('click', '.add-row', themDongTrong);
        $hop.on('input', '.quantity', function () { this.value = chuSo(this.value).slice(0, 6); });
        $hop.on('input', '.price', function () { this.value = soPhay(chuSo(this.value).slice(0, 12)); });
        $hop.on('click', '.handle-delete', function (e) {
            e.stopPropagation();
            $(this).closest('tr').remove();
        });
        $hop.on('click', '.handle-edit', function (e) {
            e.stopPropagation();
            const $tr = $(this).closest('tr');
            $tr.find('input, select').prop('disabled', false);
            $tr.find('.handle-save').removeClass('d-none');
            $tr.find('.handle-edit').addClass('d-none');
        });
        $hop.on('click', '.handle-save', function (e) {
            e.stopPropagation();
            const $tr = $(this).closest('tr');
            const ma = $tr.find('.select-item').val();
            const sl = $tr.find('.quantity').val(), gia = chuSo($tr.find('.price').val());
            if (!ma || sl === '' || gia === '') { toastr.error(TB.thieu); return; }
            if (Number(sl) <= 0) { toastr.error(TB.soSai); return; }
            const trung = $('#dg-dong tbody tr.row-item').not($tr).filter((i, el) => $(el).find('.select-item').val() === ma).length;
            if (trung) { toastr.error(TB.trung); return; }
            $tr.find('input, select').prop('disabled', true);
            $tr.find('.handle-save').addClass('d-none');
            $tr.find('.handle-edit').removeClass('d-none');
        });
        $hop.on('click', 'tr.row-item', function () {
            if ($(this).find('.handle-save').is(':visible')) return;
            $('#dg-dong tr.row-item').removeClass('active');
            $(this).addClass('active');
        });

        const ngayHop = (s) => (s ? s.split('-').reverse().join('-') : '');

        function moHop(f) {
            const sua = !!f;
            $hop.attr('data-id', sua ? f.id : '');
            $('#dg-tieu-de').text(sua ? 'Chỉnh sửa chương trình khuyến mại đồng giá' : 'Thêm chương trình khuyến mại đồng giá');
            $('#dg-ma').val(sua ? f.code : '');
            $('#dg-ten').val(sua ? f.name : '');
            moTaCu = sua ? (f.description || '') : '';
            const homNay = moment().format('DD-MM-YYYY');
            $('#dg-tu').val(sua && f.start_date ? ngayHop(f.start_date) : homNay);
            $('#dg-den').val(sua && f.end_date ? ngayHop(f.end_date) : homNay);
            $('#dg-khong-han').prop('checked', sua ? !!f.no_time_limit : false).trigger('change');
            datThu($('#dg-thu'), sua ? f.days_of_week : [1, 2, 3, 4, 5, 6, 7]);
            $(sua && !f.all_shops ? '#dg-cn-chon' : '#dg-cn-all').prop('checked', true);
            doiKieuChiNhanh();
            $('#dg-chi-nhanh').val(sua && !f.all_shops ? f.shop_ids.map(String) : []).trigger('change.select2');
            // Sửa thì không đổi được loại — dòng đã lưu gắn với nhóm hàng / hàng hoá.
            $('#dg-loai').val(sua ? String(f.type) : '1').prop('disabled', sua);
            $('#dg-bat').prop('checked', sua ? !!f.status : true);

            $('#dg-dong tbody tr.row-item').remove();
            if (sua) {
                const $add = $('#dg-dong tbody tr.tr-add');
                f.details.forEach((d) => $add.before(dongMoi({ object_id: d.object_id, quantity: d.quantity, price: d.price,
                    gifts: d.gifts.map((g) => ({ product_variant_id: g.product_variant_id, quantity: g.quantity })) }, false)));
            } else {
                themDongTrong();
            }
            $hop.modal('show');
        }

        $(document).on('click', '.add-item', () => moHop(null));
        $(document).on('click', '.edit-item', function () {
            const f = DG[$(this).closest('.item').data('id')];
            if (f.approved) {
                $('#modalHuyDuyet').attr('data-id', f.id);
                $('#hd-ten').text(f.name);
                $('#modalHuyDuyet').modal('show');
                return;
            }
            moHop(f);
        });
        $('#hd-xac-nhan').on('click', function () {
            const id = $('#modalHuyDuyet').attr('data-id');
            goiJson(URL_DG + '/' + id + '/cancel-approve', 'POST', {}).then(function (r) {
                if (!r.ok) { toastr.error(r.b.message || 'Không huỷ duyệt được.'); return; }
                toastr.success(r.b.message);
                $('#modalHuyDuyet').modal('hide');
                moHop(Object.assign({}, DG[id], { approved: false }));
                V2.napLai(location.href, false);
            });
        });

        $('.dg-luu').on('click', function () {
            const id = $hop.attr('data-id');
            if ($('#dg-dong .handle-save:not(.d-none)').length) { toastr.error(TB.chuaLuu); return; }
            if (!$('#dg-ten').val().trim()) { toastr.error('Nhập tên chương trình.'); return; }
            const khongHan = $('#dg-khong-han').is(':checked');
            if (!khongHan && (!$('#dg-tu').val() || !$('#dg-den').val())) { toastr.error('Chọn ngày áp dụng.'); return; }
            const thu = $('#dg-thu .content-day.active[data-thu!="all"]').map((i, el) => $(el).data('thu')).get();
            if (!thu.length) { toastr.error('Chọn ít nhất một ngày trong tuần.'); return; }
            const theoCN = $('#dg-cn-chon').is(':checked');
            const cn = $('#dg-chi-nhanh').val() || [];
            if (theoCN && !cn.length) { toastr.error('Chọn chi nhánh áp dụng.'); return; }
            const dong = $('#dg-dong tbody tr.row-item').map((i, el) => ({
                object_id: Number($(el).find('.select-item').val()),
                quantity: Number($(el).find('.quantity').val()),
                price: Number(chuSo($(el).find('.price').val()) || 0),
                gifts: $(el).data('gifts') || [],
            })).get();
            if (!dong.length) { toastr.error('Chưa có dữ liệu chi tiết đồng giá'); return; }

            V2.luuHop($hop, id ? URL_DG + '/' + id : URL_DG_TAO, id ? 'PUT' : 'POST', {
                name: $('#dg-ten').val().trim(),
                description: moTaCu,
                type: loai(),
                approved: $(this).data('duyet'),
                status: $('#dg-bat').is(':checked') ? 1 : 0,
                no_time_limit: khongHan ? 1 : 0,
                start_date: khongHan ? '' : $('#dg-tu').val(),
                end_date: khongHan ? '' : $('#dg-den').val(),
                'days_of_week[]': thu,
                all_shops: theoCN ? 0 : 1,
                'shop_ids[]': theoCN ? cn : [],
                details: JSON.stringify(dong),
            }, $(this));
        });

        // ================= Hộp Xem =================
        const $xem = $('#modalXemDongGia');
        $('#xem-chi-nhanh').select2({ width: 'auto', dropdownParent: $xem });
        let xemDong = [];

        function veQuaXem(i) {
            const $b = $xem.find('.table-show-gifts tbody').empty();
            ((xemDong[i] || {}).gifts || []).forEach(function (g) {
                const bt = DG_BT.find((o) => Number(o.id) === Number(g.product_variant_id)) || {};
                $b.append('<tr><td>' + esc(g.name || bt.text || '') + '</td><td>' + g.quantity + '</td><td>' + esc(bt.unit || '') + '</td></tr>');
            });
        }
        $(document).on('click', '.view-item', function () {
            const f = DG[$(this).closest('.item').data('id')];
            $('#xem-ma').val(f.code);
            $('#xem-ten').val(f.name);
            $('#xem-mo-ta').val(f.description || '');
            $('#xem-tu').val(f.no_time_limit ? '' : ngayHop(f.start_date));
            $('#xem-den').val(f.no_time_limit ? '' : ngayHop(f.end_date));
            datThu($('#xem-thu'), f.days_of_week);
            const tatCa = $('#xem-chi-nhanh option').map((i, o) => o.value).get();
            $('#xem-chi-nhanh').val(f.all_shops ? tatCa : f.shop_ids.map(String)).trigger('change.select2');
            $('#xem-loai').val(DG_LOAI[f.type] || '');
            $('#xem-bat').prop('checked', !!f.status);
            xemDong = f.details;
            const $b = $xem.find('.table-show-detail tbody').empty();
            xemDong.forEach(function (d, i) {
                $b.append('<tr data-i="' + i + '" class="' + (i === 0 ? 'active' : '') + '"><td>' + esc(d.object_name || '') + '</td><td>' + d.quantity
                    + '</td><td>' + soPhay(Math.round(Number(d.price) || 0)) + '</td></tr>');
            });
            veQuaXem(0);
            $xem.modal('show');
        });
        $xem.on('click', '.table-show-detail tbody tr', function () {
            $xem.find('.table-show-detail tbody tr').removeClass('active');
            $(this).addClass('active');
            veQuaXem(Number($(this).data('i')));
        });

        // ================= Xoá =================
        $(document).on('click', '.delete-item', function () {
            $('#deleteItem').attr('data-id', $(this).closest('.item').data('id')).modal('show');
        });
        $('#xoa-xac-nhan').on('click', function () {
            V2.luuHop('#deleteItem', URL_DG + '/' + $('#deleteItem').attr('data-id'), 'DELETE', {}, $(this));
        });
    </script>
@endpush
