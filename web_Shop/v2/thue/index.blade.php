{{-- Màn Quản lý thuế — chép khuôn từ bản v2 cũ
     (ordertable/v2/resources/views/menu/tax/index.blade.php + list.blade.php).

     Giữ nguyên lớp bọc và tên class của v2: `.row.index-tax-page` → `.content_midd`
     → `.list.scrollDiv`, bảng `.table-list-container.table-border-style` với
     `data-table-label` trên từng ô, hộp `#modalTax` và tấm `#offcanvasDetail`
     cho điện thoại.

     KHÔNG chép hộp `#deleteItem` của v2: bốn loại thuế khai cứng trong mã nguồn
     API (domain.DanhMucLoaiThue), không thêm cũng không xoá được, nên hộp ấy bên
     v2 vốn đã không nối vào đâu. Giữ lại là để một nút bấm vào không có gì xảy
     ra — tệ hơn hẳn thiếu nút.

     Bảng v2 nạp bằng AJAX; bên này danh sách đã có sẵn trong `$taxes` nên dựng
     thẳng ra HTML — không đụng gì tới backend. --}}
@extends('v2::layouts.master')

@section('title', 'Quản lý thuế')

@push('styles')
    <style>
        .select2 { min-width: 100% !important; }
        .select2-container { width: 100% !important; }
        .select2-selection { overflow: auto; }
    </style>
@endpush

@php
    $rows = collect($taxes ?? []);
@endphp

@section('content')
    <div class="row index-tax-page">
        <div class="col-12">
            <div class="content_midd">
                <div class="content_midd_title">
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang, trình
                         đọc màn hình lấy nó làm mốc. Class .tieu-de-trang giữ nguyên
                         dáng chữ của h4 bên v2. --}}
                    <h1 class="tieu-de-trang">{{ __('message.manage-tax') }}</h1>
                    <div class="justify-content-end">
                        {{-- v2 để trống hàng nút ở đây: bốn loại thuế là cố định,
                             không có thêm, không có xoá. --}}
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-list-container table-border-style" style="overflow-x:auto;">
                        <table>
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center none_mobile">{{ __('message.stt') }}</th>
                                    <th data-table-label="object_code" class="text-left show_code">Mã</th>
                                    <th data-table-label="object_name" class="text-left">{{ __('message.name') }}</th>
                                    <th data-table-label="object_value" class="text-right">{{ __('message.tax-value') }}</th>
                                    <th data-table-label="object_status" class="text-center none_mobile">{{ __('message.status') }}</th>
                                    <th data-table-label="object_action" class="text-center none_mobile">{{ __('message.action') }}</th>
                                </tr>
                            </thead>

                            <tbody>
                                @forelse($rows as $key => $item)
                                    @php
                                        $id = (int) ($item['id'] ?? 0);
                                        $bat = (bool) ($item['is_active'] ?? false);
                                        $nhan = $item['muc_nhan'] ?? [];
                                    @endphp
                                    <tr class="item" data-id="{{ $id }}"
                                        data-name="{{ $item['ten'] ?? '' }}"
                                        data-desc="{{ $item['mo_ta'] ?? '' }}"
                                        data-status="{{ $bat ? 1 : 0 }}">

                                        <td data-table-label="ordinal_number" class="text-center none_mobile">{{ $key + 1 }}</td>
                                        <td data-table-label="object_code" class="text-left item-code">{{ $item['loai'] ?? '' }}</td>
                                        <td data-table-label="object_name" class="text-left item-name">
                                            {{ $item['ten'] ?? '' }}
                                            <i class="fa-solid fa-angle-right d-none"></i>
                                        </td>
                                        <td data-table-label="object_value" class="text-right item-value">
                                            {{-- Mức -1 / -2 là mã của hoá đơn điện tử (KCT / KKKNT),
                                                 không phải phần trăm âm — API đã trả sẵn nhãn. --}}
                                            {{ implode(', ', $nhan) }}
                                        </td>
                                        <td data-table-label="object_status" class="text-center none_mobile">
                                            <input type="checkbox" class="switch_customer item-status"
                                                data-id="{{ $id }}" {{ $bat ? 'checked' : '' }}>
                                        </td>
                                        <td data-table-label="object_action" class="text-center action none_mobile">
                                            <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}">
                                                <i class="fa fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            Không đọc được danh sách thuế từ máy chủ. Tải lại trang, hoặc kiểm tra kết nối API.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="table-list-container-mobile"></div>
                </div>

                <select class="form-control item-per-page select-width d-none">
                    @foreach ([10, 20, 30, 40, 50] as $muc)
                        <option value="{{ $muc }}">{{ __('message.display', ['name' => $muc]) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp sửa bộ mức ===================== --}}
    <div class="modal" id="modalTax">
        <div class="modal-dialog modal-dialog-centered" style="min-width:30%">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add') }} / {{ __('message.edit') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center">
                        <input type="hidden" class="id">
                        <div class="row">
                            <div class="col-12 mt-3">
                                {{-- Tên khoá lại y như v2: bốn loại là bốn điểm nghiệp vụ,
                                     đổi tên là mất chỗ neo. --}}
                                <label class="form-label">{{ __('message.tax-name') }} <span class="required" style="color:red">*</span></label>
                                <input type="text" class="form-control name" disabled
                                    placeholder="{{ __('message.tax-name') }}">
                                <small class="text-muted mo-ta"></small>
                            </div>
                            <div class="col-12 mt-3">
                                <label class="form-label">{{ __('message.tax-value') }} <span class="required" style="color:red">*</span></label>
                                <div class="col-12">
                                    <select name="select-tax" class="select2 form-control value" multiple="multiple"></select>
                                </div>
                            </div>
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
            </div>
        </div>
        <div class="offcanvas-body" style="height: calc(100vh - 58px);">
            <div class="modal_center" style="padding: 0;">
                <input type="hidden" class="id">
                <div class="row">
                    <div class="col-12 mt-3">
                        <label class="form-label">{{ __('message.tax-name') }}</label>
                        <input type="text" class="form-control name" disabled placeholder="{{ __('message.tax-name') }}">
                    </div>
                    <div class="col-12 mt-3">
                        <label class="form-label">{{ __('message.tax-value') }}</label>
                        <div class="col-12">
                            <select name="select-tax" class="select2 form-control value" multiple="multiple" disabled></select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        // Danh sách thuế đã có sẵn trong trang — mỗi dòng kèm bộ mức đang chọn
        // (`muc`) và bộ mức chọn được (`chon_duoc`), đều do API trả về.
        const THUE = @json($rows->values());
        const CSRF_THUE = '{{ csrf_token() }}';
        const URL_SUA = @json(route('admin.thue.update', ['id' => '__ID__']));
        const URL_TRANG_THAI = @json(route('admin.thue.toggleStatus', ['id' => '__ID__']));
        const duong = (mau, id) => mau.replace('__ID__', String(id));
        const timThue = (id) => THUE.find((t) => Number(t.id) === Number(id));

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        function postForm(action, method, fields) {
            const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
            const them = (n, v) => $f.append($('<input>', { type: 'hidden', name: n, value: v == null ? '' : v }));
            them('_token', CSRF_THUE);
            if (method && method !== 'POST') them('_method', method);
            $.each(fields || {}, (k, v) => {
                Array.isArray(v) ? v.forEach((x) => them(k, x)) : them(k, v);
            });
            $('body').append($f);
            $f.trigger('submit');
        }

        const $hop = $('#modalTax');

        function moHop(id) {
            const t = timThue(id);
            if (!t) return;

            $hop.find('.id').val(t.id);
            $hop.find('.name').val(t.ten || '');
            $hop.find('.mo-ta').text(t.mo_ta || '');

            const dangChon = (t.muc || []).map(Number);
            const $o = $hop.find('.value').empty();
            (t.chon_duoc || []).forEach((m) => {
                $o.append(new Option(m.nhan, m.gia_tri, false, dangChon.includes(Number(m.gia_tri))));
            });
            $o.trigger('change');

            $hop.modal('show');
        }

        // Select2 gắn dropdownParent, không thì danh sách xổ ra nằm dưới lớp phủ của hộp.
        $hop.on('shown.bs.modal', function () {
            $hop.find('select.value').not('.select2-hidden-accessible')
                .select2({ dropdownParent: $hop, width: '100%' });
        });

        $(document).on('click', '.edit-item', function () {
            moHop($(this).closest('tr.item').data('id'));
        });

        $(document).on('click', '#modalTax .save-item', function () {
            const muc = $hop.find('.value').val() || [];
            if (!muc.length) { toastr.error('Giữ lại ít nhất một mức thuế.'); return; }
            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI, không tải lại trang.
            V2.luuHop($hop, duong(URL_SUA, $hop.find('.id').val()), 'PUT', { 'muc[]': muc }, $(this));
        });

        // Công tắc bật/tắt ngay trên bảng — ghi tại chỗ, không tải lại trang.
        $(document).on('change', '.item-status', function () {
            V2.ghi(duong(URL_TRANG_THAI, $(this).data('id')), 'PUT', { is_active: this.checked ? 1 : 0 });
        });
    </script>
@endpush
