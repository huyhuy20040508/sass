{{-- Hai ô của hộp Mở ca: người trực két + giờ mở (mở bù khi quên). Dùng ở màn Bán
     hàng (ô khoá khi chưa mở ca) và màn Điều phối ca. $p là tiền tố id, $truc từ
     WorkShiftController::nguoiTrucKet().

     Giờ mở tách "Hôm nay / Hôm qua" + ô HH:MM gõ tay chứ không dùng datetime-local:
     ô ấy hiện theo ngôn ngữ trình duyệt (máy để tiếng Anh ra 02:35 PM, tháng trước
     ngày) — thu ngân đọc nhầm đúng lúc đang lùi giờ. --}}
@php
    $toiTen = trim((string) data_get(session('api.user'), 'full_name', '')) ?: (string) data_get(session('api.user'), 'username', '');
@endphp
<div class="field">
    <label for="{{ $p }}Nguoi">Người trực két</label>
    <select class="input" id="{{ $p }}Nguoi" data-mo-nguoi>
        @if (! $truc['macDinh'])
            <option value="" selected>{{ $toiTen }} — tài khoản đang đăng nhập</option>
        @endif
        @foreach ($truc['ds'] as $nv)
            <option value="{{ $nv['id'] }}" @selected($nv['id'] === $truc['macDinh'])>{{ $nv['name'] }}{{ ($nv['code'] ?? '') !== '' ? ' · '.$nv['code'] : '' }}</option>
        @endforeach
    </select>
    <span class="hint">Người nhận két trong ca. Người bấm Mở ca vẫn được ghi riêng là người mở máy.</span>
</div>
<div class="field">
    <label for="{{ $p }}Gio">Giờ mở</label>
    <div class="ca-gio-mo">
        <select class="input" id="{{ $p }}Ngay" aria-label="Ngày mở" data-mo-ngay>
            <option value="0">Hôm nay</option>
            <option value="1">Hôm qua</option>
        </select>
        <input class="input num" id="{{ $p }}Gio" inputmode="numeric" maxlength="5" autocomplete="off" placeholder="HH:MM" data-mo-gio>
    </div>
    <span class="hint">Quên mở ca thì lùi giờ về lúc bắt đầu bán — tối đa 12 giờ, không quá hiện tại.</span>
</div>
@once
<style>
    .ca-gio-mo { display: grid; grid-template-columns: minmax(0, 1fr) 96px; gap: var(--s-2, 8px); }
</style>
<script>
    // Đặt / đọc hai ô trên. Không đụng giờ thì KHÔNG gửi opened_at — máy chủ lấy giờ
    // của nó, khỏi lệch theo đồng hồ máy quầy.
    window.gioMoCa = (() => {
        const pad = (n) => String(n).padStart(2, '0');
        const hhmm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
        return {
            dat(root) {
                const gio = root.querySelector('[data-mo-gio]');
                root.querySelector('[data-mo-ngay]').value = '0';
                gio.value = gio.dataset.goc = hhmm(new Date());
                gio.removeAttribute('aria-invalid');
            },
            doc(root) {
                const du = {};
                const nguoi = root.querySelector('[data-mo-nguoi]').value;
                if (nguoi) du.employee_id = Number(nguoi);
                const lui = Number(root.querySelector('[data-mo-ngay]').value || 0);
                const gio = root.querySelector('[data-mo-gio]');
                const v = gio.value.trim();
                if (!lui && v === gio.dataset.goc) return { du };
                const m = /^([01]?\d|2[0-3]):?([0-5]\d)$/.exec(v);
                const bao = (loi) => { gio.setAttribute('aria-invalid', 'true'); return { du, loi }; };
                if (!m) return bao('Giờ mở gõ dạng HH:MM, ví dụ 07:30.');
                const d = new Date();
                d.setDate(d.getDate() - lui);
                d.setHours(Number(m[1]), Number(m[2]), 0, 0);
                if (d.getTime() > Date.now() + 60000) return bao('Giờ mở không được sau thời điểm hiện tại.');
                if (d.getTime() < Date.now() - 12 * 3600000) return bao('Chỉ mở bù trong vòng 12 giờ qua.');
                gio.removeAttribute('aria-invalid');
                du.opened_at = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
                return { du };
            },
        };
    })();
    document.addEventListener('input', (e) => {
        const o = e.target.closest('[data-mo-gio]');
        if (!o) return;
        const so = o.value.replace(/\D/g, '').slice(0, 4);
        o.value = so.length > 2 ? `${so.slice(0, 2)}:${so.slice(2)}` : so;
    });
    document.querySelectorAll('[data-mo-gio]').forEach((o) => window.gioMoCa.dat(o.closest('form, dialog')));
</script>
@endonce
