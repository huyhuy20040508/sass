<script>
    // ================= HỘP TẠO / SỬA HÀNG HOÁ =================
    // Ruột hộp là markup tĩnh dựng theo #modalCreate của v2 (xem _modal.blade.php);
    // tệp này chỉ lo ĐIỀN vào và GỬI đi. Mọi thao tác ghi đi bằng form POST ẩn:
    // trang tải lại, toast do session bắn ra — cùng cách với các màn v2 khác.
    (function () {
        const DVT = @json($units);
        const THUOC_TINH = @json($attributes);           // [{id, name, values:[{id, name}]}]
        const MA_TU_SINH = @json($maTuSinh ?? false);
        const URL_ANH = @json(route('admin.products.uploadImage'));
        const ANH_MAC_DINH = @json(asset('v2/images/image_defaul.png'));

        const $hop = $('#modalCreate');
        let anh = '';          // đường dẫn ảnh đã tải lên
        let dangSua = 0;       // 0 = tạo mới
        let bienTheCu = [];    // biến thể đang có, để giữ lại id khi lưu

        const soThoi = (v) => String(v == null ? '' : v).replace(/[^\d]/g, '');
        const tien = (v) => (Number(v) || 0).toLocaleString('vi-VN');
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        // ---------- Giá sau thuế: đọc được ngay chứ không phải bấm máy tính ----------
        function veGiaSauThue() {
            const gia = Number(soThoi($hop.find('.ip_sale_price').val())) || 0;
            const vat = Number($hop.find('.select_vat_value').val());
            // Hai mã âm (KCT / KKKNT) không phải phần trăm nên không cộng thêm gì.
            const sau = vat > 0 ? Math.round(gia * (100 + vat) / 100) : gia;
            $hop.find('.ip_sale_price_after_tax').val(tien(sau));
        }
        $hop.on('input', '.ip_sale_price', veGiaSauThue);
        $hop.on('change', '.select_vat_value', veGiaSauThue);

        // ---------- Thuế: chọn LOẠI trước, ô "% VAT" bày mức của loại ấy ----------
        // Loại thuế không lưu vào mặt hàng (mặt hàng chỉ giữ một số `vat`); nó là
        // cái phễu lọc cho ô bên cạnh, đúng vai của nó bên v2.
        function veMucThue(giuLai) {
            const cu = giuLai === undefined ? $hop.find('.select_vat_value').val() : String(giuLai);
            const ds = String($hop.find('.select_tax_type option:selected').data('muc') || '')
                .split(',').filter((s) => s !== '');
            const $o = $hop.find('.select_vat_value').empty();
            ds.forEach((m) => $o.append(new Option(nhanMuc(m), m)));
            // Mức đang chọn còn nằm trong loại mới thì giữ; không thì lấy mức đầu.
            $o.val(ds.includes(String(cu)) ? String(cu) : (ds[0] || '0')).trigger('change');
        }

        /** Nhãn của một mức: hai số âm là MÃ hoá đơn điện tử, không phải phần trăm. */
        function nhanMuc(m) {
            const n = Number(m);
            if (n === -1) return 'KCT';
            if (n === -2) return 'KKKNT';

            return n + '%';
        }

        $hop.on('change', '.select_tax_type', function () { veMucThue(); });

        /**
         * Đặt ô % VAT về một mức cụ thể, kèm chuyển LOẠI thuế cho khớp.
         *
         * Mỗi loại chỉ bày được mức của riêng nó, nên đặt thẳng `val` là hỏng khi
         * mức ấy không thuộc loại đang đứng: mặt hàng khai 12% (thuế tiêu thụ đặc
         * biệt) mà ô đang ở "Thuế mặc định" (0/8/10) thì 12 không có trong danh
         * sách, select rơi về mức đầu và người dùng thấy 0%.
         */
        function datMucThue(vat) {
            const $ot = $hop.find('.select_tax_type');
            const hop = $ot.find('option').filter(function () {
                return String($(this).data('muc') || '').split(',').includes(String(vat));
            }).first();
            if (hop.length && hop.val() !== $ot.val()) {
                $ot.val(hop.val());
            }
            veMucThue(vat);
        }

        // Chọn nhóm hàng thì ô thuế tự điền theo mức của nhóm — quy tắc của v2.
        $hop.on('change', '.select_menu_group', function () {
            const vat = $(this).find('option:selected').data('vat');
            if (vat === undefined || vat === '') return;
            datMucThue(vat);
        });

        // ---------- Ảnh: tải lên ngay lúc chọn, form chỉ mang đường dẫn ----------
        $hop.on('change', '.ip_img', function () {
            const f = this.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('image', f);
            fd.append('_token', CSRF_HH);
            $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((r) => { anh = r.url; $hop.find('#img-preview').attr('src', r.url); })
                .fail((x) => window.PRD.toast((x.responseJSON && x.responseJSON.message) || 'Không tải được ảnh lên.'));
        });

        // ---------- Quy đổi đơn vị ----------
        // Bỏ ĐƠN VỊ TÍNH CHÍNH khỏi ô chọn, và mở đầu bằng một dòng trống.
        //
        // Để nguyên cả danh sách thì dòng mới sinh ra đã trúng sẵn đơn vị chính —
        // đúng cái mà cả hộp thoại lẫn API đều từ chối ("1 Cái = 5 Cái"), nên
        // người dùng bấm Thêm rồi bấm Lưu là ăn ngay một câu lỗi mà họ không hề
        // chọn gì. Dòng trống buộc phải chọn, thay vì nhận bừa một giá trị sai.
        const oDonVi = (chon) => {
            const chinh = Number($hop.find('.select_menu_unit').val() || 0);

            return '<option value="">-- chọn đơn vị --</option>' + DVT
                .filter((u) => Number(u.id) !== chinh || Number(chon) === Number(u.id))
                .map((u) => `<option value="${u.id}" ${Number(chon) === Number(u.id) ? 'selected' : ''}>${esc(u.name)}</option>`)
                .join('');
        };

        // Hàng đọc theo đúng một chiều: 1 <đơn vị quy đổi> = <số> <đơn vị tính chính>.
        //
        // Chiều này là của API chứ không phải chọn cho đẹp: `QuyDoiDonVi.Quantity`
        // là "số đơn vị tính CHÍNH đổi ra từ MỘT đơn vị quy đổi", và phiếu mua
        // hàng tính thẳng `BaseQuantity = Quantity × UnitRatio` từ nó. Đặt ô nhập
        // ở cột đầu là màn hình đọc thành "24 Thùng = 1 Cái" trong khi số gửi đi
        // vẫn mang nghĩa "1 Thùng = 24 Cái" — người khai gõ đúng mà hiểu ngược,
        // và tới lúc nhập kho mới lòi ra sai số gấp mấy chục lần.
        function dongDonVi(c) {
            return `<tr class="dong-quy-doi">
                <td><input type="text" class="form-control" value="1" disabled data-khoa-san></td>
                <td><select class="form-select qd-dvt">${oDonVi(c ? c.unit_id : 0)}</select></td>
                <td class="text-center">=</td>
                <td><input type="text" inputmode="decimal" class="form-control qd-so" value="${c ? esc(c.quantity) : ''}" placeholder="0"></td>
                <td class="qd-dvt-chinh"></td>
                <td class="text-center"><i class="fa fa-times text-danger qd-xoa" role="button"></i></td>
            </tr>`;
        }

        function veDvtChinh() {
            const ten = $hop.find('.select_menu_unit option:selected').text().split('·').pop().trim();
            $hop.find('.qd-dvt-chinh').text(Number($hop.find('.select_menu_unit').val()) > 0 ? ten : '');
        }
        $hop.on('change', '.select_menu_unit', veDvtChinh);

        $hop.on('click', '.add-unit', function () {
            // Chưa có đơn vị tính chính thì dòng quy đổi không có nghĩa: vế phải
            // của "1 Thùng = 24 ___" bỏ trống, và số khai ra không quy về đâu cả.
            if (!Number($hop.find('.select_menu_unit').val() || 0)) {
                window.PRD.toast('Chọn đơn vị tính của mặt hàng trước khi khai quy đổi.');
                return;
            }
            $('#list_unit').append(dongDonVi(null));
            veDvtChinh();
            $('#collapseMenuUnit').addClass('show');
        });
        $hop.on('click', '.close-all-unit', () => $('#list_unit').empty());
        $hop.on('click', '.qd-xoa', function () { $(this).closest('tr').remove(); });

        // ---------- Thuộc tính → biến thể ----------
        //
        // Khuôn lấy của bản v2 cũ (menu/menu/index): mỗi THUỘC TÍNH là một hàng
        // trong bảng, ruột hàng là nhiều DÒNG CHI TIẾT — mỗi dòng một giá trị,
        // có ô tick "Mặc định" và ô "Giá trị cộng thêm", kéo thả đổi thứ tự
        // được. Nút [+] thêm dòng chi tiết, nút [×] bỏ cả hàng.
        //
        // Hai chỗ ánh xạ khác v2, vì bên này là BÁN LẺ chứ không phải F&B:
        //   - "Giá trị cộng thêm" v2 lưu thành phụ phí của từng giá trị. Bên này
        //     mỗi biến thể là một mã hàng bán được, có giá riêng, nên số ấy được
        //     CỘNG VÀO giá của biến thể ở bảng dưới thay vì lưu rời.
        //   - "Mặc định" của v2 là mặc định theo từng chiều. Bên này tổ hợp gồm
        //     đúng các giá trị mặc định sẽ thành biến thể mặc định của mặt hàng
        //     (ProductVariant.IsDefault).
        let demKhoa = 0;

        function dongThuocTinh(chonId) {
            const khoa = 'tt' + (++demKhoa);
            const opts = THUOC_TINH.map((a) =>
                `<option value="${a.id}" ${Number(chonId) === Number(a.id) ? 'selected' : ''}>${esc(a.name)}</option>`).join('');

            return `<tr class="dong-thuoc-tinh" data-khoa="${khoa}">
                <td><select class="form-select tt-chon"><option value="">{{ __('message.chose') }}</option>${opts}</select></td>
                {{-- Không lặp lại hàng tiêu đề ở đây: ba nhãn con nằm một lần
                     duy nhất trên <thead> của bảng, dùng chung bộ bề rộng. --}}
                <td class="tt-chi-tiet"></td>
                <td class="tt-thao-tac">
                    <i class="fa fa-plus tt-them-dong" role="button" title="Thêm một dòng"></i>
                    <i class="fa fa-minus tt-bot-dong" role="button" title="Bớt một dòng"></i>
                </td>
            </tr>`;
        }

        /** Một dòng chi tiết: kéo thả · mặc định · giá trị · giá cộng thêm. */
        function dongChiTiet(khoa, aid, chonVid, cong) {
            const a = THUOC_TINH.find((x) => Number(x.id) === Number(aid));
            const opts = ((a || {}).values || []).map((v) =>
                `<option value="${v.id}" ${Number(chonVid) === Number(v.id) ? 'selected' : ''}>${esc(v.name)}</option>`).join('');

            return `<div class="d-flex tt-dong" draggable="true">
                <span class="tt-o-keo tt-keo" title="Kéo để đổi thứ tự"><i class="fa fa-grip-vertical"></i></span>
                <span class="tt-o-md"><input type="radio" class="form-check-input tt-mac-dinh" name="md-${khoa}"></span>
                <span class="tt-o-gt"><select class="form-select tt-gia-tri"><option value="">{{ __('message.chose') }}</option>${opts}</select></span>
                <span class="tt-o-them"><input type="text" inputmode="numeric" class="form-control format-money tt-cong-them" value="${cong ? tien(cong) : '0'}"></span>
            </div>`;
        }

        /** Thêm một dòng chi tiết vào hàng thuộc tính đang đứng. */
        function themChiTiet($tr, vid, cong) {
            const aid = $tr.find('.tt-chon').val();
            if (!aid) return;
            $tr.find('.tt-chi-tiet').append(dongChiTiet($tr.data('khoa'), aid, vid, cong));
        }

        $hop.on('click', '.add-attribute-select', function () {
            $('#collapseAttribute').addClass('show');
            $('#add-attribute').append(dongThuocTinh(null));
        });

        // Chọn thuộc tính: chặn trùng chiều, rồi dựng sẵn một dòng chi tiết.
        $hop.on('change', '.tt-chon', function () {
            const $tr = $(this).closest('tr');
            const aid = $(this).val();
            const trung = $('#add-attribute .tt-chon').not(this)
                .filter(function () { return $(this).val() === aid && aid !== ''; }).length > 0;
            if (trung) {
                toastr.error('Thuộc tính này đã khai ở hàng trên rồi.');
                $(this).val('');

                return;
            }
            $tr.find('.tt-dong').remove();
            if (aid) themChiTiet($tr, null, 0);
            veBienThe();
        });

        $hop.on('click', '.tt-them-dong', function () { themChiTiet($(this).closest('tr'), null, 0); });

        // Dấu trừ bớt ĐÚNG MỘT dòng, tính từ dưới lên. Hết dòng thì bỏ luôn cả
        // hàng thuộc tính — không để lại một hàng rỗng không nghĩa lý gì.
        $hop.on('click', '.tt-bot-dong', function () {
            const $tr = $(this).closest('tr');
            const $ds = $tr.find('.tt-dong');
            $ds.length > 1 ? $ds.last().remove() : $tr.remove();
            veBienThe();
        });

        // Chặn hai dòng cùng một giá trị trong CÙNG một thuộc tính.
        $hop.on('change', '.tt-gia-tri', function () {
            const $dong = $(this).closest('.tt-dong');
            const val = $(this).val();
            const trung = $dong.siblings('.tt-dong').find('.tt-gia-tri')
                .filter(function () { return $(this).val() === val && val !== ''; }).length > 0;
            if (trung) {
                toastr.error('Giá trị này đã có trong thuộc tính rồi.');
                $(this).val('');
            }
            veBienThe();
        });

        $hop.on('input', '.tt-cong-them', veBienThe);
        $hop.on('change', '.tt-mac-dinh', veBienThe);

        // Kéo thả đổi thứ tự dòng chi tiết — viết bằng drag native, không kéo
        // thêm jQuery UI chỉ để làm mỗi việc này.
        let $dangKeo = null;
        $hop.on('dragstart', '.tt-dong', function (e) {
            $dangKeo = $(this);
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            // Firefox không bắt đầu kéo nếu không đặt dữ liệu.
            e.originalEvent.dataTransfer.setData('text/plain', '');
        });
        $hop.on('dragover', '.tt-dong', function (e) {
            if (!$dangKeo || $dangKeo.parent()[0] !== this.parentNode) return;
            e.preventDefault();
            const o = this.getBoundingClientRect();
            $dangKeo[e.originalEvent.clientY < o.top + o.height / 2 ? 'insertBefore' : 'insertAfter'](this);
        });
        $hop.on('drop dragend', '.tt-dong', function (e) {
            e.preventDefault();
            $dangKeo = null;
            veBienThe();
        });

        /**
         * Nhân tổ hợp: mỗi chiều thuộc tính nhân với các chiều còn lại.
         *
         * Mỗi giá trị mang theo hai thứ của bản v2: `cong` (giá trị cộng thêm) và
         * `md` (có phải giá trị mặc định của chiều ấy không).
         */
        function toHop() {
            const chieu = [];
            $('#add-attribute .dong-thuoc-tinh').each(function () {
                const aid = Number($(this).find('.tt-chon').val() || 0);
                if (!aid) return;
                const a = THUOC_TINH.find((x) => Number(x.id) === aid);
                const ds = [];
                $(this).find('.tt-dong').each(function () {
                    const vid = Number($(this).find('.tt-gia-tri').val() || 0);
                    if (!vid) return;
                    ds.push({
                        attribute_id: aid,
                        value_id: vid,
                        ten: ((a.values || []).find((v) => Number(v.id) === vid) || {}).name || '',
                        cong: Number(soThoi($(this).find('.tt-cong-them').val())) || 0,
                        md: $(this).find('.tt-mac-dinh').is(':checked'),
                    });
                });
                if (ds.length) chieu.push(ds);
            });

            return chieu.reduce((tich, ds) => tich.flatMap((t) => ds.map((v) => t.concat([v]))), [[]]);
        }

        const khoaToHop = (attrs) => attrs.map((a) => a.attribute_id + ':' + a.value_id)
            .sort().join('|');

        /**
         * Vẽ lại phần phụ thuộc vào tổ hợp.
         *
         * KHÔNG còn bảng liệt kê từng tổ hợp: tab này để KHAI THUỘC TÍNH, không
         * phải để khai từng mặt hàng con. Tổ hợp được dựng ngay lúc Lưu — mã
         * hàng của mỗi biến thể do máy chủ tự đặt, giá lấy theo giá bán cộng phụ
         * phí đã khai ở dòng chi tiết.
         */
        function veBienThe() {
            const co = toHop().filter((t) => t.length).length > 0;
            // Hàng nhiều biến thể: mã vạch chung dán cho biến thể MẶC ĐỊNH.
            $hop.find('.ip_barcode_hint').text(co
                ? 'Mặt hàng có nhiều biến thể — mã vạch này dán cho biến thể mặc định.'
                : '');
        }

        // Đổi giá bán thì giá của các biến thể tính lại theo.
        $hop.on('input', '.ip_sale_price', veBienThe);

        /**
         * Dựng danh sách biến thể để gửi đi, từ chính các dòng chi tiết.
         *
         * Giữ lại id / mã / mã vạch / giá của biến thể CŨ cùng tổ hợp: sửa tên
         * mặt hàng không được làm mất mã vạch và giá riêng đã khai trước đó.
         */
        function dungBienThe(giaGoc, maVach) {
            const cu = new Map(bienTheCu.map((v) => [khoaToHop(v.attributes || []), v]));

            return toHop().filter((t) => t.length).map((t) => {
                const c = cu.get(khoaToHop(t)) || {};
                const cong = t.reduce((s, x) => s + (x.cong || 0), 0);
                // Tổ hợp gồm ĐÚNG các giá trị mặc định của mọi chiều = biến thể
                // mặc định của mặt hàng.
                const macDinh = t.every((x) => x.md);

                return {
                    id: c.id || 0,
                    is_default: macDinh ? 1 : 0,
                    name: t.map((x) => x.ten).join(' / '),
                    sku: c.sku || '',
                    // Mã vạch chung chỉ dán cho biến thể mặc định: cột UNIQUE, hai
                    // biến thể cùng một mã vạch là máy chủ từ chối cả lượt lưu.
                    barcode: c.barcode || (macDinh ? maVach : ''),
                    price: c.price != null ? c.price : (giaGoc ? Number(giaGoc) + cong : ''),
                    cost_price: c.cost_price != null ? c.cost_price : '',
                    attributes: t.map((x) => ({ attribute_id: x.attribute_id, value_id: x.value_id })),
                };
            });
        }

        // ---------- Mở hộp ----------
        function xoaTrang() {
            $hop.find('input[type=text]').val('');
            $hop.find('textarea').val('');
            $hop.find('.ip_img').val('');
            $hop.find('#img-preview').attr('src', ANH_MAC_DINH);
            $hop.find('.ip_status, .print_label, .is_stock_deducted').prop('checked', true);
            $hop.find('.is_serial').prop('checked', false);
            $hop.find('.select_menu_group, .select_menu_unit, .select_location').val('').trigger('change');
            $hop.find('.select_tax_type').prop('selectedIndex', 0);
            veMucThue(0);
            $hop.find('.select_branch, .select_tag').val([]).trigger('change');
            $('#list_unit, #add-attribute').empty();
            anh = '';
            dangSua = 0;
            bienTheCu = [];
            veBienThe();
        }

        /**
         * Bật/tắt chế độ CHỈ XEM.
         *
         * Bấm vào MÃ hàng hoá là đi xem, bấm nút bút chì mới là đi sửa — hai việc
         * khác nhau nên không dùng chung một hộp mở toang. Chế độ xem khoá mọi ô
         * và giấu nút Xác nhận, để không ai lỡ tay đổi rồi lưu.
         *
         * Ô nào vốn đã khoá (mã tự sinh, giá sau thuế, ô "= 1" của dòng quy đổi)
         * mang sẵn `data-khoa-san`: lúc trả về chế độ sửa phải giữ chúng khoá,
         * không thì mở toang cả những ô lẽ ra không cho gõ.
         */
        function datCheDo(chiXem) {
            $hop.toggleClass('dang-xem', !!chiXem);
            // CHỈ khoá ô nhập liệu. `:input` của jQuery gom cả thẻ <button>, nên
            // dùng nó là khoá luôn nút X và nút Đóng — vào chế độ xem rồi không
            // có đường thoát ra. Mấy nút làm đổi nội dung đã giấu bằng CSS.
            $hop.find('input, select, textarea').prop('disabled', !!chiXem);
            if (!chiXem) {
                $hop.find('[data-khoa-san]').prop('disabled', true);
            }
            // select2 chỉ đổi dáng khi được báo; không bắn thì ô vẫn bấm được.
            $hop.find('select.select2').trigger('change.select2');
            $hop.find('#confirm_create_menu').toggleClass('d-none', !!chiXem);
        }

        window.moHopHangHoa = function (p, chiXem) {
            // Mở khoá trước đã, không thì lượt xem trước còn khoá và lượt điền
            // của lượt này ghi vào mấy ô đang disabled.
            datCheDo(false);
            xoaTrang();
            $hop.find('.modal-title').text(
                chiXem ? '{{ __('message.detail') }}'
                    : (p ? '{{ __('message.edit') }}' : '{{ __('message.create_new') }}')
            );
            $hop.find('.nav-detail .nav-link').first().tab('show');

            if (p) {
                dangSua = p.id;
                $hop.find('.ip_code').val(p.sku || '');
                $hop.find('.ip_name').val(p.name || '');
                $hop.find('.ip_sale_price').val(tien(p.base_price));
                $hop.find('.ip_cost_price').val(p.cost_price != null ? tien(p.cost_price) : '');
                $hop.find('.select_menu_group').val(String(p.category_id || '')).trigger('change');
                $hop.find('.select_menu_unit').val(String(p.unit_id || 0)).trigger('change');
                $hop.find('.select_location').val(String(p.location_id || 0)).trigger('change');
                // Phải sau lượt chọn nhóm ở trên: nhóm cũng đẩy một mức vào ô này,
                // còn mức của CHÍNH mặt hàng mới là mức đúng.
                datMucThue(p.vat == null ? 0 : p.vat);
                $hop.find('.description').val(p.description || '');
                $hop.find('.ip_status').prop('checked', (p.status || 'active') === 'active');
                $hop.find('.print_label').prop('checked', !!p.print_label);
                $hop.find('.is_stock_deducted').prop('checked', p.is_stock_deducted !== false);
                $hop.find('.is_serial').prop('checked', !!p.is_serial);

                anh = p.thumbnail || '';
                if (anh) $hop.find('#img-preview').attr('src', anh);

                $hop.find('.select_branch').val((p.shops || p.shop_ids || []).map((x) => String(x.id || x))).trigger('change');
                $hop.find('.select_tag').val((p.tags || []).map((t) => String(t.name || t))).trigger('change');

                (p.unit_conversions || []).forEach((c) => $('#list_unit').append(dongDonVi(c)));
                veDvtChinh();
                // Mặt hàng ĐÃ khai quy đổi thì mở sẵn khối ra. Để gập lại thì người
                // mở hộp sửa không thấy là có, sửa giá hay đơn vị xong bấm Lưu mà
                // không biết mình vừa giữ lại một bảng quy đổi nào.
                $('#collapseMenuUnit').toggleClass('show', $('#list_unit .dong-quy-doi').length > 0);

                // Dựng lại các dòng thuộc tính từ biến thể đang có, rồi vẽ lại bảng tổ hợp.
                bienTheCu = (p.variants || []).filter((v) => (v.attributes || []).length);
                const theoChieu = new Map();
                bienTheCu.forEach((v) => (v.attributes || []).forEach((a) => {
                    if (!theoChieu.has(a.attribute_id)) theoChieu.set(a.attribute_id, new Set());
                    theoChieu.get(a.attribute_id).add(a.value_id);
                }));
                // Biến thể mặc định cho biết giá trị nào của mỗi chiều là mặc định.
                const vidMacDinh = new Set(
                    ((p.variants || []).find((v) => v.is_default && (v.attributes || []).length) || {}).attributes
                        ?.map((a) => a.value_id) || []
                );
                theoChieu.forEach((vals, aid) => {
                    const $tr = $(dongThuocTinh(aid));
                    $('#add-attribute').append($tr);
                    [...vals].forEach((vid) => {
                        themChiTiet($tr, vid, 0);
                        if (vidMacDinh.has(vid)) {
                            $tr.find('.tt-dong').last().find('.tt-mac-dinh').prop('checked', true);
                        }
                    });
                });

                // Hàng đơn: mã vạch nằm ở dòng biến thể mặc định.
                const don = (p.variants || []).find((v) => !(v.attributes || []).length);
                if (don) $hop.find('.ip_barcode').val(don.barcode || '');

                veBienThe();
            }

            veGiaSauThue();
            datCheDo(chiXem);
            $hop.modal('show');
        };

        // ---------- Lưu ----------
        $hop.on('click', '#confirm_create_menu', function () {
            const ten = $hop.find('.ip_name').val().trim();
            const nhom = $hop.find('.select_menu_group').val();
            const gia = soThoi($hop.find('.ip_sale_price').val());
            const ma = $hop.find('.ip_code').val().trim();

            if (!ten) { window.PRD.toast('Vui lòng nhập tên hàng hóa.'); return; }
            if (!nhom) { window.PRD.toast('Vui lòng chọn nhóm hàng hóa.'); return; }
            if (!ma && !MA_TU_SINH) { window.PRD.toast('Vui lòng nhập mã hàng.'); return; }
            if (gia === '') { window.PRD.toast('Vui lòng nhập giá bán.'); return; }

            const f = {
                name: ten,
                sku: ma,
                category_id: nhom,
                // Luôn gửi, kể cả 0: hai ô này có mặt ở mọi lượt sửa nên để trống là ý
                // muốn thật, không phải màn hình dựng hụt.
                unit_id: Number($hop.find('.select_menu_unit').val() || 0),
                location_id: Number($hop.find('.select_location').val() || 0),
                vat: $hop.find('.select_vat_value').val(),
                base_price: gia,
                cost_price: soThoi($hop.find('.ip_cost_price').val()),
                thumbnail: anh,
                description: $hop.find('.description').val().trim(),
                // Gửi CỜ bật/tắt chứ không gửi status: khu quản trị chỉ bày hai mức,
                // API còn giữ mức `discontinued` cũ — nhận cờ thì mức ấy được giữ nguyên.
                is_active: $hop.find('.ip_status').is(':checked') ? 1 : 0,
                print_label: $hop.find('.print_label').is(':checked') ? 1 : 0,
                is_stock_deducted: $hop.find('.is_stock_deducted').is(':checked') ? 1 : 0,
                is_serial: $hop.find('.is_serial').is(':checked') ? 1 : 0,
                // Cờ *_loaded để máy chủ phân biệt "gỡ hết" với "màn hình không dựng được ô ấy".
                // THIẾU cờ nào là controller bỏ qua nguyên cụm đó — thiếu
                // `variants_loaded` thì biến thể gửi lên bao nhiêu cũng không được lưu.
                shops_loaded: 1,
                tags_loaded: 1,
                conversions_loaded: 1,
                variants_loaded: 1,
            };

            ($hop.find('.select_branch').val() || []).forEach((v, i) => { f['shop_ids[' + i + ']'] = v; });
            ($hop.find('.select_tag').val() || []).forEach((v, i) => { f['tags[' + i + ']'] = v; });

            // Quy đổi đơn vị: chặn trùng ngay tại đây, để API từ chối thì mất cả lượt Lưu.
            const daCo = new Set();
            const dvtChinh = Number($hop.find('.select_menu_unit').val() || 0);
            let loi = null;
            let ci = 0;
            $('#list_unit .dong-quy-doi').each(function () {
                const uid = Number($(this).find('.qd-dvt').val() || 0);
                const so = ($(this).find('.qd-so').val() || '').replace(',', '.').trim();
                if (!uid && so === '') return;
                if (!uid || Number(so) <= 0) { loi = loi || 'Mỗi dòng quy đổi phải chọn đơn vị và nhập số lớn hơn 0.'; return; }
                if (dvtChinh > 0 && uid === dvtChinh) { loi = loi || 'Không khai quy đổi cho chính đơn vị tính của mặt hàng.'; return; }
                if (daCo.has(uid)) { loi = loi || 'Mỗi đơn vị chỉ được khai quy đổi một lần.'; return; }
                daCo.add(uid);
                f['unit_conversions[' + ci + '][unit_id]'] = uid;
                f['unit_conversions[' + ci + '][quantity]'] = so;
                ci++;
            });
            if (loi) { window.PRD.toast(loi); return; }

            // Biến thể dựng từ chính các dòng thuộc tính. Không dòng nào = hàng đơn.
            const dsBienThe = dungBienThe(gia, $hop.find('.ip_barcode').val().trim());
            dsBienThe.forEach((v, vi) => {
                f['variants[' + vi + '][id]'] = v.id;
                f['variants[' + vi + '][is_default]'] = v.is_default;
                f['variants[' + vi + '][name]'] = v.name;
                f['variants[' + vi + '][sku]'] = v.sku;
                f['variants[' + vi + '][barcode]'] = v.barcode;
                f['variants[' + vi + '][price]'] = v.price;
                f['variants[' + vi + '][cost_price]'] = v.cost_price;
                v.attributes.forEach((a, j) => {
                    f['variants[' + vi + '][attributes][' + j + '][attribute_id]'] = a.attribute_id;
                    f['variants[' + vi + '][attributes][' + j + '][value_id]'] = a.value_id;
                });
            });
            if (!dsBienThe.length) {
                // Hàng đơn: một dòng mặc định, tên để trống cho máy chủ hiểu.
                f['variants[0][id]'] = 0;
                f['variants[0][name]'] = '';
                f['variants[0][sku]'] = ma;
                f['variants[0][barcode]'] = $hop.find('.ip_barcode').val().trim();
                f['variants[0][price]'] = gia;
                f['variants[0][cost_price]'] = f.cost_price;
            }

            luu(dangSua ? URL_HH + '/' + dangSua : URL_HH_STORE, dangSua ? 'PUT' : 'POST', f);
        });

        /**
         * Gửi lượt Lưu bằng AJAX.
         *
         * Xong thì đóng hộp, nạp lại bảng tại chỗ và bắn toast xanh. HỎNG THÌ
         * GIỮ NGUYÊN HỘP — máy chủ trả 422 kèm câu lỗi (VD "Tên mặt hàng này đã
         * có trong cửa hàng"), bắn toast đỏ rồi để người dùng sửa ngay trên hộp.
         * Trước đây lượt Lưu đi bằng form ẩn nên trang tải lại, hộp biến mất rồi
         * mới thấy toast — mất trắng mọi thứ vừa gõ, kể cả bảng biến thể.
         */
        function luu(action, method, fields) {
            const $nut = $hop.find('#confirm_create_menu');
            if ($nut.prop('disabled')) return;
            $nut.prop('disabled', true);

            const fd = new FormData();
            fd.append('_token', CSRF_HH);
            if (method !== 'POST') fd.append('_method', method);
            fd.append('return', location.pathname + location.search);
            $.each(fields, (k, v) => fd.append(k, v == null ? '' : v));

            fetch(action, {
                method: 'POST',
                body: fd,
                // Accept JSON: controller nhận ra là hộp thoại gọi nên trả thẳng
                // {success, message} thay vì chuyển hướng.
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((res) => res.json().then((r) => ({ ok: res.ok, body: r })))
                .then(({ ok, body }) => {
                    if (!ok) {
                        // Lỗi validate của chính Laravel gom theo ô; lỗi từ API
                        // đã được gộp sẵn thành một câu.
                        const cau = body.message
                            || Object.values(body.errors || {}).flat().join(' ')
                            || 'Lưu không thành công.';
                        toastr.error(cau);

                        return;
                    }

                    $hop.modal('hide');
                    toastr.success(body.message || 'Đã lưu.');
                    V2.napLai(location.href, false);
                })
                .catch(() => toastr.error('Không gửi được lượt lưu. Kiểm tra kết nối rồi thử lại.'))
                // Mở khoá nút dù xong hay hỏng: hỏng mà nút vẫn khoá thì sửa
                // xong không bấm Lưu lại được, phải đóng hộp làm lại từ đầu.
                .finally(() => $nut.prop('disabled', false));
        }

        /**
         * Select2 cho các ô chọn trong hộp — dựng NGAY lúc nạp trang.
         *
         * Trước đây dựng ở `shown.bs.modal`, tức là SAU khi moHopHangHoa đã điền
         * xong. Lượt mở đầu còn may (select2 đọc giá trị sẵn có lúc khởi tạo),
         * nhưng những lượt sau thì ô hiển thị một đằng, giá trị thật một nẻo —
         * bấm Sửa thấy ô trống, mở danh sách ra thì không còn mục nào.
         *
         * Dựng sẵn thì mọi lượt `.val().trigger('change')` đều được select2 nghe
         * và vẽ lại. `dropdownParent` vẫn cần: thiếu nó thì bảng xổ ra bám vào
         * <body>, nằm dưới lớp phủ của modal.
         */
        function dungSelect2() {
            // PHẢI neo vào thẻ `select`. Chính select2 dựng thêm một
            // <span class="select2 select2-container"> ngay cạnh ô gốc, nên
            // `.select2` cũng khớp cái span ấy — mà `.not('.select2-hidden-
            // accessible')` không lọc được nó (cờ đó nằm trên ô gốc). Gọi
            // select2() lên một cái span là hỏng luôn phần hiển thị: ô hiện
            // ra TRẮNG dù giá trị bên dưới vẫn đúng.
            $hop.find('select.select2').not('.select2-hidden-accessible').each(function () {
                const $o = $(this);
                const nhieu = $o.prop('multiple');

                $o.select2({
                    dropdownParent: $hop,
                    width: '100%',
                    minimumResultsForSearch: $o.find('option').length > 8 ? 0 : Infinity,
                    language: {
                        // Ô chọn NHIỀU: select2 giấu những dòng đã tick, nên tick
                        // hết là danh sách rỗng và nó bung ra "No results found"
                        // — trông hệt như không đọc được dữ liệu.
                        noResults: () => (nhieu
                            ? 'Đã chọn hết — không còn mục nào để thêm'
                            : 'Chưa khai mục nào ở đây'),
                        searching: () => 'Đang tìm…',
                        noMatches: () => 'Không có mục nào khớp',
                    },
                });
            });
        }

        dungSelect2();
        // Hàng thuộc tính thêm sau vẫn cần một lượt dựng cho ô mới của nó.
        $hop.on('shown.bs.modal', dungSelect2);
    })();
</script>
