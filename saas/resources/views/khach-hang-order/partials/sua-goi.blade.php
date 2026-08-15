{{--
    Hộp thoại "Sửa mức giá".

    MỘT hộp thoại dùng chung cho MỌI dòng: nút Sửa trên từng dòng mang sẵn dữ
    liệu của dòng đó ở các thuộc tính data-*, JS chép sang rồi mở. Dựng mỗi dòng
    một <dialog> thì bảng giá có bao nhiêu dòng là bấy nhiêu bản sao của cùng
    một form nằm trong DOM, và sửa nhãn một ô phải sửa ở một chỗ mà hỏng ở mười.

    SỬA ĐƯỢC GÌ: tên, mô tả, giá, số ngày dùng thử, còn bán hay không.

    KHÔNG SỬA ĐƯỢC mã gói, phần mềm và chu kỳ — chúng chỉ HIỆN RA ở đầu hộp để
    biết đang sửa dòng nào. Bộ ba đó là danh tính của dòng bảng giá (khoá duy
    nhất của bảng), và hợp đồng đã ký tra tên gói về đây theo mã: đổi mã của một
    dòng đang có khách là làm hợp đồng của họ trỏ vào hư không mà không có gì
    báo. Muốn một mức giá khác thì thêm dòng mới.

    SỬA Ở ĐÂY KHÔNG ĐỤNG TỚI KHÁCH ĐANG DÙNG. Thuê bao chép giá và hạn mức ra
    lúc ký rồi sống độc lập với bảng giá — đó là lý do hạ giá gói không hạ tiền
    của người đã ký. Muốn đổi giá của MỘT khách thì sửa hợp đồng của khách đó.
--}}
@php
    // Máy chủ vừa từ chối một lượt lưu: mở lại đúng hộp đó kèm những gì đã gõ.
    // Không có bước này thì một lỗi validate xoá sạch cái người ta vừa nhập.
    $suaLai = old('_sua_id');
@endphp
<dialog class="sheet" id="sheet-gia" @if ($suaLai) data-mo-san="{{ $suaLai }}" @endif>
    <div class="sheet-head">
        <div style="flex:1">
            <h3>Sửa mức giá</h3>
            {{-- Danh tính của dòng đang sửa. Ở đây chứ không phải trong thân
                 hộp: nó là câu trả lời cho "đang sửa cái nào", thứ phải đọc
                 được trước khi gõ ô đầu tiên. --}}
            <p><span class="mono" data-sg-ma></span> · <span data-sg-chu-ky></span></p>
        </div>
        <button type="button" class="sheet-x" data-dong aria-label="Đóng">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    {{-- action để trống, JS đặt theo dòng được bấm. --}}
    <form method="POST" action="" data-sg-form>
        @csrf
        <input type="hidden" name="_sua_id" data-sg-id>

        <div class="sheet-body">
            <div class="grid2">
                <div class="rong">
                    <label class="f-label" for="sg-ten">Tên gói <span class="req">*</span></label>
                    <input type="text" class="form-control" id="sg-ten" name="name"
                           maxlength="100" required autocomplete="off" data-sg-ten>
                </div>

                <div class="rong">
                    <label class="f-label" for="sg-tagline">Mô tả ngắn</label>
                    <input type="text" class="form-control" id="sg-tagline" name="tagline"
                           maxlength="255" placeholder="Cho một cửa hàng bán đều tay"
                           autocomplete="off" data-sg-tagline>
                </div>

                <div>
                    <label class="f-label" for="sg-gia">Giá một chu kỳ</label>
                    {{-- type="text" chứ không "number": ô số của trình duyệt không
                         cho chấm phân cách nghìn, mà "1499000" là con số không ai
                         đọc đúng từ lần đầu. JS chấm hộ khi gõ, controller bóc hết
                         ký tự không phải chữ số trước khi gửi đi. --}}
                    <div class="o-don-vi">
                        <input type="text" class="form-control mono" id="sg-gia" name="gia"
                               inputmode="numeric" autocomplete="off" data-sg-gia>
                        <div class="don-vi"><span style="padding:4px 9px;font-size:12px;color:var(--ink-3)">₫</span></div>
                    </div>
                    {{-- "Liên hệ" là một GIÁ TRỊ, không phải ô trống bỏ quên: gói
                         Chuỗi cố ý chưa công bố giá. Nó khác hẳn giá 0 (miễn phí),
                         nên phải có một chỗ bấm được để nói ra điều đó — để trống ô
                         giá cũng ra cùng kết quả, nhưng người gõ không đoán được
                         điều đó nếu không có dòng này. --}}
                    <label class="f-check">
                        <input type="checkbox" name="lien_he" value="1" data-sg-lienhe>
                        <span>Chưa công bố giá — hiện “Liên hệ”</span>
                    </label>
                </div>

                <div>
                    <label class="f-label" for="sg-thu">Số ngày dùng thử</label>
                    {{-- 0 = gói này không cho dùng thử. Con số ở đây là thứ màn hình
                         "Thêm tài khoản dùng thử" điền sẵn khi chọn gói. --}}
                    <input type="number" class="form-control" id="sg-thu" name="trial_days"
                           min="0" max="365" data-sg-thu>

                    <label class="f-label" for="sg-tt" style="margin-top:12px">Trạng thái</label>
                    <select class="form-select" id="sg-tt" name="status" data-sg-tt>
                        <option value="active">Đang bán</option>
                        {{-- Ngừng bán KHÔNG xoá dòng và KHÔNG đụng tới khách đang
                             dùng gói này — chỉ là thôi bán mới. --}}
                        <option value="retired">Ngừng bán</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="sheet-foot">
            <button type="button" class="btn-ghost" data-dong>Đóng</button>
            <button type="submit" class="btn btn-plum">Lưu mức giá</button>
        </div>
    </form>
</dialog>

@push('scripts')
    <script>
        (function () {
            var hop = document.getElementById('sheet-gia');
            if (!hop) return;

            var form = hop.querySelector('[data-sg-form]');
            var o = {
                id: hop.querySelector('[data-sg-id]'),
                ma: hop.querySelector('[data-sg-ma]'),
                chuKy: hop.querySelector('[data-sg-chu-ky]'),
                ten: hop.querySelector('[data-sg-ten]'),
                tagline: hop.querySelector('[data-sg-tagline]'),
                gia: hop.querySelector('[data-sg-gia]'),
                lienHe: hop.querySelector('[data-sg-lienhe]'),
                thu: hop.querySelector('[data-sg-thu]'),
                tt: hop.querySelector('[data-sg-tt]')
            };

            // Chấm phân cách nghìn theo kiểu Việt Nam. Chỉ để NHÌN — controller
            // bóc lại hết ký tự không phải chữ số trước khi gửi sang API.
            function chamNghin(s) {
                s = String(s).replace(/\D/g, '');
                return s === '' ? '' : s.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }

            o.gia.addEventListener('input', function () {
                this.value = chamNghin(this.value);
            });

            // Tích "Liên hệ" thì ô giá tắt và trống: để nguyên một con số mờ mờ
            // bên cạnh một ô đã tích là hai câu trả lời khác nhau cho cùng một
            // câu hỏi, và người đọc không biết cái nào thắng.
            function theoLienHe() {
                o.gia.disabled = o.lienHe.checked;
                if (o.lienHe.checked) o.gia.value = '';
            }
            o.lienHe.addEventListener('change', theoLienHe);

            function mo(nut) {
                form.setAttribute('action', nut.getAttribute('data-action'));
                o.id.value = nut.getAttribute('data-id');
                o.ma.textContent = nut.getAttribute('data-ma');
                o.chuKy.textContent = nut.getAttribute('data-chu-ky');
                o.ten.value = nut.getAttribute('data-ten') || '';
                o.tagline.value = nut.getAttribute('data-tagline') || '';

                var gia = nut.getAttribute('data-gia') || '';
                o.lienHe.checked = gia === '';
                o.gia.value = chamNghin(gia);
                theoLienHe();

                o.thu.value = nut.getAttribute('data-thu') || '0';
                o.tt.value = nut.getAttribute('data-trang-thai') === 'retired' ? 'retired' : 'active';

                hop.showModal();
                o.ten.focus();
                o.ten.select();
            }

            document.querySelectorAll('[data-sua]').forEach(function (nut) {
                nut.addEventListener('click', function () { mo(nut); });
            });

            // Đóng: nút ✕, nút Đóng, và bấm ra nền. Xử lý ngay trong partial này
            // chứ không dựa vào trang chủ quản — màn hình Các gói dịch vụ không có
            // sẵn đoạn mở/đóng hộp thoại nào như màn hình Hợp đồng.
            hop.querySelectorAll('[data-dong]').forEach(function (nut) {
                nut.addEventListener('click', function () { hop.close(); });
            });
            // ::backdrop không nhận sự kiện riêng, nên nhận ở chính <dialog> rồi
            // đối chiếu: mọi thứ bên trong đều nằm trong .sheet-*, nên target là
            // chính dialog nghĩa là đã bấm ra nền.
            hop.addEventListener('click', function (e) {
                if (e.target === hop) hop.close();
            });

            // Máy chủ vừa trả lỗi: mở lại đúng dòng đó, rồi đè những gì đã gõ lên
            // trên. Lấy dữ liệu nền từ chính cái nút của dòng đó nên hộp không bao
            // giờ mở ra với mã gói hay chu kỳ trống.
            var moLai = hop.getAttribute('data-mo-san');
            if (moLai) {
                var nut = document.querySelector('[data-sua][data-id="' + moLai + '"]');
                if (nut) {
                    mo(nut);
                    @if (old('_sua_id'))
                        o.ten.value = @json(old('name'));
                        o.tagline.value = @json(old('tagline'));
                        o.lienHe.checked = {{ old('lien_he') ? 'true' : 'false' }};
                        o.gia.value = chamNghin(@json(old('gia')));
                        theoLienHe();
                        o.thu.value = @json(old('trial_days'));
                        o.tt.value = @json(old('status')) === 'retired' ? 'retired' : 'active';
                    @endif
                }
            }
        })();
    </script>
@endpush
