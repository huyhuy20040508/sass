{{--
    Hộp thoại "Thêm hợp đồng chính thức".

    Đối ứng của "Thêm tài khoản dùng thử" — cũng dựng CẢ BA thứ trong một lượt:
    cửa hàng, tài khoản quản trị của khách, và hợp đồng. Khác đúng một chỗ: thời
    hạn tính bằng THÁNG và hợp đồng ra `active` ngay, không qua giai đoạn thử.

    HAI CHẾ ĐỘ trong CÙNG một hộp, chọn bằng hai nút ở đầu:

      · KHÁCH MỚI (mặc định) — dựng mọi thứ, y như bên dùng thử.
      · CỬA HÀNG ĐÃ CÓ      — chỉ ghi hợp đồng. Đây là đường DUY NHẤT dùng được
        cho khách cũ quay lại: mã cửa hàng của họ đã bị chiếm, nên đường "khách
        mới" sẽ từ chối với lỗi trùng mã. Bỏ nhánh này đi thì một khách từng huỷ
        hợp đồng vĩnh viễn không ký lại được từ giao diện.

    Hai chế độ = hai <form> riêng, không phải một form đổi action bằng JS: mỗi
    bên có bộ ô bắt buộc khác nhau, và một form mang cả hai bộ thì trình duyệt sẽ
    chặn lúc gửi vì những ô đang bị ẩn vẫn `required`.

    Giá và ba hạn mức KHÔNG có ô nào ở cả hai chế độ: chép từ bảng giá lúc ký rồi
    sống độc lập. Thoả thuận riêng vẫn đi qua `cmd/thue-bao ky`.
--}}
@php
    $o_moi = ['plan_id', 'ma_cua_hang', 'ten_cua_hang', 'ten_dang_nhap', 'mat_khau',
              'nguoi_lien_he', 'dien_thoai', 'email_lien_he', 'so_thang', 'ghi_chu'];
    // Máy chủ trả lỗi thì mở lại hộp, và mở đúng CHẾ ĐỘ vừa gửi. `ma_cua_hang`
    // có trong old() nghĩa là người dùng đang ở chế độ khách mới (chế độ kia gửi
    // ô cùng tên nhưng là <select>, nên phân biệt bằng `ten_dang_nhap`).
    $mo_lai = $errors->hasAny($o_moi) || old('ten_dang_nhap') !== null;
    $che_do_cu = old('ten_dang_nhap') !== null ? 'moi' : (old('ma_cua_hang') !== null ? 'cu' : 'moi');
@endphp

<dialog class="sheet" id="sheet-chinhthuc" @if ($mo_lai) data-mo-san data-che-do="{{ $che_do_cu }}" @endif>
    <div class="sheet-head">
        <div style="flex:1">
            <h3>Thêm hợp đồng chính thức</h3>
            <p>Hợp đồng có hiệu lực ngay, không qua giai đoạn dùng thử.</p>
        </div>
        <button type="button" class="sheet-x" data-dong aria-label="Đóng">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- Bộ chọn chế độ. Nằm NGOÀI cả hai form để đổi qua lại không mất thứ đã gõ
         ở form kia. --}}
    <div style="padding:14px 20px 0">
        <div class="loc-han" role="group" aria-label="Chọn kiểu khách">
            <button type="button" class="chip is-on" data-che-do="moi">Khách mới</button>
            @if (count($cuaHangChuaKy))
                <button type="button" class="chip" data-che-do="cu">Cửa hàng đã có</button>
            @endif
        </div>
    </div>

    {{-- ---------- CHẾ ĐỘ 1: khách mới ---------- --}}
    <form method="POST" action="{{ route('platform.khach-hang-order.chinh-thuc.tao') }}" data-form-che-do="moi">
        @csrf

        <div class="sheet-body">
            @php $loi_le = collect($errors->keys())->diff(array_merge($o_moi, ['ho_ten'])); @endphp
            @if ($loi_le->isNotEmpty())
                <p class="sheet-note is-bad">
                    @foreach ($loi_le as $k)
                        {{ $errors->first($k) }}<br>
                    @endforeach
                </p>
            @endif

            <div class="grid2">
                <div>
                    <label class="f-label" for="ct-plan">Gói dịch vụ <span class="req">*</span></label>
                    <select class="form-select @error('plan_id') is-bad @enderror"
                            id="ct-plan" name="plan_id" data-ct-plan required>
                        @foreach ($plans as $p)
                            @php $gia = data_get($p, 'price'); @endphp
                            <option value="{{ data_get($p, 'id') }}"
                                    data-chu-ky="{{ data_get($p, 'billing_cycle') }}"
                                    @selected((string) old('plan_id') === (string) data_get($p, 'id'))>
                                {{ data_get($p, 'name') }} ·
                                {{ data_get($p, 'billing_cycle') === 'nam' ? 'theo năm' : 'theo tháng' }} ·
                                {{ $gia === null ? 'Liên hệ' : number_format((float) $gia, 0, ',', '.') . ' ₫' }}
                            </option>
                        @endforeach
                    </select>
                    @error('plan_id') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-thang">Thời hạn <span class="req">*</span></label>
                    {{-- Ô số + nút đổi đơn vị trong cùng khung. Người bán nghĩ "một
                         năm" chứ không nghĩ "mười hai tháng"; bắt họ nhân nhẩm là
                         chỗ sai số, và sai ở đây là bán nhầm gấp mười hai lần.

                         Ô GỬI LÊN luôn là `so_thang` (ô ẩn) — API chỉ biết tháng.
                         Đổi đơn vị chỉ đổi cách NHẬP, không đổi cách lưu. --}}
                    <div class="o-don-vi" data-han>
                        <input type="number" class="form-control @error('so_thang') is-bad @enderror"
                               id="ct-thang" min="1" max="60" required data-han-so>
                        <div class="don-vi" role="group" aria-label="Đơn vị thời hạn">
                            <button type="button" class="is-on" data-han-dv="thang">Tháng</button>
                            <button type="button" data-han-dv="nam">Năm</button>
                        </div>
                        {{-- Có giá trị sẵn từ máy chủ: không có JS thì ô này vẫn gửi
                             lên một con số hợp lệ thay vì rỗng và bị chặn. --}}
                        <input type="hidden" name="so_thang" value="{{ old('so_thang', 1) }}" data-han-thang>
                    </div>
                    @error('so_thang') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-ma">Mã cửa hàng <span class="req">*</span></label>
                    <input type="text" class="form-control mono @error('ma_cua_hang') is-bad @enderror"
                           id="ct-ma" name="ma_cua_hang" value="{{ old('ma_cua_hang') }}"
                           maxlength="30" placeholder="cuahangabc" required
                           autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                    @error('ma_cua_hang') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-ten">Tên cửa hàng <span class="req">*</span></label>
                    <input type="text" class="form-control @error('ten_cua_hang') is-bad @enderror"
                           id="ct-ten" name="ten_cua_hang" value="{{ old('ten_cua_hang') }}"
                           maxlength="150" placeholder="Cửa hàng ABC" required autocomplete="off">
                    @error('ten_cua_hang') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-user">Tên đăng nhập <span class="req">*</span></label>
                    <input type="text" class="form-control mono @error('ten_dang_nhap') is-bad @enderror"
                           id="ct-user" name="ten_dang_nhap" value="{{ old('ten_dang_nhap', 'admin') }}"
                           maxlength="50" required
                           autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                    @error('ten_dang_nhap') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-pass">Mật khẩu <span class="req">*</span></label>
                    {{-- type="text": người bán phải ĐỌC được cái vừa gõ để đọc cho
                         khách nghe — cùng lý do với form dùng thử. --}}
                    <input type="text" class="form-control mono @error('mat_khau') is-bad @enderror"
                           id="ct-pass" name="mat_khau" value="{{ old('mat_khau') }}"
                           minlength="6" required placeholder="tối thiểu 6 ký tự"
                           autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                    @error('mat_khau') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-lienhe">Người liên hệ</label>
                    <input type="text" class="form-control @error('nguoi_lien_he') is-bad @enderror"
                           id="ct-lienhe" name="nguoi_lien_he" value="{{ old('nguoi_lien_he') }}"
                           maxlength="150" placeholder="Anh Huy" autocomplete="off">
                    @error('nguoi_lien_he') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-sdt">Số điện thoại</label>
                    <input type="tel" class="form-control mono @error('dien_thoai') is-bad @enderror"
                           id="ct-sdt" name="dien_thoai" value="{{ old('dien_thoai') }}"
                           maxlength="20" placeholder="09xxxxxxxx" autocomplete="off">
                    @error('dien_thoai') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-email">Email</label>
                    <input type="email" class="form-control @error('email_lien_he') is-bad @enderror"
                           id="ct-email" name="email_lien_he" value="{{ old('email_lien_he') }}"
                           maxlength="150" placeholder="huy@quochuy.vn" autocomplete="off">
                    @error('email_lien_he') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="ct-ghichu">Ghi chú</label>
                    <input type="text" class="form-control @error('ghi_chu') is-bad @enderror"
                           id="ct-ghichu" name="ghi_chu" value="{{ old('ghi_chu') }}"
                           maxlength="500" placeholder="anh Sơn giới thiệu" autocomplete="off">
                    @error('ghi_chu') <p class="field-bad">{{ $message }}</p> @enderror
                </div>
            </div>

            <p class="sheet-note">
                Khách đăng nhập được ngay bằng ba ô ở trên. Giá và hạn mức chép từ bảng giá lúc ký.
                Việc này <strong>không</strong> ghi tiền vào sổ thu — thu tiền là nút riêng trên từng dòng.
            </p>
        </div>

        <div class="sheet-foot">
            <button type="button" class="btn-ghost" data-dong>Đóng</button>
            <button type="submit" class="btn btn-plum">Thêm hợp đồng</button>
        </div>
    </form>

    {{-- ---------- CHẾ ĐỘ 2: cửa hàng đã có ---------- --}}
    @if (count($cuaHangChuaKy))
        <form method="POST" action="{{ route('platform.khach-hang-order.hop-dong.ky') }}"
              data-form-che-do="cu" hidden>
            @csrf

            <div class="sheet-body">
                <div class="grid2">
                    <div class="rong">
                        <label class="f-label" id="ky-ch-nhan">Cửa hàng <span class="req">*</span></label>
                        {{-- Chỉ những cửa hàng CHƯA có hợp đồng còn hiệu lực: khoá
                             uq_subscriptions_current chỉ cho mỗi khách một hợp đồng
                             còn sống trên mỗi app, nên mời chọn một cửa hàng đã ký
                             là mời ăn lỗi trùng khoá sau khi đã điền xong form.

                             KHÔNG dùng <select>: danh sách này dài theo số cửa hàng
                             đã bán, và <select> của trình duyệt không tìm được —
                             cuộn qua hàng chục cái tên để kiếm một cái là việc không
                             ai muốn làm lần thứ hai.

                             Chọn sẵn dòng đầu (hoặc thứ máy chủ trả lại sau lỗi):
                             ô giá trị là <input hidden>, mà trình duyệt KHÔNG kiểm
                             `required` trên ô ẩn — để trống thì chỉ máy chủ bắt
                             được, sau khi người dùng đã bấm Lưu. --}}
                        @php
                            $ch_dau = old('ma_cua_hang')
                                ?: data_get($cuaHangChuaKy, '0.ma');
                            $ten_dau = collect($cuaHangChuaKy)
                                ->firstWhere('ma', $ch_dau);
                        @endphp
                        <div class="chon" data-chon>
                            <button type="button" class="form-control chon-nut" data-chon-nut
                                    aria-haspopup="listbox" aria-expanded="false" aria-labelledby="ky-ch-nhan">
                                <span data-chon-nhan>
                                    {{ data_get($ten_dau, 'ten') ?: $ch_dau }}
                                    <span class="muted mono">({{ $ch_dau }})</span>
                                </span>
                            </button>

                            <div class="chon-bang" data-chon-bang hidden>
                                <input type="text" class="chon-tim" data-chon-tim
                                       placeholder="Tìm mã hoặc tên cửa hàng…"
                                       autocomplete="off" aria-label="Tìm cửa hàng">
                                <div class="chon-ds" role="listbox" data-chon-ds>
                                    @foreach ($cuaHangChuaKy as $ch)
                                        @php
                                            $ma = data_get($ch, 'ma');
                                            $ten = data_get($ch, 'ten') ?: $ma;
                                        @endphp
                                        <button type="button" class="chon-muc {{ $ma === $ch_dau ? 'is-chon' : '' }}"
                                                role="option" aria-selected="{{ $ma === $ch_dau ? 'true' : 'false' }}"
                                                data-gia-tri="{{ $ma }}"
                                                data-nhan="{{ $ten }}"
                                                data-tim="{{ mb_strtolower($ten . ' ' . $ma) }}">
                                            {{ $ten }}@if (data_get($ch, 'trang_thai') !== 'active') <span class="tag tag-bad" style="font-size:9.5px;padding:1px 5px">đang khoá</span>@endif
                                            <span class="s mono">{{ $ma }}</span>
                                        </button>
                                    @endforeach
                                    <p class="chon-trong" data-chon-trong hidden>Không có cửa hàng nào khớp.</p>
                                </div>
                            </div>

                            <input type="hidden" name="ma_cua_hang" value="{{ $ch_dau }}" data-chon-gia-tri>
                        </div>
                    </div>

                    <div>
                        <label class="f-label" for="ky-plan">Gói dịch vụ <span class="req">*</span></label>
                        <select class="form-select" id="ky-plan" name="plan_id" data-ky-plan required>
                            @foreach ($plans as $p)
                                @php $gia = data_get($p, 'price'); @endphp
                                <option value="{{ data_get($p, 'id') }}"
                                        data-chu-ky="{{ data_get($p, 'billing_cycle') }}">
                                    {{ data_get($p, 'name') }} ·
                                    {{ data_get($p, 'billing_cycle') === 'nam' ? 'theo năm' : 'theo tháng' }} ·
                                    {{ $gia === null ? 'Liên hệ' : number_format((float) $gia, 0, ',', '.') . ' ₫' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="f-label" for="ky-thang">Thời hạn <span class="req">*</span></label>
                        <div class="o-don-vi" data-han>
                            <input type="number" class="form-control" id="ky-thang"
                                   min="1" max="60" required data-han-so>
                            <div class="don-vi" role="group" aria-label="Đơn vị thời hạn">
                                <button type="button" class="is-on" data-han-dv="thang">Tháng</button>
                                <button type="button" data-han-dv="nam">Năm</button>
                            </div>
                            <input type="hidden" name="so_thang" value="1" data-han-thang>
                        </div>
                    </div>

                    <div class="rong">
                        <label class="f-label" for="ky-ghichu">Ghi chú</label>
                        <input type="text" class="form-control" id="ky-ghichu" name="ghi_chu"
                               maxlength="500" placeholder="thoả thuận riêng, ai giới thiệu…" autocomplete="off">
                    </div>
                </div>

                <p class="sheet-note">
                    Cửa hàng và tài khoản đăng nhập đã có sẵn — chỉ ghi thêm hợp đồng.
                    Dùng cho khách cũ quay lại, hoặc cửa hàng dựng bằng công cụ dòng lệnh.
                </p>
            </div>

            <div class="sheet-foot">
                <button type="button" class="btn-ghost" data-dong>Đóng</button>
                <button type="submit" class="btn btn-plum">Ký hợp đồng</button>
            </div>
        </form>
    @endif
</dialog>

@push('scripts')
    <script>
        (function () {
            var hop = document.getElementById('sheet-chinhthuc');
            if (!hop) return;

            var nutCheDo = hop.querySelectorAll('[data-che-do]');
            var forms = hop.querySelectorAll('[data-form-che-do]');

            // Đổi chế độ = ẩn/hiện cả một <form>. `hidden` chứ không display:none
            // trong CSS: nó vừa ẩn hình vừa rút mọi ô bên trong ra khỏi lượt kiểm
            // `required` của trình duyệt — không có nó thì bấm Lưu ở form này bị
            // chặn vì một ô trống của form kia.
            function dat(cd) {
                nutCheDo.forEach(function (n) {
                    n.classList.toggle('is-on', n.getAttribute('data-che-do') === cd);
                });
                forms.forEach(function (f) {
                    f.hidden = f.getAttribute('data-form-che-do') !== cd;
                });
            }

            nutCheDo.forEach(function (n) {
                n.addEventListener('click', function () { dat(n.getAttribute('data-che-do')); });
            });

            // ----- Bộ chọn cửa hàng có ô tìm -----
            //
            // Thay <select> vì danh sách dài theo số cửa hàng đã bán, mà <select>
            // của trình duyệt không tìm được.
            (function () {
                var goc = hop.querySelector('[data-chon]');
                if (!goc) return;

                var nut = goc.querySelector('[data-chon-nut]');
                var bang = goc.querySelector('[data-chon-bang]');
                var oTim = goc.querySelector('[data-chon-tim]');
                var trong = goc.querySelector('[data-chon-trong]');
                var oGiaTri = goc.querySelector('[data-chon-gia-tri]');
                var nhan = goc.querySelector('[data-chon-nhan]');
                var muc = Array.prototype.slice.call(goc.querySelectorAll('[data-gia-tri]'));

                // Bỏ dấu tiếng Việt để "hue" tìm ra "Huệ" — cùng cách với ô tìm
                // của bảng. Chữ đ phải xử riêng: NFD không tách nó thành d + dấu.
                function khongDau(s) {
                    return (s || '')
                        .normalize('NFD').replace(/[̀-ͯ]/g, '')
                        .replace(/đ/g, 'd').replace(/Đ/g, 'D')
                        .toLowerCase();
                }
                muc.forEach(function (m) { m._tim = khongDau(m.getAttribute('data-tim')); });

                var nhay = -1; // dòng bàn phím đang đứng, tính trên các dòng ĐANG HIỆN

                function dangHien() {
                    return muc.filter(function (m) { return !m.hidden; });
                }

                function toNhay(i) {
                    var ds = dangHien();
                    muc.forEach(function (m) { m.classList.remove('is-nhay'); });
                    if (!ds.length) { nhay = -1; return; }
                    nhay = Math.max(0, Math.min(i, ds.length - 1));
                    ds[nhay].classList.add('is-nhay');
                    // Cuộn theo phím: dòng đang đứng nằm ngoài tầm nhìn thì mũi tên
                    // xuống trông như không có tác dụng gì.
                    ds[nhay].scrollIntoView({ block: 'nearest' });
                }

                function loc() {
                    var q = khongDau(oTim.value.trim());
                    var con = 0;
                    muc.forEach(function (m) {
                        var khop = !q || m._tim.indexOf(q) !== -1;
                        m.hidden = !khop;
                        if (khop) con++;
                    });
                    trong.hidden = con !== 0;
                    toNhay(0);
                }

                function mo(co) {
                    goc.classList.toggle('is-open', co);
                    bang.hidden = !co;
                    nut.setAttribute('aria-expanded', co ? 'true' : 'false');
                    if (co) {
                        oTim.value = '';
                        loc();
                        // Con trỏ nhảy thẳng vào ô tìm: mở bảng ra là gõ được ngay,
                        // không phải bấm thêm một lần nữa vào đúng ô.
                        oTim.focus();
                    }
                }

                function chon(m) {
                    oGiaTri.value = m.getAttribute('data-gia-tri');
                    nhan.innerHTML = '';
                    nhan.appendChild(document.createTextNode(m.getAttribute('data-nhan') + ' '));
                    var ma = document.createElement('span');
                    ma.className = 'muted mono';
                    ma.textContent = '(' + m.getAttribute('data-gia-tri') + ')';
                    nhan.appendChild(ma);

                    muc.forEach(function (x) {
                        var la = x === m;
                        x.classList.toggle('is-chon', la);
                        x.setAttribute('aria-selected', la ? 'true' : 'false');
                    });
                    mo(false);
                    nut.focus();
                }

                nut.addEventListener('click', function () { mo(bang.hidden); });
                oTim.addEventListener('input', loc);
                muc.forEach(function (m) {
                    m.addEventListener('click', function () { chon(m); });
                });

                // Bấm ra ngoài thì đóng. Nghe ở document nhưng chỉ đóng khi điểm
                // bấm nằm NGOÀI bộ chọn — nghe ở chính nó thì bấm đâu cũng không
                // đóng được.
                document.addEventListener('click', function (e) {
                    if (!goc.contains(e.target) && !bang.hidden) mo(false);
                });

                // Bàn phím: lên/xuống chạy dòng, Enter chọn, Esc đóng.
                //
                // Esc phải chặn lan lên trên: <dialog> đóng bằng Esc, nên không
                // chặn thì một lần bấm vừa đóng bảng vừa đóng cả hộp thoại, và
                // người dùng mất hết thứ đã gõ.
                goc.addEventListener('keydown', function (e) {
                    if (bang.hidden) return;
                    if (e.key === 'ArrowDown') { e.preventDefault(); toNhay(nhay + 1); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); toNhay(nhay - 1); }
                    else if (e.key === 'Enter') {
                        e.preventDefault();
                        var ds = dangHien();
                        if (ds[nhay]) chon(ds[nhay]);
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        mo(false);
                        nut.focus();
                    }
                });
            })();

            // ----- Ô thời hạn: số + đơn vị (tháng / năm) -----
            //
            // Ô GỬI LÊN luôn là `so_thang` — API chỉ biết tháng. Nút đơn vị chỉ
            // đổi cách NHẬP: chọn "Năm" thì con số gõ vào được nhân 12 trước khi
            // ghi vào ô ẩn. Đổi cả cách lưu thì mỗi nơi đọc hợp đồng lại phải nhớ
            // hỏi "số này là tháng hay năm".
            function dungOHan(khung) {
                if (!khung) return null;

                var oSo = khung.querySelector('[data-han-so]');
                var oThang = khung.querySelector('[data-han-thang]');
                var nutDv = khung.querySelectorAll('[data-han-dv]');
                var donVi = 'thang';

                // Trần 60 tháng của API quy ra 5 năm. Không hạ trần theo đơn vị thì
                // gõ "10 năm" trông hợp lệ cho tới lúc bấm Lưu và ăn 422.
                function tran() { return donVi === 'nam' ? 5 : 60; }

                function ghi() {
                    var n = parseInt(oSo.value, 10);
                    if (!n || n < 1) n = 1;
                    if (n > tran()) { n = tran(); oSo.value = n; }
                    oThang.value = donVi === 'nam' ? n * 12 : n;
                }

                function datDonVi(dv) {
                    donVi = dv;
                    nutDv.forEach(function (n) {
                        n.classList.toggle('is-on', n.getAttribute('data-han-dv') === dv);
                    });
                    oSo.max = tran();
                    ghi();
                }

                nutDv.forEach(function (n) {
                    n.addEventListener('click', function () { datDonVi(n.getAttribute('data-han-dv')); });
                });
                oSo.addEventListener('input', ghi);

                return {
                    // Đặt theo SỐ THÁNG: chia hết cho 12 thì hiện bằng năm cho gọn
                    // ("1 năm" dễ đọc hơn "12 tháng"), còn lại giữ nguyên tháng.
                    datTheoThang: function (soThang) {
                        if (soThang >= 12 && soThang % 12 === 0) {
                            oSo.value = soThang / 12;
                            datDonVi('nam');
                        } else {
                            oSo.value = soThang;
                            datDonVi('thang');
                        }
                    },
                };
            }

            // Thời hạn đi theo chu kỳ của gói đang chọn, ở cả hai chế độ.
            function noiGoiVoiHan(boChon, khung) {
                var oHan = dungOHan(khung);
                if (!boChon || !oHan) return;

                function theoGoi(lanDau) {
                    // Lần đầu: giữ con số máy chủ trả lại sau một lượt lỗi.
                    var daCo = parseInt(khung.querySelector('[data-han-thang]').value, 10);
                    if (lanDau && daCo > 1) {
                        oHan.datTheoThang(daCo);
                        return;
                    }

                    var ck = boChon.options[boChon.selectedIndex].getAttribute('data-chu-ky');
                    oHan.datTheoThang(ck === 'nam' ? 12 : 1);
                }

                boChon.addEventListener('change', function () { theoGoi(false); });
                theoGoi(true);
            }

            var khungs = hop.querySelectorAll('[data-han]');
            noiGoiVoiHan(hop.querySelector('[data-ct-plan]'), khungs[0]);
            noiGoiVoiHan(hop.querySelector('[data-ky-plan]'), khungs[1]);

            // Máy chủ vừa trả lỗi: mở lại hộp ĐÚNG chế độ vừa gửi, kèm dữ liệu đã gõ.
            if (hop.hasAttribute('data-mo-san')) {
                dat(hop.getAttribute('data-che-do') || 'moi');
                hop.showModal();
                var sai = hop.querySelector('[data-form-che-do]:not([hidden]) .is-bad');
                if (sai) sai.focus();
            }
        })();
    </script>
@endpush
