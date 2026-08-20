@extends('layouts.app')

@section('title', \App\Http\Controllers\ContactController::TITLE_NEWSLETTER)

@section('content')
    {{--
        Trang "Đăng ký nhận tin" — danh sách email khách để lại ở ô đăng ký dưới
        chân mọi trang của website bán hàng.

        Đây là DANH SÁCH GỬI THƯ, không phải việc cần xử lý, nên trang gọn hơn hẳn
        trang Yêu cầu của khách: không có trạng thái, không có modal chi tiết —
        chỉ tìm, lọc còn/đã gỡ, và gỡ ai không muốn nhận nữa.
    --}}
    @php
        $C = \App\Http\Controllers\ContactController::class;
        $TITLE = $C::TITLE_NEWSLETTER;
        $PAGE_SIZES = $C::PAGE_SIZES;
        $EMPTY_TEXT = $C::EMPTY_TEXT_NEWSLETTER;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== '' || $filters['status'] !== 'all';
    @endphp

    <div class="ct">
        <div class="ct-head">
            <h1 class="ct-title">{{ $TITLE }}</h1>
            <span class="ct-sum">
                Tổng: <b>{{ number_format((int) ($meta['total'] ?? 0), 0, ',', '.') }}</b> địa chỉ
                @if($filters['status'] === 'all')
                    <em>(gồm cả địa chỉ đã gỡ)</em>
                @endif
            </span>
        </div>

        {{-- Bộ lọc: đổi select là chạy ngay, gõ tìm kiếm thì chờ 400ms --}}
        <form method="GET" action="{{ route('admin.newsletter.index') }}" id="nlFilter" class="ct-filter">
            <div class="ct-toolbar">
                <div class="ct-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="ct-search-input"
                           placeholder="Tìm theo email" autocomplete="off">
                    <button type="submit" class="ct-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="ct-select" title="Lọc theo tình trạng nhận tin">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>Tất cả</option>
                    <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Đang nhận</option>
                    <option value="inactive" {{ $filters['status'] === 'inactive' ? 'selected' : '' }}>Đã gỡ</option>
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.newsletter.index') }}" class="ct-clear">Xoá lọc</a>
                @endif
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        <div class="ct-table-wrap">
            <table class="ct-table">
                <thead>
                    <tr>
                        <th class="ct-c-stt">STT</th>
                        <th class="nl-c-email">Email</th>
                        <th class="nl-c-src">Nơi đăng ký</th>
                        <th class="ct-c-status">Tình trạng</th>
                        <th class="ct-c-date">Ngày đăng ký</th>
                        <th class="ct-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $i => $r)
                        @php $dangNhan = (bool) ($r['is_active'] ?? false); @endphp
                        <tr>
                            <td class="ct-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="nl-c-email">
                                <span class="ct-name">{{ $r['email'] ?? '—' }}</span>
                                @if(! $dangNhan && ! empty($r['unsubscribed_at']))
                                    <span class="ct-sub">
                                        Đã gỡ ngày {{ \Illuminate\Support\Carbon::parse($r['unsubscribed_at'])->format('d/m/Y') }}
                                    </span>
                                @endif
                            </td>
                            <td class="nl-c-src">{{ $r['source'] ?? '—' }}</td>
                            <td class="ct-c-status">
                                <span class="ct-badge {{ $dangNhan ? 'tone-ok' : 'tone-warn' }}">
                                    {{ $dangNhan ? 'Đang nhận' : 'Đã gỡ' }}
                                </span>
                            </td>
                            <td class="ct-c-date">
                                {{ !empty($r['created_at']) ? \Illuminate\Support\Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td class="ct-c-act">
                                <div class="ct-actions">
                                    {{-- Đã gỡ rồi thì nút vẫn bấm được và nói rõ lý do, KHÔNG disable im lặng --}}
                                    <button type="button" class="ct-rowbtn ct-rowbtn--del"
                                            data-off="{{ $r['id'] }}" data-mail="{{ $r['email'] ?? '' }}"
                                            data-active="{{ $dangNhan ? 1 : 0 }}"
                                            title="{{ $dangNhan ? 'Gỡ khỏi danh sách nhận tin' : 'Địa chỉ này đã được gỡ trước đó' }}">
                                        Gỡ
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="ct-empty">
                                @if($hasFilter)
                                    Không tìm thấy địa chỉ nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'địa chỉ',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    {{-- Bộ .ct-* dùng chung với trang Yêu cầu của khách. Thiếu dòng include này
         là cả trang mất khung, mất bảng, mất thanh lọc — vì style của mỗi trang
         nằm ngay trong view của trang đó, không có tệp CSS chung. --}}
    @include('partials.styles-contacts')

    <style>
        /* Chỉ khai thêm hai cột riêng của bảng này. Cột Email là cột co giãn duy nhất. */
        .nl-c-email { width: 100%; text-align: left; }
        .nl-c-src { width: 1%; text-align: left; white-space: nowrap; color: #8c8c8c; }
    </style>

    <script>
        (function () {
            const CSRF = '{{ csrf_token() }}';
            const BASE = '{{ url('/admin/newsletter') }}';
            const $filter = document.getElementById('nlFilter');

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

            function postForm(action, method) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = action;
                f.style.display = 'none';
                const add = (name, val) => {
                    const i = document.createElement('input');
                    i.type = 'hidden'; i.name = name; i.value = val;
                    f.appendChild(i);
                };
                add('_token', CSRF);
                if (method && method !== 'POST') add('_method', method);
                document.body.appendChild(f);
                f.submit();
            }

            document.querySelector('.ct-table tbody').addEventListener('click', (e) => {
                const btn = e.target.closest('[data-off]');
                if (!btn) return;

                // Đã gỡ rồi thì nói rõ, không im lặng không phản ứng gì.
                if (btn.getAttribute('data-active') !== '1') {
                    sysConfirm({
                        title: 'Địa chỉ này đã được gỡ',
                        message: 'Địa chỉ này không còn nhận tin từ trước rồi, nên không có gì để gỡ thêm. '
                            + 'Khách muốn nhận lại thì chỉ cần đăng ký lại ở chân trang website.',
                        confirmText: 'Đã hiểu',
                        cancelText: '',
                    });
                    return;
                }

                sysDelete({
                    title: 'Gỡ khỏi danh sách nhận tin',
                    message: 'Địa chỉ này sẽ không nhận tin nữa. Dòng dữ liệu vẫn được giữ lại, '
                        + 'nên khách đăng ký lại lúc nào cũng được.',
                    highlightText: btn.getAttribute('data-mail'),
                    confirmText: 'Gỡ ngay',
                }).then((ok) => {
                    if (ok) postForm(`${BASE}/${btn.getAttribute('data-off')}/unsubscribe`, 'PUT');
                });
            });
        })();
    </script>
@endsection
