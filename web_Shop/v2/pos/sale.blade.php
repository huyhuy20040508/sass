{{--
    Bán hàng — màn chính của quầy (bản v3 "thanh dọc + biên lai").

    Hai khu: hàng hoá (tìm / quét mã · nhóm · lưới thẻ) và hoá đơn trình bày như tờ biên
    lai (tab hoá đơn · khách · dòng hàng · cụm tiền · Thanh toán). Dưới 900px hai khu
    thành hai tab, thanh đáy giữ tổng và nút Thanh toán.

    Nhiều hoá đơn giữ ở localStorage của máy. Giá trên màn chỉ để HIỂN THỊ — tiền thu do
    API tính lại lúc chốt.

    Phím tắt: F1 (hoặc F3) ô tìm · F2 hoá đơn mới · F4 thanh toán tiền mặt · F9 thanh
    toán · F8 in lại phiếu vừa bán · Ctrl+Z hoàn tác lần xoá món vừa rồi.
--}}
@extends('v2::layouts.pos')

@php
    $B = \App\Http\Controllers\PosController::class;
    $coGiam = $hanMucGiam > 0;
    $cnQuay = \App\Services\CurrentBranch::danhSach();
    $nguoiBan = trim((string) data_get(session('api.user'), 'full_name', '')) ?: (string) data_get(session('api.user'), 'username', 'Nhân viên');
    $tenTiem = app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name'));
    $tenChiNhanh = (string) (collect($cnQuay['ds'])->firstWhere('id', $cnQuay['dangChon'])['name'] ?? $tenTiem);
    $iconHinhThuc = ['cash' => 'fa-money-bill-wave', 'bank_transfer' => 'fa-building-columns'];
@endphp

@section('title', $B::TITLE)

@section('content')
    <h1 class="sr-only">Bán tại quầy</h1>

    <div id="posCauHinh" hidden
         data-search-url="{{ route('admin.orders.searchProducts') }}"
         data-customer-url="{{ route('thu-ngan.ban-hang.khach') }}"
         data-customer-create-url="{{ route('thu-ngan.ban-hang.taoKhach') }}"
         data-einvoice-url="{{ route('thu-ngan.ban-hang.hoaDon', ['id' => 0]) }}"
         data-scan-url="{{ route('thu-ngan.ban-hang.scan') }}"
         data-dong-gia-url="{{ route('thu-ngan.ban-hang.dongGia') }}"
         data-khuyen-mai-url="{{ route('thu-ngan.ban-hang.khuyenMai') }}"
         data-voucher-url="{{ route('thu-ngan.ban-hang.voucher') }}"
         data-store-url="{{ route('thu-ngan.ban-hang.store') }}"
         data-receipt-url="{{ route('thu-ngan.ban-hang.phieu', ['id' => 0]) }}"
         data-discount-limit="{{ $hanMucGiam }}"
         data-thanh-vien="{{ json_encode($thanhVien ?? ['ranks' => [], 'money_per_point' => 0]) }}"
         data-ngan-hang="{{ json_encode($nganHang ?? []) }}"
         data-phuong-thuc="{{ json_encode($phuongThuc ?? []) }}"
         data-qr-url="{{ route('thu-ngan.ban-hang.qrTrangThai', ['code' => '__MA__']) }}"
         data-huy-qr-url="{{ route('thu-ngan.ban-hang.huyQr', ['id' => 0]) }}"
         data-doi-ht-url="{{ route('thu-ngan.ban-hang.doiHinhThuc', ['id' => 0]) }}"
         data-mo-ca-url="{{ route('thu-ngan.ca-lam-viec.mo') }}"
         data-ca-mo="{{ $caMo === false ? '0' : '1' }}"
         data-nguoi-ban="{{ $nguoiBan }}"
         data-ten-tiem="{{ $tenTiem }}"
         data-empty-img="{{ asset('v2/images/icons/emptyCart.svg') }}"></div>

    <div class="pos-switch" role="tablist" aria-label="Khu làm việc">
        <button type="button" role="tab" aria-selected="true" aria-controls="paneGoods" data-switch="goods"><i class="fa-solid fa-grip"></i> Hàng</button>
        <button type="button" role="tab" aria-selected="false" aria-controls="paneBill" data-switch="bill"><i class="fa-solid fa-receipt"></i> Hoá đơn <span class="num" id="posSwitchDem">(0)</span></button>
    </div>

    <section class="pane goods" id="paneGoods" aria-label="Hàng hoá">
        @if($quaLau ?? null)
            {{-- Chỉ nhắc, không chặn bán: ca bỏ quên làm mọi con số đối chiếu két vô nghĩa. --}}
            <div class="ca-qua-lau" role="alert"><i class="fa-solid fa-clock"></i><span>{{ $quaLau['cau'] }}</span><a href="{{ route('thu-ngan.ca-lam-viec.index') }}">Điều phối ca</a></div>
        @endif
        <div class="goods-head">
            <div class="search">
                <label class="sr-only" for="posSearch">Tìm hàng hoặc quét mã vạch</label>
                <i class="fa-solid fa-barcode search__icon"></i>
                <input id="posSearch" type="search" autocomplete="off" autofocus placeholder="Tìm hoặc quét mã vạch">
                <kbd>F1</kbd>
            </div>
            <div class="goods-tools">
                <label class="switch"><input type="checkbox" id="hide_thumbnail"> Ẩn ảnh</label>
                <label class="col-picker"><span class="sr-only">Số cột</span>
                    <select class="select" id="select_col_number">
                        @foreach([2, 3, 4, 5, 6] as $c)
                            <option value="{{ $c }}">{{ $c }} cột</option>
                        @endforeach
                    </select>
                </label>
                <button type="button" class="icon-btn goods-filter" id="posNhomChon" aria-label="Chọn nhóm hàng hiện ở quầy này" title="Chọn nhóm hàng hiện ở quầy này"><i class="fa-solid fa-filter"></i></button>
            </div>
        </div>
        <div class="cats" role="toolbar" aria-label="Nhóm hàng" id="posCats">
            <button type="button" class="cat" aria-pressed="true" data-id="">Tất cả</button>
            {{-- Hai nhóm ảo: xếp theo lượt bán và theo ngày khai hàng, không lọc nhóm nào. --}}
            <button type="button" class="cat" aria-pressed="false" data-id="best_seller">Bán chạy</button>
            <button type="button" class="cat" aria-pressed="false" data-id="new">Hàng mới</button>
            @foreach($nhomHang as $n)
                <button type="button" class="cat" aria-pressed="false" data-id="{{ $n['id'] }}">{{ $n['name'] }}</button>
            @endforeach
        </div>
        <div class="goods-body" id="list" aria-live="polite"></div>
        <div class="pager" id="div_pagination"></div>
    </section>

    <section class="pane receipt" id="paneBill" aria-label="Hoá đơn">
        <div class="bill-tabs">
            <div class="tabs-scroll" id="posTabsWrap">
                <button type="button" class="tabs-arrow tabs-arrow--prev" id="posTabsTrai" aria-label="Xem các hoá đơn bên trái" hidden><i class="fa-solid fa-chevron-left"></i></button>
                <div class="bill-tabs__list" role="tablist" aria-label="Hoá đơn đang mở" id="invoiceTab"></div>
                <button type="button" class="tabs-arrow tabs-arrow--next" id="posTabsPhai" aria-label="Xem các hoá đơn bên phải" hidden><i class="fa-solid fa-chevron-right"></i></button>
            </div>
            <button type="button" class="icon-btn bill-tabs__add" id="newOrder" aria-label="Thêm hoá đơn (F2)" title="Thêm hoá đơn (F2)"><i class="fa-solid fa-plus"></i></button>
            <button type="button" class="bill-tabs__all" id="invoiceTabToggle" aria-label="Tất cả hoá đơn đang mở" title="Tất cả hoá đơn đang mở"><i class="fa-solid fa-layer-group"></i><span class="num" id="invoiceTabCount">1</span></button>
            <button type="button" class="icon-btn bill-tabs__cancel" id="posClear" aria-label="Huỷ hoá đơn đang mở" title="Huỷ hoá đơn đang mở"><i class="fa-regular fa-trash-can"></i></button>
        </div>

        <div class="paper">
            <div class="paper__head">
                <div class="paper__meta">
                    <span class="meta-chip meta-chip--branch"><i class="fa-solid fa-store"></i><span class="sr-only">Chi nhánh: </span>{{ $tenChiNhanh }}</span>
                    <span class="meta-chip meta-chip--cashier"><i class="fa-solid fa-user-tie"></i>Thu ngân: <b>{{ $nguoiBan }}</b></span>
                    <span class="meta-time num" id="posGio"></span>
                </div>
                <div class="customer">
                    <button type="button" class="customer__pick" id="posCusBtn" aria-haspopup="dialog">
                        <i class="fa-solid fa-user"></i>
                        <span id="posCusNhan"></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <button type="button" class="btn btn--add-cus" id="posCusMoi" aria-label="Thêm khách mới" title="Thêm khách mới"><i class="fa-solid fa-user-plus"></i></button>
                </div>
            </div>

            <div class="lines" id="posLines"></div>

            <div class="tear" aria-hidden="true"><i class="fa-solid fa-scissors"></i></div>

            <div class="bill-foot">
                <div class="options">
                    <button type="button" class="btn btn--option" id="posNoteBtn" aria-pressed="false"><i class="fa-solid fa-pen-to-square"></i> Ghi chú</button>
                    <button type="button" class="btn btn--option" id="posHddt" aria-pressed="false"><i class="fa-solid fa-file-invoice"></i> HĐ điện tử</button>
                    <button type="button" class="btn btn--option opt-more" id="posThem" aria-haspopup="dialog" aria-label="Thêm thao tác" title="Thêm thao tác"><i class="fa-solid fa-ellipsis"></i><span class="txt">Thêm</span><span class="opt-dot" id="posThemMark" hidden></span></button>
                </div>

                <dl class="totals" id="posTotals"></dl>

                <div class="actions">
                    <button type="button" class="btn btn--outline btn--print" id="posTamTinh" aria-label="In tạm tính" title="In tạm tính" disabled><i class="fa-solid fa-print"></i></button>
                    <button type="button" class="btn btn--primary btn--pay" id="posSubmit" disabled><span>Thanh toán</span> <kbd>F9</kbd></button>
                </div>
            </div>
        </div>
    </section>

    {{-- Chưa mở ca: khoá cả khu bán (thanh trên vẫn bấm được để đổi chi nhánh), mở ca ngay tại đây. --}}
    <div class="ca-khoa" id="posCaKhoa" @if($caMo !== false) hidden @endif>
        <form class="ca-khoa__the" id="posCaForm" novalidate aria-labelledby="posCaTieuDe">
            <div class="ca-khoa__icon"><i class="fa-solid fa-lock"></i></div>
            <h2 id="posCaTieuDe">Chưa mở ca</h2>
            <p id="posCaCau">{{ $tenChiNhanh }} chưa có ca nào đang mở. Đếm tiền trong két rồi mở ca để bắt đầu bán.</p>
            @include('v2::pos._mo-ca-nguoi-gio', ['p' => 'posCa', 'truc' => $truc ?? ['ds' => [], 'macDinh' => null]])
            <div class="field">
                <label for="posCaTien">Tiền đang có trong két *</label>
                <input class="input num" id="posCaTien" inputmode="numeric" autocomplete="off" placeholder="0">
                <span class="hint">Két trống thì nhập 0.</span>
            </div>
            <div class="field">
                <label for="posCaGhi">Ghi chú</label>
                <input class="input" id="posCaGhi" maxlength="500" autocomplete="off" placeholder="Ví dụ: nhận két từ ca tối">
            </div>
            <div class="alert" role="alert" id="posCaLoi" hidden></div>
            <div class="ca-khoa__nut">
                <a class="btn btn--outline" href="{{ route('thu-ngan.ca-lam-viec.index') }}">Điều phối ca</a>
                <button type="submit" class="btn btn--primary" id="posCaMo"><i class="fa-solid fa-lock-open"></i> Mở ca</button>
            </div>
        </form>
    </div>
@endsection

@section('bar')
    <div class="pos-bar">
        <div class="pos-bar__sum">
            <small id="posBarDem">0 món</small>
            <strong class="num" id="posBarTong">0 đ</strong>
        </div>
        <button type="button" class="btn btn--outline" data-tam-tinh aria-label="In tạm tính"><i class="fa-solid fa-print"></i></button>
        <button type="button" class="btn btn--primary" data-thanh-toan>Thanh toán</button>
    </div>
@endsection

@push('modals')
    {{-- Chọn phiên bản khi hàng có nhiều biến thể còn hàng. --}}
    <dialog class="modal" id="posVarBox" aria-labelledby="posVarTen">
        <div class="modal__head"><h2 id="posVarTen">Chọn phiên bản</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body"><ul class="pick-list" id="posVarDS"></ul></div>
    </dialog>

    {{-- Thanh toán: hình thức · tiền khách đưa (gợi ý, mệnh giá, bàn phím số) · tiền thối. --}}
    <dialog class="modal modal--pay pay3" id="posPayBox" aria-labelledby="posPayTieuDe">
        <div class="modal__head">
            <div><h2 id="posPayTieuDe">Thanh toán</h2><small class="pay-sub" id="posPayHd"></small></div>
            <button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal__body pay-grid">
            <section class="pay-left" aria-labelledby="posPayTomTat">
                <details class="pay-items-wrap" id="posPayMonBoc" open>
                    <summary><h3 class="ei-sec__title" id="posPayTomTat">Phiếu tóm tắt</h3><span class="hint" id="posPayDem"></span></summary>
                    <ul class="pay-items" id="posPayMon"></ul>
                </details>
                <dl class="pay-lines" id="posPayCac"></dl>
                <div class="pay-due"><span>Khách cần trả</span><b class="num" id="posPayTong"></b></div>
                <div class="field">
                    <label for="posPayGhiChu">Ghi chú hoá đơn</label>
                    <textarea class="input" id="posPayGhiChu" rows="2" maxlength="500" placeholder="In trên phiếu, VD: giao trước 17h"></textarea>
                </div>
            </section>

            <section class="pay-right" aria-label="Thu tiền">
                {{-- Chỉ những hình thức chi nhánh đang bật (Quản trị → Cài đặt → Phương thức thanh toán). --}}
                <div class="pay-methods pay-methods--{{ max(2, count($phuongThuc ?? [])) }}" role="radiogroup" aria-label="Hình thức thanh toán" id="posPayTabs">
                    @foreach(($phuongThuc ?? []) as $pt)
                        <button type="button" class="pay-method" role="radio" aria-checked="{{ $loop->first ? 'true' : 'false' }}" data-method="{{ $pt['ma'] }}">
                            <i class="fa-solid {{ $pt['icon'] }}"></i> {{ $pt['ten'] }}
                        </button>
                    @endforeach
                </div>
                <p class="pay-doi" id="posDoiQr" role="status" hidden></p>

                <div class="pay-block" id="posPayTienMat">
                    <div class="field">
                        <label for="posTendered">Tiền khách đưa <span class="hint">— bỏ trống nếu khách đưa vừa đủ</span></label>
                        <input class="input cash-input num" id="posTendered" inputmode="numeric" autocomplete="off" placeholder="0">
                    </div>
                    <div class="cash-grid">
                        <div class="cash-main">
                            <div class="notes" id="posGoiYTien"></div>
                            <div class="menh-gia" id="posMenhGia" aria-label="Cộng thêm mệnh giá">
                                @foreach($B::MENH_GIA as $m)
                                    <button type="button" data-add="{{ $m }}">+{{ number_format($m / 1000, 0, ',', '.') }}k</button>
                                @endforeach
                            </div>
                            <div class="change" id="posChange" hidden><span id="posChangeLabel">Tiền thối</span><span class="num" id="posChangeVal">0 đ</span></div>
                        </div>
                        <div class="numpad" data-numpad="posTendered" role="group" aria-label="Bàn phím số nhập tiền khách đưa"></div>
                    </div>
                </div>

                <div class="field pay-chon" id="posCkTkBoc" hidden>
                    <label for="posCkTk">Tài khoản nhận</label>
                    <select class="input" id="posCkTk"></select>
                </div>
                <div class="pay-block qr-card" id="posPayCk" hidden>
                    <img class="qr-card__img" id="posQr" alt="Mã QR chuyển khoản" hidden>
                    <div class="qr-card__trong" id="posQrTrong" hidden><i class="fa-solid fa-qrcode"></i></div>
                    <dl class="qr-card__info" id="posQrInfo">
                        <dt>Ngân hàng</dt><dd id="posQrNh"></dd>
                        <dt>Số tài khoản</dt><dd><b class="num" id="posQrSo"></b> <button type="button" class="link-btn" data-chep="so">Chép</button></dd>
                        <dt>Chủ tài khoản</dt><dd id="posQrChu"></dd>
                        <dt>Số tiền</dt><dd><b class="num" id="posQrTien"></b></dd>
                        <dt>Nội dung</dt><dd><b id="posQrND"></b> <button type="button" class="link-btn" data-chep="nd">Chép</button></dd>
                    </dl>
                    <p class="hint qr-card__hint" id="posQrGhi"><i class="fa-solid fa-circle-info"></i> Mở app ngân hàng của khách, xem tiền đã vào tài khoản cửa hàng rồi mới bấm Xác nhận.</p>
                </div>

                {{-- Quẹt thẻ: máy độc lập — thu ngân xác nhận tay sau khi máy in biên lai; máy mPOS — đẩy
                     số tiền xuống máy, NextPay báo quẹt xong là đơn tự hoàn tất. --}}
                <div class="pay-block the-card" id="posPayThe" hidden>
                    <div class="field pay-chon" id="posTheMayBoc">
                        <label for="posTheMay">Máy quẹt thẻ</label>
                        <select class="input" id="posTheMay"></select>
                    </div>
                    <dl class="the-card__info">
                        <dt>Số tiền quẹt</dt><dd><b class="num" id="posTheTien"></b></dd>
                        <dt>Phí thẻ <small>(cửa hàng chịu)</small></dt><dd id="posThePhi"></dd>
                    </dl>
                    <p class="hint" id="posTheGhi"><i class="fa-solid fa-circle-info"></i> Nhập đúng số tiền trên máy, cho khách quẹt / chạm thẻ. Máy in biên lai <b>thành công</b> rồi mới bấm Xác nhận.</p>
                    <div class="the-cho" id="posTheCho" hidden>
                        <p class="qrtd__trang" id="posTheChoCau" role="status" aria-live="polite"></p>
                        <button type="button" class="btn btn--ghost btn--sm" id="posTheHuy"><i class="fa-solid fa-xmark"></i> Huỷ lệnh</button>
                    </div>
                </div>

                {{-- QR tự động: tạo mã có sẵn số tiền, cổng báo tiền về là đơn tự hoàn tất. --}}
                <div class="pay-block" id="posPayQrTd" hidden>
                    <div class="pay-cong" id="posQrCong" role="radiogroup" aria-label="Cổng QR"></div>
                    <div class="qr-card qrtd" id="posQrTdThe">
                        <img class="qr-card__img" id="posQrTdAnh" alt="Mã QR thanh toán" hidden>
                        <div class="qr-card__trong" id="posQrTdTrong"><i class="fa-solid fa-qrcode"></i></div>
                        <div class="qrtd__ben">
                            <p class="qrtd__trang" id="posQrTdTrang" role="status" aria-live="polite">Bấm <b>Tạo mã QR</b> — mã có sẵn số tiền, khách quét bằng app ngân hàng là xong, không phải soi sao kê.</p>
                            <dl class="qr-card__info" id="posQrTdInfo" hidden>
                                <dt>Số tiền</dt><dd><b class="num" id="posQrTdTien"></b></dd>
                                <dt>Mã đơn</dt><dd><b id="posQrTdMa"></b></dd>
                            </dl>
                            <button type="button" class="btn btn--ghost btn--sm" id="posQrTdHuy" hidden><i class="fa-solid fa-xmark"></i> Huỷ mã QR</button>
                        </div>
                    </div>
                </div>

                <div class="alert" role="alert" id="posErr" hidden></div>
            </section>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--ghost" data-dong>Quay lại</button>
            <span class="spacer"></span>
            <button type="button" class="btn btn--outline" id="posXacNhan"><span class="spin"></span> Xác nhận</button>
            <button type="button" class="btn btn--primary" id="posXacNhanIn"><span class="spin"></span> <i class="fa-solid fa-print"></i> Xác nhận &amp; in phiếu</button>
        </div>
    </dialog>

    {{-- Đã thanh toán: tổng thu, tiền thối, trạng thái in, hoá đơn điện tử. --}}
    <dialog class="modal" id="posDoneBox" aria-labelledby="posDoneTieuDe">
        <div class="done">
            <div class="done__icon"><i class="fa-solid fa-check"></i></div>
            <h2 id="posDoneTieuDe">Đã thanh toán</h2>
            <dl class="pay-sum">
                <dt>Mã đơn</dt><dd><b id="posDoneMa"></b></dd>
                <dt>Đã thu</dt><dd class="num" id="posDoneTong"></dd>
                <div id="posDoneThoiDong"><dt>Tiền thối</dt><dd class="big num" id="posDoneThoi"></dd></div>
            </dl>
            <div class="print-status" id="posInTrangThai" aria-live="polite" hidden></div>
            <div class="done-hddt" id="posDoneHddt" hidden>
                <p id="posDoneHddtCau" role="status"></p>
                <button type="button" class="btn btn--outline" id="posDoneHddtLai"></button>
            </div>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--outline" id="posDoneIn"><i class="fa-solid fa-print"></i> In lại phiếu <kbd>F8</kbd></button>
            <span class="spacer"></span>
            <button type="button" class="btn btn--primary" data-dong autofocus>Bán đơn mới <kbd>Enter</kbd></button>
        </div>
    </dialog>

    {{-- Khách quen: tìm theo tên hoặc số điện thoại. --}}
    <dialog class="modal" id="posCusBox" aria-labelledby="posCusTieuDe">
        <div class="modal__head"><h2 id="posCusTieuDe">Chọn khách hàng</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <div class="search">
                <label class="sr-only" for="posCusName">Tìm khách theo tên hoặc số điện thoại</label>
                <i class="fa-solid fa-magnifying-glass search__icon"></i>
                <input id="posCusName" type="search" autocomplete="off" placeholder="Tên hoặc số điện thoại">
            </div>
            <div class="cus-bar"><span class="hint" id="posCusDem"></span><button type="button" class="btn btn--ghost ei-fill" data-khach-moi><i class="fa-solid fa-user-plus"></i> Thêm khách mới</button></div>
            <ul class="pick-list" id="posCusDS" aria-live="polite"></ul>
        </div>
    </dialog>

    {{-- Khách mới: lưu hồ sơ khách, hoặc bỏ tích để chỉ ghi tên + số lên hoá đơn này. --}}
    <dialog class="modal modal--wide" id="posKhachBox" aria-labelledby="posKhachTieuDe">
        <form id="posKhachForm" novalidate>
            <div class="modal__head"><h2 id="posKhachTieuDe">Thêm nhanh khách hàng</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body">
                <div class="seg ei-type" role="radiogroup" aria-label="Loại khách" id="posKhachLoai">
                    <button type="button" role="radio" aria-checked="true" data-loai="0"><i class="fa-solid fa-user"></i> Cá nhân</button>
                    <button type="button" role="radio" aria-checked="false" data-loai="1"><i class="fa-solid fa-building"></i> Doanh nghiệp</button>
                </div>
                <div class="field-2">
                    <div class="field"><label for="posKhachMa">Mã khách</label><input class="input" id="posKhachMa" readonly value="Tự sinh khi lưu"></div>
                    <div class="field"><label for="posKhachTen"><span id="posKhachTenNhan">Tên khách</span> <span class="req">*</span></label><input class="input" id="posKhachTen" autocomplete="off" maxlength="150" aria-describedby="posKhachTenLoi"><span class="err" id="posKhachTenLoi"></span></div>
                    <div class="field" data-khach-dn hidden><label for="posKhachMst">Mã số thuế</label><input class="input" id="posKhachMst" inputmode="numeric" autocomplete="off" maxlength="20" placeholder="10 số, hoặc 10 số-3 số" aria-describedby="posKhachMstLoi"><span class="err" id="posKhachMstLoi"></span></div>
                    <div class="field"><label for="posKhachSdt">Số điện thoại</label><input class="input" id="posKhachSdt" type="tel" autocomplete="off" inputmode="tel" maxlength="20" aria-describedby="posKhachSdtLoi"><span class="err" id="posKhachSdtLoi"></span></div>
                    <div class="field"><label for="posKhachEmail">Email</label><input class="input" id="posKhachEmail" type="email" inputmode="email" autocomplete="off" maxlength="191" aria-describedby="posKhachEmailLoi"><span class="err" id="posKhachEmailLoi"></span></div>
                </div>
                <div class="field"><label for="posKhachDiaChi">Địa chỉ</label><input class="input" id="posKhachDiaChi" autocomplete="off" maxlength="255"></div>
                <label class="ei-toggle">
                    <span class="switch"><input type="checkbox" id="posKhachLuu" checked></span>
                    <span><b>Lưu vào danh sách khách hàng</b><small>Số điện thoại đã có hồ sơ thì quầy chọn luôn khách đó. Bỏ tích thì chỉ ghi tên, số lên hoá đơn này.</small></span>
                </label>
                <div class="alert" role="alert" id="posKhachLoi" hidden></div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" id="posKhachXoa">Bán cho người tiêu dùng</button>
                <span class="spacer"></span>
                <button type="button" class="btn btn--ghost" data-dong>Huỷ bỏ</button>
                <button type="submit" class="btn btn--primary" id="posKhachLuuNut"><span class="spin"></span> Lưu khách</button>
            </div>
        </form>
    </dialog>

    {{-- Giảm giá: giảm tay cả đơn (trong hạn quyền) · mã giảm giá · thẻ thành viên. --}}
    <dialog class="modal" id="posGiamBox" aria-labelledby="posGiamTieuDe">
        <form id="posGiamForm" novalidate>
            <div class="modal__head"><h2 id="posGiamTieuDe">Giảm giá</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body">
                @if($coGiam)
                    <div class="field">
                        <span class="label" id="posGiamNhan">Giảm trên cả đơn</span>
                        <div class="row">
                            <input class="input num" id="posGiamDon" inputmode="decimal" autocomplete="off" placeholder="0" aria-labelledby="posGiamNhan" aria-describedby="posGiamHan">
                            <div class="seg" role="group" aria-label="Đơn vị giảm" id="posGiamKieu">
                                <button type="button" data-kieu="amount" aria-pressed="false">VNĐ</button>
                                <button type="button" data-kieu="percent" aria-pressed="true">%</button>
                            </div>
                        </div>
                        <span class="hint" id="posGiamHan"></span>
                    </div>
                @endif
                <div class="field">
                    <label for="posVoucher">Mã giảm giá khách đưa</label>
                    <input class="input" id="posVoucher" autocomplete="off" autocapitalize="characters" maxlength="50" placeholder="VD: GIAM10" style="text-transform:uppercase">
                    <span class="hint">Mã được kiểm và trừ ngay khi bấm Áp dụng.</span>
                </div>
                <div class="tv" id="posTvKhoi" hidden>
                    <div class="tv__row"><span>Giảm giá thành viên <b id="posTvHang"></b></span><b class="num" id="posTvGiam">0 đ</b></div>
                    <div id="posTvDiemKhoi">
                        <div class="tv__row">
                            <label for="posTvDiem">Đổi điểm</label>
                            <label class="switch"><input type="checkbox" id="posTvDungHet"> Dùng tất cả</label>
                            <input class="input num tv__diem" id="posTvDiem" inputmode="numeric" autocomplete="off" value="0">
                            <b class="num" id="posTvDiemTien">0 đ</b>
                        </div>
                        <span class="hint" id="posTvConLai"></span>
                    </div>
                </div>
                {{-- Mã sai thì câu của API in vào ĐÂY, như hộp Khách mới. Báo nổi không
                     dùng được cho hộp đang mở: <dialog> mở bằng showModal() nằm ở top
                     layer, nên toast (z-index 50) bị chính tờ hộp che mất. --}}
                <div class="alert" role="alert" id="posGiamLoi" hidden></div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-dong>Huỷ bỏ</button>
                <button type="submit" class="btn btn--primary" id="posGiamNut">Áp dụng giảm giá</button>
            </div>
        </form>
    </dialog>

    {{-- Phụ thu: lý do + giá trị (VNĐ / %). % quy ra số tiền trên tiền hàng lúc chốt. --}}
    <dialog class="modal" id="posPhuThuBox" aria-labelledby="posPhuThuTieuDe">
        <form id="posPhuThuForm" novalidate>
            <div class="modal__head"><h2 id="posPhuThuTieuDe">Phụ thu</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body">
                <div class="field">
                    <label for="posPhuThuLyDo">Lý do</label>
                    <input class="input" id="posPhuThuLyDo" list="posPhuThuGoiY" maxlength="255" autocomplete="off" placeholder="VD: Phí gói quà">
                    <datalist id="posPhuThuGoiY">
                        <option value="Phí gói quà"></option>
                        <option value="Phí giao tận nơi"></option>
                        <option value="Phụ thu ngoài giờ"></option>
                        <option value="Phụ thu ngày lễ"></option>
                    </datalist>
                </div>
                <div class="field">
                    <span class="label" id="posPhuThuNhan">Giá trị</span>
                    <div class="row">
                        <input class="input num" id="posPhuThuGt" inputmode="decimal" autocomplete="off" placeholder="0" aria-labelledby="posPhuThuNhan" aria-describedby="posPhuThuQuyDoi">
                        <div class="seg" role="group" aria-label="Đơn vị phụ thu" id="posPhuThuKieu">
                            <button type="button" data-kieu="amount" aria-pressed="true">VNĐ</button>
                            <button type="button" data-kieu="percent" aria-pressed="false">%</button>
                        </div>
                    </div>
                    <span class="hint" id="posPhuThuQuyDoi"></span>
                </div>
                {{-- Cùng khuôn với #posGiamLoi: báo nổi bắn lúc hộp còn mở thì bị chính
                     tờ hộp che, vì <dialog> showModal() nằm ở top layer. --}}
                <div class="alert" role="alert" id="posPhuThuLoi" hidden></div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" id="posPhuThuBo">Bỏ phụ thu</button>
                <span class="spacer"></span>
                <button type="submit" class="btn btn--primary">Lưu phụ thu</button>
            </div>
        </form>
    </dialog>

    {{-- Hoá đơn điện tử của hoá đơn đang mở: bật thì API xuất ngay sau khi chốt. --}}
    <dialog class="modal modal--wide" id="posHddtBox" aria-labelledby="posHddtTieuDe">
        <form id="posHddtForm" novalidate>
            <div class="modal__head"><h2 id="posHddtTieuDe">Hoá đơn điện tử</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body">
                <label class="ei-toggle">
                    <span class="switch"><input type="checkbox" id="posHddtBat"></span>
                    <span><b>Xuất hoá đơn điện tử khi thanh toán</b><small>Phát hành ngay sau khi chốt đơn, gửi về email người mua nếu có.</small></span>
                </label>

                <section class="ei-sec" aria-labelledby="posHddtThongTin">
                    <h3 class="ei-sec__title" id="posHddtThongTin">Thông tin hoá đơn</h3>
                    <div class="field-2">
                        <div class="field"><label for="posHddtNgay">Ngày phát hành</label><input class="input" id="posHddtNgay" readonly></div>
                        <div class="field"><label for="posHddtHd">Hoá đơn</label><input class="input" id="posHddtHd" readonly></div>
                    </div>
                </section>

                <section class="ei-sec" aria-labelledby="posHddtNguoiMua">
                    <div class="ei-sec__row">
                        <h3 class="ei-sec__title" id="posHddtNguoiMua">Thông tin người mua</h3>
                        <button type="button" class="btn btn--ghost ei-fill" id="posHddtLayKhach"><i class="fa-solid fa-user-check"></i> Lấy từ khách đang chọn</button>
                    </div>
                    <div class="seg ei-type" role="radiogroup" aria-label="Loại người mua" id="posHddtLoai">
                        <button type="button" role="radio" aria-checked="true" data-loai="personal"><i class="fa-solid fa-user"></i> Cá nhân</button>
                        <button type="button" role="radio" aria-checked="false" data-loai="company"><i class="fa-solid fa-building"></i> Doanh nghiệp</button>
                    </div>
                    <div class="field-2">
                        <div class="field" data-hddt-dn hidden><label for="posHddtMst">Mã số thuế <span class="req">*</span></label><input class="input" id="posHddtMst" maxlength="20" inputmode="numeric" autocomplete="off" placeholder="10 số, hoặc 10 số-3 số" aria-describedby="posHddtMstLoi"><span class="err" id="posHddtMstLoi"></span></div>
                        <div class="field" data-hddt-dn hidden><label for="posHddtCty">Tên đơn vị <span class="req">*</span></label><input class="input" id="posHddtCty" maxlength="255" autocomplete="organization" aria-describedby="posHddtCtyLoi"><span class="err" id="posHddtCtyLoi"></span></div>
                        <div class="field"><label for="posHddtTen">Tên người mua <span class="req" data-hddt-cn>*</span></label><input class="input" id="posHddtTen" maxlength="100" autocomplete="name" aria-describedby="posHddtTenLoi"><span class="err" id="posHddtTenLoi"></span></div>
                        <div class="field"><label for="posHddtSdt">Số điện thoại</label><input class="input" id="posHddtSdt" type="tel" maxlength="20" inputmode="tel" autocomplete="tel" aria-describedby="posHddtSdtLoi"><span class="err" id="posHddtSdtLoi"></span></div>
                    </div>
                    <div class="field"><label for="posHddtDiaChi">Địa chỉ <span class="req">*</span></label><input class="input" id="posHddtDiaChi" maxlength="255" autocomplete="street-address" aria-describedby="posHddtDiaChiLoi"><span class="err" id="posHddtDiaChiLoi"></span></div>
                    <div class="field"><label for="posHddtEmail">Email nhận hoá đơn</label><input class="input" id="posHddtEmail" type="email" maxlength="191" inputmode="email" autocomplete="email" placeholder="VD: ketoan@congty.vn" aria-describedby="posHddtEmailLoi"><span class="err" id="posHddtEmailLoi"></span></div>
                </section>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-dong>Huỷ bỏ</button>
                <button type="submit" class="btn btn--primary">Lưu thông tin hoá đơn</button>
            </div>
        </form>
    </dialog>

    {{-- Ghi chú in trên phiếu. --}}
    <dialog class="modal" id="posNoteBox" aria-labelledby="posNoteTieuDe">
        <div class="modal__head"><h2 id="posNoteTieuDe">Ghi chú hoá đơn</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <div class="field">
                <label for="posNote">Ghi chú in trên phiếu</label>
                <textarea class="input" id="posNote" rows="3" maxlength="500"></textarea>
            </div>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--primary" data-dong>Xong</button></div>
    </dialog>

    {{-- Bớt giá một món (trong hạn quyền API trả). --}}
    <dialog class="modal" id="posBotBox" aria-labelledby="posBotTieuDe">
        <form id="posBotForm" novalidate>
            <div class="modal__head"><h2 id="posBotTieuDe">Bớt giá món</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body">
                <p style="margin:0" id="posBotTen"></p>
                <div class="field">
                    <label for="posBotPt">Bớt (%)</label>
                    <input class="input num" id="posBotPt" inputmode="decimal" autocomplete="off" aria-describedby="posBotHan posBotLoi">
                    <span class="hint" id="posBotHan"></span>
                    <span class="err" id="posBotLoi"></span>
                </div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-dong>Huỷ bỏ</button>
                <button type="submit" class="btn btn--primary">Áp dụng bớt giá</button>
            </div>
        </form>
    </dialog>

    {{-- Số lượng bằng bàn phím số — màn cảm ứng chạm vào ô số lượng thì mở hộp này. --}}
    <dialog class="modal" id="posSlBox" aria-labelledby="posSlTieuDe">
        <form id="posSlForm" novalidate>
            <div class="modal__head"><h2 id="posSlTieuDe">Số lượng</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body">
                <p style="margin:0" id="posSlTen"></p>
                <div class="field">
                    <label for="posSlSo">Số lượng</label>
                    <input class="input cash-input num" id="posSlSo" inputmode="numeric" autocomplete="off" aria-describedby="posSlTon posSlLoi">
                    <span class="hint" id="posSlTon"></span>
                    <span class="err" id="posSlLoi"></span>
                </div>
                <div class="numpad numpad--qty" data-numpad="posSlSo" role="group" aria-label="Bàn phím số nhập số lượng"></div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-dong>Huỷ bỏ</button>
                <button type="submit" class="btn btn--primary">Đặt số lượng</button>
            </div>
        </form>
    </dialog>

    {{-- Thêm thao tác. --}}
    <dialog class="modal" id="posThemBox" aria-labelledby="posThemTieuDe">
        <div class="modal__head"><h2 id="posThemTieuDe">Thêm thao tác</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <ul class="pick-list">
                <li><button type="button" class="pick" id="posKhuyenMai"><i class="fa-solid fa-gift"></i><span class="pick__main"><span class="pick__title">Khuyến mãi</span><span class="pick__sub" id="posKhuyenMaiMark" hidden>Đang áp dụng</span></span></button></li>
                <li><button type="button" class="pick" id="posDongGia"><i class="fa-solid fa-tags"></i><span class="pick__main"><span class="pick__title">Đồng giá</span><span class="pick__sub" id="posDongGiaMark" hidden>Đang áp dụng</span></span></button></li>
                <li><button type="button" class="pick" data-them="giam"><i class="fa-solid fa-percent"></i><span class="pick__main"><span class="pick__title">Giảm giá, mã giảm giá, điểm</span></span></button></li>
                <li><button type="button" class="pick" data-them="phuThu"><i class="fa-solid fa-circle-plus"></i><span class="pick__main"><span class="pick__title">Phụ thu</span></span></button></li>
                <li><button type="button" class="pick" data-them="inLai"><i class="fa-solid fa-print"></i><span class="pick__main"><span class="pick__title">In lại phiếu vừa bán</span><span class="pick__sub">Chọn khổ 80 / 58 / A5 / A4 và số bản · F8</span></span></button></li>
                <li><button type="button" class="pick" id="posXoaHet"><i class="fa-solid fa-eraser"></i><span class="pick__main"><span class="pick__title">Xoá hết hàng trong hoá đơn</span><span class="pick__sub">Hoàn tác được trong 6 giây</span></span></button></li>
            </ul>
        </div>
    </dialog>

    {{-- Tất cả hoá đơn đang mở. --}}
    <dialog class="modal" id="posHdBox" aria-labelledby="posHdTieuDe">
        <div class="modal__head"><h2 id="posHdTieuDe">Hoá đơn đang mở</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body"><ul class="pick-list" id="posHdDS"></ul></div>
        <div class="modal__foot">
            <span class="hint">Tối đa 12 hoá đơn cùng lúc.</span>
            <span class="spacer"></span>
            <button type="button" class="btn btn--primary" id="posHdMoi"><i class="fa-solid fa-plus"></i> Thêm hoá đơn <kbd>F2</kbd></button>
        </div>
    </dialog>

    {{-- Đồng giá / Khuyến mãi: chỉ bày chương trình giỏ ĐỦ điều kiện, tick một hoặc nhiều. --}}
    <dialog class="modal modal--wide" id="posDongGiaBox" aria-labelledby="posDongGiaTieuDe">
        <div class="modal__head"><h2 id="posDongGiaTieuDe">Khuyến mại đồng giá</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <p class="hint" style="margin:0">Giỏ đủ điều kiện các chương trình dưới đây — chọn một hoặc nhiều.</p>
            <ul class="pick-list pick-grid" id="posDongGiaDS"></ul>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--ghost" data-dong>Huỷ bỏ</button>
            <button type="button" class="btn btn--primary" id="posDongGiaApDung">Áp dụng đồng giá</button>
        </div>
    </dialog>

    <dialog class="modal modal--wide" id="posKhuyenMaiBox" aria-labelledby="posKhuyenMaiTieuDe">
        <div class="modal__head"><h2 id="posKhuyenMaiTieuDe">Khuyến mãi</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <p class="hint" style="margin:0">Đơn đủ điều kiện các chương trình dưới đây — chọn một hoặc nhiều.</p>
            <ul class="pick-list pick-grid" id="posKhuyenMaiDS"></ul>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--ghost" data-dong>Huỷ bỏ</button>
            <button type="button" class="btn btn--primary" id="posKhuyenMaiApDung">Áp dụng khuyến mãi</button>
        </div>
    </dialog>

    {{-- Nhóm nào hiện trên hàng nhóm. Nhớ theo MÁY: mỗi quầy bán một nhóm khác nhau. --}}
    <dialog class="modal" id="posNhomBox" aria-labelledby="posNhomTieuDe">
        <div class="modal__head"><h2 id="posNhomTieuDe">Nhóm hàng hiện ở quầy này</h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <div class="row" style="align-items:center;justify-content:space-between">
                <span class="hint">Bỏ tích nhóm không bán ở quầy này.</span>
                <button type="button" class="btn btn--ghost" id="posNhomTatCa">Tích hết</button>
            </div>
            <div class="nhom-ds" id="posNhomDS"></div>
        </div>
        <div class="modal__foot"><button type="button" class="btn btn--primary" data-dong>Xong</button></div>
    </dialog>

    {{-- Hỏi lại trước thao tác không hoàn tác được. --}}
    <dialog class="modal" id="posHoiBox" aria-labelledby="posHoiTieuDe">
        <div class="modal__head"><h2 id="posHoiTieuDe"></h2><button type="button" class="icon-btn" aria-label="Đóng" data-dong><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body" id="posHoiBody"></div>
        <div class="modal__foot">
            <button type="button" class="btn btn--outline" data-dong autofocus>Giữ lại</button>
            <span class="spacer"></span>
            <button type="button" class="btn btn--danger" id="posHoiOk">Đồng ý</button>
        </div>
    </dialog>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('v2/css/pos-v3/sale.css') }}?v={{ filemtime(public_path('v2/css/pos-v3/sale.css')) }}">
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
    const KHOA_HD = 'pos.hoa-don', KHOA_ANH = 'pos.anh-tat', KHOA_COT = 'pos.so-cot', KHOA_NHOM_AN = 'pos.nhom-an', KHOA_MA_TAM = 'pos.ma-tam';
    // Hoá đơn có hàng mà để quên quá 12 giờ không còn là "lượt đang dở" — mở trang
    // ra thấy nó đứng sẵn là mời bán nhầm cho khách mới.
    const HAN_GIU_MS = 12 * 60 * 60 * 1000;
    const TOI_DA_HD = 12, CO_TRANG = 24, SAP_HET = 3;

    const $ = (id) => document.getElementById(id);
    const so = (n) => new Intl.NumberFormat('vi-VN').format(Math.round(n || 0));
    const tien = (n) => so(n) + ' đ';
    const dinhSo = (n) => (n ? so(n) : '');
    const soTien = (s) => Number(String(s ?? '').replace(/\D/g, '')) || 0;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const doc = (k, macDinh) => { try { return localStorage.getItem(k) ?? macDinh; } catch (e) { return macDinh; } };
    const ghi = (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* máy chặn lưu: vẫn bán được */ } };
    const camUng = () => matchMedia('(pointer: coarse)').matches;
    const hepMan = () => matchMedia('(max-width: 899px)').matches;
    // Chi nhánh chưa mở ca: API từ chối mọi lượt thu tiền ở quầy (409 CHUA_MO_CA).
    let chuaMoCa = false;

    // Hộp thoại là <dialog>. Mở hộp này thì đóng hộp đang mở — không chồng hai hộp lên nhau.
    // 'hop:mo' / 'hop:dong' bắn ra để các khối bên dưới đặt focus, dọn trạng thái.
    const hop = (id) => {
        const d = $(id);
        return {
            show() {
                if (chuaMoCa) return;
                document.querySelectorAll('dialog[open]').forEach((x) => { if (x !== d) x.close(); });
                if (!d.open) d.showModal();
                requestAnimationFrame(() => d.dispatchEvent(new Event('hop:mo')));
            },
            hide() { if (d.open) d.close(); },
        };
    };
    document.querySelectorAll('dialog.modal').forEach((d) => {
        d.addEventListener('close', () => d.dispatchEvent(new Event('hop:dong')));
        d.addEventListener('click', (e) => { if (e.target.closest('[data-dong]')) { e.preventDefault(); d.close(); } });
    });
    const coHopMo = () => !!document.querySelector('dialog[open]');
    const nhac = (m) => window.posToast(m, 'warn');
    const baoLoi = (m) => { $('posErr').textContent = m || ''; $('posErr').hidden = !m; };

    // Khoá khu bán tới khi mở ca. Hoá đơn đang dở vẫn giữ nguyên — mở ca xong bán tiếp.
    function khoaCa(cau) {
        chuaMoCa = true;
        document.querySelectorAll('dialog[open]').forEach((x) => x.close());
        if (cau) $('posCaCau').textContent = cau;
        $('posCaTien').value = ''; $('posCaGhi').value = '';
        $('posCaLoi').hidden = true;
        window.gioMoCa.dat($('posCaKhoa'));
        $('posCaKhoa').hidden = false;
        $('paneGoods').inert = $('paneBill').inert = true;
        $('posCaTien').focus();
    }
    function moKhoaCa() {
        chuaMoCa = false;
        $('posCaKhoa').hidden = true;
        $('paneGoods').inert = $('paneBill').inert = false;
    }
    $('posCaTien').addEventListener('input', (e) => {
        e.target.removeAttribute('aria-invalid');
        e.target.value = /\d/.test(e.target.value) ? so(soTien(e.target.value)) : '';
    });
    $('posCaForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const o = $('posCaTien'), nut = $('posCaMo');
        const bao = (m) => { $('posCaLoi').textContent = m || ''; $('posCaLoi').hidden = !m; };
        if (!o.value.trim()) {
            bao('Đếm và nhập số tiền đang có trong két (két trống thì nhập 0).');
            o.setAttribute('aria-invalid', 'true'); o.focus();
            return;
        }
        const them = window.gioMoCa.doc($('posCaKhoa'));
        if (them.loi) { bao(them.loi); $('posCaGio').focus(); return; }
        bao(''); nut.disabled = true; nut.classList.add('is-busy');
        try {
            const r = await fetch(D.moCaUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ opening_cash: soTien(o.value), note: $('posCaGhi').value.trim(), ...them.du }),
            });
            const j = await r.json().catch(() => ({}));
            // 409 = người khác vừa mở ca ở chi nhánh này: ca đã có, quầy bán được.
            if (r.ok || r.status === 409) {
                moKhoaCa();
                window.posToast(r.ok ? 'Đã mở ca — bắt đầu bán.' : (j.message || 'Chi nhánh đã có ca đang mở.'));
                $('posSearch').focus();
                return;
            }
            const loiO = j.errors ? Object.values(j.errors)[0] : null;
            bao((Array.isArray(loiO) ? loiO[0] : '') || j.message || 'Không mở được ca.');
        } catch (err) {
            bao('Mất kết nối — chưa mở được ca. Kiểm tra mạng rồi bấm lại.');
        } finally {
            nut.disabled = false; nut.classList.remove('is-busy');
        }
    });

    // Hình thức chi nhánh đang bật (Quản trị → Cài đặt → Phương thức thanh toán): mỗi ô
    // kèm tài khoản / máy / cổng của nó. Hoá đơn mới mở sẵn vào ô "mặc định ở quầy".
    const PT = (() => { try { return JSON.parse(D.phuongThuc || '[]'); } catch (e) { return []; } })();
    const ptCua = (ma) => PT.find((p) => p.ma === ma) || { ct: [] };
    const HT_MD = (PT.find((p) => p.md) || PT[0] || { ma: 'cash' }).ma;
    let hinhThuc = HT_MD;
    let dangGui = false;
    let vuaThem = null;

    /* =====================================================================
     * HOÁ ĐƠN ĐANG MỞ — { id, so, luc, gio: [{ id, ten, opt, gia, ton, sl, giam, vat }],
     *                     ten, sdt, cusId, voucher, ghiChu, hinhThuc, dua,
     *                     giamDon: { kieu, gt }, phuThu: { kieu, gt, ghiChu },
     *                     hddt: { bat, loai, ten, sdt, mst, cty, diaChi, email },
     *                     kh: { email, diaChi, mst } — hồ sơ khách quen đang chọn, để điền hoá đơn điện tử,
     * `gia` chỉ để HIỂN THỊ; tiền thu do API tính lại lúc chốt.
     * ===================================================================== */
    const hd = { ds: [], dang: null };

    const soTrong = () => { const dung = new Set(hd.ds.map((h) => h.so)); let n = 1; while (dung.has(n)) n++; return n; };
    // Khối thêm sau các bản trước: hoá đơn lưu từ bản cũ không có — nạp lên thì bù cho đủ.
    const khoiMoi = () => ({
        giamDon: { kieu: 'percent', gt: 0 },
        phuThu: { kieu: 'amount', gt: 0, ghiChu: '' },
        // loai '' = chưa lưu lần nào: mở hộp thì điền sẵn từ khách đang chọn.
        hddt: { bat: false, loai: '', ten: '', sdt: '', mst: '', cty: '', diaChi: '', email: '' },
        kh: { email: '', diaChi: '', mst: '' },
        // Đồng giá / khuyến mại: chương trình đang chọn + bản xem trước API trả. Chỉ để HIỂN THỊ.
        dongGia: { chon: [], gia: {}, qua: [] },
        khuyenMai: { chon: [], giam: 0, theo: [], qua: [] },
        // Thẻ thành viên của khách quen đang chọn: hạng, điểm còn dùng, điểm muốn đổi.
        tv: { hang: 0, diem: 0, dung: 0 },
    });
    const duKhoi = (h) => { const m = khoiMoi(); Object.keys(m).forEach((k) => { h[k] = Object.assign(m[k], h[k] || {}); }); return h; };
    const hdMoi = () => duKhoi({
        id: 'hd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6),
        so: soTrong(), luc: Date.now(), gio: [], ten: '', sdt: '', cusId: '', voucher: '', voucherGiam: 0, ghiChu: '', hinhThuc: HT_MD, dua: '',
    });
    const nhanHd = (h) => h.maTam || `Hoá đơn ${h.so}`;

    // MÃ TẠM: hoá đơn có món đầu tiên thì nhận mã "T-ngàytháng-số thứ tự trong ngày của máy"
    // để tab, thanh đáy, phiếu tạm tính gọi đúng một cái tên. KHÔNG phải mã đơn: mã DH… chỉ
    // sinh lúc chốt — giữ trước mã thật thì huỷ hoá đơn là sổ đơn nhảy số (lỗi 20 bảng bug).
    function capMaTam(h) {
        if (h.maTam || !h.gio.length) return;
        const d = new Date(), ngay = String(d.getDate()).padStart(2, '0') + String(d.getMonth() + 1).padStart(2, '0');
        let dem = { ngay, n: 0 };
        try { const x = JSON.parse(doc(KHOA_MA_TAM, 'null')); if (x && x.ngay === ngay) dem = x; } catch (e) { /* hỏng thì đếm lại từ 1 */ }
        dem.n += 1;
        ghi(KHOA_MA_TAM, JSON.stringify(dem));
        h.maTam = `T-${ngay}-${String(dem.n).padStart(2, '0')}`;
    }
    const cur = () => hd.ds.find((h) => h.id === hd.dang) || hd.ds[0];
    const gio = () => cur().gio;
    const demMon = (h) => h.gio.reduce((t, d) => t + d.sl, 0);

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
        hd.ds.forEach(capMaTam);
    }
    function luu() { cur().luc = Date.now(); ghi(KHOA_HD, JSON.stringify(hd)); }

    // Dải tab cuộn ngang: tab đang mở luôn được cuộn vào giữa, mũi tên báo còn tab bị che.
    function veTab() {
        const ds = hd.ds, list = $('invoiceTab');
        list.innerHTML = ds.map((h) => {
            const n = demMon(h), mo = h === cur();
            return `<button type="button" class="bill-tab" role="tab" aria-selected="${mo}" data-code="${h.id}">${esc(nhanHd(h))}${n ? ` <span class="count num">${n}</span>` : ''}</button>`;
        }).join('');
        const act = list.querySelector('[aria-selected="true"]');
        if (act) list.scrollLeft = act.offsetLeft - (list.clientWidth - act.offsetWidth) / 2;
        veMuiTen();
        $('invoiceTabCount').textContent = ds.length;
        $('newOrder').disabled = ds.length >= TOI_DA_HD;
    }
    function veMuiTen() {
        const list = $('invoiceTab'), trai = list.scrollLeft > 1, phai = list.scrollLeft + list.clientWidth < list.scrollWidth - 1;
        $('posTabsTrai').hidden = !trai; $('posTabsPhai').hidden = !phai;
        $('posTabsWrap').classList.toggle('fade-left', trai);
        $('posTabsWrap').classList.toggle('fade-right', phai);
    }
    $('invoiceTab').addEventListener('scroll', veMuiTen, { passive: true });
    addEventListener('resize', veMuiTen);
    $('posTabsTrai').addEventListener('click', () => $('invoiceTab').scrollBy({ left: -200, behavior: 'smooth' }));
    $('posTabsPhai').addEventListener('click', () => $('invoiceTab').scrollBy({ left: 200, behavior: 'smooth' }));

    // Mã đơn CHỈ sinh ra lúc chốt, không giữ trước: hoá đơn đang mở chỉ nằm trên máy quầy,
    // huỷ giỏ là con số bốc hơi và sổ đơn nhảy số mà không chỗ nào giải thích.

    function chuyenHd(id) { hd.dang = id; luu(); veTatCa(); if (!camUng()) $('posSearch').focus(); }
    function themHd() {
        if (hd.ds.length >= TOI_DA_HD) { nhac(`Đang mở ${TOI_DA_HD} hoá đơn — chốt hoặc đóng bớt rồi mở thêm.`); return; }
        const h = hdMoi(); hd.ds.push(h); chuyenHd(h.id);
    }
    let hoiXong = null;
    function hoiXacNhan(o, xong) {
        $('posHoiTieuDe').textContent = o.tieuDe || 'Xác nhận';
        $('posHoiBody').innerHTML = (o.doan || []).map((d) => `<p style="margin:0 0 8px">${esc(d)}</p>`).join('');
        $('posHoiOk').textContent = o.nutOk || 'Đồng ý';
        hoiXong = xong;
        hop('posHoiBox').show();
    }
    $('posHoiOk').addEventListener('click', () => {
        const f = hoiXong; hoiXong = null;
        hop('posHoiBox').hide();
        if (f) f(true);
    });
    // Bấm Giữ lại, đóng hay nhấn Esc đều là KHÔNG đồng ý.
    $('posHoiBox').addEventListener('hop:dong', () => {
        const f = hoiXong; hoiXong = null;
        if (f) f(false);
    });

    function dongHd(id, hoi = true) {
        const h = hd.ds.find((x) => x.id === id);
        if (!h) return;
        if (hoi && h.gio.length) {
            hoiXacNhan({
                tieuDe: 'Huỷ hoá đơn?',
                doan: [`${demMon(h)} món trong ${nhanHd(h)} sẽ bị xoá khỏi quầy.`, 'Thao tác này không hoàn tác được.'],
                nutOk: 'Huỷ hoá đơn',
            }, (dongY) => { if (dongY) { boHd(h); window.posToast(`Đã huỷ ${nhanHd(h)}.`); } });
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
        const b = e.target.closest('.bill-tab');
        if (b && b.dataset.code !== hd.dang) chuyenHd(b.dataset.code);
    });
    $('newOrder').addEventListener('click', themHd);
    $('invoiceTabToggle').addEventListener('click', () => {
        $('posHdDS').innerHTML = hd.ds.map((h) => {
            const mo = h === cur(), ten = h.ten.trim() || h.sdt.trim() || 'Khách lẻ';
            return `<li><button type="button" class="pick${mo ? ' is-current' : ''}" data-code="${h.id}"${mo ? ' aria-current="true"' : ''}>
                <i class="fa-solid fa-receipt"></i>
                <span class="pick__main"><span class="pick__title">${esc(nhanHd(h))}${mo ? ' · đang mở' : ''}</span><span class="pick__sub">${demMon(h)} món · ${esc(ten)}</span></span>
            </button></li>`;
        }).join('');
        $('posHdMoi').disabled = hd.ds.length >= TOI_DA_HD;
        hop('posHdBox').show();
    });
    $('posHdDS').addEventListener('click', (e) => {
        const b = e.target.closest('[data-code]');
        if (b) { hop('posHdBox').hide(); chuyenHd(b.dataset.code); }
    });
    $('posHdMoi').addEventListener('click', () => { hop('posHdBox').hide(); themHd(); });
    // Hai cửa sổ quầy trên cùng một máy: hoá đơn đổi bên này thì bên kia vẽ lại.
    window.addEventListener('storage', (e) => { if (e.key === KHOA_HD) { napHd(); veTatCa(); } });

    /* =====================================================================
     * Ô THÔNG TIN CỦA HOÁ ĐƠN
     * ===================================================================== */
    const O = { voucher: 'posVoucher', ghiChu: 'posNote', dua: 'posTendered' };

    function napForm() {
        const h = cur();
        Object.entries(O).forEach(([k, id]) => { $(id).value = h[k] || ''; });
        $('posNoteBtn').setAttribute('aria-pressed', String(!!h.ghiChu));
        veKhach();
    }
    // Ô khách: tên · hạng · số · điểm của khách đang bán, hoặc "Khách lẻ".
    function veKhach() {
        const h = cur(), ten = h.ten.trim(), sdt = h.sdt.trim();
        if (!ten && !sdt) {
            $('posCusNhan').innerHTML = '<span class="customer__name">Khách lẻ</span> <span class="customer__info">bán cho người tiêu dùng</span>';
            return;
        }
        const r = h.cusId ? hangCua(h) : null, diem = Number(h.tv.diem) || 0;
        $('posCusNhan').innerHTML = `<span class="customer__name">${esc(ten || sdt)}</span>${r ? ` <span class="rank">${esc(r.name)}</span>` : ''}`
            + `${ten && sdt ? ` <span class="customer__info">${esc(sdt)}</span>` : ''}${diem ? ` <span class="customer__info">${so(diem)} điểm</span>` : ''}`;
    }
    const datKhach = (ten, sdt, cusId, k) => {
        Object.assign(cur(), { ten: ten || '', sdt: sdt || '', cusId: cusId || '' });
        cur().kh = { email: k?.email || '', diaChi: k?.address || '', mst: k?.tax_code || '' };
        // Đổi khách là bỏ số điểm đang định đổi của khách trước.
        cur().tv = { hang: Number(k?.rank_id) || 0, diem: Number(k?.points) || 0, dung: 0 };
        luu(); veKhach(); capNhatTien();
        // Đổi khách thì mã riêng của khách trước không còn hợp lệ — hỏi lại mã đang áp.
        tinhLaiMaGiam();
    };

    // Cặp nút VNĐ / % (giảm cả đơn và phụ thu).
    const datKieu = (boc, kieu) => $(boc).querySelectorAll('[data-kieu]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.kieu === kieu)));
    const soNhap = (v, kieu) => (kieu === 'amount' ? soTien(v) : Math.max(0, Number(String(v ?? '').replace(',', '.')) || 0));
    /*
     * Đọc ô số GIỮ NGUYÊN DẤU — dùng chung cho hộp Giảm giá và hộp Phụ thu.
     *
     * soTien() lọc sạch ký tự không phải chữ số nên nuốt luôn dấu trừ, còn
     * soNhap() kẹp âm về 0. Hai hàm ấy vẫn giữ nguyên cho những chỗ khác; riêng
     * hai hộp này cần thấy số âm để mà TỪ CHỐI, thay vì lặng lẽ đổi dấu.
     */
    const soCoDau = (v, kieu) => {
        const am = String(v ?? '').trim().startsWith('-');
        const n = kieu === 'amount'
            ? soTien(v)
            : Math.abs(Number(String(v ?? '').replace(',', '.').replace(/[^\d.]/g, '')) || 0);
        return am ? -n : n;
    };
    const hienNhap = (n, kieu) => (kieu === 'amount' ? dinhSo(n) : (n ? String(n).replace('.', ',') : ''));
    const CO_GIAM_DON = !!$('posGiamDon');

    // Hộp giảm giá: giảm tay trên cả đơn (trong hạn quyền) + mã khách đưa. Kẹp hạn quyền ở đây chỉ
    // là phép lịch sự — API chặn thật, kể cả khi gõ số tiền.
    let kieuGiam = 'percent';
    const loiGiam = (m) => { $('posGiamLoi').textContent = m || ''; $('posGiamLoi').hidden = !m; };
    // Gõ lại mã là bỏ câu lỗi của lần trước: để nguyên thì câu cũ đứng cạnh mã mới,
    // đọc lên tưởng mã mới cũng hỏng.
    $('posVoucher').addEventListener('input', () => loiGiam(''));
    function moGiam() {
        if (dangDongGia()) { nhac('Không thể chọn khuyến mãi khi đã dùng đồng giá!'); return; }
        const h = cur();
        loiGiam('');
        $('posVoucher').value = h.voucher;
        if (CO_GIAM_DON) { kieuGiam = h.giamDon.kieu; datKieu('posGiamKieu', kieuGiam); $('posGiamDon').value = hienNhap(h.giamDon.gt, kieuGiam); }
        $('posTvDiem').value = h.tv.dung || 0; $('posTvDungHet').checked = false;
        veThanhVien();
        hop('posGiamBox').show();
    }
    $('posGiamBox').addEventListener('hop:mo', () => (CO_GIAM_DON ? $('posGiamDon') : $('posVoucher')).focus());
    if (CO_GIAM_DON) {
        const han = String(HAN_MUC).replace('.', ',');
        $('posGiamHan').textContent = (HAN_MUC >= 100 ? '' : `Tối đa ${han}% tiền hàng. `) + 'Bớt riêng từng món thì bấm vào đơn giá của món đó.';
        $('posGiamKieu').addEventListener('click', (e) => {
            const b = e.target.closest('[data-kieu]');
            if (!b || b.dataset.kieu === kieuGiam) return;
            kieuGiam = b.dataset.kieu; datKieu('posGiamKieu', kieuGiam); $('posGiamDon').value = ''; $('posGiamDon').focus();
        });
        $('posGiamDon').addEventListener('input', (e) => {
            // Chấm ngăn nghìn nhưng GIỮ dấu trừ vừa gõ — xoá đi là đổi nghĩa con số
            // ngay dưới tay người nhập.
            if (kieuGiam === 'amount') {
                const am = e.target.value.trim().startsWith('-');
                e.target.value = (am ? '-' : '') + dinhSo(soTien(e.target.value));
            }
            loiGiam('');
        });
    }
    /*
     * Áp dụng giảm giá: HỎI API TRƯỚC, đóng hộp SAU.
     *
     * Bản trước đóng hộp rồi mới hỏi mã cho "mượt tay". Mã sai thì hộp đã đóng,
     * ô mã vẫn còn chữ vừa gõ, tổng không đổi — nhìn y hệt lúc áp mã thành công,
     * chỉ thiếu dòng "Giảm giá (mã …)". Câu giải thích của API có bắn ra báo nổi,
     * nhưng báo nổi ở góc dưới trong 3,5 giây thì người đang nhìn vào hộp không
     * thấy; mà lúc hộp còn mở thì nó còn bị chính tờ hộp che (xem ghi chú ở khối
     * #posGiamLoi).
     *
     * Nên: mã đổi thì chờ API trả lời. Hỏng thì GIỮ hộp lại, in câu của API vào
     * trong hộp, ô mã còn nguyên để sửa. Chỉ khi mã qua được mới ghi và đóng hộp.
     *
     * Cả lượt áp dụng là MỘT việc: giảm tay và điểm cũng chỉ ghi khi mã qua, không
     * thì người bán sửa mã xong lại thấy số điểm đã bị trừ từ lần bấm hỏng.
     */
    $('posGiamForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const h = cur();

        let giamDonMoi = null;
        if (CO_GIAM_DON) {
            let gt = soCoDau($('posGiamDon').value, kieuGiam);
            if (kieuGiam === 'percent') gt = Math.round(gt * 100) / 100;

            // Số âm: "giảm -5.000" là cộng thêm tiền, không phải giảm. Nói ra rồi
            // giữ hộp lại, như hộp Phụ thu.
            if (gt < 0) {
                loiGiam('Mức giảm phải lớn hơn 0 — muốn cộng thêm tiền thì dùng Phụ thu.');
                return;
            }
            // Quá trần: trước đây có một dòng kẹp lặng lẽ hạ xuống, nên gõ nhầm 150%
            // là khách trả 0 đ mà không một câu báo. Chỉ xét ở đây khi hạn quyền đủ
            // 100% — hạn quyền thấp hơn thì nhánh dưới đã nói bằng con số của họ.
            if (kieuGiam === 'percent' && gt > 100 && HAN_MUC >= 100) {
                loiGiam('Giảm theo % tối đa là 100% tiền hàng.');
                return;
            }
            // Nhánh VNĐ cũng vậy, nhưng chỗ kẹp nằm tận trong giamDon()
            // (`Math.min(v, th)`) nên lượt Áp dụng không thấy để mà báo: gõ
            // 30.000.000 trên giỏ 22.000.000 thì hộp đóng êm, khách trả 0 đ, mà mở
            // lại hộp ô vẫn ghi 30.000.000 — số bày ra và số đang áp lệch nhau.
            //
            // Vẫn giữ phép kẹp trong giamDon(): nó còn lo trường hợp bớt hàng SAU khi
            // đã đặt mức giảm, lúc ấy không ai gõ sai cả.
            if (kieuGiam === 'amount' && HAN_MUC >= 100 && gt > tienHang()) {
                loiGiam(`Mức giảm không vượt quá tiền hàng ${so(tienHang())} đ.`);
                return;
            }

            const tran = kieuGiam === 'percent' ? HAN_MUC : Math.floor(tienHang() * HAN_MUC / 100);
            if (HAN_MUC < 100 && gt > tran) {
                // Vẫn là báo nổi: việc này KHÔNG chặn lượt áp dụng (kẹp xong đi tiếp,
                // hộp đóng ngay), nên toast còn hiện tiếp sau khi hộp đóng. In vào
                // khối lỗi trong hộp thì nó biến mất cùng lúc hộp đóng.
                nhac(kieuGiam === 'percent' ? `Bạn được giảm cả đơn tối đa ${HAN_MUC}%.` : `Bạn được giảm cả đơn tối đa ${so(tran)} đ (${HAN_MUC}% tiền hàng).`);
                gt = tran;
            }
            giamDonMoi = { kieu: kieuGiam, gt };
        }
        const diemMoi = diemNhap();
        const maMoi = $('posVoucher').value.trim().toUpperCase();

        // Mã không đổi thì khỏi hỏi lại: số giảm đã có sẵn và giỏ chưa đụng tới.
        let maQua = { ma: h.voucher, giam: h.voucherGiam };
        if (maMoi !== (h.voucher || '')) {
            if (!maMoi) {
                maQua = { ma: '', giam: 0 };
            } else if (!gio().length) {
                loiGiam('Chưa có sản phẩm nào trong giỏ.');
                return;
            } else {
                const nut = $('posGiamNut');
                const chu = nut.textContent;
                nut.disabled = true; nut.textContent = 'Đang kiểm mã…';
                try {
                    maQua = { ma: maMoi, giam: await hoiMaGiam(maMoi) };
                } catch (err) {
                    loiGiam(err.message);
                    return;
                } finally {
                    nut.disabled = false; nut.textContent = chu;
                }
            }
        }

        if (giamDonMoi) h.giamDon = giamDonMoi;
        h.tv.dung = diemMoi;
        h.voucher = maQua.ma; h.voucherGiam = maQua.giam;
        luu(); capNhatTien(); hop('posGiamBox').hide();
    });

    $('posNote').addEventListener('input', (e) => {
        cur().ghiChu = e.target.value; luu();
        $('posNoteBtn').setAttribute('aria-pressed', String(!!e.target.value));
    });
    $('posNoteBtn').addEventListener('click', () => hop('posNoteBox').show());
    $('posNoteBox').addEventListener('hop:mo', () => $('posNote').focus());

    // Chọn khách quen: mở hộp là có ngay trang đầu danh sách (API nhận từ khoá rỗng);
    // gõ tên / số để lọc. "Khách lẻ" luôn đứng đầu.
    let khachGoiY = [], lanHoiKhach = 0, henKhach = null;
    const dongKhach = (k, i) => `<li><button type="button" class="pick" data-i="${i}"><i class="fa-solid ${Number(k.customer_type) === 1 ? 'fa-building' : 'fa-user'}"></i><span class="pick__main"><span class="pick__title">${esc(k.name || k.full_name || '')}${k.rank_name ? ` <span class="rank">${esc(k.rank_name)}</span>` : ''}</span><span class="pick__sub">${esc(k.phone || k.code || '')}</span></span>${Number(k.points) ? `<span class="pick__right pick__sub">${so(k.points)} điểm</span>` : ''}</button></li>`;
    const veDsKhach = (trangThai) => {
        const q = $('posCusName').value.trim();
        const le = '<li><button type="button" class="pick" data-i="-1"><i class="fa-solid fa-user-group"></i><span class="pick__main"><span class="pick__title">Khách lẻ</span><span class="pick__sub">Bán cho người tiêu dùng</span></span></button></li>';
        $('posCusDem').textContent = trangThai === 'tai' ? 'Đang tải danh sách khách…'
            : trangThai === 'loi' ? '' : q ? `${khachGoiY.length} khách khớp “${q}”` : `${khachGoiY.length} khách gần nhất — gõ tên hoặc số để lọc`;
        if (trangThai === 'loi') {
            $('posCusDS').innerHTML = le + '<li class="notice is-error" style="margin:12px auto"><strong>Không tải được danh sách khách</strong><span>Kiểm tra mạng rồi gõ lại để tìm.</span></li>';
            return;
        }
        $('posCusDS').innerHTML = (q ? '' : le) + khachGoiY.map(dongKhach).join('')
            + (trangThai !== 'tai' && !khachGoiY.length ? (q
                ? `<li class="notice" style="margin:12px auto"><strong>Không có khách nào khớp “${esc(q)}”</strong><button type="button" class="btn btn--outline" data-khach-moi><i class="fa-solid fa-user-plus"></i> Thêm khách mới</button></li>`
                : '<li class="notice" style="margin:12px auto"><strong>Cửa hàng chưa có khách nào</strong><span>Bấm “Thêm khách mới” để lưu khách đầu tiên.</span></li>') : '');
    };
    async function taiKhach(q) {
        const lan = ++lanHoiKhach;
        veDsKhach('tai');
        try {
            const r = await fetch(`${D.customerUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
            if (!r.ok) throw new Error(String(r.status));
            const ds = (await r.json()).data || [];
            if (lan !== lanHoiKhach) return;
            khachGoiY = ds.slice(0, 10); veDsKhach();
        } catch (err) { if (lan === lanHoiKhach) { khachGoiY = []; veDsKhach('loi'); } }
    }
    $('posCusBtn').addEventListener('click', () => { $('posCusName').value = ''; khachGoiY = []; hop('posCusBox').show(); taiKhach(''); });
    $('posCusBox').addEventListener('hop:mo', () => $('posCusName').focus());
    $('posCusName').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); $('posCusDS').querySelector('.pick[data-i]:not([data-i="-1"])')?.click(); }
    });
    $('posCusName').addEventListener('input', (e) => {
        clearTimeout(henKhach);
        henKhach = setTimeout(() => taiKhach(e.target.value.trim()), 250);
    });
    $('posCusBox').addEventListener('click', (e) => {
        if (e.target.closest('[data-khach-moi]')) { moKhachMoi(); return; }
        const b = e.target.closest('#posCusDS .pick[data-i]');
        if (!b) return;
        const k = khachGoiY[Number(b.dataset.i)];
        if (k) datKhach(k.name || k.full_name || '', k.phone || '', String(k.id || ''), k); else datKhach('', '', '');
        hop('posCusBox').hide();
    });

    // Thêm nhanh khách — theo hộp thêm khách của quầy v2. Tích "Lưu" (mặc định, đặt lại mỗi
    // lần mở) thì tạo hồ sơ qua API rồi chọn luôn khách đó — trùng số điện thoại thì API trả
    // hồ sơ có sẵn. Bỏ tích thì chỉ ghi tên, số lên hoá đơn này.
    // Nhóm khách, ngày sinh, giới tính, CCCD, người đại diện, ghi chú của bản cũ chưa làm:
    // API quầy chưa nhận.
    const KHACH_O = { ten: 'posKhachTen', mst: 'posKhachMst', sdt: 'posKhachSdt', email: 'posKhachEmail' };
    const loiKhach = (m) => { $('posKhachLoi').textContent = m || ''; $('posKhachLoi').hidden = !m; };
    const xoaLoiKhach = () => { Object.values(KHACH_O).forEach((id) => { $(id + 'Loi').textContent = ''; $(id).removeAttribute('aria-invalid'); }); loiKhach(''); };
    function datLoaiKhach(loai) {
        $('posKhachLoai').querySelectorAll('[data-loai]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.loai === loai)));
        document.querySelectorAll('[data-khach-dn]').forEach((f) => { f.hidden = loai !== '1'; });
        $('posKhachTenNhan').textContent = loai === '1' ? 'Tên doanh nghiệp' : 'Tên khách';
    }
    function moKhachMoi() {
        ['posKhachTen', 'posKhachMst', 'posKhachSdt', 'posKhachEmail', 'posKhachDiaChi'].forEach((id) => { $(id).value = ''; });
        $('posKhachLuu').checked = true;
        datLoaiKhach('0'); xoaLoiKhach();
        // Gõ dở trong ô tìm khách rồi bấm "Thêm khách mới": đổ sang đúng ô (số → SĐT, chữ → tên).
        // Không gõ gì thì lấy tên / số khách lẻ đang ghi trên hoá đơn.
        const q = $('posCusBox').open ? $('posCusName').value.trim() : '';
        if (q) $(/^[\d\s.+]+$/.test(q) ? 'posKhachSdt' : 'posKhachTen').value = q;
        else if (!cur().cusId) { $('posKhachTen').value = cur().ten; $('posKhachSdt').value = cur().sdt; }
        hop('posKhachBox').show();
    }
    $('posCusMoi').addEventListener('click', moKhachMoi);
    $('posKhachBox').addEventListener('hop:mo', () => $('posKhachTen').focus());
    $('posKhachLoai').addEventListener('click', (e) => { const b = e.target.closest('[data-loai]'); if (b) datLoaiKhach(b.dataset.loai); });
    $('posKhachForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const loai = $('posKhachLoai').querySelector('[aria-checked="true"]').dataset.loai;
        // Số điện thoại gửi liền một dãy: API dò trùng theo số, "0977 111 222" và "0977111222" phải là một khách.
        const v = { ten: $('posKhachTen').value.trim(), mst: $('posKhachMst').value.trim(), sdt: $('posKhachSdt').value.replace(/[\s.]/g, ''), email: $('posKhachEmail').value.trim(), diaChi: $('posKhachDiaChi').value.trim() };
        xoaLoiKhach();
        const loi = {};
        if (!v.ten) loi.ten = loai === '1' ? 'Nhập tên doanh nghiệp.' : 'Nhập tên khách.';
        if (v.sdt && !/^0\d{9}$/.test(v.sdt)) loi.sdt = 'Số điện thoại gồm 10 số, bắt đầu bằng 0.';
        if (loai === '1' && v.mst && !/^\d{10}(-\d{3})?$/.test(v.mst)) loi.mst = 'Mã số thuế gồm 10 số, hoặc 10 số + “-” + 3 số.';
        if (v.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.email)) loi.email = 'Email chưa đúng dạng, ví dụ ten@gmail.com.';
        Object.entries(loi).forEach(([k, m]) => { $(KHACH_O[k] + 'Loi').textContent = m; $(KHACH_O[k]).setAttribute('aria-invalid', 'true'); });
        const dau = Object.keys(loi)[0];
        if (dau) { $(KHACH_O[dau]).focus(); return; }
        const mst = loai === '1' ? v.mst : '';
        if (!$('posKhachLuu').checked) {
            // Gõ tay tên/số = không còn là khách quen đã chọn (cusId về rỗng) — tính điểm sai người còn tệ hơn không tính.
            datKhach(v.ten, v.sdt, '', { address: v.diaChi, email: v.email, tax_code: mst });
            hop('posKhachBox').hide(); hop('posCusBox').hide();
            window.posToast(`Đã ghi ${v.ten} lên hoá đơn này (chưa lưu hồ sơ).`, 'success');
            return;
        }
        const nut = $('posKhachLuuNut');
        nut.disabled = true; nut.classList.add('is-busy');
        try {
            const r = await fetch(D.customerCreateUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ full_name: v.ten, phone: v.sdt, email: v.email, address: v.diaChi, customer_type: Number(loai), tax_code: mst }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) { loiKhach((j.errors ? Object.values(j.errors)[0]?.[0] : '') || j.message || 'Không lưu được khách hàng.'); return; }
            const k = (j.data || {}).customer || {};
            datKhach(k.name || v.ten, k.phone || v.sdt, String(k.id || ''), k);
            // Email của hồ sơ là nơi nhận hoá đơn điện tử nếu người bán bật xuất cho lượt này.
            if (k.email && !cur().hddt.email) { cur().hddt.email = k.email; luu(); }
            hop('posKhachBox').hide();
            window.posToast(j.message || 'Đã lưu khách.', j.data?.existed ? 'info' : 'success');
        } catch (err) {
            loiKhach('Mất kết nối — chưa lưu được khách. Bỏ tích "Lưu" để chỉ ghi lên hoá đơn này.');
        } finally {
            nut.disabled = false; nut.classList.remove('is-busy');
        }
    });
    $('posKhachXoa').addEventListener('click', () => { datKhach('', '', ''); hop('posKhachBox').hide(); hop('posCusBox').hide(); });

    /* =====================================================================
     * LƯỚI HÀNG
     * ===================================================================== */
    const loc = { q: '', nhom: '', sapXep: '', trang: 1 };
    // Hai nút nhóm ảo → kiểu xếp gửi lên API (không lọc theo nhóm nào).
    const SAP_XEP_NHOM = { best_seller: 'best_selling', new: 'created_desc' };
    let dsHang = [], lanHoiHang = 0, henTim = null, daTaiLan = false;
    let anhTat = doc(KHOA_ANH, '0') === '1';
    let soCot = Math.min(6, Math.max(2, Number(doc(KHOA_COT, '5')) || 5));

    const nhanBT = (v) => v.name || v.sku || '';
    const giaCua = (p, v) => { const g = Number(v.final_price ?? v.price ?? 0); return g > 0 ? g : Number(p.sale_price || p.base_price || 0); };
    const giaTrenThe = (p) => {
        const ds = (p.variants || []).map((v) => giaCua(p, v)).filter((g) => g > 0);
        if (!ds.length) return { gia: Number(p.sale_price || p.base_price || 0), tu: false };
        return { gia: Math.min(...ds), tu: new Set(ds).size > 1 };
    };
    const tonCua = (p) => (p.variants || []).reduce((t, v) => t + Number(v.stock || 0), 0);

    const veKhungTai = () => {
        $('list').innerHTML = `<div class="grid skeleton" style="--cols:${soCot}" aria-busy="true" aria-label="Đang tải hàng">${'<div class="card"><div class="card__img"></div><div class="card__body"><div class="sk-line"></div><div class="sk-line"></div></div></div>'.repeat(10)}</div>`;
    };

    async function taiHang() {
        const lan = ++lanHoiHang;
        if (!daTaiLan) veKhungTai();
        const qs = new URLSearchParams({ q: loc.q, page: loc.trang, page_size: CO_TRANG });
        if (loc.nhom) qs.set('category_id', loc.nhom);
        if (loc.sapXep) qs.set('sort', loc.sapXep);
        try {
            const r = await fetch(`${D.searchUrl}?${qs}`, { headers: { Accept: 'application/json' } });
            if (!r.ok) throw new Error(String(r.status));
            const j = await r.json();
            if (lan !== lanHoiHang) return;
            daTaiLan = true;
            dsHang = j.data || [];
            veLuoi(); vePhanTrang(j.meta || {});
        } catch (e) {
            if (lan !== lanHoiHang) return;
            $('div_pagination').innerHTML = '';
            $('list').innerHTML = `<div class="notice is-error" role="alert"><i class="fa-solid fa-wifi"></i><strong>Không tải được danh sách hàng</strong><span>Kiểm tra mạng rồi tải lại. Hoá đơn đang mở vẫn được giữ.</span><button type="button" class="btn btn--outline" data-tai-lai><i class="fa-solid fa-rotate-right"></i> Tải lại</button></div>`;
        }
    }

    function veLuoi() {
        if (!dsHang.length) {
            $('list').innerHTML = loc.q
                ? `<div class="notice"><i class="fa-solid fa-magnifying-glass"></i><strong>Không có hàng khớp “${esc(loc.q)}”</strong><span>Kiểm tra lại tên hoặc mã vạch, hoặc chọn nhóm “Tất cả”.</span><button type="button" class="btn btn--outline" data-xoa-tim>Xoá tìm kiếm</button></div>`
                : '<div class="notice"><i class="fa-solid fa-box-open"></i><strong>Nhóm này chưa có hàng</strong><span>Chọn nhóm khác hoặc tìm theo tên.</span></div>';
            return;
        }
        $('list').innerHTML = `<div class="grid${anhTat ? ' no-img' : ''}" style="--cols:${soCot}">${dsHang.map((p, i) => {
            const ton = tonCua(p), het = ton <= 0, it = !het && ton <= SAP_HET, g = giaTrenThe(p);
            return `<button type="button" class="card${het ? ' is-out' : ''}" data-i="${i}" ${het ? 'aria-disabled="true"' : ''} title="${esc(p.name)}">
                <span class="card__img">${p.thumbnail ? `<img src="${esc(p.thumbnail)}" alt="" loading="lazy" draggable="false">` : '<i class="fa-solid fa-box"></i>'}${het ? '<span class="card__badge"><i class="fa-solid fa-ban"></i> Hết hàng</span>' : ''}<span class="card__in num" data-in hidden></span>${het ? '' : '<span class="card__add" aria-hidden="true"><i class="fa-solid fa-plus"></i></span>'}</span>
                <span class="card__body">
                    <span class="card__name">${esc(p.name)}</span>
                    <span class="card__meta"><span class="card__price num">${g.tu ? 'từ ' : ''}${dinhSo(g.gia)}</span>${het ? '' : `<span class="card__stock${it ? ' is-low' : ''}">${it ? '<i class="fa-solid fa-triangle-exclamation"></i> Sắp hết · ' : 'Còn '}${so(ton)}</span>`}</span>
                </span>
            </button>`;
        }).join('')}</div>`;
        danhDauThe();
    }

    // Thẻ nào đang có trong hoá đơn: huy hiệu số lượng + viền.
    function danhDauThe() {
        const theoBienThe = {};
        gio().forEach((d) => { theoBienThe[d.id] = (theoBienThe[d.id] || 0) + d.sl; });
        $('list').querySelectorAll('.card[data-i]').forEach((the) => {
            const p = dsHang[Number(the.dataset.i)];
            const n = p ? (p.variants || []).reduce((t, v) => t + (theoBienThe[Number(v.id)] || 0), 0) : 0;
            const huy = the.querySelector('[data-in]');
            the.classList.toggle('is-in', n > 0);
            if (!huy) return;
            huy.hidden = !n; huy.textContent = n;
            huy.setAttribute('aria-label', `${n} trong hoá đơn`);
        });
    }

    function vePhanTrang(meta) {
        const n = Number(meta.total_pages || 0), t = Number(meta.page || loc.trang), tong = Number(meta.total || 0);
        const dem = tong ? `<span>${so(tong)} mặt hàng</span>` : '';
        if (n <= 1) { $('div_pagination').innerHTML = dem; return; }
        const nut = (den, huong, tat, nhan) => `<button type="button" class="icon-btn" aria-label="${nhan}" ${tat ? 'disabled' : `data-trang="${den}"`}><i class="fa-solid fa-angle-${huong}"></i></button>`;
        $('div_pagination').innerHTML = `${dem}${nut(t - 1, 'left', t <= 1, 'Trang trước')}<span class="num">${t} / ${n}</span>${nut(t + 1, 'right', t >= n, 'Trang sau')}`;
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
                them({ id: Number(d.product_variant_id), ten: d.product_name || '', opt: d.variant_name || '', sku: d.sku || '', anh: d.thumbnail || '', gia: Number(d.price || 0), ton: Number(d.stock || 0), vat: Number(d.vat || 0) });
                // Dọn ô ngay: máy quét không tự xoá, mã cũ còn đó thì mã sau nối vào thành rác.
                e.target.value = '';
                if (loc.q) { loc.q = ''; loc.trang = 1; taiHang(); }
                return;
            }
        } catch (err) { /* mất mạng: vẫn còn đường bấm thẻ */ }
        if (loc.q !== q) { loc.q = q; loc.trang = 1; await taiHang(); }
        const the = $('list').querySelector('.card:not(.is-out)');
        if (the) the.click(); else nhac(`Không tìm thấy hàng nào khớp “${q}”.`);
    });

    $('posCats').addEventListener('click', (e) => {
        const nut = e.target.closest('.cat');
        if (!nut) return;
        $('posCats').querySelectorAll('.cat').forEach((x) => x.setAttribute('aria-pressed', String(x === nut)));
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
        if (e.target.closest('[data-tai-lai]')) { taiHang(); return; }
        if (e.target.closest('[data-xoa-tim]')) { $('posSearch').value = ''; loc.q = ''; loc.trang = 1; taiHang(); $('posSearch').focus(); return; }
        const a = e.target.closest('.card[data-i]');
        if (!a) return;
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
            return `<li><button type="button" class="pick pos-var" data-i="${i}" ${ton <= 0 ? 'disabled' : ''}>
                <span class="pick__main"><span class="pick__title">${esc(nhanBT(v))}</span><span class="pick__sub">${esc(v.sku || '')}${v.sku ? ' · ' : ''}${ton <= 0 ? 'Hết hàng' : 'Còn ' + so(ton)}</span></span>
                <span class="pick__right num"><b>${tien(giaCua(p, v))}</b></span>
            </button></li>`;
        }).join('');
        hop('posVarBox').show();
    });
    const themBienThe = (p, v) => them({ id: Number(v.id), ten: p.name, opt: v.name || '', sku: v.sku || '', anh: v.thumbnail || p.thumbnail || '', gia: giaCua(p, v), ton: Number(v.stock || 0), vat: Number(p.vat || 0) });
    $('posVarDS').addEventListener('click', (e) => {
        const b = e.target.closest('.pos-var');
        if (!b || b.disabled || !hangDangChon) return;
        themBienThe(hangDangChon, hangDangChon.variants[Number(b.dataset.i)]);
        hop('posVarBox').hide();
    });
    $('posVarBox').addEventListener('hop:dong', () => { if (!coHopMo() && !camUng()) $('posSearch').focus(); });

    // Nút phễu: chọn nhóm nào hiện trên hàng nhóm (nhớ theo máy).
    const nhomAn = () => { try { return new Set(JSON.parse(doc(KHOA_NHOM_AN, '[]')).map(String)); } catch (e) { return new Set(); } };
    function veNhom() {
        const an = nhomAn();
        $('posCats').querySelectorAll('.cat').forEach((b) => {
            if (b.dataset.id === '') return;
            b.hidden = an.has(b.dataset.id);
            // Đang đứng ở nhóm vừa bị ẩn: kéo về "Tất cả", không thì lưới lọc theo một nhóm không còn nút nào sáng.
            if (b.hidden && b.getAttribute('aria-pressed') === 'true') $('posCats').querySelector('[data-id=""]').click();
        });
    }
    $('posNhomChon').addEventListener('click', () => {
        const an = nhomAn();
        $('posNhomDS').innerHTML = [...$('posCats').querySelectorAll('.cat')].filter((b) => b.dataset.id !== '')
            .map((b) => `<label class="switch"><input type="checkbox" value="${esc(b.dataset.id)}" ${an.has(b.dataset.id) ? '' : 'checked'}> ${esc(b.textContent.trim())}</label>`).join('')
            || '<p class="hint">Cửa hàng chưa có nhóm hàng nào.</p>';
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
    // Giá bán của dòng: giá đồng giá nếu dòng đang được chương trình phủ, không thì giá thường.
    const dangDongGia = () => cur().dongGia.chon.length > 0;
    const dangKhuyenMai = () => cur().khuyenMai.chon.length > 0;
    // Hàng tặng của cả đồng giá lẫn chương trình khuyến mại — cùng hiện dưới giỏ.
    const quaHienThi = () => [...cur().dongGia.qua, ...cur().khuyenMai.qua];
    const giamKM = () => Math.max(0, Math.min(Number(cur().khuyenMai.giam) || 0, tienHang() - giamDon()));
    // Mã giảm giá: số do API tính cho đúng giỏ này (xem apMaGiam), chặn trần ở tiền hàng như API.
    const giamMa = () => Math.max(0, Math.min(Number(cur().voucherGiam) || 0, tienHang()));

    // THẺ THÀNH VIÊN — tính trước như hộp giảm giá của v2; lúc chốt API tính lại
    // trên điểm thật (không đủ điểm thì từ chối). Giảm theo hạng tính trên tiền
    // hàng, điểm đổi kẹp trong số điểm còn và phần tiền còn phải trả.
    const TV = (() => { try { return JSON.parse(D.thanhVien || '{}'); } catch (e) { return {}; } })();
    const TV_MOI_DIEM = Number(TV.money_per_point) || 0;
    const hangCua = (h) => (TV.ranks || []).find((r) => Number(r.id) === Number(h.tv.hang));
    function giamHang() {
        const h = cur(), r = h.cusId ? hangCua(h) : null, th = tienHang(), gt = Number(r?.discount_value) || 0;
        if (!r || th <= 0 || gt <= 0) return 0;
        if (!r.apply_all_order_values) {
            if (r.min_order_value != null && th < Number(r.min_order_value)) return 0;
            if (Number(r.max_order_value) > 0 && th > Number(r.max_order_value)) return 0;
        }
        const g = Math.round(Math.min(r.discount_type === 'percent' ? th * Math.min(100, gt) / 100 : gt, th));
        return Math.max(0, Math.min(g, th - giamDon() - giamKM()));
    }
    const conTruocDiem = () => Math.max(0, tienHang() - giamDon() - giamKM() - giamHang());
    function diemDung(muon) {
        const h = cur();
        if (!h.cusId || TV_MOI_DIEM <= 0) return 0;
        const m = muon === undefined ? (Number(h.tv.dung) || 0) : muon;
        return Math.max(0, Math.min(m, Number(h.tv.diem) || 0, Math.floor(conTruocDiem() / TV_MOI_DIEM)));
    }
    const tienDiem = (muon) => Math.min(Math.round(diemDung(muon) * TV_MOI_DIEM), conTruocDiem());
    const diemNhap = () => ($('posTvDungHet').checked ? diemDung(Infinity) : diemDung(soTien($('posTvDiem').value)));
    function veThanhVien() {
        const h = cur(), r = hangCua(h);
        $('posTvKhoi').hidden = !h.cusId || (!r && TV_MOI_DIEM <= 0);
        $('posTvHang').textContent = r ? r.name : '(chưa có hạng)';
        $('posTvGiam').textContent = tien(giamHang());
        $('posTvDiemKhoi').hidden = TV_MOI_DIEM <= 0;
        $('posTvDungHet').disabled = !(Number(h.tv.diem) > 0);
        // Ô Điểm luôn bày SỐ ĐIỂM THẬT SỰ DÙNG ĐƯỢC, không phải số vừa gõ: thu ngân đọc ô
        // và sẽ nói với khách con số ấy. Chỉ ghi khi chữ khác đi — gán lại đúng chuỗi cũ
        // là đẩy con trỏ về cuối ô giữa lúc người ta đang sửa.
        const d = diemNhap();
        if ($('posTvDiem').value !== String(d)) $('posTvDiem').value = String(d);
        $('posTvDiemTien').textContent = tien(tienDiem(d));
        $('posTvConLai').textContent = `Còn ${so(Number(h.tv.diem) || 0)} điểm · 1 điểm = ${so(TV_MOI_DIEM)} đ`;
    }
    $('posTvDiem').addEventListener('input', () => { $('posTvDungHet').checked = false; veThanhVien(); });
    $('posTvDungHet').addEventListener('change', veThanhVien);
    const giaBan = (d) => (cur().dongGia.gia[d.id] ?? d.gia);
    const giaSauBot = (d) => giaBan(d) * (1 - (d.giam || 0) / 100);
    // Cùng công thức với API (buildOrderItems): bớt = làm tròn(giá × SL × %), rồi mới trừ.
    const thanhTien = (d) => giaBan(d) * d.sl - Math.round(giaBan(d) * d.sl * (d.giam || 0) / 100);

    function them(m) {
        if (!m.id) return;
        const g = gio(), dong = g.find((d) => d.id === m.id);
        const ten = m.ten + (m.opt ? ` (${m.opt})` : '');
        if (m.ton <= 0) { nhac(`${ten} đã hết hàng.`); return; }
        const sl = (dong ? dong.sl : 0) + 1;
        if (sl > m.ton) { nhac(`${ten} chỉ còn ${m.ton}.`); return; }
        if (dong) Object.assign(dong, { sl, gia: m.gia, ton: m.ton, vat: m.vat || 0, anh: dong.anh || m.anh || '', sku: dong.sku || m.sku || '' });
        else g.push({ id: m.id, ten: m.ten, opt: m.opt, sku: m.sku || '', anh: m.anh || '', gia: m.gia, ton: m.ton, sl: 1, giam: 0, vat: m.vat || 0 });
        vuaThem = m.id;
        baoLoi('');
        capMaTam(cur());
        sauKhiSuaGio();
        if (hepMan()) window.posToast(`Đã thêm ${ten}`, 'success');
    }

    function veGio() {
        const g = gio();
        if (!g.length) {
            $('posLines').innerHTML = `<div class="bill-empty"><img src="${esc(D.emptyImg)}" alt=""><strong>Hoá đơn chưa có hàng</strong><span>Quét mã vạch hoặc bấm vào thẻ hàng để thêm.</span><span>Nhân viên: <b>${esc(D.nguoiBan)}</b></span></div>`;
            danhDauThe();
            return;
        }
        // Mỗi món một thẻ hai hàng: ảnh · tên + loại + SKU · thành tiền / đơn giá × SL · bộ số lượng.
        const coBot = HAN_MUC > 0 && !dangDongGia(), slBang = camUng();
        const anh = (u) => (u ? `<img src="${esc(u)}" alt="" loading="lazy">` : '<i class="fa-solid fa-box"></i>');
        $('posLines').innerHTML = `<ul class="items" id="posCart" aria-label="Hàng trong hoá đơn">${g.map((d, i) => {
            const giaDg = cur().dongGia.gia[d.id], bot = giaBan(d) * d.sl - thanhTien(d);
            const nhanGia = `${so(giaSauBot(d))}${coBot ? '<i class="fa-solid fa-pen"></i>' : ''}`;
            return `<li class="item${d.id === vuaThem ? ' is-new' : ''}" data-i="${i}">
                <span class="item__img">${anh(d.anh)}</span>
                <div class="item__main">
                    <div class="item__name">${esc(d.ten)}</div>
                    ${d.opt || d.sku ? `<div class="item__meta">${d.opt ? `<span class="item__opt">${esc(d.opt)}</span>` : ''}${d.sku && !d.opt ? `<span class="item__sku">${esc(d.sku)}</span>` : ''}</div>` : ''}
                </div>
                <div class="item__amtbox"><b class="item__amt num">${tien(thanhTien(d))}</b>${bot > 0 ? `<span class="item__save num">−${tien(bot)}</span>` : ''}</div>
                <div class="item__price">${coBot ? `<button type="button" class="item__unit num" data-mo-giam title="Bớt giá món này">${nhanGia}</button>` : `<span class="item__unit num">${nhanGia}</span>`}<span class="item__x">×&nbsp;${d.sl}</span>${d.giam > 0 ? `<span class="item__off">−${String(d.giam).replace('.', ',')}%</span>` : ''}${giaDg !== undefined && giaDg !== d.gia ? '<span class="item__off">đồng giá</span>' : ''}</div>
                <span class="stepper stepper--sm">
                    <button type="button" aria-label="Bớt một" data-tru${d.sl <= 1 ? ' disabled' : ''}><i class="fa-solid fa-minus"></i></button>
                    <input type="text" inputmode="numeric" value="${d.sl}" aria-label="Số lượng ${esc(d.ten)}" data-sl${slBang ? ' readonly' : ''}>
                    <button type="button" aria-label="Thêm một" data-cong${d.sl >= d.ton ? ` disabled title="Chỉ còn ${d.ton} trong kho"` : ''}><i class="fa-solid fa-plus"></i></button>
                </span>
                <button type="button" class="item__del" aria-label="Xoá ${esc(d.ten)} khỏi hoá đơn" data-xoa><i class="fa-solid fa-xmark"></i></button>
            </li>`;
        }).join('')}${quaHienThi().map((q) => `<li class="item item--gift">
                <span class="item__img">${anh(q.thumbnail)}</span>
                <div class="item__main">
                    <div class="item__name">${esc(q.name)}</div>
                    <div class="item__meta"><span class="item__tag"><i class="fa-solid fa-gift"></i> Hàng tặng kèm</span></div>
                </div>
                <div class="item__amtbox"><b class="item__amt num">0 đ</b></div>
                <div class="item__price"><span class="item__x">SL&nbsp;${q.quantity}</span></div>
            </li>`).join('')}</ul>`;
        vuaThem = null;
        danhDauThe();
    }

    function sauKhiSuaGio() { luu(); veTab(); veGio(); capNhatTien(); tinhLaiDongGia(); tinhLaiKhuyenMai(); tinhLaiMaGiam(); }

    // Xoá món không hỏi lại (thu ngân xoá rất nhiều lần mỗi ca) nhưng cho hoàn tác trong 6 giây.
    let hoanTac = null;
    function xoaDong(viTri, nhan) {
        const h = cur(), bo = viTri.map((i) => ({ i, d: h.gio[i] })).filter((x) => x.d);
        if (!bo.length) return;
        h.gio = h.gio.filter((_, i) => !viTri.includes(i));
        const lam = () => {
            if (!hd.ds.includes(h)) { nhac(`${nhanHd(h)} đã thanh toán hoặc bị huỷ — không hoàn tác được.`); return; }
            bo.forEach(({ i, d }) => {
                const trung = h.gio.find((x) => x.id === d.id);
                if (trung) trung.sl = Math.min(d.ton, trung.sl + d.sl);
                else h.gio.splice(Math.min(i, h.gio.length), 0, d);
            });
            hoanTac = null;
            hd.dang = h.id; vuaThem = bo[0].d.id;
            sauKhiSuaGio(); napForm();
            window.posToast(`Đã khôi phục ${nhan}.`, 'success');
        };
        hoanTac = lam;
        sauKhiSuaGio();
        window.posToast(`Đã xoá ${nhan} khỏi ${nhanHd(h)}.`, 'info', { nhan: 'Hoàn tác', lam });
    }

    let dongDangSua = -1;
    $('posLines').addEventListener('click', (e) => {
        const tr = e.target.closest('.item[data-i]');
        if (!tr) return;
        const g = gio(), i = Number(tr.dataset.i), d = g[i];
        if (e.target.closest('[data-mo-giam]')) { moBotGia(i); return; }
        if (e.target.closest('[data-sl]') && camUng()) { moSoLuong(i); return; }
        if (e.target.closest('[data-xoa]')) { xoaDong([i], d.ten + (d.opt ? ` (${d.opt})` : '')); return; }
        if (e.target.closest('[data-tru]')) { if (d.sl > 1) d.sl--; else return; }
        else if (e.target.closest('[data-cong]')) { if (d.sl + 1 > d.ton) { nhac(`${d.ten} chỉ còn ${d.ton}.`); return; } d.sl++; }
        else return;
        sauKhiSuaGio();
    });
    $('posLines').addEventListener('change', (e) => {
        const tr = e.target.closest('.item[data-i]');
        if (!tr || !e.target.matches('[data-sl]')) return;
        const d = gio()[Number(tr.dataset.i)], n = Math.floor(soTien(e.target.value));
        if (n <= 0) d.sl = 1;
        else if (n > d.ton) { nhac(`${d.ten} chỉ còn ${d.ton} — đã đặt về ${d.ton}.`); d.sl = d.ton; }
        else d.sl = n;
        // Vẽ lại ở TICK SAU: `change` bắn ra ngay trong lượt blur, mà veGio() thay cả giỏ —
        // tức xoá đúng ô đang mất focus, Chrome kêu "node to be removed is no longer a child".
        setTimeout(sauKhiSuaGio);
    });
    $('posLines').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input')) e.target.blur(); });

    // Bớt giá một món — trong hạn quyền API trả; gõ quá thì báo ngay cạnh ô.
    function moBotGia(i) {
        const d = gio()[i];
        dongDangSua = i;
        $('posBotTen').innerHTML = `<b>${esc(d.ten)}</b>${d.opt ? ` · ${esc(d.opt)}` : ''} — ${tien(giaBan(d))}`;
        $('posBotPt').value = d.giam ? String(d.giam).replace('.', ',') : '';
        $('posBotHan').textContent = `Quyền của bạn: bớt tối đa ${String(HAN_MUC).replace('.', ',')}% mỗi món.`;
        $('posBotLoi').textContent = ''; $('posBotPt').removeAttribute('aria-invalid');
        hop('posBotBox').show();
    }
    $('posBotBox').addEventListener('hop:mo', () => { $('posBotPt').focus(); $('posBotPt').select(); });
    $('posBotForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const d = gio()[dongDangSua];
        if (!d) { hop('posBotBox').hide(); return; }
        const v = Number(String($('posBotPt').value).replace(',', '.')) || 0;
        if (v < 0 || v > HAN_MUC) {
            $('posBotLoi').textContent = `Chỉ được bớt từ 0 đến ${String(HAN_MUC).replace('.', ',')}%. Cần bớt nhiều hơn thì nhờ quản lý.`;
            $('posBotPt').setAttribute('aria-invalid', 'true');
            return;
        }
        d.giam = Math.round(v * 100) / 100;
        hop('posBotBox').hide();
        sauKhiSuaGio();
    });

    // Số lượng bằng bàn phím số (màn cảm ứng).
    function moSoLuong(i) {
        const d = gio()[i];
        dongDangSua = i;
        $('posSlTen').innerHTML = `<b>${esc(d.ten)}</b>${d.opt ? ` · ${esc(d.opt)}` : ''}`;
        $('posSlSo').value = d.sl;
        $('posSlTon').textContent = `Còn ${so(d.ton)} trong kho.`;
        $('posSlLoi').textContent = ''; $('posSlSo').removeAttribute('aria-invalid');
        hop('posSlBox').show();
    }
    $('posSlForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const d = gio()[dongDangSua];
        if (!d) { hop('posSlBox').hide(); return; }
        const n = parseInt(soTien($('posSlSo').value), 10);
        const loi = !(n >= 1) ? 'Nhập số lượng từ 1 trở lên.' : n > d.ton ? `Chỉ còn ${d.ton} trong kho — nhập tối đa ${d.ton}.` : '';
        $('posSlLoi').textContent = loi;
        $('posSlSo').setAttribute('aria-invalid', String(!!loi));
        if (loi) return;
        d.sl = n;
        hop('posSlBox').hide();
        sauKhiSuaGio();
    });

    // Bàn phím số: dùng chung cho tiền khách đưa và số lượng.
    const PHIM = ['7', '8', '9', 'del', '4', '5', '6', 'clr', '1', '2', '3', '000', '0', '00'];
    const NHAN_PHIM = { del: '<i class="fa-solid fa-delete-left"></i><span class="sr-only">Xoá một số</span>', clr: 'C<span class="sr-only"> — xoá hết</span>' };
    document.querySelectorAll('[data-numpad]').forEach((pad) => {
        pad.innerHTML = PHIM.map((k) => `<button type="button" class="np${NHAN_PHIM[k] ? ' np--fn' : ''}${k === '0' ? ' np--zero' : ''}" data-np="${k}">${NHAN_PHIM[k] || k}</button>`).join('');
        pad.addEventListener('click', (e) => {
            const k = e.target.closest('[data-np]')?.dataset.np;
            if (!k) return;
            const o = $(pad.dataset.numpad);
            let s = String(o.value).replace(/\D/g, '').replace(/^0+/, '');
            if (k === 'del') s = s.slice(0, -1);
            else if (k === 'clr') s = '';
            else s = (s + k).replace(/^0+/, '').slice(0, 12);
            o.value = s;
            o.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });

    $('posXoaHet').addEventListener('click', () => {
        hop('posThemBox').hide();
        if (gio().length) xoaDong(gio().map((_, i) => i), `${tongSl()} món`);
    });
    $('posClear').addEventListener('click', () => dongHd(cur().id));
    $('posThem').addEventListener('click', () => hop('posThemBox').show());
    $('posThemBox').addEventListener('click', (e) => {
        const b = e.target.closest('[data-them]');
        if (!b) return;
        ({ giam: moGiam, phuThu: moPhuThu, inLai: () => { hop('posThemBox').hide(); inLaiVuaBan(); } })[b.dataset.them]?.();
    });

    // PHỤ THU: lý do + số tiền hoặc %. % tính trên tiền hàng lúc chốt.
    let kieuPhuThu = 'amount';
    const loiPhuThu = (m) => { $('posPhuThuLoi').textContent = m || ''; $('posPhuThuLoi').hidden = !m; };

    /*
     * Đọc ô Giá trị GIỮ NGUYÊN DẤU.
     *
     * soTien() lọc sạch mọi ký tự không phải chữ số nên nuốt luôn dấu trừ, còn
     * soNhap() kẹp về 0. Gõ "-5000" vì thế thành 5.000 ngay lúc gõ, Lưu xong khối
     * tổng ghi "Phụ thu 5.000 đ" — người nhập có ý TRỪ mà khách bị CỘNG thêm, và
     * chốt chặn phụ thu âm bên API không bao giờ chạm tới vì số gửi lên đã dương.
     *
     * Không sửa soTien/soNhap: hộp Giảm giá dùng chung hai hàm ấy, cho số âm lọt
     * vào đó là đẻ ra một lỗi khác.
     */
    const soPhuThu = (v) => soCoDau(v, kieuPhuThu);

    const veQuyDoiPhuThu = () => {
        const gt = soPhuThu($('posPhuThuGt').value);
        $('posPhuThuQuyDoi').textContent = kieuPhuThu === 'percent'
            // Nói trước cái trần, đỡ phải gõ sai mới biết.
            // Quá trần thì THÔI quy đổi: bày "= 33.000.000 đ" cạnh câu "tối đa 100%"
            // là hai dòng nói ngược nhau ngay dưới tay người đang gõ.
            ? (gt > 0 && gt <= 100 ? `= ${tien(Math.round(tienHang() * gt / 100))} trên tiền hàng hiện tại · ` : '') + 'Tối đa 100%.'
            : 'Phụ thu cộng thẳng vào tổng, không tính thuế.';
    };
    function moPhuThu() {
        const p = cur().phuThu;
        kieuPhuThu = p.kieu; datKieu('posPhuThuKieu', kieuPhuThu);
        loiPhuThu('');
        $('posPhuThuGt').value = hienNhap(p.gt, kieuPhuThu); $('posPhuThuLyDo').value = p.ghiChu || '';
        veQuyDoiPhuThu(); hop('posPhuThuBox').show();
    }
    $('posPhuThuBox').addEventListener('hop:mo', () => $('posPhuThuGt').focus());
    $('posPhuThuKieu').addEventListener('click', (e) => {
        const b = e.target.closest('[data-kieu]');
        if (!b || b.dataset.kieu === kieuPhuThu) return;
        kieuPhuThu = b.dataset.kieu; datKieu('posPhuThuKieu', kieuPhuThu); $('posPhuThuGt').value = ''; loiPhuThu(''); veQuyDoiPhuThu(); $('posPhuThuGt').focus();
    });
    $('posPhuThuGt').addEventListener('input', (e) => {
        // Chấm ngăn nghìn nhưng GIỮ dấu trừ người ta vừa gõ — xoá nó đi là đổi
        // nghĩa con số ngay dưới tay người nhập mà không nói gì.
        if (kieuPhuThu === 'amount') {
            const am = e.target.value.trim().startsWith('-');
            e.target.value = (am ? '-' : '') + dinhSo(soTien(e.target.value));
        }
        loiPhuThu('');
        veQuyDoiPhuThu();
    });
    $('posPhuThuForm').addEventListener('submit', (e) => {
        e.preventDefault();
        let gt = soPhuThu($('posPhuThuGt').value);
        // Số âm là HIỂU NHẦM của người nhập, không phải số cần nắn lại: phụ thu
        // cộng vào tổng, muốn trừ tiền là việc của Giảm giá. Nói ra rồi giữ hộp
        // lại để họ sửa, thay vì lặng lẽ đổi dấu.
        if (gt < 0) {
            loiPhuThu('Phụ thu phải lớn hơn 0 — muốn trừ tiền thì dùng Giảm giá.');
            return;
        }
        // Quá 100%: cũng NÓI RA rồi giữ hộp lại, không lặng lẽ hạ xuống 100.
        // Gõ 150 rồi thấy hộp đóng êm, khối tổng ghi 22.000.000 đ thì người nhập
        // tin là đã lưu 150% — trong khi phần mềm vừa tự đổi thành 100%. Hộp Giảm
        // giá ngay cùng màn kẹp mức giảm thì có báo; phụ thu thì im.
        //
        // Giữ trần 100% chứ không bỏ: % ở đây tính trên tiền hàng, phụ thu lớn hơn
        // cả tiền hàng gần như luôn là gõ nhầm (định 15 thành 150), và ai thật sự
        // cần số lớn hơn thì đã có ô VNĐ.
        if (kieuPhuThu === 'percent') {
            if (gt > 100) {
                loiPhuThu('Phụ thu theo % tối đa là 100% tiền hàng. Cần hơn thì nhập bằng VNĐ.');
                return;
            }
            gt = Math.round(gt * 100) / 100;
        }
        cur().phuThu = { kieu: kieuPhuThu, gt, ghiChu: $('posPhuThuLyDo').value.trim() };
        luu(); capNhatTien(); hop('posPhuThuBox').hide();
    });
    $('posPhuThuBo').addEventListener('click', () => { cur().phuThu = khoiMoi().phuThu; loiPhuThu(''); luu(); capNhatTien(); hop('posPhuThuBox').hide(); });

    // Hai hàng "Giảm giá ✎" và "Phụ thu ✎" của cụm tiền được vẽ lại mỗi lần đổi số.
    $('posTotals').addEventListener('click', (e) => {
        if (e.target.closest('[data-mo-giam-don]')) moGiam();
        else if (e.target.closest('[data-mo-phu-thu]')) moPhuThu();
    });

    // HOÁ ĐƠN ĐIỆN TỬ — theo form của quầy v2: người mua Cá nhân / Doanh nghiệp. Bắt buộc như
    // bản cũ: cá nhân cần tên + địa chỉ; doanh nghiệp cần MST + tên đơn vị + địa chỉ.
    // CCCD, hộ chiếu, mã QHNS của bản cũ chưa làm: API chưa có chỗ lưu và chưa gửi sang cổng.
    const HDDT_O = { ten: 'posHddtTen', sdt: 'posHddtSdt', mst: 'posHddtMst', cty: 'posHddtCty', diaChi: 'posHddtDiaChi', email: 'posHddtEmail' };
    const hddtTuKhach = (h) => {
        const coMst = !!h.kh.mst;
        return { loai: coMst ? 'company' : 'personal', ten: coMst ? '' : h.ten.trim(), sdt: h.sdt.trim(), mst: h.kh.mst, cty: coMst ? h.ten.trim() : '', diaChi: h.kh.diaChi, email: h.kh.email };
    };
    const xoaLoiHddt = () => Object.values(HDDT_O).forEach((id) => { $(id + 'Loi').textContent = ''; $(id).removeAttribute('aria-invalid'); });
    function datLoaiHddt(loai) {
        $('posHddtLoai').querySelectorAll('[data-loai]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.loai === loai)));
        document.querySelectorAll('[data-hddt-dn]').forEach((f) => { f.hidden = loai !== 'company'; });
        document.querySelectorAll('[data-hddt-cn]').forEach((f) => { f.hidden = loai !== 'personal'; });
        xoaLoiHddt();
    }
    const dienHddt = (x) => { datLoaiHddt(x.loai || 'personal'); Object.entries(HDDT_O).forEach(([k, id]) => { $(id).value = x[k] || ''; }); };
    $('posHddt').addEventListener('click', () => {
        const h = cur();
        $('posHddtBat').checked = !!h.hddt.bat;
        $('posHddtNgay').value = new Date().toLocaleDateString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric' });
        $('posHddtHd').value = nhanHd(h);
        dienHddt(h.hddt.loai ? h.hddt : hddtTuKhach(h));
        hop('posHddtBox').show();
    });
    $('posHddtLoai').addEventListener('click', (e) => { const b = e.target.closest('[data-loai]'); if (b) datLoaiHddt(b.dataset.loai); });
    $('posHddtLayKhach').addEventListener('click', () => {
        const h = cur();
        if (!h.ten.trim() && !h.sdt.trim()) { nhac('Hoá đơn đang bán cho khách lẻ — chọn khách trước, hoặc nhập tay.'); return; }
        dienHddt(hddtTuKhach(h));
    });
    $('posHddtForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const loai = $('posHddtLoai').querySelector('[aria-checked="true"]').dataset.loai;
        const x = { bat: $('posHddtBat').checked, loai };
        Object.entries(HDDT_O).forEach(([k, id]) => { x[k] = $(id).value.trim(); });
        xoaLoiHddt();
        const loi = {};
        if (loai === 'company') {
            if (!x.mst) loi.mst = 'Nhập mã số thuế của đơn vị.';
            else if (!/^\d{10}(-\d{3})?$/.test(x.mst)) loi.mst = 'Mã số thuế gồm 10 số, hoặc 10 số + “-” + 3 số.';
            if (!x.cty) loi.cty = 'Nhập tên đơn vị như trên giấy đăng ký kinh doanh.';
        } else if (!x.ten) loi.ten = 'Nhập tên người mua — hoá đơn cá nhân phải có tên.';
        if (!x.diaChi) loi.diaChi = 'Nhập địa chỉ người mua.';
        if (x.sdt && !/^0\d{9}$/.test(x.sdt.replace(/[\s.]/g, ''))) loi.sdt = 'Số điện thoại gồm 10 số, bắt đầu bằng 0.';
        if (x.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x.email)) loi.email = 'Email chưa đúng dạng, ví dụ ketoan@congty.vn.';
        Object.entries(loi).forEach(([k, m]) => { $(HDDT_O[k] + 'Loi').textContent = m; $(HDDT_O[k]).setAttribute('aria-invalid', 'true'); });
        const dau = Object.keys(loi)[0];
        if (dau) { $(HDDT_O[dau]).focus(); return; }
        if (loai === 'personal') { x.mst = ''; x.cty = ''; }
        cur().hddt = x; luu(); capNhatTien(); hop('posHddtBox').hide();
        window.posToast(x.bat ? 'Đã lưu — hoá đơn điện tử sẽ xuất khi thanh toán.' : 'Đã lưu thông tin hoá đơn (chưa bật xuất).', 'success');
    });

    // TẠM TÍNH: in phiếu tạm tính của hoá đơn đang mở để khách xem trước khi trả tiền.
    // Chưa ghi gì vào sổ — giá cuối cùng vẫn do API tính lúc chốt.
    $('posTamTinh').addEventListener('click', () => {
        const g = gio();
        if (!g.length) return;
        const dong = g.map((d) => `<div class="mon"><div class="ten">${esc(d.ten)}${d.opt ? ` (${esc(d.opt)})` : ''}</div>`
            + `<div class="dong thut"><span>${d.sl} × ${so(giaSauBot(d))}</span><span>${so(thanhTien(d))}</span></div></div>`).join('')
            + quaHienThi().map((q) => `<div class="mon"><div class="ten">${esc(q.name)} (Tặng)</div><div class="dong thut"><span>${q.quantity} × 0</span><span>0</span></div></div>`).join('');
        const luc = new Date().toLocaleString('vi-VN', { hour12: false });
        // Cùng khuôn chữ với phiếu thật (pos/receipt); in bằng khung ẩn của khung quầy, không mở tab.
        window.posInHtml(`<!DOCTYPE html><html lang="vi"><head><meta charset="utf-8"><title>Phiếu tạm tính</title><style>`
            // Không đặt `size`: khổ do driver máy in quyết — xem chú thích @page ở pos/receipt.
            + `@page{margin:0}*{box-sizing:border-box}body{width:72mm;margin:0 auto;padding:0 2mm;font-family:Arial,"Liberation Sans","DejaVu Sans",sans-serif;font-variant-numeric:tabular-nums;font-size:12.5px;line-height:1.35;color:#000;background:#fff}`
            + `.ban{padding:3mm 0 6mm}.giua{text-align:center}.dong{display:flex;justify-content:space-between;gap:6px}.dong>span:last-child{white-space:nowrap}.thut{padding-left:6px}.mon+.mon{margin-top:4px}.ten{font-weight:700}`
            + `.ke{margin:5px 0;border-top:1px dashed #000}.ke-dam{margin:5px 0;border-top:2px solid #000}.tong{font-size:1.3em;font-weight:700}.tong>span{white-space:nowrap}`
            + `</style></head><body><div class="ban"><div class="giua" style="font-size:1.35em;font-weight:700;text-transform:uppercase">${esc(D.tenTiem)}</div><div class="ke"></div>`
            + `<div class="giua" style="font-size:1.1em;font-weight:700">PHIẾU TẠM TÍNH</div><div class="dong"><span>${esc(nhanHd(cur()))}</span><span>${luc}</span></div><div class="ke"></div>${dong}<div class="ke"></div>`
            + `<div class="dong"><span>Tiền hàng</span><span>${so(tamTinh())}</span></div>${botMon() > 0 ? `<div class="dong"><span>Bớt theo món</span><span>-${so(botMon())}</span></div>` : ''}`
            + `${giamDon() > 0 ? `<div class="dong"><span>Giảm cả đơn</span><span>-${so(giamDon())}</span></div>` : ''}`
            + cur().khuyenMai.theo.filter((c) => c.giam > 0).map((c) => `<div class="dong"><span>KM: ${esc(c.name)}</span><span>-${so(c.giam)}</span></div>`).join('')
            + `${phuThu() > 0 ? `<div class="dong"><span>Phụ thu${cur().phuThu.ghiChu ? ': ' + esc(cur().phuThu.ghiChu) : ''}</span><span>${so(phuThu())}</span></div>` : ''}`
            + `${thue() > 0 ? `<div class="dong"><span>Thuế sản phẩm</span><span>${so(thue())}</span></div>` : ''}`
            + `<div class="ke-dam"></div><div class="dong tong"><span>TỔNG CỘNG</span><span>${so(phaiTra())}</span></div><div class="ke-dam"></div>`
            + `<div class="giua" style="margin-top:6px;font-weight:700">(Chưa thanh toán)</div></div></body></html>`);
    });

    /* =====================================================================
     * ĐỒNG GIÁ — hỏi API chương trình giỏ đủ điều kiện, tick rồi Áp dụng.
     * Đơn đồng giá không dùng thêm giảm tay / mã giảm giá (như v2).
     * ===================================================================== */
    async function hoiDongGia(chon) {
        const r = await fetch(D.dongGiaUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ items: gio().map((d) => ({ product_variant_id: d.id, quantity: d.sl })), fixed_price_ids: chon }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'Không tính được đồng giá.');
        return j.data || { chuong_trinh: [], gia: [], qua: [] };
    }
    function ganDongGia(chon, kq) {
        const coDu = new Set((kq.chuong_trinh || []).map((c) => c.id));
        const giu = chon.filter((id) => coDu.has(id));
        cur().dongGia = {
            chon: giu,
            gia: giu.length ? Object.fromEntries((kq.gia || []).map((g) => [g.product_variant_id, Number(g.price)])) : {},
            qua: giu.length ? (kq.qua || []) : [],
        };
        luu(); veGio(); capNhatTien();
        return giu.length < chon.length;
    }
    let henDongGia = null;
    // Giỏ đổi thì giá đồng giá và hàng tặng có thể đổi theo (đủ / hết ngưỡng) — hỏi lại.
    function tinhLaiDongGia() {
        if (!dangDongGia()) return;
        clearTimeout(henDongGia);
        const chon = cur().dongGia.chon.slice();
        henDongGia = setTimeout(async () => {
            try {
                if (!gio().length) { ganDongGia([], {}); return; }
                if (ganDongGia(chon, await hoiDongGia(chon))) nhac('Giỏ không còn đủ điều kiện một chương trình đồng giá — đã bỏ chương trình đó.');
            } catch (e) { nhac(e.message); }
        }, 250);
    }
    const veChuongTrinh = (ds, chon) => ds.map((c) => `<li><label class="pick${chon.includes(c.id) ? ' is-current' : ''}">
        <input type="checkbox" value="${c.id}" ${chon.includes(c.id) ? 'checked' : ''}>
        <span class="pick__main"><span class="pick__title">${esc(c.code)}</span><span class="pick__sub">${esc(c.name)}</span></span>
    </label></li>`).join('');
    const doiChon = (e) => { const l = e.target.closest('.pick'); if (l) l.classList.toggle('is-current', e.target.checked); };

    $('posDongGia').addEventListener('click', async () => {
        const h = cur();
        if (!h.gio.length) { hop('posThemBox').hide(); nhac('Chưa có sản phẩm nào trong giỏ.'); return; }
        if (dangKhuyenMai() || h.voucher.trim() || giamDon() > 0 || h.gio.some((d) => d.giam > 0)) {
            hop('posThemBox').hide();
            nhac('Không thể dùng đồng giá khi đã sử dụng khuyến mãi — bỏ chương trình khuyến mãi, mã giảm giá và giảm tay trước.');
            return;
        }
        try {
            const kq = await hoiDongGia(h.dongGia.chon);
            const ds = kq.chuong_trinh || [];
            if (!ds.length) { hop('posThemBox').hide(); nhac('Đơn hàng chưa đủ điều kiện áp dụng chương trình đồng giá nào.'); ganDongGia([], {}); return; }
            $('posDongGiaDS').innerHTML = veChuongTrinh(ds, h.dongGia.chon);
            hop('posDongGiaBox').show();
        } catch (e) { nhac(e.message); }
    });
    $('posDongGiaDS').addEventListener('change', doiChon);
    $('posDongGiaApDung').addEventListener('click', async () => {
        const chon = [...$('posDongGiaDS').querySelectorAll('input:checked')].map((i) => Number(i.value));
        try {
            ganDongGia(chon, chon.length ? await hoiDongGia(chon) : {});
            hop('posDongGiaBox').hide();
        } catch (e) { nhac(e.message); }
    });

    /* =====================================================================
     * CHƯƠNG TRÌNH KHUYẾN MẠI — cộng dồn với mã giảm giá và giảm tay;
     * KHÔNG dùng cùng đồng giá (như v2).
     * ===================================================================== */
    async function hoiKhuyenMai(chon) {
        const r = await fetch(D.khuyenMaiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ items: gio().map((d) => ({ product_variant_id: d.id, quantity: d.sl })), program_ids: chon }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'Không tính được khuyến mãi.');
        return j.data || { chuong_trinh: [], giam: 0, theo_chuong_trinh: [], qua: [] };
    }
    function ganKhuyenMai(chon, kq) {
        const coDu = new Set((kq.chuong_trinh || []).map((c) => c.id));
        const giu = chon.filter((id) => coDu.has(id));
        cur().khuyenMai = giu.length
            ? { chon: giu, giam: Number(kq.giam) || 0, theo: kq.theo_chuong_trinh || [], qua: kq.qua || [] }
            : { chon: [], giam: 0, theo: [], qua: [] };
        luu(); veGio(); capNhatTien();
        return giu.length < chon.length;
    }
    /* =====================================================================
     * MÃ GIẢM GIÁ — hỏi API NGAY lúc Áp dụng, không đợi tới lúc chốt: không thì
     * màn bày tổng chưa trừ, đơn ghi một số, khách trả một số, tiền thối sai.
     * ===================================================================== */
    async function hoiMaGiam(ma) {
        const r = await fetch(D.voucherUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({
                items: gio().map((d) => ({ product_variant_id: d.id, quantity: d.sl })),
                code: ma,
                // Hạn mức "mỗi khách N lượt" nhận ra khách vãng lai bằng số điện thoại.
                phone: (cur().sdt || '').trim(),
                // Mã riêng (quà sinh nhật) chỉ nhận đúng khách được tặng.
                user_id: Number(cur().cusId) || 0,
            }),
        });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || 'Không kiểm được mã giảm giá.');
        return Number((j.data || {}).giam) || 0;
    }

    // Mã hỏng thì BỎ HẲN mã chứ không giữ lại: giữ một mã mà lúc chốt API sẽ từ chối là
    // hẹn người bán một lỗi nữa ở đúng lúc khách đang đứng chờ trả tiền.
    async function apMaGiam(ma) {
        const h = cur();
        if (!ma) { h.voucher = ''; h.voucherGiam = 0; luu(); capNhatTien(); return; }
        if (!gio().length) { h.voucher = ''; h.voucherGiam = 0; luu(); capNhatTien(); nhac('Chưa có sản phẩm nào trong giỏ.'); return; }
        try {
            const giam = await hoiMaGiam(ma);
            h.voucher = ma; h.voucherGiam = giam;
        } catch (e) {
            h.voucher = ''; h.voucherGiam = 0;
            nhac(e.message);
        }
        luu(); capNhatTien();
    }

    let henMaGiam = null;
    // Giỏ đổi thì số tiền giảm đổi theo (mã % tính trên tiền hàng, mã có mức tối thiểu có thể rớt).
    function tinhLaiMaGiam() {
        const ma = (cur().voucher || '').trim();
        if (!ma) return;
        clearTimeout(henMaGiam);
        henMaGiam = setTimeout(() => { apMaGiam(ma); }, 250);
    }

    let henKhuyenMai = null;
    // Giỏ đổi thì bậc đạt được có thể đổi (lên / xuống / hết) — hỏi lại như v2 áp lại lúc lưu món.
    function tinhLaiKhuyenMai() {
        if (!dangKhuyenMai()) return;
        clearTimeout(henKhuyenMai);
        const chon = cur().khuyenMai.chon.slice();
        henKhuyenMai = setTimeout(async () => {
            try {
                if (!gio().length) { ganKhuyenMai([], {}); return; }
                if (ganKhuyenMai(chon, await hoiKhuyenMai(chon))) nhac('Đơn không còn đủ điều kiện một chương trình khuyến mãi — đã bỏ chương trình đó.');
            } catch (e) { nhac(e.message); }
        }, 250);
    }
    $('posKhuyenMai').addEventListener('click', async () => {
        const h = cur();
        if (!h.gio.length) { hop('posThemBox').hide(); nhac('Chưa có sản phẩm nào trong giỏ.'); return; }
        if (dangDongGia()) { hop('posThemBox').hide(); nhac('Không thể chọn khuyến mãi khi đã dùng đồng giá!'); return; }
        try {
            const kq = await hoiKhuyenMai(h.khuyenMai.chon);
            const ds = kq.chuong_trinh || [];
            if (!ds.length) { hop('posThemBox').hide(); nhac('Đơn hàng chưa đủ điều kiện áp dụng chương trình khuyến mãi nào.'); ganKhuyenMai([], {}); return; }
            $('posKhuyenMaiDS').innerHTML = veChuongTrinh(ds, h.khuyenMai.chon);
            hop('posKhuyenMaiBox').show();
        } catch (e) { nhac(e.message); }
    });
    $('posKhuyenMaiDS').addEventListener('change', doiChon);
    $('posKhuyenMaiApDung').addEventListener('click', async () => {
        const chon = [...$('posKhuyenMaiDS').querySelectorAll('input:checked')].map((i) => Number(i.value));
        try {
            ganKhuyenMai(chon, chon.length ? await hoiKhuyenMai(chon) : {});
            hop('posKhuyenMaiBox').hide();
        } catch (e) { nhac(e.message); }
    });

    /* =====================================================================
     * TIỀN
     * ===================================================================== */
    const tongSl = () => gio().reduce((t, d) => t + d.sl, 0);
    const tamTinh = () => gio().reduce((t, d) => t + giaBan(d) * d.sl, 0);
    const botMon = () => gio().reduce((t, d) => t + (giaBan(d) * d.sl - thanhTien(d)), 0);
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
    // Thuế sản phẩm TẠM TÍNH theo đúng cách API tính (thueCuaDon): chia các khoản giảm cả đơn
    // về từng dòng theo tỉ trọng (đồng lẻ dồn dòng cuối), rồi thuế trên phần còn lại. Mã giảm giá
    // nằm trong nền tính thuế — bỏ sót thì lệch cả phần thuế của phần giảm ấy.
    function thue() {
        const g = gio(), goc = tienHang(), tong = Math.min(giamDon() + giamKM() + giamMa() + giamHang() + tienDiem(), goc);
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
    const phaiTra = () => Math.max(0, tienHang() - giamDon() - giamKM() - giamMa() - giamHang() - tienDiem()) + phuThu() + thue();

    function chonHinhThuc(m, luuLai = true) {
        const nut = $('posPayTabs').querySelector(`[data-method="${m}"]`) || $('posPayTabs').querySelector('[data-method]');
        hinhThuc = nut.dataset.method;
        $('posPayTabs').querySelectorAll('[data-method]').forEach((b) => b.setAttribute('aria-checked', String(b === nut)));
        cur().hinhThuc = hinhThuc;
        if (luuLai) luu();
        capNhatTien();
    }
    $('posPayTabs').addEventListener('click', (e) => { const b = e.target.closest('[data-method]'); if (b) chonHinhThuc(b.dataset.method); });

    function capNhatTien() {
        const tong = phaiTra(), bot = botMon(), gd = giamDon(), km = giamKM(), gm = giamMa(), tvh = giamHang(), td = tienDiem();
        const giam = gd + km + gm + tvh + td, dua = soTien($('posTendered').value), coHang = gio().length > 0, tienMat = hinhThuc === 'cash';
        const ck = hinhThuc === 'bank_transfer', laThe = hinhThuc === 'card', qrtd = hinhThuc === 'auto_qr';
        // Đơn đang chờ cổng: mã QR (kieu 'qr') hoặc lệnh đã đẩy xuống máy mPOS (kieu 'may').
        const cho = cur().qr, choMay = !!cho && cho.kieu === 'may', choQr = !!cho && !choMay;
        const mayMpos = laThe && theDangChon()?.loai === 'mpos_device';
        // Ô đang mở chính là ô của lượt chờ: bày trạng thái chờ, không phải lời nhắc đổi.
        const oCuaCho = (choQr && qrtd) || (choMay && laThe);
        const h = cur(), ma = h.voucher.trim(), pt = phuThu(), sl = tongSl();
        const chiTiet = [
            gd > 0 ? (h.giamDon.kieu === 'percent' ? `cả đơn ${String(h.giamDon.gt).replace('.', ',')}%` : 'cả đơn') : '',
            ma ? `mã ${ma}` : '',
            km > 0 ? 'khuyến mãi' : '',
            tvh > 0 ? 'hạng' : '',
            td > 0 ? `${so(diemDung())} điểm` : '',
        ].filter(Boolean).join(', ');
        $('posTotals').innerHTML = `
            <dt>Tiền hàng <span class="note num">(${sl} món)</span></dt><dd>${tien(tamTinh())}</dd>
            ${bot > 0 ? `<dt>Bớt theo món</dt><dd class="minus">−${tien(bot)}</dd>` : ''}
            <dt><button type="button" class="edit" data-mo-giam-don>Giảm giá <i class="fa-solid fa-pen-to-square"></i></button>${chiTiet ? ` <span class="customer__info note">(${esc(chiTiet)})</span>` : ''}</dt><dd class="${giam > 0 ? 'minus' : ''}">${giam > 0 ? '−' : ''}${tien(giam)}</dd>
            <dt><button type="button" class="edit" data-mo-phu-thu>Phụ thu <i class="fa-solid fa-pen-to-square"></i></button>${pt > 0 && h.phuThu.ghiChu ? ` <span class="customer__info note">(${esc(h.phuThu.ghiChu)})</span>` : ''}</dt><dd>${tien(pt)}</dd>
            <dt>Thuế sản phẩm</dt><dd>${tien(thue())}</dd>
            <dt class="grand">Khách cần trả</dt><dd class="grand">${tien(tong)}</dd>`;
        $('posHddt').setAttribute('aria-pressed', String(!!h.hddt.bat));
        $('posKhuyenMaiMark').hidden = !dangKhuyenMai();
        $('posDongGiaMark').hidden = !dangDongGia();
        $('posThemMark').hidden = !dangDongGia() && !dangKhuyenMai();
        $('posSubmit').disabled = !coHang || dangGui;
        $('posTamTinh').disabled = !coHang;
        document.querySelectorAll('[data-thanh-toan], [data-tam-tinh]').forEach((b) => { b.disabled = !coHang || (dangGui && b.matches('[data-thanh-toan]')); });
        // Giỏ trống và chỉ còn một hoá đơn thì không có gì để huỷ.
        $('posClear').disabled = !coHang && hd.ds.length <= 1;
        $('posBarDem').textContent = `${nhanHd(h)} · ${sl} món`;
        $('posBarTong').textContent = tien(tong);
        $('posSwitchDem').textContent = `(${sl})`;

        // Hộp thanh toán.
        $('posPayHd').textContent = `${nhanHd(h)} · ${h.ten.trim() || h.sdt.trim() || 'Khách lẻ'}`;
        $('posPayTong').textContent = tien(tong);
        $('posPayTienMat').hidden = !tienMat;
        $('posPayCk').hidden = !ck;
        $('posCkTkBoc').hidden = !ck || ptCua('bank_transfer').ct.length < 2;
        $('posPayThe').hidden = !laThe;
        $('posPayQrTd').hidden = !qrtd;
        if (ck) veQr(tong);
        if (laThe) veThe(tong);
        if (qrtd) veQrTd(tong);
        // Mã QR đang chờ mà khách đổi ý: chọn cách thu khác rồi Xác nhận là thu luôn trên
        // CHÍNH đơn đó (mã QR bị huỷ, giữ mã đơn và hàng đã giữ) — không phải huỷ rồi bán lại.
        // Hai chỗ không đổi thẳng được: sang QR (đơn đã có lần thử với cổng) và sang máy mPOS
        // (máy cần lệnh mới) — API cũng chặn, ở đây chỉ nói trước cho thu ngân.
        const khongDoi = !!cho && !oCuaCho && (qrtd || mayMpos);
        $('posDoiQr').hidden = !(cho && !oCuaCho);
        if (cho && !oCuaCho) {
            const ten = choMay ? 'máy mPOS' : 'QR', huy = choMay ? 'huỷ lệnh trên máy' : 'huỷ mã QR';
            $('posDoiQr').innerHTML = khongDoi
                ? `<i class="fa-solid fa-triangle-exclamation"></i> Đơn <b>${esc(cho.ma)}</b> đang chờ ${ten}. Muốn thu bằng ${qrtd ? 'mã QR' : 'máy mPOS'} thì ${huy} rồi bán lại.`
                : `<i class="fa-solid fa-arrows-rotate"></i> Đơn <b>${esc(cho.ma)}</b> đang chờ ${ten} — bấm Xác nhận sẽ <b>${huy}</b> và thu bằng cách này, giữ nguyên mã đơn.`;
        }
        const thoi = dua - tong;
        $('posChange').hidden = !(tienMat && dua > 0);
        $('posChange').classList.toggle('is-short', thoi < 0);
        $('posChangeLabel').textContent = thoi >= 0 ? 'Tiền thối' : 'Còn thiếu';
        $('posChangeVal').textContent = tien(Math.abs(thoi));
        const thieu = tienMat && dua > 0 && thoi < 0;
        const khongCo = (laThe && !theDangChon()) || (qrtd && !congDangChon()) || khongDoi;
        $('posXacNhan').disabled = $('posXacNhanIn').disabled = !coHang || thieu || dangGui || khongCo;
        // QR tự động / máy mPOS: một nút "Tạo mã QR" / "Gửi xuống máy"; phiếu in sau khi tiền
        // về (popup chỉ mở được trong lượt bấm).
        $('posXacNhan').innerHTML = `<span class="spin"></span> ${qrtd ? '<i class="fa-solid fa-qrcode"></i> Tạo mã QR' : mayMpos ? '<i class="fa-solid fa-paper-plane"></i> Gửi xuống máy' : 'Xác nhận'}`;
        $('posXacNhanIn').hidden = qrtd || mayMpos;
        $('posXacNhan').hidden = oCuaCho;
        // Ba con số đáng bấm nhất: trả đúng, chẵn chục nghìn, chẵn trăm nghìn.
        $('posGoiYTien').innerHTML = tong > 0
            ? [...new Set([tong, Math.ceil(tong / 10000) * 10000, Math.ceil(tong / 100000) * 100000])]
                .map((v, i) => `<button type="button" class="${i === 0 ? 'is-exact' : ''}" data-dat="${v}">${i === 0 ? 'Vừa đủ' : so(v)}</button>`).join('')
            : '';
    }
    const datDua = (n) => { $('posTendered').value = dinhSo(n); cur().dua = $('posTendered').value; luu(); capNhatTien(); };

    $('posTendered').addEventListener('input', (e) => { e.target.value = dinhSo(soTien(e.target.value)); cur().dua = e.target.value; luu(); capNhatTien(); });
    // Enter trong ô Khách đưa = chốt: gõ xong số tiền là việc cuối cùng.
    $('posTendered').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('posXacNhan').click(); } });
    $('posGoiYTien').addEventListener('click', (e) => { const c = e.target.closest('[data-dat]'); if (c) datDua(Number(c.dataset.dat)); });
    $('posMenhGia').addEventListener('click', (e) => { const b = e.target.closest('[data-add]'); if (b) datDua(soTien($('posTendered').value) + Number(b.dataset.add)); });

    // Nút Thanh toán mở hộp thanh toán; chốt thật ở hai nút trong hộp.
    function moPay() {
        if (chuaMoCa) { khoaCa(); return; }
        if (!gio().length || dangGui) return;
        baoLoi(''); veTomTatTT(); capNhatTien(); hop('posPayBox').show();
    }

    // Phiếu tóm tắt bên trái hộp thanh toán: thu ngân đọc lại đơn với khách trước khi thu.
    function veTomTatTT() {
        const h = cur(), giam = giamDon() + giamKM() + giamMa() + giamHang() + tienDiem(), bot = botMon(), pt = phuThu();
        $('posPayDem').textContent = `${tongSl()} món`;
        $('posPayMon').innerHTML = gio().map((d) => `<li><span class="pay-items__name">${esc(d.ten)}${d.opt || d.giam ? `<small>${esc(d.opt || '')}${d.opt && d.giam ? ' · ' : ''}${d.giam ? `bớt ${String(d.giam).replace('.', ',')}%` : ''}</small>` : ''}</span><span class="pay-items__qty num">×${d.sl}</span><b class="num">${tien(thanhTien(d))}</b></li>`).join('')
            + quaHienThi().map((q) => `<li class="is-gift"><span class="pay-items__name">${esc(q.name)}<small><i class="fa-solid fa-gift"></i> Tặng kèm</small></span><span class="pay-items__qty num">×${q.quantity}</span><b class="num">0 đ</b></li>`).join('');
        $('posPayCac').innerHTML = `<dt>Tiền hàng</dt><dd>${tien(tamTinh())}</dd>`
            + (bot > 0 ? `<dt>Bớt theo món</dt><dd class="minus">−${tien(bot)}</dd>` : '')
            + (giam > 0 ? `<dt>Giảm giá</dt><dd class="minus">−${tien(giam)}</dd>` : '')
            + (pt > 0 ? `<dt>Phụ thu${h.phuThu.ghiChu ? ` · ${esc(h.phuThu.ghiChu)}` : ''}</dt><dd>${tien(pt)}</dd>` : '')
            + `<dt>Thuế sản phẩm</dt><dd>${tien(thue())}</dd>`;
        $('posPayGhiChu').value = h.ghiChu || '';
        // Màn hẹp: danh sách món thu gọn để phần thu tiền lên ngay trước mắt.
        $('posPayMonBoc').open = !hepMan();
    }
    $('posPayGhiChu').addEventListener('input', (e) => {
        cur().ghiChu = e.target.value; $('posNote').value = e.target.value; luu();
        $('posNoteBtn').setAttribute('aria-pressed', String(!!e.target.value));
    });

    // QR CHUYỂN KHOẢN — cùng tài khoản với thanh toán online (Cài đặt → Thanh toán).
    // Có tên ngân hàng + số tài khoản thì dựng QR SePay kèm số tiền và nội dung; tên ngân
    // hàng gõ tự do nên có thể SePay không nhận — khi đó lùi về ảnh QR tĩnh đã tải lên,
    // không có thì báo cách sửa và vẫn để thông tin cho khách chuyển tay.
    const NH_CU = (() => { try { return JSON.parse(D.nganHang || '{}'); } catch (e) { return {}; } })();
    // Tài khoản đang chọn trong số tài khoản đang bật của chi nhánh; chưa có cấu hình (API
    // hỏng) thì tài khoản của trang Cài đặt cũ.
    let NH = NH_CU;
    function chonTk() {
        const ds = ptCua('bank_transfer').ct;
        if (!ds.length) { NH = NH_CU; return; }
        const tk = ds.find((d) => d.id === Number(cur().ckTk)) || ds.find((d) => d.md) || ds[0];
        NH = { id: tk.id, ten: tk.nh, so: tk.so, chu: tk.chu, suaTien: tk.suaTien, anh: '' };
        const o = $('posCkTk');
        if (o.options.length !== ds.length) o.innerHTML = ds.map((d) => `<option value="${d.id}">${esc(d.nh)} · ${esc(d.so)} · ${esc(d.chu)}</option>`).join('');
        o.value = String(tk.id);
    }
    $('posCkTk').addEventListener('change', (e) => { cur().ckTk = Number(e.target.value); luu(); capNhatTien(); });
    let noiDungCk = '';
    function veQr(soTienCk) {
        chonTk();
        noiDungCk = `TT ${nhanHd(cur())}`;
        const coTk = !!(NH.so || NH.anh);
        $('posQrInfo').hidden = !coTk;
        if (!coTk) {
            $('posQr').hidden = true; $('posQrTrong').hidden = false;
            $('posQrGhi').innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> Chưa có tài khoản nhận tiền — vào <b>Quản trị → Cài đặt → Phương thức thanh toán</b> thêm tài khoản cho chi nhánh này rồi tải lại trang quầy.';
            return;
        }
        $('posQrNh').textContent = NH.ten || '—';
        $('posQrSo').textContent = NH.so || '—';
        $('posQrChu').textContent = NH.chu || '—';
        $('posQrTien').textContent = tien(soTienCk);
        $('posQrND').textContent = noiDungCk;
        // "Khách tự gõ số tiền" (Cài đặt) → QR không kèm số tiền.
        const dong = NH.ten && NH.so ? `https://qr.sepay.vn/img?acc=${encodeURIComponent(NH.so)}&bank=${encodeURIComponent(NH.ten)}${NH.suaTien ? '' : `&amount=${Math.round(soTienCk)}`}&des=${encodeURIComponent(noiDungCk)}` : '';
        const anh = dong || NH.anh;
        $('posQrGhi').innerHTML = dong || !NH.anh
            ? '<i class="fa-solid fa-circle-info"></i> Mở app ngân hàng của khách, xem tiền đã vào tài khoản cửa hàng rồi mới bấm Xác nhận.'
            : '<i class="fa-solid fa-circle-info"></i> Mã QR tĩnh — khách tự nhập số tiền và nội dung bên cạnh.';
        $('posQrTrong').hidden = !!anh;
        $('posQr').hidden = !anh;
        if (anh && $('posQr').getAttribute('src') !== anh) $('posQr').src = anh;
    }
    $('posQr').addEventListener('error', () => {
        if (NH.anh && $('posQr').getAttribute('src') !== NH.anh) { $('posQr').src = NH.anh; return; }
        $('posQr').hidden = true; $('posQrTrong').hidden = false;
        $('posQrGhi').innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Không tạo được mã QR cho ngân hàng “${esc(NH.ten)}” — chọn lại ngân hàng của tài khoản ở Cài đặt → Phương thức thanh toán. Khách vẫn chuyển được theo số tài khoản bên cạnh.`;
    });
    $('posPayCk').addEventListener('click', (e) => {
        const b = e.target.closest('[data-chep]');
        if (!b) return;
        const v = b.dataset.chep === 'so' ? NH.so : noiDungCk;
        (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(() => window.posToast(`Đã chép ${v}`, 'success'), () => window.posToast(v));
    });
    // QUẸT THẺ — máy chọn sẵn là máy mặc định; phí thẻ cửa hàng chịu, ghi vào đơn để đối soát.
    const theDangChon = () => { const ds = ptCua('card').ct; return ds.find((d) => d.id === Number(cur().theMay)) || ds.find((d) => d.md) || ds[0] || null; };
    function veThe(tong) {
        const ds = ptCua('card').ct, may = theDangChon(), o = $('posTheMay'), cho = cur().qr?.kieu === 'may' ? cur().qr : null;
        if (o.options.length !== ds.length) o.innerHTML = ds.map((d) => `<option value="${d.id}">${esc(d.may || 'Máy')} · ${d.loai === 'mpos_device' ? 'mPOS' : 'máy độc lập'}</option>`).join('');
        if (may) o.value = String(may.id);
        $('posTheMayBoc').hidden = ds.length < 2 || !!cho;
        $('posTheTien').textContent = tien(cho ? cho.tong : tong);
        const mpos = may?.loai === 'mpos_device';
        $('posThePhi').innerHTML = !may ? '—'
            : mpos ? '<span class="hint">Theo loại thẻ — NextPay báo về mới tính</span>'
            : `${String(may.phi).replace('.', ',')}% ≈ <b class="num">${tien(Math.round(tong * may.phi / 100))}</b>`;
        $('posTheGhi').hidden = !!cho;
        $('posTheGhi').innerHTML = mpos
            ? '<i class="fa-solid fa-circle-info"></i> Bấm <b>Gửi xuống máy</b> — số tiền hiện sẵn trên máy mPOS, khách quẹt / chạm thẻ thành công là đơn tự hoàn tất.'
            : '<i class="fa-solid fa-circle-info"></i> Nhập đúng số tiền trên máy, cho khách quẹt / chạm thẻ. Máy in biên lai <b>thành công</b> rồi mới bấm Xác nhận.';
        $('posTheCho').hidden = !cho;
        if (cho) $('posTheChoCau').innerHTML = cho.lechCau
            ? `<i class="fa-solid fa-triangle-exclamation"></i> ${esc(cho.lechCau)}`
            : `<span class="qrtd__xoay" aria-hidden="true"></span> Đã gửi <b class="num">${tien(cho.tong)}</b> xuống máy <b>${esc(cho.may || 'mPOS')}</b> — chờ khách quẹt thẻ. Máy báo thành công là đơn <b>${esc(cho.ma)}</b> tự hoàn tất.`;
    }
    $('posTheMay').addEventListener('change', (e) => { cur().theMay = Number(e.target.value); luu(); capNhatTien(); });

    // QR TỰ ĐỘNG — tạo mã thì đơn ghi ngay ở trạng thái CHỜ TIỀN (hàng đã giữ), cổng báo tiền
    // về là đơn tự hoàn tất. Mã đang chờ lưu theo hoá đơn: tải lại trang vẫn hỏi tiếp được.
    const TEN_CONG = { sepay: 'SePay', payos: 'PayOS', mpos_qr: 'mPOS QR' };
    const congDangChon = () => { const ds = ptCua('auto_qr').ct; return ds.find((d) => d.id === Number(cur().qrCong)) || ds[0] || null; };
    function veQrTd(tong) {
        const h = cur(), ds = ptCua('auto_qr').ct, cong = congDangChon(), q = h.qr?.kieu === 'may' ? null : h.qr;
        $('posQrCong').hidden = ds.length < 2;
        $('posQrCong').innerHTML = ds.map((d) => `<button type="button" class="pay-cong__nut" role="radio" aria-checked="${cong && d.id === cong.id}" data-cong="${d.id}" ${q ? 'disabled' : ''}>${esc(TEN_CONG[d.loai] || d.loai)}</button>`).join('');
        $('posQrTdAnh').hidden = !q; $('posQrTdTrong').hidden = !!q;
        if (q && $('posQrTdAnh').getAttribute('src') !== q.anh) $('posQrTdAnh').src = q.anh;
        $('posQrTdInfo').hidden = !q; $('posQrTdHuy').hidden = !q;
        $('posQrTdThe').classList.toggle('is-cho', !!q);
        if (q) {
            $('posQrTdTien').textContent = tien(q.tong); $('posQrTdMa').textContent = q.ma;
            $('posQrTdTrang').innerHTML = q.lechCau
                ? `<i class="fa-solid fa-triangle-exclamation"></i> ${esc(q.lechCau)}`
                : '<span class="qrtd__xoay" aria-hidden="true"></span> Đang chờ khách quét mã… Tiền về là đơn tự hoàn tất, không cần bấm gì.';
        } else {
            $('posQrTdTrang').innerHTML = cong ? `Bấm <b>Tạo mã QR</b> — mã ${esc(TEN_CONG[cong.loai] || '')} có sẵn số tiền ${tien(tong)}, khách quét bằng app ngân hàng là xong.` : 'Chi nhánh chưa bật cổng QR nào.';
        }
    }
    $('posQrCong').addEventListener('click', (e) => { const b = e.target.closest('[data-cong]'); if (!b || cur().qr) return; cur().qrCong = Number(b.dataset.cong); luu(); capNhatTien(); });

    let hoiQr = null;
    function hoiQrTiep() {
        if (hoiQr) return;
        if (!hd.ds.some((h) => h.qr)) return;
        hoiQr = setInterval(async () => {
            const ds = hd.ds.filter((h) => h.qr);
            if (!ds.length) { clearInterval(hoiQr); hoiQr = null; return; }
            for (const h of ds) {
                try {
                    const r = await fetch(D.qrUrl.replace('__MA__', encodeURIComponent(h.qr.code)), { headers: { Accept: 'application/json' } });
                    if (!r.ok) continue;
                    const t = (await r.json()).data || {};
                    if (t.paid) { hoanTatDon(h, { order_id: h.qr.id, order_code: h.qr.ma, total_amount: h.qr.tong }, false, null); continue; }
                    // Cổng báo LỆCH số tiền: mã vẫn chờ (gói đúng tới sau vẫn hoàn tất) — chỉ
                    // nhắc thu ngân, một lần cho mỗi số lệch.
                    if (t.status === 'pending' && t.lech_tien != null && h.qr.lech !== t.lech_tien) {
                        h.qr.lech = t.lech_tien; h.qr.lechCau = t.message || ''; luu(); capNhatTien();
                        window.posToast(h.qr.lechCau, 'warn');
                    }
                    if (t.status === 'cancelled' || t.status === 'failed') {
                        const may = h.qr.kieu === 'may';
                        h.qr = null; luu(); capNhatTien();
                        window.posToast(may ? 'Lệnh trên máy mPOS không thành công — chọn cách thu khác.' : (t.message || 'Mã QR đã hết hạn hoặc bị huỷ — tạo mã mới hoặc chọn cách thu khác.'), 'warn');
                    }
                } catch (e) { /* mất mạng một nhịp: hỏi lại nhịp sau */ }
            }
        }, 3000);
    }
    // Huỷ lượt chờ (mã QR hoặc lệnh trên máy mPOS): đơn huỷ, hàng về kho.
    async function huyCho(nut) {
        const h = cur(), q = h.qr;
        if (!q || dangGui) return;
        const ten = q.kieu === 'may' ? 'lệnh trên máy mPOS' : 'mã QR';
        nut.disabled = true;
        try {
            const r = await fetch(D.huyQrUrl.replace('/0/cancel-qr', `/${q.id}/cancel-qr`), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF } });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) { baoLoi(j.message || `Không huỷ được ${ten}.`); return; }
            h.qr = null; luu(); capNhatTien(); baoLoi('');
            window.posToast(`Đã huỷ ${ten} — hàng đã trả về kho, chọn cách thu khác.`, 'success');
        } catch (e) {
            baoLoi(`Mất kết nối — ${ten} CHƯA được huỷ. Thử lại khi có mạng.`);
        } finally {
            nut.disabled = false;
        }
    }
    $('posQrTdHuy').addEventListener('click', () => huyCho($('posQrTdHuy')));
    $('posTheHuy').addEventListener('click', () => huyCho($('posTheHuy')));

    $('posSubmit').addEventListener('click', moPay);
    $('posPayBox').addEventListener('hop:mo', () => { if (hinhThuc === 'cash' && !camUng()) { $('posTendered').focus(); $('posTendered').select(); } });
    // Đang gửi đơn thì Esc không được đóng hộp — đóng giữa chừng là mất dấu lượt bán.
    $('posPayBox').addEventListener('cancel', (e) => { if (dangGui) e.preventDefault(); });

    /* =====================================================================
     * CHỐT ĐƠN
     * ===================================================================== */
    // Trình duyệt không cho biết tờ phiếu đã ra khỏi máy in hay chưa: chỉ báo điều biết chắc
    // (đã gửi lệnh in, hoặc không tải được phiếu) và luôn để sẵn nút In lại.
    // trangThai: null = không in, 'dang' = đang tải phiếu, true / false = đã gửi / hỏng.
    function veTrangThaiIn(trangThai, ma) {
        const o = $('posInTrangThai');
        o.hidden = trangThai === null;
        if (trangThai === null) return;
        const lai = '<button type="button" class="link-btn" data-in-lai-vua-ban>In lại</button>';
        if (trangThai === 'dang') {
            o.className = 'print-status';
            o.innerHTML = `<i class="fa-solid fa-print"></i><span>Đang gửi phiếu ${esc(ma)} ra máy in…</span>`;
        } else if (trangThai) {
            o.className = 'print-status is-ok';
            o.innerHTML = `<i class="fa-solid fa-print"></i><span>Đã gửi phiếu ${esc(ma)} ra máy in. Phiếu không ra? ${lai}</span>`;
        } else {
            o.className = 'print-status is-fail';
            o.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i><span><b>Chưa in được phiếu ${esc(ma)}.</b> Đơn đã ghi xong — bấm ${lai}.</span>`;
        }
    }

    async function chot(inPhieu) {
        const h = cur();
        if (!h.gio.length || dangGui) return;
        const tong = phaiTra(), dua = soTien($('posTendered').value), qrtd = hinhThuc === 'auto_qr';
        const mayMpos = hinhThuc === 'card' && theDangChon()?.loai === 'mpos_device';
        if (hinhThuc === 'cash' && dua > 0 && dua < tong) { baoLoi(`Khách đưa còn thiếu ${tien(tong - dua)}.`); return; }
        if ((qrtd || mayMpos) && h.qr) return;
        if (qrtd || mayMpos) inPhieu = false;
        // Đơn đã tạo (đang chờ QR): đổi cách thu trên chính đơn đó thay vì lập đơn mới.
        if (h.qr) return doiHinhThuc(h, inPhieu, tong, dua);

        baoLoi('');
        dangGui = true; capNhatTien();
        const nutBam = inPhieu ? $('posXacNhanIn') : $('posXacNhan');
        nutBam.classList.add('is-busy');

        const chiTiet = hinhThuc === 'bank_transfer' ? (chonTk(), NH.id) : hinhThuc === 'card' ? theDangChon()?.id : qrtd ? congDangChon()?.id : 0;
        const payload = {
            payment_method: qrtd ? congDangChon()?.loai : hinhThuc,
            customer_name: h.ten.trim(), customer_phone: h.sdt.trim(),
            voucher_code: h.voucher.trim(), note: h.ghiChu.trim(),
            items: h.gio.map((d) => ({ product_variant_id: d.id, quantity: d.sl, discount_percent: d.giam || 0 })),
        };
        if (h.cusId) payload.user_id = Number(h.cusId);
        if (h.dongGia.chon.length) payload.fixed_price_ids = h.dongGia.chon;
        if (h.khuyenMai.chon.length) payload.promotion_program_ids = h.khuyenMai.chon;
        if (h.cusId && diemDung() > 0) payload.use_points = diemDung();
        if (hinhThuc === 'cash' && dua > 0) payload.amount_tendered = dua;
        if (chiTiet) payload.payment_detail_id = chiTiet;
        // Giảm cả đơn: gửi ĐÚNG kiểu người bán gõ — % thì API tự tính tiền và kiểm hạn quyền trên %.
        if (giamDon() > 0) {
            if (h.giamDon.kieu === 'percent') payload.order_discount_percent = Number(h.giamDon.gt);
            else payload.order_discount_amount = giamDon();
        }
        if (phuThu() > 0) { payload.surcharge_amount = phuThu(); payload.surcharge_note = (h.phuThu.ghiChu || '').trim(); }
        if (h.hddt.bat) {
            payload.issue_einvoice = true;
            // Đơn không có cột tên người mua riêng: hoá đơn lấy tên / số người nhận của đơn.
            if (h.hddt.ten) payload.customer_name = h.hddt.ten;
            if (h.hddt.sdt) payload.customer_phone = h.hddt.sdt;
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
                if (j.errors?.ma === 'CHUA_MO_CA') { khoaCa(j.message); return; }
                // errors.ma là chuỗi mã máy đọc, không phải danh sách câu lỗi của ô.
                const loiO = j.errors && Array.isArray(Object.values(j.errors)[0]) ? Object.values(j.errors)[0][0] : '';
                // In NGUYÊN câu API trả: nó nói rõ món nào hết, thiếu bao nhiêu.
                baoLoi(loiO || j.message || 'Không hoàn tất được lượt bán.');
                return;
            }
            const d = j.data || {};
            if (qrtd || mayMpos) {
                // Đơn đã ghi, CHỜ TIỀN: giữ hoá đơn trên màn để còn huỷ mã / lệnh hoặc đợi tiền về.
                h.qr = {
                    id: d.order_id, ma: d.order_code, code: d.payment?.transaction_code || d.order_code, anh: d.payment?.qr_image || '',
                    tong: d.total_amount ?? tong, kieu: mayMpos ? 'may' : 'qr', may: mayMpos ? theDangChon()?.may || '' : '',
                };
                luu(); capNhatTien(); hoiQrTiep();
                return;
            }
            hoanTatDon(h, d, inPhieu);
        } catch (e) {
            baoLoi('Mất kết nối — lượt bán CHƯA được ghi. Hoá đơn và số tiền vẫn giữ nguyên, kiểm tra mạng rồi bấm lại.');
        } finally {
            dangGui = false; nutBam.classList.remove('is-busy'); capNhatTien();
        }
    }
    async function doiHinhThuc(h, inPhieu, tong, dua) {
        baoLoi('');
        dangGui = true; capNhatTien();
        const nutBam = inPhieu ? $('posXacNhanIn') : $('posXacNhan');
        nutBam.classList.add('is-busy');
        const payload = { payment_method: hinhThuc };
        const chiTiet = hinhThuc === 'bank_transfer' ? (chonTk(), NH.id) : hinhThuc === 'card' ? theDangChon()?.id : 0;
        if (chiTiet) payload.payment_detail_id = chiTiet;
        if (hinhThuc === 'cash' && dua > 0) payload.amount_tendered = dua;
        try {
            const r = await fetch(D.doiHtUrl.replace('/0/switch-payment', `/${h.qr.id}/switch-payment`), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify(payload),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok) {
                if (j.errors?.ma === 'CHUA_MO_CA') { khoaCa(j.message); return; }
                baoLoi(j.message || 'Không đổi được hình thức thanh toán.');
                return;
            }
            hoanTatDon(h, j.data || {}, inPhieu);
        } catch (e) {
            baoLoi('Mất kết nối — CHƯA đổi được hình thức. Lượt chờ vẫn giữ nguyên, kiểm tra mạng rồi bấm lại.');
        } finally {
            dangGui = false; nutBam.classList.remove('is-busy'); capNhatTien();
        }
    }

    // Lượt bán xong (thu ngay, hoặc QR vừa báo tiền về): hoá đơn nhường chỗ cho hoá đơn trống,
    // hiện hộp "Đã thanh toán". h có thể KHÔNG phải hoá đơn đang mở — QR về khi thu ngân đã
    // chuyển sang bán cho khách khác thì không được kéo họ khỏi hoá đơn đang làm.
    function hoanTatDon(h, d, inPhieu) {
        const phieu = D.receiptUrl.replace('/0/receipt', `/${d.order_id}/receipt`);
        nhoDonVuaBan({ id: d.order_id, ma: d.order_code || '' });
        const dangMo = cur() === h;
        hd.ds = hd.ds.filter((x) => x !== h);
        if (dangMo || !hd.ds.length) { const moi = hdMoi(); hd.ds.push(moi); hd.dang = moi.id; }
        hoanTac = null;
        luu(); veTatCa();

        hop('posPayBox').hide();
        $('posDoneMa').textContent = d.order_code || '';
        $('posDoneTong').textContent = tien(d.total_amount ?? 0);
        $('posDoneThoi').textContent = tien(d.change_amount || 0);
        $('posDoneThoiDong').hidden = !(Number(d.change_amount) > 0);
        veTrangThaiIn(inPhieu ? 'dang' : null, d.order_code || '');
        veHoaDonXong(d.order_id, d.einvoice || null);
        hop('posDoneBox').show();
        // In bằng khung ẩn của khung quầy (posIn): không mở tab, không bị chặn cửa sổ bật lên.
        if (inPhieu) window.posIn(phieu).then((ok) => veTrangThaiIn(ok, d.order_code || ''));
    }

    // Đơn vừa bán nhớ qua cả lượt tải lại trang: F8 / nút "In lại phiếu" in lại đúng đơn đó.
    const KHOA_VUA_BAN = 'pos.don-vua-ban';
    function nhoDonVuaBan(x) { try { localStorage.setItem(KHOA_VUA_BAN, JSON.stringify(x)); } catch (e) { /* máy chặn lưu */ } }
    function inLaiVuaBan() {
        let x = null;
        try { x = JSON.parse(localStorage.getItem(KHOA_VUA_BAN) || 'null'); } catch (e) { /* máy chặn lưu */ }
        if (!x?.id) { window.posToast('Chưa có đơn nào vừa bán trên máy này để in lại.'); return; }
        window.posInLai({ id: x.id, ma: x.ma, loai: 'don' });
    }
    $('posDoneIn').addEventListener('click', inLaiVuaBan);
    $('posDoneBox').addEventListener('click', (e) => { if (e.target.closest('[data-in-lai-vua-ban]')) inLaiVuaBan(); });
    $('posDoneBox').addEventListener('keydown', (e) => { if (e.key === 'F8') { e.preventDefault(); inLaiVuaBan(); } });

    $('posXacNhan').addEventListener('click', () => chot(false));
    $('posXacNhanIn').addEventListener('click', () => chot(true));

    // Hoá đơn điện tử của lượt vừa bán: kết quả xuất ngay (nếu đã bật) và nút xuất / xuất lại.
    // Hỏng không phải lỗi của lượt bán — đơn đã ghi và đã thu tiền.
    let donVuaBan = 0;
    function veHoaDonXong(id, kq) {
        donVuaBan = Number(id) || 0;
        $('posDoneHddt').hidden = !donVuaBan;
        $('posDoneHddtCau').hidden = !kq;
        $('posDoneHddtCau').textContent = kq ? kq.message || '' : '';
        $('posDoneHddtCau').className = kq ? (kq.ok ? 'ok' : 'err') : '';
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
    $('posDoneBox').addEventListener('hop:dong', () => { if (!camUng()) $('posSearch').focus(); });
    $('posPayBox').addEventListener('hop:dong', () => { if (!coHopMo() && !camUng()) $('posSearch').focus(); });

    /* =====================================================================
     * MÀN HẸP · THANH ĐÁY · PHÍM TẮT · ĐỒNG HỒ
     * ===================================================================== */
    let khuDang = 'goods';
    function datKhu(k) {
        khuDang = k;
        document.querySelectorAll('[data-switch]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.switch === k)));
        $('paneGoods').hidden = hepMan() && k !== 'goods';
        $('paneBill').hidden = hepMan() && k !== 'bill';
        if (k === 'bill') veMuiTen();
    }
    document.querySelectorAll('[data-switch]').forEach((b) => b.addEventListener('click', () => datKhu(b.dataset.switch)));
    matchMedia('(max-width: 899px)').addEventListener('change', () => datKhu(khuDang));
    document.querySelectorAll('[data-thanh-toan]').forEach((b) => b.addEventListener('click', moPay));
    document.querySelectorAll('[data-tam-tinh]').forEach((b) => b.addEventListener('click', () => $('posTamTinh').click()));

    document.addEventListener('keydown', (e) => {
        if (coHopMo() || chuaMoCa) return;
        if (e.key === 'F2') { e.preventDefault(); themHd(); }
        else if (e.key === 'F1' || e.key === 'F3') { e.preventDefault(); datKhu('goods'); $('posSearch').focus(); $('posSearch').select(); }
        else if (e.key === 'F4') { e.preventDefault(); if (hinhThuc !== 'cash') chonHinhThuc('cash'); moPay(); }
        else if (e.key === 'F9') { e.preventDefault(); moPay(); }
        else if (e.key === 'F8') { e.preventDefault(); inLaiVuaBan(); }
        else if (e.key === 'z' && (e.ctrlKey || e.metaKey) && hoanTac && !e.target.closest('input, textarea')) { e.preventDefault(); hoanTac(); }
    });

    const gioDong = () => { $('posGio').textContent = new Date().toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }); };
    gioDong();
    setInterval(gioDong, 30000);

    /* ===================================================================== */
    function veTatCa() {
        veTab(); napForm(); veGio();
        chonHinhThuc(cur().hinhThuc || HT_MD, false);
        hoiQrTiep();
    }

    napHd();
    $('hide_thumbnail').checked = anhTat;
    $('select_col_number').value = String(soCot);
    veNhom();
    veTatCa();
    datKhu('goods');
    taiHang();
    if (D.caMo === '0') khoaCa();
})();
</script>
@endpush
