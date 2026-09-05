{{-- Màn Quản lý chi nhánh dựng theo khuôn v2 (system/branch/index + list).

     Chép bố cục, tên class và bộ ô của bản v2: khung lọc dọc bên trái, bảng
     `.table-list-branch`, hộp `#addBranchModal` chia hai cột (hồ sơ | ảnh + ba
     khối chữ hoá đơn), hộp xem `#showBranchModal`, hai hộp hoá đơn điện tử
     `#connectInvoiceModal` / `#detailInvoiceModal`.

     Khác bản v2 ở ba chỗ, đều vì backend bên này khác:
       - v2 nạp bảng bằng AJAX qua list.blade.php; bên này $list đã có sẵn nên
         dựng thẳng ra HTML, phần nạp lại để V2.napLai của khung v2 lo.
       - v2 có ô "Ngày hết hạn" (sys_branches.expired_time do nhà cung cấp quản
         lý). API bên này không có cột ấy — hạn dùng nằm ở mức CỬA HÀNG, xem
         HanSuDung — nên bỏ ô đó thay vì bày một ô luôn trống.
       - ô QR đặt hàng online đổi thành "Link truy cập": API trả `access_link`,
         không trả sẵn ảnh QR như v2.

     Dữ liệu do ChiNhanhController đẩy sang: $list, $tatCa, $meta, $filters,
     $dangLamId, $tong, $dangMo, $stt. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\ChiNhanhController::TITLE_PAGE)

@push('styles')
    <style>
        /* ---- Chép của bản v2 (system/branch) ---- */
        .large-width-label { width: 220px; }
        label.form-label { font-weight: bold; }
        .parent_create { background: #fff; }
        #branchForm { background: #aaaaaa6b; }

        .header_action {
            background: #fff;
            border-bottom: 1px solid rgba(128, 128, 128, .418);
        }

        #addBranchModal .modal-header,
        #showBranchModal .modal-header { background: #D5E1F3 !important; }

        .form_update_img {
            height: 35%;
            border: 1px solid #d2d2d2;
            border-radius: 5px;
            margin: 5px;
        }

        .update_img {
            width: 100%;
            height: 120px;
            border: 2px dashed #ccc;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            cursor: pointer;
        }
        .update_img i.fa-camera { font-size: 48px; color: #888; }
        .update_img img { object-fit: cover; display: none; max-width: 320px; max-height: 115px; }
        .update_img.active img { display: block; }
        .update_img.active i.fa-camera { display: none; }

        #showBranchModal .modal-body { padding: 15px; }
        #showBranchModal .update_img img { max-height: 115px; object-fit: cover; border-radius: 8px; }
        #showBranchModal .card { border-radius: 8px; }

        /* Hàng "nhãn — giá trị" của hộp xem. */
        .item-left { display: flex; padding: 4px 0; gap: 4px; }
        .item-left label { flex: 0 0 35%; padding: 0; }
        .item-left span { border-bottom: 1px solid #e9ebec; text-align: center; flex: 1; }

        .left-info { flex: 0 0 60%; }

        @media screen and (max-width: 768px) {
            .left-info { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 280px; }
        }

        /* Bảng 11 cột không nhét vừa màn thường — CHO CUỘN NGANG chứ không bóp
           cột lại. Tắt bớt cột thì các cột còn lại tự giãn ra chiếm chỗ. */
        .table-list-container { overflow-x: auto; }
        table.table-list-branch { min-width: 1200px; width: 100%; }

        /* Hai cột chữ dài cắt bằng "…"; di chuột vào ô để xem đủ (title). */
        table.table-list-branch .item-name,
        table.table-list-branch .item-creator { max-width: 260px; overflow: hidden; text-overflow: ellipsis; }

        /* Dấu "Đang làm việc" ở dòng chi nhánh mình đang đứng. */
        .cn-here {
            display: inline-block; margin-left: 6px; padding: 1px 6px;
            border-radius: 10px; background: #e6f4ff; color: #0958d9;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }

        /* Đếm ký tự ba khối chữ hoá đơn. */
        .pos-invoice-char-count-wrap.is-day { color: #ff4d4f !important; }

        /* Khối chữ hoá đơn trong hộp xem: giữ nguyên xuống dòng người dùng gõ. */
        .cn-vpre {
            white-space: pre-wrap; text-align: left; min-height: 2.5em;
        }
    </style>
@endpush

@php
    $C = \App\Http\Controllers\ChiNhanhController::class;
    $MAX = $C::CHU_HOA_DON_TOI_DA;
    $anhMacDinh = asset('v2/images/image_defaul.png');

    // Tên nhà cung cấp HĐĐT đầu danh sách — ô chọn mặc định ghim vào nó.
    $etaxDau = $C::NHA_CUNG_CAP_ETAX[array_key_first($C::NHA_CUNG_CAP_ETAX)] ?? '';

    $gio = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('H:i:s d-m-Y') : '-';

    // Khu quản trị không dùng guard của Laravel — hồ sơ người đăng nhập nằm
    // trong phiên (xem AuthController).
    $toiLa = trim((string) data_get(session('api.user'), 'full_name', ''))
        ?: (string) data_get(session('api.user'), 'username', '');

    // v2 giữ trạng thái cột trong bảng riêng theo từng người; ở đây lấy từ query
    // để giữ được sau khi nạp lại. STT đứng ngoài — v2 cũng không cho tắt nó.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $nhanCot = [
        'branch_code' => __('message.branch-code'),
        'branch_name' => __('message.branch-name'),
        'tax_code' => __('message.tax_code'),
        'phone' => __('message.phone'),
        'hddt' => __('message.use_etax'),
        'type' => __('message.company').' / '.__('message.branch'),
        'creator' => __('message.creator'),
        'creation_time' => __('message.creation_time'),
        'status' => __('message.status'),
        'action' => __('message.action'),
    ];
    $columns = [];
    foreach (array_keys($nhanCot) as $c) {
        $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }
@endphp

@section('content')
    {{-- Dãy nút mở bộ lọc, CHỈ hiện trên điện thoại: mỗi nút kéo một khối lọc
         bên dưới vào tấm offcanvas chung của khung v2. --}}
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
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterStatus">
                <p class="open-modal-label">{{ __('message.status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-toggle-on"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-system-branch-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2.
             d-none d-lg-block: trên điện thoại các khối này đã đi vào offcanvas,
             để hiện cả hai chỗ là bộ lọc nằm hai nơi trong cùng một trang. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không có nút "Lọc": gõ hay đổi ô nào là lọc lại ngay. --}}
                        <form action="{{ route('admin.chi-nhanh.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterSearch" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.search') }}</span>
                                    <div class="d-flex w-100 mt-1">
                                        <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                            class="form-control search" autocomplete="off"
                                            placeholder="{{ __('message.enter-name-or-code') }}">
                                    </div>
                                </div>
                            </div>

                            <div id="filterBranch" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.branch') }}</span>
                                    {{-- Bày đủ mọi chi nhánh, kể cả cái vừa bị chính bộ lọc loại
                                         ra — nếu không thì chọn xong là không chọn lại được. --}}
                                    <select name="branch" class="form-control form-select mt-1">
                                        <option value="">{{ __('message.all') }}</option>
                                        @foreach ($tatCa as $cn)
                                            <option value="{{ $cn['id'] }}" {{ $filters['branch'] === $cn['id'] ? 'selected' : '' }}>
                                                {{ $cn['code'] }} - {{ $cn['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div id="filterStatus" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.status') }}</span>
                                    <select name="status" class="form-control form-select mt-1">
                                        <option value="">{{ __('message.all') }}</option>
                                        @foreach ($C::TRANG_THAI as $ma => $ten)
                                            <option value="{{ $ma }}" {{ $filters['status'] === (string) $ma ? 'selected' : '' }}>
                                                {{ $ten }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Đổi bộ lọc là về trang 1; số dòng mỗi trang thì giữ. --}}
                            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="content_midd">
                <div class="content_midd_title">
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang, trình
                         đọc màn hình lấy nó làm mốc. Class .tieu-de-trang giữ nguyên
                         dáng chữ của h4 bên v2. --}}
                    <h1 class="tieu-de-trang">{{ __('message.company_and_branch_list') }}</h1>
                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green btnAddBranch">{{ __('message.create_new') }}</a>

                            <a type="button" class="bt btn_gray btn_export">{{ __('message.export-excel') }}</a>

                            {{-- Chọn cột: bỏ tick là thêm cột vào ?hide=, nạp lại giữ nguyên lựa chọn. --}}
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col" href="#">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" data-col="show_all" type="checkbox" id="show_all"
                                                    {{ count($cotTat) ? '' : 'checked' }}>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($nhanCot as $ma => $ten)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="show_{{ $ma }}"
                                                        type="checkbox" id="show_{{ $ma }}"
                                                        {{ $columns['show_'.$ma] == 1 ? 'checked' : '' }}>
                                                    <label for="show_{{ $ma }}">{{ $ten }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-list-container table-border-style table-responsive">
                        <table class="table-list-branch none_mobile">
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center">{{ __('message.stt') }}</th>
                                    <th data-table-label="branch_code" class="text-left show_branch_code {{ $columns['show_branch_code'] ? '' : 'hide' }}">{{ __('message.branch-code') }}</th>
                                    <th data-table-label="branch_name" class="text-left show_branch_name {{ $columns['show_branch_name'] ? '' : 'hide' }}">{{ __('message.branch-name') }}</th>
                                    <th data-table-label="tax_code" class="text-right show_tax_code {{ $columns['show_tax_code'] ? '' : 'hide' }}">{{ __('message.tax_code') }}</th>
                                    <th data-table-label="phone" class="text-right show_phone {{ $columns['show_phone'] ? '' : 'hide' }}">{{ __('message.phone') }}</th>
                                    <th data-table-label="hddt" class="text-center show_hddt {{ $columns['show_hddt'] ? '' : 'hide' }} not-export">{{ __('message.use_etax') }}</th>
                                    <th data-table-label="type" class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">{{ __('message.company') }} / {{ __('message.branch') }}</th>
                                    <th data-table-label="creator" class="text-left show_creator {{ $columns['show_creator'] ? '' : 'hide' }}">{{ __('message.creator') }}</th>
                                    <th data-table-label="creation_time" class="text-right show_creation_time {{ $columns['show_creation_time'] ? '' : 'hide' }}">{{ __('message.creation_time') }}</th>
                                    <th data-table-label="status" class="text-center show_status {{ $columns['show_status'] ? '' : 'hide' }}">{{ __('message.status') }}</th>
                                    <th data-table-label="action" class="text-center show_action {{ $columns['show_action'] ? '' : 'hide' }} not-export">{{ __('message.action') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse ($list as $i => $cn)
                                    @php
                                        $id = (int) ($cn['id'] ?? 0);
                                        $mo = (bool) ($cn['is_active'] ?? false);
                                        $dangDung = $dangLamId > 0 && $dangLamId === $id;
                                        $loai = (int) ($cn['branch_type'] ?? $C::LOAI_CHI_NHANH);
                                        $etax = $cn['etax'] ?? null;
                                    @endphp
                                    <tr class="item" data-id="{{ $id }}">
                                        <td class="text-center">{{ $stt + $i + 1 }}</td>

                                        <td data-table-label="branch_code" class="text-left item-code show_branch_code {{ $columns['show_branch_code'] ? '' : 'hide' }}">
                                            {{ ($cn['code'] ?? '') ?: '-' }}
                                        </td>

                                        <td data-table-label="branch_name" class="text-left item-name show_branch_name {{ $columns['show_branch_name'] ? '' : 'hide' }}"
                                            title="{{ $cn['name'] ?? '' }}">
                                            {{ ($cn['name'] ?? '') ?: '-' }}
                                            @if ($dangDung)
                                                <span class="cn-here" title="Mọi thao tác kho và đơn của bạn đang tính vào chi nhánh này">Đang làm việc</span>
                                            @endif
                                        </td>

                                        <td data-table-label="tax_code" class="text-right show_tax_code {{ $columns['show_tax_code'] ? '' : 'hide' }}">
                                            {{ ($cn['tax_code'] ?? '') ?: '-' }}
                                        </td>

                                        <td data-table-label="phone" class="text-right show_phone {{ $columns['show_phone'] ? '' : 'hide' }}">
                                            {{ ($cn['phone'] ?? '') ?: '-' }}
                                        </td>

                                        <td data-table-label="hddt" class="text-center show_hddt {{ $columns['show_hddt'] ? '' : 'hide' }} not-export">
                                            {{-- Đã nối thì mở hộp cài đặt, chưa nối thì mở hộp khai tài khoản. --}}
                                            @if ($etax)
                                                <button type="button" class="bt btn_gray handle-detail-invoice" data-id="{{ $id }}"
                                                    title="Đang dùng {{ $C::NHA_CUNG_CAP_ETAX[$etax['provider'] ?? ''] ?? 'cổng hoá đơn' }} — mã số thuế {{ $etax['tax_code'] ?? '' }}">
                                                    {{ __('message.detail') }}
                                                </button>
                                            @else
                                                <button type="button" class="bt btn_green handle-connect-invoice" data-id="{{ $id }}"
                                                    title="Chi nhánh này chưa nối cổng hoá đơn điện tử">
                                                    {{ __('message.connect') }}
                                                </button>
                                            @endif
                                        </td>

                                        <td data-table-label="type" class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">
                                            {{ $C::LOAI[$loai] ?? '-' }}
                                        </td>

                                        <td data-table-label="creator" class="text-left item-creator show_creator {{ $columns['show_creator'] ? '' : 'hide' }}"
                                            title="{{ $cn['created_by_name'] ?? '' }}">
                                            {{ ($cn['created_by_name'] ?? '') ?: '-' }}
                                        </td>

                                        <td data-table-label="creation_time" class="text-right show_creation_time {{ $columns['show_creation_time'] ? '' : 'hide' }}">
                                            {{ $gio($cn['created_at'] ?? null) }}
                                        </td>

                                        <td data-table-label="status" class="text-center show_status {{ $columns['show_status'] ? '' : 'hide' }}">
                                            {{-- Chi nhánh mình đang đứng thì khoá công tắc: đóng nó là
                                                 mọi thao tác sau đó của chính người vừa bấm rơi vào một
                                                 điểm bán đã đóng. --}}
                                            <input type="checkbox" class="switch_customer item-status" data-id="{{ $id }}"
                                                {{ $mo ? 'checked' : '' }} {{ $dangDung ? 'disabled' : '' }}
                                                title="{{ $dangDung
                                                    ? 'Bạn đang làm việc tại chi nhánh này — đổi chi nhánh ở thanh trên cùng trước khi đóng nó.'
                                                    : ($mo ? 'Đang mở — bấm để đóng' : 'Đã đóng — bấm để mở lại') }}">
                                        </td>

                                        <td data-table-label="action" class="text-center action show_action {{ $columns['show_action'] ? '' : 'hide' }} not-export">
                                            <a class="detail-item" type="button" title="{{ __('message.view-detail') }}"><i class="fa fa-eye"></i></a>
                                            <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                            <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="text-center py-4">{{ $C::EMPTY_TEXT }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai. --}}
                        <div class="table-list-branch none_desktop mt-2">
                            <div class="item">
                                <div class="fw-bold">{{ __('message.branch-name') }}</div>
                                <div class="fw-bold">{{ __('message.phone') }}</div>
                            </div>
                            @foreach ($list as $cn)
                                <div class="item" data-id="{{ (int) ($cn['id'] ?? 0) }}">
                                    <div class="d-flex flex-column detail-item" role="button">
                                        <span class="fw-semibold">{{ ($cn['name'] ?? '') ?: '-' }}</span>
                                        <span style="font-size: 14px">{{ ($cn['code'] ?? '') ?: '-' }}</span>
                                    </div>
                                    <div class="d-flex text-right gap-2">{{ ($cn['phone'] ?? '') ?: '-' }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dữ liệu đầy đủ của từng dòng, ĐẶT TRONG khối được thay khi nạp
                         lại. Để ở ngoài thì lọc bằng AJAX xong bảng là bảng mới mà hộp
                         Sửa / Chi tiết vẫn đọc dữ liệu của lượt tải đầu tiên. --}}
                    <script type="application/json" id="v2-rows">@json(collect($list)->keyBy('id'))</script>

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
    <div class="modal fade" id="addBranchModal" tabindex="-1" aria-labelledby="addBranchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable mx-auto" style="min-width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBranchModalLabel">{{ __('message.create_new') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body" style="padding: 5px !important">
                    <form autocomplete="off">
                        <div id="branchForm" class="d-flex flex-column">
                            {{-- Hàng nút nằm ngay đầu hộp như bản v2: hộp này dài, để nút
                                 dưới đáy thì phải cuộn hết mới bấm được Lưu. --}}
                            <div class="d-flex justify-content-between header_action">
                                <div class="d-flex my-auto">
                                    <label class="form-label large-width-label my-auto mx-1 w-auto">{{ __('message.branch_list') }}</label>
                                </div>
                                <div class="d-flex py-1 my-auto btn-save-branch">
                                    <input type="hidden" value="" id="id_branch">
                                    <input type="hidden" value="" id="image_branch">
                                    <button type="button" class="bt btn_red mx-2" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                                    <button type="button" class="bt btn_green mx-2 create_branch">{{ __('message.save') }}</button>
                                </div>
                            </div>

                            <div class="row m-0" style="background:#fff">
                                <div class="col-12 col-md-7 mt-2">
                                    <div class="mb-2">
                                        <div class="d-flex">
                                            <label for="code" class="form-label large-width-label my-auto">{{ __('message.code') }}</label>
                                            <input type="text" class="form-control my-1 code" id="code" name="code" maxlength="30"
                                                placeholder="Bỏ trống để hệ thống tự đặt">
                                        </div>
                                        <div class="d-flex">
                                            <label for="company_name" class="form-label large-width-label my-auto">{{ __('message.name') }}
                                                <span class="required">*</span></label>
                                            <input type="text" class="form-control my-1 company_name" id="company_name"
                                                name="company_name" maxlength="150" placeholder="{{ __('message.name') }}">
                                        </div>
                                        <div class="d-flex">
                                            <label for="transaction_name" class="form-label large-width-label my-auto">{{ __('message.transaction_name') }}</label>
                                            <input type="text" class="form-control my-1 transaction_name" id="transaction_name"
                                                name="transaction_name" maxlength="150"
                                                placeholder="{{ __('message.transaction_abbreviation') }}">
                                        </div>
                                    </div>

                                    <div class="mb-2 d-flex">
                                        <label for="address" class="form-label large-width-label my-auto">{{ __('message.address') }}</label>
                                        <input type="text" class="form-control address" id="address" name="address" maxlength="255"
                                            placeholder="{{ __('message.address') }}">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="country" class="form-label large-width-label my-auto">{{ __('message.country') }}</label>
                                        <input type="text" class="form-control country" id="country" name="country" maxlength="100"
                                            placeholder="Việt Nam">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="city" class="form-label large-width-label my-auto">{{ __('message.city') }}</label>
                                        <input type="text" class="form-control city" id="city" name="city" maxlength="100"
                                            placeholder="{{ __('message.city') }}">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="location" class="form-label large-width-label my-auto">{{ __('message.location') }}
                                            <a href="https://support.google.com/maps/answer/18539?hl=vi" target="_blank" rel="noopener"
                                                title="Cách lấy toạ độ trên Google Maps"><i class="fas fa-question-circle"></i></a></label>
                                        <input type="text" class="form-control location" id="location" name="location" maxlength="50"
                                            placeholder="10.813129471158957, 106.71001056725406">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="area_scope" class="form-label large-width-label my-auto">{{ __('message.area_scope') }}</label>
                                        <input type="number" class="form-control area_scope" id="area_scope" name="area_scope"
                                            min="1" placeholder="100">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="access_link" class="form-label large-width-label my-auto">{{ __('message.access_link') }}</label>
                                        <input type="text" class="form-control access_link" id="access_link" name="access_link"
                                            maxlength="255" placeholder="{{ __('message.access_link') }}">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="email" class="form-label large-width-label my-auto">{{ __('message.email') }}</label>
                                        <input type="email" class="form-control email" id="email" name="email" maxlength="150"
                                            placeholder="{{ __('message.email') }}">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="phone" class="form-label large-width-label my-auto">{{ __('message.phone-number') }}</label>
                                        <input type="text" class="form-control phone" id="phone" name="phone" maxlength="20"
                                            placeholder="{{ __('message.phone-number') }}">
                                    </div>

                                    <div class="mb-2 d-flex">
                                        <label class="form-label large-width-label my-auto">{{ __('message.branch') }}</label>
                                        <div class="w-100 d-flex justify-content-around">
                                            @foreach ($C::LOAI as $ma => $ten)
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input branch_type" type="radio" name="branch_type"
                                                        id="branch_type_{{ $ma }}" value="{{ $ma }}"
                                                        {{ $ma === $C::LOAI_CHI_NHANH ? 'checked' : '' }}>
                                                    <label class="form-check-label" for="branch_type_{{ $ma }}">{{ $ten }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="mb-2 d-flex">
                                        <label for="tax_code" class="form-label large-width-label my-auto">{{ __('message.tax_code') }}</label>
                                        <input type="text" class="form-control tax_code" id="tax_code" name="tax_code" maxlength="20"
                                            placeholder="{{ __('message.tax_code') }}">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="creator" class="form-label large-width-label my-auto">{{ __('message.creator') }}</label>
                                        <input type="text" class="form-control creator" id="creator" name="creator" readonly
                                            value="{{ $toiLa }}" placeholder="{{ __('message.creator') }}">
                                    </div>
                                    <div class="mb-2 d-flex">
                                        <label for="created_at" class="form-label large-width-label my-auto">{{ __('message.created_at') }}</label>
                                        <input type="text" class="form-control created_at" id="created_at" name="created_at" disabled>
                                    </div>

                                    <div class="mb-2 d-flex">
                                        <label for="status" class="form-label large-width-label my-auto">{{ __('message.status') }}</label>
                                        <div class="w-100" style="height: 33px">
                                            <input type="checkbox" class="switch_customer status" id="status" name="status" checked>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 col-md-5 d-block mt-2">
                                    <div class="d-flex flex-column text-center mb-2 form_update_img">
                                        <div class="d-flex w-100" style="height: 90%; padding: 12px;">
                                            <div class="w-25 d-flex flex-column">
                                                <button type="button" class="m-auto border-0 bg-transparent btnUpdate" title="{{ __('message.upload') }}">
                                                    <img src="{{ asset('v2/images/ic_update_img.png') }}" alt="ic_update_img" width="50">
                                                </button>
                                                <button type="button" class="m-auto border-0 bg-transparent btnRemove" title="{{ __('message.delete') }}">
                                                    <img src="{{ asset('v2/images/ic_remove_img.png') }}" alt="ic_remove_img" width="50">
                                                </button>
                                            </div>
                                            <div class="w-75 d-flex">
                                                <div class="update_img m-auto">
                                                    <i class="fa fa-camera" aria-hidden="true"></i>
                                                    <img class="previewImage" src="" alt="preview">
                                                </div>
                                            </div>
                                            <input type="file" class="imageInput" style="display: none;" accept="image/*">
                                        </div>
                                        <span class="text-danger" style="font-size: 12px">* {{ __('message.invoice_logo_note') }}</span>
                                    </div>

                                    @php
                                        $khoiHoaDon = [
                                            ['header_invoice_info', __('message.header_invoice_store_name_info')],
                                            ['wifi_invoice_info', __('message.wifi_invoice_info')],
                                            ['footer_invoice_info', __('message.footer_invoice_info')],
                                        ];
                                    @endphp
                                    @foreach ($khoiHoaDon as [$ten, $nhan])
                                        <div class="mb-2 pos-invoice-field-wrap">
                                            <label for="{{ $ten }}">{{ $nhan }}</label>
                                            <textarea name="{{ $ten }}" id="{{ $ten }}" class="form-control {{ $ten }}" rows="3"
                                                maxlength="{{ $MAX }}" placeholder="{{ $nhan }}"></textarea>
                                            <small class="text-muted d-block text-end mt-1 pos-invoice-char-count-wrap">
                                                <span class="pos-invoice-char-current">0</span> / <span>{{ $MAX }}</span>
                                            </small>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Xem chi tiết ===================== --}}
    <div class="modal fade" id="showBranchModal" tabindex="-1" aria-labelledby="showBranchModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="showBranchModalLabel">{{ __('message.view-detail') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-3">
                    <div class="d-flex flex-wrap justify-content-center" id="branchDetail">
                        <div class="left-info me-md-2">
                            <div class="p-3 d-flex flex-column border mb-2" style="border-radius: 10px;">
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.code') }}</label><span class="code"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.name') }}</label><span class="company_name"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.transaction_abbreviation') }}</label><span class="transaction_name"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.address') }}</label><span class="address" style="white-space: normal; word-break: break-word;"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.country') }}</label><span class="country"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.city') }}</label><span class="city"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.location') }}</label><span class="location"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.area_scope') }}</label><span class="area_scope"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.email') }}</label><span class="email"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.phone-number') }}</label><span class="phone"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.branch') }}</label><span class="branch_type"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.tax_code') }}</label><span class="tax_code"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.status') }}</label><span class="is_active"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.creator') }}</label><span class="creator"></span></div>
                                <div class="item-left"><label class="text-start fw-bold">{{ __('message.created_at') }}</label><span class="created_at"></span></div>
                            </div>
                        </div>

                        <div style="flex: 1;">
                            <div class="card mb-2" style="height: 120px">
                                <div class="card-body p-2 w-100 d-flex" style="height: 120px">
                                    <img id="showBranchImage" class="mx-auto" style="height: 100%; width: auto;"
                                        src="{{ $anhMacDinh }}" alt="branch image">
                                </div>
                            </div>

                            <div class="card mb-2 h-auto">
                                <div class="card-body p-2">
                                    <label class="text-start fw-bold"><strong class="px-0">{{ __('message.header_invoice_store_name_info') }}</strong></label>
                                    <div class="form-control-static header_invoice_info cn-vpre mx-1 mb-3 border rounded p-2 bg-light"></div>
                                    <label class="text-start fw-bold"><strong class="px-0">{{ __('message.wifi_invoice_info') }}</strong></label>
                                    <div class="form-control-static wifi_invoice_info cn-vpre mx-1 mb-3 border rounded p-2 bg-light"></div>
                                    <label class="text-start fw-bold"><strong class="px-0">{{ __('message.footer_invoice_info') }}</strong></label>
                                    <div class="form-control-static footer_invoice_info cn-vpre mx-1 mb-3 border rounded p-2 bg-light"></div>
                                </div>
                            </div>

                            {{-- v2 vẽ mã QR đặt online ở đây; API bên này trả `access_link`
                                 chứ không trả ảnh QR, nên bày thẳng đường dẫn. --}}
                            <div class="card">
                                <label class="p-2">{{ __('message.access_link') }}</label>
                                <div class="card-body p-2 w-100 d-flex justify-content-center">
                                    <div class="access_link text-break"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green detail-edit">{{ __('message.edit') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Kết nối hoá đơn điện tử ===================== --}}
    <div class="modal fade" id="connectInvoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered" style="min-width: 70%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.setting_etax_connection_info') }} <span class="modal-phu etax-ten-cn"></span></h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="modal_center">
                        <input type="hidden" class="etax_id">

                        <div class="row">
                            <div class="row gy-1">
                                {{-- Bản v2 bày sáu ô chọn nhưng chỉ ba cái chạy thật. Ở đây khai
                                     đúng cái đã làm — bày một nhà cung cấp chưa nối được là để
                                     người dùng khai xong rồi ngồi chờ một thứ không phát hành nổi. --}}
                                @foreach ($C::NHA_CUNG_CAP_ETAX as $ma => $ten)
                                    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
                                        <h4 class="form-check border border-2 rounded py-3 justify-content-center">
                                            <input class="form-check-input me-1" type="radio" name="etax_supplier"
                                                id="etax_supplier_{{ $ma }}" value="{{ $ma }}" data-name="{{ $ten }}"
                                                {{ $loop->first ? 'checked' : '' }}>
                                            <label class="form-check-label" for="etax_supplier_{{ $ma }}">{{ $ten }}</label>
                                        </h4>
                                    </div>
                                @endforeach
                            </div>

                            <div class="col-12 mt-3">
                                <ul class="h6 fw-normal" style="margin-left: -10px">
                                    <li class="mb-2">{{ __('message.update_invoice_to_nasys') }}
                                        <span class="fw-bold etax-supplier-name">{{ $etaxDau }}</span></li>
                                    <li class="mb-2">{{ __('message.export_invoice_template_of') }}
                                        <span class="fw-bold etax-supplier-name">{{ $etaxDau }}</span></li>
                                    <li>{{ __('message.issue_etax_from_sales_admin') }}</li>
                                </ul>
                            </div>

                            <div class="col-12 col-md-3 mt-3">
                                <label class="form-label fw-bold">{{ __('message.tax_code') }} <span class="required">*</span></label>
                                <input type="text" class="form-control w-100 connect_tax_code" maxlength="30" autocomplete="off"
                                    placeholder="0312345678">
                                <small class="text-muted">Chính mã số thuế đăng nhập cổng — địa chỉ máy chủ dựng từ nó.</small>
                            </div>
                            <div class="col-12 col-md-3 mt-3">
                                <label class="form-label fw-bold">Mã đơn vị cơ sở</label>
                                <input type="text" class="form-control w-100 connect_ma_dvcs" maxlength="20" autocomplete="off" value="VP">
                                <small class="text-muted">Bỏ trống = VP (văn phòng).</small>
                            </div>
                            <div class="col-12 col-md-3 mt-3">
                                <label class="form-label fw-bold">{{ __('message.username') }} <span class="required">*</span></label>
                                <input type="text" class="form-control w-100 connect_username" maxlength="150" autocomplete="off">
                            </div>
                            <div class="col-12 col-md-3 mt-3">
                                <label class="form-label fw-bold">{{ __('message.password') }} <span class="required">*</span></label>
                                <input type="password" class="form-control w-100 connect_password" maxlength="200" autocomplete="new-password">
                            </div>

                            <div class="col-12 mt-2">
                                <small class="text-muted">Mật khẩu được mã hoá trước khi lưu và không có đường đọc lại.
                                    Đổi mật khẩu bên nhà cung cấp thì khai lại ở đây.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-etax-setting">{{ __('message.connect') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Chi tiết hoá đơn điện tử ===================== --}}
    <div class="modal fade" id="detailInvoiceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.etax_detail') }} <span class="modal-phu etax-ten-cn"></span></h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" class="etax_id">

                    <div class="p-2 border rounded mb-3">
                        <div class="item-left"><label class="fw-bold">Nhà cung cấp</label><span class="etax_provider"></span></div>
                        <div class="item-left"><label class="fw-bold">{{ __('message.tax_code') }}</label><span class="etax_tax_code"></span></div>
                        <div class="item-left"><label class="fw-bold">{{ __('message.username') }}</label><span class="etax_username"></span></div>
                        <div class="item-left"><label class="fw-bold">Đăng nhập gần nhất</label><span class="etax_synced"></span></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ký hiệu phát hành <span class="required">*</span></label>
                        <select class="form-control form-select etax_template"></select>
                        <small class="text-muted">Danh sách kéo về từ cổng. Vừa đăng ký thêm ký hiệu mới thì bấm
                            <b>Đồng bộ mẫu</b> rồi chọn lại.</small>
                    </div>

                    <div class="form-check mb-2">
                        <input class="form-check-input etax_auto_release" type="checkbox" id="etax_auto_release">
                        <label class="form-check-label" for="etax_auto_release">Tự phát hành hoá đơn khi đơn thanh toán xong</label>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input etax_auto_print" type="checkbox" id="etax_auto_print">
                        <label class="form-check-label" for="etax_auto_print">Tự in hoá đơn sau khi phát hành</label>
                    </div>

                    <small class="text-muted d-block">Tự phát hành chỉ chạy được với chữ ký số MỀM (file p12, hoặc dịch vụ
                        EASY / ICA / INTRUST). Ký bằng USB token thì đơn vẫn lưu thành hoá đơn nháp, bấm
                        <b>Ký và gửi</b> ở màn Đơn hàng để phát hành.</small>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_gray sync-etax-template">Đồng bộ mẫu</button>
                    <button type="button" class="bt btn_green save-etax-config">{{ __('message.update-btn') }}</button>
                    <button type="button" class="bt btn_red delete-connect">{{ __('message.disconnect') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Xoá ===================== --}}
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
                                <p class="mb-0 text-muted" id="deleteNote"></p>
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
@endsection

@push('scripts')
    <script>
        const URL_BASE = @json(url('/admin/branches'));
        const URL_STORE = @json(route('admin.chi-nhanh.store'));
        const URL_ANH = @json(route('admin.chi-nhanh.anh'));
        const ANH_MAC_DINH = @json($anhMacDinh);
        const LOAI = @json(\App\Http\Controllers\ChiNhanhController::LOAI);
        const NHA_CC = @json(\App\Http\Controllers\ChiNhanhController::NHA_CUNG_CAP_ETAX);
        const CHU_TOI_DA = {{ $MAX }};
        const TRANG_THAI_CHU = @json(\App\Http\Controllers\ChiNhanhController::TRANG_THAI);

        // Cả bản ghi của từng dòng — hộp Sửa / Chi tiết đọc thẳng ở đây, khỏi rải
        // hơn chục data-* lên mỗi <tr> như bản v2. Đọc lại sau mỗi lượt nạp danh
        // sách bằng AJAX, không thì dữ liệu là của bảng đã bị thay đi.
        let CN = docDongHienCo();

        function docDongHienCo() {
            try {
                return JSON.parse(document.getElementById('v2-rows').textContent) || {};
            } catch (e) {
                return {};
            }
        }

        $(document).on('v2:da-nap', function () { CN = docDongHienCo(); });

        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const chu = (v) => (v === null || v === undefined || v === '') ? '-' : String(v);

        function gioNgay(s) {
            if (!s) return '-';
            const d = new Date(s);
            if (isNaN(d.getTime())) return '-';
            return d.toLocaleString('vi-VN', {
                hour: '2-digit', minute: '2-digit', second: '2-digit',
                day: '2-digit', month: '2-digit', year: 'numeric',
            });
        }

        /** Bản ghi của dòng đang bấm — bảng và dãy thẻ điện thoại cùng dùng .item. */
        const cuaDong = (el) => CN[$(el).closest('.item').data('id')];

        /**
         * Ghi một thao tác NGAY TRÊN BẢNG (công tắc, xoá, đồng bộ, ngắt kết nối).
         *
         * Không dùng V2.ghi: controller trả JSON cho mọi lượt gọi ngầm nên V2.ghi
         * không bóc được câu báo ra khỏi HTML, gạt công tắc xong im lặng không
         * biết đã ăn hay chưa.
         */
        function ghiVaBao(action, method, fields) {
            const fd = new FormData();
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
            if (method && method !== 'POST') fd.append('_method', method);
            $.each(fields || {}, (k, v) => fd.append(k, v == null ? '' : v));

            $('.loading-overlay').removeClass('d-none');

            return fetch(action, {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((res) => res.json().then((r) => ({ ok: res.ok, body: r || {} })))
                .then((r) => {
                    r.ok
                        ? toastr.success(r.body.message || 'Đã lưu.')
                        : toastr.error(r.body.message || 'Thao tác không thành công.');

                    return V2.napLai(location.href, false).then(() => r.ok);
                })
                .catch(() => {
                    toastr.error('Không gửi được yêu cầu. Kiểm tra kết nối rồi thử lại.');

                    return false;
                })
                .finally(() => $('.loading-overlay').addClass('d-none'));
        }

        // ---------- Chọn cột ----------
        // Cột đang tắt ghi vào ?hide= rồi nạp lại, để giữ sau khi đổi trang.
        function apDungCot() {
            const tat = $('.show_col').filter((i, el) => !el.checked)
                .map((i, el) => $(el).data('col').replace('show_', '')).get();
            const q = new URLSearchParams(location.search);
            tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            apDungCot();
        });

        // ---------- Bộ lọc chạy ngay: đổi ô chọn là lọc luôn, gõ thì chờ 400ms ----------
        //
        // Tự dựng URL thay vì submit form, vì hai lẽ:
        //   - trên điện thoại khung v2 BƯNG khối lọc sang tấm offcanvas, và mỗi
        //     lượt chỉ bưng MỘT khối, nên submit lúc đó sẽ đánh rơi hai ô còn lại;
        //   - lựa chọn cột nằm ở ?hide=, không phải ô trong form, submit là mất.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai() {
            const q = new URLSearchParams();
            ['keyword', 'branch', 'status'].forEach((ten) => {
                const v = String(oLoc(ten).val() || '').trim();
                if (v) q.set(ten, v);
            });

            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size'].forEach((ten) => {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            // Cố ý không mang `page` theo: lọc lại thì trang 5 của bộ lọc cũ
            // không còn nghĩa gì.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerTim = null;
        $(document).on('change', '.fillter-box select[name="branch"], .fillter-box select[name="status"]', locLai);
        $(document).on('input', '.fillter-box input[name="keyword"]', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(locLai, 400);
        });
        $(document).on('submit', '#search-form', function (e) {
            e.preventDefault();
            locLai();
        });

        // ---------- Xuất file ----------
        // Chưa có đường xuất bên máy chủ, nên dựng CSV ngay từ bảng đang xem.
        // Cột Hành động và cột HĐĐT mang nút bấm nên bị loại (.not-export).
        $(document).on('click', '.btn_export', function () {
            const dong = [];
            $('table.table-list-branch tr').each(function () {
                const o = [];
                $(this).find('th, td').each(function () {
                    const $o = $(this);
                    if ($o.hasClass('not-export') || $o.hasClass('hide')) return;
                    o.push($o.text().trim().replace(/\s+/g, ' '));
                });
                if (o.length) dong.push(o);
            });
            if (dong.length < 2) { toastr.error('Bảng đang trống, không có gì để xuất.'); return; }

            const csv = dong.map((h) => h.map((v) => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\r\n');
            // BOM để Excel đọc đúng tiếng Việt.
            const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'chi-nhanh-' + new Date().toISOString().slice(0, 10) + '.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(a.href);
        });

        // =====================================================================
        //  Hộp Thêm / Sửa
        // =====================================================================
        const $form = $('#addBranchModal');

        function demChu($ta) {
            const n = String($ta.val() || '').length;
            const $o = $ta.closest('.pos-invoice-field-wrap').find('.pos-invoice-char-count-wrap');
            $o.find('.pos-invoice-char-current').text(n);
            $o.toggleClass('is-day', n >= CHU_TOI_DA);
        }
        $(document).on('input', '#addBranchModal textarea', function () { demChu($(this)); });

        function veAnh(duong) {
            const $khung = $form.find('.update_img');
            $khung.toggleClass('active', !!duong);
            $khung.find('.previewImage').attr('src', duong || '');
            $form.find('#image_branch').val(duong || '');
        }

        function xoaTrangForm() {
            $form.find('input[type=text], input[type=email], input[type=number], textarea').val('');
            $form.find('#id_branch, #image_branch').val('');
            $form.find('.imageInput').val('');
            $form.find('.status').prop('checked', true);
            $form.find('.branch_type[value="{{ $C::LOAI_CHI_NHANH }}"]').prop('checked', true);
            $form.find('.creator').val(@json($toiLa));
            $form.find('.created_at').val('');
            veAnh('');
            $form.find('#addBranchModalLabel').text('{{ __('message.create_new') }}');
            $form.find('textarea').each(function () { demChu($(this)); });
        }

        function doVaoForm(d) {
            $form.find('#id_branch').val(d.id);
            $form.find('.code').val(d.code || '');
            $form.find('.company_name').val(d.name || '');
            $form.find('.transaction_name').val(d.transaction_name || '');
            $form.find('.address').val(d.address || '');
            $form.find('.country').val(d.country || '');
            $form.find('.city').val(d.city || '');
            $form.find('.location').val(d.location || '');
            $form.find('.area_scope').val(d.area_scope || '');
            $form.find('.access_link').val(d.access_link || '');
            $form.find('.email').val(d.email || '');
            $form.find('.phone').val(d.phone || '');
            $form.find('.tax_code').val(d.tax_code || '');
            $form.find('.branch_type[value="' + (Number(d.branch_type) || 1) + '"]').prop('checked', true);
            $form.find('.creator').val(d.created_by_name || @json($toiLa));
            $form.find('.created_at').val(gioNgay(d.created_at));
            $form.find('.status').prop('checked', !!d.is_active);
            $form.find('#header_invoice_info').val(d.header_invoice_info || '');
            $form.find('#wifi_invoice_info').val(d.wifi_invoice_info || '');
            $form.find('#footer_invoice_info').val(d.footer_invoice_info || '');
            veAnh(d.image || '');
            $form.find('#addBranchModalLabel').text('{{ __('message.edit') }}');
            $form.find('textarea').each(function () { demChu($(this)); });
        }

        function moSua(d) {
            xoaTrangForm();
            doVaoForm(d);
            $form.modal('show');
        }

        $(document).on('click', '.btnAddBranch', function () {
            xoaTrangForm();
            $form.modal('show');
        });

        $(document).on('click', '.edit-item', function () {
            const d = cuaDong(this);
            if (d) moSua(d);
        });

        // Ảnh tải lên ngay lúc chọn; form chỉ mang theo đường dẫn — bấm Lưu mà
        // hỏng thì ảnh vẫn còn đó, không phải chọn lại.
        $(document).on('click', '#addBranchModal .btnUpdate, #addBranchModal .update_img', function () {
            $form.find('.imageInput').trigger('click');
        });
        $(document).on('click', '#addBranchModal .btnRemove', function () {
            $form.find('.imageInput').val('');
            veAnh('');
        });
        $(document).on('change', '#addBranchModal .imageInput', function () {
            const f = this.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('image', f);
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
            $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((r) => veAnh(r.url))
                .fail((x) => toastr.error((x.responseJSON && x.responseJSON.message) || 'Không tải được ảnh lên.'));
        });

        $(document).on('click', '#addBranchModal .create_branch', function () {
            const id = $form.find('#id_branch').val();
            const ten = $form.find('.company_name').val().trim();
            if (!ten) { toastr.error('Chưa nhập tên chi nhánh.'); return; }

            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI cho người dùng sửa (VD trùng mã),
            // thay vì tải lại trang làm mất sạch cả form dài này.
            V2.luuHop($form, id ? URL_BASE + '/' + id : URL_STORE, id ? 'PUT' : 'POST', {
                code: $form.find('.code').val().trim(),
                name: ten,
                transaction_name: $form.find('.transaction_name').val().trim(),
                address: $form.find('.address').val().trim(),
                country: $form.find('.country').val().trim(),
                city: $form.find('.city').val().trim(),
                location: $form.find('.location').val().trim(),
                area_scope: $form.find('.area_scope').val().trim(),
                access_link: $form.find('.access_link').val().trim(),
                email: $form.find('.email').val().trim(),
                phone: $form.find('.phone').val().trim(),
                tax_code: $form.find('.tax_code').val().trim(),
                branch_type: $form.find('.branch_type:checked').val() || 1,
                image: $form.find('#image_branch').val(),
                // Không trim ba khối hoá đơn: người dùng canh lề bản in bằng chính
                // khoảng trắng đầu dòng.
                header_invoice_info: $form.find('#header_invoice_info').val(),
                wifi_invoice_info: $form.find('#wifi_invoice_info').val(),
                footer_invoice_info: $form.find('#footer_invoice_info').val(),
                is_active: $form.find('.status').is(':checked') ? 1 : 0,
            }, $(this));
        });

        // =====================================================================
        //  Hộp Xem chi tiết
        // =====================================================================
        const $xem = $('#showBranchModal');
        let dangXem = null;

        $(document).on('click', '.detail-item', function () {
            const d = cuaDong(this);
            if (!d) return;
            dangXem = d;

            $xem.find('.code').text(chu(d.code));
            $xem.find('.company_name').text(chu(d.name));
            $xem.find('.transaction_name').text(chu(d.transaction_name));
            $xem.find('.address').text(chu(d.address));
            $xem.find('.country').text(chu(d.country));
            $xem.find('.city').text(chu(d.city));
            $xem.find('.location').text(chu(d.location));
            $xem.find('.area_scope').text(d.area_scope ? d.area_scope + ' m' : '-');
            $xem.find('.email').text(chu(d.email));
            $xem.find('.phone').text(chu(d.phone));
            $xem.find('.branch_type').text(LOAI[Number(d.branch_type) || 1] || '-');
            $xem.find('.tax_code').text(chu(d.tax_code));
            $xem.find('.is_active').text(d.is_active ? TRANG_THAI_CHU['1'] : TRANG_THAI_CHU['0']);
            $xem.find('.creator').text(chu(d.created_by_name));
            $xem.find('.created_at').text(gioNgay(d.created_at));

            $xem.find('#showBranchImage').attr('src', d.image || ANH_MAC_DINH);
            $xem.find('.header_invoice_info').text(d.header_invoice_info || '-');
            $xem.find('.wifi_invoice_info').text(d.wifi_invoice_info || '-');
            $xem.find('.footer_invoice_info').text(d.footer_invoice_info || '-');

            // Link do người dùng gõ: dựng bằng createElement, và chỉ mở http/https —
            // "javascript:..." dán vào đây là một cú bấm chạy mã.
            const $link = $xem.find('#branchDetail .access_link').empty();
            const duong = String(d.access_link || '');
            if (!duong) {
                $link.text('-');
            } else if (/^https?:\/\//i.test(duong)) {
                const a = document.createElement('a');
                a.href = duong;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = duong;
                $link.append(a);
            } else {
                $link.text(duong);
            }

            $xem.modal('show');
        });

        $(document).on('click', '#showBranchModal .detail-edit', function () {
            if (!dangXem) return;
            $xem.modal('hide');
            moSua(dangXem);
        });

        // =====================================================================
        //  Công tắc trạng thái
        // =====================================================================
        $(document).on('change', '.item-status', function () {
            ghiVaBao(URL_BASE + '/' + $(this).data('id') + '/status', 'PUT',
                { is_active: this.checked ? 1 : 0 });
        });

        // =====================================================================
        //  Xoá
        // =====================================================================
        $(document).on('click', '.delete-item', function () {
            const d = cuaDong(this);
            if (!d) return;
            $('#deleteValue').val(d.id);
            $('#deleteNote').text('Chi nhánh "' + (d.name || '') + '". Dữ liệu cũ vẫn tra lại được.');
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.delete-value', function () {
            const id = $('#deleteValue').val();
            if (!id) return;
            $('#deleteItem').modal('hide');
            ghiVaBao(URL_BASE + '/' + id, 'DELETE', {});
        });

        // =====================================================================
        //  Hoá đơn điện tử
        // =====================================================================
        const $noi = $('#connectInvoiceModal');
        const $chiTiet = $('#detailInvoiceModal');
        const etaxDuong = (id, duoi) => URL_BASE + '/' + id + '/etax' + (duoi || '');

        // Đổi ô chọn nhà cung cấp là đổi luôn tên trong ba dòng nhắc bên dưới.
        $(document).on('change', '[name="etax_supplier"]', function () {
            $noi.find('.etax-supplier-name').text($(this).data('name'));
        });

        $(document).on('click', '.handle-connect-invoice', function () {
            const d = cuaDong(this);
            const id = $(this).data('id');
            $noi.find('.etax_id').val(id);
            $noi.find('.etax-ten-cn').text(d ? '— ' + d.name : '');
            $noi.find('.connect_tax_code').val((d && d.tax_code) || '');
            $noi.find('.connect_ma_dvcs').val('VP');
            $noi.find('.connect_username, .connect_password').val('');
            $noi.find('[name="etax_supplier"]').first().prop('checked', true).trigger('change');
            $noi.modal('show');
        });

        $(document).on('click', '.save-etax-setting', function () {
            const id = $noi.find('.etax_id').val();
            const mst = $noi.find('.connect_tax_code').val().trim();
            const user = $noi.find('.connect_username').val().trim();
            const mk = $noi.find('.connect_password').val();
            if (!mst) { toastr.error('Chưa nhập mã số thuế.'); return; }
            if (!user) { toastr.error('Chưa nhập tên đăng nhập.'); return; }
            if (!mk) { toastr.error('Chưa nhập mật khẩu.'); return; }

            V2.luuHop($noi, etaxDuong(id), 'POST', {
                provider: $noi.find('[name="etax_supplier"]:checked').val(),
                tax_code: mst,
                username: user,
                // KHÔNG trim mật khẩu: khoảng trắng đầu/cuối có thể là một phần
                // của nó, và cắt đi là lượt đăng nhập hỏng mà không ai hiểu vì sao.
                password: mk,
                ma_dvcs: $noi.find('.connect_ma_dvcs').val().trim(),
            }, $(this));
        });

        /** Đổ kết nối đọc về vào hộp Chi tiết. */
        function veHopEtax(id, ten, d) {
            $chiTiet.find('.etax_id').val(id);
            $chiTiet.find('.etax-ten-cn').text(ten ? '— ' + ten : '');
            $chiTiet.find('.etax_provider').text(NHA_CC[d.provider] || d.provider || '-');
            $chiTiet.find('.etax_tax_code').text(chu(d.tax_code));
            $chiTiet.find('.etax_username').text(chu(d.username));
            $chiTiet.find('.etax_synced').text(gioNgay(d.token_synced_at));

            // Dựng ô chọn bằng Option(): ký hiệu và tên loại là chữ của nhà cung
            // cấp, nối thẳng vào innerHTML là mở cửa cho thẻ script.
            const o = $chiTiet.find('.etax_template')[0];
            o.innerHTML = '';
            const mau = d.templates || [];
            if (!mau.length) {
                o.appendChild(new Option('Chưa kéo được mẫu nào — bấm Đồng bộ mẫu', ''));
            } else {
                o.appendChild(new Option('— Chọn ký hiệu —', ''));
                mau.forEach((m) => {
                    o.appendChild(new Option(
                        m.symbol + (m.form_no ? ' · Mẫu số ' + m.form_no : '') + (m.type_name ? ' · ' + m.type_name : ''),
                        m.symbol
                    ));
                });
            }
            o.value = d.template_symbol || '';

            $chiTiet.find('.etax_auto_release').prop('checked', !!d.auto_release);
            $chiTiet.find('.etax_auto_print').prop('checked', !!d.auto_print);
        }

        $(document).on('click', '.handle-detail-invoice', function () {
            const d = cuaDong(this);
            const id = $(this).data('id');

            $.getJSON(etaxDuong(id))
                .done((r) => {
                    // API trả 404 khi chưa nối, controller đổi thành {data: null} —
                    // lúc ấy mở thẳng hộp khai tài khoản chứ đừng bày hộp rỗng.
                    if (!r.data) {
                        toastr.info('Chi nhánh này chưa nối cổng hoá đơn điện tử.');
                        $('.handle-connect-invoice[data-id="' + id + '"]').trigger('click');

                        return;
                    }
                    veHopEtax(id, d ? d.name : '', r.data);
                    $chiTiet.modal('show');
                })
                .fail((x) => toastr.error((x.responseJSON && x.responseJSON.message) || 'Không đọc được kết nối hoá đơn điện tử.'));
        });

        $(document).on('click', '.save-etax-config', function () {
            const id = $chiTiet.find('.etax_id').val();
            V2.luuHop($chiTiet, etaxDuong(id), 'PUT', {
                template_symbol: $chiTiet.find('.etax_template').val() || '',
                auto_release: $chiTiet.find('.etax_auto_release').is(':checked') ? 1 : 0,
                auto_print: $chiTiet.find('.etax_auto_print').is(':checked') ? 1 : 0,
            }, $(this));
        });

        $(document).on('click', '.sync-etax-template', function () {
            const id = $chiTiet.find('.etax_id').val();
            $chiTiet.modal('hide');
            ghiVaBao(etaxDuong(id, '/sync'), 'POST', {});
        });

        $(document).on('click', '.delete-connect', function () {
            const id = $chiTiet.find('.etax_id').val();
            $chiTiet.modal('hide');
            ghiVaBao(etaxDuong(id), 'DELETE', {});
        });
    </script>
@endpush
