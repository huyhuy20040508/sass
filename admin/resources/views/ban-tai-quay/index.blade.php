@extends('layouts.app')

@section('title', \App\Http\Controllers\BanTaiQuayController::TITLE)

@section('content')
    {{--
        Màn hình thu ngân.

        DỰNG KHÁC HẲN các trang quản trị còn lại, và đó là chủ ý. Trang danh sách
        được thiết kế cho người NGỒI: lọc, đọc, so sánh, mở từng dòng ra xem. Quầy
        thì có người đứng đợi — nên ở đây không có bảng, không có phân trang,
        không có bộ lọc. Chỉ ba việc, mỗi việc một chỗ cố định trên màn hình:
        tìm hàng (trái), cộng tiền (phải trên), thu và thối (phải dưới).

        GIÁ KHÔNG DO TRANG NÀY QUYẾT. Payload gửi lên chỉ nói "biến thể nào, mấy
        cái"; giá, khuyến mãi và tồn kho do API tra lại tại thời điểm bấm nút.
        Con số trang hiển thị là `final_price` — cùng công thức tầng thanh toán
        dùng để thu tiền — nên nó khớp; nhưng khi lệch (khuyến mãi vừa hết hạn
        giữa lúc khách chọn hàng) thì SỐ CỦA API LÀ SỐ ĐÚNG, và màn hình in lại
        đúng số đó ở phiếu sau khi bán xong.

        TIỀN THỐI hiện ngay lúc gõ chứ không đợi bấm nút: đó là con số người bán
        phải đọc to lên, và đọc được trước khi nhận tiền thì không phải nhẩm lại
        lúc đang đếm.
    --}}
    @php
        $TITLE = \App\Http\Controllers\BanTaiQuayController::TITLE;
        $PAY_METHODS = \App\Http\Controllers\BanTaiQuayController::PAYMENT_METHODS;
        $MENH_GIA = \App\Http\Controllers\BanTaiQuayController::MENH_GIA;
    @endphp

    <div class="pos" id="pos"
         data-search-url="{{ route('admin.orders.searchProducts') }}"
         data-customer-url="{{ route('admin.orders.searchCustomers') }}"
         data-store-url="{{ route('admin.ban-tai-quay.store') }}"
         data-orders-url="{{ route('admin.orders.index') }}">

        {{-- ============ CỘT TRÁI: tìm và chọn hàng ============ --}}
        <section class="pos-pick">
            <div class="pos-searchbar">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" id="posSearch" class="pos-search" autocomplete="off" autofocus
                       placeholder="Tìm theo tên sản phẩm hoặc mã SKU — bấm Enter để thêm món đầu tiên">
                <kbd class="pos-kbd">F2</kbd>
            </div>

            <div class="pos-results" id="posResults">
                <p class="pos-hint">Gõ tên hàng để bắt đầu. Mỗi kích cỡ / màu là một ô riêng — bấm vào ô để thêm vào giỏ.</p>
            </div>
        </section>

        {{-- ============ CỘT PHẢI: giỏ hàng và thanh toán ============ --}}
        <aside class="pos-till">
            <header class="pos-till-head">
                <h1 class="pos-title">{{ $TITLE }}</h1>
                <button type="button" class="pos-clear" id="posClear" hidden>Huỷ giỏ</button>
            </header>

            <div class="pos-cart" id="posCart">
                <p class="pos-empty" id="posEmpty">Giỏ đang trống.</p>
            </div>

            <div class="pos-foot">
                {{-- Khách hàng: cả khối này KHÔNG bắt buộc. Mặc định đóng vì phần lớn
                     lượt bán ở quầy là khách lẻ, mở sẵn chỉ tổ chiếm chỗ của giỏ. --}}
                <details class="pos-cus">
                    <summary>Khách hàng &amp; mã giảm giá <span class="pos-cus-tag" id="posCusTag">khách lẻ</span></summary>
                    <div class="pos-cus-body">
                        <div class="pos-ac">
                            <input type="text" id="posCusName" class="pos-input" autocomplete="off"
                                   placeholder="Tên khách (không bắt buộc)">
                            <div class="pos-ac-menu" id="posCusMenu" hidden></div>
                        </div>
                        <input type="text" id="posCusPhone" class="pos-input" autocomplete="off"
                               placeholder="Số điện thoại (không bắt buộc)">
                        <input type="text" id="posVoucher" class="pos-input pos-upper" autocomplete="off"
                               placeholder="Mã giảm giá khách xuất trình">
                        <input type="text" id="posNote" class="pos-input" autocomplete="off"
                               placeholder="Ghi chú cho lượt bán này">
                        {{-- Ô ẩn giữ tài khoản khách đã chọn từ gợi ý. Rỗng = khách lẻ. --}}
                        <input type="hidden" id="posCusId" value="">
                    </div>
                </details>

                <div class="pos-sum">
                    <div class="pos-sum-row">
                        <span>Tạm tính <em id="posQty">0 món</em></span>
                        <b id="posSubtotal">0₫</b>
                    </div>
                    <div class="pos-sum-row pos-sum-total">
                        <span>Khách phải trả</span>
                        <b id="posTotal">0₫</b>
                    </div>
                    <p class="pos-sum-note">Mã giảm giá được trừ khi chốt đơn — số cuối do hệ thống tính.</p>
                </div>

                <div class="pos-pay">
                    <div class="pos-tabs" id="posPayTabs">
                        @foreach($PAY_METHODS as $value => $label)
                            <button type="button" class="pos-tab {{ $loop->first ? 'is-on' : '' }}"
                                    data-method="{{ $value }}">{{ $label }}</button>
                        @endforeach
                    </div>

                    {{-- Khối tiền mặt chỉ hiện khi thu tiền mặt: số tiền khách đưa không
                         có nghĩa gì với một lệnh chuyển khoản. --}}
                    <div class="pos-cash" id="posCash">
                        <div class="pos-tender">
                            <label for="posTendered">Khách đưa</label>
                            <input type="text" inputmode="numeric" id="posTendered" class="pos-input pos-money"
                                   autocomplete="off" placeholder="0">
                        </div>
                        <div class="pos-notes">
                            <button type="button" class="pos-note-btn" data-tender="exact">Đủ tiền</button>
                            @foreach($MENH_GIA as $m)
                                <button type="button" class="pos-note-btn" data-add="{{ $m }}">{{ number_format($m / 1000, 0, ',', '.') }}k</button>
                            @endforeach
                        </div>
                        <div class="pos-change" id="posChange" hidden>
                            <span>Thối lại</span>
                            <b id="posChangeVal">0₫</b>
                        </div>
                    </div>

                    <p class="pos-err" id="posErr" hidden></p>

                    <button type="button" class="pos-submit" id="posSubmit" disabled>
                        Thu tiền <span id="posSubmitAmt"></span>
                    </button>
                </div>
            </div>
        </aside>
    </div>

    {{-- ============ Phiếu sau khi bán xong ============ --}}
    <div class="pos-done" id="posDone" hidden>
        <div class="pos-done-card" role="dialog" aria-modal="true" aria-labelledby="posDoneTitle">
            <div class="pos-done-tick">
                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m4 12.5 5.5 5.5L20 7"/></svg>
            </div>
            <h2 id="posDoneTitle">Đã bán xong</h2>
            <p class="pos-done-code" id="posDoneCode"></p>

            <dl class="pos-done-sum" id="posDoneSum"></dl>

            {{-- Tiền thối in TO NHẤT của cả phiếu: đây là con số duy nhất còn phải
                 làm gì đó với nó sau khi đơn đã ghi xong. --}}
            <div class="pos-done-change" id="posDoneChange" hidden>
                <span>Thối lại cho khách</span>
                <b id="posDoneChangeVal"></b>
            </div>

            <div class="pos-done-acts">
                <button type="button" class="pos-submit" id="posDoneNext">Bán tiếp <kbd>Enter</kbd></button>
                <a href="#" class="pos-done-link" id="posDoneView" target="_blank" rel="noopener">Xem đơn</a>
            </div>
        </div>
    </div>

    <style>
        /* Bảng màu và bo góc bám theo các trang còn lại (Ant-ish): #1890ff nhấn,
           #262626 chữ chính, #8c8c8c chữ phụ, #f0f0f0 viền. */
        .pos {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 380px;
            gap: 16px;
            align-items: start;
            /* Trừ chiều cao topbar + padding của layout để hai cột tự cuộn bên trong
               thay vì kéo dài cả trang: ở quầy, thanh toán phải luôn nằm trong tầm
               mắt, không được trôi xuống dưới màn hình khi giỏ dài ra. */
            height: calc(100vh - 116px);
            min-height: 480px;
        }

        /* ---------- Cột trái ---------- */
        .pos-pick {
            display: flex;
            flex-direction: column;
            min-width: 0;
            height: 100%;
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            overflow: hidden;
        }

        .pos-searchbar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
            color: #8c8c8c;
        }

        .pos-search {
            flex: 1;
            min-width: 0;
            height: 34px;
            border: 0;
            outline: 0;
            font-size: 15px;
            color: #262626;
            background: transparent;
        }

        .pos-search::placeholder { color: #bfbfbf; }

        .pos-kbd, .pos-done-acts kbd {
            padding: 2px 6px;
            border: 1px solid #e8e8e8;
            border-bottom-width: 2px;
            border-radius: 4px;
            font: 500 11px/1.4 inherit;
            color: #8c8c8c;
            background: #fafafa;
        }

        .pos-results {
            flex: 1;
            overflow-y: auto;
            padding: 14px 16px;
        }

        .pos-hint, .pos-empty {
            margin: 0;
            padding: 24px 4px;
            color: #bfbfbf;
            font-size: 13px;
            text-align: center;
        }

        .pos-prod { margin-bottom: 18px; }

        .pos-prod-name {
            margin: 0 0 8px;
            font-size: 13px;
            font-weight: 600;
            color: #262626;
        }

        .pos-vars {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(158px, 1fr));
            gap: 8px;
        }

        /* Mỗi biến thể là MỘT ô bấm được, không phải một dòng có nút bấm ở cuối:
           ở quầy người ta bấm vội và bấm bằng ngón tay trên màn hình cảm ứng, nên
           vùng bấm phải là cả ô. */
        .pos-var {
            display: block;
            width: 100%;
            padding: 9px 11px;
            border: 1px solid #e8e8e8;
            border-radius: 6px;
            background: #fff;
            text-align: left;
            cursor: pointer;
            transition: border-color .12s, background .12s;
        }

        .pos-var:hover:not(:disabled) {
            border-color: #1890ff;
            background: #f0f8ff;
        }

        .pos-var:disabled { cursor: not-allowed; opacity: .5; }

        .pos-var-opt {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #262626;
        }

        .pos-var-meta {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-top: 3px;
            font-size: 12px;
            color: #8c8c8c;
        }

        .pos-var-price { font-weight: 600; color: #1890ff; }
        .pos-var-out { color: #ff4d4f; }

        /* ---------- Cột phải ---------- */
        .pos-till {
            display: flex;
            flex-direction: column;
            height: 100%;
            background: #fff;
            border: 1px solid #f0f0f0;
            border-radius: 8px;
            overflow: hidden;
        }

        .pos-till-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 13px 16px;
            border-bottom: 1px solid #f0f0f0;
        }

        .pos-title { margin: 0; font-size: 15px; font-weight: 600; color: #262626; }

        .pos-clear {
            border: 0;
            background: transparent;
            padding: 2px 4px;
            font-size: 12px;
            color: #8c8c8c;
            cursor: pointer;
        }

        .pos-clear:hover { color: #ff4d4f; }

        .pos-cart { flex: 1; overflow-y: auto; padding: 6px 0; }

        .pos-line {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 4px 10px;
            padding: 9px 16px;
            border-bottom: 1px solid #fafafa;
        }

        .pos-line-name {
            font-size: 13px;
            font-weight: 500;
            color: #262626;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .pos-line-sub { grid-column: 1; font-size: 12px; color: #8c8c8c; }
        .pos-line-total { grid-row: 1; font-size: 13px; font-weight: 600; color: #262626; text-align: right; }

        .pos-qty {
            grid-row: 2;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            justify-self: end;
        }

        .pos-qty button {
            width: 24px;
            height: 24px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            background: #fff;
            color: #595959;
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
        }

        .pos-qty button:hover { border-color: #1890ff; color: #1890ff; }
        .pos-qty span { min-width: 30px; text-align: center; font-size: 13px; font-weight: 600; color: #262626; }

        .pos-foot { border-top: 1px solid #f0f0f0; padding: 12px 16px 14px; }

        .pos-cus { margin-bottom: 10px; }

        .pos-cus > summary {
            cursor: pointer;
            font-size: 12px;
            color: #595959;
            list-style: none;
        }

        .pos-cus > summary::-webkit-details-marker { display: none; }
        .pos-cus > summary::before { content: '＋ '; color: #bfbfbf; }
        .pos-cus[open] > summary::before { content: '－ '; }

        .pos-cus-tag {
            margin-left: 4px;
            padding: 1px 6px;
            border-radius: 4px;
            background: #f5f5f5;
            color: #8c8c8c;
            font-size: 11px;
        }

        .pos-cus-body { display: grid; gap: 6px; margin-top: 8px; }

        .pos-input {
            width: 100%;
            height: 32px;
            padding: 0 10px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            font-size: 13px;
            color: #262626;
            outline: 0;
        }

        .pos-input:focus { border-color: #1890ff; }
        .pos-upper { text-transform: uppercase; }

        /* Gợi ý khách quen — cùng cách dựng với ô tìm khách ở trang tạo đơn. */
        .pos-ac { position: relative; }

        .pos-ac-menu {
            position: absolute;
            z-index: 20;
            left: 0;
            right: 0;
            top: 34px;
            max-height: 190px;
            overflow-y: auto;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .09);
        }

        .pos-ac-item {
            display: block;
            width: 100%;
            padding: 7px 10px;
            border: 0;
            background: transparent;
            text-align: left;
            font-size: 13px;
            color: #262626;
            cursor: pointer;
        }

        .pos-ac-item:hover { background: #f0f8ff; }
        .pos-ac-item em { display: block; font-style: normal; font-size: 12px; color: #8c8c8c; }
        .pos-ac-empty { padding: 8px 10px; font-size: 12px; color: #bfbfbf; }

        .pos-sum { padding: 10px 0 4px; border-top: 1px solid #f0f0f0; }

        .pos-sum-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            font-size: 13px;
            color: #595959;
        }

        .pos-sum-row em { font-style: normal; color: #bfbfbf; }
        .pos-sum-row + .pos-sum-row { margin-top: 4px; }

        .pos-sum-total { font-size: 15px; color: #262626; }
        .pos-sum-total b { font-size: 21px; font-weight: 700; color: #262626; }
        .pos-sum-note { margin: 6px 0 0; font-size: 11px; color: #bfbfbf; }

        .pos-pay { margin-top: 12px; }

        .pos-tabs { display: flex; gap: 6px; }

        .pos-tab {
            flex: 1;
            height: 32px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            background: #fff;
            font-size: 13px;
            color: #595959;
            cursor: pointer;
        }

        .pos-tab.is-on { border-color: #1890ff; color: #1890ff; background: #f0f8ff; font-weight: 500; }

        .pos-cash { margin-top: 10px; }

        .pos-tender { display: flex; align-items: center; gap: 8px; }
        .pos-tender label { font-size: 13px; color: #595959; white-space: nowrap; }

        .pos-money { text-align: right; font-size: 16px; font-weight: 600; height: 38px; }

        .pos-notes { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; }

        .pos-note-btn {
            flex: 1 0 auto;
            min-width: 46px;
            height: 27px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            background: #fafafa;
            font-size: 12px;
            color: #595959;
            cursor: pointer;
        }

        .pos-note-btn:hover { border-color: #1890ff; color: #1890ff; }

        /* [hidden] của trình duyệt là `display: none` trong UA stylesheet, mà bất kỳ
           `display` nào do trang khai đều đè lên nó. Khối nào vừa có `display` vừa
           được bật/tắt bằng thuộc tính hidden thì PHẢI tự khai lại — thiếu dòng này
           là khối hiện thường trực dù JS đã đặt hidden. */
        .pos-change[hidden], .pos-done[hidden] { display: none; }

        .pos-change {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            margin-top: 9px;
            padding: 7px 10px;
            border-radius: 4px;
            background: #f6ffed;
            font-size: 13px;
            color: #389e0d;
        }

        .pos-change b { font-size: 18px; font-weight: 700; }

        .pos-err {
            margin: 9px 0 0;
            padding: 7px 10px;
            border-radius: 4px;
            background: #fff1f0;
            color: #cf1322;
            font-size: 12px;
        }

        .pos-submit {
            width: 100%;
            height: 44px;
            margin-top: 11px;
            border: 0;
            border-radius: 6px;
            background: #1890ff;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        .pos-submit:hover:not(:disabled) { background: #40a9ff; }
        .pos-submit:disabled { background: #d9d9d9; cursor: not-allowed; }

        /* ---------- Phiếu sau khi bán ---------- */
        .pos-done {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(0, 0, 0, .45);
        }

        .pos-done-card {
            width: 100%;
            max-width: 340px;
            padding: 22px;
            border-radius: 10px;
            background: #fff;
            text-align: center;
        }

        .pos-done-tick {
            display: grid;
            place-items: center;
            width: 52px;
            height: 52px;
            margin: 0 auto 10px;
            border-radius: 50%;
            background: #f6ffed;
            color: #52c41a;
        }

        .pos-done-card h2 { margin: 0; font-size: 17px; font-weight: 600; color: #262626; }
        .pos-done-code { margin: 3px 0 14px; font-size: 13px; color: #8c8c8c; }

        .pos-done-sum { margin: 0 0 12px; font-size: 13px; }

        .pos-done-sum div {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 4px 0;
            color: #595959;
        }

        .pos-done-sum dd { margin: 0; font-weight: 600; color: #262626; }

        .pos-done-change {
            padding: 10px;
            border-radius: 6px;
            background: #f6ffed;
            color: #389e0d;
            font-size: 13px;
        }

        .pos-done-change b { display: block; margin-top: 2px; font-size: 26px; font-weight: 700; }

        .pos-done-acts { margin-top: 14px; }
        .pos-done-acts .pos-submit { margin-top: 0; }

        .pos-done-link {
            display: inline-block;
            margin-top: 9px;
            font-size: 12px;
            color: #1890ff;
            text-decoration: none;
        }

        .pos-done-link:hover { text-decoration: underline; }

        /* Màn hình hẹp: giỏ hàng xuống dưới, mỗi khối tự cao theo nội dung. */
        @media (max-width: 1100px) {
            .pos { grid-template-columns: 1fr; height: auto; }
            .pos-pick { min-height: 340px; }
        }
    </style>

    <script>
        (function () {
            const root = document.getElementById('pos');
            if (!root) return;

            const SEARCH_URL = root.dataset.searchUrl;
            const CUSTOMER_URL = root.dataset.customerUrl;
            const STORE_URL = root.dataset.storeUrl;
            const ORDERS_URL = root.dataset.ordersUrl;
            const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

            const $ = (id) => document.getElementById(id);
            const tien = (n) => new Intl.NumberFormat('vi-VN').format(Math.round(n || 0)) + '₫';
            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));

            /* Giỏ hàng: mảng { id, ten, opt, gia, ton, sl }.
             *
             * `gia` chỉ để HIỂN THỊ. Số thu tiền do API tính lại lúc chốt đơn, nên
             * mọi chỗ trong tệp này gọi nó là tạm tính chứ không phải tổng tiền. */
            let gio = [];

            /* ---------- Tìm và chọn hàng ---------- */

            // Giá bán THẬT của một biến thể, theo đúng thứ tự tầng thanh toán dùng:
            // final_price (đã gồm khuyến mãi) > giá riêng của biến thể > giá sale
            // hợp lệ > giá gốc. Sai thứ tự ở đây là báo cho khách một con số rồi thu
            // một con số khác.
            const giaCua = (p, v) => {
                if (v.final_price !== null && v.final_price !== undefined) return Number(v.final_price);
                if (v.price) return Number(v.price);
                if (p.sale_price > 0 && p.sale_price < p.base_price) return Number(p.sale_price);
                return Number(p.base_price || 0);
            };

            const nhan = (v) => [v.size, v.color].filter(Boolean).join(' / ') || (v.sku || 'Mặc định');

            const veKetQua = (list) => {
                const box = $('posResults');
                if (!list.length) {
                    box.innerHTML = '<p class="pos-hint">Không tìm thấy sản phẩm nào.</p>';
                    return;
                }
                box.innerHTML = list.map((p) => {
                    const vars = (p.variants || []).map((v) => {
                        const gia = giaCua(p, v);
                        const ton = Number(v.stock || 0);
                        // Hết hàng thì tắt ô luôn: API cũng sẽ từ chối, nói trước ở đây
                        // đỡ hơn để người bán bấm rồi mới nhận lỗi giữa lúc khách đợi.
                        return `<button type="button" class="pos-var" ${ton <= 0 ? 'disabled' : ''}
                                    data-id="${v.id}" data-ten="${esc(p.name)}" data-opt="${esc(nhan(v))}"
                                    data-gia="${gia}" data-ton="${ton}">
                                <span class="pos-var-opt">${esc(nhan(v))}</span>
                                <span class="pos-var-meta">
                                    <span class="pos-var-price">${tien(gia)}</span>
                                    <span class="${ton <= 0 ? 'pos-var-out' : ''}">${ton <= 0 ? 'Hết hàng' : 'Còn ' + ton}</span>
                                </span>
                            </button>`;
                    }).join('');

                    return `<div class="pos-prod">
                            <p class="pos-prod-name">${esc(p.name)}</p>
                            <div class="pos-vars">${vars || '<p class="pos-hint">Sản phẩm chưa có phiên bản nào.</p>'}</div>
                        </div>`;
                }).join('');
            };

            let timer = null;
            const timHang = (q) => {
                clearTimeout(timer);
                if (!q.trim()) {
                    $('posResults').innerHTML = '<p class="pos-hint">Gõ tên hàng để bắt đầu.</p>';
                    return;
                }
                timer = setTimeout(() => {
                    fetch(`${SEARCH_URL}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                        .then((r) => r.json())
                        .then((d) => veKetQua(d.data || []))
                        .catch(() => {
                            $('posResults').innerHTML = '<p class="pos-hint">Không tải được danh sách. Kiểm tra kết nối.</p>';
                        });
                }, 250);
            };

            $('posSearch').addEventListener('input', (e) => timHang(e.target.value));

            // Enter thêm luôn món đầu tiên còn hàng: ở quầy, tay phải đang cầm hàng
            // nên tay trái chỉ gõ được vài phím, không rê chuột chọn ô.
            $('posSearch').addEventListener('keydown', (e) => {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                const dau = $('posResults').querySelector('.pos-var:not(:disabled)');
                if (dau) dau.click();
            });

            $('posResults').addEventListener('click', (e) => {
                const btn = e.target.closest('.pos-var');
                if (!btn || btn.disabled) return;
                them({
                    id: Number(btn.dataset.id),
                    ten: btn.dataset.ten,
                    opt: btn.dataset.opt,
                    gia: Number(btn.dataset.gia),
                    ton: Number(btn.dataset.ton),
                });
            });

            /* ---------- Giỏ hàng ---------- */

            function them(mon) {
                const co = gio.find((d) => d.id === mon.id);
                // Không cho vượt tồn ngay tại đây. API vẫn chặn lần nữa (nó mới là
                // nơi đọc số dưới khoá), nhưng chặn sớm thì người bán biết trước khi
                // đọc giá cho khách nghe.
                if (co) {
                    if (co.sl >= co.ton) { baoLoi(`Chỉ còn ${co.ton} sản phẩm "${co.opt}" trong kho.`); return; }
                    co.sl++;
                } else {
                    gio.push({ ...mon, sl: 1 });
                }
                baoLoi('');
                veGio();
            }

            function doiSL(id, delta) {
                const d = gio.find((x) => x.id === id);
                if (!d) return;
                if (delta > 0 && d.sl >= d.ton) { baoLoi(`Chỉ còn ${d.ton} sản phẩm "${d.opt}" trong kho.`); return; }
                d.sl += delta;
                if (d.sl <= 0) gio = gio.filter((x) => x.id !== id);
                baoLoi('');
                veGio();
            }

            const tamTinh = () => gio.reduce((s, d) => s + d.gia * d.sl, 0);
            const soMon = () => gio.reduce((s, d) => s + d.sl, 0);

            function veGio() {
                const box = $('posCart');
                if (!gio.length) {
                    box.innerHTML = '<p class="pos-empty">Giỏ đang trống.</p>';
                } else {
                    box.innerHTML = gio.map((d) => `
                        <div class="pos-line">
                            <span class="pos-line-name" title="${esc(d.ten)}">${esc(d.ten)}</span>
                            <span class="pos-line-total">${tien(d.gia * d.sl)}</span>
                            <span class="pos-line-sub">${esc(d.opt)} · ${tien(d.gia)}</span>
                            <span class="pos-qty">
                                <button type="button" data-sl="-1" data-id="${d.id}" aria-label="Bớt một">−</button>
                                <span>${d.sl}</span>
                                <button type="button" data-sl="1" data-id="${d.id}" aria-label="Thêm một">+</button>
                            </span>
                        </div>`).join('');
                }

                const tong = tamTinh();
                $('posQty').textContent = soMon() + ' món';
                $('posSubtotal').textContent = tien(tong);
                $('posTotal').textContent = tien(tong);
                $('posClear').hidden = gio.length === 0;
                capNhatThoi();
            }

            $('posCart').addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-sl]');
                if (btn) doiSL(Number(btn.dataset.id), Number(btn.dataset.sl));
            });

            $('posClear').addEventListener('click', () => {
                if (gio.length && !confirm('Bỏ toàn bộ giỏ hàng?')) return;
                donDep();
            });

            /* ---------- Thanh toán ---------- */

            // Đọc từ tab đang bật thay vì viết cứng 'cash': danh sách hình thức do
            // PHP dựng, viết cứng ở đây là hai chỗ cùng giữ một sự thật và sẽ lệch
            // nhau ngay lần đầu có người đổi thứ tự trong hằng số.
            let hinhThuc = $('posPayTabs').querySelector('.pos-tab.is-on')?.dataset.method || 'cash';

            $('posPayTabs').addEventListener('click', (e) => {
                const tab = e.target.closest('.pos-tab');
                if (!tab) return;
                hinhThuc = tab.dataset.method;
                $('posPayTabs').querySelectorAll('.pos-tab').forEach((t) => t.classList.toggle('is-on', t === tab));
                $('posCash').hidden = hinhThuc !== 'cash';
                capNhatThoi();
            });

            const soTien = (s) => Number(String(s || '').replace(/\D/g, '')) || 0;

            // Ô tiền khách đưa tự chấm phân cách hàng nghìn ngay lúc gõ: "500000" và
            // "5000000" nhìn gần như nhau, mà lệch một số 0 là thối sai mười lần.
            $('posTendered').addEventListener('input', (e) => {
                const n = soTien(e.target.value);
                e.target.value = n ? new Intl.NumberFormat('vi-VN').format(n) : '';
                capNhatThoi();
            });

            $('posCash').addEventListener('click', (e) => {
                const btn = e.target.closest('.pos-note-btn');
                if (!btn) return;
                const o = $('posTendered');
                o.value = btn.dataset.tender === 'exact'
                    ? new Intl.NumberFormat('vi-VN').format(tamTinh())
                    : new Intl.NumberFormat('vi-VN').format(soTien(o.value) + Number(btn.dataset.add));
                capNhatThoi();
                o.focus();
            });

            function capNhatThoi() {
                const tong = tamTinh();
                const dua = soTien($('posTendered').value);
                const thoi = dua - tong;
                const hienThoi = hinhThuc === 'cash' && dua > 0;

                $('posChange').hidden = !hienThoi;
                if (hienThoi) {
                    $('posChangeVal').textContent = thoi >= 0 ? tien(thoi) : 'thiếu ' + tien(-thoi);
                    $('posChange').style.background = thoi >= 0 ? '#f6ffed' : '#fff1f0';
                    $('posChange').style.color = thoi >= 0 ? '#389e0d' : '#cf1322';
                }

                // Nút chốt đơn tắt khi giỏ trống hoặc tiền mặt đưa vào chưa đủ. API
                // vẫn kiểm lại cả hai — đây chỉ là nói trước cho đỡ mất một vòng gọi.
                const thieu = hinhThuc === 'cash' && dua > 0 && thoi < 0;
                $('posSubmit').disabled = !gio.length || thieu;
                $('posSubmitAmt').textContent = gio.length ? tien(tong) : '';
            }

            const baoLoi = (msg) => {
                const p = $('posErr');
                p.textContent = msg || '';
                p.hidden = !msg;
            };

            /* ---------- Gợi ý khách quen ---------- */

            let cusTimer = null;
            $('posCusName').addEventListener('input', (e) => {
                // Gõ tay = không còn là khách đã chọn nữa: giữ lại user_id cũ thì đơn
                // gắn nhầm vào tài khoản người khác.
                $('posCusId').value = '';
                $('posCusTag').textContent = 'khách lẻ';

                const q = e.target.value.trim();
                clearTimeout(cusTimer);
                if (q.length < 2) { $('posCusMenu').hidden = true; return; }

                cusTimer = setTimeout(() => {
                    fetch(`${CUSTOMER_URL}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                        .then((r) => r.json())
                        .then((d) => {
                            const list = d.data || [];
                            const menu = $('posCusMenu');
                            menu.innerHTML = list.length
                                ? list.map((c) => `<button type="button" class="pos-ac-item" data-id="${c.id}"
                                        data-ten="${esc(c.name)}" data-sdt="${esc(c.phone)}">
                                        ${esc(c.name || '(chưa có tên)')}<em>${esc(c.phone || '')}</em></button>`).join('')
                                : '<p class="pos-ac-empty">Không có khách nào khớp — cứ để trống là bán cho khách lẻ.</p>';
                            menu.hidden = false;
                        })
                        .catch(() => { $('posCusMenu').hidden = true; });
                }, 250);
            });

            $('posCusMenu').addEventListener('click', (e) => {
                const it = e.target.closest('.pos-ac-item');
                if (!it) return;
                $('posCusId').value = it.dataset.id;
                $('posCusName').value = it.dataset.ten;
                $('posCusPhone').value = it.dataset.sdt;
                $('posCusTag').textContent = it.dataset.ten || 'khách quen';
                $('posCusMenu').hidden = true;
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('.pos-ac')) $('posCusMenu').hidden = true;
            });

            /* ---------- Chốt đơn ---------- */

            let dangGui = false;

            $('posSubmit').addEventListener('click', async () => {
                if (dangGui || !gio.length) return;
                dangGui = true;
                $('posSubmit').disabled = true;
                baoLoi('');

                const dua = soTien($('posTendered').value);
                const body = {
                    payment_method: hinhThuc,
                    customer_name: $('posCusName').value.trim(),
                    customer_phone: $('posCusPhone').value.trim(),
                    voucher_code: $('posVoucher').value.trim(),
                    note: $('posNote').value.trim(),
                    items: gio.map((d) => ({ product_variant_id: d.id, quantity: d.sl })),
                };
                if ($('posCusId').value) body.user_id = Number($('posCusId').value);
                if (hinhThuc === 'cash' && dua > 0) body.amount_tendered = dua;

                try {
                    const res = await fetch(STORE_URL, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': CSRF,
                        },
                        body: JSON.stringify(body),
                    });
                    const d = await res.json().catch(() => ({}));

                    if (!res.ok) {
                        // Câu của API đã nói rõ việc cần làm (tên món hết hàng, số tiền
                        // còn thiếu) — in nguyên văn, đừng thay bằng câu chung chung.
                        baoLoi(d.message || 'Không hoàn tất được lượt bán.');
                        return;
                    }
                    xongPhieu(d.data || {});
                } catch (err) {
                    baoLoi('Mất kết nối. Đơn CHƯA được ghi — vui lòng thử lại.');
                } finally {
                    dangGui = false;
                    capNhatThoi();
                }
            });

            /* ---------- Phiếu sau khi bán ---------- */

            function xongPhieu(d) {
                $('posDoneCode').textContent = d.order_code || '';

                // In lại số của API chứ không phải số màn hình vừa tính: khuyến mãi
                // có thể vừa hết hạn, hoặc mã giảm giá vừa được trừ thêm. Đây là con
                // số đã thu thật.
                const dong = [['Tiền hàng', tien(d.subtotal_amount)]];
                if (Number(d.discount_amount) > 0) {
                    dong.push([`Giảm giá${d.voucher_code ? ' (' + d.voucher_code + ')' : ''}`, '−' + tien(d.discount_amount)]);
                }
                dong.push(['Khách phải trả', tien(d.total_amount)]);
                if (d.amount_tendered !== null && d.amount_tendered !== undefined) {
                    dong.push(['Khách đưa', tien(d.amount_tendered)]);
                }
                $('posDoneSum').innerHTML = dong.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('');

                const coThoi = d.change_amount !== null && d.change_amount !== undefined;
                $('posDoneChange').hidden = !coThoi;
                if (coThoi) $('posDoneChangeVal').textContent = tien(d.change_amount);

                $('posDoneView').href = `${ORDERS_URL}?keyword=${encodeURIComponent(d.order_code || '')}`;
                $('posDone').hidden = false;
                $('posDoneNext').focus();
            }

            // Khối tiền mặt bám theo hình thức đang chọn ngay từ lượt tải trang, chứ
            // không chỉ khi có người bấm đổi tab.
            $('posCash').hidden = hinhThuc !== 'cash';

            const donDep = () => {
                gio = [];
                ['posTendered', 'posCusName', 'posCusPhone', 'posVoucher', 'posNote', 'posCusId'].forEach((id) => {
                    $(id).value = '';
                });
                $('posCusTag').textContent = 'khách lẻ';
                baoLoi('');
                veGio();
            };

            $('posDoneNext').addEventListener('click', () => {
                $('posDone').hidden = true;
                donDep();
                $('posSearch').value = '';
                $('posResults').innerHTML = '<p class="pos-hint">Gõ tên hàng để bắt đầu.</p>';
                $('posSearch').focus();
            });

            /* ---------- Phím tắt ---------- */

            document.addEventListener('keydown', (e) => {
                // Phiếu đang mở: Enter/Esc đều nghĩa là "xong rồi, khách tiếp theo".
                if (!$('posDone').hidden) {
                    if (e.key === 'Enter' || e.key === 'Escape') {
                        e.preventDefault();
                        $('posDoneNext').click();
                    }
                    return;
                }
                if (e.key === 'F2') {
                    e.preventDefault();
                    $('posSearch').select();
                }
            });

            veGio();
        })();
    </script>
@endsection
