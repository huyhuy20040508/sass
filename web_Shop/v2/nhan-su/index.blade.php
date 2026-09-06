{{-- Màn Nhân sự dựng theo khuôn v2 (hrm/employee/index + list).
     Dữ liệu do NhanSuController đẩy sang: $list, $filters, $meta, $chiNhanh, $quyen. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\NhanSuController::TITLE_PAGE)

@push('styles')
    <style>
        #DetailEmployee .modal-dialog { min-width: 70%; }
        #CreateEmployee .modal-body,
        #DetailEmployee .modal-body { padding: 0 !important; padding-top: 5px !important; }
        #CreateEmployee .tab-content { border-bottom: 0 !important; border-left: 0; border-right: 0; }
        .btn_top_content > * { margin-left: 8px; }
        .btn_top_content .btn-export { cursor: pointer; }

        /* 13 cột chia % chỉ như tỉ lệ gợi ý: không ép fixed, không min-width, trang không cuộn ngang.
           ☐ 3 · STT 4 · Mã 8 · Họ tên 13 · Quyền 11 · Ngày sinh 8 · Giới tính 6 · SĐT 8
           · CCCD 9 · Chi nhánh 10 · Ca 8 · Trạng thái 6 · Hành động 6 = 100 */
        table.table-employee.none_mobile { width: 100%; }
        table.table-employee.none_mobile th { white-space: nowrap; }
        table.table-employee.none_mobile th:first-child { width: 3%; }
        table.table-employee.none_mobile th:nth-child(2) { width: 4%; }
        table.table-employee.none_mobile th.show_code { width: 8%; }
        table.table-employee.none_mobile th.show_name { width: 13%; }
        table.table-employee.none_mobile th.show_type { width: 11%; }
        table.table-employee.none_mobile th.show_birthday { width: 8%; }
        table.table-employee.none_mobile th.show_gender { width: 6%; }
        table.table-employee.none_mobile th.show_phone { width: 8%; }
        table.table-employee.none_mobile th.show_cccd { width: 9%; }
        table.table-employee.none_mobile th.show_branch { width: 10%; }
        table.table-employee.none_mobile th.show_work_shift { width: 8%; }
        table.table-employee.none_mobile th.show_status { width: 6%; }
        table.table-employee.none_mobile th:last-child { width: 6%; }
        table.table-employee.none_mobile td.item-name,
        table.table-employee.none_mobile td.show_branch {
            max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .hoi-body p { margin-bottom: 8px; }

        @media screen and (max-width: 768px) {
            .item1 { display: flex; }
            .item1 label { flex: 0 0 35%; padding: 0; }
            .item1 div { flex: 1; padding: 0; }
            input.switch_customer { min-width: 2.6em !important; }
        }
    </style>
@endpush

@php
    $C = \App\Http\Controllers\NhanSuController::class;
    $hasFilter = collect($filters)->only(['keyword', 'status', 'work_shift', 'gender', 'shop_id'])
        ->contains(fn ($v) => $v !== '' && $v !== null && $v !== [] && $v !== 0);
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $anhMacDinh = asset('v2/images/image_defaul.png');

    // Huy hiệu cửa vào: quản lý xanh lá, thu ngân vàng — cùng màu với bản v2.
    $lopQuyen = ['quan_ly' => 'bg-success', 'thu_ngan' => 'bg-warning text-dark'];
    $nhanCa = ['sang' => __('message.bright'), 'chieu' => __('message.afternoon'), 'ca_ngay' => __('message.all-day')];
    $ngay = fn ($iso) => filled($iso) ? \Illuminate\Support\Carbon::parse($iso)->format('d-m-Y') : '';
@endphp

@section('content')
    {{-- Dãy nút mở bộ lọc, chỉ hiện trên điện thoại. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterSearch">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterBranch">
                <p class="open-modal-label">{{ __('message.branch') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-store"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterWorkShift">
                <p class="open-modal-label">{{ __('message.work-shift') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-clock"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterGender">
                <p class="open-modal-label">{{ __('message.gender') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-venus-mars"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-hrm-employee-page">
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không có nút "Lọc": gõ hay đổi ô nào là lọc lại ngay. --}}
                        <form action="{{ route('admin.nhan-su.index') }}" method="GET" id="search-form">
                            <div id="filterSearch" class="mb-3">
                                <div class="inner-modal-in-mobile input-group">
                                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="form-control search"
                                        autocomplete="off" placeholder="{{ __('message.enter-name-or-code') }}">
                                    <button class="btn seach-item" type="button"><i class="fa-solid fa-magnifying-glass"></i></button>
                                </div>
                            </div>

                            <div id="filterBranch" class="mb-3">
                                <div class="col-md-12 inner-modal-in-mobile">
                                    <label class="form-label title_search">{{ __('message.branch') }}</label>
                                    <select name="shop_id[]" class="form-control select2 branchEmployee" style="width:100%" multiple>
                                        @foreach ($chiNhanh as $cn)
                                            <option value="{{ $cn['id'] }}" {{ in_array((int) $cn['id'], $filters['shop_id'], true) ? 'selected' : '' }}>
                                                {{ $cn['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div id="filterWorkShift" class="mb-3">
                                <div class="col-md-12 inner-modal-in-mobile">
                                    <label class="form-label title_search">{{ __('message.work-shift') }}</label>
                                    @foreach ($nhanCa as $ma => $ten)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input" type="checkbox" name="work_shift[]" value="{{ $ma }}"
                                                id="work_shift_{{ $ma }}" {{ in_array($ma, $filters['work_shift'], true) ? 'checked' : '' }}>
                                            <label class="ms-2 form-check-label" for="work_shift_{{ $ma }}">{{ $ten }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div id="filterGender" class="mb-3">
                                <div class="col-md-12 inner-modal-in-mobile">
                                    <label class="form-label title_search">{{ __('message.gender') }}</label>
                                    @foreach (['nam' => __('message.male'), 'nu' => __('message.female')] as $ma => $ten)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input" name="gender[]" type="checkbox" value="{{ $ma }}"
                                                id="gender_{{ $ma }}" {{ in_array($ma, $filters['gender'], true) ? 'checked' : '' }}>
                                            <label class="ms-2 form-check-label" for="gender_{{ $ma }}">{{ $ten }}</label>
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

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="content_midd">
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">{{ __('message.personnel-list') }}</h1>
                    <div class="justify-content-end">
                        <div class="btn_top_content d-flex align-items-center">
                            <a type="button" class="bt btn_green add-item">{{ __('message.add') }}</a>
                            <a type="button" id="delete-mutil-data" class="bt btn_red mass-delete">{{ __('message.delete') }}</a>
                            <a class="btn btn-sm d-flex align-items-center btn-export">
                                <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export_report') }}
                            </a>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="item-modify table-employee none_mobile">
                            <tr>
                                <th class="text-center not-export"><input id="select-all-data" class="form-check-input item-select-all" type="checkbox"></th>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_code">{{ __('message.personnel-code') }}</th>
                                <th class="text-left show_name">{{ __('message.full-name') }}</th>
                                <th class="text-left show_type">{{ __('message.hr_permission') }}</th>
                                <th class="text-right show_birthday">{{ __('message.date-of-birth') }}</th>
                                <th class="text-left show_gender">{{ __('message.gender') }}</th>
                                <th class="text-right show_phone">{{ __('message.phone') }}</th>
                                <th class="text-right show_cccd">CCCD</th>
                                <th class="text-left show_branch">{{ __('message.branch') }}</th>
                                <th class="text-left show_work_shift">{{ __('message.work-shift') }}</th>
                                <th class="text-center show_status">{{ __('message.status') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>
                            <tbody id="load-data">
                                @forelse ($list as $i => $nv)
                                    @php
                                        $id = (int) ($nv['id'] ?? 0);
                                        $tenDangNhap = (string) ($nv['username'] ?? '');
                                        $tkKhoa = $tenDangNhap !== '' && ($nv['user_status'] ?? 'active') !== 'active';
                                        $ca = collect(explode(',', (string) ($nv['work_shift'] ?? '')))->filter()
                                            ->map(fn ($c) => $nhanCa[$c] ?? $c)->implode(', ');
                                    @endphp
                                    <tr class="item" data-id="{{ $id }}">
                                        <td class="text-center not-export"><input class="form-check-input item-select" type="checkbox" value="{{ $id }}"></td>
                                        <td class="text-center">{{ $stt + $i + 1 }}</td>
                                        <td class="text-left item-code show_code">{{ $nv['code'] ?? '' }}</td>
                                        <td class="text-left item-name show_name" title="{{ $nv['full_name'] ?? '' }}">{{ $nv['full_name'] ?? '' }}</td>
                                        <td class="text-left show_type">
                                            @foreach ((array) ($nv['quyen'] ?? []) as $cua)
                                                <span class="badge {{ $lopQuyen[$cua] ?? 'bg-secondary' }} me-1 is-cua-{{ $cua }}">{{ $C::NHAN_CUA[$cua] ?? $cua }}</span>
                                            @endforeach
                                            @if ($tkKhoa)
                                                <span class="badge bg-secondary me-1">Tài khoản đã khoá</span>
                                            @endif
                                        </td>
                                        <td class="text-right show_birthday">{{ $ngay($nv['birth_date'] ?? null) }}</td>
                                        <td class="text-left show_gender">{{ $C::GIOI_TINH[$nv['gender'] ?? ''] ?? '' }}</td>
                                        <td class="text-right show_phone">{{ $nv['phone'] ?? '' }}</td>
                                        <td class="text-right show_cccd">{{ $nv['id_number'] ?? '' }}</td>
                                        @php $tenCN = implode(', ', (array) ($nv['shop_names'] ?? [])) ?: ($nv['shop_name'] ?? ''); @endphp
                                        <td class="text-left show_branch" title="{{ $tenCN }}">{{ $tenCN }}</td>
                                        <td class="text-left show_work_shift">{{ $ca }}</td>
                                        <td class="text-center show_status">
                                            <input type="checkbox" class="switch_customer item-status" data-id="{{ $id }}"
                                                {{ ($nv['status'] ?? '') === $C::DANG_LAM ? 'checked' : '' }}>
                                        </td>
                                        <td class="text-center action not-export">
                                            <a class="detail-item" type="button" title="{{ __('message.detail') }}"><i class="fa fa-eye" aria-hidden="true"></i></a>
                                            <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                            <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="13" class="text-center py-4">{{ $hasFilter ? 'Không có nhân viên nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        {{-- Bản thẻ cho điện thoại. --}}
                        <div class="table-employee none_desktop">
                            <div class="p-2 d-flex justify-content-between border">
                                <div class="fw-bold">
                                    <input class="form-check-input item-select-all" type="checkbox" style="margin-top: 2px">
                                    {{ __('message.full-name') }}
                                </div>
                                <div class="fw-bold">{{ __('message.hr_permission') }}</div>
                            </div>
                            @foreach ($list as $nv)
                                <div class="item" data-id="{{ (int) ($nv['id'] ?? 0) }}">
                                    <div class="d-flex gap-1 align-items-start">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ (int) ($nv['id'] ?? 0) }}" style="margin-top: 2px;">
                                        <div>
                                            <div class="fw-semibold">{{ $nv['full_name'] ?? '' }}</div>
                                            <div style="font-size: 14px">{{ $nv['code'] ?? '' }}</div>
                                        </div>
                                    </div>
                                    <div class="d-flex text-right show_quantity gap-1">
                                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                                            @foreach ((array) ($nv['quyen'] ?? []) as $cua)
                                                <span class="badge {{ $lopQuyen[$cua] ?? 'bg-secondary' }}">{{ $C::NHAN_CUA[$cua] ?? $cua }}</span>
                                            @endforeach
                                        </div>
                                        <i class="fa-solid fa-angle-right d-none"></i>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Cả bản ghi của từng dòng, thay cùng bảng mỗi lượt nạp lại. --}}
                    <script type="application/json" id="v2-rows">@json(collect($list)->keyBy('id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)</script>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width" data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ (int) $meta['page_size'] === $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Thêm / Sửa ===================== --}}
    <div class="modal fade" id="CreateEmployee" tabindex="-1" aria-labelledby="CreateEmployeeLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable mx-auto" style="min-width: 60%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add-employee-profile') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-2" id="form-data-employee">
                    <form id="employeeForm" onsubmit="return false;">
                        <ul class="nav nav-tabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#detailEmployee" type="button" role="tab">{{ __('message.detail') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#loginEmployee" type="button" role="tab">{{ __('message.login') }}</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#settingEmployee" type="button" role="tab">{{ __('message.settings') }}</button>
                            </li>
                        </ul>
                        <div class="tab-content p-3 border border-top-0">
                            <div class="tab-pane fade show active" id="detailEmployee" role="tabpanel">
                                <div class="col-md-12">
                                    <div class="row">
                                        <div class="col-md-3 d-flex align-items-start justify-content-center">
                                            <div class="img_st w-100">
                                                <label>{{ __('message.image') }}</label>
                                                <div class="d-flex justify-content-center">
                                                    <div class="pic_add">
                                                        <img id="img-preview" src="{{ $anhMacDinh }}"
                                                            onerror="this.onerror=null; this.src='{{ $anhMacDinh }}';">
                                                    </div>
                                                </div>
                                                <div class="upload_pic">
                                                    {{ __('message.upload') }}
                                                    <input type="file" class="ip_img" accept="image/*">
                                                </div>
                                                <input type="hidden" name="avatar" class="ip_avatar">
                                                <input type="hidden" name="avatar_cu" class="ip_avatar_cu">
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="hidden" name="id" class="id">
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.employee-code') }}</label>
                                                <input type="text" name="code" class="form-control ip_code" id="code" maxlength="30"
                                                    placeholder="{{ __('message.auto-increment-code') }}">
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.full-name') }} <span class="required">*</span></label>
                                                <input type="text" name="full_name" class="form-control" id="name" maxlength="150"
                                                    placeholder="{{ __('message.full-name') }}">
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.date-of-birth') }}</label>
                                                <input type="text" name="birth_date" class="form-control no-future" id="birthday"
                                                    autocomplete="off" placeholder="{{ __('message.date-of-birth') }}">
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.email') }}</label>
                                                <input type="text" class="form-control" name="email" id="email" maxlength="191"
                                                    placeholder="{{ __('message.email') }}">
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.date-employment') }}</label>
                                                <input type="text" name="hired_on" class="form-control" id="date_employment"
                                                    autocomplete="off" placeholder="{{ __('message.date-employment') }}">
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label d-block">{{ __('message.status') }}</label>
                                                <input type="checkbox" value="1" name="status" class="switch_customer status" checked>
                                                {{-- Chỉ hiện khi nhận người cũ làm lại mà tài khoản đang khoá. --}}
                                                <div class="form-check gap-0 mt-2 d-none" id="moTaiKhoanRow">
                                                    <input class="form-check-input" type="checkbox" name="mo_tai_khoan" value="1" id="mo_tai_khoan">
                                                    <label class="ms-2 form-check-label" for="mo_tai_khoan">Mở lại tài khoản đăng nhập</label>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.branch') }} <span class="required">*</span></label>
                                                <select name="shop_ids[]" class="form-control select2 ip_shop_ids" style="width:100%" multiple>
                                                    @foreach ($chiNhanh as $cn)
                                                        <option value="{{ $cn['id'] }}">{{ $cn['name'] }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.gender') }}</label>
                                                <select class="form-select" name="gender">
                                                    <option value="">{{ __('message.gender') }}</option>
                                                    @foreach ($C::GIOI_TINH as $ma => $ten)
                                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.phone') }}</label>
                                                <input type="text" class="form-control" name="phone" id="phone" maxlength="20"
                                                    placeholder="{{ __('message.phone') }}">
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">CCCD</label>
                                                <input type="text" class="form-control" name="id_number" id="cccd" maxlength="20" placeholder="CCCD">
                                            </div>
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">{{ __('message.work-shift') }}</label>
                                                <select class="form-control select2 ip_work_shift" style="width:100%" name="work_shift[]" multiple>
                                                    @foreach ($nhanCa as $ma => $ten)
                                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label d-block">{{ __('message.hr_permission') }}</label>
                                            @foreach ($quyen as $ma => $ten)
                                                <div class="form-check gap-0">
                                                    <input class="form-check-input ip_quyen" type="checkbox" name="quyen[]" value="{{ $ma }}" id="account_type{{ $ma }}">
                                                    <label class="ms-2 form-check-label" for="account_type{{ $ma }}">{{ $ten }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="loginEmployee" role="tabpanel">
                                <p class="taiKhoanDaCo d-none"></p>
                                {{-- Tài khoản đã có: đổi mật khẩu hoặc đặt lại về mặc định, như hộp sửa của v2. --}}
                                <div class="row w-100 matKhauMoiBox d-none">
                                    <div class="col-md-5 col-xl-4 mb-3">
                                        <label class="form-label">{{ __('message.password') }}</label>
                                        <input type="password" class="form-control" name="mat_khau_moi" id="mat_khau_moi" maxlength="72"
                                            autocomplete="new-password" placeholder="{{ __('message.enter_new_password') }}">
                                    </div>
                                    <div class="col-md-5 col-xl-4 mb-3 d-flex align-items-end">
                                        <button type="button" class="bt btn_red reset-password-btn">{{ __('message.reset_password') }}</button>
                                    </div>
                                </div>
                                <div class="form-check gap-0 mb-3 coTaiKhoanRow">
                                    <input class="form-check-input" type="checkbox" name="co_tai_khoan" value="1" id="co_tai_khoan">
                                    <label class="ms-2 form-check-label" for="co_tai_khoan">Cấp tài khoản đăng nhập</label>
                                </div>
                                <div class="row w-100 taiKhoanBox d-none">
                                    <div class="col-md-5 col-xl-4 mb-3">
                                        <label class="form-label">{{ __('message.user-name') }} <span class="required">*</span></label>
                                        <input type="text" name="username" class="form-control" id="username" maxlength="50"
                                            autocomplete="off" placeholder="{{ __('message.user-name') }}">
                                    </div>
                                    <div class="col-md-5 col-xl-4 mb-3">
                                        <label class="form-label">{{ __('message.password') }}</label>
                                        <input type="password" class="form-control w-100" name="password" id="password" maxlength="72"
                                            autocomplete="new-password" placeholder="{{ __('message.password') }}">
                                    </div>
                                </div>
                            </div>

                            <div class="tab-pane fade" id="settingEmployee" role="tabpanel">
                                <div class="mb-3">
                                    <div class="d-flex flex-column flex-md-row align-items-md-center">
                                        <input type="checkbox" value="1" name="allow_access_outside_area_scope" id="allow_access_outside_area_scope"
                                            class="my-1 switch_customer allow_access_outside_area_scope me-3" checked>
                                        <label class="form-label mb-0" for="allow_access_outside_area_scope">{{ __('message.allow-access-outside-area-scope') }}</label>
                                    </div>
                                    <p class="mt-3">{{ __('message.allow-access-outside-area-scope-description') }}</p>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" id="close" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green" id="bt-submit-create">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Chi tiết (chỉ xem) ===================== --}}
    <div class="modal fade" id="DetailEmployee" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.employee-details') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="employeeFormDetail" onsubmit="return false;">
                        <ul class="nav nav-tabs none_mobile" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#detailEmployeeView" type="button" role="tab">{{ __('message.detail') }}</button>
                            </li>
                        </ul>
                        <div class="tab-content p-3 border border-top-0">
                            <div class="tab-pane fade show active" id="detailEmployeeView" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-2 d-flex align-items-start justify-content-center mb-2">
                                        <div class="img_st">
                                            <label>{{ __('message.image') }}</label>
                                            <div class="d-flex justify-content-center">
                                                <div class="pic_add">
                                                    <img class="img-detail" src="{{ $anhMacDinh }}" onerror="this.onerror=null; this.src='{{ $anhMacDinh }}';">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.full-name') }}</label>
                                            <input type="text" name="full_name" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.date-of-birth') }}</label>
                                            <input type="text" name="birth_date" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.email') }}</label>
                                            <input type="text" name="email" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.date-employment') }}</label>
                                            <input type="text" name="hired_on" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label d-block">{{ __('message.status') }}</label>
                                            <input type="checkbox" name="status" class="switch_customer status" disabled>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.branch') }}</label>
                                            <input type="text" name="shop_name" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.gender') }}</label>
                                            <input type="text" name="gender" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.phone') }}</label>
                                            <input type="text" name="phone" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">CCCD</label>
                                            <input type="text" name="id_number" class="form-control" disabled>
                                        </div>
                                        <div class="col-md-12 mb-3 item1">
                                            <label class="form-label">{{ __('message.work-shift') }}</label>
                                            <input type="text" name="work_shift" class="form-control" disabled>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        {{-- Cùng chữ với cột trên bảng và ô trong hộp Sửa: ba chỗ cùng bày
                                             một thứ (CỬA vào của người này) nên phải gọi cùng một tên. --}}
                                        <label class="form-label d-block">{{ __('message.hr_permission') }}</label>
                                        @foreach ($C::NHAN_CUA as $cua => $ten)
                                            <div class="form-check gap-0">
                                                <input class="form-check-input" type="checkbox" value="{{ $cua }}" id="detail_cua_{{ $cua }}" disabled>
                                                <label class="ms-2 form-check-label" for="detail_cua_{{ $cua }}">{{ $ten }}</label>
                                            </div>
                                        @endforeach
                                        <div class="mt-2 small text-muted detail-tai-khoan"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp xoá ===================== --}}
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
                            <div class="col"><label class="form-label">{{ __('message.delete-confirm') }}</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green delete-value">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Hộp hỏi của trang: gạt trạng thái kéo theo tài khoản đăng nhập, phải nói ra trước khi gửi. --}}
    <div class="modal" id="hoiEmployee">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title hoi-tieu-de"></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body hoi-body"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red hoi-huy" data-bs-dismiss="modal">Huỷ</button>
                    <button type="button" class="bt btn_green hoi-ok">Đồng ý</button>
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end offcanvas-custom" tabindex="-1" id="offcanvasDetail" aria-labelledby="offcanvasDetailLabel">
        <div class="offcanvas-header">
            <a type="button" aria-label="Đóng" class="btn-back" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-arrow-left" style="font-size: 20px;"></i>
            </a>
            <div class="d-flex" style="flex: 1;">
                <h5 class="offcanvas-title" id="offcanvasDetailLabel">{{ __('message.detail') }}</h5>
            </div>
            <div class="d-flex button-header" style="gap: 12px;">
                <a class="edit_bt" type="button"><i class="fa fa-edit"></i></a>
                <a class="dele_bt" type="button"><i class="fa fa-trash" style="color: red;"></i></a>
            </div>
        </div>
        <div class="offcanvas-body" style="height: calc(100vh - 58px); padding: 0px;">
            <div class="modal-view-materials"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/nhan-su-luat.js') }}"></script>
    <script>
        const CSRF = '{{ csrf_token() }}';
        const URL_BASE = @json(url('/admin/staff'));
        const URL_STORE = @json(route('admin.nhan-su.store'));
        const URL_BULK_DEL = @json(route('admin.nhan-su.bulkDestroy'));
        const URL_ANH = @json(route('admin.nhan-su.anh'));
        const URL_EXPORT = @json(route('admin.nhan-su.export'));
        const ANH_MAC_DINH = @json($anhMacDinh);
        const DANG_LAM = @json($C::DANG_LAM);
        const DA_NGHI = @json($C::DA_NGHI);
        const CUA_THEO_O = @json($C::CUA);
        const NHAN_CUA = @json($C::NHAN_CUA);
        const NHAN_CA = @json($nhanCa);
        const GIOI_TINH = @json($C::GIOI_TINH);

        // Bản ghi từng dòng; đọc lại sau mỗi lượt nạp danh sách.
        let NV = docDongHienCo();
        function docDongHienCo() {
            try { return JSON.parse(document.getElementById('v2-rows').textContent) || {}; } catch (e) { return {}; }
        }
        $(document).on('v2:da-nap', function () { NV = docDongHienCo(); });

        const hienNgay = (iso) => (iso ? String(iso).slice(0, 10).split('-').reverse().join('-') : '');
        const isoTu = (dmy) => {
            const m = /^(\d{2})-(\d{2})-(\d{4})$/.exec(String(dmy || '').trim());
            return m ? m[3] + '-' + m[2] + '-' + m[1] : '';
        };
        const tachCa = (s) => String(s || '').split(',').map((x) => x.trim()).filter(Boolean);
        // shop_ids là CSV; hồ sơ cũ chỉ có shop_id.
        const tachIds = (csv, mot) => {
            const ids = tachCa(csv);
            return ids.length ? ids : (mot ? [String(mot)] : []);
        };

        /** Ghi bằng AJAX kèm Accept JSON: có toast rồi mới nạp lại bảng. Trả về Promise<bool>. */
        function ghiJSON(url, method, fields) {
            const fd = new FormData();
            fd.append('_token', CSRF);
            if (method && method !== 'POST') fd.append('_method', method);
            if (V2.chiNhanhTab) fd.append('chi_nhanh', String(V2.chiNhanhTab));
            $.each(fields || {}, (k, v) => {
                Array.isArray(v) ? v.forEach((x) => fd.append(k, x)) : fd.append(k, v == null ? '' : v);
            });
            return fetch(url, {
                method: 'POST', body: fd, credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then((res) => res.json().then((r) => ({ ok: res.ok, body: r || {} })))
                .then((r) => {
                    const cau = r.body.message || (r.ok ? 'Đã lưu.' : 'Thao tác không thành công.');
                    r.ok ? toastr.success(cau) : toastr.error(cau);
                    V2.napLai(location.href, false);
                    return r.ok;
                })
                .catch(() => { toastr.error('Không gửi được. Kiểm tra kết nối rồi thử lại.'); return false; });
        }

        // ---------- Hộp hỏi ----------
        const $hoi = $('#hoiEmployee');
        let hoiXong = null;
        function hoi(o, xong) {
            $hoi.find('.hoi-tieu-de').text(o.tieuDe || '');
            $hoi.find('.hoi-body').html((o.doan || []).map((d) => $('<p>').text(d).prop('outerHTML')).join(''));
            $hoi.find('.hoi-ok').text(o.nutDongY || 'Đồng ý');
            $hoi.find('.hoi-huy').text(o.nutHuy || 'Huỷ');
            hoiXong = xong;
            $hoi.modal('show');
        }
        $hoi.find('.hoi-ok').on('click', function () {
            const f = hoiXong; hoiXong = null;
            $hoi.modal('hide');
            if (f) f(true);
        });
        $hoi.on('hidden.bs.modal', function () {
            const f = hoiXong; hoiXong = null;
            if (f) f(false);
        });

        // ---------- Bộ lọc chạy ngay ----------
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');
        function locLai() {
            const q = new URLSearchParams();
            const tu = String(oLoc('keyword').val() || '').trim();
            if (tu) q.set('keyword', tu);
            (oLoc('shop_id[]').val() || []).forEach((v) => q.append('shop_id[]', v));
            ['work_shift[]', 'gender[]'].forEach((ten) => {
                oLoc(ten).filter(':checked').each(function () { q.append(ten, this.value); });
            });
            const cu = new URLSearchParams(location.search);
            ['page_size', 'status'].forEach((ten) => { if (cu.get(ten)) q.set(ten, cu.get(ten)); });
            V2.napLai(location.pathname + '?' + q);
        }
        let timerTim = null;
        $(document).on('input', '.fillter-box input[name="keyword"]', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(locLai, 400);
        });
        $(document).on('click', '.fillter-box .seach-item', locLai);
        $(document).on('change', '.fillter-box select[name="shop_id[]"], .fillter-box input[name="work_shift[]"], .fillter-box input[name="gender[]"]', locLai);
        $(document).on('submit', '#search-form', function (e) { e.preventDefault(); locLai(); });
        $('.fillter-box select.branchEmployee').select2({ width: '100%', placeholder: @json(__('message.branch')) });

        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        $(document).on('click', '.btn-export', function () {
            location.href = URL_EXPORT + location.search;
        });

        // ---------- Công tắc trạng thái trên bảng ----------
        $(document).on('change', '.list .item-status', function () {
            const $o = $(this);
            const id = $o.data('id');
            const nv = NV[id] || {};
            const bat = this.checked;
            const ten = nv.full_name || 'Nhân viên này';
            const tk = nv.username || '';
            const gui = (mo) => ghiJSON(URL_BASE + '/' + id + '/status', 'PUT', {
                status: bat ? DANG_LAM : DA_NGHI, mo_tai_khoan: mo ? 1 : 0,
            }).then((ok) => { if (!ok) $o.prop('checked', !bat); });

            if (tk && !bat) {
                hoi({
                    tieuDe: 'Đánh dấu đã nghỉ việc?',
                    doan: [
                        ten + ' chuyển sang trạng thái đã nghỉ việc. Hồ sơ vẫn giữ nguyên để tra lại.',
                        'Tài khoản đăng nhập “' + tk + '” bị khoá ngay: người này không mở được quầy bán bằng mật khẩu cũ nữa.',
                    ],
                    nutDongY: 'Nghỉ việc & khoá tài khoản',
                }, (dongY) => { dongY ? gui(false) : $o.prop('checked', true); });
                return;
            }
            if (tk && bat && nv.user_status && nv.user_status !== 'active') {
                hoi({
                    tieuDe: 'Mở lại tài khoản đăng nhập?',
                    doan: [
                        ten + ' được đặt lại thành đang làm việc.',
                        'Tài khoản “' + tk + '” đang khoá. Mở lại thì họ đăng nhập được ngay bằng mật khẩu cũ.',
                    ],
                    nutDongY: 'Mở lại tài khoản',
                    nutHuy: 'Chưa mở',
                }, (dongY) => gui(dongY));
                return;
            }
            gui(false);
        });

        // =====================================================================
        //  Hộp Thêm / Sửa
        // =====================================================================
        const $crud = $('#CreateEmployee');
        const $form = $('#employeeForm');
        // Hồ sơ đang sửa: mấy ô v2 không có (địa chỉ, ghi chú, lương) gửi lại nguyên giá trị cũ.
        let hoSoDangSua = null;

        $crud.find('select.select2').select2({ width: '100%', dropdownParent: $crud });
        $form.find('#birthday').daterangepicker({
            singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true,
            maxDate: moment().endOf('day'), locale: V2.lichVN(),
        }, function (start) { $(this.element).val(start.format('DD-MM-YYYY')); });
        $form.find('#date_employment').daterangepicker({
            singleDatePicker: true, showDropdowns: true, autoUpdateInput: false, autoApply: true, locale: V2.lichVN(),
        }, function (start) { $(this.element).val(start.format('DD-MM-YYYY')); });

        // "Cả ngày" đã gồm sáng và chiều: chọn nó thì bỏ hai ca kia, và ngược lại.
        $form.find('.ip_work_shift').on('select2:select', function (e) {
            const chon = e.params.data.id;
            const hienCo = $(this).val() || [];
            const moi = chon === NhanSuLuat.CA_NGAY ? [chon] : hienCo.filter((c) => c !== NhanSuLuat.CA_NGAY);
            $(this).val(moi).trigger('change.select2');
        });

        function dongBoTrangThaiForm() {
            const bat = $form.find('input[name="status"]').is(':checked');
            const khoa = !!(hoSoDangSua && hoSoDangSua.username && hoSoDangSua.user_status && hoSoDangSua.user_status !== 'active');
            $form.find('#moTaiKhoanRow').toggleClass('d-none', !(bat && khoa));
            if (!(bat && khoa)) $form.find('#mo_tai_khoan').prop('checked', false);
        }
        $form.find('input[name="status"]').on('change', dongBoTrangThaiForm);

        function hienKhoiTaiKhoan() {
            $form.find('.taiKhoanBox').toggleClass('d-none', !$form.find('#co_tai_khoan').is(':checked'));
        }
        $form.find('#co_tai_khoan').on('change', hienKhoiTaiKhoan);

        function khoiTaiKhoanTheoHoSo(nv) {
            const daCo = !!(nv && nv.username);
            $form.find('.coTaiKhoanRow').toggleClass('d-none', daCo);
            $form.find('.matKhauMoiBox').toggleClass('d-none', !daCo);
            $form.find('#mat_khau_moi').val('');
            $form.find('.taiKhoanDaCo').toggleClass('d-none', !daCo)
                .text(daCo ? 'Đã có tài khoản: ' + nv.username + (nv.role_display_name ? ' — ' + nv.role_display_name : '')
                    + '. Đổi quyền bằng ô Quyền nhân sự ở tab Chi tiết.' : '');
            $form.find('#co_tai_khoan').prop('checked', false);
            hienKhoiTaiKhoan();
        }

        function xoaTrangCrUd() {
            hoSoDangSua = null;
            $form[0].reset();
            $form.find('input.id, .ip_avatar, .ip_avatar_cu').val('');
            $form.find('select.select2').val(null).trigger('change');
            $form.find('#img-preview').attr('src', ANH_MAC_DINH);
            $form.find('input[name="status"]').prop('checked', true);
            $form.find('#allow_access_outside_area_scope').prop('checked', true);
            $form.find('.ip_quyen').prop('checked', false);
            $form.find('#password').attr('placeholder', @json(__('message.password')));
            // Cửa hàng một chi nhánh thì chọn sẵn.
            const $cn = $form.find('.ip_shop_ids');
            if ($cn.find('option').length === 1) $cn.val([$cn.find('option').val()]).trigger('change');
            khoiTaiKhoanTheoHoSo(null);
            dongBoTrangThaiForm();
            $crud.find('.nav-tabs .nav-link').first().tab('show');
            $crud.find('#bt-submit-create').prop('disabled', false);
        }

        function doVaoCrUd(nv) {
            hoSoDangSua = nv;
            $form.find('input.id').val(nv.id);
            $form.find('.ip_code').val(nv.code || '');
            $form.find('[name="full_name"]').val(nv.full_name || '');
            $form.find('#birthday').val(hienNgay(nv.birth_date));
            $form.find('[name="email"]').val(nv.email || '');
            $form.find('#date_employment').val(hienNgay(nv.hired_on));
            $form.find('input[name="status"]').prop('checked', nv.status !== DA_NGHI);
            $form.find('.ip_shop_ids').val(tachIds(nv.shop_ids, nv.shop_id)).trigger('change');
            $form.find('#allow_access_outside_area_scope').prop('checked', nv.allow_outside_area !== false);
            $form.find('[name="gender"]').val(nv.gender || '');
            $form.find('[name="phone"]').val(nv.phone || '');
            $form.find('[name="id_number"]').val(nv.id_number || '');
            $form.find('.ip_work_shift').val(tachCa(nv.work_shift)).trigger('change');
            const nenTick = NhanSuLuat.oQuyenNenTick(nv.quyen || [], CUA_THEO_O);
            $form.find('.ip_quyen').each(function () { this.checked = !!nenTick[this.value]; });
            $form.find('.ip_avatar, .ip_avatar_cu').val(nv.avatar || '');
            $form.find('#img-preview').attr('src', nv.avatar || ANH_MAC_DINH);
            $form.find('#username, #password').val('');
            $form.find('#password').attr('placeholder', @json(__('message.enter_new_password')));
            khoiTaiKhoanTheoHoSo(nv);
            dongBoTrangThaiForm();
        }

        const cuaDong = (el) => NV[$(el).closest('.item').data('id') || $('#offcanvasDetail').attr('data-id')];

        $(document).on('click', '.add-item', function () {
            xoaTrangCrUd();
            $crud.find('.modal-title').text(@json(__('message.add-employee-profile')));
            $crud.modal('show');
        });

        $(document).on('click', '.edit_bt', function (e) {
            e.preventDefault();
            const nv = cuaDong(this);
            if (!nv) return;
            xoaTrangCrUd();
            $crud.find('.modal-title').text(@json(__('message.edit-employee-profile')));
            doVaoCrUd(nv);
            $crud.modal('show');
        });

        $crud.on('hidden.bs.modal', xoaTrangCrUd);

        // Ảnh tải lên ngay lúc chọn; form chỉ mang theo đường dẫn.
        $(document).on('change', '#CreateEmployee .ip_img', function () {
            const f = this.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('anh', f);
            fd.append('_token', CSRF);
            $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((r) => {
                    $form.find('.ip_avatar').val(r.url);
                    $form.find('#img-preview').attr('src', r.url);
                })
                .fail((x) => toastr.error((x.responseJSON && x.responseJSON.message) || 'Không tải được ảnh lên.'))
                .always(() => { this.value = ''; });
        });

        $(document).on('click', '#bt-submit-create', function () {
            const id = $form.find('input.id').val();
            const ten = $form.find('[name="full_name"]').val().trim();
            if (!ten) { toastr.error('Chưa nhập họ tên nhân viên.'); return; }
            const chiNhanh = $form.find('.ip_shop_ids').val() || [];
            if (!chiNhanh.length) { toastr.error('Chưa chọn chi nhánh làm việc.'); return; }

            const cu = hoSoDangSua || {};
            const coTaiKhoan = $form.find('#co_tai_khoan').is(':checked') && !$form.find('.coTaiKhoanRow').hasClass('d-none');
            const du = {
                code: $form.find('.ip_code').val().trim(),
                full_name: ten,
                gender: $form.find('[name="gender"]').val() || '',
                birth_date: isoTu($form.find('#birthday').val()),
                phone: $form.find('[name="phone"]').val().trim(),
                email: $form.find('[name="email"]').val().trim(),
                id_number: $form.find('[name="id_number"]').val().trim(),
                'work_shift[]': $form.find('.ip_work_shift').val() || [],
                shop_id: chiNhanh[0],
                'shop_ids[]': chiNhanh,
                hired_on: isoTu($form.find('#date_employment').val()),
                status: $form.find('input[name="status"]').is(':checked') ? DANG_LAM : DA_NGHI,
                mo_tai_khoan: $form.find('#mo_tai_khoan').is(':checked') ? 1 : 0,
                'quyen[]': $form.find('.ip_quyen:checked').map((i, o) => o.value).get(),
                co_tai_khoan: coTaiKhoan ? 1 : 0,
                username: coTaiKhoan ? $form.find('#username').val().trim() : '',
                password: coTaiKhoan ? $form.find('#password').val() : '',
                avatar: $form.find('.ip_avatar').val(),
                avatar_cu: $form.find('.ip_avatar_cu').val(),
                allow_outside_area: $form.find('#allow_access_outside_area_scope').is(':checked') ? 1 : 0,
                mat_khau_moi: $form.find('#mat_khau_moi').val(),
                // Ô v2 không có: giữ nguyên giá trị cũ, không để lượt sửa xoá mất.
                address: cu.address || '',
                note: cu.note || '',
                salary_type: cu.salary_type || '',
                salary: cu.salary || 0,
                allowance: cu.allowance || 0,
                commission_rate: cu.commission_rate || 0,
            };
            V2.luuHop($crud, id ? URL_BASE + '/' + id : URL_STORE, id ? 'PUT' : 'POST', du, $(this));
        });

        // Đặt lại mật khẩu về mặc định của cửa hàng cho tài khoản đang mở trong hộp sửa.
        $(document).on('click', '.reset-password-btn', function () {
            const nv = hoSoDangSua;
            if (!nv || !nv.username) return;
            hoi({
                tieuDe: @json(__('message.reset_password')),
                doan: [@json(__('message.confirm_reset_default_password')), 'Tài khoản “' + nv.username + '” sẽ dùng mật khẩu mặc định của cửa hàng ngay lập tức.'],
                nutDongY: @json(__('message.reset_password')),
            }, (dongY) => {
                if (dongY) ghiJSON(URL_BASE + '/' + nv.id + '/reset-password', 'POST', {});
            });
        });

        // =====================================================================
        //  Hộp Chi tiết + thẻ trên điện thoại
        // =====================================================================
        const $detailForm = $('#employeeFormDetail');
        function doVaoChiTiet(nv) {
            $detailForm.find('.img-detail').attr('src', nv.avatar || ANH_MAC_DINH);
            $detailForm.find('[name="full_name"]').val(nv.full_name || '');
            $detailForm.find('[name="birth_date"]').val(hienNgay(nv.birth_date));
            $detailForm.find('[name="email"]').val(nv.email || '');
            $detailForm.find('[name="hired_on"]').val(hienNgay(nv.hired_on));
            $detailForm.find('[name="status"]').prop('checked', nv.status === DANG_LAM);
            $detailForm.find('[name="shop_name"]').val((nv.shop_names || []).join(', ') || nv.shop_name || '');
            $detailForm.find('[name="gender"]').val(GIOI_TINH[nv.gender] || '');
            $detailForm.find('[name="phone"]').val(nv.phone || '');
            $detailForm.find('[name="id_number"]').val(nv.id_number || '');
            $detailForm.find('[name="work_shift"]').val(tachCa(nv.work_shift).map((c) => NHAN_CA[c] || c).join(', '));
            const cua = nv.quyen || [];
            $detailForm.find('input[type="checkbox"][id^="detail_cua_"]').each(function () { this.checked = cua.indexOf(this.value) !== -1; });
            $detailForm.find('.detail-tai-khoan').text(nv.username
                ? 'Tài khoản: ' + nv.username + (nv.user_status && nv.user_status !== 'active' ? ' (đã khoá)' : '')
                : 'Không có tài khoản đăng nhập');
        }

        $(document).on('click', '.detail-item', function () {
            const nv = cuaDong(this);
            if (!nv) return;
            doVaoChiTiet(nv);
            $('#DetailEmployee').modal('show');
        });

        $(document).on('click', '.table-employee.none_desktop .item', function (e) {
            if ($(e.target).closest('input').length) return;
            const nv = NV[$(this).data('id')];
            if (!nv) return;
            $('#offcanvasDetail').attr('data-id', nv.id);
            doVaoChiTiet(nv);
            $('#offcanvasDetail .modal-view-materials').append($detailForm);
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasDetail')).show();
        });
        $('#offcanvasDetail').on('hidden.bs.offcanvas', function () {
            $('#DetailEmployee .modal-body').append($detailForm);
        });

        // ---------- Xoá ----------
        $(document).on('click', '.dele_bt', function (e) {
            e.preventDefault();
            const nv = cuaDong(this);
            if (!nv) return;
            $('#deleteValue').val(nv.id);
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#delete-mutil-data', function () {
            const ids = $('.list input.item-select:checked').map((i, o) => o.value).get();
            if (!ids.length) { toastr.error(@json(__('message.delete-none'))); return; }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.delete-value', function () {
            const ids = String($('#deleteValue').val() || '').split(',').filter(Boolean);
            $('#deleteItem').modal('hide');
            if (!ids.length) return;
            const offcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasDetail'));
            if (offcanvas) offcanvas.hide();
            if (ids.length === 1) {
                const nv = NV[ids[0]] || {};
                ghiJSON(URL_BASE + '/' + ids[0], 'DELETE', { avatar: nv.avatar || '' });
            } else {
                ghiJSON(URL_BULK_DEL, 'POST', { 'ids[]': ids });
            }
        });
        $('#deleteItem').on('hidden.bs.modal', function () { $('#deleteValue').val(''); });
    </script>
@endpush
