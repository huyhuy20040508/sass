{{-- Màn Đơn vị tính — chép khuôn từ bản v2 cũ
     (ordertable/v2/resources/views/menu/menu-unit/index.blade.php + list.blade.php).

     Giữ nguyên lớp bọc và tên class của v2: `.row.index-menu-unit-page` → khung
     lọc `col-lg-2_5` bên trái → `.content_midd` bên phải → `.list.scrollDiv`,
     bảng `.table-list-container.table-border-style` với `data-table-label` trên
     từng ô, hộp `#modalMenuCategory` (v2 dùng đúng id này ở màn đơn vị),
     `#deleteItem` và tấm `#offcanvasDetail` cho điện thoại.

     Bảng của v2 nạp bằng AJAX; bên này controller đã cắt trang sẵn nên dựng
     thẳng ra HTML — không đụng gì tới backend. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\DonViTinhController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\DonViTinhController::class;
    // Đang lọc mà bảng rỗng thì nói "không khớp bộ lọc", đừng nói "chưa có":
    // chưa có là chưa khai gì, còn khớp là khai rồi nhưng lọc không ra — hai
    // việc phải làm khác hẳn nhau. Cùng khuôn với khu cũ (resources/views/chi-nhanh).
    $hasFilter = collect($filters)->only(['keyword', 'status'])
        ->contains(fn ($v) => $v !== '' && $v !== null && $v !== 0 && $v !== [] && $v !== 'all');
@endphp

@section('content')
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterTools">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-menu-unit-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <form action="{{ route('admin.don-vi-tinh.index') }}" method="GET" id="search-form">
                            <div id="filterTools">
                                <div class="inner-modal-in-mobile input-group" style="width: 100%;">
                                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                        class="form-control search" autocomplete="off"
                                        placeholder="{{ __('message.enter-name-or-code') }}" style="flex: 1;">
                                    <button class="btn seach-item" type="submit">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                    </button>
                                </div>
                            </div>

                            {{-- Lọc trạng thái: v2 không có ở màn này, nhưng controller bên
                                 mình đã nhận sẵn `status` nên bày ra cho dùng. --}}
                            <div id="filterStatus">
                                <div class="inner-modal-in-mobile">
                                    <label class="form-label title_search d-none d-md-block">{{ __('message.status') }}</label>
                                    <select name="status" class="form-select">
                                        <option value="">{{ __('message.all') }}</option>
                                        @foreach($C::TRANG_THAI as $ma => $ten)
                                            <option value="{{ $ma }}" {{ $filters['status'] === (string) $ma ? 'selected' : '' }}>{{ $ten }}</option>
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
                <div class="content_midd_title">
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang, trình
                         đọc màn hình lấy nó làm mốc. Class .tieu-de-trang giữ nguyên
                         dáng chữ của h4 bên v2. --}}
                    <h1 class="tieu-de-trang">{{ __('message.menu-unit-management') }}</h1>
                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green add-item">{{ __('message.create') }}</a>
                            <a type="button" class="bt btn_red mass-delete ms-2">{{ __('message.delete') }}</a>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-list-container table-border-style" style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center" style="width: 16px;">
                                        <input class="form-check-input item-select-all d-none d-lg-block" type="checkbox">
                                    </th>
                                    <th data-table-label="ordinal_number" class="text-center none_mobile">{{ __('message.stt') }}</th>
                                    <th data-table-label="object_code" class="text-left show_code">{{ __('message.menu-unit-code') }}</th>
                                    <th data-table-label="object_name" class="text-left">{{ __('message.menu-unit-name') }}</th>
                                    <th data-table-label="object_status" class="text-center none_mobile">{{ __('message.status') }}</th>
                                    <th data-table-label="object_action" class="text-center none_mobile">{{ __('message.action') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse($list as $i => $item)
                                    @php
                                        $id = (int) ($item['id'] ?? 0);
                                        $bat = (bool) ($item['is_active'] ?? false);
                                    @endphp
                                    <tr class="item" data-id="{{ $id }}"
                                        data-code="{{ $item['code'] ?? '' }}"
                                        data-name="{{ $item['name'] ?? '' }}"
                                        data-status="{{ $bat ? 1 : 0 }}"
                                        data-type="unit">

                                        <td class="text-center">
                                            <input class="form-check-input item-select d-none d-lg-block" type="checkbox" value="{{ $id }}">
                                        </td>
                                        <td data-table-label="ordinal_number" class="text-center none_mobile">{{ $stt + $i + 1 }}</td>
                                        <td data-table-label="object_code" class="text-left item-code">{{ $item['code'] ?? '' }}</td>
                                        <td data-table-label="object_name" class="text-left item-name">
                                            {{ $item['name'] ?? '' }}
                                            <i class="fa-solid fa-angle-right d-none"></i>
                                        </td>
                                        <td data-table-label="object_status" class="text-center none_mobile">
                                            <input type="checkbox" class="switch_customer status" data-id="{{ $id }}"
                                                {{ $bat ? 'checked' : '' }}>
                                        </td>
                                        <td data-table-label="object_action" class="text-center action none_mobile">
                                            <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                            <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                            <a class="copy_bt copy-item" type="button" title="{{ __('message.copy') }}"><i class="fa fa-copy"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4">{{ $hasFilter ? 'Không có đơn vị tính nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                        {{-- BẢN THẺ CHO ĐIỆN THOẠI — đúng khuôn v2 cũ.
                             Dưới 992px v2 giấu hẳn .table-list-container, và bày khối này thay chỗ
                             (responsive-manager.css: ẩn ở desktop, hiện ở mobile). Thiếu nó thì màn
                             hình trắng trơn — bảng vẫn nằm nguyên trong HTML nên nhìn mã nguồn
                             không thấy gì sai.

                             Khác v2 đúng một chỗ: v2 để khối này RỖNG rồi nhờ
                             public/v2/js/script.js dựng thẻ lúc chạy, mà vỏ v2 ở đây không nạp tệp
                             ấy — nên nó rỗng suốt. Dựng thẳng bằng Blade, giữ nguyên bộ class của
                             v2 để CSS của v2 tự ăn vào: hộp trắng bo góc, tên đậm, gạch ngăn, các
                             dòng "nhãn: giá trị", mã màu cam.

                             Cùng class .item và cùng bộ data-* với dòng bảng nên chọn, sửa, xoá,
                             gạt trạng thái dùng chung một đoạn JS. --}}
                        <div class="table-list-container-mobile">
                            @forelse($list as $item)
                                @php
                                    $id = (int) ($item['id'] ?? 0);
                                    $bat = (bool) ($item['is_active'] ?? false);
                                @endphp
                                <div class="table-list-mobile-item item d-flex align-items-stretch w-100 gap-0"
                                    data-id="{{ $id }}" data-code="{{ $item['code'] ?? '' }}"
                                    data-name="{{ $item['name'] ?? '' }}" data-status="{{ $bat ? 1 : 0 }}"
                                    data-type="unit">
                                    <div class="item-select-mobile-wrap flex-shrink-0 d-flex align-items-center pe-1 align-self-start">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    </div>
                                    <div class="content-finished-product-item flex-grow-1" style="min-width: 0">
                                        <div class="header-finished-product">
                                            <p class="finished-product-name">{{ $item['name'] ?? '' }}</p>
                                        </div>
                                        <div class="line-finished-product"></div>
                                        <div class="body-finished-product">
                                            <div class="finished-product-property">
                                                <p>{{ __('message.status') }}:</p>
                                                <p class="text-end">
                                                    <input type="checkbox" class="switch_customer status" data-id="{{ $id }}"
                                                        {{ $bat ? 'checked' : '' }}>
                                                </p>
                                            </div>
                                            <p class="finished-product-code">{{ $item['code'] ?? '' }}</p>
                                            <span class="action d-flex gap-2">
                                                <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                                <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                                <a class="copy_bt copy-item" type="button" title="{{ __('message.copy') }}"><i class="fa fa-copy"></i></a>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-4">{{ $hasFilter ? 'Không có đơn vị tính nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}</div>
                            @endforelse
                        </div>

                    {{-- Phân trang dựng đúng khuôn bootstrap-4 mà bản v2 in ra. --}}
                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width" data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ $meta['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Thêm / Sửa =====================
         v2 đặt id là #modalMenuCategory ở cả màn đơn vị — giữ nguyên. --}}
    <div class="modal" id="modalMenuCategory" data-mode="">
        <div class="modal-dialog modal-dialog-centered mx-auto" style="min-width: 30%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add') }} / {{ __('message.edit') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center px-2">
                        <input type="hidden" class="id">
                        <div>
                            <label class="form-label">{{ __('message.menu-unit-code') }}</label>
                            {{-- Bỏ trống thì API tự đặt mã — bản v2 bắt buộc gõ tay. --}}
                            <input type="text" class="form-control code" maxlength="20"
                                placeholder="{{ __('message.menu-unit-code') }}">
                        </div>
                        <div class="mt-3">
                            <label class="form-label">{{ __('message.menu-unit-name') }} <span class="required" style="color:red">*</span></label>
                            <input type="text" class="form-control name" maxlength="100"
                                placeholder="{{ __('message.menu-unit-name') }}">
                        </div>
                        <div class="mt-3 unitStatus">
                            <label class="form-label d-block">{{ __('message.status') }}</label>
                            <input type="checkbox" class="switch_customer status" checked>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-item">{{ __('message.save') }}</button>
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

    {{-- ===================== Tấm chi tiết cho điện thoại ===================== --}}
    <div class="offcanvas offcanvas-end offcanvas-custom" tabindex="-1" id="offcanvasDetail"
        aria-labelledby="offcanvasDetailLabel">
        <div class="offcanvas-header">
            <a type="button" aria-label="Đóng" class="btn-back" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-arrow-left" style="font-size: 20px;"></i>
            </a>
            <div class="d-flex" style="flex: 1;">
                <h5 class="offcanvas-title" id="offcanvasDetailLabel">{{ __('message.detail') }}</h5>
            </div>
            <div class="d-flex button-header" style="gap: 12px;">
                <a class="edit_bt edit-item-canvas" type="button"><i class="fa fa-edit"></i></a>
                <a class="dele_bt delete-item-canvas" type="button"><i class="fa fa-trash" style="color: red;"></i></a>
            </div>
        </div>
        <div class="offcanvas-body" style="height: calc(100dvh - 58px);">
            <div class="modal_center" style="padding: 0;">
                <input type="hidden" class="id">
                <div>
                    <label class="form-label">{{ __('message.menu-unit-code') }}</label>
                    <input type="text" class="form-control code" disabled placeholder="{{ __('message.menu-unit-code') }}">
                </div>
                <div class="mt-3">
                    <label class="form-label">{{ __('message.menu-unit-name') }}</label>
                    <input type="text" class="form-control name" disabled placeholder="{{ __('message.menu-unit-name') }}">
                </div>
                <div class="mt-3">
                    <label class="form-label d-block">{{ __('message.status') }}</label>
                    <input type="checkbox" class="switch_customer status" disabled>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const CSRF_DV = '{{ csrf_token() }}';
        const URL_DV = @json(url('/admin/units'));
        const URL_DV_STORE = @json(route('admin.don-vi-tinh.store'));
        const URL_DV_BULK = @json(route('admin.don-vi-tinh.bulkDestroy'));

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        function postForm(action, method, fields) {
            const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
            const them = (n, v) => $f.append($('<input>', { type: 'hidden', name: n, value: v == null ? '' : v }));
            them('_token', CSRF_DV);
            if (method && method !== 'POST') them('_method', method);
            // Lưu xong quay về ĐÚNG trang đang đứng, giữ nguyên bộ lọc và số trang.
            them('return', location.pathname + location.search);
            $.each(fields || {}, (k, v) => {
                Array.isArray(v) ? v.forEach((x) => them(k, x)) : them(k, v);
            });
            $('body').append($f);
            $f.trigger('submit');
        }

        // ---------- Bộ lọc: đổi ô nào là lọc lại ngay, gõ thì chờ 400ms ----------
        const $loc = $('#search-form');
        $loc.on('change', 'select', () => $loc.trigger('submit'));
        let timerDV = null;
        $loc.on('input', 'input[name="keyword"]', function () {
            clearTimeout(timerDV);
            timerDV = setTimeout(() => $loc.trigger('submit'), 400);
        });

        // ---------- Hộp Thêm / Sửa ----------
        const $hop = $('#modalMenuCategory');

        function moHop(mode, $tr) {
            $hop.attr('data-mode', mode);
            $hop.find('.id').val(mode === 'edit' ? $tr.data('id') : '');
            // Nhân bản: bê tên sang, bỏ trống mã cho API tự đặt — hai đơn vị trùng mã
            // là lúc chọn đơn vị lúc khai mặt hàng không biết đâu là đâu.
            $hop.find('.code').val(mode === 'edit' ? ($tr.attr('data-code') || '') : '');
            $hop.find('.name').val($tr ? ($tr.attr('data-name') || '') + (mode === 'copy' ? ' copy' : '') : '');
            $hop.find('.status').prop('checked', $tr ? String($tr.data('status')) === '1' : true);
            $hop.modal('show');
        }

        $(document).on('click', '.add-item', () => moHop('add', null));
        // `.item` chứ không `tr.item`: nút còn nằm trên thẻ điện thoại, và thẻ mang
        // đúng bộ data-* như dòng bảng.
        $(document).on('click', '.edit-item', function () { moHop('edit', $(this).closest('.item')); });
        $(document).on('click', '.copy-item', function () { moHop('copy', $(this).closest('.item')); });

        $(document).on('click', '#modalMenuCategory .save-item', function () {
            const id = $hop.find('.id').val();
            const ten = $hop.find('.name').val().trim();
            if (!ten) { toastr.error('Nhập tên đơn vị.'); return; }

            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI cho người dùng sửa (VD trùng
            // tên), thay vì tải lại trang rồi mới báo.
            V2.luuHop($hop, id ? URL_DV + '/' + id : URL_DV_STORE, id ? 'PUT' : 'POST', {
                code: $hop.find('.code').val().trim(),
                name: ten,
                is_active: $hop.find('.status').is(':checked') ? 1 : 0,
            }, $(this));
        });

        // ---------- Công tắc trên bảng ----------
        $(document).on('change', '.list .status', function () {
            const id = $(this).data('id');
            // Một dòng có HAI công tắc (bảng + thẻ điện thoại) — gạt bên này thì bên
            // kia phải theo, không thì đổi bề rộng cửa sổ là thấy hai trạng thái khác nhau.
            $('.list .status[data-id="' + id + '"]').prop('checked', this.checked);
            V2.ghi(URL_DV + '/' + id + '/status', 'PUT', { is_active: this.checked ? 1 : 0 });
        });

        // ---------- Chọn dòng + xoá ----------
        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        $(document).on('click', '.delete-item', function () {
            $('#deleteValue').val($(this).closest('.item').data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            // Set: mỗi dòng có hai ô tick (bảng + thẻ điện thoại) nên id hay lặp.
            const ids = [...new Set($('.item-select:checked').map((i, el) => el.value).get())];
            if (!ids.length) { toastr.error('{{ __('message.delete-none') }}'); return; }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#deleteItem .delete-value', function () {
            const ids = String($('#deleteValue').val()).split(',').filter(Boolean);
            if (!ids.length) return;
            ids.length === 1
                ? postForm(URL_DV + '/' + ids[0], 'DELETE', {})
                : postForm(URL_DV_BULK, 'POST', { 'ids[]': ids });
        });
    </script>
@endpush
