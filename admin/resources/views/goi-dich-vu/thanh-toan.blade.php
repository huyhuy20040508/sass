@extends('layouts.app')

@section('title', 'Thanh toán gia hạn')

@section('content')
    {{--
        Trang thanh toán một đơn gia hạn.

        KHÁCH TRẢ TIỀN NGAY TẠI ĐÂY, không bị đá sang trang của cổng: mã QR vẽ
        từ chuỗi VietQR do cổng trả về, kèm bản chữ (ngân hàng · số tài khoản ·
        nội dung) cho ai không quét được. Cổng vẫn còn một đường lui ở cuối trang
        cho khách muốn trả bằng ví hoặc thẻ.

        Ba thứ một trang thanh toán bắt buộc phải có, và đây là lý do từng cái:

          · ĐỒNG HỒ ĐẾM NGƯỢC — link có hạn thật (24 giờ). Không hiện thì khách
            rời đi rồi quay lại sau hai ngày, quét mã cũ, và tiền vào một link đã
            chết.
          · NỘI DUNG CHUYỂN KHOẢN nổi bật kèm nút chép — đây là thứ DUY NHẤT nối
            một lần tiền vào với một đơn. Gõ sai thì tiền vào tài khoản mà không
            đơn nào được chốt, và phải đối soát tay.
          · TRẠNG THÁI TỰ CẬP NHẬT — trang hỏi lại mỗi 4 giây, và chính lượt hỏi
            đó là thứ chốt đơn khi webhook không tới được máy chủ (máy chạy ở
            localhost, hoặc webhook trượt). Xem GiaHanService.TrangThai.
    --}}
    @php
        // ĐỌC ĐƠN QUA MỘT HÀM DUY NHẤT, không chạm thẳng $don['...'].
        //
        // Trang này đọc JSON của một máy chủ CÓ THỂ CŨ HƠN nó: khai một trường mới
        // bên Go rồi triển khai giao diện trước là mọi lượt truy cập nổ "Undefined
        // array key" — mà đây là trang khách đang giữa chừng trả tiền, một màn hình
        // 500 ở đây tệ hơn hẳn ở bất kỳ trang nào khác.
        //
        // Thiếu trường thì trang vẫn dựng, chỉ khuyết đúng dòng đó.
        $o = fn (string $khoa, $macDinh = '') => $don[$khoa] ?? $macDinh;

        $tien = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
        $daTra = (bool) $o('da_tra', false);
        $trangThai = (string) $o('trang_thai');
        // Đơn đã đóng (huỷ / hết hạn link): không trả được nữa. Trang phải nói ra
        // thay vì hiện một mã QR dẫn tiền vào chỗ không ai nhận.
        $daDong = in_array($trangThai, ['huy', 'het_han'], true);

        $ngayGio = function ($iso) {
            if (empty($iso)) {
                return '';
            }
            try {
                return \Illuminate\Support\Carbon::parse($iso)->format('H:i d/m/Y');
            } catch (\Throwable $e) {
                return '';
            }
        };

        // Số giây còn lại của link, tính ở MÁY CHỦ: đồng hồ máy khách lệch giờ là
        // chuyện thường, và một cái đếm ngược sai thì tệ hơn không có.
        $giayConLai = null;
        if (! $o('het_han_luc') === '') {
            try {
                $giayConLai = (int) round(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($o('het_han_luc')), false));
            } catch (\Throwable $e) {
                $giayConLai = null;
            }
        }

        $qr = (string) ($o('qr_code'));
        $soTK = (string) ($o('so_tai_khoan'));
        $noiDung = (string) ($o('noi_dung'));

        // ĐƠN KHÔNG CÓ THÔNG TIN TRẢ TIỀN.
        //
        // Xảy ra với đơn tạo bởi bản máy chủ CŨ (trước khi đơn biết lưu chuỗi QR
        // — xem migration 0017), hoặc khi cổng không trả về QR. Lúc đó trang này
        // không tự dựng được màn hình trả tiền, và cách duy nhất còn lại là trang
        // của cổng.
        //
        // Phải nói thẳng và đưa nút CHÍNH sang đó. Không có nhánh này thì khách
        // nhìn một cột "chuyển khoản thủ công" trống trơn, chỉ còn một đường dẫn
        // nhỏ ở cuối trang — trông như trang hỏng.
        $thieuThongTin = $qr === '' && $soTK === '';
    @endphp

    <div class="tt">
        {{-- ===== Đầu trang ===== --}}
        <div class="tt-head">
            <div>
                <p class="tt-eyebrow">Đơn #{{ $o('ma_don') ?? $id }}</p>
                <h1 class="tt-title">Thanh toán gia hạn</h1>
            </div>
            <a class="tt-back" href="{{ route('admin.goi-dich-vu.index') }}">← Về trang gói dịch vụ</a>
        </div>

        @if(!empty($error))
            <p class="tt-alert">{{ $error }}</p>
        @endif

        @if($don)
            {{-- ===== ĐÃ TRẢ XONG ===== --}}
            <div class="tt-done" data-tt-xong @unless($daTra) hidden @endunless>
                <span class="tt-done-icon">
                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4 10-10"/></svg>
                </span>
                <h2 class="tt-done-title">Đã gia hạn xong</h2>
                {{-- Chú ý: KHÔNG viết "@if" dính ngay sau một chữ cái ("tháng@if").
                     Blade chỉ nhận directive khi trước dấu @ không phải ký tự chữ,
                     nên dạng dính liền lọt qua nguyên văn còn @endif của nó thì đóng
                     nhầm khối bên ngoài — cả trang thành lỗi cú pháp PHP. --}}
                <p class="tt-done-sub">
                    Hợp đồng được cộng thêm {{ $o('so_thang') }} tháng.
                    @if($o('han_moi') !== '')
                        Hạn mới tới <b data-tt-han>{{ \Illuminate\Support\Carbon::parse($o('han_moi'))->format('d/m/Y') }}</b>.
                    @endif
                    Toàn bộ chức năng của phần mềm đã mở lại.
                </p>
                <div class="tt-done-acts">
                    <a class="tt-btn" href="{{ route('admin.dashboard') }}">Vào làm việc</a>
                    <a class="tt-btn-ghost" href="{{ route('admin.goi-dich-vu.index') }}">Xem gói dịch vụ</a>
                </div>
            </div>

            {{-- ===== ĐƠN ĐÃ ĐÓNG ===== --}}
            @if($daDong && ! $daTra)
                <div class="tt-done">
                    <h2 class="tt-done-title">Đơn này đã hết hiệu lực</h2>
                    <p class="tt-done-sub">
                        Link thanh toán đã hết hạn hoặc bị huỷ. Bạn chưa bị trừ tiền — quay lại trang gói dịch vụ
                        và bấm gia hạn để tạo đơn mới.
                    </p>
                    <div class="tt-done-acts">
                        <a class="tt-btn" href="{{ route('admin.goi-dich-vu.index') }}">Tạo đơn mới</a>
                    </div>
                </div>
            @endif

            {{-- ===== ĐANG CHỜ TRẢ TIỀN ===== --}}
            <div class="tt-grid" data-tt-cho @if($daTra || $daDong) hidden @endif>
                {{-- --- Cột trái: đơn hàng --- --}}
                <section class="tt-card tt-order">
                    <h2 class="tt-card-title">Nội dung đơn</h2>

                    <div class="tt-line">
                        <div>
                            <p class="tt-line-name">Gia hạn gói {{ $o('ten_goi') ?: '—' }}</p>
                            <p class="tt-line-note">
                                {{ $o('ten_app') ?: 'Phần mềm' }} · {{ $o('so_thang') }} tháng sử dụng
                            </p>
                        </div>
                        <span class="tt-line-money">{{ $tien((float) $o('so_tien', 0)) }}</span>
                    </div>

                    <div class="tt-sum">
                        <span>Tổng thanh toán</span>
                        <b>{{ $tien((float) $o('so_tien', 0)) }}</b>
                    </div>

                    {{-- ===== BÊN MUA =====

                         Một trang thanh toán phải nói rõ ĐANG THU TIỀN CHO AI. Không có
                         khối này thì người bấm trả tiền trên một máy dùng chung không
                         có cách nào chắc mình đang gia hạn đúng cửa hàng của mình — mà
                         tiền đã chuyển thì không rút lại được.

                         Dữ liệu lấy từ SỔ KHÁCH HÀNG của nhà cung cấp (qua hợp đồng),
                         không từ tài khoản đang đăng nhập: token nói "ai đang bấm", còn
                         hoá đơn thì thu tiền của CỬA HÀNG. Hai thứ đó khác nhau ở mọi
                         tiệm có nhiều hơn một quản trị viên. --}}
                    <div class="tt-buyer">
                        <p class="tt-buyer-head">Cửa hàng thanh toán</p>
                        <dl class="tt-buyer-rows">
                            <div>
                                <dt>Cửa hàng</dt>
                                <dd>
                                    {{ $o('ten_cua_hang') ?: '—' }}
                                    @if($o('ma_cua_hang') !== '')
                                        <span class="tt-code">{{ $o('ma_cua_hang') }}</span>
                                    @endif
                                </dd>
                            </div>
                            @if($o('nguoi_lien_he') !== '')
                                <div><dt>Người liên hệ</dt><dd>{{ $o('nguoi_lien_he') }}</dd></div>
                            @endif
                            @if($o('dien_thoai') !== '')
                                <div><dt>Điện thoại</dt><dd>{{ $o('dien_thoai') }}</dd></div>
                            @endif
                            @if($o('email') !== '')
                                <div><dt>Email</dt><dd>{{ $o('email') }}</dd></div>
                            @endif
                        </dl>
                        {{-- Ba ô liên hệ là thứ sẽ in trên hoá đơn. Hiện ra để khách PHÁT
                             HIỆN chỗ sai, nên phải kèm cách sửa — mà cách sửa duy nhất
                             hôm nay là gọi cho nhà cung cấp. --}}
                        <p class="tt-buyer-note">
                            Thông tin này lấy từ hồ sơ khách hàng của nhà cung cấp và sẽ dùng cho hoá đơn.
                            Sai chỗ nào thì liên hệ
                            <a href="mailto:{{ \App\Http\Controllers\GoiDichVuController::SUPPORT_EMAIL }}">{{ \App\Http\Controllers\GoiDichVuController::SUPPORT_EMAIL }}</a>
                            để sửa trước khi trả tiền.
                        </p>
                    </div>

                    {{-- Đồng hồ: link có hạn thật, và khách cần biết mình còn bao lâu
                         trước khi đi pha cà phê. --}}
                    @if($giayConLai !== null && $giayConLai > 0)
                        <div class="tt-clock" data-tt-clock data-giay="{{ $giayConLai }}">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <span>Mã QR còn hiệu lực <b data-tt-dem>—</b></span>
                        </div>
                        <p class="tt-expire">
                            Hết hạn lúc {{ $ngayGio($o('het_han_luc')) }}. Quá giờ thì đơn tự huỷ —
                            bạn chưa bị trừ tiền, chỉ cần bấm gia hạn lại.
                        </p>
                    @endif

                    <ul class="tt-trust">
                        <li>Hạn hợp đồng cộng thêm ngay khi tiền vào, không cần chờ ai xác nhận</li>
                        <li>Giao dịch do PayOS xử lý; phần mềm không lưu thông tin thẻ hay tài khoản của bạn</li>
                        <li>Chưa trả tiền thì chưa có gì thay đổi — đóng trang này vẫn an toàn</li>
                    </ul>
                </section>

                {{-- --- Cột phải: trả tiền --- --}}
                <section class="tt-card tt-pay">
                    @if($thieuThongTin)
                        <h2 class="tt-card-title">Trả tiền qua cổng</h2>
                        <p class="tt-qr-hint">
                            Đơn này được tạo trước khi phần mềm hiển thị được mã QR ngay tại đây,
                            nên vui lòng trả tiền trên trang của cổng thanh toán. Trả xong quay lại
                            trang này — hạn hợp đồng vẫn được cộng tự động.
                        </p>
                        @if($o('checkout_url') !== '')
                            <a class="tt-btn tt-btn--wide" href="{{ $o('checkout_url') }}" target="_blank" rel="noopener">
                                Mở trang thanh toán
                            </a>
                        @else
                            <p class="tt-qr-hint">Đơn này không có link thanh toán. Vui lòng quay lại và tạo đơn mới.</p>
                        @endif

                        <p class="tt-live" data-tt-live>
                            <span class="tt-dot"></span>
                            Đang chờ thanh toán — trang này tự cập nhật, không cần tải lại.
                        </p>
                    @else
                    <h2 class="tt-card-title">Quét mã để trả</h2>

                    @if($qr !== '')
                        <div class="tt-qr">
                            {{-- Vẽ tại chỗ từ chuỗi VietQR. Thư viện nằm TRONG dự án
                                 (public/js/qrcode.js), không lấy từ CDN: trang thanh toán
                                 mà phụ thuộc một máy chủ bên thứ ba là mất mã QR đúng lúc
                                 mạng của khách chặn CDN — và đó là lúc họ đang định trả
                                 tiền. Vẫn có nhánh dự phòng bên dưới nếu vẽ hỏng. --}}
                            <div id="ttQR" aria-label="Mã QR chuyển khoản"></div>
                            <p class="tt-qr-fallback" data-tt-qr-loi hidden>
                                Không vẽ được mã QR trên máy này. Vui lòng chuyển khoản theo thông tin bên dưới.
                            </p>
                        </div>
                        <p class="tt-qr-hint">Mở app ngân hàng → quét mã. Số tiền và nội dung đã có sẵn trong mã.</p>
                    @endif

                    <div class="tt-or"><span>hoặc chuyển khoản thủ công</span></div>

                    <dl class="tt-bank">
                        @if($o('chu_tai_khoan') !== '')
                            <div>
                                <dt>Chủ tài khoản</dt>
                                <dd>{{ $o('chu_tai_khoan') }}</dd>
                            </div>
                        @endif
                        @if($soTK !== '')
                            <div>
                                <dt>Số tài khoản</dt>
                                <dd>
                                    <span class="tt-mono">{{ $soTK }}</span>
                                    <button type="button" class="tt-copy" data-tt-copy="{{ $soTK }}">Chép</button>
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt>Số tiền</dt>
                            <dd>
                                <span class="tt-mono">{{ number_format((float) $o('so_tien', 0), 0, ',', '') }}</span>
                                <button type="button" class="tt-copy" data-tt-copy="{{ (int) $o('so_tien', 0) }}">Chép</button>
                            </dd>
                        </div>
                        @if($noiDung !== '')
                            {{-- Nội dung chuyển khoản in ĐẬM và đứng cuối: đây là ô hay bị gõ
                                 sai nhất, và gõ sai thì tiền vào tài khoản mà không đơn nào
                                 được chốt — phải đối soát tay. --}}
                            <div class="tt-bank-key">
                                <dt>Nội dung <span class="tt-req">bắt buộc</span></dt>
                                <dd>
                                    <span class="tt-mono">{{ $noiDung }}</span>
                                    <button type="button" class="tt-copy" data-tt-copy="{{ $noiDung }}">Chép</button>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    <p class="tt-live" data-tt-live>
                        <span class="tt-dot"></span>
                        Đang chờ thanh toán — trang này tự cập nhật, không cần tải lại.
                    </p>

                    @if($o('checkout_url') !== '')
                        {{-- Đường LUI, không phải đường chính: khách muốn trả bằng ví điện tử
                             hoặc thẻ thì cần trang của cổng. Mở tab mới để TRANG NÀY còn sống
                             mà theo dõi đơn — điều hướng cả tab đi thì lúc quay về là một lượt
                             tải mới và đồng hồ đếm lại từ đầu. --}}
                        <a class="tt-other" href="{{ $o('checkout_url') }}" target="_blank" rel="noopener">
                            Trả bằng ví điện tử / thẻ ngân hàng →
                        </a>
                    @endif
                    @endif
                </section>
            </div>
        @endif
    </div>

    <style>
        .tt {
            margin: -1.5rem; min-height: calc(100vh - 56px);
            padding: 20px 24px 40px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #f5f6fa;
        }
        .tt-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .tt-eyebrow { margin: 0; font-size: 11.5px; font-weight: 600; color: #8c8c8c; text-transform: uppercase; letter-spacing: .05em; font-variant-numeric: tabular-nums; }
        .tt-title { margin: 3px 0 0; font-size: 19px; font-weight: 700; letter-spacing: -.02em; }
        .tt-back { font-size: 13px; color: #1890ff; text-decoration: none; white-space: nowrap; }
        .tt-back:hover { text-decoration: underline; }

        .tt-alert {
            margin: 0 0 16px; padding: 11px 14px; border: 1px solid #ffe58f; border-radius: 10px;
            background: #fffbe6; color: #874d00; font-size: 13px;
        }

        /* Hai cột: đơn hàng trái, trả tiền phải — thứ tự đọc của mọi trang thanh
           toán quen thuộc. Dưới 900px thì xếp dọc, và cột TRẢ TIỀN vẫn nằm dưới
           cột đơn hàng: người ta xác nhận mình mua gì trước khi quét mã. */
        .tt-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(320px, 380px); gap: 16px; align-items: start; }
        @media (max-width: 900px) { .tt-grid { grid-template-columns: 1fr; } }

        .tt-card {
            background: #fff; border: 1px solid #f0f0f0; border-radius: 12px; padding: 22px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        .tt-card-title { margin: 0 0 16px; font-size: 14px; font-weight: 700; }

        /* ----- Cột đơn hàng ----- */
        .tt-line { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0; }
        .tt-line-name { margin: 0; font-size: 14px; font-weight: 600; }
        .tt-line-note { margin: 3px 0 0; font-size: 12.5px; color: #8c8c8c; }
        .tt-line-money { font-size: 14px; font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .tt-sum { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-top: 14px; }
        .tt-sum span { font-size: 13px; color: #8c8c8c; }
        /* Tổng tiền là con số quyết định — in to hẳn để không ai quét mã mà chưa đọc nó. */
        .tt-sum b { font-size: 26px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }


        /* ----- Bên mua -----
           Nền xám nhạt để tách khỏi dòng tiền phía trên: đây là phần XÁC NHẬN
           DANH TÍNH, không phải một mục nữa của hoá đơn. */
        .tt-buyer { margin-top: 18px; padding: 14px 16px; border-radius: 10px; background: #fafafa; border: 1px solid #f0f0f0; }
        .tt-buyer-head { margin: 0 0 10px; font-size: 11.5px; font-weight: 600; color: #8c8c8c; text-transform: uppercase; letter-spacing: .04em; }
        .tt-buyer-rows { margin: 0; display: flex; flex-direction: column; gap: 7px; }
        .tt-buyer-rows > div { display: flex; align-items: baseline; justify-content: space-between; gap: 14px; }
        .tt-buyer-rows dt { font-size: 12.5px; color: #8c8c8c; white-space: nowrap; }
        .tt-buyer-rows dd { margin: 0; font-size: 13px; font-weight: 500; text-align: right; overflow-wrap: anywhere; }
        /* Mã cửa hàng: chữ đơn cách, vì đây là thứ khách đối chiếu từng ký tự. */
        .tt-code {
            display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 5px;
            background: #f0f7ff; color: #0958d9;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; font-weight: 600;
        }
        .tt-buyer-note { margin: 10px 0 0; padding-top: 10px; border-top: 1px solid #f0f0f0; font-size: 11.5px; line-height: 1.6; color: #8c8c8c; }
        .tt-buyer-note a { color: #1890ff; }

        .tt-clock {
            display: inline-flex; align-items: center; gap: 7px; margin-top: 16px;
            padding: 7px 12px; border-radius: 999px;
            background: #fff7e6; color: #874d00; font-size: 12.5px;
        }
        .tt-clock b { font-variant-numeric: tabular-nums; }
        /* Còn dưới 5 phút: đổi sang tông đỏ. Cùng một con số nhưng ý nghĩa đã khác —
           lúc này khách phải quyết định ngay hoặc tạo đơn mới. */
        .tt-clock.is-gap { background: #fff1f0; color: #a8071a; }
        .tt-expire { margin: 8px 0 0; font-size: 11.5px; color: #bfbfbf; }

        .tt-trust { list-style: none; margin: 18px 0 0; padding: 16px 0 0; border-top: 1px solid #f0f0f0; display: flex; flex-direction: column; gap: 9px; }
        .tt-trust li { position: relative; padding-left: 20px; font-size: 12.5px; line-height: 1.6; color: #8c8c8c; }
        .tt-trust li::before {
            content: ''; position: absolute; left: 2px; top: 6px; width: 8px; height: 5px;
            border-left: 2px solid #52c41a; border-bottom: 2px solid #52c41a; transform: rotate(-45deg);
        }

        /* ----- Cột trả tiền ----- */
        .tt-pay { text-align: center; }
        .tt-qr {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 12px; border: 1px solid #f0f0f0; border-radius: 12px; background: #fff;
        }
        .tt-qr canvas { display: block; width: 220px; height: 220px; }
        .tt-qr-fallback { margin: 0; max-width: 220px; font-size: 12.5px; color: #8c8c8c; line-height: 1.6; }
        .tt-qr-hint { margin: 10px 0 0; font-size: 12.5px; color: #8c8c8c; line-height: 1.6; }

        /* Đường kẻ có chữ ở giữa: nói rõ hai cách trả tiền là THAY THẾ nhau, không
           phải hai bước phải làm cả hai. */
        .tt-or { display: flex; align-items: center; gap: 12px; margin: 20px 0 16px; }
        .tt-or::before, .tt-or::after { content: ''; flex: 1; height: 1px; background: #f0f0f0; }
        .tt-or span { font-size: 11.5px; color: #bfbfbf; text-transform: uppercase; letter-spacing: .04em; }

        .tt-bank { margin: 0; display: flex; flex-direction: column; gap: 10px; text-align: left; }
        .tt-bank > div { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .tt-bank dt { font-size: 12.5px; color: #8c8c8c; white-space: nowrap; }
        .tt-bank dd { margin: 0; display: flex; align-items: center; gap: 8px; min-width: 0; }
        .tt-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; font-weight: 600; overflow-wrap: anywhere; }
        /* Nội dung chuyển khoản: khối riêng có nền, vì đây là ô hay gõ sai nhất và
           gõ sai thì tiền vào mà không đơn nào được chốt. */
        .tt-bank-key { padding: 10px 12px; border-radius: 8px; background: #f0f7ff; border: 1px solid #ddeeff; }
        .tt-req { display: inline-block; margin-left: 4px; padding: 1px 6px; border-radius: 4px; background: #fff1f0; color: #cf1322; font-size: 10.5px; font-weight: 600; }
        .tt-copy {
            flex-shrink: 0; height: 26px; padding: 0 10px; border: 1px solid #d9d9d9; border-radius: 6px;
            background: #fff; font-family: inherit; font-size: 11.5px; font-weight: 600; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .tt-copy:hover { border-color: #1890ff; color: #1890ff; }
        .tt-copy.is-done { border-color: #52c41a; color: #389e0d; }

        .tt-live {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            margin: 18px 0 0; padding-top: 16px; border-top: 1px solid #f0f0f0;
            font-size: 12.5px; color: #8c8c8c; line-height: 1.6; text-align: left;
        }
        /* Chấm nhấp nháy: dấu hiệu duy nhất nói trang đang THEO DÕI chứ không đứng
           im. Không có nó thì màn hình "đang chờ" đọc như một trang treo. */
        .tt-dot { flex-shrink: 0; width: 8px; height: 8px; border-radius: 50%; background: #1890ff; animation: ttNhay 1.4s ease-in-out infinite; }
        @keyframes ttNhay { 0%, 100% { opacity: 1; } 50% { opacity: .25; } }
        @media (prefers-reduced-motion: reduce) { .tt-dot { animation: none; } }

        .tt-other { display: inline-block; margin-top: 14px; font-size: 12.5px; color: #1890ff; text-decoration: none; }
        .tt-other:hover { text-decoration: underline; }

        /* ----- Đã xong / đã đóng ----- */
        .tt-done {
            max-width: 480px; margin: 0 auto; padding: 30px 26px;
            background: #fff; border: 1px solid #f0f0f0; border-radius: 12px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03); text-align: center;
        }
        .tt-done-icon {
            width: 56px; height: 56px; margin: 0 auto 16px;
            display: flex; align-items: center; justify-content: center; border-radius: 50%;
            background: #f6ffed; color: #389e0d;
        }
        .tt-done-title { margin: 0; font-size: 19px; font-weight: 700; }
        .tt-done-sub { margin: 10px 0 0; font-size: 13.5px; line-height: 1.7; color: #595959; }
        .tt-done-acts { display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
        .tt-btn {
            display: inline-flex; align-items: center; justify-content: center;
            height: 38px; padding: 0 18px; border-radius: 8px;
            background: #1890ff; color: #fff; font-size: 13px; font-weight: 600; text-decoration: none;
        }
        .tt-btn:hover { background: #40a9ff; color: #fff; }
        .tt-btn--wide { width: 100%; margin-top: 16px; }
        .tt-btn-ghost {
            display: inline-flex; align-items: center; justify-content: center;
            height: 38px; padding: 0 18px; border: 1px solid #d9d9d9; border-radius: 8px;
            background: #fff; color: #595959; font-size: 13px; font-weight: 600; text-decoration: none;
        }
        .tt-btn-ghost:hover { border-color: #1890ff; color: #1890ff; }
    </style>

    @if($don && ! $daTra && ! $daDong)
        {{-- Thư viện vẽ QR. Nằm trên CDN như bootstrap của layout; tải hỏng thì
             khối chuyển khoản tay bên dưới vẫn đủ để trả tiền. --}}
        @if($qr !== '')
            <script src="{{ asset('js/qrcode.js') }}?v=1"></script>
        @endif

        <script>
            (function () {
                // ----- Mã QR -----
                var chuoiQR = @json($qr);
                var oQR = document.getElementById('ttQR');
                if (oQR && chuoiQR) {
                    try {
                        // typeNumber 0 = tự chọn kích thước đủ chứa chuỗi; 'M' là mức
                        // sửa lỗi tiêu chuẩn của VietQR. Vẽ ra SVG chứ không canvas:
                        // nét sắc ở mọi mật độ điểm ảnh, và in ra giấy vẫn quét được.
                        var qr = qrcode(0, 'M');
                        qr.addData(chuoiQR);
                        qr.make();
                        oQR.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 1 });
                    } catch (e) {
                        hongQR();
                    }
                }
                function hongQR() {
                    if (oQR) oQR.hidden = true;
                    var loi = document.querySelector('[data-tt-qr-loi]');
                    if (loi) loi.hidden = false;
                }

                // ----- Nút chép -----
                // Chép xong ĐỔI NHÃN trong 2 giây: không có phản hồi thì người dùng
                // bấm lại vài lần rồi tưởng nút hỏng.
                document.querySelectorAll('[data-tt-copy]').forEach(function (nut) {
                    nut.addEventListener('click', function () {
                        var chu = nut.getAttribute('data-tt-copy') || '';
                        var xong = function () {
                            var cu = nut.textContent;
                            nut.textContent = 'Đã chép';
                            nut.classList.add('is-done');
                            setTimeout(function () {
                                nut.textContent = cu;
                                nut.classList.remove('is-done');
                            }, 2000);
                        };

                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(chu).then(xong).catch(function () { chepTay(chu, xong); });
                        } else {
                            // http:// (máy local) không có clipboard API — vẫn phải chép được.
                            chepTay(chu, xong);
                        }
                    });
                });
                function chepTay(chu, xong) {
                    var o = document.createElement('textarea');
                    o.value = chu;
                    o.style.position = 'fixed';
                    o.style.opacity = '0';
                    document.body.appendChild(o);
                    o.select();
                    try { document.execCommand('copy'); xong(); } catch (e) {}
                    document.body.removeChild(o);
                }

                // ----- Đồng hồ đếm ngược -----
                var dongHo = document.querySelector('[data-tt-clock]');
                var oDem = document.querySelector('[data-tt-dem]');
                if (dongHo && oDem) {
                    var conLai = parseInt(dongHo.getAttribute('data-giay'), 10) || 0;
                    (function dem() {
                        if (conLai <= 0) {
                            oDem.textContent = 'đã hết hạn';
                            // Tải lại để trang chuyển hẳn sang trạng thái "đơn đã hết
                            // hiệu lực". Để nguyên màn hình cũ thì khách vẫn thấy mã QR
                            // và vẫn quét — tiền vào một link đã chết.
                            setTimeout(function () { window.location.reload(); }, 1200);

                            return;
                        }
                        var g = Math.floor(conLai / 3600);
                        var p = Math.floor((conLai % 3600) / 60);
                        var s = conLai % 60;
                        oDem.textContent = (g > 0 ? g + ' giờ ' : '') + p + ' phút ' + ('0' + s).slice(-2) + ' giây';
                        // Dưới 5 phút thì đổi tông: cùng một con số nhưng ý nghĩa đã khác.
                        dongHo.classList.toggle('is-gap', conLai < 300);
                        conLai--;
                        setTimeout(dem, 1000);
                    })();
                }

                // ----- Theo dõi đơn -----
                // Hỏi lại mỗi 4 giây. Chính lượt hỏi này là thứ CHỐT ĐƠN khi webhook
                // không tới được máy chủ — nên nó không phải "làm mới giao diện", nó
                // là một phần của luồng thanh toán.
                //
                // Dừng sau 15 phút: quá đó thì khách đã bỏ đi, và một tab bỏ quên
                // không được ngồi gọi API mãi.
                var url = @json(route('admin.goi-dich-vu.don', $id));
                var cho = document.querySelector('[data-tt-cho]');
                var xongKhoi = document.querySelector('[data-tt-xong]');
                var oHan = document.querySelector('[data-tt-han]');
                var het = Date.now() + 15 * 60 * 1000;

                function hoi() {
                    if (Date.now() > het || !cho || !xongKhoi) return;

                    fetch(url, { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.ok ? r.json() : null; })
                        .then(function (j) {
                            var don = j && j.data;
                            if (!don) return void setTimeout(hoi, 4000);

                            // Đơn đã đóng (hết hạn / huỷ): tải lại để hiện đúng trạng
                            // thái, thay vì tiếp tục quay vòng chờ một khoản tiền không
                            // bao giờ tới được nữa.
                            if (don.trang_thai === 'het_han' || don.trang_thai === 'huy') {
                                window.location.reload();

                                return;
                            }

                            if (don.da_tra) {
                                cho.hidden = true;
                                xongKhoi.hidden = false;
                                if (oHan && don.han_moi) {
                                    var d = new Date(don.han_moi);
                                    oHan.textContent = ('0' + d.getDate()).slice(-2) + '/' +
                                        ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
                                }
                                // Cửa hàng vừa được mở khoá ở máy chủ. Nạp lại sau một
                                // nhịp để thanh trái và menu trở lại đầy đủ — nhưng chậm
                                // đủ để người dùng đọc kịp câu "đã gia hạn xong".
                                setTimeout(function () { window.location.reload(); }, 4000);

                                return;
                            }

                            setTimeout(hoi, 4000);
                        })
                        .catch(function () { setTimeout(hoi, 6000); });
                }

                setTimeout(hoi, 4000);
            })();
        </script>
    @endif
@endsection
