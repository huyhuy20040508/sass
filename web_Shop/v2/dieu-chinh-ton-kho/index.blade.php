{{-- Màn Điều chỉnh tồn kho — chép bố cục v2 (warehouse/adjust: index + list + create + edit).
     Bên v2 hộp lập/sửa là HTML máy chủ trả về nạp vào #content_create; ở đây hai khuôn đó
     nằm sẵn trong <template> và JS đổ dữ liệu vào, vì backend phiếu chưa có.
     Dữ liệu controller đẩy sang: $list, $filters, $meta, $nhanVien, $nhomHang. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\DieuChinhTonKhoController::TITLE)

@php
    $C = \App\Http\Controllers\DieuChinhTonKhoController::class;
    $hasFilter = collect($filters)->only(['keyword', 'type', 'status', 'warehouse_status', 'created_by'])
        ->contains(fn ($v) => $v !== '' && $v !== null && $v !== 0 && $v !== [] && $v !== 'all');
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $trangThaiChon = array_filter(explode(',', $filters['status']));
    $nguoiTaoChon = array_filter(explode(',', (string) $filters['created_by']));
    $ngayVN = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '';

    // Người đang đăng nhập — ô "Người lập" mặc định của hộp lập phiếu.
    $u = session('api.user');
    $uId = (int) data_get($u, 'id', 0);
    $uTen = (string) (data_get($u, 'full_name') ?? data_get($u, 'name') ?? '');
@endphp

@push('styles')
    <style>
        /* ===== Khối style của warehouse/adjust/index.blade.php (v2) ===== */
        body{
            overflow-x: hidden
        }
        .custom-form-select2 .select2-container {
            width: 90% !important;
        }
        .bg-B0C7D240-25{
            background-color: rgba(176,199,210,.25) !important;
        }
        .bg-425D6D{
            background-color: #425D6D !important;
        }
        .btn-search,.btn-search i.fa {
            cursor: pointer;
            color: #666666 !important;
        }
        .btn-print {
            background-color: #026b97 !important;
            color: white;
            border: 1px solid #026b97 !important;
        }
        .select2-container{
            width: 100%!important;
        }

        .quantity-control {
            display: inline-flex;
            border: 1px solid #ccc;
            font-family: Arial, sans-serif;
        }

        .quantity-control button {
            width: 30px;
            height: 30px;
            border: none;
            background-color: #f1f1f1;
            cursor: pointer;
            font-size: 16px;
        }

        .quantity-control .btn-minus {
            border-right: 1px solid #ccc;
        }

        .quantity-control .btn-plus {
            border-left: 1px solid #ccc;
        }

        .quantity-control .quantity-adjust {
            width: 40px;
            text-align: center;
            line-height: 27px;
            display: inline-block;
            border: none;
        }
        .attachment-label i {
            color: #5392f3;
            cursor: pointer;
        }
        .attachment-filename {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .attachment-remove {
            cursor: pointer;
            color: #dc3545;
            margin-left: 5px;
        }

        .default-to-zero-item,
        .remove-menu {
            cursor: pointer;
        }

        /* ===== Khối style của create/edit.blade.php (v2) ===== */
        .form-check {
            gap: 10px !important;
            width: 100%;
        }
        .label-custom {
            font-size: 13px;
        }
        .hide {
            display: none;
        }
        #content_create label.form-label { text-align: left !important }

        /* Select2 trong hộp thoại phải nổi trên lớp phủ của modal. */
        #modalCreate .select2-container--open, #modalCreate .select2-dropdown { z-index: 1065 !important; }
        .table-product td, .table-product th { vertical-align: middle; }

        /* ---- Lưới hàng KHÔNG cuộn ngang trên máy tính (như v2) ----
           `table-layout: fixed` + mọi cột chia % cộng đúng 100%: để auto thì ô chọn
           lô và tên hàng dài nới bảng rộng hơn hộp và đẻ ra thanh cuộn. Dưới 992px
           thì 12 cột không thể vừa, chỗ đó mới cho cuộn. Cùng khuôn với lưới
           .table-lines của Phiếu điều chuyển. */
        #content_create .table-responsive, .modal-view-materials .table-responsive { overflow-x: hidden; }
        @media (max-width: 991px) {
            #content_create .table-responsive, .modal-view-materials .table-responsive { overflow-x: auto; }
            #content_create .table-product { min-width: 1100px; }
        }
        /* Hộp rộng hơn v2 một chút (v2 min-width 70%) để tiêu đề cột đứng nguyên
           một dòng — cắt "Trạng thái tồn kho" làm hai dòng là hàng tiêu đề cao gấp
           đôi và đọc khó. */
        #modalCreate .modal-dialog { min-width: 70%; width: 94%; max-width: 1700px; }
        .table-product { width: 100%; table-layout: fixed; }
        .table-product th { white-space: nowrap; vertical-align: middle; }
        .table-product td { overflow: hidden; text-overflow: ellipsis; }
        .table-product th:nth-child(1)  { width: 4%; }   /* STT */
        .table-product th:nth-child(2)  { width: 9%; }   /* Mã hàng */
        .table-product th:nth-child(3)  { width: 14%; }  /* Tên hàng */
        .table-product th:nth-child(4)  { width: 6%; }   /* ĐVT */
        .table-product th:nth-child(5)  { width: 12%; }  /* Số lô */
        .table-product th:nth-child(6)  { width: 9%; }   /* Hạn dùng */
        .table-product th:nth-child(7)  { width: 8%; }   /* SL tồn */
        .table-product th:nth-child(8)  { width: 10%; }  /* Điều chỉnh */
        .table-product th:nth-child(9)  { width: 9%; }   /* Tồn sau chỉnh */
        .table-product th:nth-child(10) { width: 9%; }   /* Trạng thái tồn kho */
        .table-product th:nth-child(11) { width: 5%; }   /* Đính kèm */
        .table-product th:nth-child(12) { width: 5%; }   /* Hành động */
        .table-product td.name { white-space: nowrap; }
        .table-product .lot_number_select { width: 100%; min-width: 0; }
        .table-product .quantity-control { max-width: 100%; }

        /* Bảng cân đối hàng âm: 7 cột, cùng luật chia %. */
        .table-balance { width: 100%; table-layout: fixed; }
        .table-balance th:nth-child(1) { width: 4%; }
        .table-balance th:nth-child(2) { width: 12%; }
        .table-balance th:nth-child(3) { width: 30%; }
        .table-balance th:nth-child(4) { width: 10%; }
        .table-balance th:nth-child(5) { width: 16%; }
        .table-balance th:nth-child(6) { width: 12%; }
        .table-balance th:nth-child(7) { width: 16%; }

        /* Ô khoá (mã, ngày, người lập…) vẫn phải đọc được chữ: style.css của vỏ v2
           tô mọi input và select trong modal màu #999 gần trắng. */
        #content_create .form-control:disabled { color: #212529 !important; background: #f4f6f8; }
    </style>
@endpush

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @include('v2::partials.filter-button-mobile',
                [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => "filterSearch",
                    'modalLabel' => __('message.search'),
                ]
            )
            @include('v2::partials.filter-button-mobile',
                [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => "filterTime",
                    'modalLabel' => __('message.time'),
                ]
            )
            @include('v2::partials.filter-button-mobile',
                [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => "filterCreator",
                    'modalLabel' => __('message.creator'),
                ]
            )
            @include('v2::partials.filter-button-mobile',
                [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => "filterStatus",
                    'modalLabel' => __('message.adjustment_status'),
                ]
            )
        </div>
    </div>

    <div class="row index-warehouse-ajust-page">
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">
                        {{ __('message.filter') }}
                    </div>
                    <div class="card-body px-2">
                        {{-- Form GET: master chặn submit và nạp lại bảng tại chỗ (V2.napLai). --}}
                        {{-- Bốn khối lọc theo đúng khuôn các màn phiếu (Phiếu điều chuyển, Trả hàng NCC):
                             mỗi khối = tiêu đề + ô; khoảng cách giữa khối do vỏ master lo, không rải mt-3.
                             Bỏ cặp custom-multiselect / chevron-down của v2: mũi tên ảnh đè lên ô select2. --}}
                        <form action="{{ route('admin.dieu-chinh-ton-kho.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterSearch" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.adjustment_code') }}</span>
                                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                        class="form-control mt-1 code" id="code" autocomplete="off"
                                        placeholder="{{ __('message.enter_code') }}">
                                </div>
                            </div>

                            <div id="filterTime" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.adjustment_created_date') }}</span>
                                    {{-- Hai ô ngày gõ theo DD-MM-YYYY và mở lịch daterangepicker, đúng như v2. --}}
                                    <div class="d-flex flex-lg-column gap-2 gap-lg-0">
                                        <input type="text" name="from_date" autocomplete="off"
                                            value="{{ $ngayVN($filters['from_date']) }}"
                                            class="form-control mb-lg-1" id="from_created_at"
                                            placeholder="{{ __('message.from_date') }}">
                                        <input type="text" name="to_date" autocomplete="off"
                                            value="{{ $ngayVN($filters['to_date']) }}"
                                            class="form-control" id="to_created_at" placeholder="{{ __('message.to_date') }}">
                                    </div>
                                </div>
                            </div>

                            <div id="filterCreator" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.adjustment_created_by') }}</span>
                                    <select class="form-control form-select mt-1" id="select_employee" name="created_by[]" multiple>
                                        @foreach ($nhanVien as $item)
                                            <option value="{{ $item['id'] }}"
                                                {{ in_array((string) $item['id'], $nguoiTaoChon, true) ? 'selected' : '' }}>
                                                {{ ($item['code'] ?? '') ? $item['code'] . ' - ' : '' }}{{ $item['full_name'] ?? ($item['name'] ?? '') }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div id="filterStatus" class="w-100">
                                <div class="inner-modal-in-mobile" id="search_status">
                                    <span class="title_search">{{ __('message.receipt_status') }}</span>
                                    @foreach ($C::TRANG_THAI as $ma => $ten)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input status" type="checkbox" name="status[]"
                                                value="{{ $ma }}" id="order_status_{{ $ma }}"
                                                {{ in_array($ma, $trangThaiChon, true) ? 'checked' : '' }}>
                                            <label class="form-check-label ms-2 {{ $C::CHU_TRANG_THAI[$ma] }}"
                                                style="font-weight: bold"
                                                for="order_status_{{ $ma }}">{{ $ten }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Đổi bộ lọc là về trang 1; số dòng mỗi trang thì giữ. --}}
                            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 mt-md-2 mt-lg-0">
            <div class="content_midd">
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">{{ __('message.warehouse_adjustment') }}</h1>

                    <div class="button-group d-flex my-auto">
                        <button class="btn btn-success btn_balance_quantity">
                            <span class="my-auto">{{ __('message.balance_negative_stock') }}</span>
                        </button>
                        <button class="btn btn_create btn_green mx-2">
                            <span class="my-auto">{{ __('message.create_new') }}</span>
                        </button>
                        <a class="btn btn-sm d-flex align-items-center btn-export"
                            href="{{ route('admin.dieu-chinh-ton-kho.export', request()->query()) }}">
                            <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export_report') }}
                        </a>
                    </div>
                </div>

                {{-- Bảng đi liền dưới thanh tiêu đề như mọi màn v2 khác (không lồng content_top / content_midd
                     thứ hai như bản v2 gốc: hai lớp đó đẩy bảng cách xa hàng nút). ===== list.blade.php của v2 ===== --}}
                <div class="list scrollDiv">
                            <div class="table-responsive table-border-style">
                                <table class="table-purchase list none_mobile">
                                    <tr>
                                        <th class="text-center not-export"><input class="form-check-input item-select-all" type="checkbox"></th>
                                        <th class="text-center">{{ __('message.stt') }}</th>
                                        <th class="text-left">{{ __('message.adjustment_code') }}</th>
                                        <th class="text-center">{{ __('message.type') }} </th>
                                        <th class="text-center">{{ __('message.adjustment_created_by') }}</th>
                                        <th class="text-center">{{ __('message.adjustment_created_date') }}</th>
                                        <th class="text-left">{{__('message.approver')}}</th>
                                        <th class="text-left">{{ __('message.receipt_status') }}</th>
                                        <th class="text-left">{{ __('message.reject_reason') }}</th>
                                        <th class="text-left">{{ __('message.warehouse_status') }}</th>
                                        <th class="text-left">{{ __('message.note') }}</th>
                                        <th class="text-center not-export">{{ __('message.action') }}</th>
                                    </tr>

                                    @forelse ($list as $i => $item)
                                        @php
                                            $id = (int) ($item['id'] ?? 0);
                                            $tt = $item['status'] ?? 'draft';
                                            $loai = $item['type'] ?? 'adjust';
                                            $ttKho = $item['warehouse_status'] ?? '';
                                        @endphp
                                        <tr class="item" data-id="{{ $id }}" data-status="{{ $tt }}">
                                            <td class="text-center not-export"><input class="form-check-input item-select" type="checkbox" value="{{ $id }}"></td>
                                            <td class="text-center">{{ $stt + $i + 1 }}</td>
                                            <td class="text-left">
                                                <a type="button" data-id="{{ $id }}" class="edit_bt edit-item text-decoration-none" data-bs-toggle="tooltip" data-bs-placement="top"
                                                    data-bs-title="{{ __('message.edit') }}">
                                                    {{ $item['code'] ?? '' }}
                                                </a>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge {{ $loai === 'balance' ? 'bg-success' : 'bg-info' }}  me-1">
                                                    {{ $C::LOAI_PHIEU[$loai] ?? $loai }}
                                                </span>
                                            </td>
                                            <td class="text-center">{{ $item['created_by_name'] ?? '' }}</td>
                                            <td class="text-center">{{ !empty($item['created_at']) ? $ngayVN($item['created_at']) : 'N/A' }}</td>
                                            <td class="text-left">{{ $tt === 'approved' ? ($item['approver_name'] ?? '') : '' }}</td>
                                            <td class="text-left">
                                                <b class="{{ $C::CHU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                            </td>
                                            <td class="text-left">{{ $item['reject_reason'] ?? '' }}</td>
                                            <td class="text-left">
                                                @if ($ttKho !== '' && isset($C::TRANG_THAI_KHO[$ttKho]))
                                                    <b class="{{ $C::CHU_TRANG_THAI_KHO[$ttKho] }}">{{ $C::TRANG_THAI_KHO[$ttKho] }}</b>
                                                @endif
                                            </td>
                                            <td class="text-left">{{ $item['note'] ?? '' }}</td>
                                            <td class="action not-export">
                                                @if ($tt === 'draft')
                                                    <a class="dele_bt delete-item" type="button" data-bs-toggle="tooltip" data-bs-placement="top"
                                                        data-bs-title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="12" class="text-center py-4">
                                                {{ $hasFilter ? 'Không có phiếu điều chỉnh nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </table>

                                <div class="table-purchase list none_desktop">
                                    <div class="d-flex align-items-center justify-content-between gap-1 p-2 border">
                                        <input class="form-check-input item-select-all" type="checkbox">
                                        <div class="fw-bold" style="flex: 1">
                                            {{ __('message.adjustment_code') }}
                                        </div>
                                        <div class="fw-bold">
                                            {{ __('message.warehouse_status') }}
                                        </div>
                                    </div>
                                    @foreach ($list as $key => $item)
                                        @php $tt = $item['status'] ?? 'draft'; @endphp
                                        <div key={{ $key }} class="item" data-id="{{ (int) ($item['id'] ?? 0) }}" data-status="{{ $tt }}">
                                            <input class="form-check-input item-select" type="checkbox" value="{{ (int) ($item['id'] ?? 0) }}">
                                            <div class="d-flex flex-column" style="flex: 1">
                                                <span class="fw-semibold">{{ $item['code'] ?? '' }}</span>
                                            </div>
                                            <div class="d-flex justify-content-end text-right show_quantity gap-2" style="min-width: 100px">
                                                <b class="{{ $C::CHU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                                <i class="fa-solid fa-angle-right d-none"></i>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="form_pagi">
                                @include('v2::partials.pagination', ['meta' => $meta])
                            </div>
                </div>

                <select class="form-control item-per-page select-width {{ count($list) ? '' : 'd-none' }}" data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>{{ __('message.display', ['name' => $muc]) }}</option>
                    @endforeach
                </select>
           </div>
        </div>
    </div>

    <div class="modal" id="deleteItem">
        <div class="modal-dialog modal-dialog-centered ">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.delete') }} ?</h6>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="deleteValue">
                    <div class="modal_center">
                        <div class="row">
                            <div class="col">
                                <label class="form-label">{{ __('message.delete-confirm') }}</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red denied-delete" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green delete-value">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="modalCreate" data-bs-keyboard="false" >
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-auto" style="min-width: 70%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.warehouse_adjustment_document') }}</h4>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body " style="padding-top: 0!important" id="content_create">
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end offcanvas-custom" tabindex="-1" id="offcanvasDetail"
        aria-labelledby="offcanvasDetailLabel">
        <div class="offcanvas-header">
            <a type="button" aria-label="Đóng" class="btn-back">
                <i class="fa-solid fa-arrow-left" style="font-size: 20px;"></i>
            </a>
            <div class="d-flex" style="flex: 1;">
                <h5 class="offcanvas-title" id="offcanvasDetailLabel">{{ __('message.detail') }}</h5>
            </div>
            <div class="d-flex button-header" style="gap: 12px;">
                <a class="dele_bt delete-item-canvas d-none" type="button"><i class="fa fa-trash"
                        style="color: red;"></i></a>
            </div>
        </div>
        <div class="offcanvas-body p-0" style="height: calc(100vh - 58px);">
            <div class="modal-view-materials" style="padding: 12px"></div>
            <div class="modal-edit-materials d-none">
                <div class="item-common">
                    <span class="name"></span>
                    <span class="code"></span>
                    <span class="quantity"></span>
                </div>
                <div class="item-quantity">
                    <h5>{{ __('message.general_information') }}</h5>
                    <div class="d-flex align-items-center justify-content-between"
                        style="border-bottom: 1px solid #ddd; padding: 4px;">
                        <span class="show_menu">{{ __('message.unit_of_measure') }}</span>
                        <span class="data-unit"></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between"
                        style="border-bottom: 1px solid #ddd; padding: 4px;">
                        <span class="show_menu">{{ __('message.unit_price') }}</span>
                        <span class="data-adjust-price"></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between"
                        style="border-bottom: 1px solid #ddd; padding: 4px;">
                        <span class="show_menu">{{ __('message.stock_after_adjustment') }}</span>
                        <span class="data-stock-after-adjust"></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between"
                        style="border-bottom: 1px solid #ddd; padding: 4px;">
                        <span class="show_menu">{{ __('message.status') }}</span>
                        <span class="data-status"></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between"
                        style="border-bottom: 1px solid #ddd; padding: 4px;">
                        <span class="show_menu">{{ __('message.batch_number') }}</span>
                        <span class="data-lot-number"></span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between"
                        style="border-bottom: 1px solid #ddd; padding: 4px;">
                        <span class="show_menu">{{ __('message.expiry_date') }}</span>
                        <span class="data-expire-date"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="modalBalanceQuantity">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="min-width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.negative_orders_pending_balance') }}</h6>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mx-1">
                        <span class="title_search">{{__('message.negative_stock_balance_description')}} </span>
                    </div>
                    <div class="mt-3" style="overflow: auto; height: 400px">
                        <table class="table-balance">
                            <thead>
                                <tr>
                                    <th class="text-center not-export"><input class="form-check-input item-select-all-balance" type="checkbox" checked></th>
                                    <th class="text-left">{{ __('message.menu-code') }}</th>
                                    <th class="text-left">{{ __('message.menu-name') }}</th>
                                    <th class="text-center">{{ __('message.unit') }}</th>
                                    <th class="text-center"> {{__('message.batch_number')}}</th>
                                    <th class="text-center">{{__('message.stock_quantity')}}</th>
                                    <th class="text-center">{{__('message.adjustment')}}</th>
                                </tr>
                            </thead>
                            <tbody class="list-balance"></tbody>
                        </table>
                   </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger denied-delete"
                        data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="btn btn-success btn_create_balance" style="color: white;" data-status="1">{{__('message.create_adjustment_note')}}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Báo "không thể gửi duyệt" khi máy chủ trả err_id = 1 (hàng đã bán ở POS). --}}
    <div class="modal" id="modalError">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="min-width: 40%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.approve_request') }}</h6>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center" style="color: red; text-align: center">
                        <h3 style="font-size: 20px;">{{ __('message.cannot_approve') }}</h3>
                        <p class="mb-0">{{ __('message.pos_transaction_exists') }}</p>
                        <p>{{ __('message.approve_may_cause_negative_stock') }}</p>
                        <p>{{ __('message.check_adjust_quantity_before_continue') }}</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red denied-delete"
                        data-bs-dismiss="modal">{{ __('message.close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="modalErrorApproval">
        <div class="modal-dialog modal-dialog-centered " style="min-width:60%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.confirm_approval') }}</h6>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center" style="color: red; text-align: center">
                        <h3 style="font-size: 20px;">{{ __('message.cannot_approve') }}</h3>
                        <p class="mb-0" style="white-space: pre-line">{{ __('message.warning_adjust_sold_pos') }}</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red denied-delete"
                        data-bs-dismiss="modal">{{ __('message.close') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="modalConfirmSendApproval">
        <div class="modal-dialog modal-dialog-centered " style="min-width: 20%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.confirm_send_request') }}</h6>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center" style="color: red; text-align: center">
                        <p>{{ __('message.confirm_approve_request') }}</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger denied-delete"
                        data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    {{-- Nút đồng ý luôn xanh, nút bỏ đi luôn đỏ — luật chung, dù v2 để cam. --}}
                    <button type="button" class="btn btn-success d-inline-block save-order" data-status="1">{{__('message.submit_for_approval')}}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="modalConfirmApproval">
        <div class="modal-dialog modal-dialog-centered " style="min-width: 20%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.confirm_approval') }}</h6>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center" style="color: red; text-align: center">
                        <p>{{ __('message.confirm_approve_action') }}</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger denied-delete"
                        data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="btn btn-success d-inline-block save-order" data-status="2">{{ __('message.approve') }}</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="modalReasonCancel">
        <div class="modal-dialog modal-dialog-centered " style="min-width: 40%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.reject_reason') }}</h6>
                    <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center" style="color: red; text-align: center">
                        <div class="box-textarea-cus">
                            <textarea
                                class="form-control reject_reason p-2"
                                maxlength="200"
                                style="height: 70px"
                                rows="3"
                                placeholder="{{ __('message.enter_reject_reason') }}"
                            ></textarea>
                            <small class="char-counter char-counter2"></small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger denied-delete"
                        data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="btn btn-success d-inline-block save-order" data-status="3">{{__('message.reject')}}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===== Khuôn hộp LẬP phiếu — create.blade.php của v2 ===== --}}
    <template id="tplCreate">
    <div class="row">
        <div class="col-12">
            <form action="" id="formPayment">
                <div class="py-3 px-4 pt-2 payment-info">
                    <div class="card-header border-bottom mb-3 pb-2">
                        <div class="d-flex justify-content-end">
                            <div class="button-group d-flex my-auto">
                                <button type="button" class="btn btn-secondary d-inline-block save-order" data-status="0">{{ __('message.status-temporary') }}</button>
                                <button type="button" class="btn btn-warning d-inline-block show-popup-confirm-send-approval mx-2" style="color: white;">{{__('message.submit_for_approval')}}</button>
                                <button type="button" class="btn btn-success d-inline-block show-popup-confirm-approval">{{ __('message.approve') }}</button>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.document_number')}}</label>
                                <div>
                                    <input type="text" name="code" class="form-control" placeholder="{{__('message.auto_increment_code')}}" disabled>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.creation-date')}}</label>
                                <div>
                                    <input type="text" name="created_date" class="form-control date_picker" disabled>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.created_po_by')}}</label>
                                <div>
                                    <select name="created_by" id="created_by" class="form-control select2" disabled>
                                        @php $coToi = false; @endphp
                                        @foreach ($nhanVien as $item)
                                            @php $coToi = $coToi || (int) $item['id'] === $uId; @endphp
                                            <option {{ (int) $item['id'] === $uId ? 'selected' : '' }} value="{{ $item['id'] }}">{{ $item['code'] ?? '' }} {{ !empty($item['code']) ? '-' : '' }} {{ $item['full_name'] ?? ($item['name'] ?? '') }}</option>
                                        @endforeach
                                        @if (!$coToi && $uTen !== '')
                                            <option value="{{ $uId }}" selected>{{ $uTen }}</option>
                                        @endif
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.receipt_status')}}</label>
                                <div>
                                    <input type="text" style="font-weight: bold; color: green !important;" name="adjustment_status" class="form-control" value="{{__('message.create_new')}}" disabled>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.warehouse_status')}}</label>
                                <div>
                                    <input type="text" name="warehouse_status" class="form-control" disabled>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.note')}}</label>
                                <div class="box-textarea-cus">
                                    <textarea class="form-control note p-2" aria-label="{{ __('message.detailed_reason') }}" maxlength="200" style="height: 70px" rows="3"></textarea>
                                    <small class="char-counter char-counter2"></small>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2 align-items-end">
                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.product_group')}}</label>
                                <select class="form-control select-categories">
                                    <option value="">{{ __('message.select-menu-group') }}</option>
                                    @foreach ($nhomHang ?? [] as $category)
                                        <option value="{{ $category['id'] }}">{{ $category['name'] ?? '' }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12 col-md-4 mt-2 mt-md-0">
                                <button type="button" class="btn btn-primary add-category w-100" disabled>{{ __('message.add_all_products_from_group') }}</button>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.goods')}}</label>
                                <select class="form-control select-menus form-select select2" multiple ></select>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div class="col-12 px-md-4 d-flex justify-content-end mb-2">
                <button type="button" class="btn btn-primary default-to-zero" disabled>
                    <i class="fa-solid fa-rotate-right me-2"></i>{{__('message.default_to_zero')}}
                </button>
            </div>

            <div class="col-12 px-md-4">
                <div class="info-product border-top">
                    <div class="content_midd border-0">
                        <div class="table-responsive" style="max-height: calc(100vh - 500px);">
                            <table class="table-product">
                                <tbody>
                                    <tr>
                                        <th class="text-center">{{ __('message.stt') }}</th>
                                        <th class="text-left">{{ __('message.menu-code') }}</th>
                                        <th class="text-left">{{ __('message.menu-name') }}</th>
                                        <th class="text-left">{{ __('message.unit') }}</th>
                                        <th class="text-center"> {{__('message.batch_number')}} <span class="required">*</span></th>
                                        <th class="text-right">{{__('message.expiry_date')}}</th>
                                        <th class="text-center">{{__('message.stock_quantity')}}</th>
                                        <th class="text-center">{{__('message.adjustment')}}</th>
                                        <th class="text-center">{{__('message.stock_after_adjustment')}}</th>
                                        <th class="text-right">{{__('message.status')}} {{ Str::lower(__('message.inventory')) }}</th>
                                        <th class="text-center">{{__('message.attachment')}}</th>
                                        <th class="text-center not-import not-export">{{__('message.action')}}</th>
                                    </tr>
                                </tbody>
                                <tbody class="list-menu">
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </template>

    {{-- ===== Khuôn hộp SỬA / XEM phiếu — edit.blade.php của v2 (JS đổ dữ liệu vào) ===== --}}
    <template id="tplEdit">
    <div class="row">
        <div class="col-12">
            <form action="" id="formPayment">
                <input type="hidden" name="id" value="">
                <div class="py-3 px-4 pt-2 payment-info">
                    <div class="card-header border-bottom mb-3 pb-2 edit-actions">
                        <div class="d-flex justify-content-end">
                            <div class="button-group d-flex my-auto gap-2">
                                <button type="button" class="btn btn-secondary d-inline-block save-order chi-luu-tam" data-status="0">{{ __('message.status-temporary') }}</button>
                                <button type="button" class="btn btn-warning d-inline-block show-popup-confirm-send-approval chi-luu-tam" style="color: white;">{{__('message.submit_for_approval')}}</button>
                                <button type="button" class="btn btn-success d-inline-block show-popup-confirm-approval chi-cho-duyet">{{ __('message.approve') }}</button>
                                <button type="button" class="btn btn-danger d-inline-block show-popup-confirm-reject chi-cho-duyet">{{__('message.reject')}}</button>
                            </div>
                        </div>
                    </div>

                    <div class="card-body">
                        <div class="row mb-2">
                            <div class="col-md-4 mb-2 item1">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.document_number')}}</label>
                                <div>
                                    <input type="text" name="code" class="form-control" placeholder="{{__('message.auto_increment_code')}}" value="" disabled>
                                </div>
                            </div>

                            <div class="col-md-4 mb-2 item1">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.creation-date')}}</label>
                                <div>
                                    <input type="text" name="created_date" value="" class="form-control date_picker" disabled>
                                </div>
                            </div>

                            <div class="col-md-4 mb-2 item1">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.created_po_by')}}</label>
                                <div>
                                    <select name="created_by" id="created_by" class="form-control select2" disabled>
                                        @foreach ($nhanVien as $item)
                                            <option value="{{ $item['id'] }}">{{ $item['code'] ?? '' }} {{ !empty($item['code']) ? '-' : '' }} {{ $item['full_name'] ?? ($item['name'] ?? '') }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-4 mb-2 item1">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.approval_date')}}</label>
                                <div>
                                    <input type="text" name="approval_date" value="" class="form-control" disabled>
                                </div>
                            </div>

                            <div class="col-md-4 mb-2 item1">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.approver')}}</label>
                                <div>
                                    <select name="approver" id="approver" class="form-control select2" disabled>
                                        <option value=""></option>
                                        @foreach ($nhanVien as $item)
                                            <option value="{{ $item['id'] }}">{{ $item['code'] ?? '' }} {{ !empty($item['code']) ? '-' : '' }} {{ $item['full_name'] ?? ($item['name'] ?? '') }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-4 mb-2 item1">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.receipt_status')}}</label>
                                <div>
                                    <input type="text" style="font-weight: bold;" name="adjustment_status" class="form-control" value="" disabled>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-2 item1">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.warehouse_status')}}</label>
                                <div>
                                    <input type="text" name="warehouse_status" class="form-control" value="" style="font-weight: bold; color: black !important;" disabled>
                                </div>
                            </div>

                            <div class="col-md-8 mb-2">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.note')}}</label>
                                <div class="box-textarea-cus">
                                    <textarea class="form-control note p-2" maxlength="200" style="height: 70px" rows="3"></textarea>
                                    <small class="char-counter char-counter2"></small>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-2 align-items-end">
                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.product_group')}}</label>
                                <select class="form-control select-categories">
                                    <option value="">{{ __('message.select-menu-group') }}</option>
                                    @foreach ($nhomHang ?? [] as $category)
                                        <option value="{{ $category['id'] }}">{{ $category['name'] ?? '' }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="col-12 col-md-4 mt-2 mt-md-0">
                                <button type="button" class="btn btn-primary add-category w-100" disabled>{{ __('message.add_all_products_from_group') }}</button>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label mt-1" style="text-align: left!important;">{{__('message.goods')}}</label>
                                <select class="form-control select-menus form-select select2" multiple></select>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <div class="col-12 d-flex justify-content-end mb-2 px-2">
                <button type="button" class="btn btn-primary default-to-zero">
                    <i class="fa-solid fa-rotate-right me-2"></i>{{__('message.default_to_zero')}}
                </button>
            </div>

            <div class="col-12 px-2">
                <div class="info-product border-top">
                    <div class="content_midd border-0">
                        <div class="table-responsive"  style="max-height: calc(100vh - 500px);">
                            <table class="table-product none_mobile">
                                <thead>
                                    <tr>
                                        <th class="text-center">{{ __('message.stt') }}</th>
                                        <th class="text-left">{{ __('message.menu-code') }}</th>
                                        <th class="text-left">{{ __('message.menu-name') }}</th>
                                        <th class="text-left">{{ __('message.unit') }}</th>
                                        <th class="text-center"> {{__('message.batch_number')}} <span class="required">*</span></th>
                                        <th class="text-right">{{__('message.expiry_date')}}</th>
                                        <th class="text-center">{{__('message.stock_quantity')}}</th>
                                        <th class="text-center">{{__('message.adjustment')}}</th>
                                        <th class="text-center">{{__('message.stock_after_adjustment')}}</th>
                                        <th class="text-right">{{__('message.status')}} {{ Str::lower(__('message.inventory')) }}</th>
                                        <th class="text-center">{{__('message.attachment')}}</th>
                                        <th class="text-center not-import not-export">{{__('message.action')}}</th>
                                    </tr>
                                </thead>
                                <tbody class="list-menu"></tbody>
                            </table>
                            {{-- Bản thẻ cho điện thoại: bấm một dòng thì mở chi tiết trong offcanvas. --}}
                            <div class="table-product none_desktop">
                                <div class="item">
                                    <div class="fw-bold" style="flex: 1">
                                        {{ __('message.menu-name') }}
                                    </div>
                                    <div class="fw-bold">
                                        {{ __('message.stock_quantity') }}
                                    </div>
                                </div>
                                <div class="list-menu-mobile"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </template>

    {{-- Dòng mẫu của lưới hàng — v2 để sẵn một <table class="d-none"> rồi clone. --}}
    <table class="d-none">
        <tbody>
            <tr class="menu-item">
                <td class="text-center index"></td>
                <td class="text-left code"></td>
                <td class="text-left name"></td>
                <td class="text-left unit"></td>
                <td class="text-center lot_number"></td>
                <td class="text-center expire_date"></td>
                <td class="text-center quantity"></td>
                <td class="text-center adjust_quantity">
                    <div class="quantity-control" style="display: block !important;">
                        <button type="button" class="btn-minus">-</button>
                        <input type="text" class="quantity-adjust" value="0">
                        <button type="button" class="btn-plus">+</button>
                    </div>
                    <div class="lot-undefined-control" style="display: none">
                        <span class="text-danger">{{__('message.adjust_to_zero')}} </span>
                    </div>
                </td>
                <td class="text-center stock_after_adjust"></td>
                <td class="text-left inventory_status"></td>
                <td class="text-center attached">
                    <div class="attachment-wrap">
                        <label class="attachment-label">
                            <i class="fa-solid fa-paperclip"></i>
                            <input type="file" class="form-control attachment-input" hidden>
                        </label>
                    </div>
                </td>
                <td class="text-center not-export">
                    <i class="fa-solid fa-trash text-danger remove-menu"></i>
                    <i class="fa-solid fa-rotate-right default-to-zero-item ms-2"></i>
                </td>
            </tr>
        </tbody>
    </table>
@endsection

@push('scripts')
    <script>
        const URL_BASE = @json(url('/admin/inventory-adjustments'));
        const URL_STORE = @json(route('admin.dieu-chinh-ton-kho.store'));
        const URL_MAT_HANG = @json(route('admin.dieu-chinh-ton-kho.matHang'));
        const URL_NHOM_HANG = @json(route('admin.dieu-chinh-ton-kho.matHangTheoNhom'));
        const URL_HANG_AM = @json(route('admin.dieu-chinh-ton-kho.hangAm'));
        const URL_LO_HANG = @json(route('admin.dieu-chinh-ton-kho.loHang'));
        const URL_ANH = @json(route('admin.dieu-chinh-ton-kho.anh'));
        const LO_KHONG_XAC_DINH = @json(\App\Http\Controllers\DieuChinhTonKhoController::LO_KHONG_XAC_DINH);
        const NHAN_TRANG_THAI = @json(\App\Http\Controllers\DieuChinhTonKhoController::TRANG_THAI);
        const NHAN_KHO = @json(\App\Http\Controllers\DieuChinhTonKhoController::TRANG_THAI_KHO);
        const NHAN_DONG = @json(\App\Http\Controllers\DieuChinhTonKhoController::TRANG_THAI_DONG);
        // Nút của v2 mang số 0/1/2/3; API bên này nói bằng chữ.
        const MA_TRANG_THAI = { 0: 'draft', 1: 'pending', 2: 'approved', 3: 'rejected' };
        const MAU_TRANG_THAI = { draft: 'green', pending: 'orange', approved: 'blue', rejected: 'red' };
        const CHU_KHO = { pending: 'text-secondary', done: 'text-primary', rejected: 'text-danger' };
        const TOI_ID = @json($uId);

        var startOfMonth = moment().startOf('month');
        var today = moment().endOf('day');
        let list_new_lot = [];
        let sentMenuIds = [];
        let PHIEU = null;        // phiếu đang mở trong hộp (null = lập mới)
        let lotUndefined = [];
        let $HOP = $('#content_create'); // nơi đang đổ khuôn phiếu: modal, hoặc offcanvas trên điện thoại

        // isMobileChecked() lấy từ public/v2/js/script.js (khai const toàn cục) — khai lại
        // ở đây là SyntaxError "already been declared" và cả trang chết script.

        // Số lượng tới 3 số lẻ, bỏ đuôi ".000" — đúng formatDecimal của v2.
        function formatDecimal(v) {
            const n = Math.round((Number(v) || 0) * 1000) / 1000;
            return Number.isInteger(n) ? n : n.toFixed(3).replace(/0+$/, '');
        }

        // ---------- Bộ lọc: v2 gọi list() ngay khi đổi; bên này gửi form cho master nạp lại ----------
        const $form = $('#search-form');

        $('input#from_created_at').daterangepicker({
            singleDatePicker: true,
            showDropdowns: true,
            locale: V2.lichVN(),
            autoUpdateInput: false,
            autoApply: true,
            maxDate: today.format('DD-MM-YYYY')
        }, function(start, end, label) {
            $('input#from_created_at').val(start.format('DD-MM-YYYY')).trigger("change");
            $('input#to_created_at').data('daterangepicker').minDate = start;
        });

        $('input#to_created_at').daterangepicker({
            singleDatePicker: true,
            showDropdowns: true,
            locale: V2.lichVN(),
            autoUpdateInput: false,
            autoApply: true,
            maxDate: today.format('DD-MM-YYYY'),
            minDate: startOfMonth
        }, function(start, end, label) {
            $('input#to_created_at').val(start.format('DD-MM-YYYY')).trigger("change");
            $('input#from_created_at').data('daterangepicker').maxDate = start;
        });

        $('#select_employee').select2({ placeholder: '{{ __('message.all') }}', width: '100%' });

        let debounceTimer;
        $(document).on('input', '#code', function(e) {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() {
                $form.trigger('submit');
            }, 300);
        });
        $(document).on('change', '#select_employee', function() { $form.trigger('submit'); });
        $(document).on('change', '#from_created_at, #to_created_at', function () { $form.trigger('submit'); });
        $(document).on('change', '.status', function() { $form.trigger('submit'); });

        $(document).on('click', '.list .item-select-all', function() {
            let checked = $(this).is(':checked')
            $('.list .item .item-select').prop('checked', checked)
        })

        $(document).on('click', '.item-select-balance', function() {
            let isAllCheck = true;
            $('.item-select-balance').each(function() {
                if (!this.checked) {
                    isAllCheck = false;
                }
            });
            $('.item-select-all-balance').prop('checked', isAllCheck);
        });

        $(document).on('click', '.item-select-all-balance', function() {
            let checked = $(this).is(':checked')
            $('.item-select-balance').prop('checked', checked)
        })

        // ---------- Xoá ----------
        $(document).on('click', '.delete-item', function(e) {
            e.stopPropagation();
            let item = $(this).closest('.item')
            let id = item.attr('data-id')
            $('#deleteValue').val(id)
            $('#deleteItem').modal('show')
        })

        $(document).on('click', '.delete-value', function() {
            var deleteValue = $('#deleteValue').val();
            var deleteArray = deleteValue.split(/[\s,]+/).filter(Boolean);
            deleteItems(deleteArray)
        })

        $('#deleteItem').on('hidden.bs.modal', function() {
            $('#deleteValue').val('');
        });

        function deleteItems(ids) {
            $('#deleteItem').modal('hide');
            $('#offcanvasDetail').offcanvas('hide');
            if (ids.length === 1) {
                V2.ghi(URL_BASE + '/' + ids[0], 'DELETE', {});
            } else if (ids.length > 1) {
                V2.ghi(URL_BASE + '/bulk-delete', 'POST', { 'ids[]': ids });
            }
        }

        // =================================================================
        //  Hộp lập / sửa phiếu
        // =================================================================
        function moHop(html, $dich) {
            $HOP = $dich && $dich.length ? $dich : $('#content_create');
            $HOP.html(html);
            if ($HOP.is('#content_create')) $('#modalCreate').modal('show');
            khoiTaoHop();
        }

        function khoiTaoHop() {
            const $hop = $HOP;
            $hop.find('input.date_picker').val($hop.find('input.date_picker').val() || moment().format('DD-MM-YYYY'));

            $hop.find('.select-categories').select2({
                dropdownParent: $('#modalCreate'),
            });
            $hop.find('#created_by, #approver').select2({
                dropdownParent: $('#modalCreate'),
                width: '100%',
            });

            $hop.find('.select-menus').select2({
                ajax: {
                    url: URL_MAT_HANG,
                    delay: 200,
                    transport: V2.ajaxTimHang,
                    data: function(params) {
                        return {
                            keyword: params.term || '',
                            category_id: $hop.find('.select-categories').val() || 0,
                        };
                    },
                    dataType: 'json',
                    processResults: function(data) {
                        return {
                            results: $.map(data.data || [], function(item) {
                                return {
                                    id: item.variant_id,
                                    text: [item.product_name, item.variant_name].filter(Boolean).join(' · '),
                                    mh: item,
                                };
                            })
                        };
                    },
                    cache: false
                },
                language: { errorLoading: V2.loiTimHang },
                dropdownParent: $HOP,
                placeholder: "{{__('message.item-name')}}",
                allowClear: true
            }).on('select2:opening', function(e) {
                if ($(this).data('preventOpen')) {
                    $(this).removeData('preventOpen');
                    e.preventDefault();
                }
            });

            toggleAddCategoryButton();
            updateCharCounter();
            calculatorMenuList();
        }

        $(document).on('mousedown', '.select2-selection__choice__remove', function(e) {
            const select = $(this).closest('.select2-container').prev('.select-menus');
            select.data('preventOpen', true);
        });

        function toggleAddCategoryButton() {
            let categoryId = $('#content_create .select-categories').val();
            let khoa = $('#content_create .select-categories').prop('disabled');
            $('#content_create .add-category').prop('disabled', khoa || !categoryId);
        }

        $(document).on('change', '#content_create .select-categories', function() {
            toggleAddCategoryButton();
        });

        // Chọn hàng ở ô "Hàng hóa" → thêm dòng (v2: loadMenuByIds gọi getMenu lấy lô).
        $(document).on('select2:select', '#content_create .select-menus', function(e) {
            const mh = e.params.data.mh;
            if (!mh) return;
            loadMenuByIds([mh.variant_id]);
            $(this).val('').trigger('change');
        });

        /**
         * Chọn hàng xong mới hỏi lô — đúng hai nhịp của v2 (menu.select rồi getMenu).
         * Ô tìm hàng dùng chung của phiếu mua chỉ bày lô dương; đường lô riêng của
         * màn này trả cả lô "Không xác định" và lô đang âm để có chỗ mà chữa.
         */
        function loadMenuByIds(ids) {
            ids = (ids || []).map(Number).filter(Boolean);
            if (!ids.length) return;

            $.getJSON(URL_LO_HANG, { ids: ids.join(',') })
                .done(function(res) {
                    (res.data || []).forEach(function(mh) { themDongHang(mh); });
                    sentMenuIds = $('.list-menu .menu-item').map(function() { return $(this).attr('data-id'); }).get();
                    calculatorMenuList();
                })
                .fail(function(x) {
                    handleMessage({ success: false, message: (x.responseJSON || {}).message || 'Không đọc được lô hàng.' });
                });
        }

        $(document).on('select2:unselect', '#content_create .select-menus', function(e) {
            let removedId = e.params.data.id;
            $('.list-menu .menu-item[data-id="' + removedId + '"]').remove();
            calculatorMenuList();
        });

        $(document).on('click', '#content_create .add-category', function() {
            let groupId = $('#content_create .select-categories').val();
            if (!groupId) {
                alert("Vui lòng chọn nhóm trước!");
                return;
            }
            const $nut = $(this).prop('disabled', true);

            $.getJSON(URL_NHOM_HANG, { category_id: groupId })
                .done(function(res) {
                    const ds = res.data || [];
                    if (!ds.length) {
                        alert("Không có sản phẩm nào trong nhóm.");
                        return;
                    }
                    // Bấm nút hai lần không được nhân đôi lưới: chỉ thêm mặt hàng chưa có dòng.
                    // (Chọn từng hàng ở ô "Hàng hóa" vẫn thêm được dòng thứ hai cho lô khác, như v2.)
                    const daCo = $('#content_create .list-menu .menu-item').map(function() { return String($(this).attr('data-id')); }).get();
                    const moi = ds.map(function(mh) { return mh.variant_id; }).filter(function(id) { return daCo.indexOf(String(id)) === -1; });
                    if (!moi.length) {
                        handleMessage({ success: 'info', message: 'Mọi mặt hàng của nhóm đã có trong phiếu.' });
                        return;
                    }
                    loadMenuByIds(moi);
                })
                .fail(function(x) {
                    handleMessage({ success: false, message: (x.responseJSON || {}).message || 'Không đọc được nhóm hàng.' });
                })
                .always(function() { $nut.prop('disabled', false); });
        });

        /** Dựng một dòng hàng từ mặt hàng API trả về (mh) — v2: loadMenuByIds. */
        function themDongHang(mh, cu) {
            let item = $('table.d-none tr.menu-item').clone();
            item.attr('data-id', mh.variant_id);
            item.attr('data-lot-quantity', 0);
            item.find('.code').text(mh.sku || '');
            item.find('.name').text([mh.product_name, mh.variant_name].filter(Boolean).join(' · '));
            item.find('.unit').html(mh.base_unit_name || mh.unit_name || '');
            item.attr('data-unit-id', mh.base_unit_id || mh.unit_id || 0);

            // Ô số lô: mọi lô đang có dòng tại kho kèm "(SL: x)" — kể cả lô "Không xác
            // định" (lot_number rỗng) và lô âm. Hàng chưa có dòng lô nào thì bày lô
            // "Không xác định" với tổng tồn, để vẫn chỉnh được.
            let lots = (mh.lots || []).slice();
            if (!lots.length) {
                lots.push({ lot_number: LO_KHONG_XAC_DINH, expire_date: '', quantity: mh.stock || 0 });
            }
            var innerHtml = `<select name="lot_number" class="form-control form-select lot_number_select">
                              <option value="">{{__('message.select_batch')}}</option>`;
            if (list_new_lot.length > 0) {
                list_new_lot.forEach(function (lot) {
                    innerHtml += `<option value="${lot}">${lot}</option>`;
                });
            }
            lots.forEach(function (lot) {
                let lotQty = (lot.quantity != null && lot.quantity !== '') ? parseFloat(lot.quantity) : null;
                innerHtml += `<option
                                value="${lot.lot_number ?? ''}"
                                data-expire-date="${ngayVN(lot.expire_date)}"
                                data-lot-quantity="${lotQty ?? ''}"
                            >${lot.lot_number == LO_KHONG_XAC_DINH ? "{{__('message.unknown')}}" : (lot.lot_number ?? '')} (SL: ${lotQty ?? 0})</option>`;
            });
            innerHtml += `</select>`;
            item.find('.lot_number').html(innerHtml);

            // Dòng của phiếu đang sửa: chọn sẵn lô và số đã lưu.
            if (cu) {
                item.attr('data-detail-id', cu.id || '');
                const $sel = item.find('.lot_number_select');
                const lo = cu.lot_number || LO_KHONG_XAC_DINH;
                if (!$sel.find(`option[value="${lo}"]`).length) {
                    $sel.append(`<option value="${lo}" data-lot-quantity="${formatDecimal(cu.quantity)}" data-expire-date="${ngayVN(cu.expire_date)}">${lo == LO_KHONG_XAC_DINH ? "{{__('message.unknown')}}" : lo} (SL: ${formatDecimal(cu.quantity)})</option>`);
                }
                $sel.val(lo);
                item.attr('data-lot-quantity', formatDecimal(cu.quantity));
                item.find('.quantity').text(formatDecimal(cu.quantity));
                item.find('.expire_date').text(lo == LO_KHONG_XAC_DINH ? '' : ngayVN(cu.expire_date));
                item.find('.quantity-adjust').val(formatDecimal(cu.adjust_quantity));
                item.find('.stock_after_adjust').text(formatDecimal((Number(cu.quantity) || 0) + (Number(cu.adjust_quantity) || 0)));
                item.find('.inventory_status').html(chuTrangThaiKho(PHIEU ? PHIEU.warehouse_status : ''));
                item.attr('data-adjust-price', cu.adjust_price || 0);
                if (lo == LO_KHONG_XAC_DINH) {
                    item.find('.remove-menu').hide();
                }
                if (cu.attachment) {
                    item.find('.attached').html(`
                        <input type="text" name="old_attached" class="old-attached" value="${cu.attachment}" hidden>
                        <a href="${cu.attachment}" target="_blank" download><i class="fa-solid fa-download"></i></a>`);
                }
            }

            $HOP.find('.list-menu').append(item);
        }

        function ngayVN(v) {
            if (!v) return '';
            const m = moment(v, ['YYYY-MM-DD', 'DD-MM-YYYY', moment.ISO_8601], true);
            return m.isValid() ? m.format('DD-MM-YYYY') : '';
        }

        function chuTrangThaiKho(ma) {
            if (!ma || !NHAN_KHO[ma]) return '';
            return `<b class="${CHU_KHO[ma] || ''}">${NHAN_KHO[ma]}</b>`;
        }

        $(document).on('click', '.remove-menu', function() {
            $(this).closest('.menu-item').remove();
            sentMenuIds = $('.list-menu .menu-item').map(function() {
                return $(this).attr('data-id');
            }).get();
            calculatorMenuList();
        });

        function calculatorMenuList() {
            const $items = $HOP.find('.list-menu .menu-item');
            $items.each(function (i, e) {
                $(e).find('.index').text(i + 1);
            });
            const khoa = PHIEU && PHIEU.status !== 'draft';
            $HOP.find('.default-to-zero').prop('disabled', khoa || $items.length === 0);
        }

        // ---------- Ô số lô ----------
        $(document).on('change', '.lot_number_select', function() {
            const selectedOption = $(this).find('option:selected');
            const item = $(this).closest('tr');

            let id_menu = item.data('id');
            let val = $(this).val();
            let arr_lot = [];
            $('.list-menu .menu-item[data-id="' + id_menu + '"]').not(item).each(function() {
                let lotVal = $(this).find('.lot_number_select').val();
                if (lotVal !== 'new' && lotVal !== '' && lotVal !== null && lotVal !== undefined) {
                    arr_lot.push(lotVal);
                }
            })

            if (arr_lot.includes(val)) {
                alert('{{__('message.duplicate_batch_for_product')}}');
                $(this).val('');
                return;
            }

            if (selectedOption.val() != '') {
                let lotQuantity = selectedOption.data('lot-quantity');
                if (lotQuantity !== '' && lotQuantity !== null && lotQuantity !== undefined) {
                    if (selectedOption.val() == LO_KHONG_XAC_DINH) {
                        item.find('.stock_after_adjust').text(0);
                        item.find('.expire_date').text('');
                        item.find('.lot-undefined-control').show();
                        item.find('.default-to-zero-item').hide();
                    } else {
                        item.find('.lot-undefined-control').hide();
                        item.find('.default-to-zero-item').show();
                    }
                    item.find('.quantity').text(lotQuantity);
                    item.attr('data-lot-quantity', lotQuantity);
                    item.find('.quantity-adjust').val(0);
                    item.find('.stock_after_adjust').text('');
                } else {
                    item.find('.quantity-adjust').val(0);
                    item.find('.quantity').text('0');
                    item.attr('data-lot-quantity', 0);
                    item.find('.stock_after_adjust').text('');
                }

                if (selectedOption.data('expire-date') && selectedOption.data('expire-date') != '') {
                    item.find('.expire_date').text(selectedOption.data('expire-date'));
                }
            } else {
                item.find('.expire_date').text('');
                item.find('.quantity').text('');
                item.attr('data-lot-quantity', 0);
                item.find('.quantity-adjust').val(0);
                item.find('.stock_after_adjust').text('');
            }
        });

        // ---------- Ô điều chỉnh: nút − / + ----------
        const ALLOW_DECIMAL_ADJUST = true;

        $(document).on('click', '.btn-minus', function () {
            const $item = $(this).closest('.menu-item');
            const selectedOption = $item.find('option:selected');
            const $input = $item.find('.quantity-adjust');

            if (selectedOption.val() == '') {
                return;
            }

            $item.css('border', 'none');

            const $stockAfterAdjust = $item.find('.stock_after_adjust');
            const lotQuantity = parseFloat($item.attr('data-lot-quantity')) || 0;
            let value = parseFloat($input.val()) || 0;

            if ((value + lotQuantity) > 0) {
                value--;
                $input.val(formatDecimal(value));
                $stockAfterAdjust.text(formatDecimal(lotQuantity + value));
            }
        });

        $(document).on('click', '.btn-plus', function () {
            const $item = $(this).closest('.menu-item');
            const $input = $item.find('.quantity-adjust');
            const selectedOption = $item.find('option:selected');

            if (selectedOption.val() == '') {
                return;
            }

            $item.css('border', 'none');

            const $stockAfterAdjust = $item.find('.stock_after_adjust');
            const lotQuantity = parseFloat($item.attr('data-lot-quantity')) || 0;
            let value = parseFloat($input.val()) || 0;

            value++;
            $input.val(formatDecimal(value));
            $stockAfterAdjust.text(formatDecimal(lotQuantity + value));
        });

        $(document).on('input', '.quantity-adjust', function (e) {
            const $input = $(this);
            const $item = $input.closest('.menu-item');
            const selectedOption = $item.find('option:selected');
            const $stockAfterAdjust = $item.find('.stock_after_adjust');

            if (selectedOption.val() == '') {
                $input.val('');
                e.preventDefault();
                return;
            }

            const lotQuantity = parseFloat($item.attr('data-lot-quantity')) || 0;

            let rawValue = $input.val();

            // Chỉ giữ một dấu trừ ở đầu.
            rawValue = rawValue.replace(/-/g, '');
            if ($input.val().charAt(0) === '-') {
                rawValue = '-' + rawValue;
            }

            rawValue = rawValue.replace(/[^\d.-]/g, '');

            if (!ALLOW_DECIMAL_ADJUST) {
                rawValue = rawValue.replace(/\./g, '');
            }

            const parts = rawValue.split('.');
            if (parts.length > 2) {
                rawValue = parts[0] + '.' + parts.slice(1).join('');
            }

            // Tối đa 3 số thập phân.
            const dotIdx = rawValue.indexOf('.');
            if (dotIdx !== -1 && rawValue.length - dotIdx - 1 > 3) {
                rawValue = rawValue.slice(0, dotIdx + 4);
            }

            if (rawValue === '-' || rawValue === '-0' || rawValue === '-.' || rawValue === '.') {
                $input.val(rawValue);
                $stockAfterAdjust.text(lotQuantity);
                return;
            }

            if (rawValue === '') {
                $stockAfterAdjust.text(lotQuantity);
                return;
            }

            if (rawValue.charAt(0) !== '-' && !rawValue.startsWith('0.')) {
                rawValue = rawValue.replace(/^0+/, '');
                if (rawValue === '' || rawValue === '.') rawValue = '0';
            }

            if (rawValue.startsWith('-') && !rawValue.startsWith('-0.')) {
                let numberPart = rawValue.slice(1).replace(/^0+/, '');
                rawValue = '-' + numberPart;
                if (rawValue === '-' || rawValue === '-0' || rawValue === '-.') {
                    $input.val(rawValue);
                    $stockAfterAdjust.text(lotQuantity);
                    return;
                }
            }

            $input.val(rawValue);

            let value = parseFloat(rawValue);
            if (isNaN(value)) value = 0;

            // Tồn sau chỉnh không được âm.
            if ((value + lotQuantity) < 0) {
                value = -lotQuantity;
                $input.val(value);
            }

            let stockAfterAdjustValue = lotQuantity + value;

            if (stockAfterAdjustValue % 1 !== 0) {
                $stockAfterAdjust.text(stockAfterAdjustValue.toFixed(3));
            } else {
                $stockAfterAdjust.text(stockAfterAdjustValue);
            }
        });

        $(document).on('blur', '.quantity-adjust', function () {
            const $input = $(this);
            const $item = $input.closest('.menu-item');
            const $stockAfterAdjust = $item.find('.stock_after_adjust');
            const lotQuantity = parseFloat($item.attr('data-lot-quantity')) || 0;

            let rawValue = $input.val();

            if (rawValue === '' ||
                rawValue === '-' ||
                rawValue === '.' ||
                rawValue === '-.' ||
                rawValue.endsWith('.')) {

                rawValue = rawValue.replace(/\.$/, '');

                if (rawValue === '' || rawValue === '-') {
                    rawValue = '0';
                }

                let value = parseFloat(rawValue);
                if (isNaN(value)) value = 0;

                if (Number.isInteger(value)) {
                    $input.val(value);
                } else {
                    $input.val(value.toFixed(3));
                }

                const stockAfter = lotQuantity + value;
                if (stockAfter != 0 || lotQuantity != 0) {
                    $stockAfterAdjust.text(parseFloat(stockAfter.toFixed(3)));
                } else {
                    $stockAfterAdjust.text('');
                }
            }
        });

        // ---------- Đính kèm từng dòng: hiện tên tệp + nút xoá như v2; tệp đẩy lên ngay để lấy đường dẫn ----------
        $(document).on('change', '.attachment-input', function () {
            const $input = $(this);
            const $item = $input.closest('.menu-item');
            const $wrap = $item.find('.attachment-wrap');
            const file = this.files[0];

            if (file) {
                $wrap.find('.attachment-label').hide();

                let filename = file.name;
                if (filename.length > 10) {
                    const ext = filename.split('.').pop();
                    filename = filename.substring(0, 3) + '...' + filename.slice(-3 - ext.length);
                }

                $wrap.append(`
                    <span class="attachment-filename">
                        ${filename}
                        <i class="fa-solid fa-xmark attachment-remove"></i>
                    </span>
                `);

                const fd = new FormData();
                fd.append('anh', file);
                $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                    .done(function(res) { $item.attr('data-attachment', res.url || ''); })
                    .fail(function(x) {
                        handleMessage({ success: false, message: (x.responseJSON || {}).message || 'Không tải được tệp đính kèm.' });
                        $wrap.find('.attachment-remove').trigger('click');
                    });
            }
        });

        $(document).on('click', '.attachment-remove', function () {
            const $item = $(this).closest('.menu-item');
            const $wrap = $item.find('.attachment-wrap');
            $wrap.find('.attachment-input').val('');
            $wrap.find('.attachment-filename').remove();
            $wrap.find('.attachment-label').show();
            $item.attr('data-attachment', '');
        });

        // ---------- Mặc định về 0 ----------
        $(document).on('click', '.default-to-zero', function () {
            $('#content_create .quantity-adjust').val(0);
            $('#content_create .list-menu .menu-item').each(function (i, item) {
                const $item = $(item);
                const $stockAfterAdjust = $item.find('.stock_after_adjust');
                const lotQuantity = parseFloat($item.attr('data-lot-quantity')) || 0;
                $stockAfterAdjust.text(lotQuantity != 0 ? formatDecimal(lotQuantity) : '');
            });
        });

        $(document).on('click', '.default-to-zero-item', function () {
            const $item = $(this).closest('.menu-item');
            const selectedOption = $item.find('option:selected');
            $item.find('.quantity-adjust').val(0);
            const $stockAfterAdjust = $item.find('.stock_after_adjust');
            const lotQuantity = parseFloat($item.attr('data-lot-quantity')) || 0;

            if (lotQuantity != 0 && selectedOption.val() != LO_KHONG_XAC_DINH) {
                $stockAfterAdjust.text(formatDecimal(lotQuantity));
            } else {
                $stockAfterAdjust.text('');
            }
        });

        // ---------- Đếm ký tự ----------
        $(document).on('input', '#formPayment textarea, #modalReasonCancel textarea', function() {
            updateCharCounter();
        });

        function updateCharCounter() {
            $('#formPayment textarea, #modalReasonCancel textarea').each(function() {
                let length = $(this).val().length;
                let maxLength = $(this).attr('maxlength');
                $(this).siblings('.char-counter2').text(length + '/' + maxLength);
            });
        }

        // ---------- Mở hộp lập mới ----------
        $(document).on('click', '.btn_create', function(e) {
            PHIEU = null;
            list_new_lot = [];
            sentMenuIds = [];
            moHop(document.getElementById('tplCreate').innerHTML);
        })

        // ---------- Mở hộp sửa / xem ----------
        $(document).on('click', '.edit-item', function(e) {
            e.stopPropagation();
            var id = $(this).data('id');
            moPhieu(id);
        });

        function moPhieu(id) {
            lotUndefined = [];
            $.getJSON(URL_BASE + '/' + id)
                .done(function(res) {
                    doPhieuVaoHop(res.data || {});
                })
                .fail(function(x) {
                    handleMessage({ success: false, message: (x.responseJSON || {}).message || 'Không đọc được phiếu.' });
                });
        }

        /** Đổ một phiếu vào khuôn edit — thay cho HTML máy chủ trả về ở v2. $dich bỏ trống = hộp modal. */
        function doPhieuVaoHop(p, $dich) {
            PHIEU = p;
            list_new_lot = [];
            const $html = $('<div>').html(document.getElementById('tplEdit').innerHTML);
            const tt = p.status || 'draft';
            const nhap = tt === 'draft';

            $html.find('input[name="id"]').val(p.id || '');
            $html.find('input[name="code"]').val(p.code || '');
            $html.find('input[name="created_date"]').val(p.created_at ? moment(p.created_at).format('DD-MM-YYYY') : '');
            $html.find('#created_by').val(String(p.created_by || TOI_ID));

            // Ngày duyệt: đã duyệt lấy approval_date, từ chối lấy ngày sửa cuối.
            let ngayDuyet = '';
            if (tt === 'approved' && p.approval_date) ngayDuyet = moment(p.approval_date).format('DD-MM-YYYY');
            else if (tt === 'rejected' && p.updated_at) ngayDuyet = moment(p.updated_at).format('DD-MM-YYYY');
            $html.find('input[name="approval_date"]').val(ngayDuyet);
            // API ghi người duyệt / từ chối vào handled_by; ô Người duyệt chỉ có nghĩa khi đã duyệt.
            $html.find('#approver').val(String(tt === 'approved' ? (p.handled_by || '') : ''));

            $html.find('input[name="adjustment_status"]')
                .val(NHAN_TRANG_THAI[tt] || '{{ __('message.create_new') }}')
                .css('color', (MAU_TRANG_THAI[tt] || 'black') + '');
            $html.find('input[name="adjustment_status"]')[0].style.setProperty('color', MAU_TRANG_THAI[tt] || 'black', 'important');
            $html.find('input[name="warehouse_status"]').val(NHAN_KHO[p.warehouse_status] || '');
            $html.find('textarea.note').val(p.note || '');

            // Đã duyệt / từ chối: giấu hẳn hàng nút. Lưu tạm: Lưu tạm + Gửi duyệt. Chờ duyệt: Duyệt + Từ chối.
            if (tt === 'approved' || tt === 'rejected') $html.find('.edit-actions').remove();
            $html.find('.chi-luu-tam').toggleClass('d-none', tt !== 'draft');
            $html.find('.chi-cho-duyet').toggleClass('d-none', tt !== 'pending');

            if (!nhap) {
                $html.find('textarea.note, .select-categories, .select-menus, .default-to-zero').prop('disabled', true);
            }

            moHop($html.html(), $dich);

            (p.items || []).forEach(function(it) {
                themDongHang({
                    variant_id: it.variant_id,
                    sku: it.sku || '',
                    product_name: it.product_name || '',
                    variant_name: it.variant_name || '',
                    base_unit_name: it.base_unit_name || it.unit_name || '',
                    base_unit_id: it.unit_id || 0,
                    lots: it.lots || [],
                    stock: it.quantity || 0,
                }, it);
            });

            if (!nhap) {
                $HOP.find('.list-menu :input').prop('disabled', true);
                $HOP.find('.list-menu .remove-menu, .list-menu .default-to-zero-item').remove();
            }

            veTheMobile(p);
            calculatorMenuList();
        }

        /** Bản thẻ cho điện thoại của hộp sửa — mỗi dòng mang dữ liệu để offcanvas đọc. */
        function veTheMobile(p) {
            const $ds = $HOP.find('.list-menu-mobile').empty();
            (p.items || []).forEach(function(it, key) {
                const ttKho = NHAN_KHO[p.warehouse_status] || NHAN_KHO.pending;
                $ds.append(`
                    <div key="${key}" class="item"
                        data-id="${it.id || ''}"
                        data-code="${it.sku || ''}"
                        data-name="${[it.product_name, it.variant_name].filter(Boolean).join(' · ')}"
                        data-quantity="${formatDecimal(it.quantity)}"
                        data-unit="${it.base_unit_name || it.unit_name || ''}"
                        data-lot-number="${it.lot_number || LO_KHONG_XAC_DINH}"
                        data-expire-date="${ngayVN(it.expire_date)}"
                        data-status="${ttKho}"
                        data-adjust-price="${Number(it.adjust_price || 0).toLocaleString('vi-VN')}"
                        data-stock-after-adjust="${formatDecimal((Number(it.quantity) || 0) + (Number(it.adjust_quantity) || 0))}">
                        <div class="d-flex flex-column" style="flex: 1">
                            <span class="fw-semibold">${[it.product_name, it.variant_name].filter(Boolean).join(' · ')}</span>
                            <span style="font-size: 14px">${it.sku || ''}</span>
                        </div>
                        <div class="d-flex text-right show_quantity gap-2">
                            ${formatDecimal(it.quantity)}
                            <i class="fa-solid fa-angle-right d-none"></i>
                        </div>
                    </div>`);
            });
        }

        // ---------- Gom dữ liệu lưới → gửi ----------
        function submitOrder(status, $nut) {
            let details = [];

            $('#content_create .list-menu .menu-item').each(function(i, e) {
                let lot_number = $(e).find('select.lot_number_select').val();
                let expire_date = $(e).find('.expire_date').text();

                details.push({
                    variant_id: $(e).attr('data-id'),
                    unit_id: $(e).attr('data-unit-id') || 0,
                    quantity: parseFloat($(e).find('.quantity').text()) || 0,
                    adjust_quantity: parseFloat($(e).find('.quantity-adjust').val()) || 0,
                    lot_number: lot_number || '',
                    expire_date: expire_date,
                    attachment: $(e).attr('data-attachment') || $(e).find('.old-attached').val() || '',
                })
            });

            // Soát như validator store() của v2: phải có dòng, dòng nào cũng phải có lô.
            if (!details.length) {
                handleMessage({ success: false, message: '{{ __('message.product_list_required') }}' });
                return;
            }
            if (details.some(function(d) { return !d.lot_number; })) {
                handleMessage({ success: false, message: '{{ __('message.please_enter_complete_lot_number') }}' });
                return;
            }

            const maTT = MA_TRANG_THAI[status] || 'draft';
            const note = $('#content_create textarea.note').val() || '';

            // Phiếu chờ duyệt: Duyệt / Từ chối đi đường riêng, không gửi lại lưới hàng.
            // Hai lượt này đóng hộp rồi nạp lại bảng; hỏng thì toast báo.
            if (PHIEU && PHIEU.status === 'pending' && maTT === 'approved') {
                $('#modalCreate').modal('hide');
                return V2.ghi(URL_BASE + '/' + PHIEU.id + '/approve', 'POST', { note: note });
            }
            if (PHIEU && maTT === 'rejected') {
                $('#modalCreate').modal('hide');
                return V2.ghi(URL_BASE + '/' + PHIEU.id + '/reject', 'POST', { reject_reason: $('#modalReasonCancel .reject_reason').val() || '' });
            }

            // Lưu bằng AJAX: hỏng (bớt quá tồn, trùng lô, mất kết nối) thì GIỮ HỘP LẠI
            // cùng lưới hàng vừa gõ; được thì hộp tự đóng, toast, nạp lại bảng — đúng
            // nhịp `modal('hide'); list(1)` của v2.
            const fields = {
                type: PHIEU ? (PHIEU.type || 'adjust') : 'adjust',
                status: maTT,
                note: note,
                items: JSON.stringify(details),
            };
            V2.luuHop($('#modalCreate'), PHIEU ? URL_BASE + '/' + PHIEU.id : URL_STORE, PHIEU ? 'PUT' : 'POST', fields, $nut);
        }

        $(document).on('click', '.save-order', function() {
            let status = $(this).attr('data-status');
            if (status == 3 && !$('#modalReasonCancel .reject_reason').val().trim()) {
                handleMessage({ success: false, message: '{{ __('message.enter_reject_reason') }}' });
                return;
            }
            $('#modalConfirmSendApproval').modal('hide');
            $('#modalConfirmApproval').modal('hide');
            $('#modalReasonCancel').modal('hide');
            submitOrder(status, $(this));
        })

        $(document).on('click', '.show-popup-confirm-send-approval', function() {
            $('#modalConfirmSendApproval').modal('show');
        })
        $(document).on('click', '.show-popup-confirm-approval', function() {
            $('#modalConfirmApproval').modal('show');
        })
        $(document).on('click', '.show-popup-confirm-reject', function() {
            $('#modalReasonCancel .reject_reason').val('');
            updateCharCounter();
            $('#modalReasonCancel').modal('show');
        })

        // =================================================================
        //  Cân đối hàng âm
        // =================================================================
        $(document).on('click', '.btn_balance_quantity', function(e) {
            const modal = $('#modalBalanceQuantity');
            $.getJSON(URL_HANG_AM)
                .done(function(data) {
                    const lots = data.data || [];
                    if (!lots.length) {
                        handleMessage({ success: false, message: data.message || '{{ __('message.no_negative_stock') }}' });
                        modal.find('.list-balance').html('');
                        return;
                    }
                    var html = '';
                    lots.forEach(function(lot, i) {
                        html += `
                            <tr class="item-balance" data-id="${lot.variant_id}" data-i="${i}">
                                <td class="text-center not-export"><input class="form-check-input item-select-balance" type="checkbox" checked></td>
                                <td class="text-left">${lot.sku ?? '-'}</td>
                                <td class="name text-left">${[lot.product_name, lot.variant_name].filter(Boolean).join(' · ') || '-'}</td>
                                <td class="unit text-center">${lot.unit_name ?? lot.base_unit_name ?? '-'}</td>
                                <td class="lot_number text-center">${lot.lot_number || LO_KHONG_XAC_DINH}</td>
                                <td class="quantity text-center">${formatDecimal(lot.quantity)}</td>
                                <td class="text-center"><span class="text-danger">{{__('message.adjust_to_zero')}}</span></td>
                            </tr>
                        `
                    });
                    modal.data('lots', lots);
                    modal.find('.list-balance').html(html);
                    modal.modal('show');
                })
                .fail(function(x) {
                    handleMessage({ success: false, message: (x.responseJSON || {}).message || '{{ __('message.no_negative_stock') }}' });
                });
        })

        // Phiếu cân đối luôn lưu tạm (status 0, is_balance_sheet = 1) — đúng v2.
        $(document).on('click', '.btn_create_balance', function(e) {
            const lots = $('#modalBalanceQuantity').data('lots') || [];
            let details = [];
            $('#modalBalanceQuantity .item-balance').each(function(i, e) {
                if (!$(e).find('.item-select-balance').is(':checked')) return;
                const lot = lots[Number($(e).attr('data-i'))] || {};
                let quantity = parseFloat(lot.quantity) || 0;
                details.push({
                    variant_id: $(e).attr('data-id'),
                    unit_id: lot.unit_id || lot.base_unit_id || 0,
                    quantity: quantity,
                    adjust_quantity: Math.abs(quantity),
                    lot_number: lot.lot_number || LO_KHONG_XAC_DINH,
                    expire_date: '',
                    attachment: '',
                })
            })
            if (!details.length) {
                handleMessage({ success: false, message: '{{ __('message.product_list_required') }}' });
                return;
            }
            $('.btn_create_balance').prop('disabled', true);
            $('#modalBalanceQuantity').modal('hide');
            V2.ghi(URL_STORE, 'POST', {
                type: 'balance',
                status: 'draft',
                note: '',
                items: JSON.stringify(details),
            });
            $('.btn_create_balance').prop('disabled', false);
        })

        // =================================================================
        //  Điện thoại: bấm dòng phiếu → offcanvas chi tiết; bấm dòng hàng → thẻ chi tiết
        // =================================================================
        $(document).on('click', '.table-purchase .item', function (e) {
            if ($(e.target).closest('.item-select, .delete-item, .edit-item').length) {
                return;
            }
            if (isMobileChecked()) {
                let item = $(this).closest('.item');
                var id = item.attr('data-id');
                var status = item.attr('data-status');
                $('#offcanvasDetail').attr('data-id', id);
                $('#offcanvasDetail').attr('data-status', status);
                if (status == 'draft') {
                    $('.delete-item-canvas').removeClass('d-none');
                } else $('.delete-item-canvas').addClass('d-none');

                $.getJSON(URL_BASE + '/' + id)
                    .done(function(res) {
                        // Chỉ xem: đổ khuôn vào offcanvas rồi khoá hết ô nhập.
                        doPhieuVaoHop(res.data || {}, $('.modal-view-materials'));
                        $HOP.find(':input').prop('disabled', true);
                        $HOP.find('.edit-actions, .remove-menu, .default-to-zero-item').remove();
                        $HOP = $('#content_create');

                        let offcanvasEl = document.getElementById('offcanvasDetail');
                        let bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
                        bsOffcanvas.show();
                    })
                    .fail(function(x) {
                        handleMessage({ success: false, message: (x.responseJSON || {}).message || 'Không đọc được phiếu.' });
                    });
            }
        });

        $(document).on('click', '.table-product .item', function (e) {
            if (isMobileChecked()) {
                let value = $(this).closest('.item');
                if (!value.attr('data-name')) return;

                $('#offcanvasDetail').attr('data-id', value.attr('data-id'));

                $('#offcanvasDetail').find('.item-common .name').text(value.attr('data-name'));
                $('#offcanvasDetail').find('.item-common .code').text(value.attr('data-code'));
                $('#offcanvasDetail').find('.item-common .quantity').text(value.attr('data-quantity'))

                $('#offcanvasDetail').find('.item-quantity .data-lot-number').text(value.attr('data-lot-number') || '-')
                $('#offcanvasDetail').find('.item-quantity .data-expire-date').text(value.attr('data-expire-date') || '-');
                $('#offcanvasDetail').find('.item-quantity .data-unit').text(value.attr('data-unit') || '-');
                $('#offcanvasDetail').find('.item-quantity .data-status').text(value.attr('data-status') || '-')
                $('#offcanvasDetail').find('.item-quantity .data-stock-after-adjust').text(value.attr('data-stock-after-adjust') || '-');
                $('#offcanvasDetail').find('.item-quantity .data-adjust-price').text(value.attr('data-adjust-price') || '-');
                state.isEdit = true;
            }
        });

        $(document).on('click', '.delete-item-canvas', function() {
            let id = $(this).closest('#offcanvasDetail').attr('data-id')
            $('#deleteValue').val(id)
            $('#deleteItem').modal('show')
        });

        const state = new Proxy({
            isEdit: false
        }, {
            set(target, property, value) {
                target[property] = value;
                if (property === 'isEdit') {
                    updateUI(value);
                }
                return true;
            }
        });

        function updateUI(isEdit) {
            if (isEdit) {
                $('.modal-edit-materials').removeClass('d-none');
                $('.modal-view-materials').addClass('d-none');
                $('.button-header').addClass('d-none');
                document.querySelector('.offcanvas-title').innerText = "{{ __('message.product_details') }}";
            } else {
                $('.button-header').removeClass('d-none');
                $('.button-header').addClass('d-flex');
                $('.modal-edit-materials').addClass('d-none');
                $('.modal-view-materials').removeClass('d-none');
                document.querySelector('.offcanvas-title').innerText = "{{ __('message.detail') }}";
            }
        }
        $(document).on('hidden.bs.offcanvas', '#offcanvasDetail', function () {
            state.isEdit = false;
        });
        $(document).on('click', '.btn-back', function () {
            if (state.isEdit) {
                state.isEdit = false;
            } else {
                $('.offcanvas.show').each(function () {
                    let bsOffcanvas = bootstrap.Offcanvas.getInstance(this);
                    if (bsOffcanvas) {
                        bsOffcanvas.hide();
                    }
                });
            }
        });
    </script>
@endpush
