{{-- Màn Thuộc tính — chép khuôn từ bản v2 cũ
     (ordertable/v2/resources/views/menu/menu-attribute/index.blade.php + list.blade.php).

     Giữ nguyên lớp bọc và tên class của v2: `.row.index-menu-attribute-page` →
     khung lọc `col-lg-2_5` bên trái → `.content_midd` bên phải → `.list.scrollDiv`,
     bảng `.table-list-container.table-border-style` với `data-table-label` trên
     từng ô, hộp `#modalMenuCategory` (v2 dùng đúng id này ở màn thuộc tính) có
     bảng `#menu-attribute-detail` khai giá trị con, `#deleteItem` và tấm
     `#offcanvasDetail` cho điện thoại.

     Bảng của v2 nạp bằng AJAX; bên này controller đã cắt trang sẵn nên dựng
     thẳng ra HTML — không đụng gì tới backend. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\ThuocTinhController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\ThuocTinhController::class;
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

    <div class="row index-menu-attribute-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        <form action="{{ route('admin.thuoc-tinh.index') }}" method="GET" id="search-form">
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

                            {{-- Ô lọc trạng thái v2 không có; controller bên mình đã nhận
                                 sẵn `status` nên bày ra cho dùng. --}}
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
                    <h1 class="tieu-de-trang">{{ __('message.menu-attribute-management') }}</h1>
                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green add-item">{{ __('message.create') }}</a>
                            <a type="button" class="bt btn_red mass-delete ms-2">{{ __('message.delete') }}</a>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-list-container table-border-style table-responsive">
                        <table>
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center" style="width: 16px;">
                                        <input class="form-check-input item-select-all d-none d-lg-block" type="checkbox">
                                    </th>
                                    <th data-table-label="ordinal_number" class="text-center none_mobile">{{ __('message.stt') }}</th>
                                    <th data-table-label="object_code" class="text-left">{{ __('message.menu-attribute-code') }}</th>
                                    <th data-table-label="object_name" class="text-left">{{ __('message.menu-attribute-name') }}</th>
                                    <th data-table-label="object_detail" class="text-right none_mobile">{{ __('message.detail') }}</th>
                                    <th data-table-label="object_status" class="text-center none_mobile">{{ __('message.status') }}</th>
                                    <th data-table-label="object_action" class="text-center none_mobile">{{ __('message.action') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse($list as $i => $item)
                                    @php
                                        $id = (int) ($item['id'] ?? 0);
                                        $bat = (bool) ($item['is_active'] ?? false);
                                        // Đang bị biến thể mặt hàng trỏ tới thì API sẽ từ chối xoá.
                                        // Khoá nút ngay ở đây, đừng để bấm rồi mới báo lỗi.
                                        $dangDung = (bool) ($item['in_use'] ?? false);
                                        $giaTri = $item['values'] ?? [];
                                        // Bày thẳng vài giá trị đầu, dư ra gom thành "+n" — một
                                        // thuộc tính Màu sắc có ba chục giá trị sẽ kéo dòng dài
                                        // gấp mấy lần các dòng khác.
                                        $hien = array_slice($giaTri, 0, $C::SO_GIA_TRI_HIEN);
                                        $du = count($giaTri) - count($hien);
                                        $tenGiaTri = implode(', ', array_map(fn ($g) => $g['name'] ?? '', $hien));
                                    @endphp
                                    <tr class="item" data-id="{{ $id }}"
                                        data-code="{{ $item['code'] ?? '' }}"
                                        data-name="{{ $item['name'] ?? '' }}"
                                        data-status="{{ $bat ? 1 : 0 }}"
                                        data-type="attribute"
                                        data-detail="{{ json_encode(array_map(fn ($g) => [
                                            'id' => $g['id'] ?? 0, 'code' => $g['code'] ?? '', 'name' => $g['name'] ?? '',
                                        ], $giaTri), JSON_UNESCAPED_UNICODE) }}">

                                        <td class="text-center">
                                            <input class="form-check-input item-select d-none d-lg-block" type="checkbox" value="{{ $id }}">
                                        </td>
                                        <td data-table-label="ordinal_number" class="text-center none_mobile">{{ $stt + $i + 1 }}</td>
                                        <td data-table-label="object_code" class="text-left item-code">{{ $item['code'] ?? '' }}</td>
                                        <td data-table-label="object_name" class="text-left item-name">
                                            {{ $item['name'] ?? '' }}
                                            <i class="fa-solid fa-angle-right d-none"></i>
                                        </td>
                                        <td data-table-label="object_detail" class="text-right none_mobile"
                                            title="{{ implode(', ', array_map(fn ($g) => $g['name'] ?? '', $giaTri)) }}">
                                            {{ $tenGiaTri }}@if($du > 0) <b>+{{ $du }}</b>@endif
                                        </td>
                                        <td data-table-label="object_status" class="text-center none_mobile">
                                            <input type="checkbox" class="switch_customer item-status" data-id="{{ $id }}"
                                                {{ $bat ? 'checked' : '' }}>
                                        </td>
                                        <td data-table-label="object_action" class="text-center action none_mobile">
                                            <a class="edit_bt edit-item text-decoration-none" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                            @if($dangDung)
                                                <a class="dele_bt text-decoration-none" type="button"
                                                    title="Đang được biến thể hoặc định lượng dùng — không xoá được"><i class="fa fa-times text-muted"></i></a>
                                            @else
                                                <a class="dele_bt delete-item text-decoration-none" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                            @endif
                                            <a class="copy_bt copy-item text-decoration-none" type="button" title="{{ __('message.copy') }}"><i class="fa fa-copy"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center py-4">{{ $hasFilter ? 'Không có thuộc tính nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="table-list-container-mobile"></div>

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
         v2 đặt id là #modalMenuCategory ở cả màn thuộc tính — giữ nguyên. --}}
    <div class="modal" id="modalMenuCategory">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 60%">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add') }} / {{ __('message.edit') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center row">
                        <input type="hidden" class="id">
                        <div class="col-12 col-sm-6">
                            <label class="form-label">{{ __('message.menu-attribute-code') }}</label>
                            {{-- Bỏ trống thì API tự đặt mã — bản v2 bắt gõ tay. --}}
                            <input type="text" class="form-control code" maxlength="20"
                                placeholder="{{ __('message.menu-attribute-code') }}">
                        </div>
                        <div class="mt-3 mt-sm-0 col-12 col-sm-6">
                            <label class="form-label">{{ __('message.menu-attribute-name') }} <span class="required" style="color:red">*</span></label>
                            <input type="text" class="form-control name" maxlength="100"
                                placeholder="{{ __('message.menu-attribute-name') }}">
                        </div>

                        <div class="mt-3">
                            <div class="input-group attributeStatus">
                                <label class="form-label d-block mx-md-2">{{ __('message.status') }}</label>
                                <input type="checkbox" class="switch_customer ms-2 status" checked>
                            </div>
                        </div>

                        <div class="mt-3">
                            <a type="button" class="btn btn-warning btn-sm add-detail-attribute">{{ __('message.add-detail') }}</a>
                        </div>

                        <div class="mt-3" style="justify-content: flex-end">
                            <table>
                                <tbody id="menu-attribute-detail"></tbody>
                            </table>
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
        <div class="offcanvas-body" style="height: calc(100vh - 58px);">
            <div class="modal_center" style="padding: 0;">
                <input type="hidden" class="id">
                <div>
                    <label class="form-label">{{ __('message.menu-attribute-code') }}</label>
                    <input type="text" class="form-control code" disabled placeholder="{{ __('message.menu-attribute-code') }}">
                </div>
                <div class="mt-3">
                    <label class="form-label">{{ __('message.menu-attribute-name') }}</label>
                    <input type="text" class="form-control name" disabled placeholder="{{ __('message.menu-attribute-name') }}">
                </div>
                <div class="mt-3">
                    <div class="input-group">
                        <label class="form-label d-block mx-2">{{ __('message.status') }}</label>
                        <input type="checkbox" class="switch_customer status" disabled>
                    </div>
                </div>
                <div class="mt-3">
                    <table>
                        <tbody id="menu-attribute-detail1"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const CSRF_TT = '{{ csrf_token() }}';
        const URL_TT = @json(url('/admin/attributes'));
        const URL_TT_STORE = @json(route('admin.thuoc-tinh.store'));
        const URL_TT_BULK = @json(route('admin.thuoc-tinh.bulkDestroy'));

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        function postForm(action, method, fields) {
            const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
            const them = (n, v) => $f.append($('<input>', { type: 'hidden', name: n, value: v == null ? '' : v }));
            them('_token', CSRF_TT);
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
        let timerTT = null;
        $loc.on('input', 'input[name="keyword"]', function () {
            clearTimeout(timerTT);
            timerTT = setTimeout(() => $loc.trigger('submit'), 400);
        });

        // ---------- Hộp Thêm / Sửa ----------
        const $hop = $('#modalMenuCategory');
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        /** Một dòng giá trị con — khuôn `tr.d-flex.item-detail` của v2. */
        function dongGiaTri(g, giuId) {
            return `<tr class="d-flex item-detail" ${giuId && g && g.id ? 'data-id="' + g.id + '"' : ''}>
                <td class="w-75">
                    <input type="text" class="form-control code-detail" maxlength="32"
                        placeholder="{{ __('message.code') }}" value="${g ? esc(g.code) : ''}">
                </td>
                <td class="w-75">
                    <input type="text" class="form-control detail" maxlength="100"
                        placeholder="{{ __('message.detail') }} {{ __('message.menu-attribute') }}" value="${g ? esc(g.name) : ''}">
                </td>
                <td class="w-25 align-content-center">
                    <a class="dele_bt delete-detail" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                </td>
            </tr>`;
        }

        function moHop(mode, $tr) {
            const giuId = mode === 'edit';
            $hop.find('.id').val(giuId ? $tr.data('id') : '');
            // Nhân bản: bê giá trị con sang nhưng bỏ hết id và mã, để API đặt mã mới —
            // hai thuộc tính trùng mã là lúc khai biến thể không biết chọn cái nào.
            $hop.find('.code').val(giuId ? ($tr.attr('data-code') || '') : '');
            $hop.find('.name').val($tr ? ($tr.attr('data-name') || '') + (mode === 'copy' ? ' copy' : '') : '');
            $hop.find('.status').prop('checked', $tr ? String($tr.data('status')) === '1' : true);

            const $bang = $('#menu-attribute-detail').empty();
            if ($tr) {
                let ds = [];
                try { ds = JSON.parse($tr.attr('data-detail') || '[]'); } catch (e) { ds = []; }
                ds.forEach((g) => $bang.append(dongGiaTri(giuId ? g : { code: '', name: g.name }, giuId)));
            }

            $hop.modal('show');
        }

        $(document).on('click', '.add-item', () => moHop('add', null));
        $(document).on('click', '.edit-item', function () { moHop('edit', $(this).closest('tr.item')); });
        $(document).on('click', '.copy-item', function () { moHop('copy', $(this).closest('tr.item')); });

        $(document).on('click', '.add-detail-attribute', function () {
            $('#menu-attribute-detail').append(dongGiaTri(null, false));
            $('#menu-attribute-detail tr:last .detail').focus();
        });

        // Xoá dòng giá trị chỉ bỏ khỏi bảng; xoá thật là lúc bấm Lưu, vì API nhận
        // NGUYÊN bộ giá trị sau khi sửa chứ không nhận từng lệnh xoá lẻ.
        $(document).on('click', '#menu-attribute-detail .delete-detail', function () {
            $(this).closest('tr').remove();
        });

        $(document).on('click', '#modalMenuCategory .save-item', function () {
            const id = $hop.find('.id').val();
            const ten = $hop.find('.name').val().trim();
            if (!ten) { toastr.error('Nhập tên thuộc tính.'); return; }

            const f = {
                code: $hop.find('.code').val().trim(),
                name: ten,
                is_active: $hop.find('.status').is(':checked') ? 1 : 0,
            };

            // Dòng bỏ trống tên coi như bấm thêm rồi để đấy — controller cũng lọc lại.
            let i = 0;
            $('#menu-attribute-detail tr.item-detail').each(function () {
                const ten = $(this).find('.detail').val().trim();
                if (!ten) return;
                const idCon = $(this).attr('data-id');
                if (idCon) f['values[' + i + '][id]'] = idCon;
                f['values[' + i + '][code]'] = $(this).find('.code-detail').val().trim();
                f['values[' + i + '][name]'] = ten;
                i++;
            });

            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI cho người dùng sửa (VD trùng
            // tên), thay vì tải lại trang làm mất sạch bảng giá trị vừa gõ.
            V2.luuHop($hop, id ? URL_TT + '/' + id : URL_TT_STORE, id ? 'PUT' : 'POST', f, $(this));
        });

        // ---------- Công tắc trên bảng ----------
        $(document).on('change', '.list .item-status', function () {
            V2.ghi(URL_TT + '/' + $(this).data('id') + '/status', 'PUT', { is_active: this.checked ? 1 : 0 });
        });

        // ---------- Chọn dòng + xoá ----------
        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        $(document).on('click', '.delete-item', function () {
            $('#deleteValue').val($(this).closest('tr.item').data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            const ids = $('.item-select:checked').map((i, el) => el.value).get();
            if (!ids.length) { toastr.error('{{ __('message.delete-none') }}'); return; }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#deleteItem .delete-value', function () {
            const ids = String($('#deleteValue').val()).split(',').filter(Boolean);
            if (!ids.length) return;
            ids.length === 1
                ? postForm(URL_TT + '/' + ids[0], 'DELETE', {})
                : postForm(URL_TT_BULK, 'POST', { 'ids[]': ids });
        });
    </script>
@endpush
