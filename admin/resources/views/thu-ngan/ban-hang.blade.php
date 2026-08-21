@extends('layouts.thu-ngan')

@section('title', \App\Http\Controllers\BanTaiQuayController::TITLE)

{{-- Thân KÍN: cả trang không cuộn, hai cột bên trong tự cuộn phần của mình. --}}
@section('than', 'tn-kin')

{{-- Đơn treo nằm trên thanh trên cùng, không nằm trong cột giỏ hàng: nó không
     thuộc về lượt bán đang làm dở, và để trong cột phải thì mỗi lần giỏ dài ra
     là nó bị đẩy khỏi tầm mắt. --}}
@section('posbar')
    {{-- Ca làm việc đứng cạnh Đơn treo: cả hai là trạng thái của CHÍNH cái quầy
         này, không thuộc về lượt bán đang làm dở. --}}
    <button type="button" class="posbar-btn" id="posCaMo">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M2 10h20"/><circle cx="12" cy="14" r="1.5"/></svg>
        <span id="posCaNhan">Ca làm việc</span>
    </button>

    <button type="button" class="posbar-btn" id="posTreoMo">
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14a1 1 0 0 1 1 1v16l-8-4-8 4V4a1 1 0 0 1 1-1z"/></svg>
        Đơn treo <b id="posTreoSo" hidden>0</b>
    </button>
@endsection

@section('content')
    {{--
        Màn hình thu ngân — giai đoạn 2.

        Bốn thứ giai đoạn 1 chưa có, và lý do từng thứ:

        QUÉT MÃ. Gõ tên hàng là chỗ chậm nhất của giai đoạn 1, và cũng là chỗ dễ
        chọn nhầm nhất — hai chiếc áo cùng tên khác size trông giống hệt nhau
        trên danh sách gợi ý. Enter trong ô tìm kiếm sẽ THỬ QUÉT trước: máy quét
        chỉ là một bàn phím gõ rất nhanh rồi bấm Enter, nên không cần thiết bị
        hay thư viện nào để đọc nó.

        BỚT GIÁ TỪNG MÓN. Ở quầy, việc bớt tiền gần như luôn gắn với một món cụ
        thể. Ô nhập bị kẹp theo hạn quyền của chính người đang đăng nhập; server
        kiểm lại lần nữa khi chốt đơn, nên ô này chỉ là phép lịch sự chứ không
        phải hàng rào.

        ĐƠN TREO. Khách A quay lại lấy thêm món trong khi khách B đã đứng chờ —
        đây là tình huống xảy ra hằng ngày, và không có chỗ treo thì người bán
        phải nhớ giỏ hàng trong đầu. Giỏ treo nằm ở localStorage của CHÍNH MÁY
        NÀY: nó thuộc về người đứng ở quầy này, không phải thứ cần chia cho máy
        khác. Đổi lại, xoá dữ liệu trình duyệt là mất — nên đây là chỗ để tạm
        vài phút, không phải chỗ giữ đơn qua đêm.

        IN PHIẾU. Mở ở tab riêng với bộ CSS khổ giấy nhiệt (xem phieu.blade.php).
    --}}
    @php
        $PAY_METHODS = \App\Http\Controllers\BanTaiQuayController::PAYMENT_METHODS;
        $MENH_GIA = \App\Http\Controllers\BanTaiQuayController::MENH_GIA;
    @endphp

    <div class="tnk" id="pos"
         data-search-url="{{ route('admin.orders.searchProducts') }}"
         data-customer-url="{{ route('admin.orders.searchCustomers') }}"
         data-scan-url="{{ route('thu-ngan.ban-hang.scan') }}"
         data-store-url="{{ route('thu-ngan.ban-hang.store') }}"
         data-receipt-url="{{ route('thu-ngan.ban-hang.phieu', ['id' => 0]) }}"
         data-orders-url="{{ route('thu-ngan.don-hang.index') }}"
         data-ca-url="{{ route('thu-ngan.ca-lam-viec.hienTai') }}"
         data-ca-mo-url="{{ route('thu-ngan.ca-lam-viec.mo') }}"
         data-ca-dong-url="{{ route('thu-ngan.ca-lam-viec.dong') }}"
         data-so-quy-url="{{ route('thu-ngan.ca-lam-viec.soQuy') }}"
         data-discount-limit="{{ $hanMucGiam }}">

        {{-- ============ KHU TRÁI: tìm / quét hàng ============ --}}
        <section class="tnk-khu tnk-khu--chinh">
            <div class="tnk-dau">
                <span class="tnk-tab">Hàng hoá</span>
            </div>

            <div class="tnk-than pos-pick">
                <div class="pos-searchbar">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 8v8M10 8v8M13 8v8M17 8v8"/></svg>
                    <input type="search" id="posSearch" class="pos-search" autocomplete="off" autofocus
                           placeholder="Quét mã vạch, hoặc gõ tên hàng rồi bấm Enter">
                    <kbd class="pos-kbd">F2</kbd>
                </div>

                <div class="pos-results" id="posResults">
                    <p class="pos-hint">Quét mã vạch để thêm hàng ngay, hoặc gõ tên để chọn từ danh sách.</p>
                </div>
            </div>
        </section>

        {{-- ============ KHU PHẢI: giỏ hàng và thanh toán ============ --}}
        <aside class="tnk-khu tnk-khu--phu">
            {{-- Treo đơn / Huỷ giỏ nằm trên hàng thẻ, ngoài tấm trắng: giỏ dài
                 ra thì hai nút này vẫn đứng nguyên chỗ. --}}
            <div class="tnk-dau">
                <span class="tnk-tab">Hoá đơn</span>
                <div class="pos-till-acts">
                    <button type="button" class="pos-mini" id="posTreoLuu" hidden>Treo đơn</button>
                    <button type="button" class="pos-mini is-stop" id="posClear" hidden>Huỷ giỏ</button>
                </div>
            </div>

            <div class="tnk-than">
                <div class="pos-cart" id="posCart">
                    <p class="pos-empty" id="posEmpty">Giỏ đang trống.</p>
                </div>

                <div class="pos-foot">
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
                            <input type="hidden" id="posCusId" value="">
                        </div>
                    </details>

                    <div class="pos-sum">
                        <div class="pos-sum-row">
                            <span>Tiền hàng <em id="posQty">0 món</em></span>
                            <b id="posGross">0₫</b>
                        </div>
                        <div class="pos-sum-row pos-sum-cut" id="posCutRow" hidden>
                            <span>Bớt theo món</span>
                            <b id="posCut">0₫</b>
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
            </div>
        </aside>
    </div>

    {{-- ============ Ngăn kéo đơn treo ============ --}}
    <div class="pos-drawer" id="posTreoBox" hidden>
        <div class="pos-drawer-card" role="dialog" aria-modal="true" aria-labelledby="posTreoTitle">
            <header class="pos-drawer-head">
                <h2 id="posTreoTitle">Đơn đang treo</h2>
                <button type="button" class="pos-mini" id="posTreoDong">Đóng</button>
            </header>
            <div class="pos-drawer-body" id="posTreoDS"></div>
            <p class="pos-drawer-note">
                Đơn treo nằm trên chính máy này. Xoá dữ liệu trình duyệt hoặc mở ở máy khác thì không thấy —
                đây là chỗ để tạm vài phút, không phải chỗ giữ đơn qua đêm.
            </p>
        </div>
    </div>

    {{-- ============ Ca làm việc & sổ quỹ ============ --}}
    <div class="pos-drawer" id="posCaBox" hidden>
        <div class="pos-drawer-card" role="dialog" aria-modal="true" aria-labelledby="posCaTitle">
            <header class="pos-drawer-head">
                <h2 id="posCaTitle">Ca làm việc</h2>
                <button type="button" class="pos-mini" id="posCaDong">Đóng</button>
            </header>

            <div class="pos-drawer-body" id="posCaND"></div>

            <p class="pos-drawer-note">
                Sổ quỹ chỉ ghi TIỀN MẶT. Chuyển khoản không đi qua két nên không nằm ở đây —
                gộp vào là con số cuối ca không còn khớp tiền đếm được.
            </p>
        </div>
    </div>

    {{-- ============ Phiếu sau khi bán xong ============ --}}
    <div class="pos-drawer" id="posDone" hidden>
        <div class="pos-done-card" role="dialog" aria-modal="true" aria-labelledby="posDoneTitle">
            <div class="pos-done-tick">
                <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m4 12.5 5.5 5.5L20 7"/></svg>
            </div>
            <h2 id="posDoneTitle">Đã bán xong</h2>
            <p class="pos-done-code" id="posDoneCode"></p>

            <dl class="pos-done-sum" id="posDoneSum"></dl>

            <div class="pos-done-change" id="posDoneChange" hidden>
                <span>Thối lại cho khách</span>
                <b id="posDoneChangeVal"></b>
            </div>

            <div class="pos-done-acts">
                <button type="button" class="pos-submit" id="posDoneNext">Bán tiếp <kbd>Enter</kbd></button>
                <div class="pos-done-links">
                    <a href="#" id="posDonePrint" target="_blank" rel="noopener">In phiếu</a>
                    <a href="#" id="posDoneView" target="_blank" rel="noopener">Xem đơn</a>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Hai khu của trang dùng khuôn .tnk-* của layout (xem layouts/thu-ngan).
           Dưới đây chỉ còn ruột của từng khu. */

        /* ---------- Khu trái: tìm / quét hàng ---------- */
        .pos-searchbar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            color: #8c8c8c;
        }

        .pos-search {
            flex: 1;
            min-width: 0;
            height: 36px;
            border: 0;
            outline: 0;
            font-family: inherit;
            font-size: 16px;
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

        .pos-results { flex: 1; overflow-y: auto; padding: 14px 16px; }

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
            grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
            gap: 8px;
        }

        .pos-var {
            display: block;
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e8e8e8;
            border-radius: 6px;
            background: #fff;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
            transition: border-color .12s, background .12s;
        }

        .pos-var:hover:not(:disabled) { border-color: #1890ff; background: #f0f8ff; }
        .pos-var:disabled { cursor: not-allowed; opacity: .5; }

        .pos-var-opt { display: block; font-size: 13px; font-weight: 500; color: #262626; }

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

        /* ---------- Khu phải: giỏ hàng và thanh toán ---------- */
        /* Hai nút của khu nằm trên hàng thẻ, dồn về mép phải. */
        .pos-till-acts { display: flex; gap: 6px; margin-left: auto; }

        .pos-mini {
            height: 28px;
            padding: 0 10px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            background: #fff;
            font-family: inherit;
            font-size: 12px;
            color: #595959;
            cursor: pointer;
        }

        .pos-mini:hover { border-color: #1890ff; color: #1890ff; }
        .pos-mini.is-stop:hover { border-color: #ff4d4f; color: #ff4d4f; }

        .pos-cart { flex: 1; overflow-y: auto; padding: 6px 0; }

        .pos-line {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 3px 10px;
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

        .pos-line-total { grid-row: 1; font-size: 13px; font-weight: 600; color: #262626; text-align: right; }
        .pos-line-sub { grid-column: 1; font-size: 12px; color: #8c8c8c; }

        /* Giá gốc gạch ngang khi dòng có bớt giá — người bán liếc qua là biết dòng
           nào đã được bớt, không phải mở từng ô ra xem. */
        .pos-line-was { text-decoration: line-through; color: #bfbfbf; }

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
            font-family: inherit;
            color: #595959;
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
        }

        .pos-qty button:hover { border-color: #1890ff; color: #1890ff; }
        .pos-qty span { min-width: 30px; text-align: center; font-size: 13px; font-weight: 600; }

        /* Ô bớt giá: nhỏ, nằm ở hàng thứ ba của dòng hàng. Cố ý KHÔNG nổi bật —
           đây là ngoại lệ, không phải thao tác thường ngày. */
        .pos-line-cut {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            font-size: 12px;
            color: #8c8c8c;
        }

        .pos-line-cut input {
            width: 54px;
            height: 26px;
            padding: 0 6px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            font-family: inherit;
            font-size: 12px;
            text-align: right;
            color: #262626;
            outline: 0;
        }

        .pos-line-cut input:focus { border-color: #1890ff; }
        .pos-line-cut input.is-err { border-color: #ff4d4f; background: #fff1f0; }
        .pos-line-cut b { color: #cf1322; font-weight: 600; }

        .pos-foot { border-top: 1px solid #f0f0f0; padding: 12px 16px 14px; }

        .pos-cus { margin-bottom: 10px; }

        .pos-cus > summary { cursor: pointer; font-size: 12px; color: #595959; list-style: none; }
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
            font-family: inherit;
            font-size: 13px;
            color: #262626;
            outline: 0;
        }

        .pos-input:focus { border-color: #1890ff; }
        .pos-upper { text-transform: uppercase; }

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
            font-family: inherit;
            text-align: left;
            font-size: 13px;
            color: #262626;
            cursor: pointer;
        }

        .pos-ac-item:hover { background: #f0f8ff; }
        .pos-ac-item em { display: block; font-style: normal; font-size: 12px; color: #8c8c8c; }
        .pos-ac-empty { padding: 8px 10px; margin: 0; font-size: 12px; color: #bfbfbf; }

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
        .pos-sum-cut b { color: #cf1322; }
        .pos-sum-total { font-size: 15px; color: #262626; }
        .pos-sum-total b { font-size: 22px; font-weight: 700; color: #262626; }
        .pos-sum-note { margin: 6px 0 0; font-size: 11px; color: #bfbfbf; }

        .pos-pay { margin-top: 12px; }
        .pos-tabs { display: flex; gap: 6px; }

        .pos-tab {
            flex: 1;
            height: 32px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            background: #fff;
            font-family: inherit;
            font-size: 13px;
            color: #595959;
            cursor: pointer;
        }

        .pos-tab.is-on { border-color: #1890ff; color: #1890ff; background: #f0f8ff; font-weight: 500; }

        .pos-cash { margin-top: 10px; }
        .pos-tender { display: flex; align-items: center; gap: 8px; }
        .pos-tender label { font-size: 13px; color: #595959; white-space: nowrap; }
        .pos-money { text-align: right; font-size: 17px; font-weight: 600; height: 40px; }
        .pos-notes { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 7px; }

        .pos-note-btn {
            flex: 1 0 auto;
            min-width: 46px;
            height: 28px;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            background: #fafafa;
            font-family: inherit;
            font-size: 12px;
            color: #595959;
            cursor: pointer;
        }

        .pos-note-btn:hover { border-color: #1890ff; color: #1890ff; }

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

        .pos-change b { font-size: 19px; font-weight: 700; }

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
            height: 46px;
            margin-top: 11px;
            border: 0;
            border-radius: 6px;
            background: #1890ff;
            color: #fff;
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        .pos-submit:hover:not(:disabled) { background: #40a9ff; }
        .pos-submit:disabled { background: #d9d9d9; cursor: not-allowed; }

        /* ---------- Lớp phủ (đơn treo + phiếu bán xong) ---------- */
        /* [hidden] của trình duyệt là display:none trong UA stylesheet, mà bất kỳ
           display nào do trang khai đều đè lên nó. Khối vừa có display vừa bật/tắt
           bằng hidden thì PHẢI tự khai lại — thiếu dòng này là nó hiện thường trực. */
        .pos-drawer[hidden], .pos-change[hidden], .pos-sum-cut[hidden] { display: none; }

        .pos-drawer {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(0, 0, 0, .45);
        }

        .pos-drawer-card, .pos-done-card {
            width: 100%;
            border-radius: 10px;
            background: #fff;
        }

        .pos-drawer-card { max-width: 460px; max-height: 80vh; display: flex; flex-direction: column; }

        .pos-drawer-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
        }

        .pos-drawer-head h2 { margin: 0; font-size: 15px; font-weight: 600; }
        .pos-drawer-body { flex: 1; overflow-y: auto; padding: 6px 0; }
        .pos-drawer-note { margin: 0; padding: 10px 16px 14px; font-size: 11px; color: #bfbfbf; border-top: 1px solid #f0f0f0; }

        .pos-treo-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-bottom: 1px solid #fafafa;
        }

        .pos-treo-info { flex: 1; min-width: 0; }
        .pos-treo-ten { font-size: 13px; font-weight: 500; color: #262626; }
        .pos-treo-phu { font-size: 12px; color: #8c8c8c; }

        /* ---------- Ngăn kéo ca làm việc ---------- */
        .pos-ca-form { display: grid; gap: 8px; padding: 14px 16px; }
        .pos-ca-form .pos-done-sum { margin: 0; }
        .pos-ca-lb { font-size: 12px; color: #595959; }
        .pos-ca-hint { margin: 0; font-size: 12px; color: #8c8c8c; }
        .pos-ca-line { display: grid; grid-template-columns: 90px 1fr; gap: 8px; }
        .pos-ca-line .pos-money { height: 32px; font-size: 14px; }
        .pos-ca-hr { width: 100%; margin: 4px 0; border: 0; border-top: 1px solid #f0f0f0; }

        /* ---------- Phiếu sau khi bán ---------- */
        .pos-done-card { max-width: 350px; padding: 22px; text-align: center; }

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

        .pos-done-change[hidden] { display: none; }
        .pos-done-change b { display: block; margin-top: 2px; font-size: 26px; font-weight: 700; }

        .pos-done-acts { margin-top: 14px; }
        .pos-done-acts .pos-submit { margin-top: 0; }
        .pos-done-links { margin-top: 9px; display: flex; justify-content: center; gap: 14px; }
        .pos-done-links a { font-size: 12px; color: #1890ff; text-decoration: none; }
        .pos-done-links a:hover { text-decoration: underline; }

        /* Xếp dọc (khuôn .tnk lo phần chia cột) thì khu chọn hàng phải có chiều
           cao tối thiểu, không thì nó co lại còn đúng ô tìm kiếm. */
        @media (max-width: 1100px) {
            html, body { overflow: auto; }
            .pos-pick { min-height: 340px; }
        }
    </style>

    <script>
        (function () {
            const root = document.getElementById('pos');
            if (!root) return;

            const SEARCH_URL = root.dataset.searchUrl;
            const CUSTOMER_URL = root.dataset.customerUrl;
            const SCAN_URL = root.dataset.scanUrl;
            const STORE_URL = root.dataset.storeUrl;
            const RECEIPT_URL = root.dataset.receiptUrl;   // …/0/phieu — thay 0 bằng id thật
            const ORDERS_URL = root.dataset.ordersUrl;
            // Hạn quyền giảm giá của CHÍNH người đang đăng nhập, do API trả về.
            // 0 = không được tự bớt đồng nào → ô nhập biến mất hẳn.
            const HAN_MUC = Number(root.dataset.discountLimit) || 0;
            const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const KHOA_TREO = 'pos.treo';

            const $ = (id) => document.getElementById(id);
            const tien = (n) => new Intl.NumberFormat('vi-VN').format(Math.round(n || 0)) + '₫';
            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));

            /* Giỏ hàng: { id, ten, opt, gia, ton, sl, giam }.
             *
             * `gia` và `giam` chỉ để HIỂN THỊ và để gửi mức giảm lên. Số thu tiền do
             * API tính lại lúc chốt đơn — kể cả phần bớt, kể cả hạn quyền. */
            let gio = [];

            /* ---------- Tìm và quét hàng ---------- */

            const giaCua = (p, v) => {
                if (v.final_price !== null && v.final_price !== undefined) return Number(v.final_price);
                if (v.price) return Number(v.price);
                if (p.sale_price > 0 && p.sale_price < p.base_price) return Number(p.sale_price);
                return Number(p.base_price || 0);
            };

            const nhan = (v) => v.name || v.sku || 'Hàng đơn';

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
                    $('posResults').innerHTML = '<p class="pos-hint">Quét mã vạch để thêm hàng ngay, hoặc gõ tên để chọn từ danh sách.</p>';
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

            // Enter = "thêm món này vào giỏ", và có hai cách hiểu tuỳ thứ vừa gõ.
            //
            // THỬ QUÉT TRƯỚC: máy quét mã vạch chỉ là một bàn phím gõ rất nhanh rồi
            // bấm Enter, nên không cần thiết bị hay thư viện nào để đọc nó — chỉ cần
            // hỏi server xem chuỗi vừa gõ có phải một mã trong sổ không. Không phải
            // thì rơi về nghĩa cũ: thêm món đầu tiên trong danh sách gợi ý.
            $('posSearch').addEventListener('keydown', async (e) => {
                if (e.key !== 'Enter') return;
                e.preventDefault();

                const q = e.target.value.trim();
                if (!q) return;

                try {
                    const res = await fetch(`${SCAN_URL}?code=${encodeURIComponent(q)}`, {
                        headers: { Accept: 'application/json' },
                    });
                    if (res.ok) {
                        const d = (await res.json()).data || {};
                        them({
                            id: Number(d.product_variant_id),
                            ten: d.product_name || '',
                            opt: d.variant_name || d.sku || '',
                            gia: Number(d.price || 0),
                            ton: Number(d.stock || 0),
                        });
                        // Dọn ô ngay để lượt quét sau bắt đầu từ trắng — máy quét
                        // không tự xoá, mã cũ còn nằm đó là mã sau nối vào thành rác.
                        e.target.value = '';
                        timHang('');
                        return;
                    }
                } catch (err) {
                    /* Mất mạng giữa chừng thì vẫn còn đường gõ tay bên dưới. */
                }

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
                if (co) {
                    if (co.sl >= co.ton) { baoLoi(`Chỉ còn ${co.ton} sản phẩm "${co.opt}" trong kho.`); return; }
                    co.sl++;
                } else {
                    gio.push({ ...mon, sl: 1, giam: 0 });
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

            const tienHang = () => gio.reduce((s, d) => s + d.gia * d.sl, 0);
            const botMon = () => gio.reduce((s, d) => s + Math.round(d.gia * d.sl * (d.giam || 0) / 100), 0);
            const phaiTra = () => tienHang() - botMon();
            const soMon = () => gio.reduce((s, d) => s + d.sl, 0);

            function veGio() {
                const box = $('posCart');
                if (!gio.length) {
                    box.innerHTML = '<p class="pos-empty">Giỏ đang trống.</p>';
                } else {
                    box.innerHTML = gio.map((d) => {
                        const goc = d.gia * d.sl;
                        const bot = Math.round(goc * (d.giam || 0) / 100);
                        // Ô bớt giá chỉ dựng ra khi người này CÓ quyền bớt. Hiện một ô
                        // rồi từ chối mọi con số gõ vào là kiểu giao diện tệ nhất.
                        const oGiam = HAN_MUC > 0 ? `
                            <div class="pos-line-cut">
                                <span>Bớt</span>
                                <input type="text" inputmode="decimal" class="pos-cut-input"
                                       data-id="${d.id}" value="${d.giam || ''}" placeholder="0"
                                       title="Tối đa ${HAN_MUC}%">
                                <span>% ${bot > 0 ? '· <b>-' + tien(bot) + '</b>' : ''}</span>
                            </div>` : '';

                        return `
                        <div class="pos-line">
                            <span class="pos-line-name" title="${esc(d.ten)}">${esc(d.ten)}</span>
                            <span class="pos-line-total">
                                ${bot > 0 ? `<span class="pos-line-was">${tien(goc)}</span> ` : ''}${tien(goc - bot)}
                            </span>
                            <span class="pos-line-sub">${esc(d.opt)} · ${tien(d.gia)}</span>
                            <span class="pos-qty">
                                <button type="button" data-sl="-1" data-id="${d.id}" aria-label="Bớt một">−</button>
                                <span>${d.sl}</span>
                                <button type="button" data-sl="1" data-id="${d.id}" aria-label="Thêm một">+</button>
                            </span>
                            ${oGiam}
                        </div>`;
                    }).join('');
                }

                const bot = botMon();
                $('posQty').textContent = soMon() + ' món';
                $('posGross').textContent = tien(tienHang());
                $('posCutRow').hidden = bot <= 0;
                $('posCut').textContent = '-' + tien(bot);
                $('posTotal').textContent = tien(phaiTra());
                $('posClear').hidden = gio.length === 0;
                $('posTreoLuu').hidden = gio.length === 0;
                capNhatThoi();
            }

            $('posCart').addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-sl]');
                if (btn) doiSL(Number(btn.dataset.id), Number(btn.dataset.sl));
            });

            // Gõ trong ô bớt giá: cập nhật ngay nhưng KHÔNG vẽ lại cả giỏ, nếu không
            // ô đang gõ bị dựng lại và con trỏ nhảy về đầu sau mỗi ký tự.
            $('posCart').addEventListener('input', (e) => {
                const o = e.target.closest('.pos-cut-input');
                if (!o) return;

                const d = gio.find((x) => x.id === Number(o.dataset.id));
                if (!d) return;

                let v = parseFloat(String(o.value).replace(',', '.'));
                if (!isFinite(v) || v < 0) v = 0;

                // Kẹp theo hạn quyền ngay tại ô. Server vẫn kiểm lại khi chốt đơn —
                // đây chỉ là nói trước, để người bán không đọc giá cho khách nghe rồi
                // mới bị từ chối.
                const vuot = v > HAN_MUC;
                if (vuot) v = HAN_MUC;
                o.classList.toggle('is-err', vuot);
                baoLoi(vuot ? `Bạn được bớt tối đa ${HAN_MUC}% — mức cao hơn cần quản lý duyệt.` : '');

                d.giam = v;
                capNhatTien();
            });

            // Rời ô thì vẽ lại giỏ để số tiền trên từng dòng khớp con số vừa kẹp.
            $('posCart').addEventListener('focusout', (e) => {
                if (e.target.closest('.pos-cut-input')) veGio();
            });

            // Chỉ cập nhật các con số tổng — dùng khi đang gõ dở trong một ô.
            function capNhatTien() {
                const bot = botMon();
                $('posCutRow').hidden = bot <= 0;
                $('posCut').textContent = '-' + tien(bot);
                $('posTotal').textContent = tien(phaiTra());
                capNhatThoi();
            }

            $('posClear').addEventListener('click', () => {
                if (gio.length && !confirm('Bỏ toàn bộ giỏ hàng?')) return;
                donDep();
            });

            /* ---------- Đơn treo ---------- */

            const docTreo = () => {
                try {
                    const v = JSON.parse(localStorage.getItem(KHOA_TREO) || '[]');
                    return Array.isArray(v) ? v : [];
                } catch (e) {
                    // localStorage hỏng hoặc bị tắt: coi như không có đơn treo nào.
                    // Bán hàng không được phép dừng lại vì một tính năng phụ.
                    return [];
                }
            };

            const ghiTreo = (ds) => {
                try {
                    localStorage.setItem(KHOA_TREO, JSON.stringify(ds));
                } catch (e) {
                    baoLoi('Máy này không lưu được đơn treo (bộ nhớ trình duyệt đã đầy hoặc bị chặn).');
                }
                veSoTreo();
            };

            function veSoTreo() {
                const n = docTreo().length;
                $('posTreoSo').textContent = n;
                $('posTreoSo').hidden = n === 0;
            }

            $('posTreoLuu').addEventListener('click', () => {
                if (!gio.length) return;
                const ds = docTreo();
                ds.unshift({
                    id: Date.now(),
                    luc: new Date().toLocaleString('vi-VN', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }),
                    ten: $('posCusName').value.trim(),
                    sdt: $('posCusPhone').value.trim(),
                    cusId: $('posCusId').value,
                    voucher: $('posVoucher').value.trim(),
                    ghiChu: $('posNote').value.trim(),
                    gio: gio,
                });
                // Giữ 20 đơn gần nhất: quá số đó thì danh sách thành một đống không ai
                // đọc, và localStorage cũng có trần dung lượng.
                ghiTreo(ds.slice(0, 20));
                donDep();
                $('posSearch').focus();
            });

            const moNganKeo = () => {
                const ds = docTreo();
                $('posTreoDS').innerHTML = ds.length ? ds.map((t) => {
                    const soLuong = t.gio.reduce((s, d) => s + d.sl, 0);
                    const tong = t.gio.reduce((s, d) => s + d.gia * d.sl - Math.round(d.gia * d.sl * (d.giam || 0) / 100), 0);
                    return `
                        <div class="pos-treo-row">
                            <div class="pos-treo-info">
                                <div class="pos-treo-ten">${esc(t.ten || 'Khách lẻ')} · ${tien(tong)}</div>
                                <div class="pos-treo-phu">${soLuong} món · treo lúc ${esc(t.luc)}</div>
                            </div>
                            <button type="button" class="pos-mini" data-mo="${t.id}">Mở lại</button>
                            <button type="button" class="pos-mini is-stop" data-xoa="${t.id}">Xoá</button>
                        </div>`;
                }).join('') : '<p class="pos-ac-empty">Chưa có đơn nào đang treo.</p>';
                $('posTreoBox').hidden = false;
            };

            $('posTreoMo').addEventListener('click', moNganKeo);
            $('posTreoDong').addEventListener('click', () => { $('posTreoBox').hidden = true; });

            $('posTreoDS').addEventListener('click', (e) => {
                const moBtn = e.target.closest('[data-mo]');
                const xoaBtn = e.target.closest('[data-xoa]');
                const ds = docTreo();

                if (xoaBtn) {
                    ghiTreo(ds.filter((t) => String(t.id) !== xoaBtn.dataset.xoa));
                    moNganKeo();
                    return;
                }
                if (!moBtn) return;

                const t = ds.find((x) => String(x.id) === moBtn.dataset.mo);
                if (!t) return;
                // Mở đè lên một giỏ đang dở là mất giỏ đó — hỏi trước.
                if (gio.length && !confirm('Giỏ hiện tại sẽ bị thay bằng đơn treo này. Tiếp tục?')) return;

                gio = t.gio || [];
                $('posCusName').value = t.ten || '';
                $('posCusPhone').value = t.sdt || '';
                $('posCusId').value = t.cusId || '';
                $('posVoucher').value = t.voucher || '';
                $('posNote').value = t.ghiChu || '';
                $('posCusTag').textContent = t.ten || 'khách lẻ';

                // Lấy ra khỏi danh sách treo: đơn đang được bán thì không còn treo nữa,
                // giữ lại là sớm muộn có người bán nó hai lần.
                ghiTreo(ds.filter((x) => x.id !== t.id));
                $('posTreoBox').hidden = true;
                veGio();
            });

            /* ---------- Thanh toán ---------- */

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
                    ? new Intl.NumberFormat('vi-VN').format(phaiTra())
                    : new Intl.NumberFormat('vi-VN').format(soTien(o.value) + Number(btn.dataset.add));
                capNhatThoi();
                o.focus();
            });

            function capNhatThoi() {
                const tong = phaiTra();
                const dua = soTien($('posTendered').value);
                const thoi = dua - tong;
                const hienThoi = hinhThuc === 'cash' && dua > 0;

                $('posChange').hidden = !hienThoi;
                if (hienThoi) {
                    $('posChangeVal').textContent = thoi >= 0 ? tien(thoi) : 'thiếu ' + tien(-thoi);
                    $('posChange').style.background = thoi >= 0 ? '#f6ffed' : '#fff1f0';
                    $('posChange').style.color = thoi >= 0 ? '#389e0d' : '#cf1322';
                }

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
                    items: gio.map((d) => ({
                        product_variant_id: d.id,
                        quantity: d.sl,
                        discount_percent: d.giam || 0,
                    })),
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

                const dong = [];
                if (Number(d.line_discount_amount) > 0) {
                    dong.push(['Tiền hàng', tien(Number(d.subtotal_amount) + Number(d.line_discount_amount))]);
                    dong.push(['Bớt theo món', '−' + tien(d.line_discount_amount)]);
                } else {
                    dong.push(['Tiền hàng', tien(d.subtotal_amount)]);
                }
                if (Number(d.discount_amount) > 0) {
                    dong.push([`Mã ${d.voucher_code || 'giảm giá'}`, '−' + tien(d.discount_amount)]);
                }
                dong.push(['Khách phải trả', tien(d.total_amount)]);
                if (d.amount_tendered !== null && d.amount_tendered !== undefined) {
                    dong.push(['Khách đưa', tien(d.amount_tendered)]);
                }
                $('posDoneSum').innerHTML = dong.map(([k, v]) => `<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('');

                const coThoi = d.change_amount !== null && d.change_amount !== undefined;
                $('posDoneChange').hidden = !coThoi;
                if (coThoi) $('posDoneChangeVal').textContent = tien(d.change_amount);

                $('posDonePrint').href = RECEIPT_URL.replace(/\/0\/phieu$/, `/${d.order_id}/phieu`);
                $('posDoneView').href = `${ORDERS_URL}?keyword=${encodeURIComponent(d.order_code || '')}`;
                $('posDone').hidden = false;
                $('posDoneNext').focus();

                // Lượt bán vừa rồi làm đổi số của ca — cập nhật nhãn trên thanh trên
                // cùng để người trực thấy két đang có bao nhiêu mà không phải mở ngăn
                // kéo ra xem. Chạy nền, không chặn phiếu.
                tairCa();
            }

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
                timHang('');
                $('posSearch').focus();
            });

            /* ---------- Ca làm việc & sổ quỹ ---------- */

            const CA_URL = root.dataset.caUrl;
            const CA_MO_URL = root.dataset.caMoUrl;
            const CA_DONG_URL = root.dataset.caDongUrl;
            const SO_QUY_URL = root.dataset.soQuyUrl;

            // Ca hiện tại, giữ lại để nhãn trên thanh trên cùng không phải hỏi lại
            // sau mỗi lượt bán. null = chưa mở ca.
            let ca = null;

            const guiJSON = (url, body) => fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                },
                body: JSON.stringify(body),
            });

            async function tairCa() {
                try {
                    const r = await fetch(CA_URL, { headers: { Accept: 'application/json' } });
                    ca = (await r.json()).data || null;
                } catch (e) {
                    // Cụm ca làm việc là thứ GHI CHÉP, không phải thứ gác cửa: hỏng thì
                    // nhãn mất chứ bán hàng vẫn chạy bình thường.
                    ca = null;
                }
                veNhanCa();
            }

            function veNhanCa() {
                $('posCaNhan').textContent = ca
                    ? 'Ca: ' + tien(Number(ca.opening_cash || 0) + Number(ca.tong_thu || 0) - Number(ca.tong_chi || 0))
                    : 'Chưa mở ca';
            }

            function veNganKeoCa() {
                const box = $('posCaND');

                if (!ca) {
                    box.innerHTML = `
                        <div class="pos-ca-form">
                            <p class="pos-ca-hint">
                                Chưa mở ca. Đếm số tiền đang có trong két rồi mở ca — từ lúc đó mọi lượt
                                thu chi tiền mặt được ghi vào sổ của ca này.
                            </p>
                            <label class="pos-ca-lb" for="posCaDauCa">Tiền có sẵn trong két</label>
                            <input type="text" inputmode="numeric" id="posCaDauCa" class="pos-input pos-money" placeholder="0">
                            <button type="button" class="pos-submit" id="posCaMoBtn">Mở ca</button>
                        </div>`;
                    return;
                }

                const theoSo = Number(ca.opening_cash || 0) + Number(ca.tong_thu || 0) - Number(ca.tong_chi || 0);
                box.innerHTML = `
                    <div class="pos-ca-form">
                        <dl class="pos-done-sum">
                            <div><dt>Tiền đầu ca</dt><dd>${tien(ca.opening_cash)}</dd></div>
                            <div><dt>Thu tiền mặt (${ca.so_don_tien_mat || 0} lượt bán)</dt><dd>${tien(ca.tong_thu)}</dd></div>
                            <div><dt>Chi tiền mặt</dt><dd>−${tien(ca.tong_chi)}</dd></div>
                            <div><dt><b>Theo sổ, két phải có</b></dt><dd><b>${tien(theoSo)}</b></dd></div>
                        </dl>

                        <div class="pos-ca-line">
                            <select id="posQuyChieu" class="pos-input">
                                <option value="out">Chi</option>
                                <option value="in">Thu</option>
                            </select>
                            <input type="text" inputmode="numeric" id="posQuyTien" class="pos-input pos-money" placeholder="Số tiền">
                        </div>
                        <input type="text" id="posQuyLyDo" class="pos-input" placeholder="Lý do (bắt buộc) — VD: mua nước, trả tiền ship">
                        <button type="button" class="pos-mini" id="posQuyGhi">Ghi vào sổ quỹ</button>

                        <hr class="pos-ca-hr">

                        <label class="pos-ca-lb" for="posCaDemCuoi">Tiền ĐẾM ĐƯỢC trong két</label>
                        <input type="text" inputmode="numeric" id="posCaDemCuoi" class="pos-input pos-money" placeholder="0">
                        <p class="pos-ca-hint" id="posCaChenh"></p>
                        <button type="button" class="pos-submit" id="posCaDongBtn">Đóng ca</button>
                    </div>`;
            }

            const moNganKeoCa = async () => {
                await tairCa();
                veNganKeoCa();
                $('posCaBox').hidden = false;
            };

            $('posCaMo').addEventListener('click', moNganKeoCa);
            $('posCaDong').addEventListener('click', () => { $('posCaBox').hidden = true; });

            // Chênh lệch hiện NGAY lúc gõ, trước khi bấm đóng ca: người đếm tiền cần
            // biết mình đang thừa hay thiếu trong lúc còn đang đếm, không phải sau khi
            // đã chốt sổ.
            $('posCaND').addEventListener('input', (e) => {
                if (e.target.classList.contains('pos-money')) {
                    const n = soTien(e.target.value);
                    e.target.value = n ? new Intl.NumberFormat('vi-VN').format(n) : '';
                }
                if (e.target.id === 'posCaDemCuoi' && ca) {
                    const theoSo = Number(ca.opening_cash || 0) + Number(ca.tong_thu || 0) - Number(ca.tong_chi || 0);
                    const lech = soTien(e.target.value) - theoSo;
                    const p = $('posCaChenh');
                    p.textContent = lech === 0
                        ? 'Khớp sổ.'
                        : (lech > 0 ? 'Thừa ' : 'Thiếu ') + tien(Math.abs(lech)) + ' so với sổ.';
                    p.style.color = lech === 0 ? '#389e0d' : '#cf1322';
                }
            });

            $('posCaND').addEventListener('click', async (e) => {
                const btn = e.target.closest('button');
                if (!btn) return;

                if (btn.id === 'posCaMoBtn') {
                    const r = await guiJSON(CA_MO_URL, { opening_cash: soTien($('posCaDauCa').value) });
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) { alert(d.message || 'Không mở được ca.'); return; }
                    await moNganKeoCa();
                    return;
                }

                if (btn.id === 'posQuyGhi') {
                    const lyDo = $('posQuyLyDo').value.trim();
                    if (!lyDo) { alert('Vui lòng ghi lý do.'); return; }
                    const r = await guiJSON(SO_QUY_URL, {
                        direction: $('posQuyChieu').value,
                        amount: soTien($('posQuyTien').value),
                        reason: lyDo,
                    });
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) { alert(d.message || 'Không ghi được sổ quỹ.'); return; }
                    await moNganKeoCa();
                    return;
                }

                if (btn.id === 'posCaDongBtn') {
                    if (!confirm('Đóng ca với số tiền vừa đếm? Con số đối chiếu sẽ được chốt lại.')) return;
                    const r = await guiJSON(CA_DONG_URL, { counted_cash: soTien($('posCaDemCuoi').value) });
                    const d = await r.json().catch(() => ({}));
                    if (!r.ok) { alert(d.message || 'Không đóng được ca.'); return; }

                    const c = d.data?.ca || {};
                    const lech = Number(c.difference || 0);
                    const ngoai = (d.data?.ngoai_ca || []).length;
                    alert(
                        'Đã đóng ca.\n\n'
                        + 'Theo sổ: ' + tien(c.expected_cash) + '\n'
                        + 'Đếm được: ' + tien(c.counted_cash) + '\n'
                        + (lech === 0 ? 'Khớp sổ.' : (lech > 0 ? 'Thừa ' : 'Thiếu ') + tien(Math.abs(lech)))
                        + (ngoai ? '\n\nLƯU Ý: có ' + ngoai + ' khoản tiền mặt phát sinh lúc chưa mở ca, '
                            + 'không nằm trong con số đối chiếu ở trên.' : '')
                    );
                    $('posCaBox').hidden = true;
                    await tairCa();
                }
            });

            /* ---------- Phím tắt ---------- */

            document.addEventListener('keydown', (e) => {
                if (!$('posDone').hidden) {
                    if (e.key === 'Enter' || e.key === 'Escape') {
                        e.preventDefault();
                        $('posDoneNext').click();
                    }
                    return;
                }
                if (!$('posTreoBox').hidden && e.key === 'Escape') {
                    $('posTreoBox').hidden = true;
                    return;
                }
                if (e.key === 'F2') {
                    e.preventDefault();
                    $('posSearch').select();
                }
            });

            veSoTreo();
            veGio();
            tairCa();
        })();
    </script>
@endsection
