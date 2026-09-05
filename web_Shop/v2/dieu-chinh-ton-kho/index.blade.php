{{-- Màn Điều chỉnh tồn kho dựng theo khuôn v2 (warehouse/adjust: index + list + create).
     Dữ liệu do DieuChinhTonKhoController đẩy sang: $list, $filters, $meta, $nhanVien, $nhomHang. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\DieuChinhTonKhoController::TITLE)

@php
    $C = \App\Http\Controllers\DieuChinhTonKhoController::class;
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $trangThaiChon = array_filter(explode(',', $filters['status']));
    $nguoiTaoChon = array_filter(explode(',', (string) $filters['created_by']));
@endphp

@push('styles')
    <style>
        /* Lấy nguyên khối style của warehouse/adjust bản v2. */
        body { overflow-x: hidden }
        .select2-container { width: 100% !important; }
        .quantity-control {
            display: inline-flex;
            border: 1px solid #ccc;
            font-family: Arial, sans-serif;
        }
        .quantity-control button {
            width: 30px; height: 30px; border: none;
            background-color: #f1f1f1; cursor: pointer; font-size: 16px;
        }
        .quantity-control .btn-minus { border-right: 1px solid #ccc; }
        .quantity-control .btn-plus { border-left: 1px solid #ccc; }
        .quantity-control .quantity-adjust {
            width: 60px; text-align: center; line-height: 27px; display: inline-block; border: none;
        }
        .attachment-label i { color: #5392f3; cursor: pointer; }
        .attachment-filename { display: inline-flex; align-items: center; gap: 5px; }
        .attachment-remove { cursor: pointer; color: #dc3545; margin-left: 5px; }
        .default-to-zero-item, .remove-menu { cursor: pointer; }
        .btn-print { background-color: #026b97 !important; color: white; border: 1px solid #026b97 !important; }
        #content_create label.form-label { text-align: left !important }
        .table-product td, .table-product th { vertical-align: middle; }
        .lot-new-box { display: flex; gap: 6px; align-items: center; margin-top: 4px; }
        .lot-new-box input { width: 110px; }
    </style>
@endpush

@section('content')
    {{-- Nút mở bộ lọc dạng offcanvas, chỉ hiện trên điện thoại — đúng bốn nút của v2. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterSearch">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterTime">
                <p class="open-modal-label">{{ __('message.time') }}</p>
                <div class="icon-for-cta"><i class="fa-regular fa-calendar"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterCreator">
                <p class="open-modal-label">{{ __('message.creator') }}</p>
                <div class="icon-for-cta"><i class="fa-regular fa-user"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterStatus">
                <p class="open-modal-label">{{ __('message.adjustment_status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-filter"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-warehouse-ajust-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <form action="{{ route('admin.dieu-chinh-ton-kho.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterSearch">
                                <div class="inner-modal-in-mobile">
                                    <div class="input-group mb-3">
                                        <span class="title_search">{{ __('message.order_code') }}</span>
                                        <div class="d-flex w-100">
                                            <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                                class="form-control code" id="code"
                                                placeholder="{{ __('message.enter_code') }}" autocomplete="off">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="filterTime" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.order-created-at') }}</span>
                                    {{-- Hai ô ngày gõ theo DD-MM-YYYY và mở lịch daterangepicker, đúng như v2. --}}
                                    <div class="d-flex flex-lg-column gap-2 gap-lg-0">
                                        <input type="text" name="from_date" autocomplete="off"
                                            value="{{ $filters['from_date'] ? date('d-m-Y', strtotime($filters['from_date'])) : '' }}"
                                            class="form-control mb-lg-1" id="from_date"
                                            placeholder="{{ __('message.from_date') }}">
                                        <input type="text" name="to_date" autocomplete="off"
                                            value="{{ $filters['to_date'] ? date('d-m-Y', strtotime($filters['to_date'])) : '' }}"
                                            class="form-control" id="to_date"
                                            placeholder="{{ __('message.to_date') }}">
                                    </div>
                                </div>
                            </div>

                            <div id="filterCreator" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="form-label title_search">{{ __('message.creator') }}</span>
                                    <div class="d-flex custom-multiselect">
                                        <select class="form-control form-select select2" id="select_employee"
                                            name="created_by[]" multiple>
                                            @foreach ($nhanVien as $nv)
                                                <option value="{{ $nv['id'] }}"
                                                    {{ in_array((string) $nv['id'], $nguoiTaoChon, true) ? 'selected' : '' }}>
                                                    {{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                                </option>
                                            @endforeach
                                        </select>
                                        <span class="chevron-down"></span>
                                    </div>
                                </div>
                            </div>

                            <div id="filterStatus">
                                <div class="inner-modal-in-mobile input-group d-flex flex-column" id="search_status">
                                    <span class="title_search d-none d-md-block">{{ __('message.adjustment_status') }}</span>
                                    @foreach ($C::TRANG_THAI as $ma => $ten)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input status" type="checkbox" name="status[]"
                                                value="{{ $ma }}" id="order_status_{{ $ma }}"
                                                {{ in_array($ma, $trangThaiChon, true) ? 'checked' : '' }}>
                                            <label class="form-check-label ms-2 {{ $C::CHU_TRANG_THAI[$ma] }}"
                                                style="font-weight: bold" for="order_status_{{ $ma }}">{{ $ten }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 mt-md-2 mt-lg-0 wrapper-content-dashboard-middle">
            <div class="content_midd">
                <div class="content_midd_title">
                    {{-- h1 chứ không phải h3: đây là tiêu đề cấp 1 của trang. Cỡ chữ dùng
                         .tieu-de-trang như mọi màn v2 khác — trước đây màn này để .h5
                         (20px) còn màn khác 24px, mà bản gốc v2 thì 18px cả loạt. --}}
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

                @if(!empty($error))
                    <div class="alert alert-warning py-2 my-2">{{ $error }}</div>
                @endif

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-purchase list none_mobile">
                            <tr>
                                <th class="text-center not-export"><input class="form-check-input item-select-all" type="checkbox"></th>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left">{{ __('message.adjustment_code') }}</th>
                                <th class="text-center">{{ __('message.type') }}</th>
                                <th class="text-center">{{ __('message.adjustment_created_by') }}</th>
                                <th class="text-center">{{ __('message.adjustment_created_date') }}</th>
                                <th class="text-left">{{ __('message.approver') }}</th>
                                <th class="text-left">{{ __('message.receipt_status') }}</th>
                                <th class="text-left">{{ __('message.reject_reason') }}</th>
                                <th class="text-left">{{ __('message.warehouse_status') }}</th>
                                <th class="text-left">{{ __('message.note') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($list as $i => $p)
                                @php
                                    $id = (int) ($p['id'] ?? 0);
                                    $tt = $p['status'] ?? 'draft';
                                    $loai = $p['type'] ?? 'adjust';
                                    $ttKho = $p['warehouse_status'] ?? '';
                                @endphp
                                <tr class="item" data-id="{{ $id }}" data-status="{{ $tt }}"
                                    data-code="{{ $p['code'] ?? '' }}">
                                    <td class="text-center not-export">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    </td>
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    <td class="text-left">
                                        <a type="button" data-id="{{ $id }}" class="edit_bt edit-item text-decoration-none"
                                            data-bs-toggle="tooltip" data-bs-placement="top"
                                            data-bs-title="{{ __('message.edit') }}">{{ $p['code'] ?? '' }}</a>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge {{ $loai === 'balance' ? 'bg-success' : 'bg-info' }} me-1">
                                            {{ $C::LOAI_PHIEU[$loai] ?? $loai }}
                                        </span>
                                    </td>
                                    <td class="text-center">{{ $p['created_by_name'] ?? '' }}</td>
                                    <td class="text-center">
                                        {{ !empty($p['created_at']) ? date('d-m-Y', strtotime($p['created_at'])) : 'N/A' }}
                                    </td>
                                    <td class="text-left">
                                        {{ $tt === 'approved' ? ($p['approver_name'] ?? '') : '' }}
                                    </td>
                                    <td class="text-left">
                                        <b class="{{ $C::CHU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                    </td>
                                    <td class="text-left">{{ $p['reject_reason'] ?? '' }}</td>
                                    <td class="text-left">
                                        @if($ttKho !== '' && isset($C::TRANG_THAI_KHO[$ttKho]))
                                            <b class="{{ $C::CHU_TRANG_THAI_KHO[$ttKho] }}">{{ $C::TRANG_THAI_KHO[$ttKho] }}</b>
                                        @endif
                                    </td>
                                    <td class="text-left">{{ $p['note'] ?? '' }}</td>
                                    <td class="action not-export">
                                        @if($tt === 'draft')
                                            <a class="dele_bt delete-item" type="button" data-bs-toggle="tooltip"
                                                data-bs-placement="top" data-bs-title="{{ __('message.delete') }}">
                                                <i class="fa fa-times"></i>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="12" class="text-center py-4">{{ $C::EMPTY_TEXT }}</td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai — đúng như v2. --}}
                        <div class="table-purchase list none_desktop">
                            <div class="d-flex align-items-center justify-content-between gap-1 p-2 border">
                                <input class="form-check-input item-select-all" type="checkbox">
                                <div class="fw-bold" style="flex: 1">{{ __('message.adjustment_code') }}</div>
                                <div class="fw-bold">{{ __('message.warehouse_status') }}</div>
                            </div>
                            @foreach ($list as $p)
                                @php $tt = $p['status'] ?? 'draft'; @endphp
                                <div class="item" data-id="{{ (int) ($p['id'] ?? 0) }}" data-status="{{ $tt }}">
                                    <input class="form-check-input item-select" type="checkbox" value="{{ (int) ($p['id'] ?? 0) }}">
                                    <div class="d-flex flex-column" style="flex: 1">
                                        <span class="fw-semibold">{{ $p['code'] ?? '' }}</span>
                                    </div>
                                    <div class="d-flex justify-content-end text-right show_quantity gap-2" style="min-width: 100px">
                                        <b class="{{ $C::CHU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Phân trang dựng đúng khuôn bootstrap-4 mà bản v2 in ra. --}}
                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width" data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ================= Hộp xoá phiếu ================= --}}
    <div class="modal" id="deleteItem">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.delete') }} ?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_red delete-value">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Hộp lập / sửa phiếu ================= --}}
    <div class="modal" id="modalCreate" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-auto" style="min-width: 70%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.warehouse_adjustment_document') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body" style="padding-top: 0!important" id="content_create">
                    <div class="row">
                        <div class="col-12">
                            <div class="py-3 px-2 pt-2 payment-info">
                                {{-- Hàng nút nằm TRÊN cùng, canh phải — đúng bản v2. --}}
                                <div class="card-header border-bottom mb-3 pb-2">
                                    <div class="d-flex justify-content-end">
                                        <div class="button-group d-flex my-auto">
                                            <button type="button" class="btn btn-secondary d-inline-block save-order"
                                                data-status="draft">{{ __('message.status-temporary') }}</button>
                                            <button type="button" class="btn btn-warning d-inline-block show-popup-confirm-send-approval mx-2"
                                                style="color: white;">{{ __('message.submit_for_approval') }}</button>
                                            <button type="button" class="btn btn-success d-inline-block show-popup-confirm-approval">
                                                {{ __('message.approve') }}</button>
                                            <button type="button" class="btn btn-danger d-inline-block show-popup-confirm-reject ms-2 d-none">
                                                {{ __('message.reject') }}</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <div class="row mb-2">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.document_number') }}</label>
                                            <input type="text" name="code" class="form-control"
                                                placeholder="{{ __('message.auto_increment_code') }}" disabled>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.creation-date') }}</label>
                                            <input type="text" name="created_date" class="form-control" disabled>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.created_po_by') }}</label>
                                            <input type="text" name="created_by_name" class="form-control" disabled
                                                value="{{ session('api.user.full_name') ?? '' }}">
                                        </div>
                                    </div>

                                    <div class="row mb-2">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.receipt_status') }}</label>
                                            <input type="text" name="adjustment_status" class="form-control"
                                                style="font-weight: bold; color: green !important;"
                                                value="{{ __('message.create_new') }}" disabled>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.warehouse_status') }}</label>
                                            <input type="text" name="warehouse_status" class="form-control" disabled>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.note') }}</label>
                                            <div class="box-textarea-cus">
                                                <textarea class="form-control note p-2" maxlength="200"
                                                    style="height: 70px" rows="3"></textarea>
                                                <small id="count-textarea" class="char-counter char-counter2"></small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row mb-2 align-items-end">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.product_group') }}</label>
                                            <select class="form-control select-categories">
                                                <option value="">{{ __('message.select-menu-group') }}</option>
                                                @foreach ($nhomHang ?? [] as $nh)
                                                    <option value="{{ $nh['id'] }}">{{ $nh['name'] ?? '' }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-4 mt-2 mt-md-0">
                                            <button type="button" class="btn btn-primary add-category w-100" disabled>
                                                {{ __('message.add_all_products_from_group') }}
                                            </button>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mt-1">{{ __('message.goods') }}</label>
                                            <select class="form-control select-menus form-select" multiple></select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 d-flex justify-content-end mb-2">
                                <button type="button" class="btn btn-primary default-to-zero" disabled>
                                    <i class="fa-solid fa-rotate-right me-2"></i>{{ __('message.default_to_zero') }}
                                </button>
                            </div>

                            <div class="col-12">
                                <div class="info-product border-top">
                                    <div class="content_midd border-0">
                                        <div class="table-responsive" style="max-height: calc(100vh - 420px);">
                                            <table class="table-product">
                                                <tbody>
                                                    <tr>
                                                        <th class="text-center">{{ __('message.stt') }}</th>
                                                        <th class="text-left">{{ __('message.menu-code') }}</th>
                                                        <th class="text-left">{{ __('message.menu-name') }}</th>
                                                        <th class="text-left">{{ __('message.unit') }}</th>
                                                        <th class="text-center">{{ __('message.batch_number') }} <span class="required">*</span></th>
                                                        <th class="text-right">{{ __('message.expiry_date') }}</th>
                                                        <th class="text-center">{{ __('message.stock_quantity') }}</th>
                                                        <th class="text-center">{{ __('message.adjustment') }}</th>
                                                        <th class="text-center">{{ __('message.stock_after_adjustment') }}</th>
                                                        <th class="text-right">{{ __('message.status') }} {{ Str::lower(__('message.inventory')) }}</th>
                                                        <th class="text-center">{{ __('message.attachment') }}</th>
                                                        <th class="text-center not-export">{{ __('message.action') }}</th>
                                                    </tr>
                                                </tbody>
                                                <tbody class="list-menu"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Hộp cân đối hàng âm ================= --}}
    <div class="modal" id="modalBalanceQuantity">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="min-width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.negative_orders_pending_balance') }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mx-1">
                        <span class="title_search">{{ __('message.negative_stock_balance_description') }}</span>
                    </div>
                    <div class="mt-3" style="overflow: auto; height: 400px">
                        <table class="table-balance">
                            <thead>
                                <tr>
                                    <th class="text-center not-export">
                                        <input class="form-check-input item-select-all-balance" type="checkbox" checked>
                                    </th>
                                    <th class="text-left">{{ __('message.menu-code') }}</th>
                                    <th class="text-left">{{ __('message.menu-name') }}</th>
                                    <th class="text-center">{{ __('message.unit') }}</th>
                                    <th class="text-center">{{ __('message.batch_number') }}</th>
                                    <th class="text-center">{{ __('message.stock_quantity') }}</th>
                                    <th class="text-center">{{ __('message.adjustment') }}</th>
                                </tr>
                            </thead>
                            <tbody class="list-balance"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="btn btn-success btn_create_balance" style="color: white;">
                        {{ __('message.create_adjustment_note') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Hộp xác nhận gửi duyệt ================= --}}
    <div class="modal" id="modalConfirmSendApproval">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 20%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.confirm_send_request') }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center" style="color: red; text-align: center">
                        <p>{{ __('message.confirm_approve_request') }}</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="btn btn-warning d-inline-block save-order" style="color: white;"
                        data-status="pending">{{ __('message.submit_for_approval') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Hộp xác nhận duyệt ================= --}}
    <div class="modal" id="modalConfirmApproval">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 20%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.approve') }} ?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center" style="color: red; text-align: center">
                        <p>{{ __('message.confirm_approve_request') }}</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="btn btn-success d-inline-block save-order"
                        data-status="approved">{{ __('message.approve') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ================= Hộp lý do từ chối ================= --}}
    <div class="modal" id="modalReject">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 30%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.reject_reason') }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <textarea class="form-control reject_reason p-2" rows="3" maxlength="500"
                        placeholder="{{ __('message.enter_reject_reason') }}"></textarea>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="btn btn-danger btn-reject-confirm">{{ __('message.reject') }}</button>
                </div>
            </div>
        </div>
    </div>

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
                </td>
                <td class="text-center stock_after_adjust"></td>
                <td class="text-left inventory_status"></td>
                <td class="text-center attached">
                    <div class="attachment-wrap">
                        <label class="attachment-label">
                            <i class="fa-solid fa-paperclip"></i>
                            <input type="file" class="form-control attachment-input" accept="image/*" hidden>
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
        const CSRF = '{{ csrf_token() }}';
        const URL_BASE = @json(url('/admin/inventory-adjustments'));
        const URL_STORE = @json(route('admin.dieu-chinh-ton-kho.store'));
        const URL_MAT_HANG = @json(route('admin.dieu-chinh-ton-kho.matHang'));
        const URL_NHOM_HANG = @json(route('admin.dieu-chinh-ton-kho.matHangTheoNhom'));
        const URL_HANG_AM = @json(route('admin.dieu-chinh-ton-kho.hangAm'));
        const URL_ANH = @json(route('admin.dieu-chinh-ton-kho.anh'));
        const LO_MAC_DINH = @json(\App\Http\Controllers\DieuChinhTonKhoController::LO_KHONG_XAC_DINH);
        const NHAN_DONG = @json(\App\Http\Controllers\DieuChinhTonKhoController::TRANG_THAI_DONG);
        const NHAN_TRANG_THAI = @json(\App\Http\Controllers\DieuChinhTonKhoController::TRANG_THAI);
        const NHAN_KHO = @json(\App\Http\Controllers\DieuChinhTonKhoController::TRANG_THAI_KHO);

        // Số lượng cho tới 3 số lẻ, bỏ đuôi ".000" thừa — đúng cách v2 hiển thị.
        function soLuong(v) {
            const n = Math.round((Number(v) || 0) * 1000) / 1000;
            return n.toLocaleString('vi-VN', { maximumFractionDigits: 3 });
        }

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        function postForm(action, method, fields) {
            const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
            const them = (n, v) => $f.append($('<input>', { type: 'hidden', name: n, value: v == null ? '' : v }));
            them('_token', CSRF);
            if (method && method !== 'POST') them('_method', method);
            them('return', location.pathname + location.search);
            $.each(fields || {}, (k, v) => them(k, v));
            $('body').append($f);
            $f.trigger('submit');
        }

        // ---------- Bộ lọc: đổi ô nào là lọc lại ngay, gõ thì chờ 400ms ----------
        const $form = $('#search-form');
        $form.on('change', 'select, input[type="checkbox"]', () => $form.trigger('submit'));

        // Hai ô ngày: lịch một ngày, khuôn DD-MM-YYYY — cùng bộ daterangepicker của v2.
        $('#from_date, #to_date').each(function () {
            $(this).daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                autoUpdateInput: false,
                autoApply: true,
                locale: V2.lichVN(),
            }, function (start) {
                $(this.element).val(start.format('DD-MM-YYYY'));
                $form.trigger('submit');
            });
        });
        let timerTim = null;
        $form.on('input', '#code', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(() => $form.trigger('submit'), 400);
        });
        $('#select_employee').select2({ placeholder: '{{ __('message.all') }}', width: '100%' });

        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        // =================================================================
        //  Lưới hàng của phiếu
        // =================================================================
        let DONG = [];   // dòng hàng đang gõ
        let SUA_ID = 0;  // 0 = đang lập mới

        const timDong = ($tr) => DONG.find((d) => d.key === Number($tr.data('key')));

        /** Số điều chỉnh: cho số âm, tối đa 3 số lẻ, tồn sau chỉnh không được âm. */
        function datDieuChinh(d, v) {
            let n = Number(v);
            if (!Number.isFinite(n)) n = 0;
            n = Math.round(n * 1000) / 1000;
            const ton = Number(d.quantity) || 0;
            if (ton + n < 0) n = -ton;
            d.adjust_quantity = n;
        }

        function veLuoi() {
            const $body = $('#modalCreate .list-menu').empty();

            DONG.forEach((d, i) => {
                const $tr = $('table.d-none tr.menu-item').clone();
                $tr.attr('data-key', d.key).attr('data-id', d.variant_id);

                $tr.find('.index').text(i + 1);
                $tr.find('.code').text(d.sku || '');
                $tr.find('.name').text([d.product_name, d.variant_name].filter(Boolean).join(' · '));

                // Đơn vị tính: hàng có nhiều đơn vị thì thành ô chọn, một đơn vị thì để chữ.
                if ((d.units || []).length > 1) {
                    const $sl = $('<select class="form-control form-select unit-select" style="min-width:110px">');
                    d.units.forEach((u) => $sl.append($('<option>', {
                        value: u.unit_id, text: u.name || 'Đơn vị chính', selected: Number(u.unit_id) === Number(d.unit_id),
                    })));
                    $tr.find('.unit').empty().append($sl);
                } else {
                    $tr.find('.unit').text(d.base_unit_name || '');
                }

                // Ô số lô: các lô đang có + "Không xác định" + "Lô mới…" như v2.
                const $lo = $('<select class="form-control form-select lot_number_select" style="min-width:150px">');
                const dsLo = [LO_MAC_DINH].concat((d.lots || []).map((l) => l.lot_number).filter(Boolean));
                if (d.lot_number && dsLo.indexOf(d.lot_number) === -1) dsLo.push(d.lot_number);
                [...new Set(dsLo)].forEach((l) => $lo.append($('<option>', {
                    value: l, text: l === LO_MAC_DINH ? '{{ __('message.unknown') }}' : l, selected: l === d.lot_number,
                })));
                $lo.append($('<option>', { value: 'new', text: '{{ __('message.enter_new_batch') }}' }));
                $tr.find('.lot_number').empty().append($lo);

                $tr.find('.expire_date').text(d.lot_number === LO_MAC_DINH ? '' : (d.expire_date || ''));
                $tr.find('.quantity').text(soLuong(d.quantity));
                $tr.find('.quantity-adjust').val(soLuong(d.adjust_quantity));

                const sau = (Number(d.quantity) || 0) + (Number(d.adjust_quantity) || 0);
                $tr.find('.stock_after_adjust').text(soLuong(sau));
                $tr.find('.inventory_status').text(NHAN_DONG[d.inventory_status] || '');

                if (d.attachment) {
                    $tr.find('.attachment-wrap').html(
                        '<span class="attachment-filename"><a href="' + d.attachment + '" target="_blank">Ảnh</a>'
                        + '<i class="fa-solid fa-xmark attachment-remove"></i></span>');
                }

                $body.append($tr);
            });

            $('#modalCreate .default-to-zero').prop('disabled', DONG.length === 0);
            $('#modalCreate .show-popup-confirm-send-approval, #modalCreate .show-popup-confirm-approval')
                .prop('disabled', DONG.length === 0);
        }

        /** Thêm một dòng. Cùng mặt hàng + cùng lô là một dòng, không nhân đôi. */
        function themDong(mh, cu, imLang) {
            if (!mh) return;
            const donVi = (mh.units && mh.units.length) ? mh.units
                : [{ unit_id: mh.base_unit_id || 0, name: mh.base_unit_name || '', ratio: 1 }];
            const lo = cu ? (cu.lot_number || LO_MAC_DINH) : LO_MAC_DINH;

            if (!cu && DONG.some((d) => d.variant_id === mh.variant_id && d.lot_number === lo)) {
                if (!imLang) toastr.warning('{{ __('message.duplicate_batch_for_product') }}');
                return;
            }

            DONG.push({
                key: DONG.length ? Math.max(...DONG.map((x) => x.key)) + 1 : 1,
                variant_id: mh.variant_id,
                sku: mh.sku || '',
                product_name: mh.product_name || '',
                variant_name: mh.variant_name || '',
                base_unit_name: mh.base_unit_name || '',
                units: donVi,
                unit_id: cu ? Number(cu.unit_id || 0) : Number(donVi[0].unit_id || 0),
                lots: mh.lots || [],
                lot_number: lo,
                expire_date: cu ? (cu.expire_date || '') : '',
                quantity: cu ? Number(cu.quantity || 0) : Number(mh.stock || 0),
                adjust_quantity: cu ? Number(cu.adjust_quantity || 0) : 0,
                inventory_status: cu ? (cu.inventory_status || '') : '',
                attachment: cu ? (cu.attachment || '') : '',
            });
        }

        // ---------- Ô chọn hàng hóa (select2 gọi API tìm) ----------
        function napSelectMenus() {
            $('#modalCreate .select-menus').select2({
                dropdownParent: $('#modalCreate'),
                placeholder: '{{ __('message.goods') }}',
                width: '100%',
                minimumInputLength: 0,
                ajax: {
                    url: URL_MAT_HANG,
                    dataType: 'json',
                    delay: 300,
                    data: (params) => ({
                        keyword: params.term || '',
                        category_id: $('#modalCreate .select-categories').val() || 0,
                    }),
                    processResults: (res) => ({
                        results: (res.data || []).map((r) => ({
                            id: r.variant_id,
                            text: [r.product_name, r.variant_name].filter(Boolean).join(' · ')
                                + ' (' + (r.sku || '') + ')',
                            mh: r,
                        })),
                    }),
                },
            });
        }

        $(document).on('select2:select', '#modalCreate .select-menus', function (e) {
            themDong(e.params.data.mh);
            veLuoi();
            $(this).val(null).trigger('change');
        });

        $(document).on('change', '#modalCreate .select-categories', function () {
            $('#modalCreate .add-category').prop('disabled', !$(this).val());
        });

        /** Đổ cả một nhóm hàng vào lưới — nút của v2. */
        $(document).on('click', '#modalCreate .add-category', function () {
            const nhom = $('#modalCreate .select-categories').val();
            if (!nhom) return;
            const $nut = $(this).prop('disabled', true);

            $.getJSON(URL_NHOM_HANG, { category_id: nhom })
                .done((res) => {
                    const ds = res.data || [];
                    if (!ds.length) { toastr.warning('{{ __('message.import-none') }}'); return; }
                    ds.forEach((mh) => themDong(mh, null, true));
                    veLuoi();
                })
                .fail(() => toastr.error('Không đọc được nhóm hàng.'))
                .always(() => $nut.prop('disabled', false));
        });

        // ---------- Ô số lô ----------
        $(document).on('change', '#modalCreate .lot_number_select', function () {
            const $tr = $(this).closest('tr');
            const d = timDong($tr);
            if (!d) return;

            if (this.value === 'new') {
                // Gõ lô mới ngay tại chỗ: ô lô + ô hạn dùng + hai nút Áp dụng / Đóng.
                $(this).hide();
                $tr.find('.lot_number').append(
                    '<div class="lot-new-box">'
                    + '<input type="text" class="form-control lot-input" placeholder="{{ __('message.enter_new_batch') }}">'
                    + '<input type="date" class="form-control expire-input">'
                    + '</div>'
                    + '<div class="d-flex justify-content-center gap-1 mt-1">'
                    + '<button type="button" class="btn btn-outline-success btn-sm apply-lot-btn">{{ __('message.apply') }}</button>'
                    + '<button type="button" class="btn btn-outline-danger btn-sm close-lot-btn">{{ __('message.close') }}</button>'
                    + '</div>');
                return;
            }

            const lo = (d.lots || []).find((l) => l.lot_number === this.value);
            d.lot_number = this.value;
            d.expire_date = lo ? (lo.expire_date || '') : '';
            veLuoi();
        });

        $(document).on('click', '#modalCreate .apply-lot-btn', function () {
            const $tr = $(this).closest('tr');
            const d = timDong($tr);
            const lo = $tr.find('.lot-input').val().trim();
            const han = $tr.find('.expire-input').val();

            if (!lo) { alert('{{ __('message.please_enter_batch_number') }}'); return; }
            if (DONG.some((x) => x !== d && x.variant_id === d.variant_id && x.lot_number === lo)) {
                alert('{{ __('message.batch_already_exists') }}');
                return;
            }
            d.lot_number = lo;
            d.expire_date = han || '';
            veLuoi();
        });

        $(document).on('click', '#modalCreate .close-lot-btn', function () {
            veLuoi();
        });

        // ---------- Ô điều chỉnh: nút − / + và gõ tay ----------
        $(document).on('click', '#modalCreate .btn-minus, #modalCreate .btn-plus', function () {
            const $tr = $(this).closest('tr');
            const d = timDong($tr);
            if (!d) return;
            datDieuChinh(d, (Number(d.adjust_quantity) || 0) + ($(this).hasClass('btn-plus') ? 1 : -1));
            veLuoi();
        });

        $(document).on('input', '#modalCreate .quantity-adjust', function () {
            const $tr = $(this).closest('tr');
            const d = timDong($tr);
            if (!d) return;

            // Giữ nguyên chuỗi đang gõ ("-", "1."), chỉ tính lại cột tồn sau chỉnh.
            let raw = this.value.replace(/[^\d.-]/g, '');
            if (raw.indexOf('-') > 0) raw = raw.replace(/-/g, '');
            const phan = raw.split('.');
            if (phan.length > 2) raw = phan[0] + '.' + phan.slice(1).join('');
            const cham = raw.indexOf('.');
            if (cham !== -1 && raw.length - cham - 1 > 3) raw = raw.slice(0, cham + 4);
            if (this.value !== raw) this.value = raw;

            datDieuChinh(d, ['', '-', '.', '-.'].includes(raw) ? 0 : parseFloat(raw));
            $tr.find('.stock_after_adjust').text(soLuong((Number(d.quantity) || 0) + d.adjust_quantity));
        });

        $(document).on('change', '#modalCreate .unit-select', function () {
            const d = timDong($(this).closest('tr'));
            if (d) d.unit_id = Number(this.value) || 0;
        });

        $(document).on('click', '#modalCreate .remove-menu', function () {
            const d = timDong($(this).closest('tr'));
            DONG = DONG.filter((x) => x !== d);
            veLuoi();
        });

        $(document).on('click', '#modalCreate .default-to-zero-item', function () {
            const d = timDong($(this).closest('tr'));
            if (d) { d.adjust_quantity = 0; veLuoi(); }
        });

        $(document).on('click', '#modalCreate .default-to-zero', function () {
            DONG.forEach((d) => { d.adjust_quantity = 0; });
            veLuoi();
        });

        // ---------- Ảnh chứng từ từng dòng ----------
        $(document).on('change', '#modalCreate .attachment-input', function () {
            const $tr = $(this).closest('tr');
            const d = timDong($tr);
            if (!d || !this.files[0]) return;

            const fd = new FormData();
            fd.append('anh', this.files[0]);
            fd.append('_token', CSRF);

            $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((res) => { d.attachment = res.url || ''; veLuoi(); })
                .fail((x) => toastr.error((x.responseJSON || {}).message || 'Không tải được ảnh lên.'));
        });

        $(document).on('click', '#modalCreate .attachment-remove', function () {
            const d = timDong($(this).closest('tr'));
            if (d) { d.attachment = ''; veLuoi(); }
        });

        // ---------- Đếm ký tự ghi chú ----------
        $(document).on('input', '#modalCreate .note', function () {
            $(this).siblings('.char-counter2').text(this.value.length + '/' + $(this).attr('maxlength'));
        });

        // =================================================================
        //  Mở hộp lập / sửa phiếu
        // =================================================================
        function moPhieu(p) {
            const $m = $('#modalCreate');
            SUA_ID = p ? Number(p.id || 0) : 0;
            DONG = [];

            $m.find('input[name="code"]').val(p ? (p.code || '') : '');
            $m.find('input[name="created_date"]').val(
                p && p.created_at ? moment(p.created_at).format('DD-MM-YYYY') : moment().format('DD-MM-YYYY'));
            if (p && p.created_by_name) $m.find('input[name="created_by_name"]').val(p.created_by_name);
            $m.find('input[name="adjustment_status"]')
                .val(p ? (NHAN_TRANG_THAI[p.status] || '') : '{{ __('message.create_new') }}')
                .css('color', p && p.status === 'rejected' ? 'red' : (p && p.status === 'approved' ? 'blue' : 'green'));
            $m.find('input[name="warehouse_status"]').val(p ? (NHAN_KHO[p.warehouse_status] || '') : '');
            $m.find('.note').val(p ? (p.note || '') : '').trigger('input');

            // Phiếu đã duyệt / bị từ chối chỉ để xem; phiếu chờ duyệt thì hiện nút Duyệt và Từ chối.
            const nhap = !p || p.status === 'draft';
            const cho = p && p.status === 'pending';
            $m.find('.save-order[data-status="draft"], .show-popup-confirm-send-approval').toggleClass('d-none', !nhap);
            $m.find('.show-popup-confirm-approval').toggleClass('d-none', !(nhap || cho));
            $m.find('.show-popup-confirm-reject').toggleClass('d-none', !cho);
            $m.find('.select-categories, .select-menus, .add-category, .default-to-zero, .note')
                .prop('disabled', !nhap);

            ((p && p.items) || []).forEach((it) => themDong({
                variant_id: Number(it.variant_id || 0),
                sku: it.sku || '',
                product_name: it.product_name || '',
                variant_name: it.variant_name || '',
                base_unit_name: it.base_unit_name || it.unit_name || '',
                units: it.units || [{ unit_id: it.unit_id || 0, name: it.unit_name || '' }],
                lots: it.lots || [],
            }, it, true));

            veLuoi();
            if (!nhap) $m.find('.list-menu :input').prop('disabled', true);
            $m.modal('show');
        }

        $(document).on('click', '.btn_create', function () {
            moPhieu(null);
            napSelectMenus();
        });

        $(document).on('click', '.edit-item', function () {
            const id = $(this).data('id') || $(this).closest('.item').data('id');
            $.getJSON(URL_BASE + '/' + id)
                .done((res) => { moPhieu(res.data); napSelectMenus(); })
                .fail((x) => toastr.error((x.responseJSON || {}).message || 'Không đọc được phiếu.'));
        });

        // ---------- Lưu ----------
        /** Soát lưới trước khi gửi: thiếu lô, trùng lô, hay chỉnh 0 hết là chặn. */
        function soatLuoi() {
            if (!DONG.length) return '{{ __('message.product_list_required') }}';

            const daGap = new Set();
            for (const d of DONG) {
                const lo = (d.lot_number || '').trim();
                if (!lo) return '{{ __('message.please_enter_complete_lot_number') }}';
                const khoa = d.variant_id + '|' + lo;
                if (daGap.has(khoa)) return '{{ __('message.duplicate_batch_for_product') }}';
                daGap.add(khoa);
            }
            if (DONG.every((d) => (Number(d.adjust_quantity) || 0) === 0)) {
                return 'Mọi dòng đều đang điều chỉnh 0 — chưa có gì để lưu.';
            }
            return '';
        }

        $(document).on('click', '.save-order', function () {
            const status = $(this).data('status');
            const loi = soatLuoi();
            if (loi) { toastr.error(loi); return; }

            const items = DONG.map((d) => ({
                variant_id: d.variant_id,
                unit_id: d.unit_id,
                lot_number: (d.lot_number || '').trim() || LO_MAC_DINH,
                expire_date: d.expire_date || '',
                quantity: d.quantity,
                adjust_quantity: d.adjust_quantity,
                attachment: d.attachment || '',
            }));

            postForm(SUA_ID ? URL_BASE + '/' + SUA_ID : URL_STORE, SUA_ID ? 'PUT' : 'POST', {
                type: 'adjust',
                status,
                note: $('#modalCreate .note').val() || '',
                items: JSON.stringify(items),
            });
        });

        $(document).on('click', '.show-popup-confirm-send-approval', () => $('#modalConfirmSendApproval').modal('show'));
        $(document).on('click', '.show-popup-confirm-approval', function () {
            // Phiếu đang chờ duyệt thì duyệt thẳng, không gửi lại cả lưới hàng.
            if (SUA_ID && $('#modalCreate input[name="adjustment_status"]').val() === NHAN_TRANG_THAI.pending) {
                postForm(URL_BASE + '/' + SUA_ID + '/approve', 'POST', {});
                return;
            }
            $('#modalConfirmApproval').modal('show');
        });
        $(document).on('click', '.show-popup-confirm-reject', () => $('#modalReject').modal('show'));

        $(document).on('click', '.btn-reject-confirm', function () {
            const lyDo = $('#modalReject .reject_reason').val().trim();
            if (!lyDo) { toastr.error('{{ __('message.enter_reject_reason') }}'); return; }
            postForm(URL_BASE + '/' + SUA_ID + '/reject', 'POST', { reject_reason: lyDo });
        });

        // ---------- Xoá phiếu ----------
        $(document).on('click', '.delete-item', function () {
            $('#deleteValue').val($(this).closest('.item').data('id'));
            $('#deleteItem').modal('show');
        });
        $(document).on('click', '.delete-value', function () {
            postForm(URL_BASE + '/' + $('#deleteValue').val(), 'DELETE', {});
        });

        // =================================================================
        //  Cân đối hàng âm
        // =================================================================
        let HANG_AM = [];

        $(document).on('click', '.btn_balance_quantity', function () {
            $.getJSON(URL_HANG_AM)
                .done((res) => {
                    HANG_AM = res.data || [];
                    if (!HANG_AM.length) {
                        toastr.warning(res.message || '{{ __('message.no_negative_stock') }}');
                        return;
                    }
                    veHangAm();
                    $('#modalBalanceQuantity').modal('show');
                })
                .fail((x) => toastr.warning(((x.responseJSON || {}).message) || '{{ __('message.no_negative_stock') }}'));
        });

        function veHangAm() {
            $('#modalBalanceQuantity .list-balance').html(HANG_AM.map((r, i) => `
                <tr class="item-balance" data-i="${i}">
                    <td class="text-center"><input class="form-check-input item-select-balance" type="checkbox" checked></td>
                    <td class="text-left">${r.sku || '-'}</td>
                    <td class="text-left">${[r.product_name, r.variant_name].filter(Boolean).join(' · ')}</td>
                    <td class="text-center">${r.unit_name || r.base_unit_name || '-'}</td>
                    <td class="text-center">${r.lot_number || LO_MAC_DINH}</td>
                    <td class="text-center">${soLuong(r.quantity)}</td>
                    <td class="text-center"><span class="text-danger">{{ __('message.adjust_to_zero') }}</span></td>
                </tr>`).join(''));
        }

        $(document).on('change', '.item-select-all-balance', function () {
            $('.item-select-balance').prop('checked', this.checked);
        });

        /** Phiếu cân đối luôn dừng ở lưu tạm — người dùng soát lại rồi mới gửi duyệt. */
        $(document).on('click', '.btn_create_balance', function () {
            const items = [];
            $('#modalBalanceQuantity .item-balance').each(function () {
                if (!$(this).find('.item-select-balance').is(':checked')) return;
                const r = HANG_AM[Number($(this).data('i'))];
                if (!r) return;
                items.push({
                    variant_id: Number(r.variant_id || 0),
                    unit_id: Number(r.unit_id || r.base_unit_id || 0),
                    lot_number: r.lot_number || LO_MAC_DINH,
                    expire_date: r.expire_date || '',
                    quantity: Number(r.quantity || 0),
                    adjust_quantity: -Number(r.quantity || 0),
                    attachment: '',
                });
            });

            if (!items.length) { toastr.error('{{ __('message.product_list_required') }}'); return; }
            postForm(URL_STORE, 'POST', {
                type: 'balance',
                status: 'draft',
                note: '{{ __('message.balance_negative_stock') }}',
                items: JSON.stringify(items),
            });
        });
    </script>
@endpush
