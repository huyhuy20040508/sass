{{--
    Component "khoảng ngày" DÙNG CHUNG cho mọi trang danh sách.

    QUY TẮC: trang nào cần lọc theo khoảng ngày thì @include partial này, KHÔNG tự
    dựng hai ô <input type="date"> hay copy lại bảng lịch — nếu không mỗi trang lại
    một kiểu, người dùng phải học lại cách lọc ở từng trang.

    Tham số:
      - formId    : id của <form> bộ lọc, bấm "Áp dụng"/preset sẽ submit form đó (bắt buộc)
      - from / to : giá trị đang lọc, dạng YYYY-MM-DD hoặc '' (bắt buộc)
      - fromName  : tên field gửi lên, mặc định 'from_date'
      - toName    : mặc định 'to_date'
      - title     : tooltip của nút mở lịch, mặc định 'Lọc theo khoảng ngày'

    Cách dùng:
      @include('partials.date-range', [
          'formId' => 'rcFilter',
          'from' => $filters['from_date'],
          'to' => $filters['to_date'],
          'title' => 'Lọc theo ngày nhận hàng',
      ])

    Hai ô ẩn gửi ĐÚNG khoảng người dùng đã chọn, rỗng khi chưa chọn gì — không đổ
    sẵn giá trị mặc định, vì chúng nằm trong form lọc: chỉ cần đổi ô Sắp xếp là
    khoảng ngày mặc định đó bị gửi lên theo và dữ liệu cũ biến mất khỏi bảng.
--}}
@php
    $drFromName = $fromName ?? 'from_date';
    $drToName = $toName ?? 'to_date';
    $drTitle = $title ?? 'Lọc theo khoảng ngày';
    $drFrom = (string) ($from ?? '');
    $drTo = (string) ($to ?? '');
@endphp

<div class="dr" data-form="{{ $formId }}">
    <input type="hidden" name="{{ $drFromName }}" value="{{ $drFrom }}" data-dr-from>
    <input type="hidden" name="{{ $drToName }}" value="{{ $drTo }}" data-dr-to>

    <button type="button" class="dr-trigger" data-dr-trigger aria-haspopup="dialog" aria-expanded="false"
        title="{{ $drTitle }}">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"
            stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="4" width="18" height="18" rx="2" />
            <path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
        <span class="dr-label" data-dr-label>—</span>
        <svg class="dr-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 9 6 6 6-6" />
        </svg>
    </button>

    <div class="dr-pop" data-dr-pop role="dialog" aria-label="Chọn khoảng ngày" hidden>
        <div class="dr-presets">
            <button type="button" class="dr-preset" data-preset="today">Hôm nay</button>
            <button type="button" class="dr-preset" data-preset="yesterday">Hôm qua</button>
            <button type="button" class="dr-preset" data-preset="last7">7 ngày qua</button>
            <button type="button" class="dr-preset" data-preset="last30">30 ngày qua</button>
            <button type="button" class="dr-preset" data-preset="thismonth">Tháng này</button>
        </div>
        <div class="dr-main">
            <div class="dr-months">
                <div class="dr-cal" data-cal="0"></div>
                <div class="dr-cal" data-cal="1"></div>
            </div>
            <div class="dr-foot">
                <span class="dr-range" data-dr-range>Chọn ngày bắt đầu</span>
                <div class="dr-foot-btns">
                    <button type="button" class="dr-btn-ghost" data-dr-clear>Xoá</button>
                    <button type="button" class="dr-btn-primary" data-dr-apply>Áp dụng</button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- CSS + JS chỉ nhúng một lần dù trang include nhiều lần. --}}
@once
    <style>
        /* Khoảng ngày: nút mở + bảng lịch 2 tháng (đồng bộ mọi trang danh sách) */
        .dr { position: relative; display: inline-flex; font-family: inherit; }
        .dr-trigger {
            height: 34px; display: inline-flex; align-items: center; gap: 8px; padding: 0 12px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; font-size: 13px; color: #262626;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .dr-trigger:hover, .dr.open .dr-trigger { border-color: #1890ff; color: #1890ff; }
        .dr-trigger > svg:first-child { color: #8c8c8c; flex-shrink: 0; }
        .dr-trigger:hover > svg:first-child, .dr.open .dr-trigger > svg:first-child { color: #1890ff; }
        .dr-label { white-space: nowrap; }
        .dr-caret { transition: transform .2s; }
        .dr.open .dr-caret { transform: rotate(180deg); }

        .dr-pop {
            position: absolute; top: calc(100% + 6px); left: 0; z-index: 1060; display: flex; background: #fff;
            border: 1px solid #e6e6e6; border-radius: 8px; box-shadow: 0 8px 30px rgba(0, 0, 0, .14);
            overflow: hidden; transform-origin: top left; animation: drIn .14s ease;
        }
        @keyframes drIn { from { opacity: 0; transform: translateY(-4px) scale(.98); } to { opacity: 1; transform: none; } }
        .dr-pop[hidden] { display: none; }
        .dr-presets {
            display: flex; flex-direction: column; gap: 2px; padding: 10px; border-right: 1px solid #f0f0f0;
            background: #fafafa; min-width: 132px;
        }
        .dr-preset {
            text-align: left; border: 0; background: none; border-radius: 4px; padding: 8px 10px; font-size: 13px;
            color: #595959; cursor: pointer; transition: background .15s, color .15s;
        }
        .dr-preset:hover { background: #e6f7ff; color: #1890ff; }
        .dr-main { display: flex; flex-direction: column; }
        .dr-months { display: flex; gap: 8px; padding: 12px; }
        .dr-cal { width: 244px; }
        .dr-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .dr-title { font-size: 13px; font-weight: 700; color: #262626; }
        .dr-nav {
            width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; color: #595959; cursor: pointer;
            transition: all .15s;
        }
        .dr-nav:hover { border-color: #1890ff; color: #1890ff; }
        .dr-nav[disabled] { visibility: hidden; }
        .dr-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
        .dr-dow { text-align: center; font-size: 11px; font-weight: 600; color: #bfbfbf; padding: 4px 0; }
        .dr-day {
            height: 32px; border: 0; background: none; border-radius: 4px; font-size: 12px; color: #262626;
            cursor: pointer; transition: background .12s, color .12s; position: relative;
        }
        .dr-day:hover:not(.is-empty) { background: #e6f7ff; }
        .dr-day.is-empty { cursor: default; }
        .dr-day.in-range { background: #e6f7ff; border-radius: 0; }
        .dr-day.is-start, .dr-day.is-end { background: #1890ff; color: #fff; }
        .dr-day.is-start { border-radius: 4px 0 0 4px; }
        .dr-day.is-end { border-radius: 0 4px 4px 0; }
        .dr-day.is-start.is-end { border-radius: 4px; }
        .dr-day.is-today::after {
            content: ''; position: absolute; left: 50%; bottom: 4px; transform: translateX(-50%);
            width: 4px; height: 4px; border-radius: 50%; background: #1890ff;
        }
        .dr-day.is-start.is-today::after, .dr-day.is-end.is-today::after { background: #fff; }
        .dr-foot {
            display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px;
            border-top: 1px solid #f0f0f0;
        }
        .dr-range { font-size: 13px; color: #595959; }
        .dr-foot-btns { display: flex; gap: 8px; }
        .dr-btn-ghost, .dr-btn-primary {
            height: 30px; padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; transition: background .15s, border-color .15s, color .15s;
        }
        .dr-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .dr-btn-ghost:hover { border-color: #1890ff; color: #1890ff; }
        .dr-btn-primary { background: #1890ff; color: #fff; }
        .dr-btn-primary:hover { background: #40a9ff; }

        @media (max-width: 720px) {
            .dr-pop { flex-direction: column; }
            .dr-presets { flex-direction: row; flex-wrap: wrap; border-right: 0; border-bottom: 1px solid #f0f0f0; min-width: 0; }
            .dr-months { flex-direction: column; }
        }
    </style>

    <script>
        // Bảng lịch chọn khoảng ngày (2 tháng) — chạy cho MỌI .dr có trên trang.
        document.querySelectorAll('.dr').forEach(function (wrap) {
            const form = document.getElementById(wrap.dataset.form);
            const trigger = wrap.querySelector('[data-dr-trigger]');
            const pop = wrap.querySelector('[data-dr-pop]');
            const label = wrap.querySelector('[data-dr-label]');
            const rangeText = wrap.querySelector('[data-dr-range]');
            const $from = wrap.querySelector('[data-dr-from]');
            const $to = wrap.querySelector('[data-dr-to]');
            if (!form || !trigger || !pop) return;

            const DOW = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
            const parse = (s) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
            const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            const disp = (d) => d ? `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}` : '';
            const sameDay = (a, b) => a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
            const startOfMonth = (d) => new Date(d.getFullYear(), d.getMonth(), 1);
            const addMonths = (d, n) => new Date(d.getFullYear(), d.getMonth() + n, 1);
            const today = (() => { const t = new Date(); return new Date(t.getFullYear(), t.getMonth(), t.getDate()); })();

            let start = parse($from.value);
            let end = parse($to.value);
            let hover = null;                        // ngày đang di chuột khi đã chọn bắt đầu
            let lastHover = '';                      // ô vừa hover (tránh tô lại thừa)
            let view = startOfMonth(start || today); // tháng hiển thị ở lịch bên trái

            const syncLabel = () => {
                label.textContent = (start || end) ? `${disp(start) || '…'} → ${disp(end) || '…'}` : 'Chọn ngày';
            };
            syncLabel();

            // Dựng khung lịch (chỉ chạy khi mở popup / đổi tháng) — chưa kèm trạng thái range.
            function buildCalendars() {
                [0, 1].forEach((i) => {
                    const base = addMonths(view, i);
                    const y = base.getFullYear(), mo = base.getMonth();
                    const first = new Date(y, mo, 1);
                    const offset = (first.getDay() + 6) % 7;
                    const days = new Date(y, mo + 1, 0).getDate();
                    let cells = '';
                    for (let k = 0; k < offset; k++) cells += '<button type="button" class="dr-day is-empty" disabled></button>';
                    for (let d = 1; d <= days; d++) {
                        const date = new Date(y, mo, d);
                        const todayCls = sameDay(date, today) ? ' is-today' : '';
                        cells += `<button type="button" class="dr-day${todayCls}" data-day="${ymd(date)}">${d}</button>`;
                    }
                    wrap.querySelector(`.dr-cal[data-cal="${i}"]`).innerHTML =
                        `<div class="dr-hd">
                            <button type="button" class="dr-nav" data-nav="-1" ${i === 1 ? 'disabled' : ''}>&lsaquo;</button>
                            <span class="dr-title">Tháng ${mo + 1}/${y}</span>
                            <button type="button" class="dr-nav" data-nav="1" ${i === 0 ? 'disabled' : ''}>&rsaquo;</button>
                        </div>` +
                        `<div class="dr-grid">${DOW.map((w) => `<span class="dr-dow">${w}</span>`).join('')}</div>` +
                        `<div class="dr-grid">${cells}</div>`;
                });
                paint();
            }

            // Tô lại trạng thái range trên các ô sẵn có — chạy khi hover/chọn (mượt, không dựng lại DOM).
            function paint() {
                const hi = end || hover;
                pop.querySelectorAll('.dr-day[data-day]').forEach((btn) => {
                    const d = parse(btn.dataset.day);
                    btn.classList.remove('in-range', 'is-start', 'is-end');
                    if (start && hi) {
                        const lo = start < hi ? start : hi;
                        const up = start < hi ? hi : start;
                        if (d > lo && d < up) btn.classList.add('in-range');
                    }
                    if (sameDay(d, start)) btn.classList.add('is-start');
                    if (sameDay(d, end) || (!end && hover && start && sameDay(d, hover))) btn.classList.add('is-end');
                });
                rangeText.textContent = start && end
                    ? `${disp(start)} → ${disp(end)}`
                    : (start ? 'Chọn ngày kết thúc' : 'Chọn ngày bắt đầu');
            }

            const openPop = () => {
                pop.hidden = false; wrap.classList.add('open'); trigger.setAttribute('aria-expanded', 'true');
                view = startOfMonth(start || today);
                buildCalendars();
            };
            const closePop = () => {
                pop.hidden = true; wrap.classList.remove('open'); trigger.setAttribute('aria-expanded', 'false');
                hover = null; lastHover = '';
            };

            trigger.addEventListener('click', (e) => { e.stopPropagation(); pop.hidden ? openPop() : closePop(); });
            // Chỉ đóng khi bấm ra NGOÀI vùng lịch, và chỉ khi đang mở.
            document.addEventListener('click', (e) => { if (!pop.hidden && !wrap.contains(e.target)) closePop(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !pop.hidden) closePop(); });

            // Điều hướng tháng + chọn ngày. Chặn nổi bọt để không kích hoạt đóng-ngoài.
            pop.addEventListener('click', (e) => {
                e.stopPropagation();

                const nav = e.target.closest('[data-nav]');
                if (nav) { view = addMonths(view, +nav.getAttribute('data-nav')); buildCalendars(); return; }

                const day = e.target.closest('[data-day]');
                if (day && !day.classList.contains('is-empty')) {
                    const d = parse(day.dataset.day);
                    if (!start || (start && end)) { start = d; end = null; }
                    else if (d < start) { start = d; }
                    else { end = d; }
                    hover = null; lastHover = '';
                    paint();
                }
            });

            // Xem trước khoảng khi mới chọn ngày bắt đầu — chỉ tô lại khi đổi ô.
            pop.addEventListener('mousemove', (e) => {
                if (!start || end) return;
                const day = e.target.closest('[data-day]');
                const key = day ? day.dataset.day : '';
                if (key === lastHover) return;
                lastHover = key;
                hover = day ? parse(day.dataset.day) : null;
                paint();
            });
            pop.addEventListener('mouseleave', () => {
                if (start && !end && hover) { hover = null; lastHover = ''; paint(); }
            });

            // Presets nhanh — chọn xong áp dụng luôn.
            pop.querySelectorAll('.dr-preset').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const p = btn.getAttribute('data-preset');
                    if (p === 'today') { start = new Date(today); end = new Date(today); }
                    else if (p === 'yesterday') { const y = new Date(today); y.setDate(y.getDate() - 1); start = new Date(y); end = new Date(y); }
                    else if (p === 'last7') { const s = new Date(today); s.setDate(s.getDate() - 6); start = s; end = new Date(today); }
                    else if (p === 'last30') { const s = new Date(today); s.setDate(s.getDate() - 29); start = s; end = new Date(today); }
                    else if (p === 'thismonth') { start = new Date(today.getFullYear(), today.getMonth(), 1); end = new Date(today); }
                    apply();
                });
            });

            function apply() {
                if (start && !end) end = new Date(start);
                $from.value = start ? ymd(start) : '';
                $to.value = end ? ymd(end) : '';
                syncLabel();
                form.submit();
            }

            wrap.querySelector('[data-dr-apply]').addEventListener('click', (e) => { e.stopPropagation(); apply(); });
            // Xoá lọc ngày rồi tải lại danh sách (hiện tất cả).
            wrap.querySelector('[data-dr-clear]').addEventListener('click', (e) => {
                e.stopPropagation();
                start = null; end = null; hover = null; lastHover = '';
                $from.value = ''; $to.value = '';
                form.submit();
            });
        });
    </script>
@endonce
