@extends('layouts.app')

@section('title', \App\Http\Controllers\ContactController::TITLE)

@section('content')
    {{--
        Trang "Yêu cầu của khách" — hộp thư đến của cửa hàng, cùng khuôn với
        Nhập hàng / Nhà cung cấp: [ header ] + [ thanh lọc realtime ] + [ bảng ]
        + [ chân trang ] + [ modal chi tiết ].

        Nguồn dữ liệu là hai form trên storefront: /lien-he và /thu-mua. Trước đây
        hai form đó chỉ hiện hộp thoại "cảm ơn" rồi vứt sạch, nên trang này chính
        là chỗ đầu tiên trong cửa hàng nhìn thấy được khách đã nhắn gì.
    --}}
    @php
        $C = \App\Http\Controllers\ContactController::class;
        $TITLE = $C::TITLE;
        $TYPES = $C::TYPES;
        $STATUSES = $C::STATUSES;
        $TONES = $C::STATUS_TONES;
        $NEXT = $C::NEXT_STATUS;
        $PAGE_SIZES = $C::PAGE_SIZES;
        $EMPTY_TEXT = $C::EMPTY_TEXT;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['type'] !== 'all'
            || $filters['status'] !== 'all'
            || $filters['from'] !== ''
            || $filters['to'] !== '';
    @endphp

    <div class="ct">
        {{-- Header --}}
        <div class="ct-head">
            <h1 class="ct-title">{{ $TITLE }}</h1>
            <span class="ct-sum">
                Chờ xử lý: <b class="ct-hot">{{ number_format($stats['moi'], 0, ',', '.') }}</b> ·
                Đang xử lý: <b>{{ number_format($stats['dang-xu-ly'], 0, ',', '.') }}</b> ·
                Đã xong: <b>{{ number_format($stats['da-xong'], 0, ',', '.') }}</b>
            </span>
        </div>

        {{-- Việc cần làm: còn yêu cầu chưa ai đụng tới. Bấm vào là lọc đúng nhóm đó. --}}
        @if($stats['moi'] > 0 && $filters['status'] !== 'moi')
            <a class="ct-live" href="{{ route('admin.contacts.index', ['status' => 'moi']) }}">
                <span class="ct-live-dot"></span>
                Có <b>{{ number_format($stats['moi'], 0, ',', '.') }}</b> yêu cầu khách gửi chưa được xử lý
                <span class="ct-live-cta">Xem ngay</span>
            </a>
        @endif

        {{-- Bộ lọc: đổi select là chạy ngay, gõ tìm kiếm thì chờ 400ms — không có nút "Áp dụng" --}}
        <form method="GET" action="{{ route('admin.contacts.index') }}" id="ctFilter" class="ct-filter">
            <div class="ct-toolbar">
                <div class="ct-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="ct-search-input"
                           placeholder="Tìm theo tên, số điện thoại, email, chủ đề hoặc nội dung" autocomplete="off">
                    <button type="submit" class="ct-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="type" class="ct-select" title="Lọc theo loại yêu cầu">
                    <option value="all">Tất cả loại</option>
                    @foreach($TYPES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['type'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="status" class="ct-select" title="Lọc theo trạng thái xử lý">
                    <option value="all">Mọi trạng thái</option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                {{-- Khoảng ngày gửi — dùng component chung, KHÔNG tự dựng ô ngày riêng. --}}
                @include('partials.date-range', [
                    'formId' => 'ctFilter',
                    'from' => $filters['from'],
                    'to' => $filters['to'],
                    'title' => 'Lọc theo ngày khách gửi',
                    'fromName' => 'from',
                    'toName' => 'to',
                ])

                @if($hasFilter)
                    <a href="{{ route('admin.contacts.index') }}" class="ct-clear">Xoá lọc</a>
                @endif

                <div class="ct-toolbar-actions">
                    <div class="ct-util" id="ctUtil">
                        <button type="button" class="ct-util-btn" id="ctUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="ct-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="ct-util-menu">
                            <a href="{{ route('admin.contacts.export', request()->query()) }}" class="ct-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng yêu cầu --}}
        <div class="ct-table-wrap">
            <table class="ct-table">
                <thead>
                    <tr>
                        <th class="ct-c-stt">STT</th>
                        <th class="ct-c-type">Loại</th>
                        <th class="ct-c-cus">Khách hàng</th>
                        <th class="ct-c-body">Nội dung</th>
                        <th class="ct-c-img">Ảnh</th>
                        <th class="ct-c-status">Trạng thái</th>
                        <th class="ct-c-date">Ngày gửi</th>
                        <th class="ct-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $i => $r)
                        @php
                            $status = $r['status'] ?? 'moi';
                            $next = $NEXT[$status] ?? null;
                            $anh = $r['images'] ?? [];
                        @endphp
                        <tr data-json="{{ json_encode($r, JSON_UNESCAPED_UNICODE) }}">
                            <td class="ct-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="ct-c-type">
                                <span class="ct-tag ct-tag--{{ $r['type'] ?? 'lien-he' }}">
                                    {{ $TYPES[$r['type'] ?? ''] ?? '—' }}
                                </span>
                            </td>
                            <td class="ct-c-cus" data-view title="Xem chi tiết yêu cầu">
                                <span class="ct-name">{{ $r['full_name'] ?? '—' }}</span>
                                <span class="ct-sub">
                                    {{ $r['phone'] !== '' ? $r['phone'] : ($r['email'] !== '' ? $r['email'] : '—') }}
                                </span>
                            </td>
                            <td class="ct-c-body" data-view title="Xem chi tiết yêu cầu">
                                @if(($r['subject'] ?? '') !== '')
                                    <span class="ct-name">{{ $r['subject'] }}</span>
                                @endif
                                <span class="ct-sub">{{ \Illuminate\Support\Str::limit($r['content'] ?? '', 90) }}</span>
                            </td>
                            <td class="ct-c-img">
                                {{-- In số 0 chứ không để trống: ô trống dễ bị đọc nhầm là "chưa tải xong" --}}
                                <span class="ct-imgcount {{ count($anh) > 0 ? 'has' : '' }}">{{ count($anh) }}</span>
                            </td>
                            <td class="ct-c-status">
                                <span class="ct-badge tone-{{ $TONES[$status] ?? 'info' }}">
                                    {{ $STATUSES[$status] ?? '—' }}
                                </span>
                            </td>
                            <td class="ct-c-date">
                                {{-- Carbon::parse giữ nguyên offset +07:00 API trả về (strtotime đổi về UTC làm lệch ngày) --}}
                                {{ !empty($r['created_at']) ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="ct-c-act">
                                <div class="ct-actions">
                                    {{-- Nút hiện đúng BƯỚC KẾ TIẾP, không bày cả ba trạng thái ra --}}
                                    @if($next)
                                        <button type="button" class="ct-rowbtn ct-rowbtn--go"
                                                data-next="{{ $next }}" data-id="{{ $r['id'] }}"
                                                title="Chuyển sang &quot;{{ $STATUSES[$next] }}&quot;">
                                            {{ $STATUSES[$next] }}
                                        </button>
                                    @else
                                        <button type="button" class="ct-rowbtn ct-rowbtn--back"
                                                data-next="dang-xu-ly" data-id="{{ $r['id'] }}"
                                                title="Mở lại: chuyển về &quot;Đang xử lý&quot;">
                                            Mở lại
                                        </button>
                                    @endif

                                    <button type="button" class="ct-rowbtn" data-view title="Xem chi tiết yêu cầu">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="3"/><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>
                                        </svg>
                                    </button>

                                    <button type="button" class="ct-rowbtn ct-rowbtn--del" data-del="{{ $r['id'] }}" title="Xoá yêu cầu">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="ct-empty">
                                @if($hasFilter)
                                    Không tìm thấy yêu cầu nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    {{ $EMPTY_TEXT }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'yêu cầu',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- ===== Modal chi tiết một yêu cầu ===== --}}
    <div class="ct-overlay" id="ctOverlay" style="display:none;">
        <div class="ct-dialog">
            <div class="ct-modal-head">
                <h4 class="ct-modal-title" id="ctModalTitle">Chi tiết yêu cầu</h4>
                <button type="button" class="ct-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" id="ctStatusForm">
                @csrf
                @method('PUT')
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                <input type="hidden" name="status" id="ctStatusValue">

                <div class="ct-modal-body">
                    <dl class="ct-info" id="ctInfo"></dl>

                    <div class="ct-block">
                        <span class="ct-block-label">Nội dung khách gửi</span>
                        <p class="ct-content" id="ctContent">—</p>
                    </div>

                    <div class="ct-block" id="ctImagesBlock" hidden>
                        <span class="ct-block-label">Ảnh khách gửi kèm</span>
                        <div class="ct-images" id="ctImages"></div>
                    </div>

                    <div class="ct-block">
                        <label class="ct-block-label" for="ctNote">Ghi chú nội bộ</label>
                        <textarea class="ct-textarea" name="admin_note" id="ctNote" rows="3" maxlength="500"
                                  placeholder="Ví dụ: đã gọi lúc 10h, khách hẹn mai gửi ảnh thêm."></textarea>
                    </div>
                </div>

                <div class="ct-modal-foot">
                    <button type="button" class="ct-btn-ghost" data-close>Đóng</button>
                    {{-- Ba nút trạng thái: nút của trạng thái ĐANG Ở bị làm mờ nhưng vẫn
                         bấm được — bấm vào chỉ lưu lại ghi chú, đúng thứ người dùng
                         mong đợi khi họ vừa gõ ghi chú xong. --}}
                    @foreach($STATUSES as $value => $label)
                        <button type="submit" class="ct-btn-status tone-{{ $TONES[$value] }}" data-set-status="{{ $value }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </form>
        </div>
    </div>

    @include('partials.styles-contacts')

    <script>
        (function () {
            const CSRF = '{{ csrf_token() }}';
            const STATUS_URL = '{{ url('/admin/contacts') }}';
            const $filter = document.getElementById('ctFilter');
            const $overlay = document.getElementById('ctOverlay');
            const $form = document.getElementById('ctStatusForm');
            const TYPES = @json($TYPES);
            const STATUSES = @json($STATUSES);

            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            // ---------- Bộ lọc: đổi select -> chạy ngay; gõ tìm kiếm -> chờ 400ms ----------
            $filter.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => $filter.submit());
            });
            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // ---------- Menu Tiện ích ----------
            const $util = document.getElementById('ctUtil');
            document.getElementById('ctUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                $util.classList.toggle('open');
                document.getElementById('ctUtilBtn')
                    .setAttribute('aria-expanded', $util.classList.contains('open') ? 'true' : 'false');
            });
            document.addEventListener('click', () => $util.classList.remove('open'));

            // ---------- Form POST động cho mọi thao tác ghi (kèm CSRF) ----------
            function postForm(action, method, fields) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = action;
                f.style.display = 'none';
                const add = (name, val) => {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = name;
                    i.value = val == null ? '' : val;
                    f.appendChild(i);
                };
                add('_token', CSRF);
                if (method && method !== 'POST') add('_method', method);
                for (const [k, v] of Object.entries(fields)) add(k, v);
                document.body.appendChild(f);
                f.submit();
            }

            // ---------- Modal chi tiết ----------
            function openDetail(row) {
                let r = {};
                try { r = JSON.parse(row.getAttribute('data-json')); } catch (e) { return; }

                document.getElementById('ctModalTitle').textContent =
                    (TYPES[r.type] || 'Yêu cầu') + ' — ' + (r.full_name || '');

                const dong = (nhan, giaTri) => giaTri
                    ? `<dt>${esc(nhan)}</dt><dd>${giaTri}</dd>`
                    : '';
                const tel = r.phone ? `<a href="tel:${esc(r.phone)}">${esc(r.phone)}</a>` : '';
                const mail = r.email ? `<a href="mailto:${esc(r.email)}">${esc(r.email)}</a>` : '';

                document.getElementById('ctInfo').innerHTML =
                    dong('Họ tên', esc(r.full_name))
                    + dong('Điện thoại', tel)
                    + dong('Email', mail)
                    + dong('Địa chỉ', esc(r.address))
                    + dong('Chủ đề', esc(r.subject))
                    + dong('Trạng thái', esc(STATUSES[r.status] || r.status))
                    + dong('Người xử lý', esc(r.handler_name));

                document.getElementById('ctContent').textContent = r.content || '—';
                document.getElementById('ctNote').value = r.admin_note || '';

                const anh = r.images || [];
                const $imgBlock = document.getElementById('ctImagesBlock');
                $imgBlock.hidden = anh.length === 0;
                document.getElementById('ctImages').innerHTML = anh.map((u) =>
                    `<a href="${esc(u)}" target="_blank" rel="noopener"><img src="${esc(u)}" alt="Ảnh khách gửi" loading="lazy"></a>`
                ).join('');

                // Nút của trạng thái đang ở thì làm mờ (vẫn bấm được để lưu ghi chú).
                $form.querySelectorAll('[data-set-status]').forEach((b) => {
                    b.classList.toggle('is-current', b.getAttribute('data-set-status') === r.status);
                });

                $form.action = `${STATUS_URL}/${r.id}/trang-thai`;
                $overlay.style.display = 'flex';
            }

            function closeDetail() { $overlay.style.display = 'none'; }

            $form.querySelectorAll('[data-set-status]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    document.getElementById('ctStatusValue').value = btn.getAttribute('data-set-status');
                });
            });

            $overlay.addEventListener('click', (e) => {
                if (e.target === $overlay || e.target.closest('[data-close]')) closeDetail();
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && $overlay.style.display === 'flex') closeDetail();
            });

            // ---------- Thao tác trên từng hàng ----------
            document.querySelector('.ct-table tbody').addEventListener('click', (e) => {
                const row = e.target.closest('tr[data-json]');
                if (!row) return;

                const del = e.target.closest('[data-del]');
                if (del) {
                    let r = {};
                    try { r = JSON.parse(row.getAttribute('data-json')); } catch (err) { return; }
                    sysDelete({
                        title: 'Xác nhận xoá yêu cầu',
                        message: 'Yêu cầu này sẽ không còn hiện trong danh sách. '
                            + 'Nếu chỉ muốn đánh dấu đã giải quyết thì chuyển sang "Đã xong" thay vì xoá.',
                        highlightText: `${r.full_name || ''} — ${r.subject || (r.content || '').slice(0, 50)}`,
                    }).then((ok) => {
                        if (ok) postForm(`${STATUS_URL}/${del.getAttribute('data-del')}`, 'DELETE', {});
                    });
                    return;
                }

                const go = e.target.closest('[data-next]');
                if (go) {
                    postForm(`${STATUS_URL}/${go.getAttribute('data-id')}/trang-thai`, 'PUT', {
                        status: go.getAttribute('data-next'),
                        admin_note: (JSON.parse(row.getAttribute('data-json')).admin_note || ''),
                    });
                    return;
                }

                if (e.target.closest('[data-view]')) openDetail(row);
            });
        })();
    </script>
@endsection
