{{-- Bảng quyền theo chức năng, dựng từ danh mục API trả về.

     Nhận: $danhMuc, $viec, $dangBat (tập quyền đang bật), $chiDoc.
     $chiDoc = true → bảng chỉ để NHÌN (tab Theo nhân viên: quyền của một người là
     hợp các nhóm họ mang, sửa ở danh sách nhóm phía trên chứ không sửa ở đây).

     Script cuối tệp treo window.pqMaTran cho trang gọi lại: .dat(danh sách quyền). --}}
@php
    $chiDoc = $chiDoc ?? false;
@endphp

<div class="pq-matrix-wrap">
    <table class="pq-matrix {{ $chiDoc ? 'is-readonly' : '' }}" id="pqMatrix">
        <thead>
            <tr>
                <th class="pq-col-name">Chức năng</th>
                @foreach($viec as $nhan)
                    <th class="pq-col-viec">{{ $nhan }}</th>
                @endforeach
                <th class="pq-col-le">Tuỳ chọn</th>
            </tr>
        </thead>
        <tbody>
            {{-- Khối gập sẵn: mở ra là việc của người dùng.
                 Trước đây mở toang hết, tải lại trang là lại mở. --}}
            @foreach($danhMuc as $i => $khoi)
                <tr class="pq-sec" data-pq-sec="{{ $i }}">
                    <td colspan="{{ count($viec) + 2 }}">
                        <button type="button" class="pq-sec-btn" data-pq-toggle="{{ $i }}" aria-expanded="false">
                            <svg class="pq-caret" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <label class="pq-sec-label">
                            <input type="checkbox" class="pq-cb pq-sec-cb" data-pq-sec-cb="{{ $i }}" @disabled($chiDoc)>
                            <span>{{ $khoi['ten'] ?? '' }}</span>
                        </label>
                    </td>
                </tr>

                @foreach($khoi['mucs'] ?? [] as $muc)
                    @php
                        $prefix = $muc['prefix'] ?? '';
                        $coViec = (array) ($muc['viec'] ?? []);
                    @endphp
                    <tr class="pq-row is-hidden" data-pq-row="{{ $i }}">
                        <td class="pq-col-name">
                            <label class="pq-row-label">
                                <input type="checkbox" class="pq-cb pq-row-cb" @disabled($chiDoc)>
                                <span>{{ $muc['ten'] ?? $prefix }}</span>
                            </label>
                        </td>

                        @foreach($viec as $ma => $nhan)
                            <td class="pq-col-viec">
                                @if(in_array($ma, $coViec, true))
                                    <input type="checkbox" class="pq-cb pq-perm"
                                           @if(! $chiDoc) name="quyen[]" @endif
                                           value="{{ $prefix.'.'.$ma }}"
                                           data-pq-perm="{{ $prefix.'.'.$ma }}"
                                           @checked(isset($dangBat[$prefix.'.'.$ma]))
                                           @disabled($chiDoc)
                                           aria-label="{{ $nhan }} — {{ $muc['ten'] ?? $prefix }}">
                                @endif
                            </td>
                        @endforeach

                        <td class="pq-col-le">
                            @foreach($muc['le'] ?? [] as $le)
                                @php $maLe = $prefix.'.'.($le['ma'] ?? ''); @endphp
                                <label class="pq-le">
                                    <input type="checkbox" class="pq-cb pq-perm"
                                           @if(! $chiDoc) name="quyen[]" @endif
                                           value="{{ $maLe }}"
                                           data-pq-perm="{{ $maLe }}"
                                           @checked(isset($dangBat[$maLe]))
                                           @disabled($chiDoc)>
                                    <span>{{ $le['ten'] ?? '' }}</span>
                                </label>
                            @endforeach
                        </td>
                    </tr>
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
        var elCount = document.getElementById('pqCount');
        var elAll = document.getElementById('pqAll');

        function permsCuaHang(row) {
            return Array.prototype.slice.call(row.querySelectorAll('.pq-perm'));
        }

        function permsCuaKhoi(i) {
            return rows.filter(function (r) { return r.dataset.pqRow === String(i); })
                .reduce(function (acc, r) { return acc.concat(permsCuaHang(r)); }, []);
        }

        /** Ô cha thành tick / gạch ngang / trống theo số con đang bật. */
        function datCha(cb, ds) {
            var bat = ds.filter(function (x) { return x.checked; }).length;
            cb.checked = ds.length > 0 && bat === ds.length;
            cb.indeterminate = bat > 0 && bat < ds.length;
        }

        function dongBo() {
            rows.forEach(function (r) {
                var cb = r.querySelector('.pq-row-cb');
                if (cb) datCha(cb, permsCuaHang(r));
            });
            secs.forEach(function (s) {
                var cb = s.querySelector('.pq-sec-cb');
                if (cb) datCha(cb, permsCuaKhoi(s.dataset.pqSec));
            });
            if (elAll) datCha(elAll, perms);
            if (elCount) elCount.textContent = perms.filter(function (x) { return x.checked; }).length;
        }

        function datKhoi(sec, mo) {
            sec.classList.toggle('is-open', mo);
            sec.querySelector('.pq-sec-btn').setAttribute('aria-expanded', mo ? 'true' : 'false');
            rows.filter(function (r) { return r.dataset.pqRow === sec.dataset.pqSec; })
                .forEach(function (r) { r.classList.toggle('is-hidden', !mo); });
        }

        matrix.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-pq-toggle]');
            if (!btn) return;
            var sec = btn.closest('.pq-sec');
            datKhoi(sec, !sec.classList.contains('is-open'));
        });

        var moTatCa = document.getElementById('pqOpenAll');
        var dongTatCa = document.getElementById('pqCloseAll');
        if (moTatCa) moTatCa.addEventListener('click', function () { secs.forEach(function (s) { datKhoi(s, true); }); });
        if (dongTatCa) dongTatCa.addEventListener('click', function () { secs.forEach(function (s) { datKhoi(s, false); }); });

        if (!chiDoc) {
            matrix.addEventListener('change', function (e) {
                var t = e.target;
                if (t.classList.contains('pq-row-cb')) {
                    permsCuaHang(t.closest('.pq-row')).forEach(function (cb) { cb.checked = t.checked; });
                } else if (t.classList.contains('pq-sec-cb')) {
                    permsCuaKhoi(t.dataset.pqSecCb).forEach(function (cb) { cb.checked = t.checked; });
                } else if (!t.classList.contains('pq-perm')) {
                    return;
                }
                dongBo();
            });

            if (elAll) {
                elAll.addEventListener('change', function () {
                    perms.forEach(function (cb) { cb.checked = elAll.checked; });
                    dongBo();
                });
            }
        }

        dongBo();

        return {
            /** Đặt lại toàn bộ dấu tick theo một danh sách quyền. */
            dat: function (ds) {
                var bat = {};
                (ds || []).forEach(function (q) { bat[q] = true; });
                perms.forEach(function (cb) { cb.checked = !!bat[cb.dataset.pqPerm]; });
                dongBo();
            },
        };
    })();
</script>
