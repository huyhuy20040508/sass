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

        {{-- ============ KHU TRÁI: chọn hàng ============

             Xếp theo màn thu ngân bản v2: nhóm hàng trên cùng, ô tìm/quét ngay
             dưới, rồi tới lưới thẻ và phân trang. Người bán quen mặt hàng thì bấm
             thẳng vào thẻ, không gõ chữ nào; ô tìm để dành cho hàng lạ và máy quét. --}}
        <section class="tnk-khu tnk-khu--chinh">
            <div class="tnk-dau">
                <span class="tnk-tab">Hàng hoá</span>
                <div class="pos-pick-acts">
                    {{-- Tắt ảnh thì mỗi màn xếp được gần gấp đôi số thẻ. Nhớ theo
                         MÁY vì đây là thói quen của người đứng quầy này. --}}
                    <label class="pos-switch">
                        <input type="checkbox" id="posAnhTat">
                        <span>Ẩn ảnh</span>
                    </label>
                </div>
            </div>

            <div class="tnk-than pos-pick">
                {{-- Cuộn ngang chứ không xuống dòng: tiệm nhiều nhóm thì thanh này
                     ăn mất nguyên một hàng thẻ hàng. --}}
                <div class="pos-cats" id="posCats">
                    <button type="button" class="pos-cat is-on" data-id="">Tất cả</button>
                    @foreach($nhomHang as $n)
                        <button type="button" class="pos-cat" data-id="{{ $n['id'] }}">{{ $n['name'] }}</button>
                    @endforeach
                </div>

                <div class="pos-searchbar">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M7 8v8M10 8v8M13 8v8M17 8v8"/></svg>
                    <input type="search" id="posSearch" class="pos-search" autocomplete="off" autofocus
                           placeholder="Quét mã vạch, hoặc gõ tên hàng rồi bấm Enter">
                    <kbd class="pos-kbd">F2</kbd>
                </div>

                <div class="pos-grid" id="posResults"></div>

                {{-- Phân trang nằm ngoài vùng cuộn: cuộn hết lưới mới thấy nút sang
                     trang thì lần nào cũng phải cuộn thêm một nhịp nữa. --}}
                <div class="pos-pager" id="posPager" hidden></div>
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
                {{-- Đầu cột và các dòng hàng nằm TRONG CÙNG một khung cuộn: hai
                     khung riêng thì khi thanh cuộn hiện ra, ruột giỏ hụt mất bề
                     ngang của nó còn đầu cột thì không, và cả bảng lệch cột. --}}
                <div class="pos-cart-wrap">
                    {{-- Dính trên nóc — giỏ dài ra thì vẫn đọc được cột nào là gì.
                         Bề ngang các cột lấy theo bản v2. --}}
                    <div class="pos-cart-head">
                        <span>Tên hàng</span>
                        <span>SL</span>
                        <span>Đơn giá</span>
                        <span>Thành tiền</span>
                        <span></span>
                    </div>

                    <div class="pos-cart" id="posCart"></div>
                </div>

                {{-- Giỏ trống nằm trong <template> chứ không viết hai lần: lúc mở
                     trang và lúc bán xong đều cần đúng khối này, mà hai bản sao thì
                     sớm muộn có bản bị bỏ quên khi sửa chữ. --}}
                <template id="posEmptyTpl">
                    <div class="pos-empty">
                        <img src="{{ asset('images/gio-trong.svg') }}" alt="" class="pos-empty-anh">
                        <p class="pos-empty-tieu">Chưa có hàng trong giỏ</p>
                        <p class="pos-empty-phu">
                            Quét mã vạch, hoặc bấm vào thẻ hàng bên trái để thêm.
                        </p>
                        <p class="pos-empty-phim"><kbd class="pos-kbd">F2</kbd> để về ô tìm kiếm</p>
                    </div>
                </template>

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

                    {{-- ===== Khu thanh toán =====

                         Xếp theo bản v2: MỘT bảng tiền duy nhất, mọi thứ đều là một hàng
                         "nhãn bên trái · số bên phải" — kể cả ô Khách đưa và dòng Thối lại.
                         Bản cũ của mình tách khối tổng ra khỏi khối nhận tiền, thành ra
                         con số cuối cùng và con số khách vừa đưa nằm ở hai bảng khác nhau,
                         mắt phải nhảy qua nhảy lại để so.

                         Khác v2 ở đúng một chỗ, và là cố ý: hàng chọn hình thức đứng NGAY
                         DƯỚI tổng, trước ô Khách đưa. v2 để nó tận cuối, trong khi ô nhận
                         tiền chỉ có nghĩa sau khi đã biết khách trả bằng gì. --}}
                    <div class="pos-tinh">
                        <div class="pos-hang">
                            <span>Tiền hàng <em id="posQty">0 món</em></span>
                            <b id="posGross">0₫</b>
                        </div>

                        <div class="pos-hang is-tru" id="posCutRow" hidden>
                            <span>Bớt theo món</span>
                            <b id="posCut">0₫</b>
                        </div>

                        {{-- Mã giảm giá: chỉ hiện khi có mã, và KHÔNG bịa ra con số. Mức
                             giảm do API tính lại lúc chốt đơn, đoán trước ở đây là đọc cho
                             khách nghe một số có thể sai. --}}
                        <div class="pos-hang is-ma" id="posVoucherRow" hidden>
                            <span>Mã <b id="posVoucherMa"></b></span>
                            <b>trừ khi chốt đơn</b>
                        </div>

                        <div class="pos-hang is-tong">
                            <span>Khách phải trả</span>
                            <b id="posTotal">0₫</b>
                        </div>

                        {{-- Hình thức: nút có icon như v2, không phải tab chữ. Ở quầy thì
                             hình dạng dễ nhận hơn chữ, nhất là khi tay đang cầm tiền. --}}
                        <div class="pos-httt" id="posPayTabs">
                            @foreach($PAY_METHODS as $value => $label)
                                <button type="button" class="pos-tab {{ $loop->first ? 'is-on' : '' }}"
                                        data-method="{{ $value }}">
                                    @if($value === 'cash')
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></svg>
                                    @else
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></svg>
                                    @endif
                                    <span>{{ $label }}</span>
                                </button>
                            @endforeach
                        </div>

                        <div class="pos-cash" id="posCash">
                            {{-- Ô nhập không viền, căn phải, có chữ "đ" đứng cạnh — để nó
                                 đọc như một dòng nữa của bảng tiền chứ không như một ô form
                                 lạc vào giữa. --}}
                            <div class="pos-hang is-nhap">
                                <label for="posTendered">
                                    Khách đưa
                                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                                </label>
                                <span class="pos-o-tien">
                                    <input type="text" inputmode="numeric" id="posTendered"
                                           autocomplete="off" placeholder="0">
                                    <i>đ</i>
                                </span>
                            </div>

                            {{-- Hai hàng nút, hai việc khác nhau:
                                 · Hàng trên ĐẶT thẳng số tiền, tính từ tổng đơn — khách trả
                                   đúng, hoặc đưa chẵn chục nghìn / trăm nghìn.
                                 · Hàng dưới CỘNG DỒN mệnh giá — khách đưa mấy tờ thì bấm
                                   mấy nút, không phải nhẩm tổng trong đầu. --}}
                            <div class="pos-goiy" id="posGoiY" hidden></div>

                            <div class="pos-notes">
                                @foreach($MENH_GIA as $m)
                                    <button type="button" class="pos-note-btn" data-add="{{ $m }}">{{ number_format($m / 1000, 0, ',', '.') }}k</button>
                                @endforeach
                            </div>

                            <div class="pos-hang is-thoi" id="posChange" hidden>
                                <span>Thối lại</span>
                                <b id="posChangeVal">0₫</b>
                            </div>
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

    {{-- ============ Chọn phiên bản ============
         Chỉ bật lên khi thẻ hàng có từ hai phiên bản còn hàng trở lên. Một phiên
         bản thì bấm thẻ là vào giỏ luôn — hỏi lại một câu chỉ có một đáp án là
         bắt người bán bấm thừa một nhịp cho mỗi món. --}}
    <div class="pos-drawer" id="posVarBox" hidden>
        <div class="pos-drawer-card" role="dialog" aria-modal="true" aria-labelledby="posVarTitle">
            <header class="pos-drawer-head">
                <h2 id="posVarTitle">Chọn phiên bản</h2>
                <button type="button" class="pos-mini" id="posVarDong">Đóng</button>
            </header>
            <p class="pos-var-ten" id="posVarTen"></p>
            <div class="pos-drawer-body pos-vars" id="posVarDS"></div>
        </div>
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
        /* Thanh này CAO 40px, và con số đó là cố ý giữ thấp: nó chỉ là lối vào phụ
           (hàng lạ và máy quét), còn lối chính là bấm thẳng vào thẻ bên dưới. Mỗi
           chục pixel cho nó là một hàng thẻ bớt đi trên màn 14 inch ở quầy. */
        .pos-searchbar {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-bottom: 1px solid #f0f0f0;
            color: #8c8c8c;
        }

        .pos-search {
            flex: 1;
            min-width: 0;
            height: 28px;
            border: 0;
            outline: 0;
            font-family: inherit;
            font-size: 14px;
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

        /* Thanh nhóm hàng — viên thuốc bo tròn, nhóm đang chọn tô nền đậm chứ
           không chỉ đổi màu chữ: ở khoảng cách đứng bán, chữ đổi màu không đủ để
           liếc một cái là biết mình đang ở nhóm nào. */
        .pos-cats {
            display: flex;
            flex-shrink: 0;
            gap: 6px;
            padding: 7px 14px;
            border-bottom: 1px solid #f0f0f0;
            overflow-x: auto;
            scrollbar-width: thin;
        }

        .pos-cat {
            flex-shrink: 0;
            height: 28px;
            padding: 0 13px;
            border: 1px solid #e8e8e8;
            border-radius: 14px;
            background: #fff;
            font-family: inherit;
            font-size: 13px;
            color: #595959;
            white-space: nowrap;
            cursor: pointer;
            transition: border-color .12s, background .12s, color .12s;
        }

        .pos-cat:hover { border-color: #1890ff; color: #1890ff; }
        .pos-cat.is-on { border-color: #001529; background: #001529; color: #fff; }

        /* Cong tac an anh nam tren hang the, canh tieu de khu. */
        .pos-pick-acts { display: flex; align-items: center; gap: 10px; margin-left: auto; padding-right: 4px; }

        .pos-switch {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; color: #595959; cursor: pointer; user-select: none;
        }

        .pos-switch input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }

        /* Lưới thẻ hàng. auto-fill để màn rộng thì thêm cột, chứ không kéo thẻ to ra. */
        .pos-grid {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            display: grid;
            align-content: start;
            grid-template-columns: repeat(auto-fill, minmax(142px, 1fr));
            gap: 10px;
            padding: 12px 14px;
        }

        /* Tắt ảnh: thẻ chỉ còn chữ nên xếp dày hơn được. */
        .pos-grid.is-gon { grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 8px; }

        /* Câu báo trống của lưới hàng — trải hết bề ngang lưới thay vì đứng lọt
           thỏm trong một ô. */
        .pos-hint {
            grid-column: 1 / -1;
            margin: 0;
            padding: 24px 4px;
            color: #bfbfbf;
            font-size: 13px;
            text-align: center;
        }

        .pos-card {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 8px;
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            background: #fff;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
            transition: border-color .12s, box-shadow .12s;
        }

        .pos-card:hover:not(:disabled) { border-color: #1890ff; box-shadow: 0 2px 8px rgba(24, 144, 255, .14); }
        .pos-card:disabled { cursor: not-allowed; opacity: .55; }

        .pos-card-anh { display: block; aspect-ratio: 1 / 1; border-radius: 5px; overflow: hidden; background: #f5f5f5; }
        .pos-card-anh img { display: block; width: 100%; height: 100%; object-fit: cover; }

        /* Kẹp 2 dòng: một cái tên dài không được kéo thẻ này cao hơn thẻ bên cạnh,
           lưới so le nhìn như bị vỡ. */
        .pos-card-ten {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            font-size: 13px;
            font-weight: 500;
            line-height: 17px;
            color: #262626;
        }

        .pos-card-gia { font-size: 13px; font-weight: 600; color: #1890ff; }
        .pos-card-ton { font-size: 11px; color: #8c8c8c; }
        .pos-card.is-het .pos-card-ton { color: #ff4d4f; }

        /* Ngoài vùng cuộn: cuộn hết lưới mới thấy nút sang trang thì lần nào cũng
           phải cuộn thêm một nhịp nữa. */
        .pos-pager {
            display: flex;
            flex-shrink: 0;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px;
            border-top: 1px solid #f0f0f0;
        }

        .pos-pager-so { font-size: 12px; color: #8c8c8c; }

        /* Danh sach phien ban trong hop chon. */
        .pos-var-ten { margin: 0 0 8px; font-size: 13px; font-weight: 600; color: #262626; }
        .pos-vars { display: grid; grid-template-columns: repeat(auto-fill, minmax(168px, 1fr)); gap: 8px; }

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

        /* Khung cuộn là thằng BỌC NGOÀI, chứa cả đầu cột lẫn các dòng — xem chú
           thích ở phần markup. */
        .pos-cart-wrap { flex: 1; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; }
        .pos-cart { flex: 1; padding: 0 0 6px; }

        /* CHỈ lúc giỏ trống mới bật flex, để khối rỗng bên trong tự căn giữa bằng
           margin:auto. Bật thường trực thì các dòng hàng thành flex item và bị co
           dẹp lại khi giỏ dài hơn khung. */
        .pos-cart.is-trong { display: flex; }

        .pos-empty { margin: auto; padding: 24px 20px; text-align: center; }

        /* Mờ bớt: đây là hình lấp một ô trống, không phải thứ mời người ta nhìn. */
        .pos-empty-anh { width: 60px; height: auto; opacity: .7; }

        .pos-empty-tieu { margin: 14px 0 0; font-size: 14px; font-weight: 600; color: #595959; }

        .pos-empty-phu {
            max-width: 232px;
            margin: 5px auto 0;
            font-size: 12px;
            line-height: 1.55;
            color: #bfbfbf;
        }

        /* Nhắc phím tắt — nhạt hơn hẳn hai dòng trên: người quen việc không cần
           đọc nó, người mới thì nhìn một lần là nhớ. */
        .pos-empty-phim { margin: 14px 0 0; font-size: 11px; color: #d9d9d9; }
        .pos-empty-phim .pos-kbd { margin-right: 3px; }

        /* Bề ngang cột 36/20/20/18/6 — số của bản v2. Đầu cột và dòng hàng dùng
           CHUNG một khai báo grid, lệch một con số là bảng so le ngay. */
        .pos-cart-head,
        .pos-line {
            display: grid;
            grid-template-columns: 36% 20% 20% 18% 6%;
        }

        .pos-cart-head {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 8px 10px;
            border-bottom: 1px solid #f0f0f0;
            background: #fafafa;
            font-size: 12px;
            font-weight: 600;
            color: #8c8c8c;
        }

        /* Canh cột: khai CHUNG cho ô tiêu đề và ô dữ liệu, mỗi cột đúng một dòng.
           Dùng justify-self chứ không text-align vì cột SL chứa một cụm nút, không
           phải chữ — text-align không với tới nó, và đó chính là chỗ từng lệch. */
        .pos-cart-head span:nth-child(2), .pos-qty,
        .pos-cart-head span:nth-child(3), .pos-line-unit { justify-self: center; }
        .pos-cart-head span:nth-child(4), .pos-line-total { justify-self: end; }

        .pos-line {
            align-items: center;
            gap: 3px 0;
            padding: 8px 10px;
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

        .pos-line-unit { grid-column: 3; grid-row: 1; font-size: 13px; color: #595959; }
        .pos-line-total { grid-column: 4; grid-row: 1; font-size: 13px; font-weight: 600; color: #262626; }
        .pos-line-sub { grid-column: 1 / 5; grid-row: 2; font-size: 12px; color: #8c8c8c; }

        /* Nút bỏ dòng: mờ sẵn, chỉ đậm lên khi rê vào. Nó đứng ngay cạnh nút cộng
           số lượng, mà bấm nhầm thì mất cả dòng chứ không phải bớt một cái. */
        .pos-line-del {
            grid-column: 5;
            grid-row: 1;
            justify-self: end;
            width: 22px;
            height: 22px;
            border: 0;
            border-radius: 4px;
            background: transparent;
            color: #d9d9d9;
            font-family: inherit;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
        }

        .pos-line-del:hover { background: #fff1f0; color: #ff4d4f; }

        /* Giá gốc gạch ngang khi dòng có bớt giá — người bán liếc qua là biết dòng
           nào đã được bớt, không phải mở từng ô ra xem.
           Nằm RIÊNG một dòng phía trên giá đã bớt: để cạnh nhau thì hai con số tiền
           triệu cộng lại rộng gấp đôi cột "Thành tiền" và tràn sang cột đơn giá. */
        .pos-line-was {
            display: block;
            text-align: right;
            font-size: 11px;
            font-weight: 400;
            text-decoration: line-through;
            color: #bfbfbf;
        }

        .pos-qty {
            grid-column: 2;
            grid-row: 1;
            display: inline-flex;
            align-items: center;
            gap: 2px;
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

        /* Ô số lượng gõ được. Trông như một con số chứ không như ô nhập — viền chỉ
           hiện khi rê vào hoặc đang gõ: giữa hai nút − + mà kẻ thêm một khung nữa
           thì cụm này rối, trong khi 90% lượt bán không ai gõ vào đây. */
        .pos-qty-so {
            width: 40px;
            height: 24px;
            padding: 0 2px;
            border: 1px solid transparent;
            border-radius: 4px;
            background: transparent;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: #262626;
            text-align: center;
            outline: 0;
        }

        .pos-qty-so:hover { border-color: #e8e8e8; }
        .pos-qty-so:focus { border-color: #1890ff; background: #fff; }

        /* Nháy nền một cái ở dòng vừa được cộng thêm. Chỉ đổi nền, KHÔNG đổi chiều
           cao hay lề — dòng nhảy chỗ giữa lúc người bán đang nhìn còn khó theo dõi
           hơn là không báo gì. */
        @keyframes pos-nhay {
            from { background: #e6f4ff; }
            to { background: transparent; }
        }

        .pos-line.is-vua-them { animation: pos-nhay 1.1s ease-out; }

        /* Máy quầy cảm ứng: ngón tay cần khoảng 44px, chuột thì 24px là vừa. Tách
           bằng pointer:coarse nên không phải hỏi máy quầy là loại nào — thiết bị tự
           khai báo mình được điều khiển bằng gì. */
        @media (pointer: coarse) {
            .pos-qty button { width: 34px; height: 34px; font-size: 18px; }
            .pos-qty-so { width: 46px; height: 34px; font-size: 15px; border-color: #e8e8e8; }
            .pos-line-del { width: 30px; height: 30px; font-size: 19px; }
            .pos-cat { height: 34px; padding: 0 16px; }
            .pos-tab { height: 52px; }
            .pos-o-tien input { height: 40px; font-size: 21px; }
            .pos-note-btn { height: 36px; }
            .pos-goiy button { height: 42px; }
        }

        /* Ô bớt giá: nhỏ, nằm ở hàng thứ ba của dòng hàng. Cố ý KHÔNG nổi bật —
           đây là ngoại lệ, không phải thao tác thường ngày. */
        .pos-line-cut {
            grid-column: 1 / -1;
            grid-row: 3;
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

        /* Ô nhập TIỀN trong ngăn kéo Ca làm việc — số căn phải và to hơn chữ thường,
           vì đó là con số người ta vừa đếm trong két rồi gõ vào để đối chiếu.
           Ô "Khách đưa" ngoài màn bán KHÔNG dùng lớp này: nó nằm trong bảng tiền và
           có kiểu riêng (.pos-o-tien). */
        .pos-money { height: 40px; font-size: 17px; font-weight: 600; text-align: right; }
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

        /* ---------- Khu thanh toán: một bảng "nhãn trái · số phải" ----------
           Khuôn của bản v2. Mọi dòng đều cùng một dáng, kể cả dòng có ô nhập, nên
           mắt chỉ phải quét một cột số duy nhất từ trên xuống. */
        .pos-tinh { padding: 10px 0 2px; border-top: 1px solid #f0f0f0; }

        .pos-hang {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-height: 26px;
            font-size: 13px;
            color: #595959;
        }

        .pos-hang em { font-style: normal; color: #bfbfbf; }
        .pos-hang b { font-weight: 600; color: #262626; }

        /* Dòng bị TRỪ đi: số đỏ, để phân biệt với các dòng cộng vào. */
        .pos-hang.is-tru b { color: #cf1322; }

        /* Dòng mã giảm giá: vế phải là chữ chứ không phải số, nên nhạt và nhỏ hơn —
           nó là một lời hứa, không phải một con số để cộng.
           Phải dùng CON TRỰC TIẾP (> b): mã cũng nằm trong thẻ <b>, mà nó là con
           cuối của <span> nên :last-child bắt trúng luôn cả hai. */
        .pos-hang.is-ma > b { font-weight: 400; font-size: 12px; color: #bfbfbf; }
        .pos-hang.is-ma span b { font-weight: 600; color: #595959; }

        /* Dòng tổng — tách bằng một đường kẻ và tô đỏ như v2: đây là con số duy
           nhất trên màn hình được đọc to lên cho khách nghe. */
        .pos-hang.is-tong {
            margin-top: 8px;
            padding-top: 9px;
            border-top: 1px dashed #e8e8e8;
            font-size: 15px;
            font-weight: 600;
            color: #262626;
        }

        .pos-hang.is-tong b { font-size: 24px; font-weight: 700; color: #cf1322; }

        /* ----- Hình thức thanh toán: nút có icon, không phải tab chữ ----- */
        .pos-httt { display: flex; gap: 8px; margin: 11px 0 4px; }

        .pos-tab {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            height: 44px;
            border: 1px solid #e8e8e8;
            border-radius: 6px;
            background: #fff;
            font-family: inherit;
            font-size: 13px;
            color: #8c8c8c;
            cursor: pointer;
            transition: border-color .12s, color .12s, background .12s;
        }

        .pos-tab:hover { border-color: #1890ff; color: #1890ff; }

        /* Đang chọn: viền dày hơn 1px chứ không chỉ đổi màu — người bán liếc qua là
           thấy, không phải so hai sắc xanh với nhau. */
        .pos-tab.is-on {
            border-color: #1890ff;
            border-width: 2px;
            background: #f0f8ff;
            color: #1890ff;
            font-weight: 600;
        }

        /* ----- Ô nhận tiền: đọc như một dòng nữa của bảng, không như ô form ----- */
        .pos-cash { margin-top: 2px; }

        .pos-hang.is-nhap { padding: 4px 0; }

        .pos-hang.is-nhap label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #595959;
            cursor: pointer;
        }

        .pos-hang.is-nhap label svg { color: #bfbfbf; }

        /* Gạch chân thay cho khung: ô vẫn rõ là gõ được, mà không kẻ thêm một hộp
           nữa vào giữa bảng tiền. */
        .pos-o-tien {
            display: inline-flex;
            align-items: baseline;
            gap: 3px;
            padding: 0 2px;
            border-bottom: 1px solid #e8e8e8;
            transition: border-color .12s;
        }

        .pos-o-tien:focus-within { border-color: #1890ff; }
        .pos-o-tien i { font-style: normal; font-size: 14px; color: #8c8c8c; }

        .pos-o-tien input {
            width: 130px;
            height: 32px;
            border: 0;
            outline: 0;
            background: transparent;
            font-family: inherit;
            font-size: 19px;
            font-weight: 600;
            color: #262626;
            text-align: right;
        }

        /* Dòng thối lại — nền xanh lá khi đủ, đỏ khi thiếu; đổi bằng class chứ
           không bằng style gắn thẳng vào thẻ. */
        .pos-hang.is-thoi {
            margin-top: 9px;
            padding: 7px 10px;
            border-radius: 4px;
            background: #f6ffed;
            color: #389e0d;
        }

        .pos-hang.is-thoi b { font-size: 19px; font-weight: 700; color: inherit; }
        .pos-hang.is-thoi.is-thieu { background: #fff1f0; color: #cf1322; }

        /* Hàng gợi ý số tiền — nút thưa và cao hơn hàng mệnh giá bên dưới, vì đây
           mới là hàng được bấm nhiều nhất (khách trả đúng hoặc đưa chẵn). */
        .pos-goiy { display: flex; gap: 6px; margin-top: 8px; }

        .pos-goiy button {
            flex: 1;
            min-width: 0;
            height: 34px;
            border: 1px solid #d6e9ff;
            border-radius: 4px;
            background: #f0f8ff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            color: #1890ff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
            transition: border-color .12s, background .12s;
        }

        .pos-goiy button:hover { border-color: #1890ff; background: #e6f4ff; }

        .pos-notes { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }

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
        .pos-drawer[hidden], .pos-hang[hidden], .pos-pager[hidden], .pos-goiy[hidden] { display: none; }

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
            const KHOA_ANH = 'pos.anh-tat';
            const KHOA_DANG_DO = 'pos.gio-dang-do';
            // Giỏ để quên quá lâu thì không còn là "lượt bán đang dở" nữa — nó là rác
            // của ca hôm trước, và bày ra như giỏ đang làm là mời người ta bán nhầm.
            const HAN_GIU_GIO_MS = 12 * 60 * 60 * 1000;

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

            // Giá in trên thẻ hàng. Thẻ chỉ đủ chỗ cho MỘT con số, nên lấy giá thấp
            // nhất trong các phiên bản CÒN HÀNG — báo giá của phiên bản đã hết là báo
            // một giá khách không mua được.
            //
            // Trả kèm cờ `tu`: chỉ ghi "từ …" khi các phiên bản THỰC SỰ khác giá.
            // Áo ba size cùng một giá mà đề "từ 250.000₫" thì người bán phải mở ra
            // xem mới dám đọc, trong khi chẳng có gì để chọn.
            const giaTrenThe = (p) => {
                const con = (p.variants || []).filter((v) => Number(v.stock || 0) > 0);
                const ds = (con.length ? con : (p.variants || [])).map((v) => giaCua(p, v));
                if (!ds.length) return { gia: Number(p.sale_price || p.base_price || 0), tu: false };

                return { gia: Math.min(...ds), tu: new Set(ds).size > 1 };
            };

            /* Trạng thái của lưới: đang gõ gì, đứng ở nhóm nào, trang mấy.
             * Gom vào một chỗ để mọi nhánh đổi lưới đều đi qua đúng một đường. */
            let loc = { q: '', nhom: '', trang: 1 };
            let anhTat = false;
            try { anhTat = localStorage.getItem(KHOA_ANH) === '1'; } catch (e) { /* trình duyệt chặn */ }

            // Trang hàng đang hiện. Giữ nguyên object của server để lúc bấm thẻ còn
            // đủ danh sách phiên bản mà không phải hỏi lại.
            let dsHang = [];

            const veLuoi = () => {
                const box = $('posResults');
                box.classList.toggle('is-gon', anhTat);

                if (!dsHang.length) {
                    box.innerHTML = '<p class="pos-hint">Không có hàng nào ở đây. Đổi nhóm khác hoặc gõ tên để tìm.</p>';
                    return;
                }

                box.innerHTML = dsHang.map((p, i) => {
                    const ton = (p.variants || []).reduce((t, v) => t + Number(v.stock || 0), 0);
                    const het = ton <= 0;
                    const g = giaTrenThe(p);
                    const anh = (anhTat || !p.thumbnail)
                        ? ''
                        : `<span class="pos-card-anh"><img src="${esc(p.thumbnail)}" alt="" loading="lazy"></span>`;

                    return `<button type="button" class="pos-card${het ? ' is-het' : ''}" data-i="${i}" ${het ? 'disabled' : ''}>
                            ${anh}
                            <span class="pos-card-ten" title="${esc(p.name)}">${esc(p.name)}</span>
                            <span class="pos-card-gia">${g.tu ? 'từ ' : ''}${tien(g.gia)}</span>
                            <span class="pos-card-ton">${het ? 'Hết hàng' : 'Còn ' + ton}</span>
                        </button>`;
                }).join('');
            };

            const vePhanTrang = (meta) => {
                const box = $('posPager');
                const soTrang = Number(meta.total_pages || 0);

                box.hidden = soTrang <= 1;
                if (box.hidden) return;

                const t = Number(meta.page || 1);
                box.innerHTML = `
                    <button type="button" class="pg-btn${t <= 1 ? ' is-disabled' : ''}" data-trang="${t - 1}">Trước</button>
                    <span class="pos-pager-so">Trang ${t}/${soTrang}</span>
                    <button type="button" class="pg-btn${t >= soTrang ? ' is-disabled' : ''}" data-trang="${t + 1}">Sau</button>`;
            };

            let timer = null;
            // chamTre = đang gõ trong ô tìm, chờ 250ms cho gõ xong rồi mới hỏi server.
            const taiHang = (chamTre = false) => {
                clearTimeout(timer);

                const chay = () => {
                    const u = new URL(SEARCH_URL, location.origin);
                    u.searchParams.set('q', loc.q);
                    u.searchParams.set('page', loc.trang);
                    // Tắt ảnh thì một màn chứa được nhiều thẻ hơn, lấy luôn cho đủ trang.
                    u.searchParams.set('page_size', anhTat ? 40 : 24);
                    if (loc.nhom) u.searchParams.set('category_id', loc.nhom);

                    fetch(u, { headers: { Accept: 'application/json' } })
                        .then((r) => r.json())
                        .then((d) => {
                            dsHang = d.data || [];
                            veLuoi();
                            vePhanTrang(d.meta || {});
                        })
                        .catch(() => {
                            dsHang = [];
                            $('posResults').innerHTML = '<p class="pos-hint">Không tải được danh sách. Kiểm tra kết nối.</p>';
                            $('posPager').hidden = true;
                        });
                };

                if (chamTre) timer = setTimeout(chay, 250); else chay();
            };

            // Đổi từ khoá thì về trang 1: đứng ở trang 3 của kết quả cũ mà tìm từ mới
            // thì gần như chắc chắn nhận về một trang trống.
            const timHang = (q) => {
                loc.q = q || '';
                loc.trang = 1;
                taiHang();
            };

            $('posSearch').addEventListener('input', (e) => {
                loc.q = e.target.value;
                loc.trang = 1;
                taiHang(true);
            });

            $('posCats').addEventListener('click', (e) => {
                const nut = e.target.closest('.pos-cat');
                if (!nut) return;
                $('posCats').querySelectorAll('.pos-cat').forEach((x) => x.classList.toggle('is-on', x === nut));
                loc.nhom = nut.dataset.id;
                loc.trang = 1;
                taiHang();
            });

            $('posPager').addEventListener('click', (e) => {
                const nut = e.target.closest('[data-trang]');
                if (!nut || nut.classList.contains('is-disabled')) return;
                loc.trang = Number(nut.dataset.trang);
                taiHang();
                $('posResults').scrollTop = 0;
            });

            $('posAnhTat').checked = anhTat;
            $('posAnhTat').addEventListener('change', (e) => {
                anhTat = e.target.checked;
                try { localStorage.setItem(KHOA_ANH, anhTat ? '1' : '0'); } catch (err) { /* bỏ qua */ }
                loc.trang = 1;
                taiHang();
            });

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

                const dau = $('posResults').querySelector('.pos-card:not(:disabled)');
                if (dau) dau.click();
            });

            const themBienThe = (p, v) => them({
                id: Number(v.id),
                ten: p.name,
                opt: nhan(v),
                gia: giaCua(p, v),
                ton: Number(v.stock || 0),
            });

            // Sản phẩm đang mở hộp chọn phiên bản.
            let hangDangChon = null;

            const moChonBienThe = (p) => {
                hangDangChon = p;
                $('posVarTen').textContent = p.name;
                $('posVarDS').innerHTML = (p.variants || []).map((v, i) => {
                    const ton = Number(v.stock || 0);
                    return `<button type="button" class="pos-var" data-i="${i}" ${ton <= 0 ? 'disabled' : ''}>
                            <span class="pos-var-opt">${esc(nhan(v))}</span>
                            <span class="pos-var-meta">
                                <span class="pos-var-price">${tien(giaCua(p, v))}</span>
                                <span class="${ton <= 0 ? 'pos-var-out' : ''}">${ton <= 0 ? 'Hết hàng' : 'Còn ' + ton}</span>
                            </span>
                        </button>`;
                }).join('') || '<p class="pos-ac-empty">Sản phẩm chưa có phiên bản nào.</p>';
                $('posVarBox').hidden = false;
            };

            $('posResults').addEventListener('click', (e) => {
                const the = e.target.closest('.pos-card');
                if (!the || the.disabled) return;

                const p = dsHang[Number(the.dataset.i)];
                if (!p) return;

                const con = (p.variants || []).filter((v) => Number(v.stock || 0) > 0);
                if (con.length === 1) { themBienThe(p, con[0]); return; }
                moChonBienThe(p);
            });

            $('posVarDS').addEventListener('click', (e) => {
                const btn = e.target.closest('.pos-var');
                if (!btn || btn.disabled || !hangDangChon) return;
                themBienThe(hangDangChon, (hangDangChon.variants || [])[Number(btn.dataset.i)]);
                $('posVarBox').hidden = true;
            });

            $('posVarDong').addEventListener('click', () => { $('posVarBox').hidden = true; });

            /* ---------- Giỏ hàng ---------- */

            /* Kéo một dòng vào tầm nhìn rồi nháy nền nó một cái.
             *
             * Bấm thêm một món ĐÃ có trong giỏ thì không có dòng mới nào xuất hiện —
             * chỉ một con số ở đâu đó tăng lên, và giỏ dài thì nó nằm ngoài màn hình.
             * Người bán không thấy gì thay đổi nên bấm lại lần nữa. */
            const keoDongVaoTam = (id) => {
                const dong = $('posCart').querySelector(`.pos-line[data-dong="${id}"]`);
                if (!dong) return;

                const khung = document.querySelector('.pos-cart-wrap');
                const dauCot = document.querySelector('.pos-cart-head');
                if (khung) {
                    // Trừ chiều cao đầu cột: nó dính trên nóc nên phần dưới nó mới là
                    // vùng thật sự nhìn thấy được.
                    const che = dauCot ? dauCot.offsetHeight : 0;
                    const k = khung.getBoundingClientRect();
                    const d = dong.getBoundingClientRect();

                    if (d.top < k.top + che) {
                        khung.scrollTo({ top: khung.scrollTop + (d.top - k.top - che), behavior: 'smooth' });
                    } else if (d.bottom > k.bottom) {
                        khung.scrollTo({ top: khung.scrollTop + (d.bottom - k.bottom), behavior: 'smooth' });
                    }
                }

                // Gỡ rồi gắn lại class để lần bấm thứ hai cũng nháy, không phải chỉ lần đầu.
                dong.classList.remove('is-vua-them');
                void dong.offsetWidth;
                dong.classList.add('is-vua-them');
            };

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
                keoDongVaoTam(mon.id);
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

            /* Ruột ô "Thành tiền" của một dòng.
             *
             * Tách riêng vì có HAI chỗ cần vẽ nó: lúc dựng lại cả giỏ, và lúc người
             * bán đang gõ số lượng (chỗ đó không được dựng lại giỏ, nếu không ô đang
             * gõ bị thay và con trỏ nhảy về đầu). Hai bản sao thì sớm muộn lệch nhau. */
            const ruotThanhTien = (d) => {
                const goc = d.gia * d.sl;
                const bot = Math.round(goc * (d.giam || 0) / 100);

                return (bot > 0 ? `<span class="pos-line-was">${tien(goc)}</span>` : '') + tien(goc - bot);
            };

            // Vẽ lại đúng ô "Thành tiền" của dòng đang gõ, không đụng phần còn lại.
            const veLaiThanhTien = (o, d) => {
                const oTien = o.closest('.pos-line')?.querySelector('.pos-line-total');
                if (oTien) oTien.innerHTML = ruotThanhTien(d);
            };

            function veGio() {
                const box = $('posCart');
                box.classList.toggle('is-trong', !gio.length);
                if (!gio.length) {
                    box.innerHTML = $('posEmptyTpl').innerHTML;
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
                        <div class="pos-line" data-dong="${d.id}">
                            <span class="pos-line-name" title="${esc(d.ten)}">${esc(d.ten)}</span>
                            <span class="pos-qty">
                                <button type="button" data-sl="-1" data-id="${d.id}" aria-label="Bớt một">−</button>
                                <input type="text" inputmode="numeric" class="pos-qty-so" data-id="${d.id}"
                                       value="${d.sl}" aria-label="Số lượng">
                                <button type="button" data-sl="1" data-id="${d.id}" aria-label="Thêm một">+</button>
                            </span>
                            <span class="pos-line-unit">${tien(d.gia)}</span>
                            <span class="pos-line-total">${ruotThanhTien(d)}</span>
                            <button type="button" class="pos-line-del" data-xoa="${d.id}"
                                    title="Bỏ dòng này" aria-label="Bỏ dòng này">×</button>
                            <span class="pos-line-sub">${esc(d.opt)}</span>
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
                luuGioDangDo();
            }

            $('posCart').addEventListener('click', (e) => {
                const xoa = e.target.closest('[data-xoa]');
                if (xoa) {
                    gio = gio.filter((x) => x.id !== Number(xoa.dataset.xoa));
                    baoLoi('');
                    veGio();
                    return;
                }

                const btn = e.target.closest('button[data-sl]');
                if (btn) doiSL(Number(btn.dataset.id), Number(btn.dataset.sl));
            });

            /* Gõ thẳng số lượng. Bán 12 lon nước mà bấm nút + 12 nhát là chỗ chậm nhất
             * còn lại trong giỏ.
             *
             * Giống ô bớt giá bên dưới: cập nhật số nhưng KHÔNG vẽ lại cả giỏ, nếu
             * không ô đang gõ bị dựng lại và con trỏ nhảy về đầu sau mỗi phím. */
            $('posCart').addEventListener('input', (e) => {
                const oSL = e.target.closest('.pos-qty-so');
                if (oSL) {
                    const d = gio.find((x) => x.id === Number(oSL.dataset.id));
                    if (!d) return;

                    // Gạt sạch thứ không phải chữ số ngay tại ô — máy quét bắn nhầm
                    // vào đây, hay ngón tay chạm phím chữ, đều không được thành số lượng.
                    const sach = String(oSL.value).replace(/\D/g, '');
                    if (sach !== oSL.value) oSL.value = sach;

                    // Ô rỗng lúc đang xoá để gõ lại: giữ nguyên số cũ trong giỏ, đừng
                    // vội coi là 0 rồi xoá dòng ngay dưới tay người ta.
                    if (sach === '') return;

                    let n = Number(sach);

                    const vuot = n > d.ton;
                    if (vuot) { n = d.ton; oSL.value = n; }
                    baoLoi(vuot ? `Chỉ còn ${d.ton} sản phẩm "${d.opt}" trong kho.` : '');

                    d.sl = n;
                    veLaiThanhTien(oSL, d);
                    capNhatTien();

                    return;
                }

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
                veLaiThanhTien(o, d);
                capNhatTien();
            });

            // Rời ô thì vẽ lại giỏ để số tiền trên từng dòng khớp con số vừa kẹp.
            $('posCart').addEventListener('focusout', (e) => {
                const oSL = e.target.closest('.pos-qty-so');
                if (oSL) {
                    // Rời ô mà đang để trống hoặc 0 = bỏ dòng. Đây là lúc DUY NHẤT số 0
                    // được hiểu là xoá — trong lúc còn đang gõ thì không.
                    const d = gio.find((x) => x.id === Number(oSL.dataset.id));
                    if (d && (String(oSL.value).trim() === '' || Number(oSL.value) <= 0)) {
                        gio = gio.filter((x) => x.id !== d.id);
                    }
                    veGio();
                    return;
                }

                if (e.target.closest('.pos-cut-input')) veGio();
            });

            // Enter trong ô số lượng = xong, nhả ô ra (focusout ở trên lo phần chốt số).
            $('posCart').addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.target.closest('.pos-qty-so')) {
                    e.preventDefault();
                    e.target.blur();
                }
            });

            // Chỉ cập nhật các con số tổng — dùng khi đang gõ dở trong một ô.
            function capNhatTien() {
                const bot = botMon();
                $('posCutRow').hidden = bot <= 0;
                $('posCut').textContent = '-' + tien(bot);
                $('posTotal').textContent = tien(phaiTra());
                capNhatThoi();
                luuGioDangDo();
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
                // Xoá ô Khách đưa: số đang nằm đó là của LƯỢT TRƯỚC, mà giỏ thì vừa bị
                // thay hẳn. Để nguyên thì tiền thối tính trên một con số không liên quan,
                // và nếu số đó lớn hơn tổng mới thì nút Thu tiền vẫn sáng như thường.
                $('posTendered').value = '';
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

            /* ---------- Giữ giỏ đang dở ----------
             *
             * Khác Đơn treo ở chỗ KHÔNG ai bấm gì cả: giỏ tự được ghi lại sau mỗi thay
             * đổi, để một lần lỡ tay F5 — hoặc máy quầy sập nguồn giữa lúc khách đang
             * đứng đợi — không xoá sạch thứ vừa gõ vào.
             *
             * Cũng nằm ở localStorage của CHÍNH MÁY NÀY như Đơn treo, và cũng chỉ để
             * cứu vài phút chứ không phải chỗ giữ đơn.  */

            const luuGioDangDo = () => {
                try {
                    if (!gio.length) {
                        localStorage.removeItem(KHOA_DANG_DO);

                        return;
                    }

                    localStorage.setItem(KHOA_DANG_DO, JSON.stringify({
                        luc: Date.now(),
                        gio: gio,
                        ten: $('posCusName').value,
                        sdt: $('posCusPhone').value,
                        cusId: $('posCusId').value,
                        voucher: $('posVoucher').value,
                        ghiChu: $('posNote').value,
                    }));
                } catch (e) {
                    // Bộ nhớ đầy hoặc bị chặn. Im lặng bỏ qua: đây là lưới an toàn,
                    // không phải một bước của việc bán hàng.
                }
            };

            const khoiPhucGioDangDo = () => {
                let d = null;
                try {
                    d = JSON.parse(localStorage.getItem(KHOA_DANG_DO) || 'null');
                } catch (e) {
                    d = null;
                }
                if (!d || !Array.isArray(d.gio) || !d.gio.length) return;

                try { localStorage.removeItem(KHOA_DANG_DO); } catch (e) { /* bỏ qua */ }

                // Để quên quá lâu thì đẩy sang Đơn treo thay vì bày ra như giỏ đang
                // làm dở: giỏ của ca hôm trước mà hiện lên như vừa gõ xong là mời
                // người ta bấm Thu tiền. Vẫn không mất gì, chỉ là phải mở ra lấy.
                if (Date.now() - Number(d.luc || 0) > HAN_GIU_GIO_MS) {
                    const ds = docTreo();
                    ds.unshift({
                        id: Date.now(),
                        luc: new Date(Number(d.luc) || Date.now()).toLocaleString('vi-VN', {
                            hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit',
                        }),
                        ten: d.ten || '',
                        sdt: d.sdt || '',
                        cusId: d.cusId || '',
                        voucher: d.voucher || '',
                        ghiChu: d.ghiChu || '',
                        gio: d.gio,
                    });
                    ghiTreo(ds.slice(0, 20));

                    return;
                }

                gio = d.gio;
                $('posCusName').value = d.ten || '';
                $('posCusPhone').value = d.sdt || '';
                $('posCusId').value = d.cusId || '';
                $('posVoucher').value = d.voucher || '';
                $('posNote').value = d.ghiChu || '';
                $('posCusTag').textContent = d.ten || 'khách lẻ';
            };

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

            /* Ba con số đáng bấm nhất, tính từ tổng đơn: trả đúng, đưa chẵn chục
             * nghìn, đưa chẵn trăm nghìn. Trùng nhau thì bỏ bớt — tổng 400.000₫ chỉ
             * cần một nút, ba nút giống hệt nhau chỉ tổ làm người ta phải đọc. */
            const veGoiYTien = () => {
                const box = $('posGoiY');
                const tong = phaiTra();

                box.hidden = !(hinhThuc === 'cash' && tong > 0);
                if (box.hidden) { box.innerHTML = ''; return; }

                const ds = [...new Set([
                    tong,
                    Math.ceil(tong / 10000) * 10000,
                    Math.ceil(tong / 100000) * 100000,
                ])];

                box.innerHTML = ds.map((v, i) => `
                    <button type="button" data-dat="${v}">${i === 0 ? 'Đủ tiền · ' : ''}${tien(v)}</button>
                `).join('');
            };

            $('posGoiY').addEventListener('click', (e) => {
                const btn = e.target.closest('[data-dat]');
                if (!btn) return;
                const o = $('posTendered');
                o.value = new Intl.NumberFormat('vi-VN').format(Number(btn.dataset.dat));
                capNhatThoi();
                o.focus();
            });

            $('posCash').addEventListener('click', (e) => {
                const btn = e.target.closest('.pos-note-btn');
                if (!btn) return;
                const o = $('posTendered');
                o.value = new Intl.NumberFormat('vi-VN').format(soTien(o.value) + Number(btn.dataset.add));
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
                    $('posChange').classList.toggle('is-thieu', thoi < 0);
                }

                const thieu = hinhThuc === 'cash' && dua > 0 && thoi < 0;
                $('posSubmit').disabled = !gio.length || thieu;
                $('posSubmitAmt').textContent = gio.length ? tien(tong) : '';
                veGoiYTien();
                veHangMa();
            }

            /* Dòng mã giảm giá chỉ hiện khi khách có xuất trình mã, và vế phải là CHỮ
             * chứ không phải số: mức giảm do API tính lại trên giá tại thời điểm bán,
             * đoán trước ở đây là đọc cho khách nghe một con số có thể sai. */
            const veHangMa = () => {
                const ma = String($('posVoucher').value || '').trim().toUpperCase();
                $('posVoucherRow').hidden = ma === '';
                $('posVoucherMa').textContent = ma;
            };

            $('posVoucher').addEventListener('input', veHangMa);

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
                    // KHÔNG nói "đơn chưa được ghi": mạng đứt sau khi server đã ghi
                    // xong cũng rơi vào đây, mà câu đó thì mời người bán bấm lại lần nữa
                    // và thu tiền khách hai lượt.
                    baoLoi('Mất kết nối giữa chừng — CHƯA rõ đơn đã được ghi hay chưa. '
                        + 'Mở Lịch sử đơn kiểm tra trước khi bán lại.');
                } finally {
                    dangGui = false;
                    capNhatThoi();
                }
            });

            /* ---------- Phiếu sau khi bán ---------- */

            function xongPhieu(d) {
                // Xoá bản giữ giỏ NGAY khi server báo bán xong, không đợi bấm "Bán tiếp".
                // Đóng trình duyệt lúc đang xem phiếu mà bản giữ còn nằm đó thì lần mở
                // sau nó sống lại y như một giỏ đang làm dở — và giỏ đó đã bán rồi.
                try { localStorage.removeItem(KHOA_DANG_DO); } catch (e) { /* bỏ qua */ }

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

                // Đưa hình thức về Tiền mặt. Không đưa về thì khách sau vẫn đang đứng ở
                // Chuyển khoản của khách trước: người bán nhận tiền mặt, bấm Thu tiền,
                // và đơn đó được ghi là chuyển khoản. Sổ quỹ chỉ đếm tiền mặt, nên cuối
                // ca đếm két sẽ THỪA đúng bằng số tiền đó mà không ai biết thừa từ đâu.
                //
                // Bấm hẳn vào nút thay vì tự gán biến: nút còn phải đổi lớp is-on và
                // bật lại khối nhận tiền, gán tay thì sớm muộn quên một trong hai.
                const nutMacDinh = $('posPayTabs').querySelector('.pos-tab[data-method="cash"]')
                    || $('posPayTabs').querySelector('.pos-tab');
                if (nutMacDinh && !nutMacDinh.classList.contains('is-on')) nutMacDinh.click();

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
                if (!$('posVarBox').hidden && e.key === 'Escape') {
                    $('posVarBox').hidden = true;
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
            khoiPhucGioDangDo();
            veGio();
            taiHang();
            tairCa();
        })();
    </script>
@endsection
