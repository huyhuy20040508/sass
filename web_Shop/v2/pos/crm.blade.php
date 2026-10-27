{{--
    CRM — màn "Khách hàng" của quầy: CRM → Danh sách khách hàng của Quản trị cắt bớt
    (thiết kế design/html/crm.html). Cùng dữ liệu, cùng handler, cùng quyền sửa.

    Trái: ô tìm · nút lọc nhanh (bộ lọc của Quản trị) · hạng · tab Cá nhân / Doanh nghiệp.
    Phải: hồ sơ — ghi chú cố định (customer_note) + cảnh báo / hẹn hôm nay ở đầu, hạng + điểm,
    Tổng mua · Số đơn · Còn nợ, tab Lịch sử giao dịch / Ghi chú (sổ customer_notes) / Thông tin.
    Còn nợ: dòng tổng ở đầu khung thu đơn cũ nhất, mỗi đơn nợ có nút Thu — hộp Thu nợ
    dùng chung với Lịch sử (v2::pos._thu-no); thu xong đổi số tại chỗ, không tải lại.
    Lọc / chuyển trang là GET; ?khach=<id> giữ khách đang mở sau lượt lưu.

    Phím: F1 ô tìm · F2 thêm khách · ↑/↓ chuyển khách · Ctrl+Enter lưu trong hộp.
--}}
@extends('v2::layouts.pos')

@php
    $C = \App\Http\Controllers\CounterCrmController::class;
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $error = $error ?? null;
    $giu = array_filter(['q' => $f['q'], 'nhanh' => $f['nhanh'], 'type' => $f['type'], 'rank' => $f['rank'] ?: ''], fn ($v) => $v !== '');
    $link = fn (array $doi) => route('thu-ngan.crm.index', array_filter(array_merge($giu, $doi), fn ($v) => $v !== '' && $v !== null));
    $ngay = fn ($d) => \Illuminate\Support\Carbon::parse($d)->timezone(config('app.timezone'));

    // Hạng của API không mang màu — tô theo thứ bậc điểm để quầy nhìn là phân biệt.
    $mau = [['#8a5a2b', '#f6ece2', '#ead7c3'], ['#56606f', '#eef1f5', '#dde2ea'], ['#a86a00', '#fff4dc', '#f3e0b4'], ['#1f5fa8', '#e8f1fb', '#cfe0f4'], ['#6339b8', '#f3eefc', '#e3d8f7']];
    $hang = collect($thanhVien['ranks'] ?? [])->sortBy('point')->values()->map(fn ($r, $i) => $r + ['mau' => $mau[min($i, count($mau) - 1)]])->all();
    $mauHang = fn ($id) => collect($hang)->firstWhere('id', $id)['mau'] ?? ['#545b6b', '#f0f2f5', '#e3e8f0'];
    $sinhNhat = function ($dob) {
        if (! $dob) {
            return null;
        }
        $d = \Illuminate\Support\Carbon::parse($dob);
        $n = now()->startOfDay();
        $lan = $d->copy()->year($n->year);
        if ($lan->lt($n)) {
            $lan->addYear();
        }
        $con = (int) $n->diffInDays($lan);

        return $con <= 7 ? $con : null;
    };
    $lanCuoi = function ($d) use ($ngay) {
        if (! $d) {
            return 'Chưa mua';
        }
        $x = $ngay($d)->startOfDay();
        $n = (int) $x->diffInDays(now()->startOfDay());

        return $n === 0 ? 'Mua hôm nay' : ($n === 1 ? 'Mua hôm qua' : ($n < 30 ? $n.' ngày trước' : 'Mua '.$x->format('d/m/Y')));
    };
    $vietTat = function ($ten) {
        $w = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', (string) $ten))) ?: [];
        $w = array_values(array_filter($w));

        return mb_strtoupper(count($w) > 1 ? mb_substr($w[0], 0, 1).mb_substr($w[count($w) - 1], 0, 1) : mb_substr($w[0] ?? '?', 0, 2));
    };
    $total = (int) ($meta['total'] ?? count($rows));
    $trang = (int) ($meta['page'] ?? 1);
    $soTrang = max(1, (int) ($meta['total_pages'] ?? 1));
    $chon = $f['khach'] && collect($rows)->contains('id', $f['khach']) ? $f['khach'] : (int) ($rows[0]['id'] ?? 0);
    $duLieu = [
        'rows' => $rows,
        'chon' => $chon,
        'hang' => $hang,
        'tienMoiDiem' => (float) ($thanhVien['money_per_point'] ?? 0),
        'nhom' => $nhom,
        'gioiTinh' => $C::GIOI_TINH,
        // Có quyền khach-hang.sua không (API trả trong meta). Thiếu cờ thì giữ nút như
        // cũ — API vẫn chặn lượt sửa, cờ chỉ để khoá sẵn nút.
        // Ai đang đăng nhập: chỉ người ghi mới thấy nút xoá ghi chú của mình.
        'toi' => (int) data_get(session('api.user'), 'id', 0),
        'coTheSua' => array_key_exists('co_the_sua', $meta) ? (bool) $meta['co_the_sua'] : true,
        'url' => [
            'store' => route('thu-ngan.crm.store'),
            'update' => route('thu-ngan.crm.update', ['id' => 0]),
            'don' => route('thu-ngan.crm.donHang', ['id' => 0]),
            'conNo' => route('thu-ngan.crm.conNo', ['id' => 0]),
            'tangMaSN' => route('thu-ngan.crm.tangMaSinhNhat', ['id' => 0]),
            'daChucSN' => route('thu-ngan.crm.daChucSinhNhat', ['id' => 0]),
            'ghiChu' => route('thu-ngan.crm.ghiChu', ['id' => 0]),
            'xoaGhiChu' => route('thu-ngan.crm.xoaGhiChu', ['id' => 0, 'note' => 0]),
            'lichSu' => route('thu-ngan.don-hang.index'),
        ],
    ];
@endphp

@section('title', $C::TITLE)
@section('than', 'tc-page')

@section('content')
    <section class="tc-list" aria-labelledby="crTitle">
        <div class="tc-top">
            <div class="tc-title">
                <h1 id="crTitle">Khách hàng</h1>
                <p>{{ $f['q'] !== '' ? 'Kết quả tìm “'.$f['q'].'”' : $total.' khách' }}</p>
            </div>
            <form class="search" role="search" method="get" action="{{ route('thu-ngan.crm.index') }}">
                <i class="fa-solid fa-magnifying-glass search__icon" aria-hidden="true"></i>
                <label class="sr-only" for="crQ">Tìm khách</label>
                <input id="crQ" name="q" type="search" autocomplete="off" placeholder="Tên, email hoặc số điện thoại" value="{{ $f['q'] }}" data-q>
                @foreach(['nhanh', 'type', 'rank'] as $k)
                    @if(($giu[$k] ?? '') !== '')<input type="hidden" name="{{ $k }}" value="{{ $giu[$k] }}">@endif
                @endforeach
                <kbd>F1</kbd>
            </form>
            <div class="tc-new">
                <button type="button" class="btn btn--cr" data-create title="Thêm khách (F2)"><i class="fa-solid fa-user-plus"></i><span><span class="tc-new__w">Thêm </span>khách</span><kbd>F2</kbd></button>
            </div>
        </div>

        <div class="tc-bar cr-locbar">
            <div class="cr-chips" role="group" aria-label="Lọc nhanh">
                @foreach($C::NHANH as $k => $n)
                    <a class="cr-chip" data-nhanh="{{ $k }}" href="{{ $link(['nhanh' => $k, 'page' => null, 'khach' => null]) }}" aria-pressed="{{ $f['nhanh'] === $k ? 'true' : 'false' }}"><i class="fa-solid {{ $n['icon'] }}"></i>{{ $n['ten'] }}@if($n['dem'] && isset($dem[$k]))<b>{{ $dem[$k] }}</b>@endif</a>
                @endforeach
            </div>
            <form class="cr-filter" method="get" action="{{ route('thu-ngan.crm.index') }}" data-rank-form>
                @foreach(['q', 'nhanh', 'type'] as $k)
                    @if(($giu[$k] ?? '') !== '')<input type="hidden" name="{{ $k }}" value="{{ $giu[$k] }}">@endif
                @endforeach
                <select class="select" name="rank" aria-label="Hạng thành viên" data-rank>
                    <option value="">Mọi hạng</option>
                    @foreach($hang as $r)
                        <option value="{{ $r['id'] }}" @selected($f['rank'] === (int) $r['id'])>{{ $r['name'] }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        <div class="tc-card">
            <div class="tc-tabs" role="tablist" aria-label="Loại khách">
                @foreach($C::LOAI as $k => $nhan)
                    <a class="tc-tab" role="tab" href="{{ $link(['type' => $k, 'page' => null, 'khach' => null]) }}" aria-selected="{{ $f['type'] === (string) $k ? 'true' : 'false' }}">{{ $nhan }}</a>
                @endforeach
            </div>
            <div class="tc-scroll">
                @if($error)
                    <div class="tc-state"><span class="tc-state__ic"><i class="fa-solid fa-wifi"></i></span><h3>Không tải được danh sách khách</h3><p>{{ $error }}</p><a class="btn btn--outline" href="{{ request()->fullUrl() }}">Thử lại</a></div>
                @elseif(! $rows)
                    <div class="tc-state">
                        @if($f['q'] !== '')
                            <span class="tc-state__ic"><i class="fa-solid fa-magnifying-glass"></i></span><h3>Không có khách khớp “{{ $f['q'] }}”</h3><p>Thử số điện thoại không dấu cách, hoặc một phần tên.</p>
                            <button type="button" class="btn btn--cr" data-create-q="{{ $f['q'] }}"><i class="fa-solid fa-user-plus"></i> Thêm “{{ $f['q'] }}” làm khách mới</button>
                        @elseif($f['nhanh'] !== '' || $f['type'] !== '' || $f['rank'])
                            <span class="tc-state__ic"><i class="fa-solid fa-filter"></i></span><h3>Không có khách trong nhóm này</h3><p>Đổi nút lọc hoặc hạng ở trên.</p>
                        @else
                            <span class="tc-state__ic"><i class="fa-solid fa-users"></i></span><h3>Cửa hàng chưa có khách nào</h3><p>Thêm khách để tích điểm, nhận ưu đãi hạng và theo dõi công nợ.</p>
                            <button type="button" class="btn btn--cr" data-create><i class="fa-solid fa-user-plus"></i> Thêm khách</button>
                        @endif
                    </div>
                @else
                    <ul class="tc-rows" role="listbox" aria-label="Khách hàng">
                        @foreach($rows as $c)
                            @php
                                $dn = (int) ($c['customer_type'] ?? 0) === 1;
                                $sn = $dn ? null : $sinhNhat($c['date_of_birth'] ?? null);
                                [$rk, $rkNen] = $mauHang($c['rank_id'] ?? null);
                                $no = (float) ($c['still_in_debt'] ?? 0);
                            @endphp
                            <li class="tc-row cr-row" role="option" tabindex="{{ (int) $c['id'] === $chon ? 0 : -1 }}" aria-selected="{{ (int) $c['id'] === $chon ? 'true' : 'false' }}" data-id="{{ $c['id'] }}">
                                <span class="cr-av{{ $dn ? ' is-biz' : '' }}" aria-hidden="true">@if($dn)<i class="fa-solid fa-building"></i>@else{{ $vietTat($c['full_name']) }}@endif</span>
                                <div class="tc-r1"><span class="cr-name">{{ $c['full_name'] }}</span>
                                    @if(! empty($c['rank_name']))<span class="cr-rank" style="--rk:{{ $rk }};--rk-bg:{{ $rkNen }}"><i class="fa-solid fa-crown"></i>{{ $c['rank_name'] }}</span>@endif
                                    @if($sn !== null)<i class="fa-solid fa-cake-candles cr-cake" title="{{ $sn === 0 ? 'Sinh nhật hôm nay' : 'Sinh nhật sau '.$sn.' ngày' }}"></i>@endif
                                </div>
                                @if($no > 0)<span class="cr-debt">Nợ {{ $so($no) }}</span>@else<span class="tc-amt t-num">{{ $so($c['total_spent'] ?? 0) }}</span>@endif
                                <div class="tc-r2">{{ ($c['phone'] ?? '') ?: ($c['customer_code'] ?? '') }} · {{ $so($c['points'] ?? 0) }} điểm</div>
                                <span class="cr-last">{{ $lanCuoi($c['last_order_at'] ?? null) }}</span>
                                @if($f['nhanh'] === 'birthday')
                                    <div class="cr-sn" data-sn-box>
                                        <button type="button" class="cr-sn__tang" data-sn-tang title="Sinh mã giảm giá dùng một lần cho riêng khách này"><i class="fa-solid fa-gift"></i> Tạo mã sinh nhật</button>
                                        <button type="button" class="cr-sn__chuc" data-sn-chuc title="Đã chúc, không tặng mã — khách rời danh sách tới năm sau">Đã chúc</button>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="tc-foot">
                <span>{{ $total }} khách</span>
                @if($soTrang > 1)
                    <span>
                        @if($trang > 1)<a href="{{ $link(['page' => $trang - 1]) }}">‹ Trước</a>@endif
                        {{ $trang }}/{{ $soTrang }}
                        @if($trang < $soTrang)<a href="{{ $link(['page' => $trang + 1]) }}">Sau ›</a>@endif
                    </span>
                @elseif($rows)
                    <span>↑ ↓ để chuyển khách</span>
                @endif
            </div>
        </div>
    </section>

    <section class="tc-detail" aria-label="Hồ sơ khách" data-detail>
        <div class="tc-paper" data-paper></div>
    </section>

<script type="application/json" id="crDuLieu">@json($duLieu)</script>
@endsection

@push('modals')
<dialog class="modal tc-dlg cr-dlg" id="crHop" aria-labelledby="crHopT">
    <form method="dialog" data-cform novalidate>
        <header class="tcm-head">
            <span class="tcm-badge" aria-hidden="true" data-c-badge><i class="fa-solid fa-user-plus"></i></span>
            <div class="tcm-head__t"><h2 id="crHopT" data-c-title>Thêm khách</h2><p data-c-sub>Mã khách tự sinh khi lưu</p></div>
            <button type="button" class="tcm-x" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="modal__body tcm-body">
            <div class="tcm-col tcm-col--money">
                <div class="cr-seg" role="group" aria-label="Loại khách" data-c-type>
                    <button type="button" data-v="0" aria-pressed="true">Cá nhân</button>
                    <button type="button" data-v="1" aria-pressed="false">Doanh nghiệp</button>
                </div>
                <div class="tcm-f">
                    <div class="tcm-lab"><label for="cName" data-c-name-label>Tên khách</label><small class="is-req">Bắt buộc</small></div>
                    <input class="input" id="cName" maxlength="150" autocomplete="off" data-c-name>
                </div>
                <div class="tcm-f">
                    <div class="tcm-lab"><label for="cPhone">Số điện thoại</label><small>Dùng để nhận ra khách lần sau</small></div>
                    <input class="input" id="cPhone" inputmode="tel" maxlength="20" autocomplete="off" data-c-phone>
                </div>
                <div class="cr-dup" data-c-dup hidden><i class="fa-solid fa-user-check"></i><span data-c-dup-t></span><button type="button" data-c-dup-open>Mở hồ sơ</button></div>
                <div class="tcm-f" data-c-biz hidden>
                    <div class="tcm-lab"><label for="cTax">Mã số thuế</label><small>Không bắt buộc</small></div>
                    <input class="input" id="cTax" inputmode="numeric" maxlength="20" autocomplete="off" data-c-tax>
                </div>
                <div class="tcm-f">
                    <div class="tcm-lab"><label for="cEmail">Email</label><small>Không bắt buộc</small></div>
                    <input class="input" id="cEmail" type="email" maxlength="191" autocomplete="off" data-c-email>
                </div>
            </div>
            <div class="tcm-col">
                <div class="cr-row2" data-c-person>
                    <div class="tcm-f"><div class="tcm-lab"><label for="cDob">Ngày sinh</label></div><input class="input" id="cDob" type="date" max="{{ now()->toDateString() }}" data-c-dob></div>
                    <div class="tcm-f"><div class="tcm-lab"><span>Giới tính</span></div>
                        <div class="cr-gender" role="group" aria-label="Giới tính" data-c-gender><button type="button" data-v="male">Nam</button><button type="button" data-v="female">Nữ</button><button type="button" data-v="other">Khác</button></div></div>
                </div>
                <div class="tcm-f"><div class="tcm-lab"><label for="cAddr">Địa chỉ</label><small>Không bắt buộc</small></div><input class="input" id="cAddr" maxlength="255" autocomplete="off" data-c-addr></div>
                <div class="tcm-f"><div class="tcm-lab"><label for="cGroup">Nhóm khách</label><small>Nhóm mới thêm ở Quản trị</small></div><select class="select" id="cGroup" data-c-group></select></div>
                <div class="tcm-f"><div class="tcm-lab"><label for="cNote">Ghi chú cố định</label><small>Dị ứng, yêu cầu riêng — hiện đầu hồ sơ</small></div>
                    <div class="tcm-note"><textarea class="input" id="cNote" maxlength="500" rows="3" placeholder="Vd. Dị ứng len; luôn xuất hoá đơn công ty" data-c-note style="height:auto;min-height:86px"></textarea></div></div>
                <span class="tc-err" data-c-err hidden></span>
            </div>
        </div>
        <footer class="tcm-foot">
            <span class="tcm-keys"><kbd>Esc</kbd> đóng · <kbd>Ctrl</kbd> <kbd>Enter</kbd> lưu</span>
            <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
            <button type="submit" class="btn tcm-ok" data-c-ok><span data-c-ok-t>Lưu khách</span></button>
        </footer>
    </form>
</dialog>
@include('v2::pos._thu-no')

<dialog class="modal tc-dlg cr-dlg cr-sn-dlg" id="crSnHop" aria-labelledby="crSnHopT">
    <header class="tcm-head">
        <span class="tcm-badge" aria-hidden="true"><i class="fa-solid fa-gift"></i></span>
        <div class="tcm-head__t"><h2 id="crSnHopT" data-sn-title>Mã sinh nhật</h2><p data-sn-sub></p></div>
        <button type="button" class="tcm-x" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button>
    </header>
    <div class="modal__body tcm-body cr-sn-body">
        <div class="cr-sn-ma"><b data-sn-code></b><button type="button" class="cr-sn-chep" data-sn-copy title="Chép mã"><i class="fa-regular fa-copy"></i></button></div>
        <dl class="cr-sn-dk" data-sn-dk></dl>
        <p class="cr-sn-luu"><i class="fa-solid fa-circle-info"></i><span data-sn-luu></span></p>
    </div>
    <footer class="tcm-foot">
        <button type="button" class="btn btn--ghost" data-tn-close>Đóng</button>
        <button type="button" class="btn tcm-ok" data-sn-in><i class="fa-solid fa-print"></i> In phiếu</button>
    </footer>
</dialog>
@endpush

@push('styles')
@foreach(['cashbook.css', 'returns.css', 'thu-no.css', 'crm.css'] as $tep)
<link rel="stylesheet" href="{{ asset('v2/css/pos-v3/'.$tep) }}?v={{ filemtime(public_path('v2/css/pos-v3/'.$tep)) }}">
@endforeach
@endpush

@push('scripts')
<script>
(function () {
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const num = (n) => Math.round(Number(n) || 0).toLocaleString('vi-VN');
    const vnd = (n) => `${num(n)} đ`;
    const pad = (n) => String(n).padStart(2, '0');
    const dmy = (d) => `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
    const dm = (d) => `${pad(d.getDate())}/${pad(d.getMonth() + 1)}`;
    const hm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const pickBtns = (box, v) => $$('button', box).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === String(v))));
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const D = JSON.parse($('#crDuLieu').textContent);
    const ROWS = D.rows || [];
    const HANG = D.hang || [];
    const urlId = (mau, id) => mau.replace(/\/0(?=\/|$)/, `/${id}`);
    const rowOf = (id) => ROWS.find((c) => c.id === id);
    const ngaySinh = (s) => (s ? new Date(String(s).slice(0, 10) + 'T00:00') : null);
    const initials = (s) => { const w = String(s || '').replace(/\(.*?\)/g, '').trim().split(/\s+/).filter(Boolean); return (w.length > 1 ? w[0][0] + w[w.length - 1][0] : (w[0] || '?').slice(0, 2)).toUpperCase(); };
    const sinhNhat = (s) => { const d = ngaySinh(s); if (!d) return null; const n = new Date(); n.setHours(0, 0, 0, 0); const y = new Date(n.getFullYear(), d.getMonth(), d.getDate()); if (y < n) y.setFullYear(y.getFullYear() + 1); const k = Math.round((y - n) / 864e5); return k <= 7 ? k : null; };
    const mauHang = (id) => HANG.find((r) => r.id === id)?.mau || ['#545b6b', '#f0f2f5', '#e3e8f0'];
    const hangKe = (tp) => HANG.find((r) => r.point > tp) || null;
    const hangNay = (id) => HANG.find((r) => r.id === id) || null;
    const NHAN_TT = { paid: ['Đã thanh toán', 'is-paid'], pending: ['Chưa thanh toán', 'is-owe'], failed: ['Thanh toán lỗi', 'is-owe'], refunded: ['Đã hoàn tiền', 'is-ret'] };
    const NHAN_DON = { cancelled: 'Đã huỷ', returned: 'Đã trả hàng' };

    async function goi(u, opts = {}) {
        const res = await fetch(u, { headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF }, ...opts });
        let body = {};
        try { body = await res.json(); } catch (e) { /* trang lỗi không phải JSON */ }
        if (!res.ok) {
            const loi = new Error(body.message || (body.errors ? Object.values(body.errors).flat()[0] : '') || 'Không kết nối được máy chủ. Vui lòng thử lại.');
            loi.ma = body.errors?.ma;
            loi.data = body.data;
            throw loi;
        }
        return body;
    }
    function taiLai(id, msg) {
        try { if (msg) sessionStorage.setItem('cr.toast', msg); } catch (e) { /* máy chặn lưu */ }
        const u = new URL(location.href);
        if (id) u.searchParams.set('khach', id); else u.searchParams.delete('khach');
        location.href = u.toString();
    }
    try { const m = sessionStorage.getItem('cr.toast'); if (m) { sessionStorage.removeItem('cr.toast'); posToast(m); } } catch (e) { /* bỏ qua */ }

    /* ------------------------------------------------------------ hồ sơ */
    const paper = $('[data-paper]');
    const detail = $('[data-detail]');
    let sel = D.chon || null;
    let daHien = null;
    let tab = 'don';
    const donCache = {};
    const noCache = {};
    const noDangTai = {};
    const ghiCache = {};
    const ghiDangTai = {};
    const pad2 = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const HOM_NAY = pad2(new Date());
    // Ô gõ nhanh của tab Ghi chú — giữ qua các lượt vẽ lại khung, xoá khi đổi khách.
    const nf = { loai: 'cham-soc', ngay: HOM_NAY, noi: '', dangGui: false };
    const LOAI_GC = {
        'cham-soc': ['Chăm sóc', 'fa-comment-dots'],
        hen: ['Hẹn', 'fa-calendar-check'],
        'canh-bao': ['Cảnh báo', 'fa-triangle-exclamation'],
    };
    const coHenNay = (ds) => (ds || []).some((g) => g.loai === 'hen' && g.ngay_hen === HOM_NAY);

    // Sổ ghi chú: tải cho mọi khách được mở — dòng cảnh báo / hẹn hôm nay ở đầu khung
    // và số trên tab cần nó, không riêng lúc đứng ở tab Ghi chú.
    function taiGhi(c) {
        if (ghiCache[c.id] !== undefined || ghiDangTai[c.id]) return;
        ghiDangTai[c.id] = goi(urlId(D.url.ghiChu, c.id)).then((body) => { ghiCache[c.id] = body.data || []; }, (err) => { ghiCache[c.id] = err; })
            .finally(() => { delete ghiDangTai[c.id]; if (sel === c.id) veGhi(c); });
    }
    function veGhi(c) {
        const ds = ghiCache[c.id] instanceof Error ? null : ghiCache[c.id];
        const dem = $('[data-tab="note"] b');
        if (dem) { dem.textContent = ds && ds.length ? ds.length : ''; dem.hidden = !(ds && ds.length); }
        const canh = (ds || []).find((g) => g.loai === 'canh-bao');
        const hen = (ds || []).filter((g) => g.loai === 'hen' && g.ngay_hen === HOM_NAY);
        const top = $('[data-alerts]');
        if (top) {
            top.innerHTML = (canh ? `<p class="cr-alert is-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>${esc(canh.noi_dung)}</span></p>` : '')
                + hen.map((g) => `<p class="cr-alert is-hen"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i><span><b>Hẹn hôm nay:</b> ${esc(g.noi_dung)}</span></p>`).join('');
            top.hidden = !top.innerHTML;
        }
        const box = $('[data-notes]');
        if (!box) return;
        const g0 = ghiCache[c.id];
        if (g0 === undefined) { box.innerHTML = '<div class="th-empty">Đang tải ghi chú…</div>'; return; }
        if (g0 instanceof Error) { box.innerHTML = `<div class="th-empty">${esc(g0.message)}</div>`; return; }
        if (!g0.length) { box.innerHTML = '<div class="th-empty">Chưa có ghi chú nào. Ghi lại điều đã hứa với khách để người trực sau biết.</div>'; return; }
        box.innerHTML = g0.map((g) => {
            const [ten, ic] = LOAI_GC[g.loai] || LOAI_GC['cham-soc'];
            const d = new Date(g.created_at);
            const ngay = g.ngay_hen ? (g.ngay_hen === HOM_NAY ? '<b class="cr-n__hen is-nay">Hẹn hôm nay</b>' : `<b class="cr-n__hen${g.ngay_hen < HOM_NAY ? ' is-qua' : ''}">Hẹn ${(g.ngay_hen.slice(0, 4) === HOM_NAY.slice(0, 4) ? dm : dmy)(new Date(g.ngay_hen + 'T00:00'))}</b>`) : '';
            return `<div class="cr-n is-${esc(g.loai)}"><span class="cr-n__ic" title="${ten}"><i class="fa-solid ${ic}"></i></span><div class="cr-n__t"><p>${esc(g.noi_dung)}</p>
                <small>${ngay || ten} · ${esc(g.nguoi_ghi || '—')} · ${dmy(d)} ${hm(d)}${g.ten_chi_nhanh ? ` · ${esc(g.ten_chi_nhanh)}` : ''}</small></div>
                ${D.toi && g.created_by === D.toi ? `<button type="button" class="cr-n__x" data-nxoa="${g.id}" aria-label="Xoá ghi chú" title="Xoá ghi chú của bạn"><i class="fa-solid fa-trash-can"></i></button>` : ''}</div>`;
        }).join('');
    }
    function veOGhi() {
        const f = $('[data-nform]');
        if (!f) return;
        pickBtns($('[data-nloai]', f), nf.loai);
        const ngay = $('[data-nngay]', f);
        ngay.hidden = nf.loai !== 'hen';
        ngay.value = nf.ngay;
        $('[data-nnoi]', f).value = nf.noi;
        $('[data-nnoi]', f).placeholder = nf.loai === 'hen' ? 'Hẹn gì? Vd. 17h qua lấy áo đã sửa' : nf.loai === 'canh-bao' ? 'Điều người trực sau cần cẩn thận' : 'Thêm ghi chú… Enter để lưu';
        $('[data-nok]', f).disabled = nf.dangGui;
    }
    // Đổi trạng thái "có hẹn hôm nay" của một khách → số trên nút lọc.
    function demHen(truoc, sau) {
        const b = $('.cr-chip[data-nhanh="hen"] b');
        if (b && truoc !== sau) b.textContent = Math.max(0, (Number(b.textContent) || 0) + (sau ? 1 : -1));
    }
    async function luuGhi() {
        const c = rowOf(sel);
        const err = $('[data-nerr]');
        if (!c || nf.dangGui) return;
        nf.noi = $('[data-nnoi]').value;
        if (!nf.noi.trim()) { err.textContent = 'Nhập nội dung ghi chú.'; err.hidden = false; $('[data-nnoi]').focus(); return; }
        if (nf.loai === 'hen' && !nf.ngay) { err.textContent = 'Chọn ngày hẹn.'; err.hidden = false; return; }
        nf.dangGui = true;
        err.hidden = true;
        veOGhi();
        try {
            const body = await goi(urlId(D.url.ghiChu, c.id), { method: 'POST', body: JSON.stringify({ loai: nf.loai, noi_dung: nf.noi.trim(), ngay_hen: nf.loai === 'hen' ? nf.ngay : '' }) });
            const ds = Array.isArray(ghiCache[c.id]) ? ghiCache[c.id] : [];
            const truoc = coHenNay(ds);
            ghiCache[c.id] = [body.data, ...ds];
            demHen(truoc, coHenNay(ghiCache[c.id]));
            nf.noi = '';
            posToast('Đã thêm ghi chú.');
        } catch (e) {
            err.textContent = e.message;
            err.hidden = false;
        }
        nf.dangGui = false;
        if (sel === c.id) { veGhi(c); veOGhi(); $('[data-nnoi]')?.focus(); }
    }
    async function xoaGhi(nut) {
        const c = rowOf(sel);
        if (!c) return;
        // Bấm hai lần: lần đầu hỏi lại ngay trên nút, khỏi mở thêm hộp thoại.
        if (!nut.classList.contains('is-hoi')) { nut.classList.add('is-hoi'); nut.innerHTML = 'Xoá?'; return; }
        const id = +nut.dataset.nxoa;
        nut.disabled = true;
        try {
            await goi(D.url.xoaGhiChu.replace(/\/0\/notes\/0$/, `/${c.id}/notes/${id}`), { method: 'DELETE' });
            const truoc = coHenNay(ghiCache[c.id]);
            ghiCache[c.id] = ghiCache[c.id].filter((g) => g.id !== id);
            demHen(truoc, coHenNay(ghiCache[c.id]));
            posToast('Đã xoá ghi chú.');
        } catch (e) {
            posToast(e.message, 'error');
        }
        if (sel === c.id) veGhi(c);
    }

    function veDon(c) {
        const box = $('[data-don]');
        if (!box) return;
        const ds = donCache[c.id];
        if (ds === undefined) { box.innerHTML = '<div class="th-empty">Đang tải lịch sử…</div>'; return; }
        if (ds instanceof Error) { box.innerHTML = `<div class="th-empty">${esc(ds.message)}</div>`; return; }
        if (!ds.length) { box.innerHTML = '<div class="th-empty">Khách chưa mua đơn nào.</div>'; return; }
        const no = noCache[c.id] instanceof Error ? null : noCache[c.id];
        const dongNo = (id) => no?.don.find((x) => x.id === id);
        // Ô dưới mã đơn: đơn còn nợ thì số nợ + nút Thu (đơn chi nhánh khác chỉ báo nơi thu).
        const oThu = (n, kenh) => (!n ? `<small>${kenh}</small>`
            : n.thu_duoc ? `<span class="cr-ord__no">Nợ <b>${num(n.con_no)}</b><button type="button" class="cr-thu" data-thu="${n.id}"><i class="fa-solid fa-hand-holding-dollar"></i>Thu</button></span>`
                : `<small>Nợ ${num(n.con_no)} · thu ở ${esc(n.ten_chi_nhanh || 'chi nhánh khác')}</small>`);
        const coDs = new Set(ds.map((o) => o.id));
        const cuHon = (no?.don || []).filter((n) => !coDs.has(n.id));
        box.innerHTML = ds.map((o) => {
            const d = new Date(o.created_at);
            const huy = NHAN_DON[o.status];
            const [tt, lop] = huy ? [huy, 'is-ret'] : (NHAN_TT[o.payment_status] || [o.payment_status, '']);
            return `<div class="cr-ord"><span><b>${esc(o.order_code)}</b> <small>· ${dmy(d)} ${hm(d)}</small></span><span class="cr-ord__amt">${num(o.total_amount)}</span>
                ${oThu(dongNo(o.id), o.channel === 'pos' ? 'Bán tại quầy' : 'Đơn giao hàng')}<span class="cr-st ${lop}">${esc(tt)}</span></div>`;
        }).join('') + `<p class="th-hint" style="margin-top:8px">10 đơn gần nhất · <a href="${esc(D.url.lichSu)}?q=${encodeURIComponent(c.phone || c.full_name)}" style="color:var(--c-navy)">xem ở Lịch sử →</a></p>`
        + (cuHon.length ? `<p class="th-sec">Đơn còn nợ cũ hơn</p>${cuHon.map((n) => {
            const d = new Date(n.created_at);
            return `<div class="cr-ord"><span><b>${esc(n.order_code)}</b> <small>· ${dmy(d)} ${hm(d)}</small></span><span class="cr-ord__amt">${num(n.tong_tien)}</span>
                ${oThu(n, '')}<span class="cr-st is-owe">${n.da_thu > 0 ? 'Còn nợ' : 'Chưa thanh toán'}</span></div>`;
        }).join('')}` : '');
    }
    async function taiDon(c) {
        if (donCache[c.id] !== undefined) return;
        try {
            const body = await goi(urlId(D.url.don, c.id));
            donCache[c.id] = body.data || [];
        } catch (err) {
            donCache[c.id] = err;
        }
        if (sel === c.id && tab === 'don') veDon(c);
    }

    // Đơn còn nợ của khách (cũ trước). Tổng API trả về cùng công thức cột Còn nợ, nên
    // mỗi lượt tải là lúc đồng bộ lại con số trên khung và trên dòng danh sách.
    function taiNo(c, ep) {
        if (!ep && (noCache[c.id] !== undefined || !(c.still_in_debt > 0))) return noDangTai[c.id];
        if (noDangTai[c.id]) return noDangTai[c.id];
        noDangTai[c.id] = goi(urlId(D.url.conNo, c.id)).then((body) => {
            noCache[c.id] = { tong: Number(body.data?.tong_con_no) || 0, don: body.data?.don || [] };
            datNo(c, noCache[c.id].tong);
        }, (err) => { noCache[c.id] = err; }).finally(() => {
            delete noDangTai[c.id];
            if (sel === c.id) { veNo(c); if (tab === 'don') veDon(c); }
        });
        return noDangTai[c.id];
    }
    function veNo(c) {
        const k = $('[data-kpi-no]');
        if (k) { k.textContent = num(c.still_in_debt); k.parentElement.classList.toggle('is-debt', c.still_in_debt > 0); }
        const box = $('[data-no]');
        if (!box) return;
        box.hidden = !(c.still_in_debt > 0);
        if (box.hidden) return;
        const n = noCache[c.id];
        const cu = n && !(n instanceof Error) ? n.don.find((x) => x.thu_duoc) : null;
        const ly = n === undefined ? 'Đang tải đơn nợ…' : n instanceof Error ? n.message
            : cu ? `${n.don.length} đơn · thu đơn cũ nhất ${cu.order_code} trước` : 'Đơn nợ thuộc chi nhánh khác — thu ở chi nhánh đó';
        box.innerHTML = `<i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i><span>Còn nợ <b class="t-num">${num(c.still_in_debt)}</b><small>${esc(ly)}</small></span>
            <button type="button" class="btn" data-thu="${cu ? cu.id : ''}"${cu ? '' : ' disabled'}>Thu nợ</button>`;
    }
    // Đổi số nợ của khách: dòng danh sách + số đếm của nút lọc "Nợ nhiều nhất".
    function datNo(c, moi) {
        const cu = Number(c.still_in_debt) || 0;
        c.still_in_debt = moi;
        const o = $(`.tc-row[data-id="${c.id}"]`)?.querySelector('.cr-debt, .tc-amt');
        if (o) o.outerHTML = moi > 0 ? `<span class="cr-debt">Nợ ${num(moi)}</span>` : `<span class="tc-amt t-num">${num(c.total_spent)}</span>`;
        const dem = $('.cr-chip[data-nhanh="debt"] b');
        if (dem && (cu > 0) !== (moi > 0)) dem.textContent = Math.max(0, (Number(dem.textContent) || 0) + (moi > 0 ? 1 : -1));
    }
    function moThu(id) {
        const c = rowOf(sel);
        const n = c && !(noCache[c.id] instanceof Error) ? noCache[c.id]?.don.find((x) => x.id === id) : null;
        if (!n || !n.thu_duoc) return;
        window.posThuNo({ id: n.id, ma: n.order_code, tong_tien: n.tong_tien, da_thu: n.da_thu, con_no: n.con_no }, (v) => {
            posToast(`Đã ghi ${vnd(v)} cho ${n.order_code}${v >= n.con_no ? ' — đơn đã thu đủ' : ''}.`);
            // Số tạm ngay khi đóng hộp; taiNo lấy lại số thật của API rồi vẽ lại.
            c.total_paid = (Number(c.total_paid) || 0) + v;
            datNo(c, Math.max(0, (Number(c.still_in_debt) || 0) - v));
            delete noCache[c.id];
            delete donCache[c.id];
            taiNo(c, true);
            if (sel === c.id) veChiTiet();
        });
    }

    function veChiTiet() {
        const c = rowOf(sel);
        detail.classList.toggle('is-open', !!c && innerWidth < 900 && daHien !== null);
        if (!c) {
            paper.innerHTML = '<div class="tc-blank"><div><span class="tc-state__ic"><i class="fa-solid fa-user"></i></span>Chọn một khách để xem hồ sơ</div></div>';
            return;
        }
        const dn = Number(c.customer_type) === 1;
        const [rk, rkNen, rkVien] = mauHang(c.rank_id);
        const r = hangNay(c.rank_id);
        const ke = hangKe(Number(c.total_points) || 0);
        const goc = r ? r.point : 0;
        const pct = ke ? Math.max(0, Math.min(100, Math.round(((c.total_points || 0) - goc) / (ke.point - goc) * 100))) : 100;
        const giam = r && r.discount_value ? `giảm ${r.discount_type === 'percent' ? `${num(r.discount_value)}%` : vnd(r.discount_value)} mỗi đơn` : '';
        const sn = dn ? null : sinhNhat(c.date_of_birth);
        const cuoi = c.last_order_at ? new Date(c.last_order_at) : null;
        let body;
        if (tab === 'don') {
            body = '<div data-don></div>';
        } else if (tab === 'note') {
            body = `<form class="cr-nform" data-nform novalidate>
                <div class="cr-nloai" role="group" aria-label="Loại ghi chú" data-nloai>${Object.entries(LOAI_GC).map(([k, [t, ic]]) => `<button type="button" data-v="${k}" aria-pressed="false"><i class="fa-solid ${ic}"></i>${t}</button>`).join('')}</div>
                <div class="cr-nrow">
                    <input class="input cr-nngay" type="date" min="${HOM_NAY}" aria-label="Ngày hẹn" data-nngay hidden>
                    <input class="input" maxlength="500" autocomplete="off" aria-label="Nội dung ghi chú" data-nnoi>
                    <button type="submit" class="btn btn--cr" data-nok><i class="fa-solid fa-plus"></i> Thêm ghi chú</button>
                </div>
                <span class="tc-err" data-nerr hidden></span>
            </form><div class="cr-notes" data-notes></div>`;
        } else {
            const dob = ngaySinh(c.date_of_birth);
            body = `<dl class="tc-kv">
                <div><dt>Mã khách</dt><dd>${esc(c.customer_code || '—')}</dd></div>
                ${dn ? `<div><dt>Mã số thuế</dt><dd>${esc(c.tax_code || '—')}</dd></div>` : `<div><dt>Ngày sinh</dt><dd>${dob ? dmy(dob) : '—'}${sn !== null ? `<small style="color:#d6336c">${sn === 0 ? 'Sinh nhật hôm nay' : `Sinh nhật sau ${sn} ngày`}</small>` : ''}</dd></div><div><dt>Giới tính</dt><dd>${esc(D.gioiTinh[c.gender] || '—')}</dd></div>`}
                <div><dt>Email</dt><dd style="overflow-wrap:anywhere">${esc(c.email || '—')}</dd></div>
                <div><dt>Địa chỉ</dt><dd>${esc(c.address || '—')}</dd></div>
                <div><dt>Nhóm</dt><dd>${esc(c.customer_group_name || '—')}</dd></div>
                <div><dt>Tổng đã thanh toán</dt><dd>${vnd(c.total_paid || 0)}</dd></div>
            </dl>`;
        }
        paper.classList.toggle('is-swap', daHien !== c.id);
        paper.innerHTML = `
            <div class="tc-anim" style="display:contents">
            <div class="tc-d-head">
                <div class="cr-head">
                    <button type="button" class="tc-d-mini tc-back" aria-label="Quay lại danh sách" data-back><i class="fa-solid fa-arrow-left"></i></button>
                    <span class="cr-av${dn ? ' is-biz' : ''}" aria-hidden="true">${dn ? '<i class="fa-solid fa-building"></i>' : esc(initials(c.full_name))}</span>
                    <div style="min-width:0"><h2>${esc(c.full_name)}</h2><small>${esc(c.phone || 'Chưa có SĐT')} · ${esc(c.customer_code || '')}${c.customer_group_name ? ` · ${esc(c.customer_group_name)}` : ''}</small></div>
                    ${c.phone ? `<a class="cr-call" href="tel:${esc(String(c.phone).replace(/\s/g, ''))}" aria-label="Gọi ${esc(c.phone)}" title="Gọi"><i class="fa-solid fa-phone"></i></a>` : ''}
                </div>
                ${c.customer_note ? `<p class="cr-pin" title="Ghi chú cố định — sửa ở Sửa thông tin"><i class="fa-solid fa-thumbtack" aria-hidden="true"></i><span>${esc(c.customer_note)}</span></p>` : ''}
                <div class="cr-alerts" data-alerts hidden></div>
                <div class="cr-nobar" data-no hidden></div>
                <div class="cr-pts" style="--rk:${rk};--rk-bg:${rkNen};--rk-line:${rkVien}">
                    <div class="cr-pts__top"><span>${c.rank_name ? `<span class="cr-rank" style="--rk:${rk};--rk-bg:${rkNen}"><i class="fa-solid fa-crown"></i>${esc(c.rank_name)}</span>` : 'Chưa có hạng'} ${giam ? `· ${esc(giam)}` : ''}</span><b class="t-num">${num(c.points)} <small>điểm${D.tienMoiDiem ? ` · ${vnd((c.points || 0) * D.tienMoiDiem)}` : ''}</small></b></div>
                    ${HANG.length ? `<div class="cr-bar" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"><i style="width:${pct}%"></i></div>
                    <div class="cr-pts__hint"><span>Tích luỹ ${num(c.total_points)} điểm</span><span>${ke ? `Còn ${num(ke.point - (c.total_points || 0))} điểm lên <b>${esc(ke.name)}</b>` : 'Hạng cao nhất'}</span></div>` : ''}
                </div>
                <div class="cr-kpis">
                    <div class="cr-kpi"><span>Tổng mua</span><b>${num(c.total_spent)}</b></div>
                    <div class="cr-kpi"><span>Số đơn · lần cuối</span><b>${num(c.total_orders)} · ${cuoi ? dm(cuoi) : '—'}</b></div>
                    <div class="cr-kpi${c.still_in_debt > 0 ? ' is-debt' : ''}"><span>Còn nợ</span><b data-kpi-no>${num(c.still_in_debt)}</b></div>
                </div>
            </div>
            <div class="cr-tabs" role="tablist">${[['don', 'Lịch sử giao dịch'], ['note', 'Ghi chú'], ['info', 'Thông tin']].map(([k, t]) => `<button type="button" role="tab" aria-selected="${tab === k}" data-tab="${k}">${t}${k === 'note' ? '<b hidden></b>' : ''}</button>`).join('')}</div>
            <div class="tc-d-body">${body}</div>
            ${D.coTheSua ? '' : '<p class="tc-why cr-quyen"><i class="fa-solid fa-lock"></i><span>Chưa được cấp quyền sửa khách — nhờ quản lý cấp quyền <b>Khách hàng · Sửa</b> ở Quản trị → Nhân sự.</span></p>'}
            <div class="tc-d-foot">
                <button type="button" class="btn btn--primary" data-edit${D.coTheSua ? '' : ' disabled title="Chưa được cấp quyền sửa khách"'}><i class="fa-solid fa-pen"></i> Sửa thông tin</button>
            </div>
            </div>`;
        daHien = c.id;
        veNo(c);
        taiNo(c);
        veGhi(c);
        taiGhi(c);
        if (tab === 'don') { veDon(c); taiDon(c); }
        if (tab === 'note') veOGhi();
    }

    function chon(id, focus) {
        if (id !== sel) Object.assign(nf, { loai: 'cham-soc', ngay: HOM_NAY, noi: '' });
        sel = id;
        $$('.tc-row').forEach((li) => {
            const on = +li.dataset.id === id;
            li.setAttribute('aria-selected', String(on));
            li.tabIndex = on ? 0 : -1;
            if (on && focus) { li.focus({ preventScroll: true }); li.scrollIntoView({ block: 'nearest' }); }
        });
        if (daHien === null) daHien = 0;
        veChiTiet();
        const u = new URL(location.href);
        u.searchParams.set('khach', id);
        history.replaceState(null, '', u);
    }

    /* ------------------------------------------------------------ hộp khách */
    const dlg = $('#crHop');
    const cf = { id: null, loai: 0, gt: '', dupId: null, dangGui: false };

    function renderCus() {
        pickBtns($('[data-c-type]'), cf.loai);
        pickBtns($('[data-c-gender]'), cf.gt);
        $('[data-c-biz]').hidden = cf.loai !== 1;
        $('[data-c-person]').hidden = cf.loai !== 0;
        $('[data-c-name-label]').textContent = cf.loai ? 'Tên doanh nghiệp' : 'Tên khách';
        const g = $('[data-c-group]');
        const cur = g.value;
        g.innerHTML = `<option value="">Không nhóm</option>${(D.nhom || []).filter((x) => Number(x.type) === cf.loai).map((x) => `<option value="${x.id}">${esc(x.name)}</option>`).join('')}`;
        if ([...g.options].some((o) => o.value === cur)) g.value = cur;
        const c = cf.id ? rowOf(cf.id) : null;
        $('[data-c-title]').textContent = c ? `Sửa · ${c.full_name}` : 'Thêm khách';
        $('[data-c-sub]').textContent = c ? `${c.customer_code || ''}` : 'Mã khách tự sinh khi lưu';
        $('[data-c-badge]').innerHTML = `<i class="fa-solid ${c ? 'fa-user-pen' : 'fa-user-plus'}"></i>`;
        $('[data-c-ok-t]').textContent = cf.dangGui ? 'Đang lưu…' : c ? 'Lưu thay đổi' : 'Lưu khách';
        $('[data-c-ok]').disabled = cf.dangGui;
    }
    function hienTrung(ten, id) {
        cf.dupId = id || null;
        $('[data-c-dup]').hidden = !id;
        if (id) $('[data-c-dup-t]').innerHTML = esc(ten);
    }
    function openCus(c, preset) {
        Object.assign(cf, { id: c?.id || null, loai: Number(c?.customer_type) || 0, gt: c?.gender || '', dupId: null, dangGui: false });
        $('[data-c-name]').value = c?.full_name || '';
        $('[data-c-phone]').value = c?.phone || '';
        $('[data-c-email]').value = c?.email || '';
        $('[data-c-tax]').value = c?.tax_code || '';
        $('[data-c-dob]').value = c?.date_of_birth ? String(c.date_of_birth).slice(0, 10) : '';
        $('[data-c-addr]').value = c?.address || '';
        $('[data-c-note]').value = c?.customer_note || '';
        if (preset) { if (/^[\d\s.+-]{6,}$/.test(preset)) $('[data-c-phone]').value = preset; else $('[data-c-name]').value = preset; }
        $('[data-c-err]').hidden = true;
        hienTrung('', null);
        renderCus();
        $('[data-c-group]').value = c?.customer_group_id ? String(c.customer_group_id) : '';
        dlg.showModal();
        $('[data-c-name]').focus();
    }
    async function saveCus() {
        if (cf.dangGui) return;
        const ten = $('[data-c-name]').value.trim();
        const err = $('[data-c-err]');
        if (!ten) { err.textContent = cf.loai ? 'Nhập tên doanh nghiệp.' : 'Nhập tên khách.'; err.hidden = false; $('[data-c-name]').focus(); return; }
        const payload = {
            full_name: ten, phone: $('[data-c-phone]').value.trim(), email: $('[data-c-email]').value.trim(),
            customer_type: cf.loai, gender: cf.loai ? '' : cf.gt, date_of_birth: cf.loai ? '' : $('[data-c-dob]').value,
            address: $('[data-c-addr]').value.trim(), customer_group_id: +$('[data-c-group]').value || 0,
            tax_code: cf.loai ? $('[data-c-tax]').value.trim() : '', customer_note: $('[data-c-note]').value.trim(),
        };
        cf.dangGui = true;
        err.hidden = true;
        renderCus();
        try {
            const body = cf.id
                ? await goi(urlId(D.url.update, cf.id), { method: 'PUT', body: JSON.stringify(payload) })
                : await goi(D.url.store, { method: 'POST', body: JSON.stringify(payload) });
            const id = body.data?.id || cf.id;
            dlg.close();
            taiLai(id, cf.id ? `Đã lưu ${ten}.` : `Đã thêm ${ten}${body.data?.customer_code ? ` (${body.data.customer_code})` : ''}.`);
        } catch (e) {
            cf.dangGui = false;
            if (e.ma === 'TRUNG_SDT') hienTrung(e.message, e.data?.id || null);
            else { err.textContent = e.message; err.hidden = false; }
            renderCus();
        }
    }
    $('[data-cform]').addEventListener('submit', (e) => { e.preventDefault(); saveCus(); });
    // Ô gõ nhanh nằm trong khung vẽ lại liên tục — bắt sự kiện ở khung.
    paper.addEventListener('submit', (e) => { if (e.target.matches('[data-nform]')) { e.preventDefault(); luuGhi(); } });
    paper.addEventListener('input', (e) => {
        if (e.target.matches('[data-nnoi]')) { nf.noi = e.target.value; $('[data-nerr]').hidden = true; }
        if (e.target.matches('[data-nngay]')) nf.ngay = e.target.value;
    });
    $('[data-c-phone]').addEventListener('input', () => hienTrung('', null));

    /* ------------------------------------------------------------ quà sinh nhật */
    const snDlg = $('#crSnHop');
    let snDangXem = null; // { c, v }
    const moTaGiam = (v) => (v.discount_type === 'percentage'
        ? `Giảm ${num(v.discount_value)}% tiền hàng${Number(v.max_discount_amount) > 0 ? `, tối đa ${vnd(v.max_discount_amount)}` : ''}`
        : `Giảm ${vnd(v.discount_value)}`);
    const hanDung = (v) => (v.end_at ? dmy(new Date(v.end_at)) : '');
    function moMaSN(c, v, cu) {
        snDangXem = { c, v };
        $('[data-sn-title]', snDlg).textContent = cu ? 'Đã tặng mã trước đó' : 'Mã sinh nhật đã tạo';
        $('[data-sn-sub]', snDlg).textContent = `${c.full_name}${cu ? ` · tặng ${dmy(new Date(cu))}` : ''}`;
        $('[data-sn-code]', snDlg).textContent = v.code;
        $('[data-sn-dk]', snDlg).innerHTML = [['Ưu đãi', moTaGiam(v)], ['Đơn tối thiểu', Number(v.min_order_amount) > 0 ? vnd(v.min_order_amount) : 'Không'], ['Dùng tới', `Hết ngày ${hanDung(v)}`]]
            .map(([k, x]) => `<div><dt>${k}</dt><dd>${esc(x)}</dd></div>`).join('');
        $('[data-sn-luu]', snDlg).textContent = `Dùng một lần, chỉ ${c.full_name} dùng được — khi bán nhớ chọn đúng khách rồi mới nhập mã.`;
        snDlg.showModal();
    }
    // Dòng đã xử lý: mờ đi, thay hai nút bằng kết quả (khỏi bấm lần nữa).
    function lamMo(id, q) {
        const box = $(`.tc-row[data-id="${id}"] [data-sn-box]`);
        if (!box) return;
        box.closest('.tc-row').classList.add('is-da-chuc');
        box.innerHTML = q.voucher
            ? `<span class="cr-sn__xong"><i class="fa-solid fa-check"></i> Đã tặng <b>${esc(q.voucher.code)}</b></span><button type="button" class="cr-sn__chuc" data-sn-xem="${id}">Xem mã</button>`
            : '<span class="cr-sn__xong"><i class="fa-solid fa-check"></i> Đã chúc</span>';
    }
    const quaDaXem = {};
    async function quaSN(id, nut, tangMa) {
        const c = rowOf(id);
        if (!c || nut.disabled) return;
        nut.disabled = true;
        try {
            const body = await goi(urlId(tangMa ? D.url.tangMaSN : D.url.daChucSN, id), { method: 'POST', body: '{}' });
            quaDaXem[id] = body.data;
            lamMo(id, body.data);
            if (tangMa && body.data?.voucher) moMaSN(c, body.data.voucher);
            else posToast(`Đã ghi: đã chúc ${c.full_name}.`);
        } catch (e) {
            if (e.ma === 'DA_CHUC' && e.data) {
                quaDaXem[id] = e.data;
                lamMo(id, e.data);
                if (e.data.voucher) moMaSN(c, e.data.voucher, e.data.created_at);
                else posToast(`${c.full_name} đã được chúc ngày ${dmy(new Date(e.data.created_at))}.`);
                return;
            }
            nut.disabled = false;
            posToast(e.message, 'error');
        }
    }
    function inPhieuSN() {
        if (!snDangXem) return;
        const { c, v } = snDangXem;
        const tiem = document.querySelector('.rail__brand img')?.alt || '';
        window.posInHtml(`<!doctype html><html lang="vi"><head><meta charset="utf-8"><title>Mã sinh nhật</title><style>
            @page{margin:0}*{box-sizing:border-box}body{margin:0;padding:4mm 3mm;width:72mm;font:13px/1.45 Arial,Helvetica,sans-serif;color:#000}
            h1{margin:0 0 1mm;font-size:15px;text-align:center}.c{text-align:center}p{margin:1mm 0}
            .ma{margin:3mm 0;padding:2.5mm 0;border:1.5px dashed #000;font:700 24px/1.2 'Courier New',monospace;letter-spacing:2px;text-align:center}
            .nho{font-size:11px}</style></head><body>
            ${tiem ? `<h1>${esc(tiem)}</h1>` : ''}<p class="c"><b>Chúc mừng sinh nhật ${esc(c.full_name)}!</b></p><p class="c">Tặng bạn mã giảm giá</p>
            <div class="ma">${esc(v.code)}</div><p>${esc(moTaGiam(v))}</p>${Number(v.min_order_amount) > 0 ? `<p>Đơn tối thiểu: ${esc(vnd(v.min_order_amount))}</p>` : ''}
            <p>Dùng tới hết ngày ${esc(hanDung(v))}</p><p class="nho">Mã dùng một lần, chỉ dành cho ${esc(c.full_name)}${c.phone ? ` (${esc(c.phone)})` : ''}.</p></body></html>`);
    }
    $('[data-sn-in]').addEventListener('click', inPhieuSN);
    $('[data-sn-copy]').addEventListener('click', () => {
        const ma = snDangXem?.v.code || '';
        (navigator.clipboard?.writeText(ma) || Promise.reject()).then(() => posToast(`Đã chép ${ma}`), () => posToast(ma));
    });

    /* ------------------------------------------------------------ sự kiện */
    document.addEventListener('click', (ev) => {
        const t = ev.target;
        // Nút trên dòng khách bắt TRƯỚC cú bấm chọn dòng.
        const sn = t.closest('[data-sn-tang], [data-sn-chuc], [data-sn-xem]');
        if (sn) {
            const id = +sn.closest('.tc-row').dataset.id;
            if (sn.dataset.snXem !== undefined) { const q = quaDaXem[id]; if (q?.voucher) moMaSN(rowOf(id), q.voucher, q.created_at); } else quaSN(id, sn, sn.hasAttribute('data-sn-tang'));
            return;
        }
        const row = t.closest('.tc-row');
        if (row) { chon(+row.dataset.id); return; }
        const tb = t.closest('[data-tab]');
        if (tb) { tab = tb.dataset.tab; daHien = sel; veChiTiet(); return; }
        if (t.closest('[data-create]')) { openCus(null); return; }
        const cq = t.closest('[data-create-q]');
        if (cq) { openCus(null, cq.dataset.createQ); return; }
        if (t.closest('[data-edit]')) { if (D.coTheSua) openCus(rowOf(sel)); return; }
        if (t.closest('[data-back]')) { detail.classList.remove('is-open'); return; }
        const nl = t.closest('[data-nloai] button');
        if (nl) { nf.loai = nl.dataset.v; nf.noi = $('[data-nnoi]').value; veOGhi(); $('[data-nloai]').closest('form').querySelector(nf.loai === 'hen' ? '[data-nngay]' : '[data-nnoi]').focus(); return; }
        const nx = t.closest('[data-nxoa]');
        if (nx) { if (!nx.disabled) xoaGhi(nx); return; }
        const th = t.closest('[data-thu]');
        if (th) { if (!th.disabled && th.dataset.thu) moThu(+th.dataset.thu); return; }
        const ty = t.closest('[data-c-type] button');
        if (ty) { cf.loai = +ty.dataset.v; renderCus(); return; }
        const gt = t.closest('[data-c-gender] button');
        if (gt) { cf.gt = cf.gt === gt.dataset.v ? '' : gt.dataset.v; renderCus(); return; }
        if (t.closest('[data-c-dup-open]') && cf.dupId) { dlg.close(); const u = new URL(location.pathname, location.href); u.searchParams.set('q', $('[data-c-phone]').value.replace(/\D/g, '')); u.searchParams.set('khach', cf.dupId); location.href = u.toString(); }
    });
    $('[data-rank]')?.addEventListener('change', (e) => e.target.form.requestSubmit());

    document.addEventListener('keydown', (e) => {
        if (dlg.open && e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); saveCus(); return; }
        if (e.key === 'F1') { e.preventDefault(); $('[data-q]').focus(); $('[data-q]').select(); return; }
        if (document.querySelector('dialog[open]')) return;
        if (e.key === 'F2') { e.preventDefault(); openCus(null); return; }
        if (e.key === 'Escape') { detail.classList.remove('is-open'); return; }
        if (/INPUT|SELECT|TEXTAREA/.test(document.activeElement?.tagName)) return;
        if (!['ArrowDown', 'ArrowUp'].includes(e.key)) return;
        const ids = $$('.tc-row').map((li) => +li.dataset.id);
        if (!ids.length) return;
        e.preventDefault();
        const i = ids.indexOf(sel);
        chon(ids[i < 0 ? 0 : e.key === 'ArrowDown' ? Math.min(ids.length - 1, i + 1) : Math.max(0, i - 1)], true);
    });

    veChiTiet();
    daHien = sel;
})();
</script>
@endpush
