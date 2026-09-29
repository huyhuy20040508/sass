{{-- CRM → Thẻ thành viên — dựng theo bản v2 đang chạy (crm/membership-rank:
     index + list + sidebar), mục "Giảm giá theo xếp hạng".

     Đầu trang có hai quy đổi: "Quy đổi tiền ra điểm" (khách tích điểm khi thanh
     toán) và "Áp dụng quy đổi điểm" (ô tick bật / tắt đổi điểm ra tiền ở quầy).
     Bảng hạng: điểm tích luỹ tối thiểu (kèm số tiền tương ứng), mức giảm, số
     khách đang ở hạng; bấm tên hạng mở trang chi tiết.

     Bảng KHÔNG xuống dòng (luật đã chốt cho mọi bảng v2). --}}
@extends('v2::layouts.master')

@section('title', 'Thẻ thành viên')

@php
    $tienMoiDiemTich = ! empty($conversion['earn_point']) ? (float) $conversion['earn_money'] / (int) $conversion['earn_point'] : 0;
    $tienMoiDiemDoi = ! empty($conversion['redeem_point']) ? (float) $conversion['redeem_money'] / (int) $conversion['redeem_point'] : 0;
@endphp

@push('styles')
    <style>
        .tv-sidebar a { display: block; border: 1px solid #dee2e6; border-radius: 6px; padding: 8px; font-weight: 700; color: #212529; text-decoration: none; }
        .tv-sidebar a.active { color: #F39320; }
        .tv-quy-doi { display: inline-flex; align-items: center; gap: 10px; color: inherit; text-decoration: none; font-size: 14px; cursor: pointer; }
        .tv-quy-doi .fa-pencil { font-size: 13px; }
        table.tv-bang { width: 100%; table-layout: auto; }
        table.tv-bang th, table.tv-bang td { white-space: nowrap; vertical-align: middle; padding: 8px; font-size: 14px; }
        table.tv-bang th { height: 42px; font-weight: 700; }
        table.tv-bang tr.item { height: 53px; }
        table.tv-bang td.action a { padding: 0 3px; cursor: pointer; }
        .tv-hop .form-label { font-size: 14px; }
        .tv-hop .form-check { display: inline-flex; align-items: center; gap: 6px; padding-left: 0; }
        .tv-hop .form-check .form-check-input { margin: 0; zoom: 1.3; }
    </style>
@endpush

@section('content')
    <div class="row index-setting-crm.membership-rank-page">
        <div class="col-12 col-lg-2 pe-lg-0 fillter-box-wrapper">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">Cấu Hình Giảm Giá</div>
                    <div class="card-body px-2 tv-sidebar">
                        <a href="{{ route('admin.crm.membership.index') }}" class="active">Giảm giá theo xếp hạng</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-10">
            <div class="content_midd">
                <div class="content_midd_title d-flex flex-column flex-lg-row align-items-lg-start align-items-xl-center">
                    <h1 class="tieu-de-trang text-nowrap">Giảm giá theo xếp hạng</h1>
                    <div class="d-flex flex-wrap align-items-center justify-content-lg-end gap-3 w-100">
                        <div class="d-flex flex-wrap align-items-center gap-4">
                            <a class="tv-quy-doi mo-quy-doi" data-kind="earn">
                                <span>Quy đổi tiền ra điểm: {{ number_format((float) ($conversion['earn_money'] ?? 0)) }}đ = {{ number_format((int) ($conversion['earn_point'] ?? 0)) }} điểm</span>
                                <i class="fa fa-pencil"></i>
                            </a>
                            <span class="tv-quy-doi">
                                <input type="checkbox" class="form-check-input m-0" id="tv-bat-doi" {{ ! empty($conversion['redeem_enabled']) ? 'checked' : '' }}>
                                <a class="tv-quy-doi mo-quy-doi" data-kind="redeem">
                                    <span>Áp dụng quy đổi điểm: 1={{ number_format((int) round($tienMoiDiemDoi)) }}đ</span>
                                    <i class="fa fa-pencil"></i>
                                </a>
                            </span>
                        </div>
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green add-item mx-2">Tạo</a>
                            <a type="button" class="bt btn_red mass-delete">Xóa</a>
                        </div>
                    </div>
                </div>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-striped tv-bang">
                            <thead>
                                <tr class="header-table-list">
                                    <th class="text-center"><input class="form-check-input item-select-all" type="checkbox"></th>
                                    <th class="text-left">Thẻ thành viên</th>
                                    <th class="text-right">Điểm tích lũy tối thiểu</th>
                                    <th class="text-right">Giảm giá</th>
                                    <th class="text-right">Số thành viên</th>
                                    <th class="text-center">Chỉnh sửa</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($ranks as $r)
                                    <tr class="item" data-id="{{ (int) $r['id'] }}">
                                        <td class="text-center"><input class="form-check-input item-select" type="checkbox"></td>
                                        <td class="text-left"><a class="text-decoration-none" href="{{ route('admin.crm.membership.detail', $r['id']) }}">{{ $r['name'] }}</a></td>
                                        <td class="text-right">
                                            {{ $r['point'] }}
                                            @if ($tienMoiDiemTich > 0)
                                                <div class="small text-muted">({{ number_format($r['point'] * $tienMoiDiemTich) }}đ)</div>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ number_format((float) $r['discount_value']) }} {{ $r['discount_type'] === 'percent' ? '%' : '' }}</td>
                                        <td class="text-right">{{ (int) ($r['member_count'] ?? 0) }}</td>
                                        <td class="text-center action">
                                            <a class="edit_bt edit-item" title="Chỉnh sửa"><i class="fa fa-edit"></i></a>
                                            <a class="dele_bt delete-item" title="Xoá"><i class="fa fa-times"></i></a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="text-center py-4">Chưa có hạng thành viên nào. Bấm "Tạo" để thêm hạng đầu tiên.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <script type="application/json" id="v2-rows">@json(collect($ranks)->keyBy('id'))</script>
                    <div class="form_pagi">@include('v2::partials.pagination', ['meta' => $meta])</div>
                </div>
                <select class="form-control item-per-page select-width {{ count($ranks) ? '' : 'd-none' }}" data-param="page_size">
                    @foreach (\App\Http\Controllers\MembershipController::PAGE_SIZES as $muc)
                        <option value="{{ $muc }}" {{ $pageSize == $muc ? 'selected' : '' }}>{{ __('message.display', ['name' => $muc]) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Quy đổi tiền ra điểm (tích điểm) --}}
    <div class="modal tv-hop" id="tvEarn">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 48%">
            <div class="modal-content">
                <div class="modal-header"><h4 class="modal-title">Quy đổi tiền ra điểm</h4><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="fw-bold" style="margin-bottom: 10px;">Tích điểm (Tiền → Điểm)</div>
                    <div class="row align-items-end">
                        <div class="col-12 col-md-4"><label class="form-label">Tiền</label>
                            <input type="text" class="form-control tv-tien" id="tv-earn-money" value="{{ number_format((float) ($conversion['earn_money'] ?? 0)) }}"></div>
                        <div class="col-12 col-md-4"><label class="form-label">Số điểm</label>
                            <input type="number" min="1" class="form-control" id="tv-earn-point" value="{{ (int) ($conversion['earn_point'] ?? 0) }}"></div>
                        <div class="col-8 col-md-2"><label class="form-label d-block">Trạng thái</label>
                            <input type="checkbox" class="switch_customer" id="tv-earn-bat" {{ ! empty($conversion['earn_enabled']) ? 'checked' : '' }}></div>
                        <div class="col-4 col-md-2"><a type="button" class="bt btn_green w-100 tv-luu-quy-doi" data-kind="earn">Lưu</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Áp dụng quy đổi điểm (đổi điểm ra tiền ở quầy) --}}
    <div class="modal tv-hop" id="tvRedeem">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 48%">
            <div class="modal-content">
                <div class="modal-header"><h4 class="modal-title">Áp dụng quy đổi điểm</h4><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="fw-bold" style="margin-bottom: 10px;">Đổi điểm (Điểm → Tiền)</div>
                    <div class="row align-items-end">
                        <div class="col-12 col-sm-4"><label class="form-label">Số điểm</label>
                            <input type="number" min="1" class="form-control" id="tv-redeem-point" value="{{ (int) ($conversion['redeem_point'] ?? 0) }}"></div>
                        <div class="col-12 col-sm-4 mt-2 mt-sm-0"><label class="form-label">Tiền</label>
                            <input type="text" class="form-control tv-tien" id="tv-redeem-money" value="{{ number_format((float) ($conversion['redeem_money'] ?? 0)) }}"></div>
                        <div class="col-12 col-sm-4"><a type="button" class="bt btn_green w-100 mt-3 mt-sm-0 tv-luu-quy-doi" data-kind="redeem">Lưu</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Thêm mới / Chỉnh sửa hạng --}}
    <div class="modal tv-hop" id="tvHang" data-id="">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 30%">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title" id="tv-tieu-de">Thêm mới</h4>
                    <a class="ms-auto" href="#" data-bs-dismiss="modal"><i class="fa fa-times" aria-hidden="true"></i></a>
                </div>
                <div class="modal-body">
                    <div>
                        <label class="form-label">Tên hạng <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="tv-ten" maxlength="100" autocomplete="off">
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Số điểm <span class="text-danger">*</span></label>
                        <input type="number" min="1" class="form-control" id="tv-diem">
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Giá trị <span class="text-danger">*</span></label>
                        <div class="row align-items-center">
                            <div class="col-12 col-sm-5">
                                <div class="form-check mt-1 ms-1"><input class="form-check-input" type="radio" name="tv-kieu" id="tv-kieu-tien" value="money" checked><label for="tv-kieu-tien">Tiền</label></div>
                                <div class="form-check mt-1 ms-4"><input class="form-check-input" type="radio" name="tv-kieu" id="tv-kieu-pt" value="percent"><label for="tv-kieu-pt">Phần trăm</label></div>
                            </div>
                            <div class="col-12 col-sm-7"><input type="text" class="form-control" id="tv-gia-tri" autocomplete="off"></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label d-block">Áp dụng <span class="text-danger">*</span></label>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="tv-tat-ca" checked><label for="tv-tat-ca">Tất cả các giá trị đơn hàng</label></div>
                        <div class="row align-items-center">
                            <div class="col-12 col-sm-4 mb-2 mb-sm-0"><div class="form-check"><input class="form-check-input" type="checkbox" id="tv-khoang"><label for="tv-khoang">Giá trị đơn hàng áp dụng</label></div></div>
                            <div class="col-12 col-sm-4 mb-2 mb-sm-0"><input type="text" class="form-control tv-tien" id="tv-tu" placeholder="Từ" disabled></div>
                            <div class="col-12 col-sm-4"><input type="text" class="form-control tv-tien" id="tv-den" placeholder="Đến" disabled></div>
                        </div>
                    </div>
                    <div class="d-flex mt-3">
                        <label class="form-label d-block">Trạng thái</label>
                        <input type="checkbox" class="switch_customer ms-1" id="tv-bat" checked>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="bt btn_green" id="tv-luu">Lưu</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="deleteItem" data-ids="">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title">Xóa ?</h6><a class="ms-auto" href="#" data-bs-dismiss="modal"><i class="fa fa-times"></i></a></div>
                <div class="modal-body"><label class="form-label">Bạn có chắc muốn xóa?</label></div>
                <div class="modal-footer">
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="bt btn_red" id="xoa-xac-nhan">Xóa</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_TV = @json(url('/admin/membership-ranks'));
        const URL_TV_TAO = @json(route('admin.membership.store'));
        const URL_TV_XOA = @json(route('admin.membership.destroy'));
        const URL_TV_QD = @json(route('admin.membership.conversion'));
        const QD = @json($conversion);

        let TV = docDong();
        function docDong() {
            try { return JSON.parse(document.getElementById('v2-rows').textContent) || {}; } catch (e) { return {}; }
        }
        $(document).on('v2:da-nap', function () { TV = docDong(); });

        const chuSo = (v) => String(v == null ? '' : v).replace(/[^0-9]/g, '');
        const soPhay = (v) => (chuSo(v) === '' ? '' : Number(chuSo(v)).toLocaleString('en-US'));
        $(document).on('input', '.tv-tien', function () { this.value = soPhay(this.value); });

        // ================= Quy đổi điểm =================
        $(document).on('click', '.mo-quy-doi', function (e) {
            e.preventDefault();
            $($(this).data('kind') === 'earn' ? '#tvEarn' : '#tvRedeem').modal('show');
        });
        function luuQuyDoi(kind, $nut, bat) {
            const earn = kind === 'earn';
            V2.luuHop(earn ? '#tvEarn' : '#tvRedeem', URL_TV_QD, 'PUT', {
                kind: kind,
                money: chuSo($(earn ? '#tv-earn-money' : '#tv-redeem-money').val()),
                point: $(earn ? '#tv-earn-point' : '#tv-redeem-point').val(),
                enabled: (earn ? $('#tv-earn-bat').is(':checked') : bat) ? 1 : 0,
            }, $nut);
        }
        $(document).on('click', '.tv-luu-quy-doi', function () {
            luuQuyDoi($(this).data('kind'), $(this), $('#tv-bat-doi').is(':checked'));
        });
        // Ô tick "Áp dụng quy đổi điểm" bật / tắt đổi điểm ngay, như v2.
        $(document).on('change', '#tv-bat-doi', function () { luuQuyDoi('redeem', null, this.checked); });

        // ================= Thêm / Sửa hạng =================
        const $hop = $('#tvHang');
        function dongBoKhoang(tuKhoang) {
            const khoang = tuKhoang ? $('#tv-khoang').is(':checked') : !$('#tv-tat-ca').is(':checked');
            $('#tv-tat-ca').prop('checked', !khoang);
            $('#tv-khoang').prop('checked', khoang);
            $('#tv-tu, #tv-den').prop('disabled', !khoang);
        }
        $('#tv-tat-ca').on('change', () => dongBoKhoang(false));
        $('#tv-khoang').on('change', () => dongBoKhoang(true));
        $('#tv-gia-tri').on('input', function () {
            let v = chuSo(this.value);
            if ($('input[name="tv-kieu"]:checked').val() === 'percent' && Number(v) > 100) v = '100';
            this.value = soPhay(v);
        });
        $('input[name="tv-kieu"]').on('change', () => $('#tv-gia-tri').trigger('input'));

        function moHop(r) {
            $hop.attr('data-id', r ? r.id : '');
            $('#tv-tieu-de').text(r ? 'Chỉnh sửa' : 'Thêm mới');
            $('#tv-ten').val(r ? r.name : '');
            $('#tv-diem').val(r ? r.point : '');
            $(r && r.discount_type === 'percent' ? '#tv-kieu-pt' : '#tv-kieu-tien').prop('checked', true);
            $('#tv-gia-tri').val(r ? soPhay(Math.round(r.discount_value)) : '');
            $('#tv-tat-ca').prop('checked', r ? !!r.apply_all_order_values : true);
            dongBoKhoang(false);
            $('#tv-tu').val(r && r.min_order_value != null ? soPhay(Math.round(r.min_order_value)) : '');
            $('#tv-den').val(r && r.max_order_value != null ? soPhay(Math.round(r.max_order_value)) : '');
            $('#tv-bat').prop('checked', r ? !!r.status : true);
            $hop.modal('show');
        }
        $(document).on('click', '.add-item', () => moHop(null));
        $(document).on('click', '.edit-item', function () { moHop(TV[$(this).closest('.item').data('id')]); });
        $('#tv-luu').on('click', function () {
            const id = $hop.attr('data-id');
            const tatCa = $('#tv-tat-ca').is(':checked');
            V2.luuHop($hop, id ? URL_TV + '/' + id : URL_TV_TAO, id ? 'PUT' : 'POST', {
                name: $('#tv-ten').val().trim(),
                point: $('#tv-diem').val(),
                discount_type: $('input[name="tv-kieu"]:checked').val(),
                discount_value: chuSo($('#tv-gia-tri').val()),
                apply_all_order_values: tatCa ? 1 : 0,
                min_order_value: tatCa ? '' : chuSo($('#tv-tu').val()),
                max_order_value: tatCa ? '' : chuSo($('#tv-den').val()),
                status: $('#tv-bat').is(':checked') ? 1 : 0,
            }, $(this));
        });

        // ================= Xoá (một dòng hoặc các dòng đã tick) =================
        $(document).on('click', '.item-select-all', function () { $('.item-select').prop('checked', this.checked); });
        function moXoa(ids) {
            if (!ids.length) { toastr.error('Vui lòng chọn hạng cần xoá.'); return; }
            $('#deleteItem').data('ids', ids).modal('show');
        }
        $(document).on('click', '.delete-item', function () { moXoa([$(this).closest('.item').data('id')]); });
        $(document).on('click', '.mass-delete', function () {
            moXoa($('.item-select:checked').map((i, el) => $(el).closest('.item').data('id')).get());
        });
        $('#xoa-xac-nhan').on('click', function () {
            V2.luuHop('#deleteItem', URL_TV_XOA, 'POST', { 'ids[]': $('#deleteItem').data('ids') }, $(this));
        });
    </script>
@endpush
