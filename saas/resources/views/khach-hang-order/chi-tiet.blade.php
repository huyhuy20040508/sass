{{--
    Chi tiết một hợp đồng — trọn hồ sơ khách, và chỗ sửa được những gì sửa được.

    HAI KHỐI, và ranh giới giữa chúng là ranh giới quan trọng nhất của màn hình:

      · ĐIỀU KHOẢN ĐÃ KÝ (gói · chu kỳ · giá · ba hạn mức · tên miền riêng) —
        CHỈ ĐỌC. Chúng chép ra từ bảng giá lúc ký rồi sống độc lập, và cả hệ
        thống dựng trên nguyên tắc chúng không đổi. Bán thêm quyền lợi cho một
        khách là sửa chính hợp đồng của họ, làm bằng `cmd/thue-bao` trên máy chủ
        — nơi có bảng đối chiếu in ra trước mắt người ký.

      · THÔNG TIN (tên cửa hàng · người liên hệ · ghi chú) — sửa được. Khách đổi
        tên tiệm, đổi người phụ trách, đổi số điện thoại; những thứ đó đổi thật
        và không có chỗ nào khác để sửa.

    Ô "ngày hết hạn" chỉ hiện khi API bảo được (`sua_duoc_han`) — tức hợp đồng
    đang dùng thử. Máy chủ quyết, Blade không tự suy: chép luật ra hai nơi là để
    chúng lệch nhau, và người dùng gặp cái lệch đó dưới dạng một ô nhập bị từ
    chối ngay lúc bấm Lưu.
--}}
@extends('layouts.app')
@section('title', data_get($hd, 'ten_cua_hang') ?: 'Chi tiết hợp đồng')

@php
    // Hợp đồng này thuộc NHÓM nào — xét theo `het_dung_thu` (trial_ends_at),
    // đúng luật mà API dùng để chia hai màn hình danh sách.
    //
    // KHÔNG xét `trang_thai === 'trial'`: trạng thái đổi khi kỳ thử hết hạn
    // (thành `past_due`) hay bị huỷ, mà nhóm thì không. Xét theo trạng thái thì
    // một hợp đồng thử vừa hết hạn có nút Quay lại trỏ sang "Người dùng chính
    // thức" — đúng cái danh sách không chứa nó — và nút hành động đọc thành "Gia
    // hạn" cho một khách chưa trả đồng nào.
    //
    // API xoá `trial_ends_at` đúng lúc chuyển sang chính thức (xem GiaHan), nên
    // khách trả tiền lần đầu tự sang nhóm bên kia mà không ai phải đánh dấu gì.
    $la_dung_thu = data_get($hd, 'het_dung_thu') !== null;
    $da_huy = data_get($hd, 'trang_thai') === 'canceled';
    $con_lai = (int) data_get($hd, 'con_lai_ngay');
    $sua_duoc = $ghiDuoc && ! $da_huy;

    $ngay = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    // Mốc thời hạn in KÈM GIỜ. "Còn 4 ngày" trên danh sách suy ra từ đúng những
    // mốc này tính tới từng phút, nên in mỗi ngày là giấu đi thứ quyết định con
    // số đó — và hai hợp đồng cùng ngày mà lệch nhau nửa buổi trông y hệt nhau.
    $luc = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y · H:i') : '—';
    // 0 = KHÔNG GIỚI HẠN, in ∞ chứ không in số 0 — số 0 đọc thành "không được
    // cái nào", tức ngược hẳn nghĩa thật.
    $so = fn ($v) => ((int) $v) ?: '∞';

    $trang_thai = [
        'trial' => ['Đang dùng thử', 'tag-warn'],
        'active' => ['Chính thức', 'tag-good'],
        'past_due' => ['Quá hạn', 'tag-bad'],
        'canceled' => ['Đã huỷ', 'tag-mute'],
    ][data_get($hd, 'trang_thai')] ?? [data_get($hd, 'trang_thai'), 'tag-mute'];
@endphp

@section('content')
    <div class="page-bar">
        <div class="page-head">
            {{-- Đường lui về đúng danh sách vừa đứng: hợp đồng dùng thử về màn
                 hình dùng thử, còn lại về màn hình chính thức. --}}
            <a class="eyebrow" style="text-decoration:none"
               href="{{ route($la_dung_thu
                    ? 'platform.khach-hang-order.nguoi-dung-thu'
                    : 'platform.khach-hang-order.nguoi-chinh-thuc') }}">
                ← {{ $la_dung_thu ? 'Người dùng thử' : 'Người dùng chính thức' }}
            </a>
            <h2>{{ data_get($hd, 'ten_cua_hang') ?: data_get($hd, 'ma_cua_hang') }}</h2>
            <p>
                <span class="mono">{{ data_get($hd, 'ma_cua_hang') }}</span> ·
                {{ data_get($hd, 'ten_app') }} ·
                <span class="tag {{ $trang_thai[1] }}">{{ $trang_thai[0] }}</span>
                @if (data_get($hd, 'trang_thai_cua_hang') !== 'active')
                    <span class="tag tag-bad">Cửa hàng bị khoá</span>
                @endif
            </p>
        </div>

        @if ($sua_duoc)
            <button type="button" class="btn-ghost" data-mo="sheet-giahan"
                    data-id="{{ data_get($hd, 'id') }}"
                    data-ten="{{ data_get($hd, 'ten_cua_hang') }}"
                    data-goi="{{ data_get($hd, 'ten_goi') }}">
                {{ $la_dung_thu ? 'Chuyển sang chính thức' : 'Gia hạn' }}
            </button>
            {{-- is-bad: đỏ lên lúc rê chuột, không đỏ sẵn. Đỏ từ đầu thì trang
                 lúc nào cũng có một chấm báo động trong khi chẳng có gì hỏng. --}}
            <button type="button" class="btn-ghost is-bad" data-mo="sheet-huy"
                    data-id="{{ data_get($hd, 'id') }}"
                    data-ten="{{ data_get($hd, 'ten_cua_hang') }}">
                Huỷ hợp đồng
            </button>
        @endif
    </div>

    @if ($da_huy)
        <div class="notice">
            Hợp đồng này đã huỷ ngày {{ $ngay(data_get($hd, 'het_han')) }} — đây là bản ghi lịch sử,
            không sửa được nữa. Khách quay lại thì ký hợp đồng mới.
        </div>
    @endif

    <div class="row g-3">
        {{-- ĐIỀU KHOẢN — chỉ đọc. Đặt bên trái và lên trước vì đây là thứ người
             mở trang này muốn xem đầu tiên: khách đang mua cái gì. --}}
        <div class="col-12 col-lg-5">
            <section class="panel">
                <div class="panel-head">Điều khoản đã ký</div>
                <div class="panel-body">
                    <dl class="ke">
                        <dt>Gói dịch vụ</dt>
                        <dd>
                            {{ data_get($hd, 'ten_goi') ?: '—' }}
                            <span class="muted">· {{ data_get($hd, 'chu_ky') === 'nam' ? 'theo năm' : 'theo tháng' }}</span>
                        </dd>

                        <dt>Giá</dt>
                        <dd class="mono">
                            {{ number_format((float) data_get($hd, 'gia'), 0, ',', '.') }} ₫
                            <span class="muted">/ {{ data_get($hd, 'chu_ky') === 'nam' ? 'năm' : 'tháng' }}</span>
                        </dd>

                        <dt>Chi nhánh</dt>
                        <dd class="mono">{{ $so(data_get($hd, 'chi_nhanh')) }}</dd>

                        <dt>Tài khoản</dt>
                        <dd class="mono">{{ $so(data_get($hd, 'tai_khoan')) }}</dd>

                        <dt>Sản phẩm</dt>
                        <dd class="mono">{{ $so(data_get($hd, 'san_pham')) }}</dd>

                        <dt>Tên miền riêng</dt>
                        <dd>
                            @if (data_get($hd, 'ten_mien_rieng'))
                                <span class="tag tag-good">Có</span>
                            @else
                                <span class="tag tag-mute">Không</span>
                            @endif
                        </dd>
                    </dl>

                    <p class="hint" style="margin-top:12px">
                        Chép từ bảng giá lúc ký rồi sống độc lập — sửa bảng giá về sau không đụng tới
                        khách này, và đổi điều khoản của riêng khách này thì làm bằng
                        <span class="mono">cmd/thue-bao</span> trên máy chủ.
                    </p>
                </div>
            </section>

            <section class="panel" style="margin-top:14px">
                <div class="panel-head">Thời hạn</div>
                <div class="panel-body">
                    <dl class="ke">
                        <dt>Bắt đầu</dt>
                        <dd class="mono">{{ $luc(data_get($hd, 'bat_dau')) }}</dd>

                        <dt>Hết hạn</dt>
                        <dd class="mono">{{ $luc(data_get($hd, 'het_han')) }}</dd>

                        @if (data_get($hd, 'het_dung_thu'))
                            <dt>Hết dùng thử</dt>
                            <dd class="mono">{{ $luc(data_get($hd, 'het_dung_thu')) }}</dd>
                        @endif

                        <dt>Còn lại</dt>
                        <dd>
                            <span class="tag {{ $con_lai < 0 ? 'tag-bad' : ($con_lai <= 7 ? 'tag-warn' : 'tag-mute') }}">
                                {{ $con_lai < 0 ? 'Quá ' . abs($con_lai) . ' ngày' : $con_lai . ' ngày' }}
                            </span>
                        </dd>

                        <dt>Khách vào sổ</dt>
                        <dd class="mono">{{ $ngay(data_get($hd, 'ngay_vao_so')) }}</dd>
                    </dl>
                </div>
            </section>

            {{-- TÀI KHOẢN ĐĂNG NHẬP của khách — hai trong ba ô của màn hình đăng
                 nhập Sellio Order. Ô thứ ba là mật khẩu, và nó không đọc được từ
                 đâu cả: chỉ ĐẶT LẠI được.

                 Đọc từ data plane nên có thể vắng mặt mà hợp đồng vẫn đúng. Nói
                 ra điều đó thay vì giấu cả khối đi. --}}
            <section class="panel" style="margin-top:14px">
                <div class="panel-head">Tài khoản đăng nhập của khách</div>
                <div class="panel-body">
                    @php $qt = data_get($hd, 'quan_tri'); @endphp
                    @if ($qt)
                        <dl class="ke">
                            <dt>Mã cửa hàng</dt>
                            <dd class="mono">{{ data_get($hd, 'ma_cua_hang') }}</dd>

                            <dt>Tên đăng nhập</dt>
                            <dd class="mono">{{ data_get($qt, 'ten_dang_nhap') ?: '—' }}</dd>

                            <dt>Họ tên</dt>
                            <dd>{{ data_get($qt, 'ho_ten') ?: '—' }}</dd>

                            <dt>Trạng thái</dt>
                            <dd>
                                @if (data_get($qt, 'trang_thai') === 'active')
                                    <span class="tag tag-good">Đang hoạt động</span>
                                @else
                                    <span class="tag tag-bad">Đang bị khoá</span>
                                @endif
                            </dd>
                        </dl>

                        @if ($sua_duoc)
                            <button type="button" class="btn-ghost" style="margin-top:14px" data-mo="sheet-matkhau">
                                Đổi mật khẩu
                            </button>
                            <p class="hint" style="margin-top:8px">
                                Tài khoản quản trị cửa hàng không có đường tự lấy lại mật khẩu — khách quên thì
                                đặt lại ở đây rồi đọc cho họ.
                            </p>
                        @endif
                    @else
                        <p class="muted" style="margin:0">
                            Chưa đọc được tài khoản quản trị của cửa hàng này. Có thể cửa hàng không còn tài khoản
                            <span class="mono">super_admin</span> nào, hoặc lượt đọc sang database bán hàng vừa hỏng.
                        </p>
                    @endif
                </div>
            </section>
        </div>

        {{-- THÔNG TIN — sửa được. --}}
        <div class="col-12 col-lg-7">
            <section class="panel">
                <div class="panel-head">Thông tin khách hàng</div>

                @if (! $sua_duoc)
                    {{-- Chỉ đọc: hoặc vai trò không được ghi, hoặc hợp đồng đã huỷ.
                         In ra chính những giá trị đó thay vì hiện ô nhập rồi để họ
                         ăn 403 lúc bấm Lưu. --}}
                    <div class="panel-body">
                        <dl class="ke">
                            <dt>Tên cửa hàng</dt>
                            <dd>{{ data_get($hd, 'ten_cua_hang') ?: '—' }}</dd>
                            <dt>Người liên hệ</dt>
                            <dd>{{ data_get($hd, 'nguoi_lien_he') ?: '—' }}</dd>
                            <dt>Điện thoại</dt>
                            <dd class="mono">{{ data_get($hd, 'dien_thoai') ?: '—' }}</dd>
                            <dt>Email</dt>
                            <dd class="mono">{{ data_get($hd, 'email') ?: '—' }}</dd>
                            <dt>Ghi chú khách</dt>
                            <dd>{{ data_get($hd, 'ghi_chu_khach') ?: '—' }}</dd>
                            <dt>Ghi chú hợp đồng</dt>
                            <dd>{{ data_get($hd, 'ghi_chu_hop_dong') ?: '—' }}</dd>
                        </dl>
                        @unless ($da_huy)
                            <p class="hint" style="margin-top:12px">
                                Vai trò của bạn trong khu điều hành chỉ được xem.
                            </p>
                        @endunless
                    </div>
                @else
                    <form method="POST" action="{{ route('platform.khach-hang-order.hop-dong.luu', data_get($hd, 'id')) }}">
                        @csrf
                        <div class="panel-body">
                            <div class="grid2">
                                <div class="rong">
                                    <label class="f-label" for="ct-ten">Tên cửa hàng <span class="req">*</span></label>
                                    <input type="text" class="form-control @error('ten_cua_hang') is-bad @enderror"
                                           id="ct-ten" name="ten_cua_hang" maxlength="150" required
                                           value="{{ old('ten_cua_hang', data_get($hd, 'ten_cua_hang')) }}">
                                    @error('ten_cua_hang') <p class="field-bad">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="f-label" for="ct-lienhe">Người liên hệ</label>
                                    <input type="text" class="form-control @error('nguoi_lien_he') is-bad @enderror"
                                           id="ct-lienhe" name="nguoi_lien_he" maxlength="150"
                                           value="{{ old('nguoi_lien_he', data_get($hd, 'nguoi_lien_he')) }}">
                                    @error('nguoi_lien_he') <p class="field-bad">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="f-label" for="ct-sdt">Số điện thoại</label>
                                    <input type="tel" class="form-control mono @error('dien_thoai') is-bad @enderror"
                                           id="ct-sdt" name="dien_thoai" maxlength="20"
                                           value="{{ old('dien_thoai', data_get($hd, 'dien_thoai')) }}">
                                    @error('dien_thoai') <p class="field-bad">{{ $message }}</p> @enderror
                                </div>

                                <div class="{{ data_get($hd, 'sua_duoc_han') ? '' : 'rong' }}">
                                    <label class="f-label" for="ct-email">Email</label>
                                    <input type="email" class="form-control @error('email') is-bad @enderror"
                                           id="ct-email" name="email" maxlength="150"
                                           value="{{ old('email', data_get($hd, 'email')) }}">
                                    @error('email') <p class="field-bad">{{ $message }}</p> @enderror
                                </div>

                                @if (data_get($hd, 'sua_duoc_han'))
                                    {{-- Chỉ hiện khi API bảo được — tức hợp đồng đang
                                         dùng thử. Hợp đồng đã trả tiền muốn dài thêm
                                         thì đi đường Gia hạn, để đường tiền và đường
                                         hạn không tách khỏi nhau. --}}
                                    <div>
                                        <label class="f-label" for="ct-han">Hết dùng thử lúc</label>
                                        {{-- datetime-local chứ không date: hạn dùng
                                             thử tính tới TỪNG PHÚT — "còn 4 ngày"
                                             trên danh sách suy ra từ đúng mốc này,
                                             và chốt 9h sáng khác hẳn chốt cuối ngày
                                             khi khách gọi lúc 10h. --}}
                                        <input type="datetime-local" class="form-control @error('het_han') is-bad @enderror"
                                               id="ct-han" name="het_han"
                                               value="{{ old('het_han', \Illuminate\Support\Carbon::parse(data_get($hd, 'het_han'))->format('Y-m-d\TH:i')) }}">
                                        @error('het_han') <p class="field-bad">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                <div class="rong">
                                    <label class="f-label" for="ct-ghichu-kh">Ghi chú về khách</label>
                                    <input type="text" class="form-control @error('ghi_chu_khach') is-bad @enderror"
                                           id="ct-ghichu-kh" name="ghi_chu_khach" maxlength="500"
                                           placeholder="đi theo khách qua mọi hợp đồng"
                                           value="{{ old('ghi_chu_khach', data_get($hd, 'ghi_chu_khach')) }}">
                                    @error('ghi_chu_khach') <p class="field-bad">{{ $message }}</p> @enderror
                                </div>

                                <div class="rong">
                                    <label class="f-label" for="ct-ghichu-hd">Ghi chú hợp đồng</label>
                                    <input type="text" class="form-control @error('ghi_chu_hop_dong') is-bad @enderror"
                                           id="ct-ghichu-hd" name="ghi_chu_hop_dong" maxlength="500"
                                           placeholder="chỉ thuộc về hợp đồng này"
                                           value="{{ old('ghi_chu_hop_dong', data_get($hd, 'ghi_chu_hop_dong')) }}">
                                    @error('ghi_chu_hop_dong') <p class="field-bad">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="toolbar is-foot">
                            <span class="muted">Không sửa được gói, giá và hạn mức — đó là điều khoản đã ký.</span>
                            <span class="spacer"></span>
                            <button type="submit" class="btn btn-plum">Lưu thay đổi</button>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    </div>

    @if ($sua_duoc)
        @include('khach-hang-order.partials.hop-dong-thao-tac', ['laDungThu' => $la_dung_thu])

        {{-- Đổi mật khẩu tài khoản quản trị của khách.
             Hộp riêng chứ không gộp vào form sửa thông tin: mật khẩu không phải
             một ô hồ sơ. Gộp vào thì mỗi lần sửa số điện thoại cũng phải đi ngang
             qua hai ô mật khẩu trống, và ai đó sẽ gõ vào đấy. --}}
        <dialog class="sheet" id="sheet-matkhau" style="width:min(460px,calc(100vw - 32px))"
                @if ($errors->hasAny(['mat_khau'])) data-mo-san @endif>
            <div class="sheet-head">
                <div style="flex:1">
                    <h3>Đổi mật khẩu</h3>
                    <p>
                        {{ data_get($hd, 'ten_cua_hang') }} ·
                        tài khoản <strong class="mono">{{ data_get($hd, 'quan_tri.ten_dang_nhap') }}</strong>
                    </p>
                </div>
                <button type="button" class="sheet-x" data-dong aria-label="Đóng">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <form method="POST" action="{{ route('platform.khach-hang-order.hop-dong.doi-mat-khau', data_get($hd, 'id')) }}">
                @csrf

                <div class="sheet-body">
                    <div class="grid2">
                        <div class="rong">
                            <label class="f-label" for="mk-moi">Mật khẩu mới <span class="req">*</span></label>
                            {{-- type="text" chứ không "password", cùng lý do với form
                                 mở tài khoản: người bấm phải ĐỌC được cái vừa gõ để
                                 đọc cho khách nghe. Che đi chỉ tạo ra một mật khẩu gõ
                                 nhầm mà không ai biết tới lúc khách thử đăng nhập —
                                 và ô xác nhận bên dưới cũng gõ nhầm y hệt. --}}
                            <input type="text" class="form-control mono @error('mat_khau') is-bad @enderror"
                                   id="mk-moi" name="mat_khau" minlength="6" maxlength="100" required autofocus
                                   placeholder="tối thiểu 6 ký tự"
                                   autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                            @error('mat_khau') <p class="field-bad">{{ $message }}</p> @enderror
                        </div>

                        <div class="rong">
                            <label class="f-label" for="mk-xn">Xác nhận mật khẩu <span class="req">*</span></label>
                            <input type="text" class="form-control mono"
                                   id="mk-xn" name="mat_khau_confirmation" minlength="6" maxlength="100" required
                                   autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                            {{-- Tên ô phải là <ô gốc>_confirmation — đó là quy ước
                                 quy tắc `confirmed` của Laravel tra theo. Đổi tên là
                                 luật so khớp im lặng không chạy nữa. --}}
                        </div>
                    </div>

                    <p class="sheet-note">
                        Khách đăng nhập lại bằng <strong class="mono">{{ data_get($hd, 'ma_cua_hang') }}</strong> ·
                        <strong class="mono">{{ data_get($hd, 'quan_tri.ten_dang_nhap') }}</strong> · mật khẩu mới.
                        Mọi phiên đang mở của họ vẫn chạy tiếp cho tới khi hết hạn token.
                    </p>
                </div>

                <div class="sheet-foot">
                    <button type="button" class="btn-ghost" data-dong>Đóng</button>
                    <button type="submit" class="btn btn-plum">Đổi mật khẩu</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection

@push('scripts')
    <script>
        // Cùng cách mở hộp thoại với màn hình danh sách: nút khai data-mo="<id hộp>"
        // kèm data-* để nạp vào hộp, và form dựng đường gửi từ mẫu có __ID__.
        //
        // Chép lại ở đây thay vì tách ra tệp dùng chung: đây là mười lăm dòng, còn
        // một tệp JS dùng chung thì phải có chỗ nạp, thứ tự nạp, và một lý do để
        // tồn tại. Ngày nào màn hình thứ ba cần tới nó thì tách, kèm lý do đó.
        (function () {
            document.querySelectorAll('[data-mo]').forEach(function (nut) {
                nut.addEventListener('click', function () {
                    var hop = document.getElementById(nut.getAttribute('data-mo'));
                    if (!hop) return;

                    hop.querySelectorAll('[data-nap]').forEach(function (o) {
                        var v = nut.getAttribute('data-' + o.getAttribute('data-nap'));
                        if (v === null) return;
                        if (o.tagName === 'INPUT' || o.tagName === 'SELECT') o.value = v;
                        else o.textContent = v;
                    });

                    var form = hop.querySelector('form[data-mau]');
                    var id = nut.getAttribute('data-id');
                    if (form && id) form.action = form.getAttribute('data-mau').replace('__ID__', id);

                    hop.showModal();
                });
            });

            document.querySelectorAll('dialog.sheet').forEach(function (hop) {
                hop.querySelectorAll('[data-dong]').forEach(function (nut) {
                    nut.addEventListener('click', function () { hop.close(); });
                });
                hop.addEventListener('click', function (e) { if (e.target === hop) hop.close(); });

                // Máy chủ vừa trả lỗi cho hộp này: mở sẵn ra. Hai ô mật khẩu KHÔNG
                // được điền lại (không có old() cho chúng, và cũng không nên có) —
                // gõ lại là đúng, vì lỗi thường là "hai ô không khớp".
                if (hop.hasAttribute('data-mo-san')) {
                    hop.showModal();
                    var sai = hop.querySelector('.is-bad');
                    if (sai) sai.focus();
                }
            });
        })();
    </script>
@endpush
