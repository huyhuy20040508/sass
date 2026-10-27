{{--
    Phương thức thanh toán — khách trả tiền gia hạn vào đâu.

    FORM DỰNG TỪ `fields` DO API TRẢ VỀ (registry trong
    service/cau_hinh_nen_tang_service.go), không chép lại bảng khoá ở đây: thêm
    một ô cấu hình bên Go là trang này tự có thêm ô, không phải sửa Blade.

    Bên phải là BẢN XEM TRƯỚC đúng thứ chủ tiệm sẽ thấy trên trang gia hạn của
    họ. Nó có mặt vì một lý do cụ thể: mẫu nội dung chuyển khoản chứa
    {ma_cua_hang}, và nhìn chuỗi mẫu thì không ai đọc ra được câu cuối cùng
    khách phải gõ vào app ngân hàng. Xem trước thay {ma_cua_hang} bằng một mã
    thật để thấy ngay.

    KHÔNG có ô khoá API của cổng thanh toán, và đó là chủ ý — xem migration 0015:
    bảng này nằm nguyên văn trong mọi bản sao lưu database.
--}}
@extends('layouts.app')
@section('title', 'Phương thức thanh toán')

@section('content')
    @php
        // Giá trị đang hiển thị: ưu tiên thứ vừa gõ (khi lưu hỏng quay lại), sau
        // đó mới tới giá trị đọc từ API. Thiếu vế đầu thì một lần bấm Lưu bị từ
        // chối là xoá sạch những gì vừa điền.
        $val = fn (string $key) => (string) old('items.'.$key, $values[$key] ?? '');

        $bat = $val('ck_bat') === '1';
        $mau = $val('ck_noi_dung_mau');
        // Mã cửa hàng ví dụ cho bản xem trước. Chuỗi cố định, KHÔNG lấy một khách
        // thật: xem trước là để đọc hình dạng câu, không phải để tra cứu ai đó.
        $noiDungMau = str_replace('{ma_cua_hang}', 'CUAHANG01', $mau);
    @endphp

    <div class="page-head">
        <span class="eyebrow">Cài đặt nhà cung cấp</span>
        <h2>Phương thức thanh toán</h2>
        <p>Thông tin khách đọc được trên trang gia hạn của họ. Đây là cấu hình của chính mình, dùng chung cho mọi phần mềm và mọi khách hàng.</p>
    </div>

    @if ($loi)
        <div class="notice">{{ $loi }}</div>
    @endif

    <div class="ct-grid">
        <form method="POST" action="{{ route('platform.cai-dat.thanh-toan.luu') }}" class="panel">
            @csrf
            <div class="panel-head">Hình thức nhận tiền</div>
            <div class="panel-body">
                @php
                    // MỖI CÔNG TẮC LÀ MỘT NHÓM, và nhóm dựng từ `cong_tac` do API trả
                    // về — không xếp tay ở đây. Thêm một hình thức thanh toán bên Go
                    // là trang này tự mọc thêm một khối, không phải sửa Blade.
                    $nhom = collect($fields)->where('type', 'bool')->map(fn ($ct) => [
                        'congTac' => $ct,
                        'oNhap' => collect($fields)->where('cong_tac', data_get($ct, 'key'))->values(),
                    ])->values();

                    // Tiêu đề khối khai — nói rõ đang khai CÁI GÌ, khác với công tắc
                    // (nói có dùng hình thức đó hay không).
                    $tenKhoi = [
                        'ck_bat' => 'Thông tin tài khoản nhận tiền',
                        'payos_bat' => 'Khoá kết nối PayOS',
                    ];

                    // Lượt lưu vừa bị từ chối thì MỞ HẾT: gập lại đúng lúc báo lỗi thì
                    // người dùng không thấy ô nào đang sai.
                    $vuaLoi = ! empty(old('items'));
                @endphp

                @foreach ($nhom as $g)
                    @php
                        $khoaBat = data_get($g['congTac'], 'key');
                        $dangBat = $val($khoaBat) === '1';
                        $moSan = $dangBat || $vuaLoi;
                        $coBiMat = collect($g['oNhap'])->contains(fn ($f) => data_get($f, 'bi_mat'));
                    @endphp

                    <div class="ct-nhom">
                        {{-- Công tắc đứng NGOÀI khối gập: nó quyết định cả khối bên dưới
                             có nghĩa hay không, nên gập nó theo chính khối đó thì tắt
                             xong là mất luôn chỗ bật lại.

                             hidden "0" đứng TRƯỚC ô tích: checkbox không gửi gì khi bỏ
                             chọn, nên thiếu dòng này thì "tắt" không bao giờ tới được
                             máy chủ — người dùng bấm tắt, bấm lưu, và nó vẫn bật. --}}
                        <input type="hidden" name="items[{{ $khoaBat }}]" value="0">
                        <label class="ct-switch">
                            <input type="checkbox" name="items[{{ $khoaBat }}]" value="1"
                                   @checked($dangBat) data-ct-bat="{{ $khoaBat }}">
                            <span>
                                <b>{{ data_get($g['congTac'], 'label') }}</b>
                                @if ($gy = data_get($g['congTac'], 'goi_y'))<em>{{ $gy }}</em>@endif
                            </span>
                        </label>

                        {{-- ===== Khối khai, GẬP ĐƯỢC =====

                             Khai xong một lần rồi thì mấy ô này gần như không đụng tới
                             nữa, nhưng chúng vẫn chiếm nguyên chiều cao trang. Gập lại
                             thì hai công tắc, hai dòng tóm tắt và nút Lưu nằm gọn trong
                             một màn hình.

                             KHÔNG KHOÁ khi công tắc tắt — khác .set-block bên trang Cài
                             đặt của Shop Admin: ở đây bật hình thức lên mà chưa khai đủ
                             thì API TỪ CHỐI lưu, nên người dùng buộc phải khai TRƯỚC
                             hoặc cùng lúc với việc bật.

                             Ô nhập vẫn nằm nguyên trong form khi gập, nên gập rồi bấm
                             Lưu KHÔNG mất dữ liệu đã khai. --}}
                        <div class="ct-block {{ $moSan ? 'is-open' : '' }}" data-ct-block>
                            <button type="button" class="ct-block-head" data-ct-head
                                    aria-expanded="{{ $moSan ? 'true' : 'false' }}"
                                    aria-controls="ct-body-{{ $khoaBat }}">
                                <span class="ct-caret" aria-hidden="true"></span>
                                <span class="ct-block-title">{{ $tenKhoi[$khoaBat] ?? 'Thông tin cấu hình' }}</span>
                                {{-- Tóm tắt: gập một khối thành một dòng trống thì lần
                                     sau phải mở ra mới nhớ mình đã điền gì. --}}
                                <span class="ct-block-sum" data-ct-sum></span>
                            </button>

                            <div class="ct-collapse" id="ct-body-{{ $khoaBat }}">
                                <div class="ct-collapse-inner">
                                    @if ($coBiMat && ! $khoaMaHoa)
                                        {{-- Nói TRƯỚC khi người ta gõ khoá PayOS vào rồi bấm
                                             Lưu và nhận lỗi. Máy chủ vẫn từ chối ghi (không
                                             có chỗ mã hoá thì không cất bí mật), đây chỉ là
                                             báo sớm. --}}
                                        <p class="ct-canhbao">
                                            Máy chủ chưa khai <b>PLATFORM_SECRET_KEY</b> nên chưa cất được khoá bí mật.
                                            Thêm khoá đó vào <span class="mono">api/.env</span> rồi khởi động lại API.
                                        </p>
                                    @endif

                                    @foreach ($g['oNhap'] as $f)
                                        @php
                                            $key = data_get($f, 'key');
                                            $type = data_get($f, 'type');
                                            $nhan = data_get($f, 'label');
                                            $goiY = data_get($f, 'goi_y');
                                            $batBuoc = (bool) data_get($f, 'bat_buoc_khi_bat');
                                            $biMat = (bool) data_get($f, 'bi_mat');
                                            $max = (int) data_get($f, 'max');
                                            // Ô bí mật: API chỉ trả BẢN CHE, và bản che KHÔNG
                                            // được đổ vào value — gửi ngược lên là ghi đè khoá
                                            // thật bằng mấy chấm tròn. Nó đi vào placeholder,
                                            // còn ô thì để trống.
                                            $daKhai = $biMat && $val($key) !== '';
                                        @endphp

                                        <div class="ct-field">
                                            <label class="f-label" for="ct-{{ $key }}">
                                                {{ $nhan }}
                                                {{-- Sao đỏ chỉ có nghĩa KHI hình thức tương ứng
                                                     đang bật — tắt đi thì bỏ trống là hợp lệ. --}}
                                                @if ($batBuoc)
                                                    <span class="req" data-ct-req @unless($dangBat) hidden @endunless>*</span>
                                                @endif
                                            </label>

                                            @if ($type === 'textarea')
                                                <textarea class="form-control" id="ct-{{ $key }}" name="items[{{ $key }}]"
                                                          rows="3" @if ($max) maxlength="{{ $max }}" @endif>{{ $val($key) }}</textarea>
                                            @elseif ($biMat)
                                                <input type="text" class="form-control mono"
                                                       id="ct-{{ $key }}" name="items[{{ $key }}]"
                                                       value="" autocomplete="off" spellcheck="false"
                                                       @if ($max) maxlength="{{ $max }}" @endif
                                                       placeholder="{{ $daKhai ? $val($key).' — để trống nếu không đổi' : 'Chưa khai' }}">
                                            @else
                                                <input type="text" class="form-control {{ $key === 'ck_so_tai_khoan' ? 'mono' : '' }}"
                                                       id="ct-{{ $key }}" name="items[{{ $key }}]"
                                                       value="{{ $val($key) }}" autocomplete="off"
                                                       @if ($max) maxlength="{{ $max }}" @endif
                                                       @if ($key === 'ck_noi_dung_mau') data-ct-mau @endif
                                                       @if ($key === 'ck_anh_qr') data-ct-qr @endif>
                                            @endif

                                            @if ($goiY)<p class="ct-hint">{{ $goiY }}</p>@endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                @if ($ghiDuoc)
                    <div class="ct-actions">
                        <button type="submit" class="btn btn-plum">Lưu cấu hình</button>
                    </div>
                @else
                    {{-- Vai trò chỉ đọc: nói rõ vì sao không có nút Lưu, thay vì để một
                         form bấm mãi không lưu được. --}}
                    <p class="ct-hint ct-readonly">Vai trò của bạn chỉ được xem. Nhờ owner hoặc operator sửa giúp.</p>
                @endif
            </div>
        </form>

        {{-- ===== Bản xem trước ===== --}}
        <aside class="panel ct-preview">
            <div class="panel-head">Khách sẽ thấy</div>
            <div class="panel-body">
                {{-- PayOS đứng TRƯỚC: bấm một nút rồi xong luôn là đường khách chọn
                     trước chuyển khoản tay, nên xem trước xếp đúng thứ tự đó thay vì
                     thứ tự trong form. --}}
                <div class="ct-card ct-card-payos" data-ct-card="payos_bat" @unless($val('payos_bat') === '1') hidden @endunless>
                    <p class="ct-card-title">Thanh toán online</p>
                    <p class="ct-guide">
                        Khách bấm một nút, trả bằng QR ngân hàng hoặc ví, và hạn hợp đồng được đẩy ngay khi
                        PayOS báo về — không ai phải nhìn sao kê.
                    </p>
                </div>

                <div class="ct-card" data-ct-card="ck_bat" @unless($bat) hidden @endunless>
                    <p class="ct-card-title">Chuyển khoản ngân hàng</p>
                    <dl class="ct-rows">
                        <div><dt>Ngân hàng</dt><dd data-ct-out="ck_ngan_hang_ten">{{ $val('ck_ngan_hang_ten') ?: '—' }}</dd></div>
                        <div><dt>Số tài khoản</dt><dd class="mono" data-ct-out="ck_so_tai_khoan">{{ $val('ck_so_tai_khoan') ?: '—' }}</dd></div>
                        <div><dt>Chủ tài khoản</dt><dd data-ct-out="ck_chu_tai_khoan">{{ $val('ck_chu_tai_khoan') ?: '—' }}</dd></div>
                        <div><dt>Nội dung</dt><dd class="mono" data-ct-noidung>{{ $noiDungMau ?: '—' }}</dd></div>
                    </dl>

                    @if ($val('ck_anh_qr'))
                        <img class="ct-qr" src="{{ $val('ck_anh_qr') }}" alt="Mã QR" data-ct-qr-img>
                    @else
                        <div class="ct-qr-empty" data-ct-qr-img hidden></div>
                    @endif

                    @if ($val('ck_huong_dan'))
                        <p class="ct-guide">{{ $val('ck_huong_dan') }}</p>
                    @endif

                    <p class="ct-note">
                        Nội dung trên dùng mã cửa hàng ví dụ <b>CUAHANG01</b>. Mỗi khách thấy mã của chính họ —
                        đó là thứ để đối chiếu sao kê khi tiền vào.
                    </p>
                </div>

                {{-- Tắt HẾT mọi hình thức: nói ra hậu quả, vì đó là một quyết định kinh
                     doanh chứ không phải một ô tích. --}}
                <p class="ct-off" data-ct-off @if($bat || $val('payos_bat') === '1') hidden @endif>
                    Đang tắt hết. Trang gia hạn của khách không hiện cách trả tiền nào, họ phải liên hệ để hỏi.
                </p>
            </div>
        </aside>
    </div>

    <style>
        /* Hai cột: form trái, xem trước phải. Xem trước là thứ ĐỌC trong lúc gõ ở
           cột kia, nên nó phải đứng cạnh chứ không nằm dưới. */
        .ct-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(280px, 1fr); gap: 20px; align-items: start; }
        @media (max-width: 1080px) { .ct-grid { grid-template-columns: 1fr; } }

        .ct-field { margin-top: 16px; }
        .ct-field:first-of-type { margin-top: 0; }
        .ct-field .form-control {
            width: 100%; height: 36px; padding: 0 12px;
            font-size: 13px; border-radius: 4px;
            border: 1px solid var(--rule); background: var(--surface); color: var(--ink);
        }
        .ct-field textarea.form-control { height: auto; padding: 8px 12px; line-height: 1.55; }
        .ct-hint { margin: 5px 0 0; font-size: 12px; line-height: 1.55; color: var(--ink-3); }
        .ct-readonly { margin-top: 18px; }

        /* Công tắc: dòng đầu tiên của form, có nền riêng để tách khỏi các ô nhập —
           nó quyết định cả khối bên dưới có nghĩa hay không. */
        .ct-switch {
            display: flex; align-items: flex-start; gap: 10px;
            margin-bottom: 20px; padding: 12px 14px;
            border: 1px solid var(--rule-soft); border-radius: 4px; background: var(--surface-2, #fafafa);
            cursor: pointer;
        }
        .ct-switch input { width: 15px; height: 15px; margin: 2px 0 0; accent-color: var(--plum); cursor: pointer; }
        .ct-switch b { display: block; font-size: 13px; font-weight: 600; color: var(--ink); }
        .ct-switch em { display: block; margin-top: 3px; font-size: 12px; font-style: normal; color: var(--ink-3); }

        .ct-actions { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--rule-soft); }

        /* Hai nhóm hình thức đứng liền nhau: kẻ một đường và cho khoảng thở, không
           thì công tắc thứ hai đọc như một ô tích của khối phía trên. */
        .ct-nhom + .ct-nhom { margin-top: 26px; padding-top: 22px; border-top: 1px solid var(--rule); }

        /* Thiếu khoá mã hoá: cảnh báo nằm NGAY TRONG khối cần nó, không phải một
           dải đỏ ở đầu trang — người đang khai khoá PayOS mới là người cần đọc. */
        .ct-canhbao {
            margin: 0 0 16px; padding: 10px 12px; border-radius: 4px;
            background: #fff6e8; border: 1px solid #f0d9b5;
            font-size: 12.5px; line-height: 1.6; color: #7a4b12;
        }

        .ct-card + .ct-card { margin-top: 12px; }
        .ct-card-payos .ct-guide { margin-top: 0; }


        /* ----- Khối gập được ----- */
        .ct-block { border-top: 1px solid var(--rule-soft); margin-top: 4px; }
        .ct-block-head {
            display: flex; align-items: center; gap: 8px; width: 100%;
            padding: 14px 0 0; border: 0; background: none; cursor: pointer;
            font-family: inherit; text-align: left;
        }
        .ct-block-head:focus-visible { outline: 2px solid var(--plum); outline-offset: 3px; border-radius: 3px; }
        .ct-block-title { font-size: 13px; font-weight: 600; color: var(--ink); }
        /* Tóm tắt đẩy sang phải và tự cắt: nó là thông tin phụ, không được đẩy tiêu
           đề khối ra khỏi hàng khi số tài khoản dài. */
        .ct-block-sum {
            flex: 1; min-width: 0; text-align: right;
            font-size: 12px; color: var(--ink-3);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        /* Mũi tên vẽ bằng hai cạnh một ô vuông xoay 45°, cùng cách với .rail-caret
           của thanh trái — hai chỗ gập/mở trong cùng một app thì phải cùng ký hiệu. */
        .ct-caret {
            width: 6px; height: 6px; flex: 0 0 6px;
            border-right: 1.5px solid var(--ink-3);
            border-bottom: 1.5px solid var(--ink-3);
            transform: translateY(-2px) rotate(-45deg);
            transition: transform .18s ease;
        }
        .ct-block.is-open .ct-caret { transform: translateY(-2px) rotate(45deg); }

        /* Trượt ra/vào bằng grid-template-rows: 0fr -> 1fr — cách duy nhất chạy mượt
           với nội dung cao bao nhiêu cũng được mà không phải đo bằng script. Trình
           duyệt cũ không chạy hiệu ứng thì vẫn đóng/mở đúng, chỉ là nhảy cái. */
        .ct-collapse {
            display: grid; grid-template-rows: 0fr; opacity: 0;
            transition: grid-template-rows .22s ease, opacity .16s ease;
        }
        .ct-block.is-open .ct-collapse { grid-template-rows: 1fr; opacity: 1; }
        /* visibility: ô nhập trong khối đang gập vẫn nằm trong form (để không mất dữ
           liệu đã khai) nhưng KHÔNG được nhận con trỏ khi bấm Tab — gõ vào một ô
           không nhìn thấy là kiểu lỗi không ai lần ra được. Đổi trễ đúng bằng thời
           gian gập để nội dung không biến mất giữa chừng lúc đang trượt vào. */
        .ct-collapse-inner {
            overflow: hidden; min-height: 0;
            visibility: hidden; transition: visibility 0s linear .22s;
        }
        .ct-block.is-open .ct-collapse-inner { visibility: visible; transition: visibility 0s; }
        .ct-block.is-open .ct-collapse-inner { padding-top: 16px; }

        @media (prefers-reduced-motion: reduce) {
            .ct-collapse, .ct-caret { transition: none; }
        }

        /* ----- Xem trước ----- */
        .ct-card { border: 1px solid var(--rule); border-radius: 4px; padding: 16px; }
        .ct-card-title { margin: 0 0 12px; font-size: 13px; font-weight: 600; }
        .ct-rows { margin: 0; display: flex; flex-direction: column; gap: 9px; }
        .ct-rows > div { display: flex; align-items: baseline; justify-content: space-between; gap: 14px; }
        .ct-rows dt { font-size: 12px; color: var(--ink-3); }
        .ct-rows dd { margin: 0; font-size: 13px; font-weight: 500; text-align: right; overflow-wrap: anywhere; }
        .ct-qr { display: block; width: 148px; height: 148px; object-fit: contain; margin: 14px auto 0; }
        .ct-guide { margin: 12px 0 0; font-size: 12.5px; line-height: 1.6; color: var(--ink-2); white-space: pre-line; }
        .ct-note { margin: 12px 0 0; font-size: 11.5px; line-height: 1.6; color: var(--ink-3); }
        .ct-off { margin: 0; font-size: 12.5px; line-height: 1.6; color: var(--ink-3); }
    </style>

    <script>
        (function () {
            var form = document.querySelector('.ct-grid form');
            if (!form) return;

            // ----- Bản xem trước, cập nhật NGAY trong lúc gõ -----
            // Không phải hiệu ứng: mẫu nội dung chuyển khoản chứa {ma_cua_hang}, và
            // câu cuối cùng khách gõ vào app ngân hàng chỉ đọc ra được sau khi thay
            // chỗ đó bằng một mã thật.
            function noiDung() {
                var o = form.querySelector('[data-ct-mau]');
                var ra = document.querySelector('[data-ct-noidung]');
                if (!o || !ra) return;
                ra.textContent = (o.value || '').replace('{ma_cua_hang}', 'CUAHANG01') || '—';
            }

            // Câu "đang tắt hết" chỉ đúng khi KHÔNG còn hình thức nào bật. Trước đây
            // nó buộc vào mỗi công tắc chuyển khoản, nên bật PayOS mà tắt chuyển
            // khoản là màn hình nói sai.
            function veKhoiTat() {
                var tat = document.querySelector('[data-ct-off]');
                if (!tat) return;
                var coBat = Array.prototype.some.call(
                    form.querySelectorAll('[data-ct-bat]'),
                    function (o) { return o.checked; }
                );
                tat.hidden = coBat;
            }

            // ----- Một nhóm = một công tắc + một khối gập -----
            Array.prototype.forEach.call(form.querySelectorAll('[data-ct-bat]'), function (bat) {
                var nhom = bat.closest('.ct-nhom');
                if (!nhom) return;

                var khoi = nhom.querySelector('[data-ct-block]');
                var dau = nhom.querySelector('[data-ct-head]');
                var tomTat = nhom.querySelector('[data-ct-sum]');
                var the = document.querySelector('[data-ct-card="' + bat.getAttribute('data-ct-bat') + '"]');

                function veTomTat() {
                    if (!tomTat) return;
                    // Hai giá trị đầu tiên đã khai: đủ để nhận ra đang khai gì mà không
                    // biến dòng tóm tắt thành bản sao của cả khối. Ô BÍ MẬT lấy theo
                    // placeholder (bản che) — giá trị thật không bao giờ có ở đây.
                    var phan = [];
                    Array.prototype.forEach.call(nhom.querySelectorAll('input[name^="items["]'), function (o) {
                        if (o.type === 'checkbox' || o.type === 'hidden' || phan.length >= 2) return;
                        var v = o.value.trim();
                        if (!v && o.placeholder && o.placeholder.indexOf('•') === 0) {
                            v = o.placeholder.split(' — ')[0];
                        }
                        if (v) phan.push(v);
                    });
                    // Chỉ hiện khi ĐANG GẬP: mở ra rồi thì mấy giá trị đó nằm ngay
                    // dưới, lặp lại trên tiêu đề chỉ là tiếng ồn.
                    var dangMo = khoi && khoi.classList.contains('is-open');
                    tomTat.textContent = (!dangMo && phan.length) ? phan.join(' · ') : '';
                }

                function doiGap(mo) {
                    if (!khoi || !dau) return;
                    khoi.classList.toggle('is-open', mo);
                    dau.setAttribute('aria-expanded', mo ? 'true' : 'false');
                    veTomTat();
                }

                function doiHien() {
                    if (the) the.hidden = !bat.checked;
                    // Sao đỏ chỉ có nghĩa khi hình thức này đang bật — tắt đi thì bỏ
                    // trống là hợp lệ.
                    Array.prototype.forEach.call(nhom.querySelectorAll('[data-ct-req]'), function (sao) {
                        sao.hidden = !bat.checked;
                    });
                    veKhoiTat();
                }

                if (dau) {
                    dau.addEventListener('click', function () {
                        doiGap(!khoi.classList.contains('is-open'));
                    });
                }

                bat.addEventListener('change', function () {
                    doiHien();
                    // Vừa bật lên thì mở khối ra luôn: bật xong mà không khai gì là
                    // lượt Lưu sau đó bị API từ chối, và người dùng không thấy ô nào
                    // để sửa.
                    if (bat.checked) doiGap(true);
                });

                nhom.addEventListener('input', function (e) {
                    var o = e.target;
                    if (!o.name) return;

                    var khoa = (o.name.match(/^items\[(.+)\]$/) || [])[1];
                    var ra = khoa && document.querySelector('[data-ct-out="' + khoa + '"]');
                    if (ra) ra.textContent = o.value.trim() || '—';

                    if (o.hasAttribute('data-ct-mau')) noiDung();

                    if (o.hasAttribute('data-ct-qr')) {
                        var anh = document.querySelector('[data-ct-qr-img]');
                        if (anh && anh.tagName === 'IMG') {
                            anh.src = o.value.trim();
                            anh.hidden = !o.value.trim();
                        }
                    }

                    // Dòng tóm tắt đi theo ô đang gõ, để gập lại là thấy ngay giá trị
                    // vừa sửa chứ không phải giá trị của lần tải trang.
                    veTomTat();
                });

                doiHien();
                veTomTat();
            });
        })();
    </script>
@endsection
