{{--
    Hộp thoại "Thêm tài khoản dùng thử".

    MỘT lượt gửi dựng ba thứ: cửa hàng, tài khoản quản trị đầu tiên của khách
    (cả hai ở data plane), và hợp đồng dùng thử (control plane).

    KHÔNG có lời chú dưới từng ô. Mười dòng giải thích xếp chồng làm cái form
    dài gấp đôi và rốt cuộc không ai đọc; nhãn + ví dụ trong placeholder đã đủ,
    còn chỗ nào gõ sai thì câu báo lỗi hiện ra ngay dưới đúng ô đó — đúng lúc
    người ta cần nghe. Những điều khoản cần giải thích dài (giá và hạn mức chép
    từ bảng giá, khách đăng nhập bằng ba ô) nằm ở đầu tệp này và ở
    service.DungThuService, nơi người sửa code đọc.

    KHÔNG có ô email cho tài khoản đăng nhập: khách vào Sellio Order bằng ba ô
    (mã cửa hàng · tên đăng nhập · mật khẩu). `users.email` do máy chủ tự đặt.
    Ô "Email" dưới đây là email THẬT của khách, ghi vào sổ khách hàng.

    KHÔNG có ô "Họ tên người quản trị": máy chủ lấy theo ô "Người liên hệ" —
    chủ tiệm vừa là người mình gọi vừa là người ngồi gõ phần mềm.

    Giá và hạn mức cũng không có ô nào: hợp đồng thử chạy đúng theo gói đang
    bán, máy chủ chép từ bảng giá lúc ký. Thoả thuận riêng đi qua `cmd/thue-bao`.

    $moLai: máy chủ vừa trả lỗi thì mở sẵn hộp ra kèm dữ liệu đã gõ.
--}}
<dialog class="sheet" id="sheet-them" @if ($moLai) data-mo-san @endif>
    <div class="sheet-head">
        <div style="flex:1">
            <h3>Thêm tài khoản dùng thử</h3>
        </div>
        <button type="button" class="sheet-x" data-dong aria-label="Đóng">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <form method="POST" action="{{ route('platform.khach-hang-order.dung-thu.tao') }}">
        @csrf

        <div class="sheet-body">
            {{-- Lỗi KHÔNG khớp ô nào — đến từ tầng binding của Go, nơi trả về tên
                 trường Go ("MaCuaHang") chứ không phải tên ô. Không có khối này
                 thì form im lặng không nhận mà chẳng báo gì. --}}
            @php $loi_le = collect($errors->keys())->diff($oForm); @endphp
            @if ($loi_le->isNotEmpty())
                <p class="sheet-note is-bad">
                    @foreach ($loi_le as $k)
                        {{ $errors->first($k) }}<br>
                    @endforeach
                </p>
            @endif

            <div class="grid2">
                <div>
                    <label class="f-label" for="dt-plan">Gói dịch vụ <span class="req">*</span></label>
                    {{-- Mỗi <option> là một DÒNG bảng giá (gói × chu kỳ), không phải
                         một gói: "Cửa hàng" theo tháng và theo năm là hai dòng, hai
                         giá, hai bộ hạn mức — nên nhãn phải nói cả chu kỳ. --}}
                    <select class="form-select @error('plan_id') is-bad @enderror"
                            id="dt-plan" name="plan_id" data-plan required>
                        @foreach ($plans as $p)
                            @php $gia = data_get($p, 'price'); @endphp
                            <option value="{{ data_get($p, 'id') }}"
                                    data-thu="{{ (int) data_get($p, 'trial_days') }}"
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
                    <label class="f-label" for="dt-ngay">Số ngày dùng thử <span class="req">*</span></label>
                    {{-- BẮT BUỘC, nhưng bộ chọn gói bên cạnh tự điền sẵn số của gói
                         (xem script cuối tệp) nên người bán không phải gõ — chỉ phải
                         NHÌN. Đó là điểm của việc bắt buộc: bán một thời hạn mà chính
                         người bán không biết là bao nhiêu thì tới lúc khách hỏi
                         "được mấy ngày" mới đi tra ngược. --}}
                    <input type="number" class="form-control @error('so_ngay_dung_thu') is-bad @enderror"
                           id="dt-ngay" name="so_ngay_dung_thu" value="{{ old('so_ngay_dung_thu') }}"
                           min="1" max="180" required data-ngay-thu>
                    @error('so_ngay_dung_thu') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-ma">Mã cửa hàng <span class="req">*</span></label>
                    {{-- autofocus: thiếu nó thì <dialog> đặt tiêu điểm vào nút ✕. --}}
                    <input type="text" class="form-control mono @error('ma_cua_hang') is-bad @enderror"
                           id="dt-ma" name="ma_cua_hang" value="{{ old('ma_cua_hang') }}"
                           maxlength="30" placeholder="cuahangabc" required autofocus
                           autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                    @error('ma_cua_hang') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-ten">Tên cửa hàng <span class="req">*</span></label>
                    <input type="text" class="form-control @error('ten_cua_hang') is-bad @enderror"
                           id="dt-ten" name="ten_cua_hang" value="{{ old('ten_cua_hang') }}"
                           maxlength="150" placeholder="Cửa hàng ABC" required autocomplete="off">
                    @error('ten_cua_hang') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-user">Tên đăng nhập <span class="req">*</span></label>
                    <input type="text" class="form-control mono @error('ten_dang_nhap') is-bad @enderror"
                           id="dt-user" name="ten_dang_nhap" value="{{ old('ten_dang_nhap', 'admin') }}"
                           maxlength="50" required
                           autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                    @error('ten_dang_nhap') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-pass">Mật khẩu <span class="req">*</span></label>
                    {{-- type="text" chứ không "password": người bán phải ĐỌC được cái
                         vừa gõ để đọc cho khách nghe. Che đi chỉ tạo ra một mật khẩu
                         gõ nhầm mà không ai biết tới lúc khách thử đăng nhập. --}}
                    <input type="text" class="form-control mono @error('mat_khau') is-bad @enderror"
                           id="dt-pass" name="mat_khau" value="{{ old('mat_khau') }}"
                           minlength="6" required placeholder="tối thiểu 6 ký tự"
                           autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">
                    @error('mat_khau') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-lienhe">Người liên hệ</label>
                    <input type="text" class="form-control @error('nguoi_lien_he') is-bad @enderror"
                           id="dt-lienhe" name="nguoi_lien_he" value="{{ old('nguoi_lien_he') }}"
                           maxlength="150" placeholder="Anh Huy" autocomplete="off">
                    @error('nguoi_lien_he') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-sdt">Số điện thoại</label>
                    <input type="tel" class="form-control mono @error('dien_thoai') is-bad @enderror"
                           id="dt-sdt" name="dien_thoai" value="{{ old('dien_thoai') }}"
                           maxlength="20" placeholder="09xxxxxxxx" autocomplete="off">
                    @error('dien_thoai') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-email">Email</label>
                    <input type="email" class="form-control @error('email_lien_he') is-bad @enderror"
                           id="dt-email" name="email_lien_he" value="{{ old('email_lien_he') }}"
                           maxlength="150" placeholder="huy@quochuy.vn" autocomplete="off">
                    @error('email_lien_he') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="dt-ghichu">Ghi chú</label>
                    <input type="text" class="form-control @error('ghi_chu') is-bad @enderror"
                           id="dt-ghichu" name="ghi_chu" value="{{ old('ghi_chu') }}"
                           maxlength="500" placeholder="anh Sơn giới thiệu" autocomplete="off">
                    @error('ghi_chu') <p class="field-bad">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="sheet-foot">
            <button type="button" class="btn-ghost" data-dong>Đóng</button>
            <button type="submit" class="btn btn-plum">Mở tài khoản</button>
        </div>
    </form>
</dialog>

@push('scripts')
    <script>
        (function () {
            var hop = document.getElementById('sheet-them');
            if (!hop) return;

            // Máy chủ vừa trả lỗi: mở sẵn hộp kèm dữ liệu đã gõ. showModal() trong
            // luồng dựng trang là hợp lệ — đây không phải cửa sổ tự bật, mà là hộp
            // người dùng vừa gửi đi và bị trả lại.
            if (hop.hasAttribute('data-mo-san')) {
                hop.showModal();
                var sai = hop.querySelector('.is-bad');
                if (sai) sai.focus();
            }

            // Ô "số ngày dùng thử" là ô BẮT BUỘC, và bộ chọn gói điền hộ nó.
            //
            // Điền GIÁ TRỊ chứ không phải placeholder: ô bắt buộc mà để trống thì
            // trình duyệt chặn lúc bấm Lưu, và bắt người bán tự gõ lại đúng con số
            // đang nằm sẵn trong bảng giá là bắt làm một việc máy đã biết.
            //
            // Gói chưa khai ngày dùng thử thì để TRỐNG — lúc đó ô bắt buộc làm đúng
            // việc của nó: chặn ngay tại chỗ, thay vì để bấm Lưu rồi ăn 422 từ máy
            // chủ (bên Go cũng từ chối, xem DungThuService.Tao).
            var boChon = hop.querySelector('[data-plan]');
            var oNgay = hop.querySelector('[data-ngay-thu]');
            if (!boChon || !oNgay) return;

            function theoGoi(lanDau) {
                // Lần đầu: giữ nguyên số máy chủ trả lại sau một lượt lỗi. Ghi đè nó
                // là xoá mất thứ người ta vừa gõ.
                if (lanDau && oNgay.value !== '') return;

                var thu = boChon.options[boChon.selectedIndex].getAttribute('data-thu');
                oNgay.value = (thu && thu !== '0') ? thu : '';
                oNgay.placeholder = (thu && thu !== '0') ? '' : 'gói này chưa khai — tự nhập';
            }

            // Đổi gói là điền lại theo gói mới, kể cả khi người ta đã sửa tay: đổi
            // gói nghĩa là đổi thứ đang bán, và con số cũ thuộc về gói cũ.
            boChon.addEventListener('change', function () { theoGoi(false); });
            theoGoi(true);
        })();
    </script>
@endpush
