{{-- Bảng quyền theo chức năng, dựng từ cây API trả về: KHU → nhóm → mục.

     Nhận: $danhMuc, $viec, $dangBat (tập quyền đang bật), $chiDoc.
     $chiDoc = true → bảng chỉ để NHÌN (tab Theo nhân viên: quyền của một người là
     hợp các nhóm họ mang, sửa ở danh sách nhóm phía trên chứ không sửa ở đây).

     $chiQuay = true → người đang chọn CHỈ có cửa quầy: cả khu Quản trị bị khoá.
     Không phải để làm khó — nhóm route `manage` bên API đòi cửa `quan_ly` trước
     khi hỏi tới quyền, nên tick mấy ô đó chỉ ghi xuống chữ chết.

     GẬP SẴN CẢ HAI TẦNG. Mở trang ra chỉ thấy hai dòng "Quản trị" và "Thu ngân",
     đúng lối bản ERP cũ: tick thẳng vào mục lớn là giao cả khu, còn muốn chọn lẻ
     thì bung ra. Bung sẵn cả trăm dòng thì người tick phải cuộn đi tìm.

     Script cuối tệp treo window.pqMaTran cho trang gọi lại: .dat(danh sách quyền). --}}
@php
    $chiDoc = $chiDoc ?? false;
    $chiQuay = $chiQuay ?? false;

    $chuKhoa = 'Việc này nằm trong khu quản trị — mở cửa Quản lý cho họ ở hồ sơ Nhân sự trước.';

    // Số thứ tự của nhóm, chạy xuyên qua cả hai khu: JS gom hàng theo con số này
    // nên nó phải là duy nhất trong cả bảng.
    $sec = 0;
@endphp

<div class="pq-matrix-wrap">
    <table class="pq-matrix {{ $chiDoc ? 'is-readonly' : '' }}" id="pqMatrix">
        <thead>
            <tr>
                <th class="pq-col-name">Tính năng</th>
                @foreach($viec as $nhan)
                    <th class="pq-col-viec">{{ $nhan }}</th>
                @endforeach
                <th class="pq-col-le">Tuỳ chọn</th>
            </tr>
        </thead>
        <tbody>
            @foreach($danhMuc as $k => $khu)
                @php
                    // Khoá cả khu một lượt, không dò từng mã: khu CHÍNH LÀ ranh
                    // giới mà API chặn, nên chia nhỏ hơn nữa chỉ là đoán mò.
                    $khuKhoa = $chiQuay && ($khu['ma'] ?? '') !== \App\Services\CuaVao::THU_NGAN;
                @endphp

                {{-- MỤC LỚN: một khu làm việc. Người tick nhìn ra ngay đâu là việc
                     của quầy, đâu là việc của khu quản trị — trước đây hai thứ nằm
                     lẫn trong cùng một khối "Bán hàng". --}}
                <tr @class(['pq-khu', 'is-khoa' => $khuKhoa]) data-pq-khu="{{ $k }}">
                    <td colspan="{{ count($viec) + 2 }}">
                        <button type="button" class="pq-khu-btn" data-pq-khu-toggle="{{ $k }}" aria-expanded="false">
                            <svg class="pq-caret" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <label class="pq-khu-label">
                            <input type="checkbox" class="pq-cb pq-khu-cb" data-pq-khu-cb="{{ $k }}"
                                   @disabled($chiDoc || $khuKhoa)>
                            <span class="pq-khu-ten">{{ $khu['ten'] ?? '' }}</span>
                        </label>
                        <span class="pq-khu-mota">{{ $khuKhoa ? $chuKhoa : ($khu['mo_ta'] ?? '') }}</span>
                    </td>
                </tr>

                @foreach($khu['nhom'] ?? [] as $nhom)
                    @php $sec++; @endphp
                    <tr @class(['pq-sec', 'is-hidden', 'is-khoa' => $khuKhoa])
                        data-pq-sec="{{ $sec }}" data-pq-khu-sec="{{ $k }}">
                        <td colspan="{{ count($viec) + 2 }}">
                            <button type="button" class="pq-sec-btn" data-pq-toggle="{{ $sec }}" aria-expanded="false">
                                <svg class="pq-caret" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                            </button>
                            <label class="pq-sec-label">
                                <input type="checkbox" class="pq-cb pq-sec-cb" data-pq-sec-cb="{{ $sec }}"
                                       @disabled($chiDoc || $khuKhoa)>
                                <span>{{ $nhom['ten'] ?? '' }}</span>
                            </label>
                        </td>
                    </tr>

                    @foreach($nhom['mucs'] ?? [] as $muc)
                        @php
                            $prefix = $muc['prefix'] ?? '';
                            $coViec = (array) ($muc['viec'] ?? []);
                        @endphp
                        <tr @class(['pq-row', 'is-hidden', 'is-khoa' => $khuKhoa])
                            data-pq-row="{{ $sec }}" data-pq-khu-row="{{ $k }}">
                            <td class="pq-col-name">
                                <label class="pq-row-label">
                                    <input type="checkbox" class="pq-cb pq-row-cb" @disabled($chiDoc || $khuKhoa)>
                                    <span>{{ $muc['ten'] ?? $prefix }}</span>
                                </label>
                            </td>

                            @foreach($viec as $ma => $nhan)
                                <td class="pq-col-viec">
                                    @if(in_array($ma, $coViec, true))
                                        @php
                                            $maQ = $prefix.'.'.$ma;
                                            $bat = isset($dangBat[$maQ]);
                                        @endphp
                                        <input type="checkbox" class="pq-cb pq-perm"
                                               @if(! $chiDoc && ! $khuKhoa) name="quyen[]" @endif
                                               value="{{ $maQ }}"
                                               data-pq-perm="{{ $maQ }}"
                                               @checked($bat)
                                               @disabled($chiDoc || $khuKhoa)
                                               @if($khuKhoa) title="{{ $chuKhoa }}" @endif
                                               aria-label="{{ $nhan }} — {{ $muc['ten'] ?? $prefix }}">
                                        @include('phan-quyen.partials.giu-quyen-khoa', ['khoa' => $khuKhoa, 'bat' => $bat, 'maGiu' => $maQ])
                                    @endif
                                </td>
                            @endforeach

                            <td class="pq-col-le">
                                @foreach($muc['le'] ?? [] as $le)
                                    @php
                                        $maLe = $prefix.'.'.($le['ma'] ?? '');
                                        $bat = isset($dangBat[$maLe]);
                                    @endphp
                                    <label @class(['pq-le', 'is-khoa' => $khuKhoa])
                                           @if($khuKhoa) title="{{ $chuKhoa }}" @endif>
                                        <input type="checkbox" class="pq-cb pq-perm"
                                               @if(! $chiDoc && ! $khuKhoa) name="quyen[]" @endif
                                               value="{{ $maLe }}"
                                               data-pq-perm="{{ $maLe }}"
                                               @checked($bat)
                                               @disabled($chiDoc || $khuKhoa)>
                                        <span>{{ $le['ten'] ?? '' }}</span>
                                    </label>
                                    @include('phan-quyen.partials.giu-quyen-khoa', ['khoa' => $khuKhoa, 'bat' => $bat, 'maGiu' => $maLe])
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            @endforeach
        </tbody>
    </table>
</div>

<script>
    window.pqMaTran = (function () {
        var chiDoc = @json($chiDoc);
        var matrix = document.getElementById('pqMatrix');
        if (!matrix) return null;

        var perms = Array.prototype.slice.call(matrix.querySelectorAll('.pq-perm'));
        var rows = Array.prototype.slice.call(matrix.querySelectorAll('.pq-row'));
        var secs = Array.prototype.slice.call(matrix.querySelectorAll('.pq-sec'));
        var khus = Array.prototype.slice.call(matrix.querySelectorAll('.pq-khu'));
        var elCount = document.getElementById('pqCount');
        var elAll = document.getElementById('pqAll');

        // Tra ngược theo số, dựng một lần: mỗi lượt gập/mở đều phải hỏi "nhóm này
        // thuộc khu nào" và "hàng này thuộc nhóm nào".
        var khuTheoSo = {}, secTheoSo = {};
        khus.forEach(function (kh) { khuTheoSo[kh.dataset.pqKhu] = kh; });
        secs.forEach(function (s) { secTheoSo[s.dataset.pqSec] = s; });

        function mo(el) { return !!el && el.classList.contains('is-open'); }

        /** Ô KHOÁ không nghe lệnh của ô cha lẫn nút "Chọn tất cả". */
        function moKhoa(ds) {
            return ds.filter(function (x) { return !x.disabled; });
        }

        function permsCuaHang(row) {
            return Array.prototype.slice.call(row.querySelectorAll('.pq-perm'));
        }

        function gom(loc) {
            return rows.filter(loc).reduce(function (acc, r) {
                return acc.concat(permsCuaHang(r));
            }, []);
        }

        function permsCuaKhoi(i) {
            return gom(function (r) { return r.dataset.pqRow === String(i); });
        }

        function permsCuaKhu(k) {
            return gom(function (r) { return r.dataset.pqKhuRow === String(k); });
        }

        /**
         * Vẽ lại trạng thái ẩn/hiện TỪ hai cờ is-open, thay vì bật tắt từng chỗ.
         *
         * Hai tầng gập chồng nhau: một hàng chỉ hiện khi CẢ khu lẫn nhóm của nó
         * đang mở. Bật tắt tay ở mỗi lượt bấm thì đóng khu rồi mở lại là mấy nhóm
         * bên trong tự bung ra theo — trạng thái nằm rải rác nên không ai giữ nổi.
         */
        function veLai() {
            secs.forEach(function (s) {
                s.classList.toggle('is-hidden', !mo(khuTheoSo[s.dataset.pqKhuSec]));
            });
            rows.forEach(function (r) {
                var hien = mo(khuTheoSo[r.dataset.pqKhuRow]) && mo(secTheoSo[r.dataset.pqRow]);
                r.classList.toggle('is-hidden', !hien);
            });
        }

        function datMo(el, batTat) {
            el.classList.toggle('is-open', batTat);
            var btn = el.querySelector('[aria-expanded]');
            if (btn) btn.setAttribute('aria-expanded', batTat ? 'true' : 'false');
            veLai();
        }

        /** Ô cha thành tick / gạch ngang / trống theo số con đang bật. */
        function datCha(cb, ds) {
            var bat = ds.filter(function (x) { return x.checked; }).length;
            cb.checked = ds.length > 0 && bat === ds.length;
            cb.indeterminate = bat > 0 && bat < ds.length;
        }

        function dongBo() {
            rows.forEach(function (r) {
                // Ô cha nói về những ô nó ĐIỀU KHIỂN ĐƯỢC. Đếm cả ô khoá vào thì
                // một khu bị khoá vẫn hiện dấu gạch ngang như thể còn việc để làm.
                var cb = r.querySelector('.pq-row-cb');
                if (cb) datCha(cb, moKhoa(permsCuaHang(r)));
            });
            secs.forEach(function (s) {
                var cb = s.querySelector('.pq-sec-cb');
                if (cb) datCha(cb, moKhoa(permsCuaKhoi(s.dataset.pqSec)));
            });
            khus.forEach(function (kh) {
                var cb = kh.querySelector('.pq-khu-cb');
                if (cb) datCha(cb, moKhoa(permsCuaKhu(kh.dataset.pqKhu)));
            });
            if (elAll) datCha(elAll, moKhoa(perms));
            // Con số thì đếm HẾT, kể cả ô khoá đang bật: nó trả lời "người này
            // đang có bao nhiêu quyền", không phải "vừa tick được bao nhiêu".
            if (elCount) elCount.textContent = perms.filter(function (x) { return x.checked; }).length;
        }

        matrix.addEventListener('click', function (e) {
            var nutKhu = e.target.closest('[data-pq-khu-toggle]');
            if (nutKhu) {
                var kh = nutKhu.closest('.pq-khu');
                datMo(kh, !mo(kh));

                return;
            }
            var btn = e.target.closest('[data-pq-toggle]');
            if (!btn) return;
            var sec = btn.closest('.pq-sec');
            datMo(sec, !mo(sec));
        });

        // "Mở tất cả" bung CẢ HAI tầng. Mở mỗi khu mà để nhóm gập lại thì cái nút
        // ấy chỉ làm được nửa việc, và người dùng vẫn phải bấm từng nhóm.
        function moTatCa(batTat) {
            khus.concat(secs).forEach(function (el) { datMo(el, batTat); });
        }

        var nutMo = document.getElementById('pqOpenAll');
        var nutDong = document.getElementById('pqCloseAll');
        if (nutMo) nutMo.addEventListener('click', function () { moTatCa(true); });
        if (nutDong) nutDong.addEventListener('click', function () { moTatCa(false); });

        if (!chiDoc) {
            matrix.addEventListener('change', function (e) {
                var t = e.target;
                if (t.classList.contains('pq-row-cb')) {
                    moKhoa(permsCuaHang(t.closest('.pq-row'))).forEach(function (cb) { cb.checked = t.checked; });
                } else if (t.classList.contains('pq-sec-cb')) {
                    moKhoa(permsCuaKhoi(t.dataset.pqSecCb)).forEach(function (cb) { cb.checked = t.checked; });
                } else if (t.classList.contains('pq-khu-cb')) {
                    // Tick mục lớn là giao cả khu — kể cả khi nó đang gập. Đó chính
                    // là lối đi nhanh của bản cũ: khỏi bung ra tick từng dòng.
                    moKhoa(permsCuaKhu(t.dataset.pqKhuCb)).forEach(function (cb) { cb.checked = t.checked; });
                } else if (!t.classList.contains('pq-perm')) {
                    return;
                }
                dongBo();
            });

            if (elAll) {
                elAll.addEventListener('change', function () {
                    moKhoa(perms).forEach(function (cb) { cb.checked = elAll.checked; });
                    dongBo();
                });
            }
        }

        veLai();
        dongBo();

        return {
            /** Đặt lại toàn bộ dấu tick theo một danh sách quyền. */
            dat: function (ds) {
                var bat = {};
                (ds || []).forEach(function (q) { bat[q] = true; });
                moKhoa(perms).forEach(function (cb) { cb.checked = !!bat[cb.dataset.pqPerm]; });
                dongBo();
            },
        };
    })();
</script>
