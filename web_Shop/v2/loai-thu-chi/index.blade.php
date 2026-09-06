{{-- Màn Loại thu chi — chép khuôn từ bản v2 cũ
     (ordertable/v2/resources/views/cashbook/type/index.blade.php + list-in + list-ex).

     Giữ đúng dáng của v2: hai bảng đứng cạnh nhau, trái là Phân loại thu, phải là
     Phân loại chi; mỗi bảng có ô tìm riêng và nút Thêm riêng; một hộp thoại dùng
     chung cho thêm/sửa với đúng MỘT ô tên. Loại hệ thống (is_default) không có
     nút sửa/xoá — bản v2 chỉ giấu ở bảng chi, bảng thu quên; bên này giấu cả hai.

     Danh sách nhỏ (mỗi bên vài chục dòng), không phân trang: lọc ngay trên
     trình duyệt, gõ tới đâu ẩn dòng tới đó. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\LoaiThuChiController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\LoaiThuChiController::class;
@endphp

@push('styles')
    <style>
        /* Mỗi khung là một thẻ trắng riêng, cao theo số dòng của chính nó — không kéo
           khung thu dài bằng khung chi như v2 cũ. */
        .index-income-expense-type-page .content_midd { padding-bottom: 4px; }
        .index-income-expense-type-page .searchContainer { gap: 6px; }
        .index-income-expense-type-page .searchContainer .form-control { min-width: 140px; }
        .index-income-expense-type-page table { width: 100%; table-layout: fixed; }
        /* Mọi cột đặt %, tổng đúng 100 — bỏ trống một cột là bảng hở khoảng chết. */
        .index-income-expense-type-page th.cot-stt { width: 12%; }
        .index-income-expense-type-page th.cot-ten { width: 68%; }
        .index-income-expense-type-page th.cot-hanh-dong { width: 20%; }
        .index-income-expense-type-page td.item-name { white-space: normal; word-break: break-word; }
    </style>
@endpush

@section('content')
    {{-- Hai KHUNG TRẮNG riêng, có khoảng hở ở giữa — đúng dáng v2 cũ (.expense-content
         > .row.row-gap-2 > .col-md-6 > .content_midd). Không có tiêu đề trang bọc
         ngoài; tên mỗi khung là tiêu đề của chính nó.

         Cả hai khung nằm trong MỘT .list: vỏ v2 nạp lại trang bằng cách thay
         ruột của .list đầu tiên, tách hai .list là khung chi không bao giờ đổi
         sau khi lưu. Thẻ .list ở đây đứng ngoài .content_midd nên không ăn CSS
         khung cuộn của v2. --}}
    <div class="list index-income-expense-type-page">
        <div class="row row-gap-2">
            @foreach ([$C::LOAI_THU => $thu, $C::LOAI_CHI => $chi] as $loai => $danhSach)
                @php $laThu = $loai === $C::LOAI_THU; @endphp
                <div class="col-12 col-md-6 bang-loai" data-loai="{{ $loai }}">
                    <div class="content_midd table-border-style">
                        <div class="content_midd_title">
                            <h4>{{ $laThu ? __('message.income_category') : __('message.expense_category') }}</h4>
                            {{-- Lọc realtime, nút Thêm đứng cuối thanh lọc — quy tắc chung mọi trang danh sách. --}}
                            <div class="d-flex searchContainer">
                                <input type="text" class="form-control tim-loai" autocomplete="off"
                                    data-loai="{{ $loai }}" placeholder="{{ __('message.search') }}">
                                <a type="button" class="bt btn_green add-item text-nowrap" data-loai="{{ $loai }}">{{ __('message.add') }}</a>
                            </div>
                        </div>

                        <div class="table-list-container" style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr class="header-table-list">
                                        <th class="text-center cot-stt">{{ __('message.stt') }}</th>
                                        <th class="text-left cot-ten">{{ $laThu ? __('message.income_category_name') : __('message.expense_category_name') }}</th>
                                        <th class="text-center cot-hanh-dong"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($danhSach as $i => $item)
                                        @php
                                            $id = (int) ($item['id'] ?? 0);
                                            $heThong = (bool) ($item['is_default'] ?? false);
                                        @endphp
                                        <tr class="item" data-id="{{ $id }}" data-loai="{{ $loai }}"
                                            data-name="{{ $item['name'] ?? '' }}">
                                            <td class="text-center stt">{{ $i + 1 }}</td>
                                            <td class="text-left item-name">{{ $item['name'] ?? '' }}</td>
                                            <td class="text-center action">
                                                {{-- Loại hệ thống: phiếu tự sinh (bán hàng, trả hàng…) trỏ vào đây, không sửa/xoá được — v2 để trống ô. --}}
                                                @unless ($heThong)
                                                    <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                                    <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                                @endunless
                                            </td>
                                        </tr>
                                    @empty
                                        <tr class="dong-trong">
                                            <td colspan="3" class="text-center py-4">{{ $laThu ? $C::EMPTY_THU : $C::EMPTY_CHI }}</td>
                                        </tr>
                                    @endforelse
                                    {{-- Hiện khi gõ tìm mà không dòng nào khớp. --}}
                                    <tr class="dong-khong-khop d-none">
                                        <td colspan="3" class="text-center py-4">Không có phân loại nào khớp từ đang tìm.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ===================== Hộp Thêm / Sửa =====================
         v2 đặt id #addTypeIncomeExpense, một ô tên duy nhất — giữ nguyên. --}}
    <div class="modal" id="addTypeIncomeExpense" data-mode="">
        <div class="modal-dialog modal-dialog-centered mx-auto" style="min-width: 30%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add_income_category') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center px-2">
                        <input type="hidden" class="id">
                        <input type="hidden" class="loai">
                        <div>
                            <label class="form-label nhan-ten">{{ __('message.income_category_name') }} <span class="required" style="color:red">*</span></label>
                            <input type="text" class="form-control name" maxlength="255"
                                placeholder="{{ __('message.enter_income_category_name') }}">
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
@endsection

@push('scripts')
    <script>
        const URL_LTC = @json(url('/admin/cashbook/categories'));
        const URL_LTC_STORE = @json(route('admin.loai-thu-chi.store'));
        const LOAI_THU = {{ $C::LOAI_THU }};
        const CHU = {
            [LOAI_THU]: {
                them: @json(__('message.add_income_category')),
                ten: @json(__('message.income_category_name')),
                goi: @json(__('message.enter_income_category_name')),
            },
            [{{ $C::LOAI_CHI }}]: {
                them: @json(__('message.add_expense_category')),
                ten: @json(__('message.expense_category_name')),
                goi: @json(__('message.enter_expense_category_name')),
            },
        };

        // ---------- Lọc tại chỗ: gõ tới đâu ẩn dòng tới đó, đánh lại số thứ tự ----------
        // Nhớ từ khoá theo từng bảng: sau khi lưu, vỏ v2 thay ruột .list nên ô tìm
        // bị dựng lại trống — điền lại để người đang lọc dở không mất chỗ.
        const tuKhoa = {};

        function locBang(loai) {
            const $bang = $('.bang-loai[data-loai="' + loai + '"]');
            const q = (tuKhoa[loai] || '').trim().toLowerCase();
            let thay = 0;
            $bang.find('tr.item').each(function () {
                const khop = !q || String($(this).attr('data-name') || '').toLowerCase().includes(q);
                $(this).toggleClass('d-none', !khop);
                if (khop) $(this).find('.stt').text(++thay);
            });
            const coDong = $bang.find('tr.item').length > 0;
            $bang.find('.dong-khong-khop').toggleClass('d-none', !(coDong && thay === 0));
        }

        let timerLTC = null;
        $(document).on('input', '.tim-loai', function () {
            const loai = $(this).data('loai');
            tuKhoa[loai] = this.value;
            clearTimeout(timerLTC);
            timerLTC = setTimeout(() => locBang(loai), 150);
        });

        $(document).on('v2:da-nap', function () {
            $('.tim-loai').each(function () {
                const loai = $(this).data('loai');
                $(this).val(tuKhoa[loai] || '');
                locBang(loai);
            });
        });

        // ---------- Hộp Thêm / Sửa ----------
        const $hop = $('#addTypeIncomeExpense');

        function moHop(mode, loai, $tr) {
            const chu = CHU[loai];
            $hop.attr('data-mode', mode);
            $hop.find('.id').val(mode === 'edit' ? $tr.data('id') : '');
            $hop.find('.loai').val(loai);
            $hop.find('.modal-title').text(mode === 'edit' ? @json(__('message.edit')) : chu.them);
            $hop.find('.nhan-ten').html(chu.ten + ' <span class="required" style="color:red">*</span>');
            $hop.find('.name').val(mode === 'edit' ? ($tr.attr('data-name') || '') : '').attr('placeholder', chu.goi);
            $hop.modal('show');
        }

        $hop.on('shown.bs.modal', () => $hop.find('.name').trigger('focus'));

        $(document).on('click', '.add-item', function () { moHop('add', $(this).data('loai'), null); });
        $(document).on('click', '.edit-item', function () {
            const $tr = $(this).closest('tr.item');
            moHop('edit', $tr.data('loai'), $tr);
        });

        // Enter trong ô tên = bấm Lưu.
        $hop.on('keydown', '.name', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $hop.find('.save-item').trigger('click'); }
        });

        $(document).on('click', '#addTypeIncomeExpense .save-item', function () {
            const id = $hop.find('.id').val();
            const ten = $hop.find('.name').val().trim();
            if (!ten) { toastr.error(CHU[$hop.find('.loai').val()].goi + '.'); return; }

            // Lưu bằng AJAX: hỏng (trùng tên) thì GIỮ HỘP LẠI cho người dùng sửa.
            V2.luuHop($hop, id ? URL_LTC + '/' + id : URL_LTC_STORE, id ? 'PUT' : 'POST', {
                type: $hop.find('.loai').val(),
                name: ten,
            }, $(this));
        });

        // ---------- Xoá ----------
        $(document).on('click', '.delete-item', function () {
            $('#deleteValue').val($(this).closest('tr.item').data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#deleteItem .delete-value', function () {
            const id = $('#deleteValue').val();
            if (!id) return;
            // Đi đường hộp thoại (JSON): xoá xong hộp tự đóng và bắn toast; hỏng
            // (loại đang có phiếu trỏ vào) thì hộp giữ nguyên và báo lý do.
            V2.luuHop($('#deleteItem'), URL_LTC + '/' + id, 'DELETE', {}, $(this));
        });
    </script>
@endpush
