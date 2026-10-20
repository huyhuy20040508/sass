{{--
    VỎ MODULE THU NGÂN — bản v3 "thanh dọc + biên lai".

    Khung: thanh dọc bên trái (biểu tượng · các màn của quầy · âm báo · thông báo · nút
    khu làm việc ở góc dưới) + vùng làm việc. Dưới 900px thanh dọc thành thanh trên cùng.
    Nút khu làm việc mở menu nhỏ: đổi module, đổi chi nhánh, tài khoản, đăng xuất —
    thay cho hộp ☰ của v2 cũ.

    Màn con đổ nội dung vào section 'content' (bên trong <main class="pos-main">) và
    'bar' (thanh đáy màn hẹp, nếu có). Stack: styles · modals · scripts.
    Hộp thoại là <dialog>; window.posToast(msg, kieu, hanhDong) báo nổi cho mọi màn.
--}}
@php
    $tnApi = app(\App\Services\ApiClient::class);
    $tnTenTiem = $tnApi->settingString('site_name', config('app.name'));

    $tnCn = \App\Services\CurrentBranch::danhSach();
    $tnCnDang = collect($tnCn['ds'])->firstWhere('id', $tnCn['dangChon']);
    $tnTenCn = (string) ($tnCnDang['name'] ?? $tnTenTiem);
    $tnUser = session('api.user');
    $tnTen = trim((string) data_get($tnUser, 'full_name', '')) ?: (string) data_get($tnUser, 'username', 'Tài khoản');
    $tnVietTat = mb_strtoupper(collect(preg_split('/\s+/u', $tnTen))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: 'TK';
    // Hồ sơ và đổi chi nhánh là đường của khu quản trị: người CHỈ có cửa quầy không gọi được.
    $tnCoQuanLy = in_array('quan_ly', \App\Http\Middleware\EnsureWorkspace::cuaCuaPhien(), true);

    // route null = màn chưa dựng: vẫn bày ra nhưng bấm chỉ báo, không dẫn tới trang lỗi.
    $tnMuc = [
        ['nhan' => 'Bán hàng', 'tieu_de' => 'Bán hàng', 'anh' => 'icons/pos/sell.png', 'route' => 'thu-ngan.ban-hang.index', 'dang' => 'thu-ngan.ban-hang.*'],
        ['nhan' => 'Đơn online', 'tieu_de' => 'Đơn online', 'anh' => 'icons/pos/online-order.png', 'route' => null],
        ['nhan' => 'Lịch sử', 'tieu_de' => 'Lịch sử đơn hàng', 'anh' => 'icons/pos/history.png', 'route' => 'thu-ngan.don-hang.index', 'dang' => 'thu-ngan.don-hang.*'],
        ['nhan' => 'Điều phối ca', 'tieu_de' => 'Điều phối ca', 'anh' => 'icons/pos/shift.png', 'route' => 'thu-ngan.ca-lam-viec.index', 'dang' => 'thu-ngan.ca-lam-viec.*'],
        ['nhan' => 'Thu chi', 'tieu_de' => 'Thu chi tiền mặt', 'anh' => 'icons/pos/cash-in-out.png', 'route' => null],
        ['nhan' => 'Trả hàng', 'tieu_de' => 'Trả hàng', 'anh' => 'icons/pos/return.png', 'route' => null],
        ['nhan' => 'CRM', 'tieu_de' => 'CRM', 'anh' => 'icons/pos/crm.png', 'route' => null],
    ];

    $tnModuleDs = \App\Services\SubscriptionExpiry::daKhoa() ? [] : \App\Services\WorkspaceModule::danhSach();
    $tnModuleDangO = \App\Services\WorkspaceModule::hienTai();
    $tnAnhModule = [\App\Services\WorkspaceModule::QUAN_TRI => 'admin', \App\Services\WorkspaceModule::THU_NGAN => 'cashier'];
    $tnDoiCnDuoc = $tnCoQuanLy && count($tnCn['ds']) > 1;
    $tnCss = fn (string $tep) => asset('v2/css/pos-v3/'.$tep).'?v='.filemtime(public_path('v2/css/pos-v3/'.$tep));
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Thu ngân') — {{ $tnTenTiem }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Roboto+Mono:wght@500;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="stylesheet" href="{{ $tnCss('tokens.css') }}">
    <link rel="stylesheet" href="{{ $tnCss('base.css') }}">
    <link rel="stylesheet" href="{{ $tnCss('v3.css') }}">
    @stack('styles')
</head>

<body class="v3 @yield('than')">
<div class="shell">
    <nav class="rail" aria-label="Thu ngân">
        <span class="rail__brand"><img src="{{ asset('favicon.svg') }}" alt="{{ $tnTenTiem }}" title="{{ $tnTenTiem }}"></span>

        <div class="pos-modules rail__modules">
            @foreach($tnMuc as $m)
                @php $dang = $m['route'] && request()->routeIs($m['dang']); @endphp
                <a class="pos-module {{ $dang ? 'is-active' : '' }}" title="{{ $m['tieu_de'] }}"
                   href="{{ $m['route'] ? route($m['route']) : '#' }}"
                   @if($dang) aria-current="page" @endif
                   @unless($m['route']) aria-disabled="true" data-chua-dung="{{ $m['tieu_de'] }}" @endunless>
                    @unless($m['route'])<span class="pos-module__soon">Sắp có</span>@endunless
                    <img src="{{ asset('v2/images/'.$m['anh']) }}" alt="">
                    <span class="pos-module__label">{{ $m['nhan'] }}</span>
                </a>
            @endforeach
        </div>

        <div class="rail__foot">
            <button type="button" class="rail__tool" id="btnMute" aria-label="Tắt âm báo" aria-pressed="false" title="Tắt / bật âm báo"><i class="fa-solid fa-volume-high"></i></button>
            <button type="button" class="rail__tool" id="tnChuong" aria-label="Thông báo" title="Thông báo"><i class="fa-solid fa-bell"></i></button>
            <button type="button" class="rail__switch" data-tn-ws aria-haspopup="dialog" title="Đổi module, chi nhánh, tài khoản">
                <span class="rail__me" aria-hidden="true">{{ $tnVietTat }}</span>
                <span class="rail__switch-label">Thu ngân <i class="fa-solid fa-chevron-up"></i></span>
                <span class="sr-only">{{ $tnTen }} — mở menu đổi module, chi nhánh, đăng xuất</span>
            </button>
        </div>
    </nav>

    <header class="topbar">
        <button type="button" class="topbar__ws" data-tn-ws aria-haspopup="dialog">
            <img src="{{ asset('favicon.svg') }}" alt="">
            <span><b>Thu ngân</b><small>{{ $tnTenCn }}</small></span>
            <i class="fa-solid fa-chevron-down"></i>
        </button>
        <button type="button" class="icon-btn" aria-label="Mở menu" data-tn-drawer><i class="fa-solid fa-bars"></i></button>
    </header>

    <main class="pos-main">
        @yield('content')
    </main>

    @yield('bar')
</div>

<div class="toasts" role="status" aria-live="polite" id="tnToasts"></div>

<dialog class="drawer" id="tnDrawer" aria-label="Menu thu ngân">
    <div class="modal__head" style="background:transparent">
        <h2>Menu</h2>
        <button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="drawer__body">
        <nav class="pos-modules" aria-label="Màn của quầy">
            @foreach($tnMuc as $m)
                <a class="pos-module {{ $m['route'] && request()->routeIs($m['dang']) ? 'is-active' : '' }}"
                   href="{{ $m['route'] ? route($m['route']) : '#' }}"
                   @unless($m['route']) aria-disabled="true" data-chua-dung="{{ $m['tieu_de'] }}" @endunless>
                    @unless($m['route'])<span class="pos-module__soon">Sắp có</span>@endunless
                    <img src="{{ asset('v2/images/'.$m['anh']) }}" alt="">
                    <span class="pos-module__label">{{ $m['nhan'] }}</span>
                </a>
            @endforeach
        </nav>
        <button type="button" class="branch-btn" data-tn-ws><i class="fa-solid fa-right-left"></i> Đổi module · chi nhánh · tài khoản</button>
    </div>
</dialog>

<dialog class="ws-pop" id="tnWs" aria-label="Khu làm việc">
    <div class="ws-pop__in">
        @if($tnModuleDs !== [])
            <div class="ws-pop__group" role="group" aria-labelledby="tnWsMod">
                <p class="ws-pop__label" id="tnWsMod">Module</p>
                @foreach($tnModuleDs as $md)
                    @php $anh = $tnAnhModule[$md['ma']] ?? 'cashier'; $dangO = $md['ma'] === $tnModuleDangO; @endphp
                    <a class="ws-item {{ $dangO ? 'is-current' : '' }}" href="{{ $dangO ? '#' : $md['href'] }}"
                       @if($dangO) aria-current="page" data-tn-close @else title="Hoá đơn đang mở được giữ trên máy này" @endif>
                        <img src="{{ asset('v2/images/'.$anh.'_normal.png') }}" alt="">
                        <span>{{ $md['ten'] }}</span>
                        <i class="fa-solid {{ $dangO ? 'fa-check' : 'fa-arrow-right' }}"></i>
                    </a>
                @endforeach
            </div>
        @endif

        @if(count($tnCn['ds']) > 0)
            <div class="ws-pop__group" role="group" aria-labelledby="tnWsCn"
                 @unless($tnDoiCnDuoc) title="Đổi chi nhánh là việc của khu quản trị" @endunless>
                <p class="ws-pop__label" id="tnWsCn">Chi nhánh</p>
                @foreach($tnCn['ds'] as $cn)
                    @php $chon = (int) $tnCn['dangChon'] === (int) $cn['id']; @endphp
                    <button type="button" class="ws-item {{ $chon ? 'is-current' : '' }}" data-tn-cn="{{ $cn['id'] }}"
                            @if($chon) aria-current="true" @endif
                            @if(! $chon && ! $tnDoiCnDuoc) disabled @endif>
                        <i class="fa-solid fa-store"></i><span>{{ $cn['name'] }}</span>
                        @if($chon)<i class="fa-solid fa-check"></i>@endif
                    </button>
                @endforeach
            </div>
        @endif

        @if($tnCoQuanLy)
            <div class="ws-pop__group">
                <a class="ws-item" href="{{ route('admin.profile.edit') }}"><i class="fa-solid fa-user-gear" style="width:24px;text-align:center;color:var(--c-muted)"></i><span>Tài khoản của tôi</span></a>
            </div>
        @endif

        <div class="ws-pop__me">
            <span class="ws-avatar" aria-hidden="true">{{ $tnVietTat }}</span>
            <span class="ws-me"><b>{{ $tnTen }}</b><small>{{ data_get($tnUser, 'username', '') }}</small></span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="icon-btn ws-logout" aria-label="Đăng xuất" title="Đăng xuất"><i class="fa-solid fa-right-from-bracket"></i></button>
            </form>
        </div>
    </div>
</dialog>

@if($tnDoiCnDuoc)
    <form method="POST" action="{{ route('admin.chi-nhanh.dangLam') }}" id="tnCnForm" hidden>
        @csrf
        <input type="hidden" name="id" id="tnCnId" value="">
    </form>
@endif

@stack('modals')

{{-- IN PHIẾU DÙNG CHUNG CỦA QUẦY. In bằng khung ẩn ngay trên màn đang đứng: không bật
     tab mới, không vướng chặn cửa sổ bật lên. Hộp "In lại" chọn khổ (theo mẫu / 80 /
     58 / A5 / A4) và số bản cho lần in đó, không đổi mẫu đã cài. --}}
<iframe id="tnInKhung" title="Khung in phiếu" aria-hidden="true" tabindex="-1"
        style="position:fixed;right:0;bottom:0;width:0;height:0;border:0"></iframe>
<dialog class="modal" id="tnInLai" aria-labelledby="tnInLaiT"
        data-url-phieu="{{ route('thu-ngan.ban-hang.phieu', ['id' => 0]) }}"
        data-url-a="{{ route('thu-ngan.ban-hang.hoaDonA', ['id' => 0]) }}"
        data-url-tra="{{ route('thu-ngan.tra-hang.phieu', ['id' => 0]) }}">
    <form method="dialog" id="tnInLaiForm">
        <div class="modal__head"><h2 id="tnInLaiT">In lại <span id="tnInLaiMa"></span></h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <div class="field">
                <span class="label">Khổ giấy</span>
                <div class="seg" id="tnInLaiKho" role="group" aria-label="Khổ giấy" style="flex-wrap:wrap">
                    <button type="button" data-v="" aria-pressed="true">Theo mẫu</button>
                    <button type="button" data-v="80" aria-pressed="false">80mm</button>
                    <button type="button" data-v="58" aria-pressed="false">58mm</button>
                    <button type="button" data-v="a5" aria-pressed="false" data-chi-don>A5</button>
                    <button type="button" data-v="a4" aria-pressed="false" data-chi-don>A4</button>
                </div>
                <span class="hint">Chỉ đổi cho lần in này — mẫu đã cài trong Cài đặt thiết bị giữ nguyên.</span>
            </div>
            <div class="field">
                <label for="tnInLaiBan">Số bản</label>
                <input class="input t-num" id="tnInLaiBan" type="number" min="1" max="9" step="1" value="1" style="max-width:140px">
            </div>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--outline" data-tn-close>Huỷ</button>
            <button type="submit" class="btn btn--primary" id="tnInLaiNut"><i class="fa-solid fa-print"></i> In <kbd>Enter</kbd></button>
        </div>
    </form>
</dialog>

<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Báo nổi dùng chung. hanhDong = { nhan, lam } thêm một nút (vd "Hoàn tác") và giữ lâu hơn.
    window.posToast = function (msg, kieu, hanhDong) {
        const loi = kieu === 'error' || kieu === 'warn';
        const t = document.createElement('div');
        t.className = 'toast' + (loi ? ' is-error' : '');
        t.innerHTML = `<i class="fa-solid ${loi ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i><span>${esc(msg)}</span>`;
        if (hanhDong) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'toast__act';
            b.textContent = hanhDong.nhan;
            b.addEventListener('click', () => { hanhDong.lam(); t.remove(); });
            t.append(b);
        }
        $('tnToasts').append(t);
        setTimeout(() => t.remove(), hanhDong ? 6000 : 3500);
    };

    const moHop = (d) => { document.querySelectorAll('dialog[open]').forEach((x) => { if (x !== d) x.close(); }); if (!d.open) d.showModal(); };
    document.querySelectorAll('[data-tn-ws]').forEach((b) => b.addEventListener('click', () => moHop($('tnWs'))));
    document.querySelectorAll('[data-tn-drawer]').forEach((b) => b.addEventListener('click', () => moHop($('tnDrawer'))));

    document.addEventListener('click', (e) => {
        // Menu khu làm việc và ngăn kéo là popover: bấm vào nền (chính thẻ dialog) là đóng.
        if (e.target.matches('#tnWs, #tnDrawer')) { e.target.close(); return; }
        const dong = e.target.closest('[data-tn-close]');
        if (dong) { e.preventDefault(); dong.closest('dialog').close(); return; }
        const chua = e.target.closest('[data-chua-dung]');
        if (chua) { e.preventDefault(); posToast(`Màn "${chua.dataset.chuaDung}" của quầy đang được xây dựng.`); return; }
        const cn = e.target.closest('[data-tn-cn]');
        if (cn && !cn.disabled && !cn.classList.contains('is-current') && $('tnCnForm')) {
            $('tnCnId').value = cn.dataset.tnCn;
            $('tnCnForm').submit();
        }
    });

    // ---- In phiếu bằng khung ẩn. Trả về true khi đã gọi lệnh in. ----
    // Trang phiếu không vẽ ra .ban / .to (hết phiên → trang đăng nhập, đơn đã bị xoá → 404)
    // thì KHÔNG in: in ra tờ "Trang không tồn tại" còn tệ hơn báo lỗi.
    let dangIn = null;
    function inKhung(nap) {
        const khung = $('tnInKhung');
        if (dangIn) dangIn(false);
        return new Promise((xong) => {
            dangIn = xong;
            const het = setTimeout(() => { if (dangIn === xong) { dangIn = null; posToast('Phiếu tải quá lâu — kiểm tra mạng rồi bấm In lại.', 'error'); xong(false); } }, 20000);
            khung.onload = () => {
                if (dangIn !== xong) return;
                clearTimeout(het);
                dangIn = null;
                let doc = null;
                try { doc = khung.contentDocument; } catch (err) { /* khác nguồn */ }
                if (!doc || !doc.querySelector('.ban, .to')) {
                    posToast('Không tải được phiếu để in — tải lại trang rồi thử lại.', 'error');
                    xong(false);
                    return;
                }
                // Lấy lại con trỏ sau khi in: để nó ở khung ẩn là phím tắt của quầy (F8, F9…)
                // chết cho tới khi thu ngân bấm chuột vào màn.
                const truoc = document.activeElement;
                khung.contentWindow.focus();
                khung.contentWindow.print();
                window.focus();
                if (truoc && truoc !== document.body && truoc.isConnected) truoc.focus({ preventScroll: true });
                xong(true);
            };
            nap(khung);
        });
    }
    window.posIn = (url) => inKhung((khung) => {
        const u = new URL(url, location.href);
        u.searchParams.set('nhung', '1');
        khung.removeAttribute('srcdoc');
        khung.src = u.pathname + u.search;
    });
    /** Phiếu dựng sẵn ở trình duyệt (tạm tính) — html phải có khối .ban. */
    window.posInHtml = (html) => inKhung((khung) => { khung.srcdoc = html; });

    // Lần in lại gần nhất nhớ khổ + số bản: quầy hay in lại cùng một kiểu.
    let inLai = null;
    const KHOA_IN_LAI = 'pos.in-lai';
    const chonKho = (v) => document.querySelectorAll('#tnInLaiKho button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === v)));
    $('tnInLaiKho').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b && !b.hidden) chonKho(b.dataset.v); });
    /** viec = { id, ma, loai: 'don' | 'tra' }. */
    window.posInLai = function (viec) {
        inLai = viec;
        const laDon = viec.loai !== 'tra';
        $('tnInLaiMa').textContent = viec.ma || '';
        document.querySelectorAll('#tnInLaiKho [data-chi-don]').forEach((b) => { b.hidden = !laDon; });
        let nho = {};
        try { nho = JSON.parse(localStorage.getItem(KHOA_IN_LAI) || '{}'); } catch (err) { /* máy chặn lưu */ }
        chonKho(laDon || !/^a/.test(nho.kho || '') ? (nho.kho || '') : '');
        // Số bản luôn về 1: nhớ số lần trước là lần sau F8 lại nhả nhiều tờ mà không ai để ý.
        $('tnInLaiBan').value = 1;
        // Chồng lên hộp đang mở (vd "Đã thanh toán") thay vì đóng nó: in xong vẫn quay về đúng chỗ.
        if (!$('tnInLai').open) $('tnInLai').showModal();
        $('tnInLaiNut').focus();
    };
    $('tnInLaiForm').addEventListener('submit', (e) => {
        e.preventDefault();
        if (!inLai) return;
        const d = $('tnInLai').dataset;
        const kho = document.querySelector('#tnInLaiKho [aria-pressed="true"]')?.dataset.v || '';
        const ban = Math.max(1, Math.min(9, parseInt($('tnInLaiBan').value, 10) || 1));
        try { localStorage.setItem(KHOA_IN_LAI, JSON.stringify({ kho })); } catch (err) { /* máy chặn lưu */ }
        const goc = inLai.loai === 'tra' ? d.urlTra : (/^a/.test(kho) ? d.urlA : d.urlPhieu);
        const u = new URL(goc.replace(/\/0\//, `/${inLai.id}/`), location.href);
        if (/^a/.test(kho)) u.searchParams.set('kho', kho);
        else if (kho) u.searchParams.set('paper', kho);
        if (ban > 1) u.searchParams.set('ban', String(ban));
        $('tnInLai').close();
        posIn(u.pathname + u.search);
    });

    $('tnChuong').addEventListener('click', () => posToast('Chưa có thông báo nào cho quầy.'));

    const veLoa = (tat) => {
        $('btnMute').setAttribute('aria-pressed', String(tat));
        $('btnMute').setAttribute('aria-label', tat ? 'Bật âm báo' : 'Tắt âm báo');
        $('btnMute').innerHTML = `<i class="fa-solid ${tat ? 'fa-volume-xmark' : 'fa-volume-high'}"></i>`;
    };
    let tatTieng = false;
    try { tatTieng = localStorage.getItem('pos.tat-tieng') === '1'; } catch (err) { /* máy chặn lưu */ }
    veLoa(tatTieng);
    $('btnMute').addEventListener('click', () => {
        tatTieng = !tatTieng;
        veLoa(tatTieng);
        try { localStorage.setItem('pos.tat-tieng', tatTieng ? '1' : '0'); } catch (err) { /* máy chặn lưu */ }
    });
})();
</script>

@stack('scripts')
</body>
</html>
