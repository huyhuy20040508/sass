{{--
    Bán hàng — màn chính của quầy, đổ vào KHUÔN QUẦY của v2::layouts.pos.

    Layout giữ khung hai khu (.order-layout > .menu-area + .bill-area), thẻ "Hàng hoá"
    và việc khoá cuộn cả trang. View này chỉ đổ nội dung vào bốn chỗ:
      · dau-khu-trai  chip chi nhánh (chỗ bản gốc để ô chọn BÀN)
      · khu-trai      hàng nhóm · ô tìm/quét mã · lưới thẻ hàng
      · dau-khu-phai  tab các hoá đơn đang mở · nút thêm hoá đơn
      · khu-phai      bảng hàng · khách · khối tiền · hình thức · nút chốt

    Markup của từng khu CHÉP TỪ TRANG THẬT (table1.klkim.com/v2/order/cashier), giữ tên
    lớp để CSS thu ngân v2 vẽ đúng. Khác bản gốc ở những chỗ quầy bán lẻ cần: không bàn,
    không bếp; ô tên + số điện thoại thay select2; bớt % theo món kẹp theo hạn quyền API
    trả; nhiều hoá đơn giữ ở localStorage của máy. Khách đưa / tiền thừa nằm trong hộp
    "Xác nhận thanh toán" — đúng chỗ bản gốc để.

    Phím tắt: F1 (hoặc F3) ô tìm · F2 hoá đơn mới · F4 thanh toán tiền mặt · F9 thanh toán.
--}}
@extends('v2::layouts.pos')

@php
    $B = \App\Http\Controllers\PosController::class;
    $coGiam = $hanMucGiam > 0;
    $cnQuay = \App\Services\CurrentBranch::danhSach();
    $tenChiNhanh = count($cnQuay['ds']) > 1 ? (collect($cnQuay['ds'])->firstWhere('id', $cnQuay['dangChon'])['name'] ?? '') : '';
    $nguoiBan = trim((string) data_get(session('api.user'), 'full_name', '')) ?: (string) data_get(session('api.user'), 'username', 'Nhân viên');
    $tenTiem = app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name'));
@endphp

@section('title', $B::TITLE)

{{-- Chỗ bản gốc để ô chọn BÀN; ở quầy bán lẻ chỗ đó đáng nói hơn: KHO sắp bị trừ hàng. --}}
@section('dau-khu-trai')
    @if($tenChiNhanh !== '')
                            {{-- Chỗ bản gốc để ô chọn BÀN. Ở quầy bán lẻ chỗ đó đáng nói hơn: KHO sắp bị trừ hàng. --}}
                            <div id="switchTableWrap" style="background-color: #1A234AA6; border-radius: 25px; max-width: 300px; position: absolute; left: 50%; transform: translateX(-50%); bottom: 7px;">
                                <div class="d-flex align-items-center justify-content-center ps-3 pe-3 text-white" style="height: 35px; min-width: 220px;" title="Kho sẽ bị trừ hàng khi bán">
                                    <i class="fa-solid fa-store"></i><strong class="fs-6 ms-2">{{ $tenChiNhanh }}</strong>
                                </div>
                            </div>
                        @endif
@endsection

@section('khu-trai')
    <h1 class="visually-hidden">Bán tại quầy</h1>

    {{-- Cấu hình cho JS của màn (URL, hạn quyền bớt giá…). Khối bọc hai khu nay thuộc
         layout, nên cấu hình nằm ở một thẻ ẩn riêng. --}}
    <div id="posCauHinh" hidden
         data-search-url="{{ route('admin.orders.searchProducts') }}"
         data-customer-url="{{ route('thu-ngan.ban-hang.khach') }}"
         data-customer-create-url="{{ route('thu-ngan.ban-hang.taoKhach') }}"
         data-einvoice-url="{{ route('thu-ngan.ban-hang.hoaDon', ['id' => 0]) }}"
         data-scan-url="{{ route('thu-ngan.ban-hang.scan') }}"
         data-store-url="{{ route('thu-ngan.ban-hang.store') }}"
         data-receipt-url="{{ route('thu-ngan.ban-hang.phieu', ['id' => 0]) }}"
         data-discount-limit="{{ $hanMucGiam }}"
         data-nguoi-ban="{{ $nguoiBan }}"
         data-ten-tiem="{{ $tenTiem }}"
         data-empty-img="{{ asset('v2/images/icons/emptyCart.svg') }}"></div>

    <div class="list-category-container scroll-delta-y" id="posNhomBoc">
        <div class="list-category" id="posCats">
            <button class="btn btn-category active" data-id="">Tất cả</button>
            {{-- Hai nhóm ảo của bản gốc ("Bán chạy", "Món mới"), cùng data-id để ăn màu đỏ của CSS gốc:
                 xếp theo lượt bán và theo ngày khai hàng. --}}
            <button class="btn btn-category" data-id="best_seller">Bán chạy</button>
            <button class="btn btn-category" data-id="new">Hàng mới</button>
            @foreach($nhomHang as $n)
                <button class="btn btn-category" data-id="{{ $n['id'] }}">{{ $n['name'] }}</button>
            @endforeach
        </div>
        <div class="category-side-buttons">
            <button type="button" class="btn-category-setting" id="posNhomChon" title="Chỉnh sửa hiển thị danh mục">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"></path></svg>
            </button>
            <button type="button" class="btn-toggle-categories mt-2" id="posNhomBung" title="Tất cả" aria-expanded="false">
                <i class="fa-solid fa-arrow-up"></i>
            </button>
        </div>
    </div>

    <div class="row g-2 px-2 mb-2">
        <div class="col-12 col-xl-6">
            <input style="min-width: 200px; border-radius:10px;" type="text" class="form-control filter_search"
                   id="posSearch" autocomplete="off" autofocus placeholder="Tìm và chọn hàng (F1)">
        </div>
        <div class="col-12 col-xl-6 mb-1 px-xl-0">
            <div class="d-flex justify-content-end align-items-center">
                <div class="form-check form-switch my-auto cursor-pointer me-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="hide_thumbnail">
                    <label class="form-check-label ms-2" for="hide_thumbnail">Ẩn hình ảnh</label>
                </div>
                <div class="d-sm-flex white-space-nowrap align-items-center my-auto mx-2" style="margin-right: 0;">
                    <select id="select_col_number" name="col-number" class="form-select" style="border-radius:10px;">
                        @foreach([2, 3, 4, 5, 6] as $c)
                            <option value="{{ $c }}">{{ $c }} cột</option>
                        @endforeach
                    </select>
                </div>
                <div id="div_pagination" class="d-flex align-items-center me-3 my-auto"></div>
            </div>
        </div>
    </div>

    <div class="mt-1" style="height: calc(100% - 84px); overflow: auto">
        <div class="product-cotainer" id="list"></div>
    </div>
@endsection

@section('dau-khu-phai')
    <ul class="nav nav-tabs mt-auto border-0" id="invoiceTab" role="tablist"></ul>
    <div class="position-relative d-inline-block mx-3" id="invoiceTabToggleContainer">
        <button id="invoiceTabToggle" type="button" class="d-flex align-items-center bg-transparent border-0 d-none" title="Các hoá đơn khác">
            <i class="fa-solid fa-layer-group"></i>
            <span id="invoiceTabCount" class="fw-bold ms-2" style="font-size: 16px;">0</span>
        </button>
        <div id="invoiceTabDropdown" class="position-absolute bg-white shadow-lg d-none" style="top: calc(100% + 10px); left: -8px; min-width: 200px; max-height: 400px; overflow-y: auto; z-index: 1050;">
            <div id="invoiceTabDropdownList"></div>
        </div>
    </div>
    <a id="newOrder" class="iconButton" type="button" title="Thêm hoá đơn mới (F2)">
        <svg width="20" height="20" viewBox="0 0 23 23" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11.5 1.5V21.5M1.5 11.5H21.5" stroke="#FAFAFA" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></path></svg>
    </a>
@endsection

@section('khu-phai')
    <div class="product-table-container mb-2">
        <div class="mh_menu scroll-container overflow-y-auto">
            <table class="table w-100 table-fixed-header minW-340 d-flex flex-column rounded">
                <thead class="sticky-top z-0">
                    <tr class="d-flex w-100">
                        <th scope="col" class="text-start" style="width: 36%; z-index: 0">Tên hàng</th>
                        <th scope="col" style="width: 20%; z-index: 0;">Số lượng</th>
                        <th scope="col" style="width: 20%; z-index: 0;">Đơn giá</th>
                        <th scope="col" style="width: 18%; z-index: 0;">Thành tiền</th>
                        <th scope="col" style="width: 6%;z-index: 0;"></th>
                    </tr>
                </thead>
                <tbody class="list-order d-flex flex-column h-100" id="posCart"></tbody>
            </table>
        </div>
    </div>

    <div class="col-footer col-12 col-footer-right px-2 action-btn-cashier">
        <div id="cashier-list-select" class="d-flex align-items-start">
            <button class="btn btn-option general_note_modal" id="posNoteBtn" type="button" style="height: 35px; background: #D9D9D9; min-width: fit-content;">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"> <g clip-path="url(#clip0_21886_3394)"> <path d="M4.44444 16H6.77778L13.4444 10.05L11.0556 7.9L4.44444 13.85V16ZM14.2222 9.35L15.3889 8.25C15.5 8.15 15.5556 8.03333 15.5556 7.9C15.5556 7.76667 15.5 7.65 15.3889 7.55L13.8333 6.15C13.7222 6.05 13.5926 6 13.4444 6C13.2963 6 13.1667 6.05 13.0556 6.15L11.8333 7.2L14.2222 9.35ZM2.22222 20C1.61111 20 1.08815 19.8043 0.653333 19.413C0.218519 19.0217 0.000740741 18.5507 0 18V4C0 3.45 0.217778 2.97933 0.653333 2.588C1.08889 2.19667 1.61185 2.00067 2.22222 2H6.88889C7.12963 1.4 7.53259 0.916667 8.09778 0.55C8.66296 0.183333 9.29704 0 10 0C10.703 0 11.3374 0.183333 11.9033 0.55C12.4693 0.916667 12.8719 1.4 13.1111 2H17.7778C18.3889 2 18.9122 2.196 19.3478 2.588C19.7833 2.98 20.0007 3.45067 20 4V18C20 18.55 19.7826 19.021 19.3478 19.413C18.913 19.805 18.3896 20.0007 17.7778 20H2.22222ZM10 3.25C10.2407 3.25 10.44 3.179 10.5978 3.037C10.7556 2.895 10.8341 2.716 10.8333 2.5C10.8326 2.284 10.7537 2.105 10.5967 1.963C10.4396 1.821 10.2407 1.75 10 1.75C9.75926 1.75 9.56037 1.821 9.40333 1.963C9.2463 2.105 9.16741 2.284 9.16667 2.5C9.16593 2.716 9.24481 2.89533 9.40333 3.038C9.56185 3.18067 9.76074 3.25133 10 3.25Z" fill="#183556"></path> </g> <defs> <clipPath id="clip0_21886_3394"> <rect width="20" height="20" fill="white"></rect> </clipPath> </defs> </svg>
                <span class="w-auto ms-2 text-center">Ghi chú</span>
                <span class="button-notice-mark" id="posNoteMark" style="display: none;"></span>
            </button>
            <div class="btn-invoice-web">
                <button class="btn btn-option electronic_invoice" id="posHddt" type="button" style="height: 35px; background: #D9D9D9">
                    <svg width="18" height="20" viewBox="0 0 18 20" fill="none" xmlns="http://www.w3.org/2000/svg"> <path fill-rule="evenodd" clip-rule="evenodd" d="M0 2C0 1.46957 0.210714 0.960859 0.585787 0.585786C0.960859 0.210714 1.46957 0 2 0L12.9427 0L17.3333 4.39067V18C17.3333 18.5304 17.1226 19.0391 16.7475 19.4142C16.3725 19.7893 15.8638 20 15.3333 20H2C1.46957 20 0.960859 19.7893 0.585787 19.4142C0.210714 19.0391 0 18.5304 0 18V2ZM4 5.33333H8V4H4V5.33333ZM13.3333 6.66667H4V13.3333H13.3333V6.66667ZM13.3333 16H9.33333V14.6667H13.3333V16Z" fill="#183556"></path> </svg>
                    <span class="ms-2 w-auto text-center">Hóa đơn điện tử</span>
                    <span class="button-notice-mark" id="posHddtMark" style="display: none;"></span>
                </button>
            </div>
            <div class="position-relative d-inline-block">
                <button type="button" class="iconButton toggle-options btn-extraOptions" data-bs-toggle="collapse" data-bs-target="#extraOptions" aria-expanded="false" aria-controls="extraOptions" title="Thêm">
                    <img src="{{ asset('v2/images/icons/dot.svg') }}" width="17" height="4" alt="">
                </button>
                <div id="extraOptions" class="collapse custom-option-menu">
                    <button type="button" class="dropdown-item option-item" id="posXoaHet"><i class="fa-regular fa-trash-can me-2"></i> Xoá hết hàng</button>
                    <a class="dropdown-item option-item border-bottom-0" href="{{ route('thu-ngan.don-hang.index') }}"><i class="fa-regular fa-file-lines me-2"></i> Lịch sử đơn</a>
                </div>
            </div>
            <div class="filter-and-bill-container p-0 flex-grow-1">
                <div class="form-delivery d-flex">
                    {{-- Bản gốc là select2 chọn khách; ở đây là hộp cùng dáng, bấm ra ô tìm khách quen. --}}
                    <div class="min-w-0 customer-select-member mx-2 position-relative flex-grow-1" id="posKh">
                        <span class="select2 select2-container select2-container--default w-100" dir="ltr" id="posCusBtn" role="button" tabindex="0" aria-haspopup="listbox" aria-expanded="false">
                            <span class="selection"><span class="select2-selection select2-selection--single" role="combobox">
                                <span class="select2-selection__rendered" id="posCusNhan" title="Bán cho người tiêu dùng">Bán cho người tiêu dùng</span>
                                <span class="select2-selection__arrow" role="presentation"><b role="presentation"></b></span>
                            </span></span>
                        </span>
                        <div class="pos-ac-menu" id="posCusMenu" hidden>
                            <input type="text" class="form-control pos-ac-tim" id="posCusName" autocomplete="off" placeholder="Gõ tên hoặc số điện thoại khách quen">
                            <div id="posCusDS"></div>
                        </div>
                    </div>
                    {{-- Nút + của bản gốc: thêm khách mới — lưu hồ sơ, hoặc chỉ ghi tên + số lên hoá đơn này. --}}
                    <button type="button" class="btn add_member add-new-member" id="posCusMoi" title="Khách mới">
                        <svg width="14" height="14" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M0.875 8.79434H16.8667M8.87084 0.875V16.7137" stroke="#FAFAFA" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                    </button>
                </div>
            </div>
        </div>

        <input type="text" class="form-control mt-2" id="posNote" autocomplete="off" maxlength="500" hidden placeholder="Ghi chú cho hoá đơn này">

        <div class="payData hide" style="display: block;">
            <div class="">
                <div class="col-12 shadow-box scroll-content-payment content-payment-wrapper">
                    <table class="table table-hover body-none-border custom-body-payment" style="margin:5px 0" cellpadding="5" cellspacing="5">
                        <tbody class="info_pay last-tr-border">
                            <tr>
                                <td class="text-header-payment text-left main-header-payment" colspan="2">
                                    <div class="d-flex">Tổng tiền (<div class="total_cart text-danger" id="posQty">0</div>)</div>
                                </td>
                                <td class="text-header-payment goods_total text-right" colspan="2" id="posGross">0 đ</td>
                            </tr>
                            <tr class="cursor-pointer" id="extra_fee" title="Phụ thu">
                                <td class="text-left text-extra_fee" colspan="3" style="white-space: normal">Phụ thu: <span class="list-name-extra" id="posPhuThuTen"></span> <i class="fas fa-edit color-1a234a"></i></td>
                                <td class="text-right total_surcharge" colspan="1" id="posPhuThu">0 đ</td>
                            </tr>
                            {{-- Bấm hàng này mở hộp giảm giá: giảm cả đơn (VNĐ / %) và mã giảm giá. --}}
                            <tr class="cursor-pointer" id="posGiamRow" title="Giảm giá cả đơn, mã giảm giá">
                                <td class="text-left text-decrease_price" colspan="3" style="white-space: normal">Giảm giá: <span class="list-name-discount" id="posGiamTen"></span> <i class="fas fa-edit color-1a234a"></i></td>
                                <td class="text-right total_discount" colspan="1" id="posCut">0 đ</td>
                            </tr>
                            <tr class="last-tr-border">
                                <td class="text-left"> Thuế sản phẩm</td>
                                <td colspan="2"></td>
                                <td class="text-right total_vat" data-type="goods" id="posThue">0 đ</td>
                            </tr>
                            <tr style="position:relative">
                                <td colspan="2" class="text-left text-danger fw-bold" style="font-size:20px">Tổng tiền thanh toán</td>
                                <td colspan="2" class="text-right total_payment fw-bold text-danger" style="font-size:20px" id="posTotal">0 đ</td>
                            </tr>
                        </tbody>
                    </table>

                    <table class="table body-none-border custom-body-payment" cellpadding="5" cellspacing="5">
                        <tbody class="actionButtons">
                            <tr class="no-hover">
                                <td colspan="4" class="px-0">
                                    <div class="list-payment-method" id="posPayTabs">
                                        @foreach($B::PAYMENT_METHODS as $ma => $nhan)
                                            <button type="button" data-method="{{ $ma }}"
                                                    class="btn_payment_child {{ $ma === 'cash' ? 'btn_cash' : 'btn_bank' }} d-flex flex-column align-items-center flex-xl-row justify-content-xl-center gap-1 {{ $loop->first ? 'active' : '' }}">
                                                <img src="{{ asset('v2/images/icons/'.($ma === 'cash' ? 'cash' : 'banking').'.svg') }}" alt="" width="28">
                                                {{ $nhan }}
                                            </button>
                                        @endforeach
                                        {{-- Bản gốc có thêm hai hình thức này; API quầy chưa nhận nên bày ra nhưng khoá. --}}
                                        <button type="button" class="btn_payment_child btn_mpos d-flex flex-column align-items-center flex-xl-row justify-content-xl-center gap-1" disabled title="Quầy chưa nhận quẹt thẻ">
                                            <img src="{{ asset('v2/images/icons/qrcode.svg') }}" alt="" width="28">
                                            Quẹt Thẻ
                                        </button>
                                        <button type="button" class="btn_payment_child btn_mpos d-flex flex-column align-items-center flex-xl-row justify-content-xl-center gap-1" disabled title="Quầy chưa nhận QR tự động">
                                            <img src="{{ asset('v2/images/icons/qrcode.svg') }}" alt="" width="28">
                                            QR Tự động
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr class="no-hover">
                                <td class="p-0" colspan="4">
                                    <div id="modalPaymentButtons" class="list-button-payment shadow-box">
                                        {{-- Chỗ bản gốc để "In phiếu Bếp/Bar" (nút cam) — quầy bán lẻ không có bếp. --}}
                                        <button type="button" class="btn_action_model btn-request-kitchen cancel_order d-flex flex-column flex-xl-row align-items-center gap-1" id="posClear">
                                            <i class="fa-solid fa-trash-can"></i> Huỷ hoá đơn
                                        </button>
                                        <button type="button" class="btn_action_model provisional_print d-flex flex-column flex-xl-row align-items-center gap-1" id="posTamTinh" disabled>
                                            <img src="{{ asset('v2/images/icons/invoice.svg') }}" alt="" width="25" height="25"> Tạm tính
                                        </button>
                                        <button type="button" class="btn_action_model confirm_pay_and_print d-flex flex-column flex-xl-row align-items-center gap-1" id="posSubmit" disabled>
                                            <img src="{{ asset('v2/images/icons/currency.svg') }}" alt="" width="25" height="25"> Thanh toán
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('modals')
    {{-- Chọn phiên bản khi hàng có nhiều biến thể còn hàng. --}}
    <div class="modal fade" id="posVarBox" tabindex="-1" aria-labelledby="posVarTen" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posVarTen">Chọn phiên bản</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body"><div class="row g-2" id="posVarDS"></div></div>
            </div>
        </div>
    </div>

    {{-- XÁC NHẬN THANH TOÁN — cùng khuôn hộp #waitingPayment của bản gốc: mã / hình
         thức / tổng tiền, rồi hai nút "Xác nhận" và "Xác nhận & In". Tiền mặt thì có
         thêm ô khách đưa, gợi ý tiền chẵn, mệnh giá và tiền thừa. --}}
    <div class="modal fade" id="posPayBox" tabindex="-1" data-bs-backdrop="static" aria-labelledby="posPayTieuDe" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered pos-pay-hop">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posPayTieuDe">Xác nhận thanh toán</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body p-2">
                    <div class="d-flex flex-column my-2 w-100 px-2">
                        <div class="content-order-payment">
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><strong class="m-0 title">Hoá đơn:</strong><b id="posPayHd"></b></div>
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><strong class="m-0 title">Hình thức:</strong><b id="posPayHinhThuc"></b></div>
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><strong class="m-0 title">Tổng tiền:</strong><b class="total_wait_pay" id="posPayTong"></b></div>
                            <hr class="my-2">
                        </div>
                        <div id="posPayTienMat">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <strong class="m-0 title">Khách đưa</strong>
                                <div class="d-flex align-items-center pos-o-dua">
                                    <input type="text" inputmode="numeric" class="clean-input text-right customer_give" id="posTendered" autocomplete="off" placeholder="0">
                                    <div style="font-size:16px">đ</div>
                                </div>
                            </div>
                            <div class="hint_cash_suggest hint_cash_open justify-content-end my-2" id="posGoiYTien"></div>
                            <div class="wrapper-btn-suggest d-flex gap-2 mb-2" id="posMenhGia">
                                @foreach($B::MENH_GIA as $m)
                                    <button type="button" class="btn-suggest" data-add="{{ $m }}">+{{ number_format($m / 1000, 0, ',', '.') }}k</button>
                                @endforeach
                            </div>
                            <div class="d-flex justify-content-between align-items-center ex_change" id="posChange" hidden>
                                <span id="posChangeLabel">Tiền thừa trả khách</span><b id="posChangeVal">0 đ</b>
                            </div>
                        </div>
                        <p class="pos-err mt-2" id="posErr" role="alert" hidden></p>
                    </div>
                </div>
                <div class="d-flex justify-content-center m-3">
                    <button type="button" class="bt btn_green border-0 btn-lg confirm_done mx-2" id="posXacNhan" style="min-width: 135px;">Xác nhận</button>
                    <button type="button" class="bt btn_green border-0 btn-lg confirm_done_and_print" id="posXacNhanIn" style="min-width: 135px;">Xác nhận &amp; In</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Khách mới (nút + cam của bản gốc): lưu hồ sơ khách, hoặc bỏ tích để chỉ ghi tên + số lên hoá đơn này. --}}
    <div class="modal fade" id="posKhachBox" tabindex="-1" aria-labelledby="posKhachTieuDe" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="posKhachForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="posKhachTieuDe">Khách mới</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="posKhachTen">Tên khách <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="posKhachTen" autocomplete="off" maxlength="100">
                    <label class="form-label mt-3" for="posKhachSdt">Số điện thoại</label>
                    <input type="text" class="form-control" id="posKhachSdt" autocomplete="off" inputmode="tel" maxlength="20">
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label mt-3" for="posKhachEmail">Email</label>
                            <input type="email" class="form-control" id="posKhachEmail" autocomplete="off" maxlength="191">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label mt-3" for="posKhachDiaChi">Địa chỉ</label>
                            <input type="text" class="form-control" id="posKhachDiaChi" autocomplete="off" maxlength="255">
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="posKhachLuu" checked>
                        <label class="form-check-label" for="posKhachLuu">Lưu vào danh sách khách hàng</label>
                    </div>
                    <p class="text-secondary mt-1 mb-0" style="font-size: 12.5px">Số điện thoại đã có hồ sơ thì quầy chọn luôn khách đó. Bỏ tích thì chỉ ghi tên, số lên hoá đơn này.</p>
                    <p class="pos-err mt-2 mb-0" id="posKhachLoi" role="alert" hidden></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="posKhachXoa">Bán cho người tiêu dùng</button>
                    <button type="submit" class="btn tn-nut" id="posKhachLuuNut">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Giảm giá: giảm tay trên cả đơn (VNĐ / %, trong hạn quyền) + mã giảm giá. Mức giảm của MÃ do API tính lúc chốt. --}}
    <div class="modal fade" id="posGiamBox" tabindex="-1" aria-labelledby="posGiamTieuDe" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="posGiamForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="posGiamTieuDe">Giảm giá</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    @if($coGiam)
                        <label class="form-label" for="posGiamDon">Giảm trên cả đơn</label>
                        <div class="input-group input-group-container">
                            <input type="text" class="form-control shadow-none" id="posGiamDon" inputmode="decimal" autocomplete="off" placeholder="0">
                            <div class="toggle-discount-type-wrapper" id="posGiamKieu">
                                <button type="button" class="toggle-discount-btn" data-kieu="amount">VNĐ</button>
                                <button type="button" class="toggle-discount-btn active" data-kieu="percent">%</button>
                            </div>
                        </div>
                        <p class="text-secondary mt-1 mb-3" style="font-size: 12.5px" id="posGiamHan"></p>
                    @endif
                    <label class="form-label" for="posVoucher">Mã giảm giá khách đưa</label>
                    <input type="text" class="form-control text-uppercase" id="posVoucher" autocomplete="off" maxlength="50" placeholder="VD: GIAM10">
                    <p class="text-secondary mt-2 mb-0" style="font-size: 12.5px">Mức giảm của mã được API tính và trừ lúc chốt đơn.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn tn-nut">Áp dụng</button>
                </div>
            </form>
        </div>
    </div>

    {{-- PHỤ THU — khuôn #extraFeeModal của bản gốc: lý do + giá trị (VNĐ / %). Gõ % thì quy ra
         số tiền trên tiền hàng lúc chốt; API nhận số tiền. --}}
    <div class="modal fade" id="posPhuThuBox" tabindex="-1" aria-labelledby="posPhuThuTieuDe" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="posPhuThuForm" novalidate>
                <div class="modal-header">
                    <h4 class="modal-title" id="posPhuThuTieuDe">Phụ thu</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="row m-2 g-2">
                        <div class="col-4 d-flex"><label class="title-content-pay my-auto" for="posPhuThuLyDo">Lý do phụ thu</label></div>
                        <div class="col-8">
                            <input type="text" class="form-control reason_surcharge" id="posPhuThuLyDo" list="posPhuThuGoiY" maxlength="255" autocomplete="off" placeholder="VD: Phí gói quà">
                            <datalist id="posPhuThuGoiY">
                                <option value="Phí gói quà"></option>
                                <option value="Phí giao tận nơi"></option>
                                <option value="Phụ thu ngoài giờ"></option>
                                <option value="Phụ thu ngày lễ"></option>
                            </datalist>
                        </div>
                        <div class="col-4 d-flex"><label class="title-content-pay my-auto" for="posPhuThuGt">Giá trị phụ thu</label></div>
                        <div class="col-8">
                            <div class="input-group input-group-container">
                                <input type="text" class="form-control shadow-none extra_charge" id="posPhuThuGt" inputmode="decimal" autocomplete="off" placeholder="0">
                                <div class="toggle-discount-type-wrapper" id="posPhuThuKieu">
                                    <button type="button" class="toggle-discount-btn active" data-kieu="amount">VNĐ</button>
                                    <button type="button" class="toggle-discount-btn" data-kieu="percent">%</button>
                                </div>
                            </div>
                            <p class="text-secondary mt-1 mb-0" style="font-size: 12.5px" id="posPhuThuQuyDoi"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="posPhuThuBo">Bỏ phụ thu</button>
                    <button type="submit" class="btn tn-nut">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- HOÁ ĐƠN ĐIỆN TỬ của hoá đơn đang mở: bật thì API xuất ngay sau khi chốt. Cổng và ký hiệu
         lấy theo chi nhánh đang bán; người mua là khách đang chọn ở ô khách hàng. --}}
    <div class="modal fade" id="posHddtBox" tabindex="-1" aria-labelledby="posHddtTieuDe" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="posHddtForm" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="posHddtTieuDe">Hoá đơn điện tử</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="posHddtBat">
                        <label class="form-check-label fw-bold" for="posHddtBat">Xuất hoá đơn điện tử khi thanh toán</label>
                    </div>
                    <div id="posHddtO">
                        <label class="form-label" for="posHddtMst">Mã số thuế</label>
                        <input type="text" class="form-control" id="posHddtMst" maxlength="20" inputmode="numeric" autocomplete="off" placeholder="Bỏ trống nếu khách cá nhân">
                        <label class="form-label mt-2" for="posHddtCty">Tên đơn vị</label>
                        <input type="text" class="form-control" id="posHddtCty" maxlength="255" autocomplete="off">
                        <label class="form-label mt-2" for="posHddtDiaChi">Địa chỉ</label>
                        <input type="text" class="form-control" id="posHddtDiaChi" maxlength="255" autocomplete="off">
                        <label class="form-label mt-2" for="posHddtEmail">Email nhận hoá đơn</label>
                        <input type="email" class="form-control" id="posHddtEmail" maxlength="191" autocomplete="off">
                    </div>
                    <p class="pos-err mt-2 mb-0" id="posHddtLoi" role="alert" hidden></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                    <button type="submit" class="btn tn-nut">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Bán xong. --}}
    <div class="modal fade" id="posDoneBox" tabindex="-1" aria-labelledby="posDoneTieuDe" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center">
                <div class="modal-body py-4">
                    <i class="fa-solid fa-circle-check" style="font-size: 46px; color: #22ac00"></i>
                    <h5 class="mt-3 mb-1" id="posDoneTieuDe">Đã bán xong</h5>
                    <p class="mb-3" style="color: #6b7280">Mã đơn <b id="posDoneMa"></b></p>
                    <div class="d-flex justify-content-between px-4" style="font-size: 16px"><span>Tổng thu</span><b id="posDoneTong"></b></div>
                    <div class="d-flex justify-content-between px-4 mt-1" id="posDoneThoiDong" style="font-size: 20px; color: #16a34a"><span>Thối lại</span><b id="posDoneThoi"></b></div>
                    {{-- Hoá đơn điện tử của lượt vừa bán: kết quả xuất ngay (nếu đã bật) và nút xuất / xuất lại. --}}
                    <div class="px-4 mt-3" id="posDoneHddt" hidden>
                        <p class="mb-2" id="posDoneHddtCau" style="font-size: 13.5px" role="status"></p>
                        <button type="button" class="btn btn-sm tn-nut-phu" id="posDoneHddtLai"></button>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <a class="btn tn-nut-phu" id="posDoneIn" href="#" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> In phiếu</a>
                    <button type="button" class="btn tn-nut" data-bs-dismiss="modal">Bán tiếp (Enter)</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Nhóm nào được hiện trên hàng nút (nút phễu của bản gốc). Nhớ theo MÁY:
         mỗi quầy bán một nhóm khác nhau, mà cùng một người có thể đứng hai quầy. --}}
    <div class="modal fade" id="posNhomBox" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Nhóm hàng hiện trên hàng nhóm</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span style="font-size: 12.5px; color: #6b7280">Bỏ tích nhóm không bán ở quầy này.</span>
                        <button type="button" class="btn btn-sm" id="posNhomTatCa" style="border: 1px solid #dee2e6">Tích hết</button>
                    </div>
                    <div id="posNhomDS" class="d-flex flex-column gap-1"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div>
            </div>
        </div>
    </div>

    {{-- Hộp hỏi của quầy. Thay confirm() của trình duyệt: hộp ấy không theo giao
         diện, không dịch được theo ngôn ngữ đang chọn, và vài trình duyệt chặn
         thẳng — lúc đó người bán bấm Huỷ hoá đơn mà không có gì xảy ra. --}}
    <div class="modal fade" id="posHoiBox" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="posHoiTieuDe"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body" id="posHoiBody"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="bt btn_green" id="posHoiOk">Đồng ý</button>
                </div>
            </div>
        </div>
    </div>
@endpush

@push('styles')
<style>
    /* Phần hình của hai khu do CSS bản gốc lo. Dưới đây chỉ ba nhóm bản gốc không có:
       1. thứ quầy bán lẻ mới có; 2. chỗ CSS quán ăn chia theo số nút của nó;
       3. khung dọc để lưới hàng và bảng hàng tự cuộn trong màn khoá cuộn. */

    /* ---------- 1. Quầy bán lẻ ---------- */
    .invoice-tab .dem { display: inline-block; min-width: 18px; margin-left: 4px; padding: 0 5px; border-radius: 9px; background: #F29220; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; }
    .invoice-tab .dong { position: absolute; top: 0; right: 0; border: 0; background: none; color: #9ca3af; font-size: 14px; line-height: 1; padding: 0 3px; }
    .invoice-tab .dong:hover { color: #cf1322; }
    .invoice-dropdown-item { display: block; width: 100%; padding: 8px 14px; border: 0; background: #fff; text-align: left; font-size: 13.5px; }
    .invoice-dropdown-item:hover { background: #eef5ff; }
    .order-layout #newOrder { cursor: pointer; }
    /* Chip chi nhánh đứng ở chỗ ô chọn BÀN của bản gốc — cùng viên xanh đậm, chữ trắng đậm. */
    #switchTableWrap > div { border-radius: 25px; background: #1A234A; font-weight: 700; }

    .list-menu .product-item.het-hang { opacity: .55; pointer-events: none; }
    /* Nhãn "Hết hàng" góc trên thẻ — cùng chỗ bản gốc gắn nhãn "Mới". */
    .list-menu .pos-het-hang { position: absolute; top: 4px; right: 4px; z-index: 1; padding: 2px 8px; border-radius: 4px; background: #fde2e2; color: #cf1322; font-size: 11px; font-weight: 600; }
    .list-menu .hide-thumbnail-product .pos-het-hang { position: static; display: inline-block; margin-top: 4px; }
    /* Ô ảnh trống cao bằng ảnh thật (max-height 120) — viết đủ tầm để thắng `.list-menu .menu-item .card-img-top { min-height: 110px }`. */
    .list-menu .menu-item .card-img-top.card-img-trong { display: grid; place-items: center; width: 100%; min-height: 120px; background: #eef2ff; color: #b9c3e6; font-size: 26px; }
    .pos-hint { width: 100%; margin: 0; padding: 28px 4px; text-align: center; color: #9ca3af; font-size: 13px; }

    /* Dòng hàng: đúng khuôn tr.order-item của bản gốc — CSS bản gốc vẽ h5.name-menu,
       .input-group-number, .btn-number, .sale-price-item. Bên mình chỉ thêm phần bớt %. */
    .order-layout .list-order > tr { flex: none; }
    .order-layout .list-order td.text-left { text-align: left !important; }
    .list-order .name-menu .ten-bien-the { display: block; margin-top: 3px; font-size: 12px; font-weight: 400; color: #6b7280; }
    /* Bản gốc có khung xanh nhạt quanh ô số lượng (lớp border-info) — CSS chép về thiếu luật này. */
    .list-order .input-group-number { border: 1px solid #0dcaf0; border-radius: 4px; }
    .list-order .input-number { width: 44px; }
    .list-order .price .mo-giam { cursor: pointer; font-size: 12px; }
    .list-order .price .gia-goc { display: block; margin-top: 2px; font-size: 11px; font-weight: 400; color: #9ca3af; text-decoration: line-through; }
    .list-order .cum-giam { display: flex; align-items: center; justify-content: center; gap: 3px; margin-top: 4px; font-size: 12px; color: #6b7280; }
    .list-order .o-giam { width: 50px; height: 26px; padding: 0 4px; text-align: right; font-size: 12px; }
    .list-order .delete_order { color: #e22b2b; font-size: 18px; }
    @keyframes pos-nhay { from { background: #fff1b8; } to { background: transparent; } }
    .list-order tr.order-item-just-added td { animation: pos-nhay 1.2s ease-out; }

    /* Ô chọn khách: markup select2 của bản gốc (CSS select2 đã nạp), JS của mình lo phần bấm. */
    #posCusBtn { cursor: pointer; }
    #posCusBtn.is-khach .select2-selection__rendered { color: #000084; font-weight: 600; }
    /* Danh sách khách quen: ô tìm ở trên, kết quả ở dưới. z-index 1000: tiêu đề bảng hàng dính trên nóc (999). */
    .pos-ac-menu { position: absolute; z-index: 1000; left: 0; right: 0; bottom: calc(100% + 2px); border: 1px solid #dee2e6; border-radius: 6px; background: #fff; box-shadow: rgba(0,0,0,.16) 0 1px 4px; }
    .pos-ac-tim { margin: 6px; width: calc(100% - 12px); height: 32px; font-size: 13.5px; }
    #posCusDS { max-height: 220px; overflow-y: auto; }
    .pos-ac-item { display: block; width: 100%; padding: 7px 12px; border: 0; background: #fff; text-align: left; font-size: 13.5px; }
    .pos-ac-item:hover, .pos-ac-item.is-chon { background: #5897fb; color: #fff; }
    .pos-ac-item:hover em, .pos-ac-item.is-chon em { color: #e8f0ff; }
    .pos-ac-item em { display: block; font-style: normal; font-size: 12px; color: #6b7280; }
    .pos-ac-empty { margin: 0; padding: 10px 12px; font-size: 12.5px; color: #9ca3af; }
    .pos-err { margin: 4px 0; padding: 8px 10px; border-radius: 6px; background: #fff1f0; color: #cf1322; font-size: 13px; }

    /* Hộp xác nhận thanh toán — khuôn #waitingPayment của bản gốc. */
    .pos-pay-hop { min-width: 550px; }
    #posPayBox .content-order-payment .title { font-size: 16px; }
    #posPayBox .total_wait_pay { font-size: 22px; font-weight: 700; color: #22ac00; }
    #posPayBox .pos-o-dua { width: 200px; border-bottom: 1px solid #1a234a; }
    #posPayBox .customer_give { font-size: 22px; font-weight: 700; }
    #posPayBox .hint_cash_suggest_value { font-size: 13px; white-space: nowrap; }
    #posPayBox .wrapper-btn-suggest .btn-suggest { flex: 1; min-width: 0; white-space: nowrap; }
    #posPayBox .ex_change { color: #16a34a; font-size: 18px; font-weight: 700; }
    #posPayBox .ex_change.is-thieu { color: #cf1322; }
    #posPayBox .confirm_done { background: #183556 !important; }
    #posPayBox .bt { height: 40px !important; font-size: 15px; }
    @media (max-width: 600px) { .pos-pay-hop { min-width: 0; } }

    /* Nút đang khoá (hình thức quầy chưa nhận, Tạm tính khi giỏ trống) mờ đi như nút
       "In phiếu Bếp/Bar" khoá của bản gốc. Kích cỡ hai hàng nút để CSS bản gốc chia (25%). */
    .order-layout .list-button-payment .btn-request-kitchen:disabled,
    .order-layout .list-payment-method .btn_payment_child:disabled { opacity: .55; cursor: not-allowed; }
    .order-layout .list-button-payment .provisional_print:disabled,
    .order-layout .list-button-payment .confirm_pay_and_print:disabled { opacity: 1; cursor: not-allowed; }
    .list-category-container { flex: none; }

    /* ---------- 3. Cuộn bên trong hai khu ----------
       Layout cho cả thân khu tự cuộn. Màn bán hàng thì hàng nhóm + ô tìm (trái) và khối
       thu tiền (phải) phải ĐỨNG YÊN — chỉ lưới hàng và bảng hàng tự cuộn. */
    .menu-area > .div-handle-list-product { overflow: hidden; }
    .div-handle-list-product > .mt-1 { flex: 1; min-height: 0; display: flex; }
    .product-cotainer { flex: 1; min-height: 0; width: 100%; overflow-y: auto; }
    .product-cotainer .list-menu { max-height: none !important; }
    .bill-area > .tab-content > .tab-pane { overflow: hidden; }
    .product-table-container { flex: 1; min-height: 0; }
    .product-table-container .mh_menu { height: 100%; max-height: none !important; }
    .col-footer { position: static !important; transform: none !important; width: auto !important; flex: none; }

    /* Hai nút của hộp thoại "Đã bán xong" — khu quản trị không có sẵn hai lớp này. */
    .tn-nut { background: #0151B4; color: #fff !important; border: 0; font-weight: 600; }
    .tn-nut:hover { background: #003f8f; }
    .tn-nut-phu { background: #eef5ff; color: #0151B4 !important; border: 1px solid #bcd6ff; font-weight: 600; }
    /* Nút Hoá đơn điện tử đang BẬT cho hoá đơn này: nền xanh nhạt + chấm như nút Ghi chú. */
    #cashier-list-select .electronic_invoice { position: relative; }
    #cashier-list-select .electronic_invoice.is-bat { background: #cfe3ff !important; box-shadow: inset 0 0 0 1px #0151B4; }
    #posDoneHddtCau { font-weight: 600; }
    #posHddtO { transition: opacity .15s; }

    @media (pointer: coarse) {
        .list-order .btn-number { width: 32px !important; }
        #posPayBox .hint_cash_suggest_value { padding: 8px 10px; }
    }
    /* Màn hẹp: layout đã cho hai khu xếp dọc; ở đây chỉ ghìm chiều cao lưới và bảng hàng. */
    @media (max-width: 1200px) {
        .menu-area > .div-handle-list-product { overflow: visible; }
        .product-cotainer { max-height: 52vh; }
        .product-table-container .mh_menu { max-height: 44vh !important; }
    }
</style>
@endpush

@push('scripts')
<script>
(function () {
    const cauHinh = document.getElementById('posCauHinh');
    if (!cauHinh) return;

    const D = cauHinh.dataset;
    // Hạn quyền bớt giá của CHÍNH người đang đăng nhập (API trả). 0 = không có ô bớt.
    const HAN_MUC = Number(D.discountLimit) || 0;
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const KHOA_HD = 'pos.hoa-don', KHOA_ANH = 'pos.anh-tat', KHOA_COT = 'pos.so-cot', KHOA_NHOM_AN = 'pos.nhom-an';
    // Hoá đơn có hàng mà để quên quá 12 giờ không còn là "lượt đang dở" — mở trang
    // ra thấy nó đứng sẵn là mời bán nhầm cho khách mới.
    const HAN_GIU_MS = 12 * 60 * 60 * 1000;
    const TOI_DA_HD = 12, TAB_HIEN = 3, CO_TRANG = 24;

    const $ = (id) => document.getElementById(id);
    const so = (n) => new Intl.NumberFormat('vi-VN').format(Math.round(n || 0));
    const tien = (n) => so(n) + '₫';
    const dinhSo = (n) => (n ? so(n) : '');
    const soTien = (s) => Number(String(s ?? '').replace(/\D/g, '')) || 0;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const doc = (k, macDinh) => { try { return localStorage.getItem(k) ?? macDinh; } catch (e) { return macDinh; } };
    const ghi = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* máy chặn lưu: vẫn bán được */ } };
    const hop = (id) => bootstrap.Modal.getOrCreateInstance($(id));
    const coHopMo = () => !!document.querySelector('.modal.show');
    const nhac = (m) => (window.toastr ? toastr.warning(m) : alert(m));
    const baoLoi = (m) => { $('posErr').textContent = m || ''; $('posErr').hidden = !m; };

    let hinhThuc = 'cash';
    let dangGui = false;
    let vuaThem = null;

    /* =====================================================================
     * HOÁ ĐƠN ĐANG MỞ — { id, so, luc, gio: [{ id, ten, opt, gia, ton, sl, giam, vat }],
     *                     ten, sdt, cusId, voucher, ghiChu, hinhThuc, dua,
     *                     giamDon: { kieu, gt }, phuThu: { kieu, gt, ghiChu },
     *                     hddt: { bat, mst, cty, diaChi, email },
     * `gia` chỉ để HIỂN THỊ; tiền thu do API tính lại lúc chốt.
     * ===================================================================== */
    const hd = { ds: [], dang: null };

    const soTrong = () => { const dung = new Set(hd.ds.map((h) => h.so)); let n = 1; while (dung.has(n)) n++; return n; };
    // Ba khối thêm sau (giảm cả đơn, phụ thu, hoá đơn điện tử). Hoá đơn lưu từ bản trước không có
    // chúng — nạp lên thì bù cho đủ, khỏi để mọi chỗ đọc phải hỏi "có chưa".
    const khoiMoi = () => ({
        giamDon: { kieu: 'percent', gt: 0 },
        phuThu: { kieu: 'amount', gt: 0, ghiChu: '' },
        hddt: { bat: false, mst: '', cty: '', diaChi: '', email: '' },
    });
    const duKhoi = (h) => { const m = khoiMoi(); Object.keys(m).forEach((k) => { h[k] = Object.assign(m[k], h[k] || {}); }); return h; };
    const hdMoi = () => duKhoi({
        id: 'hd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
        so: soTrong(), luc: Date.now(), gio: [], ten: '', sdt: '', cusId: '', voucher: '', ghiChu: '', hinhThuc: 'cash', dua: '',
    });
    const nhanHd = (h) => `Hoá đơn ${h.so}`;
    const cur = () => hd.ds.find((h) => h.id === hd.dang) || hd.ds[0];
    const gio = () => cur().gio;

    function napHd() {
        hd.ds = []; hd.dang = null;
        try {
            const x = JSON.parse(doc(KHOA_HD, 'null'));
            if (x && Array.isArray(x.ds)) {
                hd.ds = x.ds.filter((h) => h && Array.isArray(h.gio) && (!h.gio.length || Date.now() - Number(h.luc || 0) < HAN_GIU_MS)).map(duKhoi);
                hd.dang = x.dang;
            }
        } catch (e) { /* hỏng thì bắt đầu sạch */ }
        if (!hd.ds.length) hd.ds = [hdMoi()];
        if (!hd.ds.some((h) => h.id === hd.dang)) hd.dang = hd.ds[0].id;
    }
    function luu() { cur().luc = Date.now(); ghi(KHOA_HD, JSON.stringify(hd)); }

    function veTab() {
        const ds = hd.ds, i = ds.indexOf(cur());
        const bat = Math.max(0, Math.min(i, ds.length - TAB_HIEN));
        const hien = ds.slice(bat, bat + TAB_HIEN), an = ds.filter((h) => !hien.includes(h));
        $('invoiceTab').innerHTML = hien.map((h) => {
            const mo = h === cur() ? 'active' : '', n = h.gio.reduce((t, d) => t + d.sl, 0);
            return `<li class="invoice-tab position-relative ${mo}" role="presentation" data-code="${h.id}">
                <button type="button" class="nav-link text-capitalize px-0 ${mo}"><span class="px-3">${esc(nhanHd(h))}${n ? `<span class="dem">${n}</span>` : ''}</span></button>
                ${ds.length > 1 ? `<button type="button" class="dong" data-dong="${h.id}" title="Đóng hoá đơn này">×</button>` : ''}
            </li>`;
        }).join('');
        $('invoiceTabToggle').classList.toggle('d-none', !an.length);
        $('invoiceTabCount').textContent = an.length;
        $('invoiceTabDropdownList').innerHTML = an.map((h) => `<button type="button" class="invoice-dropdown-item" data-code="${h.id}">${esc(nhanHd(h))} · ${h.gio.length} món</button>`).join('');
        if (!an.length) $('invoiceTabDropdown').classList.add('d-none');
        $('newOrder').classList.toggle('disabled', ds.length >= TOI_DA_HD);
    }

    // Mã đơn CHỈ sinh ra lúc chốt, không giữ trước.
    //
    // Bản trước xin mã ngay khi có món đầu tiên để tab hiện mã thật như v2 cũ.
    // Nhưng v2 ghi hẳn đơn nháp xuống database nên số của nó luôn có một dòng
    // đứng tên, còn ở đây hoá đơn đang mở chỉ nằm trên máy quầy: huỷ giỏ là con
    // số bốc hơi, sổ đơn nhảy số mà không chỗ nào giải thích. Tab hiện
    // "Hoá đơn N" cho tới lúc đơn vào sổ.

    function chuyenHd(id) { hd.dang = id; luu(); veTatCa(); $('posSearch').focus(); }
    function themHd() {
        if (hd.ds.length >= TOI_DA_HD) { nhac(`Đang mở ${TOI_DA_HD} hoá đơn — chốt hoặc đóng bớt rồi mở thêm.`); return; }
        const h = hdMoi(); hd.ds.push(h); chuyenHd(h.id);
    }
    let hoiXong = null;
    function hoiXacNhan(o, xong) {
        $('posHoiTieuDe').textContent = o.tieuDe || 'Xác nhận';
        $('posHoiBody').innerHTML = (o.doan || []).map((d) => `<p class="mb-2">${esc(d)}</p>`).join('');
        $('posHoiOk').textContent = o.nutOk || 'Đồng ý';
        hoiXong = xong;
        hop('posHoiBox').show();
    }
    $('posHoiOk').addEventListener('click', () => {
        const f = hoiXong; hoiXong = null;
        hop('posHoiBox').hide();
        if (f) f(true);
    });
    // Bấm Đóng, bấm ra ngoài hay nhấn Esc đều là KHÔNG đồng ý.
    $('posHoiBox').addEventListener('hidden.bs.modal', () => {
        const f = hoiXong; hoiXong = null;
        if (f) f(false);
    });

    function dongHd(id, hoi = true) {
        const h = hd.ds.find((x) => x.id === id);
        if (!h) return;
        if (hoi && h.gio.length) {
            hoiXacNhan({
                tieuDe: 'Huỷ hoá đơn',
                doan: [`Huỷ ${nhanHd(h)} đang có ${h.gio.length} món?`, 'Giỏ hàng của hoá đơn này sẽ bị xoá.'],
                nutOk: 'Huỷ hoá đơn',
            }, (dongY) => { if (dongY) boHd(h); });

            return;
        }
        boHd(h);
    }
    function boHd(h) {
        hd.ds = hd.ds.filter((x) => x !== h);
        if (!hd.ds.length) hd.ds.push(hdMoi());
        if (hd.dang === h.id) hd.dang = hd.ds[hd.ds.length - 1].id;
        luu(); veTatCa();
    }

    $('invoiceTab').addEventListener('click', (e) => {
        const x = e.target.closest('[data-dong]');
        if (x) { dongHd(x.dataset.dong); return; }
        const li = e.target.closest('.invoice-tab');
        if (li && li.dataset.code !== hd.dang) chuyenHd(li.dataset.code);
    });
    $('newOrder').addEventListener('click', themHd);
    $('invoiceTabToggle').addEventListener('click', (e) => { e.stopPropagation(); $('invoiceTabDropdown').classList.toggle('d-none'); });
    $('invoiceTabDropdownList').addEventListener('click', (e) => {
        const b = e.target.closest('[data-code]');
        if (b) { $('invoiceTabDropdown').classList.add('d-none'); chuyenHd(b.dataset.code); }
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('#invoiceTabToggleContainer')) $('invoiceTabDropdown').classList.add('d-none'); });
    // Hai cửa sổ quầy trên cùng một máy: hoá đơn đổi bên này thì bên kia vẽ lại.
    window.addEventListener('storage', (e) => { if (e.key === KHOA_HD) { napHd(); veTatCa(); } });

    /* =====================================================================
     * Ô THÔNG TIN CỦA HOÁ ĐƠN
     * ===================================================================== */
    const O = { voucher: 'posVoucher', ghiChu: 'posNote', dua: 'posTendered' };

    function napForm() {
        const h = cur();
        Object.entries(O).forEach(([k, id]) => { $(id).value = h[k] || ''; });
        $('posNote').hidden = !h.ghiChu && $('posNote').dataset.mo !== '1';
        $('posNoteMark').style.display = h.ghiChu ? 'inline-block' : 'none';
        veKhach();
    }
    // Ô khách: tên (và số) khách đang bán, hoặc "Bán cho người tiêu dùng" như bản gốc.
    function veKhach() {
        const h = cur(), ten = h.ten.trim(), sdt = h.sdt.trim();
        $('posCusNhan').textContent = ten || sdt ? [ten, sdt].filter(Boolean).join(' - ') : 'Bán cho người tiêu dùng';
        $('posCusNhan').title = $('posCusNhan').textContent;
        $('posCusBtn').classList.toggle('is-khach', !!(ten || sdt));
    }
    const datKhach = (ten, sdt, cusId) => { Object.assign(cur(), { ten: ten || '', sdt: sdt || '', cusId: cusId || '' }); luu(); veKhach(); };

    // Cặp nút VNĐ / % của bản gốc (dùng cho giảm cả đơn và phụ thu).
    const datKieu = (boc, kieu) => $(boc).querySelectorAll('[data-kieu]').forEach((b) => b.classList.toggle('active', b.dataset.kieu === kieu));
    const soNhap = (v, kieu) => (kieu === 'amount' ? soTien(v) : Math.max(0, Number(String(v ?? '').replace(',', '.')) || 0));
    const hienNhap = (n, kieu) => (kieu === 'amount' ? dinhSo(n) : (n ? String(n).replace('.', ',') : ''));
    const CO_GIAM_DON = !!$('posGiamDon');

    // Hộp giảm giá: giảm tay trên cả đơn (trong hạn quyền) + mã khách đưa. Kẹp hạn quyền ở đây chỉ
    // là phép lịch sự — API chặn thật, kể cả khi gõ số tiền.
    let kieuGiam = 'percent';
    $('posGiamRow').addEventListener('click', () => {
        const h = cur();
        $('posVoucher').value = h.voucher;
        if (CO_GIAM_DON) { kieuGiam = h.giamDon.kieu; datKieu('posGiamKieu', kieuGiam); $('posGiamDon').value = hienNhap(h.giamDon.gt, kieuGiam); }
        hop('posGiamBox').show();
    });
    $('posGiamBox').addEventListener('shown.bs.modal', () => (CO_GIAM_DON ? $('posGiamDon') : $('posVoucher')).focus());
    if (CO_GIAM_DON) {
        const han = String(HAN_MUC).replace('.', ',');
        $('posGiamHan').textContent = (HAN_MUC >= 100 ? '' : `Tối đa ${han}% tiền hàng. `) + 'Bớt riêng từng món thì bấm ✎ cạnh đơn giá của món đó.';
        $('posGiamKieu').addEventListener('click', (e) => {
            const b = e.target.closest('[data-kieu]');
            if (!b || b.dataset.kieu === kieuGiam) return;
            kieuGiam = b.dataset.kieu; datKieu('posGiamKieu', kieuGiam); $('posGiamDon').value = ''; $('posGiamDon').focus();
        });
        $('posGiamDon').addEventListener('input', (e) => { if (kieuGiam === 'amount') e.target.value = dinhSo(soTien(e.target.value)); });
    }
    $('posGiamForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const h = cur();
        if (CO_GIAM_DON) {
            let gt = soNhap($('posGiamDon').value, kieuGiam);
            if (kieuGiam === 'percent') gt = Math.round(gt * 100) / 100;
            const tran = kieuGiam === 'percent' ? HAN_MUC : Math.floor(tienHang() * HAN_MUC / 100);
            if (HAN_MUC < 100 && gt > tran) {
                nhac(kieuGiam === 'percent' ? `Bạn được giảm cả đơn tối đa ${HAN_MUC}%.` : `Bạn được giảm cả đơn tối đa ${so(tran)} đ (${HAN_MUC}% tiền hàng).`);
                gt = tran;
            }
            h.giamDon = { kieu: kieuGiam, gt: kieuGiam === 'percent' ? Math.min(100, gt) : gt };
        }
        h.voucher = $('posVoucher').value.trim().toUpperCase(); luu(); capNhatTien(); hop('posGiamBox').hide();
    });
    $('posNote').addEventListener('input', (e) => {
        cur().ghiChu = e.target.value; luu();
        $('posNoteMark').style.display = e.target.value ? 'inline-block' : 'none';
    });
    // Nút Ghi chú bật/tắt ô ghi chú. Đã có chữ thì ô không đóng lại — ẩn đi là giấu
    // mất một điều khách dặn mà người bán sắp quên.
    $('posNoteBtn').addEventListener('click', () => {
        const mo = $('posNote').hidden;
        $('posNote').dataset.mo = mo ? '1' : '';
        $('posNote').hidden = !mo && !cur().ghiChu;
        if (mo) $('posNote').focus();
    });

    // Hộp chọn khách quen: bấm ô → ô tìm + danh sách (như select2 của bản gốc).
    let khachGoiY = [], lanHoiKhach = 0, henKhach = null;
    const veDsKhach = () => {
        $('posCusDS').innerHTML = `<button type="button" class="pos-ac-item" data-i="-1">Bán cho người tiêu dùng</button>`
            + khachGoiY.map((k, i) => `<button type="button" class="pos-ac-item" data-i="${i}">${esc(k.name || k.full_name || '')}<em>${esc(k.phone || '')}</em></button>`).join('')
            + (!khachGoiY.length && $('posCusName').value.trim().length >= 2 ? '<p class="pos-ac-empty">Không có khách quen nào khớp.</p>' : '');
    };
    const moChonKhach = (mo) => {
        $('posCusMenu').hidden = !mo;
        $('posCusBtn').setAttribute('aria-expanded', mo ? 'true' : 'false');
        if (mo) { $('posCusName').value = ''; khachGoiY = []; veDsKhach(); $('posCusName').focus(); }
    };
    $('posCusBtn').addEventListener('click', (e) => { e.stopPropagation(); moChonKhach($('posCusMenu').hidden); });
    $('posCusBtn').addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); moChonKhach($('posCusMenu').hidden); } });
    document.addEventListener('click', (e) => { if (!e.target.closest('#posKh')) moChonKhach(false); });
    $('posCusName').addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { moChonKhach(false); $('posCusBtn').focus(); }
        if (e.key === 'Enter') { e.preventDefault(); $('posCusDS').querySelector('.pos-ac-item[data-i]:not([data-i="-1"])')?.click(); }
    });
    $('posCusName').addEventListener('input', (e) => {
        clearTimeout(henKhach);
        const q = e.target.value.trim();
        if (q.length < 2) { khachGoiY = []; veDsKhach(); return; }
        henKhach = setTimeout(async () => {
            const lan = ++lanHoiKhach;
            try {
                const r = await fetch(`${D.customerUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
                const ds = r.ok ? ((await r.json()).data || []) : [];
                if (lan !== lanHoiKhach) return;
                khachGoiY = ds.slice(0, 8); veDsKhach();
            } catch (err) { khachGoiY = []; veDsKhach(); }
        }, 250);
    });
    $('posCusDS').addEventListener('click', (e) => {
        const b = e.target.closest('.pos-ac-item');
        if (!b) return;
        const k = khachGoiY[Number(b.dataset.i)];
        if (k) datKhach(k.name || k.full_name || '', k.phone || '', String(k.id || '')); else datKhach('', '', '');
        moChonKhach(false); $('posSearch').focus();
    });
    // Nút + cam: khách mới. Tích "Lưu" (mặc định) thì tạo hồ sơ qua API rồi chọn luôn khách đó —
    // trùng số điện thoại thì API trả hồ sơ có sẵn. Bỏ tích thì chỉ ghi tên, số lên hoá đơn này.
    const loiKhach = (m) => { $('posKhachLoi').textContent = m || ''; $('posKhachLoi').hidden = !m; };
    $('posCusMoi').addEventListener('click', () => {
        $('posKhachTen').value = cur().ten; $('posKhachSdt').value = cur().sdt;
        $('posKhachEmail').value = ''; $('posKhachDiaChi').value = '';
        loiKhach(''); hop('posKhachBox').show();
    });
    $('posKhachBox').addEventListener('shown.bs.modal', () => $('posKhachTen').focus());
    $('posKhachForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const ten = $('posKhachTen').value.trim(), sdt = $('posKhachSdt').value.trim();
        if (!$('posKhachLuu').checked) {
            // Gõ tay tên/số = không còn là khách quen đã chọn (cusId về rỗng) — tính điểm sai người còn tệ hơn không tính.
            datKhach(ten, sdt, ''); hop('posKhachBox').hide();
            return;
        }
        if (!ten) { loiKhach('Nhập tên khách để lưu hồ sơ.'); $('posKhachTen').focus(); return; }
        const nut = $('posKhachLuuNut');
        nut.disabled = true; loiKhach('');
        try {
            const r = await fetch(D.customerCreateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ full_name: ten, phone: sdt, email: $('posKhachEmail').value.trim(), address: $('posKhachDiaChi').value.trim() }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) { loiKhach((j.errors ? Object.values(j.errors)[0]?.[0] : '') || j.message || 'Không lưu được khách hàng.'); return; }
            const k = (j.data || {}).customer || {};
            datKhach(k.name || ten, k.phone || sdt, String(k.id || ''));
            // Email của hồ sơ là nơi nhận hoá đơn điện tử nếu người bán bật xuất cho lượt này.
            if (k.email && !cur().hddt.email) { cur().hddt.email = k.email; luu(); }
            hop('posKhachBox').hide();
            if (window.toastr) toastr[j.data?.existed ? 'info' : 'success'](j.message || 'Đã lưu khách.');
        } catch (err) {
            loiKhach('Mất kết nối — chưa lưu được khách. Bỏ tích "Lưu" để chỉ ghi lên hoá đơn này.');
        } finally {
            nut.disabled = false;
        }
    });
    $('posKhachXoa').addEventListener('click', () => { datKhach('', '', ''); hop('posKhachBox').hide(); });

    /* =====================================================================
     * LƯỚI HÀNG
     * ===================================================================== */
    const loc = { q: '', nhom: '', sapXep: '', trang: 1 };
    // Hai nút nhóm ảo → kiểu xếp gửi lên API (không lọc theo nhóm nào).
    const SAP_XEP_NHOM = { best_seller: 'best_selling', new: 'created_desc' };
    let dsHang = [], lanHoiHang = 0, henTim = null;
    let anhTat = doc(KHOA_ANH, '0') === '1';
    let soCot = Math.min(6, Math.max(2, Number(doc(KHOA_COT, '5')) || 5));

    const nhanBT = (v) => v.name || v.sku || '';
    const giaCua = (p, v) => { const g = Number(v.final_price ?? v.price ?? 0); return g > 0 ? g : Number(p.sale_price || p.base_price || 0); };
    const giaTrenThe = (p) => {
        const ds = (p.variants || []).map((v) => giaCua(p, v)).filter((g) => g > 0);
        if (!ds.length) return { gia: Number(p.sale_price || p.base_price || 0), tu: false };
        return { gia: Math.min(...ds), tu: new Set(ds).size > 1 };
    };

    async function taiHang() {
        const lan = ++lanHoiHang;
        const qs = new URLSearchParams({ q: loc.q, page: loc.trang, page_size: CO_TRANG });
        if (loc.nhom) qs.set('category_id', loc.nhom);
        if (loc.sapXep) qs.set('sort', loc.sapXep);
        try {
            const r = await fetch(`${D.searchUrl}?${qs}`, { headers: { Accept: 'application/json' } });
            const j = r.ok ? await r.json() : {};
            if (lan !== lanHoiHang) return;
            dsHang = j.data || [];
            veLuoi(); vePhanTrang(j.meta || {});
        } catch (e) {
            if (lan === lanHoiHang) $('list').innerHTML = '<p class="pos-hint">Không tải được hàng — kiểm tra mạng rồi thử lại.</p>';
        }
    }

    function veLuoi() {
        if (!dsHang.length) {
            $('list').innerHTML = `<p class="pos-hint">${loc.q ? `Không có hàng nào khớp “${esc(loc.q)}”.` : 'Nhóm này chưa có hàng.'}</p>`;
            return;
        }
        // Bề ngang thẻ đặt bằng inline style theo số cột — đúng cách trang thật làm.
        const rong = (100 / soCot).toFixed(4);
        $('list').innerHTML = `<div class="row list-menu scroll-container">${dsHang.map((p, i) => {
            const ton = (p.variants || []).reduce((t, v) => t + Number(v.stock || 0), 0), het = ton <= 0, g = giaTrenThe(p);
            const anh = anhTat ? '' : `<div class="d-flex justify-content-center bg-gray-light position-relative">${het ? '<span class="pos-het-hang">Hết hàng</span>' : ''}${p.thumbnail
                ? `<img class="card-img-top" draggable="false" loading="lazy" src="${esc(p.thumbnail)}" alt="">`
                : '<div class="card-img-top card-img-trong"><i class="fa-solid fa-box"></i></div>'}</div>`;
            return `<div data-id="${p.id}" class="product-item mb-3${anhTat ? ' hide-thumbnail-product' : ''}${het ? ' het-hang' : ''}" style="max-width: calc(${rong}%);">
                <a href="#" draggable="false" class="text-decoration-none chose_menu" data-i="${i}">
                    <div class="menu-item h-100" title="${esc(p.name)}">${anh}
                        <div class="card-body">
                            <h6 class="card-text w-100 text-center">${esc(p.name)}</h6>
                            <span class="menu-price">${g.tu ? 'từ ' : ''}${dinhSo(g.gia)}</span>
                            ${het && anhTat ? '<span class="pos-het-hang">Hết hàng</span>' : ''}
                        </div>
                    </div>
                </a>
            </div>`;
        }).join('')}</div>`;
    }

    function vePhanTrang(meta) {
        const n = Number(meta.total_pages || 0), t = Number(meta.page || loc.trang);
        if (n <= 1) { $('div_pagination').innerHTML = ''; return; }
        const nut = (den, huong, tat) => `<a class="text-decoration-none ${tat ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}" ${tat ? '' : `data-trang="${den}"`}><i class="fa fa-angle-${huong}"></i></a>`;
        $('div_pagination').innerHTML = `<div class="form_pagi d-flex justify-content-end align-items-center gap-2 mt-0">${nut(t - 1, 'left', t <= 1)}<h6 class="mb-0 text-black">${t} / ${n}</h6>${nut(t + 1, 'right', t >= n)}</div>`;
    }

    function datCot(n) {
        soCot = n; $('select_col_number').value = String(n); ghi(KHOA_COT, String(n)); veLuoi();
    }

    $('posSearch').addEventListener('input', (e) => {
        clearTimeout(henTim);
        henTim = setTimeout(() => { loc.q = e.target.value.trim(); loc.trang = 1; taiHang(); }, 250);
    });

    // Enter = thêm hàng. THỬ QUÉT TRƯỚC: máy quét mã vạch chỉ là bàn phím gõ rất nhanh
    // rồi Enter. Không phải mã trong sổ thì rơi về nghĩa cũ: thêm thẻ đầu của lưới.
    $('posSearch').addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const q = e.target.value.trim();
        if (!q) return;
        clearTimeout(henTim);
        try {
            const r = await fetch(`${D.scanUrl}?code=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
            if (r.ok) {
                const d = (await r.json()).data || {};
                them({ id: Number(d.product_variant_id), ten: d.product_name || '', opt: d.variant_name || d.sku || '', gia: Number(d.price || 0), ton: Number(d.stock || 0), vat: Number(d.vat || 0) });
                // Dọn ô ngay: máy quét không tự xoá, mã cũ còn đó thì mã sau nối vào thành rác.
                e.target.value = '';
                if (loc.q) { loc.q = ''; loc.trang = 1; taiHang(); }
                return;
            }
        } catch (err) { /* mất mạng: vẫn còn đường bấm thẻ */ }
        if (loc.q !== q) { loc.q = q; loc.trang = 1; await taiHang(); }
        const the = $('list').querySelector('.product-item:not(.het-hang) .chose_menu');
        if (the) the.click(); else nhac(`Không tìm thấy hàng nào khớp “${q}”.`);
    });

    $('posCats').addEventListener('click', (e) => {
        const nut = e.target.closest('.btn-category');
        if (!nut) return;
        $('posCats').querySelectorAll('.btn-category').forEach((x) => x.classList.toggle('active', x === nut));
        const sx = SAP_XEP_NHOM[nut.dataset.id] || '';
        loc.sapXep = sx; loc.nhom = sx ? '' : nut.dataset.id; loc.trang = 1; taiHang();
    });
    $('div_pagination').addEventListener('click', (e) => {
        const nut = e.target.closest('[data-trang]');
        if (nut) { loc.trang = Number(nut.dataset.trang); taiHang(); }
    });
    $('hide_thumbnail').addEventListener('change', (e) => { anhTat = e.target.checked; ghi(KHOA_ANH, anhTat ? '1' : '0'); veLuoi(); });
    $('select_col_number').addEventListener('change', (e) => datCot(Number(e.target.value) || 5));

    let hangDangChon = null;
    $('list').addEventListener('click', (e) => {
        const a = e.target.closest('.chose_menu');
        if (!a) return;
        e.preventDefault();
        const p = dsHang[Number(a.dataset.i)];
        if (!p) return;
        const conHang = (p.variants || []).filter((v) => Number(v.stock || 0) > 0);
        // Một phiên bản còn hàng thì vào giỏ luôn — hỏi lại một câu chỉ có một đáp án là bắt bấm thừa.
        if (conHang.length === 1) { themBienThe(p, conHang[0]); return; }
        if (!conHang.length) { nhac(`${p.name} đã hết hàng.`); return; }
        hangDangChon = p;
        $('posVarTen').textContent = p.name;
        $('posVarDS').innerHTML = (p.variants || []).map((v, i) => {
            const ton = Number(v.stock || 0);
            return `<div class="col-6 col-lg-4">
                <button type="button" class="btn w-100 text-start pos-var" data-i="${i}" ${ton <= 0 ? 'disabled' : ''} style="border:1px solid #dee2e6; border-radius:8px; padding:10px 12px;">
                    <span class="d-block fw-bold" style="color:#000084">${esc(nhanBT(v))}</span>
                    <span class="d-flex justify-content-between mt-1" style="font-size:12.5px; color:#6b7280">
                        <span class="fw-bold" style="color:#0151b4">${tien(giaCua(p, v))}</span>
                        <span class="${ton <= 0 ? 'text-danger' : ''}">${ton <= 0 ? 'Hết hàng' : 'Tồn ' + ton}</span>
                    </span>
                </button>
            </div>`;
        }).join('');
        hop('posVarBox').show();
    });
    const themBienThe = (p, v) => them({ id: Number(v.id), ten: p.name, opt: nhanBT(v), gia: giaCua(p, v), ton: Number(v.stock || 0), vat: Number(p.vat || 0) });
    $('posVarDS').addEventListener('click', (e) => {
        const b = e.target.closest('.pos-var');
        if (!b || b.disabled || !hangDangChon) return;
        themBienThe(hangDangChon, hangDangChon.variants[Number(b.dataset.i)]);
        hop('posVarBox').hide();
    });
    $('posVarBox').addEventListener('hidden.bs.modal', () => $('posSearch').focus());

    // Hai nút cuối hàng nhóm: mũi tên bung lưới (luật vẽ của bản gốc), phễu chọn nhóm hiện.
    $('posNhomBung').addEventListener('click', () => {
        const mo = $('posNhomBoc').classList.toggle('categories-expanded');
        $('posNhomBung').setAttribute('aria-expanded', mo ? 'true' : 'false');
        $('posNhomBung').title = mo ? 'Thu lại' : 'Tất cả';
        $('posNhomBung').querySelector('i').className = mo ? 'fa-solid fa-arrow-down' : 'fa-solid fa-arrow-up';
    });
    const nhomAn = () => { try { return new Set(JSON.parse(doc(KHOA_NHOM_AN, '[]')).map(String)); } catch (e) { return new Set(); } };
    function veNhom() {
        const an = nhomAn();
        $('posCats').querySelectorAll('.btn-category').forEach((b) => {
            if (b.dataset.id === '') return;
            b.hidden = an.has(b.dataset.id);
            // Đang đứng ở nhóm vừa bị ẩn: kéo về "Tất cả", không thì lưới lọc theo một nhóm không còn nút nào sáng.
            if (b.hidden && b.classList.contains('active')) $('posCats').querySelector('[data-id=""]').click();
        });
    }
    $('posNhomChon').addEventListener('click', () => {
        const an = nhomAn();
        $('posNhomDS').innerHTML = [...$('posCats').querySelectorAll('.btn-category')].filter((b) => b.dataset.id !== '')
            .map((b) => `<label class="form-check d-flex align-items-center gap-2 m-0" style="padding:6px 4px">
                <input class="form-check-input m-0" type="checkbox" value="${esc(b.dataset.id)}" ${an.has(b.dataset.id) ? '' : 'checked'}>
                <span>${esc(b.textContent.trim())}</span></label>`).join('') || '<p class="pos-ac-empty">Cửa hàng chưa có nhóm hàng nào.</p>';
        hop('posNhomBox').show();
    });
    $('posNhomDS').addEventListener('change', () => {
        ghi(KHOA_NHOM_AN, JSON.stringify([...$('posNhomDS').querySelectorAll('input:not(:checked)')].map((i) => i.value)));
        veNhom();
    });
    $('posNhomTatCa').addEventListener('click', () => {
        $('posNhomDS').querySelectorAll('input').forEach((i) => { i.checked = true; });
        ghi(KHOA_NHOM_AN, '[]'); veNhom();
    });

    /* =====================================================================
     * HÀNG TRONG HOÁ ĐƠN
     * ===================================================================== */
    const giaSauBot = (d) => d.gia * (1 - (d.giam || 0) / 100);
    // Cùng công thức với API (buildOrderItems): bớt = làm tròn(giá × SL × %), rồi mới trừ.
    const thanhTien = (d) => d.gia * d.sl - Math.round(d.gia * d.sl * (d.giam || 0) / 100);

    function them(m) {
        if (!m.id) return;
        const g = gio(), dong = g.find((d) => d.id === m.id);
        const ten = m.ten + (m.opt ? ` (${m.opt})` : '');
        if (m.ton <= 0) { nhac(`${ten} đã hết hàng.`); return; }
        const sl = (dong ? dong.sl : 0) + 1;
        if (sl > m.ton) { nhac(`${ten} chỉ còn ${m.ton}.`); return; }
        if (dong) Object.assign(dong, { sl, gia: m.gia, ton: m.ton, vat: m.vat || 0 });
        else g.push({ id: m.id, ten: m.ten, opt: m.opt, gia: m.gia, ton: m.ton, sl: 1, giam: 0, vat: m.vat || 0 });
        vuaThem = m.id;
        baoLoi('');
        luu(); veTab(); veGio(); capNhatTien();
    }

    function veGio() {
        const g = gio();
        if (!g.length) {
            $('posCart').innerHTML = `<tr class="order-item h-100 d-flex align-content-center" style="align-items: center;">
                <td colspan="8" class="border-none text-center image-emptyCart w-100 flex-column justify-content-center align-items-center" style="border-bottom: none;">
                    <img src="${esc(D.emptyImg)}" alt="emptyCart" width="100">
                    <p class="text-primary-400 text-secondary pt-2 text-medium">Bạn chưa thêm sản phẩm nào</p>
                    <p class="text-primary-400 text-secondary text-medium">Nhân viên: <strong class="text-medium">${esc(D.nguoiBan)}</strong></p>
                </td></tr>`;
            return;
        }
        $('posCart').innerHTML = g.map((d, i) => `<tr class="order-item align-baseline d-flex ${d.id === vuaThem ? 'order-item-just-added' : ''}" data-i="${i}">
            <td class="text-left">
                <h5 class="mb-1 name-menu">${esc(d.ten)}${d.opt ? `<span class="ten-bien-the">${esc(d.opt)}</span>` : ''}</h5>
            </td>
            <td style="vertical-align: top;">
                <div class="d-flex align-items-center justify-content-center">
                    <div class="d-flex align-items-center border-info input-group-number mx-auto quantity-pay">
                        <button type="button" class="iconButton btn-number p-1 my-auto mx-1 d-flex" data-tru aria-label="Bớt một"><span class="fa fa-minus m-auto"></span></button>
                        <input type="text" class="form-control shadow-none input-number quantity my-auto" inputmode="numeric" pattern="[0-9]*" value="${d.sl}" data-sl aria-label="Số lượng">
                        <button type="button" class="iconButton btn-number p-1 my-auto mx-1 d-flex" data-cong aria-label="Thêm một"><span class="fa fa-plus m-auto"></span></button>
                    </div>
                </div>
            </td>
            <td class="fw-bold price" style="vertical-align: top;">
                <div class="d-flex justify-content-center align-items-center gap-1">${so(giaSauBot(d))}${HAN_MUC > 0 ? `<i class="fas fa-edit color-1a234a mo-giam" data-mo-giam role="button" title="Bớt giá món này"></i>` : ''}</div>
                ${d.giam > 0 ? `<span class="gia-goc">${so(d.gia)} · bớt ${d.giam}%</span>` : ''}
                ${HAN_MUC > 0 ? `<div class="cum-giam" ${d.giam > 0 ? '' : 'hidden'}><input class="form-control o-giam" inputmode="decimal" value="${d.giam || ''}" placeholder="0" data-giam aria-label="Bớt phần trăm">%</div>` : ''}
            </td>
            <td class="amount" style="vertical-align: top;"><p class="sale-price-item">${so(thanhTien(d))} đ</p></td>
            <td><a href="#" class="text-decoration-none delete_order" data-xoa title="Bỏ món"><i class="fa-solid fa-xmark"></i></a></td>
        </tr>`).join('');
        vuaThem = null;
    }

    function sauKhiSuaGio() { luu(); veTab(); veGio(); capNhatTien(); }
    $('posCart').addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-i]');
        if (!tr) return;
        const g = gio(), i = Number(tr.dataset.i), d = g[i];
        if (e.target.closest('[data-mo-giam]')) { const o = tr.querySelector('.cum-giam'); o.hidden = !o.hidden; if (!o.hidden) o.querySelector('input').focus(); return; }
        if (e.target.closest('[data-xoa]')) { e.preventDefault(); g.splice(i, 1); }
        else if (e.target.closest('[data-tru]')) { if (--d.sl <= 0) g.splice(i, 1); }
        else if (e.target.closest('[data-cong]')) { if (d.sl + 1 > d.ton) { nhac(`${d.ten} chỉ còn ${d.ton}.`); return; } d.sl++; }
        else return;
        sauKhiSuaGio();
    });
    $('posCart').addEventListener('change', (e) => {
        const tr = e.target.closest('tr[data-i]');
        if (!tr) return;
        const g = gio(), i = Number(tr.dataset.i), d = g[i];
        if (e.target.matches('[data-sl]')) {
            const n = Math.floor(soTien(e.target.value));
            if (n <= 0) g.splice(i, 1);
            else if (n > d.ton) { nhac(`${d.ten} chỉ còn ${d.ton}.`); d.sl = d.ton; }
            else d.sl = n;
        } else if (e.target.matches('[data-giam]')) {
            const v = Number(String(e.target.value).replace(',', '.')) || 0;
            // Kẹp theo hạn quyền API trả — gõ quá thì hạ về mức tối đa và nói rõ.
            if (v > HAN_MUC) nhac(`Bạn được bớt tối đa ${HAN_MUC}% mỗi món.`);
            d.giam = Math.min(HAN_MUC, Math.max(0, Math.round(v * 100) / 100));
        } else return;
        // Vẽ lại ở TICK SAU: `change` của ô số lượng / ô bớt % bắn ra ngay trong
        // lượt blur, mà veGio() thay cả innerHTML của giỏ — tức xoá đúng ô đang
        // mất focus, nên Chrome kêu "node to be removed is no longer a child".
        setTimeout(sauKhiSuaGio);
    });
    $('posCart').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input')) e.target.blur(); });

    $('posXoaHet').addEventListener('click', () => {
        if (gio().length) {
            hoiXacNhan({
                tieuDe: 'Xoá hết hàng',
                doan: ['Xoá hết hàng trong hoá đơn này?'],
                nutOk: 'Xoá hết',
            }, (dongY) => { if (dongY) { cur().gio = []; sauKhiSuaGio(); } });
        }
        bootstrap.Collapse.getOrCreateInstance($('extraOptions'), { toggle: false }).hide();
    });
    $('posClear').addEventListener('click', () => dongHd(cur().id));

    // PHỤ THU (hàng "Phụ thu ✎"): lý do + số tiền hoặc %. % tính trên tiền hàng lúc chốt.
    let kieuPhuThu = 'amount';
    const veQuyDoiPhuThu = () => {
        const gt = soNhap($('posPhuThuGt').value, kieuPhuThu);
        $('posPhuThuQuyDoi').textContent = kieuPhuThu === 'percent' && gt > 0 ? `= ${so(Math.round(tienHang() * gt / 100))} đ trên tiền hàng hiện tại` : '';
    };
    $('extra_fee').addEventListener('click', () => {
        const p = cur().phuThu;
        kieuPhuThu = p.kieu; datKieu('posPhuThuKieu', kieuPhuThu);
        $('posPhuThuGt').value = hienNhap(p.gt, kieuPhuThu); $('posPhuThuLyDo').value = p.ghiChu || '';
        veQuyDoiPhuThu(); hop('posPhuThuBox').show();
    });
    $('posPhuThuBox').addEventListener('shown.bs.modal', () => $('posPhuThuGt').focus());
    $('posPhuThuKieu').addEventListener('click', (e) => {
        const b = e.target.closest('[data-kieu]');
        if (!b || b.dataset.kieu === kieuPhuThu) return;
        kieuPhuThu = b.dataset.kieu; datKieu('posPhuThuKieu', kieuPhuThu); $('posPhuThuGt').value = ''; veQuyDoiPhuThu(); $('posPhuThuGt').focus();
    });
    $('posPhuThuGt').addEventListener('input', (e) => { if (kieuPhuThu === 'amount') e.target.value = dinhSo(soTien(e.target.value)); veQuyDoiPhuThu(); });
    $('posPhuThuForm').addEventListener('submit', (e) => {
        e.preventDefault();
        let gt = soNhap($('posPhuThuGt').value, kieuPhuThu);
        if (kieuPhuThu === 'percent') gt = Math.min(100, Math.round(gt * 100) / 100);
        cur().phuThu = { kieu: kieuPhuThu, gt, ghiChu: $('posPhuThuLyDo').value.trim() };
        luu(); capNhatTien(); hop('posPhuThuBox').hide();
    });
    $('posPhuThuBo').addEventListener('click', () => { cur().phuThu = khoiMoi().phuThu; luu(); capNhatTien(); hop('posPhuThuBox').hide(); });

    // HOÁ ĐƠN ĐIỆN TỬ: bật cho hoá đơn đang mở + thông tin người mua lấy hoá đơn.
    const veHddtO = () => { $('posHddtO').style.opacity = $('posHddtBat').checked ? '1' : '.5'; };
    const loiHddt = (m) => { $('posHddtLoi').textContent = m || ''; $('posHddtLoi').hidden = !m; };
    $('posHddt').addEventListener('click', () => {
        const x = cur().hddt;
        $('posHddtBat').checked = !!x.bat;
        $('posHddtMst').value = x.mst; $('posHddtCty').value = x.cty; $('posHddtDiaChi').value = x.diaChi; $('posHddtEmail').value = x.email;
        loiHddt(''); veHddtO(); hop('posHddtBox').show();
    });
    $('posHddtBat').addEventListener('change', veHddtO);
    $('posHddtForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const x = { bat: $('posHddtBat').checked, mst: $('posHddtMst').value.trim(), cty: $('posHddtCty').value.trim(), diaChi: $('posHddtDiaChi').value.trim(), email: $('posHddtEmail').value.trim() };
        if (x.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x.email)) { loiHddt('Email nhận hoá đơn chưa đúng dạng.'); $('posHddtEmail').focus(); return; }
        if (x.mst && !x.cty) { loiHddt('Có mã số thuế thì nhập cả tên đơn vị.'); $('posHddtCty').focus(); return; }
        cur().hddt = x; luu(); capNhatTien(); hop('posHddtBox').hide();
    });

    // TẠM TÍNH: in phiếu tạm tính của hoá đơn đang mở để khách xem trước khi trả tiền.
    // Chưa ghi gì vào sổ — giá cuối cùng vẫn do API tính lúc chốt.
    $('posTamTinh').addEventListener('click', () => {
        const g = gio();
        if (!g.length) return;
        const w = window.open('', '_blank', 'width=420,height=680');
        if (!w) { nhac('Trình duyệt chặn cửa sổ in — cho phép cửa sổ bật lên rồi bấm lại.'); return; }
        const dong = g.map((d) => `<div class="mon"><div>${esc(d.ten)}${d.opt ? ` (${esc(d.opt)})` : ''}</div>`
            + `<div class="dong thut"><span>${d.sl} × ${so(giaSauBot(d))}</span><span>${so(thanhTien(d))}</span></div></div>`).join('');
        const luc = new Date().toLocaleString('vi-VN', { hour12: false });
        w.document.write(`<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>Phiếu tạm tính</title><style>`
            + `@page{size:80mm auto;margin:0}*{box-sizing:border-box}body{width:76mm;margin:0 auto;padding:4mm 2mm 8mm;font-family:"Cascadia Mono",Consolas,"DejaVu Sans Mono",monospace;font-size:12px;line-height:1.45;color:#000}`
            + `.giua{text-align:center}.dong{display:flex;justify-content:space-between;gap:6px}.thut{padding-left:8px}.mon{margin-top:4px}.ke{margin:4px 0;border-top:1px dashed #000}.tong{margin-top:4px;font-size:1.2em;font-weight:700}`
            + `</style></head><body><div class="giua" style="font-size:1.2em;font-weight:700">${esc(D.tenTiem)}</div><div class="ke"></div>`
            + `<div class="giua" style="font-weight:700">PHIẾU TẠM TÍNH</div><div class="dong"><span>${esc(nhanHd(cur()))}</span><span>${luc}</span></div><div class="ke"></div>${dong}<div class="ke"></div>`
            + `<div class="dong"><span>Tiền hàng</span><span>${so(tamTinh())}</span></div>${botMon() > 0 ? `<div class="dong"><span>Bớt theo món</span><span>-${so(botMon())}</span></div>` : ''}`
            + `${giamDon() > 0 ? `<div class="dong"><span>Giảm cả đơn</span><span>-${so(giamDon())}</span></div>` : ''}`
            + `${phuThu() > 0 ? `<div class="dong"><span>Phụ thu${cur().phuThu.ghiChu ? ': ' + esc(cur().phuThu.ghiChu) : ''}</span><span>${so(phuThu())}</span></div>` : ''}`
            + `${thue() > 0 ? `<div class="dong"><span>Thuế sản phẩm</span><span>${so(thue())}</span></div>` : ''}`
            + `<div class="dong tong"><span>TỔNG CỘNG</span><span>${so(phaiTra())}</span></div><div class="giua" style="margin-top:8px">(Chưa thanh toán)</div>`
            + `<script>window.onload=function(){setTimeout(function(){window.print()},120)}<\/script></body></html>`);
        w.document.close();
    });

    /* =====================================================================
     * TIỀN
     * ===================================================================== */
    const tongSl = () => gio().reduce((t, d) => t + d.sl, 0);
    const tamTinh = () => gio().reduce((t, d) => t + d.gia * d.sl, 0);
    const botMon = () => gio().reduce((t, d) => t + (d.gia * d.sl - thanhTien(d)), 0);
    // Tiền hàng SAU bớt từng món — nền của giảm cả đơn, phụ thu theo % và thuế (API gọi là subtotal).
    const tienHang = () => gio().reduce((t, d) => t + thanhTien(d), 0);
    function giamDon() {
        if (!CO_GIAM_DON) return 0;
        const g = cur().giamDon, th = tienHang();
        const v = g.kieu === 'amount' ? Math.round(Number(g.gt) || 0) : Math.round(th * (Number(g.gt) || 0) / 100);
        return Math.max(0, Math.min(v, th));
    }
    function phuThu() {
        const p = cur().phuThu, gt = Number(p.gt) || 0;
        return Math.max(0, p.kieu === 'percent' ? Math.round(tienHang() * gt / 100) : Math.round(gt));
    }
    // Thuế sản phẩm TẠM TÍNH theo đúng cách API tính (thueCuaDon): chia giảm cả đơn về từng dòng theo
    // tỉ trọng (đồng lẻ dồn dòng cuối), rồi thuế trên phần còn lại. Mã giảm giá chưa biết số tiền nên
    // chưa trừ ở đây — con số thu thật là con số API trả sau khi chốt.
    function thue() {
        const g = gio(), goc = tienHang(), tong = Math.min(giamDon(), goc);
        let daChia = 0, cong = 0;
        g.forEach((d, i) => {
            let chia = 0;
            if (tong > 0 && goc > 0) {
                chia = i === g.length - 1 ? tong - daChia : Math.round(tong * thanhTien(d) / goc);
                daChia += chia;
            }
            const muc = Number(d.vat) || 0;
            if (muc > 0) cong += Math.round(Math.round(thanhTien(d) - chia) * muc / 100);
        });
        return cong;
    }
    const phaiTra = () => Math.max(0, tienHang() - giamDon()) + phuThu() + thue();

    function chonHinhThuc(m, luuLai = true) {
        const nut = $('posPayTabs').querySelector(`[data-method="${m}"]`) || $('posPayTabs').querySelector('[data-method]');
        hinhThuc = nut.dataset.method;
        $('posPayTabs').querySelectorAll('[data-method]').forEach((b) => b.classList.toggle('active', b === nut));
        cur().hinhThuc = hinhThuc;
        if (luuLai) luu();
        capNhatTien();
    }
    $('posPayTabs').addEventListener('click', (e) => { const b = e.target.closest('[data-method]'); if (b) chonHinhThuc(b.dataset.method); });

    function capNhatTien() {
        const tong = phaiTra(), bot = botMon(), gd = giamDon(), cut = bot + gd, dua = soTien($('posTendered').value), coHang = gio().length > 0, tienMat = hinhThuc === 'cash';
        const h = cur(), ma = h.voucher.trim(), pt = phuThu();
        $('posQty').textContent = tongSl();
        $('posGross').textContent = so(tamTinh()) + ' đ';
        $('posCut').textContent = (cut > 0 ? '−' : '') + so(cut) + ' đ';
        $('posGiamTen').textContent = [
            bot > 0 ? 'bớt theo món' : '',
            gd > 0 ? (h.giamDon.kieu === 'percent' ? `cả đơn ${String(h.giamDon.gt).replace('.', ',')}%` : 'cả đơn') : '',
            ma ? 'mã ' + ma : '',
        ].filter(Boolean).join(', ');
        $('posPhuThu').textContent = so(pt) + ' đ';
        $('posPhuThuTen').textContent = pt > 0 ? (h.phuThu.ghiChu || '') : '';
        $('posThue').textContent = so(thue()) + ' đ';
        $('posHddt').classList.toggle('is-bat', !!h.hddt.bat);
        $('posHddtMark').style.display = h.hddt.bat ? 'inline-block' : 'none';
        $('posTotal').textContent = so(tong) + ' đ';
        $('posSubmit').disabled = !coHang || dangGui;
        $('posTamTinh').disabled = !coHang;
        // Giỏ trống và chỉ còn một hoá đơn thì không có gì để huỷ.
        $('posClear').disabled = !coHang && hd.ds.length <= 1;

        // Hộp xác nhận thanh toán.
        $('posPayHd').textContent = nhanHd(cur());
        $('posPayHinhThuc').textContent = $('posPayTabs').querySelector('[data-method].active')?.textContent.trim() || '';
        $('posPayTong').textContent = so(tong) + ' đ';
        $('posPayTienMat').hidden = !tienMat;
        const thoi = dua - tong;
        $('posChange').hidden = !(tienMat && dua > 0);
        $('posChange').classList.toggle('is-thieu', thoi < 0);
        $('posChangeLabel').textContent = thoi >= 0 ? 'Tiền thừa trả khách' : 'Khách đưa còn thiếu';
        $('posChangeVal').textContent = so(Math.abs(thoi)) + ' đ';
        const thieu = tienMat && dua > 0 && thoi < 0;
        $('posXacNhan').disabled = $('posXacNhanIn').disabled = !coHang || thieu || dangGui;
        // Ba con số đáng bấm nhất: trả đúng, chẵn chục nghìn, chẵn trăm nghìn.
        $('posGoiYTien').innerHTML = tong > 0
            ? [...new Set([tong, Math.ceil(tong / 10000) * 10000, Math.ceil(tong / 100000) * 100000])]
                .map((v, i) => `<span class="hint_cash_suggest_value" data-dat="${v}">${i === 0 ? 'Đủ ' : ''}${so(v)}đ</span>`).join('')
            : '';
    }
    const datDua = (n) => { $('posTendered').value = dinhSo(n); cur().dua = $('posTendered').value; luu(); capNhatTien(); };

    $('posTendered').addEventListener('input', (e) => { e.target.value = dinhSo(soTien(e.target.value)); cur().dua = e.target.value; luu(); capNhatTien(); });
    // Enter trong ô Khách đưa = chốt: gõ xong số tiền là việc cuối cùng.
    $('posTendered').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('posXacNhan').click(); } });
    ['posGoiYTien', 'posMenhGia'].forEach((id) => $(id).addEventListener('mousedown', (e) => e.preventDefault()));
    $('posGoiYTien').addEventListener('click', (e) => { const c = e.target.closest('[data-dat]'); if (c) datDua(Number(c.dataset.dat)); });
    $('posMenhGia').addEventListener('click', (e) => { const b = e.target.closest('[data-add]'); if (b) datDua(soTien($('posTendered').value) + Number(b.dataset.add)); });

    // Nút Thanh toán mở hộp xác nhận (như bản gốc); chốt thật ở hai nút trong hộp.
    function moPay() {
        if (!gio().length || dangGui) return;
        baoLoi(''); capNhatTien(); hop('posPayBox').show();
    }
    $('posSubmit').addEventListener('click', moPay);
    $('posPayBox').addEventListener('shown.bs.modal', () => { if (hinhThuc === 'cash') { $('posTendered').focus(); $('posTendered').select(); } });

    /* =====================================================================
     * CHỐT ĐƠN
     * ===================================================================== */
    async function chot(inPhieu) {
        const h = cur();
        if (!h.gio.length || dangGui) return;
        const tong = phaiTra(), dua = soTien($('posTendered').value);
        if (hinhThuc === 'cash' && dua > 0 && dua < tong) { baoLoi(`Khách đưa còn thiếu ${so(tong - dua)} đ.`); return; }

        baoLoi('');
        dangGui = true; capNhatTien();
        // Mở cửa sổ phiếu NGAY trong lượt bấm: mở sau `await` là trình duyệt chặn popup.
        const cuaSo = inPhieu ? window.open('about:blank', '_blank') : null;

        const payload = {
            payment_method: hinhThuc,
            customer_name: h.ten.trim(), customer_phone: h.sdt.trim(),
            voucher_code: h.voucher.trim(), note: h.ghiChu.trim(),
            items: h.gio.map((d) => ({ product_variant_id: d.id, quantity: d.sl, discount_percent: d.giam || 0 })),
        };
        if (h.cusId) payload.user_id = Number(h.cusId);
        if (hinhThuc === 'cash' && dua > 0) payload.amount_tendered = dua;
        // Giảm cả đơn: gửi ĐÚNG kiểu người bán gõ — % thì API tự tính tiền và kiểm hạn quyền trên %.
        if (giamDon() > 0) {
            if (h.giamDon.kieu === 'percent') payload.order_discount_percent = Number(h.giamDon.gt);
            else payload.order_discount_amount = giamDon();
        }
        if (phuThu() > 0) { payload.surcharge_amount = phuThu(); payload.surcharge_note = (h.phuThu.ghiChu || '').trim(); }
        if (h.hddt.bat) {
            payload.issue_einvoice = true;
            [['customer_email', 'email'], ['buyer_tax_code', 'mst'], ['buyer_company', 'cty'], ['buyer_address', 'diaChi']]
                .forEach(([khoa, o]) => { if (h.hddt[o]) payload[khoa] = h.hddt[o]; });
        }

        try {
            const r = await fetch(D.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify(payload),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) {
                if (cuaSo) cuaSo.close();
                const loiO = j.errors ? Object.values(j.errors)[0]?.[0] : '';
                // In NGUYÊN câu API trả: nó nói rõ món nào hết, thiếu bao nhiêu.
                baoLoi(loiO || j.message || 'Không hoàn tất được lượt bán.');
                return;
            }
            const d = j.data || {};
            const phieu = D.receiptUrl.replace('/0/receipt', `/${d.order_id}/receipt`);
            if (cuaSo) cuaSo.location.href = phieu;

            // Hoá đơn vừa chốt nhường chỗ cho một hoá đơn trống, sẵn cho khách sau.
            hd.ds = hd.ds.filter((x) => x !== h);
            const moi = hdMoi(); hd.ds.push(moi); hd.dang = moi.id;
            luu(); veTatCa();

            hop('posPayBox').hide();
            $('posDoneMa').textContent = d.order_code || '';
            $('posDoneTong').textContent = tien(d.total_amount ?? tong);
            $('posDoneThoi').textContent = tien(d.change_amount || 0);
            $('posDoneThoiDong').hidden = !(Number(d.change_amount) > 0);
            $('posDoneIn').href = phieu;
            veHoaDonXong(d.order_id, d.einvoice || null);
            hop('posDoneBox').show();
        } catch (e) {
            if (cuaSo) cuaSo.close();
            baoLoi('Mất kết nối — lượt bán CHƯA được ghi. Kiểm tra mạng rồi bấm lại.');
        } finally {
            dangGui = false; capNhatTien();
        }
    }
    $('posXacNhan').addEventListener('click', () => chot(false));
    $('posXacNhanIn').addEventListener('click', () => chot(true));

    // Hoá đơn điện tử của lượt vừa bán: in kết quả xuất ngay (nếu đã bật), và cho bấm xuất / xuất lại.
    // Hỏng không phải lỗi của lượt bán — đơn đã ghi và đã thu tiền.
    let donVuaBan = 0;
    function veHoaDonXong(id, kq) {
        donVuaBan = Number(id) || 0;
        $('posDoneHddt').hidden = !donVuaBan;
        $('posDoneHddtCau').hidden = !kq;
        $('posDoneHddtCau').textContent = kq ? kq.message || '' : '';
        $('posDoneHddtCau').style.color = kq ? (kq.ok ? '#16a34a' : '#cf1322') : '';
        $('posDoneHddtLai').hidden = !!(kq && kq.ok);
        $('posDoneHddtLai').innerHTML = `<i class="fa-solid fa-file-invoice"></i> ${kq ? 'Xuất lại hoá đơn điện tử' : 'Xuất hoá đơn điện tử'}`;
    }
    $('posDoneHddtLai').addEventListener('click', async () => {
        if (!donVuaBan) return;
        const nut = $('posDoneHddtLai');
        nut.disabled = true;
        try {
            const r = await fetch(D.einvoiceUrl.replace('/0/einvoice', `/${donVuaBan}/einvoice`), {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            });
            const j = await r.json().catch(() => ({}));
            veHoaDonXong(donVuaBan, { ok: r.ok, message: j.message || (r.ok ? 'Đã phát hành hoá đơn.' : 'Phát hành hoá đơn không thành công.') });
        } catch (e) {
            veHoaDonXong(donVuaBan, { ok: false, message: 'Mất kết nối — hoá đơn chưa được xuất. Bấm lại khi có mạng.' });
        } finally {
            nut.disabled = false;
        }
    });
    $('posDoneBox').addEventListener('hidden.bs.modal', () => $('posSearch').focus());
    $('posPayBox').addEventListener('hidden.bs.modal', () => { if (!coHopMo()) $('posSearch').focus(); });
    $('posDoneBox').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.target.closest('a, button')) { e.preventDefault(); hop('posDoneBox').hide(); } });

    document.addEventListener('keydown', (e) => {
        if (coHopMo()) return;
        if (e.key === 'F2') { e.preventDefault(); themHd(); }
        else if (e.key === 'F1' || e.key === 'F3') { e.preventDefault(); $('posSearch').focus(); $('posSearch').select(); }
        else if (e.key === 'F4') { e.preventDefault(); if (hinhThuc !== 'cash') chonHinhThuc('cash'); moPay(); }
        else if (e.key === 'F9') { e.preventDefault(); moPay(); }
    });

    /* ===================================================================== */
    function veTatCa() {
        veTab(); napForm(); veGio();
        chonHinhThuc(cur().hinhThuc || 'cash', false);
    }

    napHd();
    $('hide_thumbnail').checked = anhTat;
    $('select_col_number').value = String(soCot);
    veNhom();
    veTatCa();
    taiHang();
    // Hoá đơn còn hàng từ lượt trước mà chưa có mã (mất mạng, hoặc mã vừa hết hạn): xin lại.
})();
</script>
@endpush
