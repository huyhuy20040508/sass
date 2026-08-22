@extends('layouts.app')

@section('title', $meta['title'])

@section('content')
    {{-- Bố cục theo bản ERP cũ (order v2 → Thông số chung → Quy tắc đánh số):
         thanh mục bên trái, bảng chi nhánh ở trên, bảng quy tắc ở dưới. Màu sắc
         và nút giữ của trang quản trị hiện tại. --}}
    @php
        $C = \App\Http\Controllers\ThongSoChungController::class;

        // Chi nhánh đang mở: ưu tiên lượt vừa gửi hỏng, rồi tới ?cn=, rồi chi nhánh đầu.
        $cnDau = (int) ($chiNhanh[0]['id'] ?? 0);
        $cnChon = (int) (old('shop_id', request('cn', $cnDau)));
        if (! collect($chiNhanh)->contains(fn ($c) => (int) $c['id'] === $cnChon)) {
            $cnChon = $cnDau;
        }

        // Giá trị đang hiện của một loại: lượt gửi hỏng > bản đã lưu > gợi ý.
        $giaTri = function (array $l, string $o, $mac) use ($dangLuu, $cnChon) {
            $pham = ($l['dung_chung'] ?? false) ? 0 : $cnChon;
            $cu = $dangLuu[$pham][$l['ma']] ?? null;

            return old('rules.'.$l['ma'].'.'.$o, $cu[$o] ?? $mac);
        };

        // Chỉ loại CÓ ô tick mới ẩn/hiện theo trạng thái bật. Những loại còn lại
        // luôn nằm sẵn trong bảng: chứng từ vốn đã tự sinh mã, còn nhóm hàng hoá /
        // thuộc tính / đơn vị tính thì bỏ trống mã là phần mềm đặt hộ theo dải sẵn
        // có — ở đó quy tắc chỉ đổi HÌNH DẠNG mã chứ không bật/tắt việc sinh mã.
        // API quyết định loại nào có tick (LoaiMa.BatTatDuoc), không phải màn hình.
        $coTick = fn (array $l) => ($l['dung_chung'] ?? false) && ($l['bat_tat_duoc'] ?? false);

        $dangBat = function (array $l) use ($dangLuu, $coTick) {
            if (! $coTick($l)) {
                return true;
            }
            if (old('rules') !== null) {
                return old('rules.'.$l['ma']) !== null;
            }

            return (bool) ($dangLuu[0][$l['ma']]['is_active'] ?? false);
        };

        $danhMuc = collect($loai)->filter($coTick);
    @endphp

    <div class="tsc">
        <div class="tsc-head">
            <div class="tsc-head-text">
                <h1 class="tsc-title">{{ $meta['title'] }}</h1>
                <p class="tsc-sub">{{ $meta['sub'] }}</p>
            </div>

            <div class="tsc-head-actions">
                <button type="submit" form="tscForm" class="tsc-btn-primary" @disabled(empty($chiNhanh) || empty($loai))>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    Lưu thay đổi
                </button>
            </div>
        </div>

        {{-- Kết quả một lượt bấm (lưu xong / lưu hỏng) đi bằng TOAST — partials.toasts
             của layout tự đọc session. Chỉ giữ lại lỗi TẢI TRANG ở đây, vì nó giải
             thích vì sao bảng bên dưới trống chứ không phải báo kết quả thao tác. --}}
        @if(!empty($error))
            <p class="tsc-callout is-error">{{ $error }}</p>
        @endif

        <div class="tsc-body">
            <aside class="tsc-side">
                @include('thong-so-chung.partials.sidebar')
            </aside>

            <section class="tsc-main">
                @if(empty($chiNhanh) || empty($loai))
                    <div class="tsc-empty">
                        <p class="tsc-empty-title">Chưa tải được dữ liệu</p>
                        <p class="tsc-empty-sub">Máy chủ API không trả về chi nhánh hoặc danh mục loại chứng từ. Kiểm tra API rồi tải lại trang.</p>
                    </div>
                @else
                    {{-- 1. Chi nhánh: bấm một dòng để mở bảng quy tắc của nơi đó. --}}
                    <div class="tsc-block">
                        <div class="tsc-block-head">Danh sách chi nhánh</div>
                        <div class="tsc-table-wrap">
                            <table class="tsc-table tsc-table-cn">
                                <colgroup>
                                    <col style="width: 6%"><col style="width: 16%"><col style="width: 30%">
                                    <col style="width: 16%"><col style="width: 32%">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>STT</th><th>Mã chi nhánh</th><th>Tên chi nhánh</th>
                                        <th>Điện thoại</th><th>Địa chỉ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($chiNhanh as $i => $cn)
                                        <tr class="tsc-cn {{ (int) $cn['id'] === $cnChon ? 'is-open' : '' }}"
                                            data-cn="{{ $cn['id'] }}" data-ten="{{ $cn['name'] }}" tabindex="0">
                                            <td>{{ $i + 1 }}</td>
                                            <td><code>{{ $cn['code'] }}</code></td>
                                            <td>{{ $cn['name'] }}</td>
                                            <td>{{ $cn['phone'] ?: '—' }}</td>
                                            <td>{{ $cn['address'] ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('admin.thong-so-chung.luuQuyTacDanhSo') }}" id="tscForm">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="shop_id" id="tscShopId" value="{{ $cnChon }}">

                        {{-- 2. Danh mục: tick là bật tự sinh mã, bỏ tick là gõ tay như cũ.
                             Một bộ dùng chung cho cả cửa hàng, không theo chi nhánh. --}}
                        @if($danhMuc->isNotEmpty())
                            <div class="tsc-block">
                                <div class="tsc-block-head">Quy tắc mã danh mục</div>
                                <div class="tsc-ticks">
                                    @foreach($danhMuc as $l)
                                        <label class="tsc-tick">
                                            <input type="checkbox" data-tick="{{ $l['ma'] }}" @checked($dangBat($l))>
                                            <span>{{ $l['ten'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="tsc-note">
                                    Tick ô nào thì phần mềm tự đặt mã cho danh mục đó và ô mã ở màn nhập khoá lại.
                                    Bỏ tick là quay về gõ tay — mã đã đặt cho những bản ghi cũ vẫn giữ nguyên.
                                    Những danh mục không có ở đây thì mã vẫn tự đặt được khi để trống, nên chúng
                                    nằm sẵn trong bảng bên dưới; sửa ở đó là đổi hình dạng mã.
                                </p>
                            </div>
                        @endif

                        {{-- 3. Bảng quy tắc của chi nhánh đang chọn. --}}
                        <div class="tsc-block">
                            <div class="tsc-block-head">
                                Quy tắc của chi nhánh: <b id="tscTenCn">{{ collect($chiNhanh)->firstWhere('id', $cnChon)['name'] ?? '' }}</b>
                            </div>
                            <div class="tsc-table-wrap">
                                <table class="tsc-table tsc-table-qt">
                                    <colgroup>
                                        <col style="width: 5%"><col style="width: 24%"><col style="width: 13%">
                                        <col style="width: 17%"><col style="width: 11%"><col style="width: 13%">
                                        <col style="width: 17%">
                                    </colgroup>
                                    <thead>
                                        <tr>
                                            <th>STT</th><th>Loại chứng từ / danh mục</th><th>Tiền tố</th>
                                            <th>Phần giá trị</th><th>Số ký tự</th><th>Hậu tố</th><th>Mã mẫu</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($loai as $l)
                                            @php $bat = $dangBat($l); @endphp
                                            <tr data-loai="{{ $l['ma'] }}" data-chung="{{ ($l['dung_chung'] ?? false) ? 1 : 0 }}"
                                                @class(['is-off' => ! $bat]) @if(! $bat) hidden @endif>
                                                <td class="tsc-stt"></td>
                                                <td class="tsc-ten">
                                                    {{ $l['ten'] }}
                                                    @if($l['dung_chung'] ?? false)<span class="tsc-chip">dùng chung</span>@endif
                                                </td>
                                                <td>
                                                    <input type="text" class="tsc-input" maxlength="20"
                                                           name="rules[{{ $l['ma'] }}][prefix]"
                                                           value="{{ $giaTri($l, 'prefix', $l['tien_to_goi_y'] ?? '') }}"
                                                           placeholder="{{ $l['tien_to_goi_y'] ?? 'Nhập mã' }}" @disabled(! $bat)>
                                                </td>
                                                <td>
                                                    <select class="tsc-input" name="rules[{{ $l['ma'] }}][value_part]" @disabled(! $bat)>
                                                        @foreach($C::PHAN_GIA_TRI as $ma => $ten)
                                                            <option value="{{ $ma }}" @selected($giaTri($l, 'value_part', 'so-thu-tu') === $ma)>{{ $ten }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td>
                                                    <input type="number" class="tsc-input" min="{{ $C::DO_DAI_MIN }}" max="{{ $C::DO_DAI_MAX }}"
                                                           name="rules[{{ $l['ma'] }}][length]"
                                                           value="{{ $giaTri($l, 'length', $C::DO_DAI_MAC_DINH) }}" @disabled(! $bat)>
                                                </td>
                                                <td>
                                                    <input type="text" class="tsc-input" maxlength="20"
                                                           name="rules[{{ $l['ma'] }}][suffix]"
                                                           value="{{ $giaTri($l, 'suffix', '') }}" placeholder="—" @disabled(! $bat)>
                                                </td>
                                                <td><code class="tsc-mau"></code></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="tsc-note">
                                <b>Số ký tự</b> là độ dài phần giữa, chưa tính tiền tố và hậu tố. Chọn ngày tháng năm
                                thì phần ngày ăn trước, số đếm lấy chỗ còn lại. Cột <b>Mã mẫu</b> là mã đầu tiên sẽ sinh ra.
                            </p>
                        </div>
                    </form>
                @endif
            </section>
        </div>
    </div>

    @include('thong-so-chung.partials.style')

    <script>
        (function () {
            var form = document.getElementById('tscForm');
            if (!form) return;

            var oShop = document.getElementById('tscShopId');
            var oTenCn = document.getElementById('tscTenCn');
            var bang = form.querySelector('.tsc-table-qt tbody');
            // Quy tắc đã lưu của MỌI chi nhánh: {phạm vi: {loại: quy tắc}}. Phạm vi 0
            // là bộ dùng chung. Có sẵn ở đây nên đổi chi nhánh không phải gọi API.
            var DA_LUU = @json($dangLuu);
            var LOAI = @json($loai);
            var MAC_DINH_DAI = {{ $C::DO_DAI_MAC_DINH }};
            var banSua = false;

            function pad2(n) { return String(n).padStart(2, '0'); }

            // Bản sao JS của domain.QuyTacMa.MaMau bên Go — hai chỗ phải cho ra
            // cùng một chuỗi, sửa bên này thì sửa cả bên kia.
            function maMau(prefix, phan, dai, hau) {
                var d = new Date(), ngay = '';
                if (phan === 'ngay-thang-nam') ngay = pad2(d.getDate()) + pad2(d.getMonth() + 1) + d.getFullYear();
                else if (phan === 'thang-nam') ngay = pad2(d.getMonth() + 1) + String(d.getFullYear()).slice(-2);

                var con = Math.max(1, (parseInt(dai, 10) || 0) - ngay.length);
                return prefix + ngay + String(1).padStart(con, '0') + hau;
            }

            function o(tr, ten) { return tr.querySelector('[name$="[' + ten + ']"]'); }

            // Vẽ lại số thứ tự và mã mẫu của những dòng đang hiện.
            function veLai() {
                var stt = 0;
                bang.querySelectorAll('tr').forEach(function (tr) {
                    if (tr.hidden) { tr.querySelector('.tsc-mau').textContent = ''; return; }
                    tr.querySelector('.tsc-stt').textContent = ++stt;
                    tr.querySelector('.tsc-mau').textContent = maMau(
                        o(tr, 'prefix').value.trim(), o(tr, 'value_part').value,
                        o(tr, 'length').value, o(tr, 'suffix').value.trim()
                    );
                });
            }

            // Bật/tắt một dòng: dòng tắt bị ẩn VÀ disabled — trình duyệt không gửi
            // lên, và API hiểu vắng mặt là tắt.
            function batDong(tr, bat) {
                tr.hidden = !bat;
                tr.classList.toggle('is-off', !bat);
                tr.querySelectorAll('.tsc-input').forEach(function (i) { i.disabled = !bat; });
            }

            // Đổ lại toàn bảng theo quy tắc đã lưu của một chi nhánh.
            function doiChiNhanh(id, ten) {
                oShop.value = id;
                oTenCn.textContent = ten;

                LOAI.forEach(function (l) {
                    var tr = bang.querySelector('tr[data-loai="' + l.ma + '"]');
                    if (!tr) return;

                    var pham = l.dung_chung ? 0 : id;
                    var cu = (DA_LUU[pham] || {})[l.ma] || null;

                    o(tr, 'prefix').value = cu ? cu.prefix : (l.tien_to_goi_y || '');
                    o(tr, 'value_part').value = cu ? cu.value_part : 'so-thu-tu';
                    o(tr, 'length').value = cu ? cu.length : MAC_DINH_DAI;
                    o(tr, 'suffix').value = cu ? cu.suffix : '';

                    // Chỉ loại có ô tick mới theo trạng thái đã lưu; loại không có
                    // tick thì luôn hiện (xem $coTick ở phần Blade bên trên).
                    var bat = (l.dung_chung && l.bat_tat_duoc) ? !!(cu && cu.is_active) : true;
                    batDong(tr, bat);
                    var tick = form.querySelector('[data-tick="' + l.ma + '"]');
                    if (tick) tick.checked = bat;
                });

                banSua = false;
                veLai();
            }

            document.querySelectorAll('.tsc-cn').forEach(function (row) {
                row.addEventListener('click', function () {
                    var id = parseInt(row.dataset.cn, 10);
                    if (id === parseInt(oShop.value, 10)) return;
                    // Đổi chi nhánh là nạp lại bảng, nên hỏi trước khi bỏ dở.
                    if (banSua && !confirm('Bảng quy tắc đang có thay đổi chưa lưu. Chuyển sang chi nhánh khác sẽ bỏ những thay đổi đó?')) return;

                    document.querySelectorAll('.tsc-cn').forEach(function (r) { r.classList.remove('is-open'); });
                    row.classList.add('is-open');
                    doiChiNhanh(id, row.dataset.ten);
                });
                row.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.click(); }
                });
            });

            form.querySelectorAll('[data-tick]').forEach(function (tick) {
                tick.addEventListener('change', function () {
                    var tr = bang.querySelector('tr[data-loai="' + tick.dataset.tick + '"]');
                    if (!tr) return;
                    batDong(tr, tick.checked);
                    banSua = true;
                    veLai();
                });
            });

            bang.addEventListener('input', function () { banSua = true; veLai(); });
            bang.addEventListener('change', function () { banSua = true; veLai(); });

            veLai();
        })();
    </script>
@endsection
