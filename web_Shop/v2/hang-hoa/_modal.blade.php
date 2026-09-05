{{-- HỘP TẠO / SỬA HÀNG HOÁ — chép khuôn từ #modalCreate của bản v2 cũ
     (ordertable/v2/resources/views/menu/menu/index.blade.php): cùng lớp bọc
     `modal fade` → `modal-dialog modal-xl` 95% → `nav nav-tabs nav-detail` →
     `w-100 d-flex nav-normal-info-container` (cột ảnh + công tắc bên trái, lưới
     ô bên phải) → accordion "Quy đổi đơn vị" → tab "Thuộc tính".

     Bỏ những phần bản v2 có mà nghiệp vụ bán lẻ bên này không có: tab Topping,
     tab Combo, sản phẩm dịch vụ tính giờ, hoa hồng, khoá nhóm người dùng, ô
     "loại hàng hoá" (hàng bán / nguyên vật liệu). --}}
<div class="modal fade" id="modalCreate" aria-labelledby="modalCreate" aria-modal="true" role="dialog">
    {{-- Khổ hộp giữ đúng như v2: rộng 95% màn, cao 100%. --}}
    {{-- Hai class của Bootstrap đều bị BỎ, vì cả hai đều ép khung cao hết màn
         nên hộp ít ô vẫn có thanh cuộn và một mảng trống lớn phía dưới:
           - `modal-dialog-scrollable` -> height: calc(100% - ...)
           - `modal-dialog-centered`   -> min-height: calc(100% - ...)
         Không có chúng thì khung cao đúng bằng ruột. Phần cuộn cho nội dung
         dài do `max-height` của .modal-body lo (xem CSS ở index). --}}
    <div class="modal-dialog modal-xl mx-auto" style="max-width: 95%;">
        {{-- BỎ `h-100`: nó ép thân hộp cao bằng cả màn hình, nên hộp ít ô vẫn
             hiện thanh cuộn và chừa một khoảng trống lớn phía dưới. Không có
             nó thì hộp co đúng theo nội dung, còn `modal-dialog-scrollable`
             vẫn lo phần cuộn khi nội dung thật sự dài quá màn. --}}
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ __('message.create') }}/{{ __('message.edit') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body" style="padding: 5px 0 !important;">
                <ul class="nav nav-tabs nav-detail px-3" role="tablist">
                    <li class="nav-item nav-normal" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#add-menu-normal"
                            type="button" role="tab">{{ __('message.detail') }}</button>
                    </li>
                    <li class="nav-item nav-attribute" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#attribute"
                            type="button" role="tab">{{ __('message.attribute') }}</button>
                    </li>
                </ul>

                <div class="content overflow-x-hidden tab-content">
                    <input type="hidden" id="id_menu">

                    {{-- ===================== Tab Chi tiết ===================== --}}
                    <div class="tab-pane fade show active" id="add-menu-normal" role="tabpanel">
                        <div class="w-100 d-flex py-3 nav-normal-info-container">

                            {{-- Cột trái: ảnh + dãy công tắc, đúng chỗ của v2 --}}
                            <div class="col-12 col-lg-3 col-xl-2 remove-w-20-on-mobile d-flex align-items-start justify-content-center px-2">
                                <div class="img_st w-100 flex-row flex-lg-column">
                                    <div class="uploadPhoto">
                                        <label class="d-none d-lg-block">{{ __('message.image') }}</label>
                                        <div class="d-flex justify-content-center">
                                            <div class="pic_add">
                                                <img id="img-preview" src="{{ asset('v2/images/image_defaul.png') }}">
                                            </div>
                                        </div>
                                        <div class="upload_pic mt-2">
                                            {{ __('message.upload') }}
                                            <input type="file" class="ip_img" id="formFileMultiple" accept="image/*">
                                        </div>
                                    </div>

                                    {{-- KHÔNG dùng class .type-product: nó kẻ một khung viền
                                         quanh cả khối (responsive-manager.css). Bên v2 khung đó
                                         bọc hai nút chọn loại hàng + dãy công tắc nên có nghĩa;
                                         bán lẻ không có nút chọn loại, còn trơ mấy cái công tắc
                                         trong một cái hộp không đầu không cuối. --}}
                                    <div class="cong-tac ms-2 ms-lg-0">
                                        <div class="my-2 d-flex justify-content-between mx-2 status-responsive">
                                            <label class="form-label d-block" for="ip_status">{{ __('message.status') }}</label>
                                            <input type="checkbox" class="ms-2 switch_customer ip_status" id="ip_status" checked>
                                        </div>
                                        <div class="mt-2 d-flex justify-content-between mx-2 content_print_label">
                                            <label class="form-label d-block" for="print_label">{{ __('message.print_label') }}</label>
                                            <input type="checkbox" class="ms-2 switch_customer print_label" id="print_label" checked>
                                        </div>
                                        <div class="mt-2 d-flex justify-content-between mx-2 div_is_stock_deducted">
                                            <label class="form-label" for="is_stock_deducted">{{ __('message.deduct_inventory') }}</label>
                                            <input type="checkbox" class="ms-2 switch_customer is_stock_deducted" id="is_stock_deducted" checked>
                                        </div>
                                        <div class="mt-2 d-flex justify-content-between mx-2 div_is_serial">
                                            <label class="form-label" for="is_serial">Số seri / IMEI</label>
                                            <input type="checkbox" class="ms-2 switch_customer is_serial" id="is_serial">
                                        </div>
                                    </div>

                                </div>
                            </div>

                            {{-- Cột phải: lưới ô, mỗi hàng ba ô như v2 --}}
                            <div class="col-12 col-lg-9 col-xl-10 data-body px-2 py-3 py-lg-0" id="data-body">
                                <div class="row data-body-container">
                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">{{ __('message.goods-code') }}
                                            @unless($maTuSinh)<span class="required" style="color:red">*</span>@endunless
                                        </label>
                                        <input type="text" class="form-control ip_code" name="code"
                                            placeholder="{{ $maTuSinh ? __('message.auto-increment-code') : __('message.product-code') }}"
                                            {{ $maTuSinh ? 'disabled data-khoa-san' : '' }}>
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">Bar/Qr Code</label>
                                        <div class="inner-modal-in-mobile input-group" style="width: 100%;">
                                            <input type="text" class="form-control ip_barcode" name="barcode"
                                                placeholder="{{ __('message.barcode') }}">
                                        </div>
                                        <small class="text-muted ip_barcode_hint"></small>
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">{{ __('message.product_group') }} <span class="required" style="color:red">*</span></label>
                                        {{-- Chỉ bày nhóm nhỏ nhất (không có nhóm con) và chỉ in TÊN nó.
                                             Đường dẫn từ nhóm gốc xuống để ở `title` cho ai cần rà lại
                                             nhánh, chứ không chen vào dòng chọn. --}}
                                        <select class="form-select select_menu_group select2" style="width:100%">
                                            <option value="">{{ __('message.select-menu-group') }}</option>
                                            @foreach($catsChonDuoc as $c)
                                                <option value="{{ $c['id'] }}" data-vat="{{ $c['vat'] }}"
                                                    @if($c['path']) title="{{ implode(' › ', $c['path']) }} › {{ $c['name'] }}" @endif>
                                                    {{ $c['name'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @if(empty($catsChonDuoc))
                                            <small class="loi-nap">{{ $napLoi['categories'] ?? 'Chưa có nhóm hàng hoá nào. Mở Hàng hoá → Nhóm hàng hoá để khai trước.' }}</small>
                                        @endif
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">{{ __('message.product-name') }} <span class="required" style="color:red">*</span></label>
                                        <input type="text" class="form-control ip_name" name="name"
                                            placeholder="{{ __('message.product-name') }}" maxlength="200">
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">{{ __('message.price') }} <span class="required" style="color:red">*</span></label>
                                        <input type="text" class="form-control format-money ip_sale_price"
                                            inputmode="numeric" maxlength="14" placeholder="{{ __('message.price') }}">
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">{{ __('message.cost-price') }}</label>
                                        <input type="text" class="form-control format-money ip_cost_price"
                                            inputmode="numeric" maxlength="14" placeholder="{{ __('message.cost-price') }}">
                                    </div>

                                    {{-- Hàng 4-2-2-4 đúng như v2: ĐVT | Thuế | % VAT | Giá sau thuế. --}}
                                    <div class="col-4">
                                        <label class="form-label">{{ __('message.unit_of_measure') }}</label>
                                        <select class="select select2 select_menu_unit" style="width:100%">
                                            <option value="0">{{ __('message.select-menu-unit') }}</option>
                                            @foreach($units as $u)
                                                <option value="{{ $u['id'] }}">{{ $u['code'] }} · {{ $u['name'] }}</option>
                                            @endforeach
                                        </select>
                                        @if(empty($units))
                                            <small class="loi-nap">{{ $napLoi['units'] ?? 'Chưa có đơn vị tính nào đang bật. Mở Hàng hoá → Đơn vị tính để khai.' }}</small>
                                        @endif
                                    </div>

                                    {{-- Chọn LOẠI thuế trước; ô "% VAT" bên cạnh chỉ bày mức của loại
                                         ấy. Loại thuế KHÔNG lưu vào mặt hàng — mặt hàng chỉ giữ một số
                                         `vat`; ô này là cái phễu lọc cho ô bên cạnh, đúng vai của nó
                                         bên v2. --}}
                                    <div class="col-2">
                                        <label class="form-label">{{ __('message.taxes') }}</label>
                                        <select class="form-select select_tax_type">
                                            @foreach($loaiThue as $lt)
                                                <option value="{{ $lt['loai'] }}" data-muc="{{ implode(',', $lt['muc']) }}">
                                                    {{ $lt['ten'] }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="col-2">
                                        <label class="form-label">% {{ __('message.vat') }}</label>
                                        {{-- Nạp bằng JS theo loại đang chọn; dựng sẵn bộ của loại đầu
                                             tiên để trang không nhấp nháy lúc mới mở. --}}
                                        <select class="form-select select_vat_value">
                                            @foreach($vatRates as $muc)
                                                <option value="{{ $muc }}">{{ \App\Support\MucThue::chu($muc) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="col-4">
                                        <label class="form-label">Giá sau thuế</label>
                                        {{-- format-money như v2 để con số có dấu ngăn nghìn. Vẫn khoá
                                             lại: v2 cho gõ ngược từ giá sau thuế ra giá bán, bên mình
                                             chỉ tính một chiều nên mở ra là gõ vào rồi mất. --}}
                                        <input type="text" class="form-control format-money ip_sale_price_after_tax"
                                            disabled data-khoa-san placeholder="Giá sau thuế">
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        {{-- `message.position` là "Chức vụ" (chức vụ nhân sự) — chép
                                             nhầm khoá. Ô này là chỗ ĐỂ HÀNG trên kệ. --}}
                                        <label class="form-label">{{ __('message.location') }}</label>
                                        <select class="form-select select_location select2" style="width:100%">
                                            <option value="0">{{ __('message.all') }}</option>
                                            @foreach($locations as $loc)
                                                <option value="{{ $loc['id'] }}">{{ $loc['code'] }} · {{ $loc['name'] }}</option>
                                            @endforeach
                                        </select>
                                        @if(empty($locations) && isset($napLoi['locations']))
                                            <small class="loi-nap">{{ $napLoi['locations'] }}</small>
                                        @endif
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">{{ __('message.branch') }}</label>
                                        {{-- Không chọn chi nhánh nào = bán ở MỌI chi nhánh. --}}
                                        <select class="form-select select2 select_branch" multiple style="width:100%">
                                            @foreach($branches as $b)
                                                <option value="{{ $b['id'] }}">{{ $b['name'] }}</option>
                                            @endforeach
                                        </select>
                                        @if(empty($branches))
                                            <small class="loi-nap">{{ $napLoi['branches'] ?? 'Chưa có chi nhánh nào đang hoạt động.' }}</small>
                                        @endif
                                    </div>

                                    <div class="col-12 col-lg-4">
                                        <label class="form-label">Thẻ hàng hoá</label>
                                        <select class="form-select select2 select_tag" multiple style="width:100%">
                                            @foreach($tags as $t)
                                                <option value="{{ $t['name'] ?? $t }}">{{ $t['name'] ?? $t }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">{{ __('message.description') }}</label>
                                        <div class="box-textarea-cus">
                                            <textarea class="form-control description" rows="2" maxlength="500"></textarea>
                                        </div>
                                    </div>
                                </div>

                                {{-- Accordion "Quy đổi đơn vị" — y khuôn v2 --}}
                                {{-- Quy đổi đơn vị.

                                     v2 neo hai nút bằng toạ độ tuyệt đối (`top:11px; left:186px`)
                                     — con số đó đo theo bề ngang chữ tiêu đề BÊN ĐÓ, đổi nhãn hay
                                     đổi ngôn ngữ là nút đè lên chữ. Ở đây dựng bằng hàng flex:
                                     tiêu đề bên trái, hai nút dạt phải, không phụ thuộc bề ngang
                                     của chữ. --}}
                                <div class="mt-3 khoi-quy-doi">
                                    <div class="qd-dau d-flex align-items-center justify-content-between">
                                        <button class="btn-collapse collapsed flex-grow-1 text-start" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#collapseMenuUnit">
                                            {{ __('message.unit_conversion') }}
                                        </button>
                                        <div class="d-flex gap-2 ps-2">
                                            <button type="button" class="btn btn-sm btn-primary add-unit">{{ __('message.add') }}</button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary close-all-unit"
                                                title="{{ __('message.delete_all') }}"><i class="fa fa-close"></i></button>
                                        </div>
                                    </div>
                                    <div id="collapseMenuUnit" class="collapse">
                                        <div class="qd-than table-responsive w-100">
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <th style="width: 25%">{{ __('message.converted_quantity') }}</th>
                                                        <th style="width: 20%">{{ __('message.converted_unit') }}</th>
                                                        <th style="width: 10%">=</th>
                                                        <th style="width: 20%">{{ __('message.quantity') }}</th>
                                                        <th style="width: 15%">{{ __('message.unit_of_measurement') }}</th>
                                                        <th style="width: 10%">{{ __('message.action') }}</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="list_unit"></tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ===================== Tab Thuộc tính ===================== --}}
                    <div class="tab-pane fade" id="attribute" role="tabpanel">
                        <div class="mt-2 mx-2">
                            {{-- Cùng khuôn với khối Quy đổi đơn vị: hàng tiêu đề bằng flex,
                                 không neo nút bằng toạ độ tuyệt đối như v2. --}}
                            <div class="khoi-quy-doi">
                                <div class="qd-dau d-flex align-items-center justify-content-between">
                                    <button class="btn-collapse collapsed flex-grow-1 text-start" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#collapseAttribute">
                                        {{ __('message.menu-attribute') }}
                                    </button>
                                    <div class="d-flex gap-2 ps-2">
                                        <button type="button" class="btn btn-sm btn-primary add-attribute-select">
                                            {{ __('message.add-attribute') }}
                                        </button>
                                    </div>
                                </div>
                                <div id="collapseAttribute" class="collapse show">
                                        <div class="qd-than table-responsive w-100">
                                            <table>
                                                <thead>
                                                    {{-- Tiêu đề hai tầng: tầng trên là tên cột, tầng dưới là
                                                         ba nhãn con của ruột hàng. Ba nhãn ấy dùng ĐÚNG bộ bề
                                                         rộng của dòng chi tiết bên dưới nên thẳng cột. Trước
                                                         đây mỗi hàng thuộc tính lại lặp một hàng tiêu đề
                                                         riêng — vừa rối vừa không thẳng hàng nào với hàng nào. --}}
                                                    <tr>
                                                        <th style="width: 26%" rowspan="2">{{ __('message.attribute') }}</th>
                                                        <th>{{ __('message.attribute_details') }}</th>
                                                        <th style="width: 90px" rowspan="2">{{ __('message.action') }}</th>
                                                    </tr>
                                                    <tr>
                                                        <th class="tt-dau-o">
                                                            <div class="d-flex tt-dau">
                                                                <span class="tt-o-keo"></span>
                                                                <span class="tt-o-md">{{ __('message.default') }}</span>
                                                                <span class="tt-o-gt">{{ __('message.detail') }}</span>
                                                                <span class="tt-o-them">{{ __('message.additional_value') }}</span>
                                                            </div>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody id="add-attribute"></tbody>
                                            </table>
                                        </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer justify-content-center">
                <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                <button type="button" class="bt btn_green" id="confirm_create_menu">{{ __('message.confirm') }}</button>
            </div>
        </div>
    </div>
</div>
