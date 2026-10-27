{{--
    Trả hàng — màn "Trả hàng" của quầy (thiết kế design/html/tra-hang.html). Phiếu một bước:
    chọn đơn → chọn món → hoàn tiền (hoặc đổi hàng) → in phiếu trả.

    Trái: kỳ · thẻ tổng · tab Hoàn tiền / Đổi hàng · danh sách phiếu nhân viên lập ở chi nhánh.
    Phải: tờ phiếu, dựng bằng JS từ dữ liệu nhúng. Lọc / chuyển trang là GET (tải lại trang).
    ?don=<id đơn> mở sẵn hộp với đơn ấy (nút Trả hàng ở Lịch sử dẫn sang).

    Phím: F1 ô tìm · F2 lập phiếu trả · ↑/↓ chuyển phiếu · Ctrl+Enter lưu trong hộp.
--}}
@extends('v2::layouts.pos')

@php
    $C = \App\Http\Controllers\CounterReturnController::class;
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $error = $error ?? null;
    $giu = array_filter(['q' => $f['q'], 'ky' => $f['ky'], 'kind' => $f['kind'],
        'from_date' => $f['ky'] === 'tuy-chon' ? $f['from_date'] : '', 'to_date' => $f['ky'] === 'tuy-chon' ? $f['to_date'] : ''], fn ($v) => $v !== '');
    $link = fn (array $doi) => route('thu-ngan.tra-hang.index', array_filter(array_merge($giu, $doi), fn ($v) => $v !== '' && $v !== null));

    $thu = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
    $ngay = fn ($d) => \Illuminate\Support\Carbon::parse($d)->timezone(config('app.timezone'));
    $caMo = $ca ? $ngay($ca['opened_at']) : null;
    $phuDe = match (true) {
        $f['q'] !== '' => 'Kết quả tìm “'.$f['q'].'” ở mọi ngày',
        $f['ky'] === 'ca' => 'Ca #'.$ca['id'].' · mở '.$caMo->format('H:i').($caMo->isToday() ? ' hôm nay' : ' '.$caMo->format('d/m')),
        $f['ky'] === 'hom-nay' => $thu[now()->dayOfWeek].', '.now()->format('d/m/Y'),
        $f['ky'] === 'thang-nay' => 'Tháng '.now()->format('n/Y'),
        default => $ngay($f['from_date'])->format('d/m/Y').' – '.$ngay($f['to_date'])->format('d/m/Y'),
    };
    $khoa = ! $ca;
    $ketTheoSo = $ca ? (float) ($ca['opening_cash'] ?? 0) + (float) ($ca['tong_thu'] ?? 0) - (float) ($ca['tong_chi'] ?? 0) : null;

    $soPhieu = (int) ($meta['count'] ?? 0);
    $soDoi = (int) ($meta['swap_count'] ?? 0);
    $total = (int) ($meta['total'] ?? 0);
    $trang = (int) ($meta['page'] ?? 1);
    $soTrang = max(1, (int) ($meta['total_pages'] ?? 1));
    $chenh = fn ($r) => (float) ($r['exchange_order']['total_amount'] ?? 0) - (float) ($r['items_amount'] ?? 0);

    $nhomNgay = collect($rows)->groupBy(fn ($r) => $ngay($r['created_at'])->toDateString());
    $chon = $f['phieu'] && collect($rows)->contains('id', $f['phieu']) ? $f['phieu'] : (int) ($rows[0]['id'] ?? 0);
    $cn = \App\Services\CurrentBranch::danhSach();
    $tenCn = (string) (collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? '');
    $duLieu = [
        'rows' => $rows,
        'chon' => $chon,
        'ca' => $ca ? ['id' => (int) $ca['id']] : null,
        'ketTheoSo' => $ketTheoSo,
        'coTheTra' => $coTheTra,
        'moDon' => $f['don'] ?: null,
        'lyDo' => $C::LY_DO,
        'hinhThuc' => $C::HINH_THUC,
        'url' => [
            'don' => route('thu-ngan.tra-hang.don'),
            'mon' => route('thu-ngan.tra-hang.mon', ['id' => 0]),
            'store' => route('thu-ngan.tra-hang.store'),
            'doi' => route('thu-ngan.tra-hang.doi'),
            'sp' => route('admin.orders.searchProducts'),
            'phieu' => route('thu-ngan.tra-hang.phieu', ['id' => 0]),
            'lichSu' => route('thu-ngan.don-hang.index'),
        ],
    ];
@endphp

@section('title', $C::TITLE)
@section('than', 'tc-page')

@section('content')
    <section class="tc-list" aria-labelledby="thTitle">
        <div class="tc-top">
            <div class="tc-title">
                <h1 id="thTitle">Trả hàng</h1>
                <p>{{ $phuDe }}</p>
            </div>
            <form class="search" role="search" method="get" action="{{ route('thu-ngan.tra-hang.index') }}">
                <i class="fa-solid fa-magnifying-glass search__icon" aria-hidden="true"></i>
                <label class="sr-only" for="thQ">Tìm phiếu trả</label>
                <input id="thQ" name="q" type="search" autocomplete="off" placeholder="Mã phiếu, mã đơn, tên / SĐT khách" value="{{ $f['q'] }}" data-q>
                <kbd>F1</kbd>
            </form>
            @if($coTheTra)
                <div class="tc-new">
                    <button type="button" class="btn btn--ret" data-create title="{{ $khoa ? 'Chưa mở ca' : 'Lập phiếu trả (F2)' }}" @disabled($khoa)><i class="fa-solid fa-rotate-left"></i><span><span class="tc-new__w">Lập </span>phiếu trả</span><kbd>F2</kbd></button>
                </div>
            @endif
        </div>

        @if($khoa && $coTheTra)
            <div class="tc-alert tc-alert--lock">
                <i class="fa-solid fa-lock"></i>
                <span><b>Chi nhánh chưa mở ca</b>Hoàn tiền cho khách là tiền ra khỏi két — mở ca rồi mới lập phiếu trả. Phiếu cũ vẫn xem và in lại được.</span>
                <a class="btn btn--primary" href="{{ route('thu-ngan.ca-lam-viec.index') }}">Mở ca</a>
            </div>
        @elseif(! $coTheTra && ! $error)
            <div class="tc-alert tc-alert--info">
                <i class="fa-solid fa-eye"></i>
                <span><b>Chỉ xem</b>Tài khoản chưa được cấp quyền “Đổi / trả hàng tại quầy”. Nhờ quản lý cấp ở Quản trị → Nhân sự.</span>
            </div>
        @endif

        <div class="tc-bar">
            <div class="tc-seg" role="group" aria-label="Kỳ">
                @foreach($C::KY as $k => $nhan)
                    @if($k === 'ca' && ! $ca)
                        <button type="button" disabled title="Chưa mở ca">{{ $nhan }}</button>
                    @else
                        <a class="tc-seg__a" href="{{ $link(['ky' => $k, 'q' => '', 'page' => null, 'phieu' => null]) }}" aria-pressed="{{ $f['q'] === '' && $f['ky'] === $k ? 'true' : 'false' }}">{{ $nhan }}</a>
                    @endif
                @endforeach
            </div>
            @if($f['ky'] === 'tuy-chon' && $f['q'] === '')
                <form class="tc-range" method="get" action="{{ route('thu-ngan.tra-hang.index') }}" data-range>
                    <input type="hidden" name="ky" value="tuy-chon">
                    @if($f['kind'] !== '')<input type="hidden" name="kind" value="{{ $f['kind'] }}">@endif
                    <input type="date" name="from_date" aria-label="Từ ngày" value="{{ $f['from_date'] }}" max="{{ now()->toDateString() }}">
                    <span>–</span>
                    <input type="date" name="to_date" aria-label="Đến ngày" value="{{ $f['to_date'] }}" max="{{ now()->toDateString() }}">
                </form>
            @endif
            <span class="tc-scope"><i class="fa-solid fa-store"></i>Phiếu trả do nhân viên lập{{ $tenCn !== '' ? ' · '.$tenCn : '' }}</span>
        </div>

        @unless($error)
            <div class="tc-sum" aria-label="Tổng theo kỳ đang xem">
                <div class="tc-kpi"><span>Phiếu trả</span><b class="t-num">{{ $soPhieu }}</b><small>{{ (int) ($meta['item_quantity'] ?? 0) }} món · {{ (int) ($meta['restock_quantity'] ?? 0) }} món nhập lại kho</small></div>
                <div class="tc-kpi is-out"><span>Đã hoàn cho khách</span><b class="t-num">{{ $so($meta['refund_amount'] ?? 0) }}</b><small>{{ $soDoi ? ($soPhieu - $soDoi).' phiếu hoàn · '.$soDoi.' phiếu đổi hàng' : 'Tiền mặt và chuyển khoản' }}</small></div>
                <div class="tc-kpi is-cash"><span>Hoàn bằng tiền mặt</span><b class="t-num">{{ (float) ($meta['cash_refund_amount'] ?? 0) > 0 ? '−' : '' }}{{ $so($meta['cash_refund_amount'] ?? 0) }}</b>
                    @if($ca && $f['ky'] === 'ca' && $f['q'] === '')
                        <a class="link-btn" href="{{ route('thu-ngan.ca-lam-viec.index') }}">Két theo sổ {{ $so($ketTheoSo) }} đ <i class="fa-solid fa-arrow-right"></i></a>
                    @else
                        <small>Lấy từ két ca đưa khách</small>
                    @endif
                </div>
            </div>
        @endunless

        <div class="tc-card">
            <div class="tc-tabs" role="tablist" aria-label="Loại phiếu">
                @foreach(['' => $soPhieu, 'refund' => $soPhieu - $soDoi, 'swap' => $soDoi] as $k => $n)
                    <a class="tc-tab" role="tab" href="{{ $link(['kind' => $k, 'page' => null, 'phieu' => null]) }}" aria-selected="{{ $f['kind'] === (string) $k ? 'true' : 'false' }}">{{ $C::LOAI[$k] }}<span class="tc-tab__n">{{ $n }}</span></a>
                @endforeach
            </div>
            <div class="tc-scroll">
                @if($error)
                    <div class="tc-state"><span class="tc-state__ic"><i class="fa-solid fa-wifi"></i></span><h3>Không tải được phiếu trả hàng</h3><p>{{ $error }}</p><a class="btn btn--outline" href="{{ request()->fullUrl() }}">Thử lại</a></div>
                @elseif(! $rows)
                    <div class="tc-state">
                        @if($f['q'] !== '')
                            <span class="tc-state__ic"><i class="fa-solid fa-magnifying-glass"></i></span><h3>Không có phiếu khớp “{{ $f['q'] }}”</h3><p>Tìm theo mã phiếu, mã đơn gốc, tên hoặc SĐT khách, ở mọi ngày.</p>
                            <a class="btn btn--ghost" href="{{ $link(['q' => '']) }}">Xoá từ khoá</a>
                        @else
                            <span class="tc-state__ic"><i class="fa-solid fa-rotate-left"></i></span><h3>{{ $f['ky'] === 'ca' ? 'Ca này chưa có phiếu trả' : 'Không có phiếu trả trong kỳ này' }}</h3>
                            <p>Khách mang hàng tới trả hoặc đổi: bấm Lập phiếu trả, quét mã trên hoá đơn là chọn được đơn.</p>
                            @if($coTheTra && ! $khoa)
                                <button type="button" class="btn btn--ret" data-create><i class="fa-solid fa-rotate-left"></i> Lập phiếu trả</button>
                            @endif
                        @endif
                    </div>
                @else
                    <ul class="tc-rows" role="listbox" aria-label="Phiếu trả hàng">
                        @foreach($nhomNgay as $d => $ds)
                            @php $dd = $ngay($d); @endphp
                            <li class="tc-day" role="presentation">{{ $dd->isToday() ? 'Hôm nay' : ($dd->isYesterday() ? 'Hôm qua' : $thu[$dd->dayOfWeek]) }} · {{ $dd->format('d/m') }}<span class="t-num">{{ $ds->count() }} phiếu · hoàn {{ $so($ds->sum('refund_amount')) }}</span></li>
                            @foreach($ds as $r)
                                @php
                                    $doi = ! empty($r['exchange_order_id']);
                                    $c = $chenh($r);
                                    $soMon = collect($r['items'] ?? [])->sum('quantity');
                                @endphp
                                <li class="tc-row" role="option" tabindex="{{ (int) $r['id'] === $chon ? 0 : -1 }}" aria-selected="{{ (int) $r['id'] === $chon ? 'true' : 'false' }}" data-id="{{ $r['id'] }}">
                                    <span class="tc-dir {{ $doi ? 'is-swap' : 'is-ret' }}" aria-hidden="true"><i class="fa-solid {{ $doi ? 'fa-arrow-right-arrow-left' : 'fa-rotate-left' }}"></i></span>
                                    <div class="tc-r1"><span class="tc-code">{{ $r['return_code'] }}</span><span class="tc-time">{{ $ngay($r['created_at'])->format('H:i') }}</span>
                                        @unless($r['restock'] ?? true)<i class="fa-solid fa-box-archive tc-lock" title="Không nhập lại kho"></i>@endunless
                                    </div>
                                    <span class="tc-amt t-num is-out">{{ $doi ? ($c > 0 ? '+'.$so($c) : ($c < 0 ? '−'.$so(-$c) : '0')) : '−'.$so($r['refund_amount']) }}</span>
                                    <div class="tc-r2">{{ $r['order']['order_code'] ?? '' }} · {{ ($r['order']['recipient_name'] ?? '') ?: 'Khách lẻ' }} · {{ count($r['items'] ?? []) === 1 ? $r['items'][0]['product_name'] : $soMon.' món' }}</div>
                                    <span class="tc-pm {{ $doi ? 'is-swap' : (($r['refund_method'] ?? '') === 'cash' ? 'is-cash' : '') }}">{{ $doi ? 'Đổi hàng' : ($C::HINH_THUC[$r['refund_method']] ?? 'Khác') }}</span>
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="tc-foot">
                <span>{{ $total }} phiếu</span>
                @if($soTrang > 1)
                    <span>
                        @if($trang > 1)<a href="{{ $link(['page' => $trang - 1]) }}">‹ Trước</a>@endif
                        {{ $trang }}/{{ $soTrang }}
                        @if($trang < $soTrang)<a href="{{ $link(['page' => $trang + 1]) }}">Sau ›</a>@endif
                    </span>
                @elseif($rows)
                    <span>↑ ↓ để chuyển phiếu</span>
                @endif
            </div>
        </div>
    </section>

    <section class="tc-detail" aria-label="Chi tiết phiếu trả" data-detail>
        <div class="tc-paper" data-paper></div>
    </section>

<script type="application/json" id="thDuLieu">@json($duLieu)</script>
@endsection

@push('modals')
<dialog class="modal tc-dlg th-dlg" id="thHop" aria-labelledby="thHopT">
    <form method="dialog" data-form novalidate>
        <header class="tcm-head">
            <span class="tcm-badge" aria-hidden="true" data-f-badge><i class="fa-solid fa-rotate-left"></i></span>
            <div class="tcm-head__t">
                <h2 id="thHopT" data-f-title>Lập phiếu trả</h2>
                <div class="th-steps" data-steps>
                    <span class="th-step" data-step="1"><b>1</b>Chọn đơn</span><i class="fa-solid fa-chevron-right"></i>
                    <span class="th-step" data-step="2"><b>2</b>Món trả &amp; hoàn tiền</span>
                </div>
            </div>
            <button type="button" class="tcm-x" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button>
        </header>

        <div class="modal__body th-pick" data-pane="1">
            <div class="th-pick__bar">
                <label class="search" for="thOq">
                    <i class="fa-solid fa-barcode search__icon" aria-hidden="true"></i>
                    <span class="sr-only">Tìm đơn</span>
                    <input id="thOq" type="search" autocomplete="off" placeholder="Quét mã vạch trên hoá đơn, hoặc gõ mã đơn / SĐT / tên khách" data-oq>
                </label>
                <div class="tc-seg" role="group" aria-label="Đơn trong" data-operiod></div>
            </div>
            <p class="th-hint" data-ohint></p>
            <ul class="th-orders" data-orders></ul>
        </div>

        <div class="modal__body tcm-body" data-pane="2" hidden>
            <div class="tcm-col tcm-col--money">
                <div class="th-ordchip" data-ordchip></div>
                <div class="th-allbar">
                    <label><input type="checkbox" data-all> Trả toàn bộ</label>
                    <span class="th-hint" data-linecount></span>
                </div>
                <ul class="th-lines" data-lines></ul>
                <p class="th-note-gift" data-giftnote hidden></p>
                <div class="tcm-f">
                    <div class="tcm-lab"><label for="thNote">Ghi chú</label><small>Không bắt buộc</small></div>
                    <div class="tcm-note"><textarea class="input" id="thNote" maxlength="500" rows="2" placeholder="Vd. Ốp bị nứt góc trên, khách còn giữ hộp" data-note style="min-height:60px"></textarea></div>
                </div>
            </div>

            <div class="tcm-col">
                <div class="tcm-dir" role="group" aria-label="Cách xử lý" data-mode>
                    <button type="button" data-v="in" aria-pressed="true"><i class="fa-solid fa-money-bill-transfer"></i>Hoàn tiền</button>
                    <button type="button" data-v="out" aria-pressed="false"><i class="fa-solid fa-arrow-right-arrow-left"></i>Đổi hàng</button>
                </div>

                <div class="tcm-f">
                    <div class="tcm-lab"><span>Lý do</span><small class="is-req">Bắt buộc</small></div>
                    <div class="tcm-pills" data-reasons></div>
                    <span class="tc-err" data-reason-err hidden>Chọn lý do trả hàng.</span>
                </div>

                <div class="th-sw">
                    <span><b>Nhập lại kho</b><small data-restock-hint></small></span>
                    <label class="switch"><input type="checkbox" checked data-restock aria-label="Nhập lại kho"></label>
                </div>

                <div data-refund-only>
                    <div class="tcm-f">
                        <div class="tcm-lab"><span>Hoàn bằng</span><small data-pm-hint></small></div>
                        <div class="tcm-pm" role="group" aria-label="Hoàn bằng" data-pm style="display:grid;grid-template-columns:1fr 1fr">
                            <button type="button" data-v="cash" aria-pressed="true" style="justify-content:center"><i class="fa-solid fa-money-bill-wave"></i>Tiền mặt</button>
                            <button type="button" data-v="bank_transfer" aria-pressed="false" style="justify-content:center"><i class="fa-solid fa-building-columns"></i>Chuyển khoản</button>
                        </div>
                    </div>
                    <div class="th-ded" style="margin-top:14px">
                        <span class="tcm-lab" style="display:grid;gap:2px"><span>Khấu trừ</span><small>Hàng đã dùng, thiếu hộp / phụ kiện…</small></span>
                        <input class="input" inputmode="numeric" autocomplete="off" placeholder="0" data-ded>
                    </div>
                </div>

                <div class="tcm-f" data-swap-only hidden>
                    <div class="tcm-lab"><span>Món đổi sang</span><small>Giá bán hiện tại</small></div>
                    <div class="th-new-search">
                        <div class="tcm-ic"><i class="fa-solid fa-magnifying-glass"></i><input class="input" autocomplete="off" placeholder="Gõ tên hoặc mã hàng mới" data-nq></div>
                        <ul class="th-new-list" data-nlist hidden></ul>
                    </div>
                    <ul class="th-newlines" data-newlines></ul>
                </div>

                <div class="th-sum" data-sumbox></div>
                <div class="tc-warn" data-neg hidden><i class="fa-solid fa-triangle-exclamation"></i><span data-neg-text></span></div>
                <span class="tc-err" data-f-err hidden></span>
            </div>
        </div>

        <div class="modal__body" data-pane="3" hidden>
            <div class="th-done" data-done></div>
        </div>

        <footer class="tcm-foot" data-foot>
            <span class="tcm-keys" data-keys><kbd>Esc</kbd> đóng · <kbd>Ctrl</kbd> <kbd>Enter</kbd> lưu</span>
            <button type="button" class="btn btn--ghost" data-back-step hidden><i class="fa-solid fa-arrow-left"></i> Chọn đơn khác</button>
            <button type="button" class="btn btn--ghost" data-tn-close data-foot-close>Huỷ</button>
            <button type="submit" class="btn tcm-ok" data-ok hidden><span data-ok-t>Hoàn tiền</span><b data-ok-amt></b></button>
        </footer>
    </form>
</dialog>
@endpush

@push('styles')
<link rel="stylesheet" href="{{ asset('v2/css/pos-v3/cashbook.css') }}?v={{ filemtime(public_path('v2/css/pos-v3/cashbook.css')) }}">
<link rel="stylesheet" href="{{ asset('v2/css/pos-v3/returns.css') }}?v={{ filemtime(public_path('v2/css/pos-v3/returns.css')) }}">
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
    const hm = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    const dmy = (d) => `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
    const parseMoney = (s) => Number(String(s).replace(/\D/g, '')) || 0;
    const pickBtns = (box, v) => $$('button', box).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === v)));
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const D = JSON.parse($('#thDuLieu').textContent);
    const ROWS = D.rows || [];
    const CA = D.ca;
    const PM = { cash: 'Tiền mặt', bank_transfer: 'Chuyển khoản', card: 'Quẹt thẻ', payos: 'QR tự động', sepay: 'QR tự động', mpos_qr: 'QR tự động', mpos_card: 'Quẹt thẻ', none: 'Không hoàn' };
    const urlId = (mau, id) => mau.replace(/\/0(?=\/|$)/, `/${id}`);
    const rowOf = (id) => ROWS.find((r) => r.id === id);
    const tenLyDo = (v) => D.lyDo[v]?.ten || 'Khác';
    const ngay = (s) => new Date(s);
    const daysAgo = (d) => { const a = new Date(); a.setHours(0, 0, 0, 0); const b = new Date(d); b.setHours(0, 0, 0, 0); return Math.round((a - b) / 864e5); };

    async function goi(u, opts = {}) {
        const res = await fetch(u, { headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF }, ...opts });
        let body = {};
        try { body = await res.json(); } catch (e) { /* trang lỗi không phải JSON */ }
        if (!res.ok) {
            const loi = new Error(body.message || 'Không kết nối được máy chủ. Vui lòng thử lại.');
            loi.ma = body.errors?.ma;
            throw loi;
        }
        return body;
    }
    function taiLai(id, msg) {
        try { if (msg) sessionStorage.setItem('th.toast', msg); } catch (e) { /* máy chặn lưu */ }
        const u = new URL(location.href);
        u.searchParams.delete('don');
        if (id) u.searchParams.set('phieu', id); else u.searchParams.delete('phieu');
        location.href = u.toString();
    }
    try { const m = sessionStorage.getItem('th.toast'); if (m) { sessionStorage.removeItem('th.toast'); posToast(m); } } catch (e) { /* bỏ qua */ }

    /* ------------------------------------------------------------ tờ phiếu */
    const paper = $('[data-paper]');
    const detail = $('[data-detail]');
    let sel = D.chon || null;
    let daHien = null;

    function veChiTiet() {
        const r = rowOf(sel);
        detail.classList.toggle('is-open', !!r && innerWidth < 900 && daHien !== null);
        if (!r) {
            paper.innerHTML = '<div class="tc-blank"><div><span class="tc-state__ic"><i class="fa-solid fa-rotate-left"></i></span>Chọn một phiếu để xem chi tiết</div></div>';
            return;
        }
        const doi = !!r.exchange_order_id;
        const moi = r.exchange_order || null;
        const c = doi ? Math.round((moi?.total_amount || 0) - r.items_amount) : 0;
        const d = ngay(r.created_at);
        const o = r.order || {};
        const items = (ds, laMoi) => (ds || []).map((i) => `<li class="th-it${laMoi ? ' is-new' : ''}"><span class="th-it__img"><i class="fa-solid ${laMoi ? 'fa-bag-shopping' : 'fa-box'}"></i></span><span class="th-it__name">${esc(i.product_name)}</span><span class="th-it__amt t-num">${laMoi ? '' : '−'}${num(i.total_price)}</span><span class="th-it__sub">${i.variant_name ? `${esc(i.variant_name)} · ` : ''}${num(i.unit_price)} × ${i.quantity}</span></li>`).join('');
        paper.classList.toggle('is-swap', daHien !== r.id);
        paper.innerHTML = `
            <div class="tc-anim" style="display:contents">
            <div class="tc-d-head">
                <div class="tc-d-top">
                    <button type="button" class="tc-d-mini tc-back" aria-label="Quay lại danh sách" data-back><i class="fa-solid fa-arrow-left"></i></button>
                    <h2>${esc(r.return_code)}</h2>
                    <button type="button" class="tc-d-mini" aria-label="Chép mã phiếu" title="Chép mã" data-copy><i class="fa-regular fa-copy"></i></button>
                    <span class="tc-chip ${doi ? 'is-swap' : 'is-ret'}"><i class="fa-solid ${doi ? 'fa-arrow-right-arrow-left' : 'fa-rotate-left'}"></i>${doi ? 'Đổi hàng' : (r.status === 'refunded' ? 'Đã hoàn tiền' : 'Đã nhận hàng')}</span>
                </div>
                <div class="tc-d-meta"><span><i class="fa-regular fa-clock"></i>${hm(d)} · ${dmy(d)}</span></div>
                <div class="tc-hero ${doi ? 'is-swap' : 'is-ret'}">
                    <div><div class="tc-hero__label">${doi ? (c > 0 ? 'Khách trả thêm' : c < 0 ? 'Trả lại khách' : 'Đổi ngang giá') : 'Hoàn cho khách'}</div><div class="tc-hero__amt t-num">${vnd(doi ? Math.abs(c) : r.refund_amount)}</div></div>
                    <div class="tc-hero__pm">${doi ? `Đơn mới ${esc(moi?.order_code || '')}` : r.refund_method === 'cash' ? 'Chi từ két ca' : 'Không qua két'}<b>${esc(PM[doi ? moi?.payment_method : r.refund_method] || '')}</b></div>
                </div>
                <a class="th-src" href="${esc(D.url.lichSu)}?q=${encodeURIComponent(o.order_code || '')}"><i class="fa-solid fa-receipt"></i><span><b>Đơn gốc ${esc(o.order_code || '')}</b><small>${esc(o.recipient_name || 'Khách lẻ')}${o.recipient_phone ? ` · ${esc(o.recipient_phone)}` : ''}</small></span><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            </div>
            <div class="tc-d-body">
                <p class="th-sec">Món trả</p>
                <ul class="th-items">${items(r.items)}</ul>
                ${doi ? `<p class="th-sec">Món đổi sang</p><ul class="th-items">${items(moi?.items, true)}</ul>` : ''}
                <dl class="th-tot">
                    <div><dt>Tiền hàng trả</dt><dd>${num(r.items_amount)}</dd></div>
                    ${doi ? `<div><dt>Hàng mới</dt><dd>${num(moi?.total_amount || 0)}</dd></div>` : ''}
                    ${r.deduction ? `<div><dt>Khấu trừ</dt><dd>−${num(r.deduction)}</dd></div>` : ''}
                    <div class="is-grand"><dt>${doi ? (c >= 0 ? 'Khách trả thêm' : 'Trả lại khách') : 'Hoàn lại'}</dt><dd>${vnd(doi ? Math.abs(c) : r.refund_amount)}</dd></div>
                </dl>
                <dl class="tc-kv" style="margin-top:8px">
                    <div><dt>Lý do</dt><dd>${esc(tenLyDo(r.reason))}${r.reason_note ? `<small>${esc(r.reason_note)}</small>` : ''}</dd></div>
                    <div><dt>Nhập lại kho</dt><dd>${r.restock ? 'Có — đã cộng lại tồn' : 'Không — hàng lỗi, để riêng'}</dd></div>
                    ${!doi && r.refund_amount > 0 ? '<div><dt>Phiếu chi</dt><dd>Tự sinh<small>Xem ở Thu chi (Quản trị)</small></dd></div>' : ''}
                </dl>
            </div>
            <div class="tc-d-foot">
                <button type="button" class="btn btn--primary" data-print><i class="fa-solid fa-print"></i> In phiếu trả</button>
                <a class="btn btn--soft" href="${esc(D.url.lichSu)}?q=${encodeURIComponent(o.order_code || '')}" style="flex:0 0 auto"><i class="fa-solid fa-receipt"></i> Đơn gốc</a>
            </div>
            </div>`;
        daHien = r.id;
    }

    function chon(id, focus) {
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
        u.searchParams.set('phieu', id);
        history.replaceState(null, '', u);
    }

    /* ------------------------------------------------------------ hộp Lập phiếu trả */
    const dlg = $('#thHop');
    const f = { step: 1, oq: '', operiod: 7, orders: [], order: null, items: [], qty: {}, mode: 'refund', reason: '', kho: true, khoTay: false, pm: 'cash', ded: 0, moi: [], confirmNeg: false, err: '', dangGui: false, ket: Number(D.ketTheoSo) || 0, ketqua: null };
    const monChinh = () => f.items.filter((i) => !i.is_gift);
    const tienDong = (i, q) => Math.round(i.paid_total * q / i.quantity);
    const tongTra = () => monChinh().reduce((t, i) => t + tienDong(i, f.qty[i.order_item_id] || 0), 0);
    const tongMoi = () => f.moi.reduce((t, m) => t + m.gia * m.sl, 0);
    const soMon = () => Object.values(f.qty).reduce((t, n) => t + n, 0);

    async function moHop(donId) {
        if (!D.coTheTra) return;
        if (!CA) { posToast('Chi nhánh chưa mở ca — mở ca rồi mới lập phiếu trả.', 'error'); return; }
        Object.assign(f, { step: 1, oq: '', operiod: 7, order: null, items: [], qty: {}, mode: 'refund', reason: '', kho: true, khoTay: false, pm: 'cash', ded: 0, moi: [], confirmNeg: false, err: '', dangGui: false, ketqua: null });
        $('[data-oq]').value = '';
        $('[data-note]').value = '';
        $('[data-ded]').value = '';
        $('[data-nq]').value = '';
        $('[data-neg]').hidden = true;
        render();
        if (!dlg.open) dlg.showModal();
        if (donId) { await chonDon(donId); } else { $('[data-oq]').focus(); taiDon(); }
    }

    let luotTai = 0;
    async function taiDon() {
        const luot = ++luotTai;
        $('[data-orders]').innerHTML = '<li class="th-empty" style="margin:12px">Đang tải đơn…</li>';
        try {
            const u = new URL(D.url.don, location.href);
            if (f.oq.trim()) u.searchParams.set('q', f.oq.trim()); else u.searchParams.set('days', String(f.operiod || 1));
            const body = await goi(u);
            if (luot !== luotTai) return;
            f.orders = body.data || [];
        } catch (err) {
            if (luot !== luotTai) return;
            f.orders = [];
            $('[data-orders]').innerHTML = `<li class="th-empty" style="margin:12px">${esc(err.message)}</li>`;
            return;
        }
        renderPick();
    }

    async function chonDon(id) {
        try {
            const body = await goi(urlId(D.url.mon, id));
            const d = body.data || {};
            if (!d.returnable) {
                posToast(d.reason || 'Đơn này không trả được ở quầy.', 'error');
                f.step = 1; render(); taiDon();
                return;
            }
            f.order = d.order;
            f.items = d.items || [];
            f.qty = {};
            monChinh().forEach((i) => { if (i.remain_quantity > 0) f.qty[i.order_item_id] = i.remain_quantity; });
            f.pm = d.order.payment_method === 'cash' ? 'cash' : 'bank_transfer';
            f.step = 2;
            render();
        } catch (err) {
            posToast(err.message, 'error');
            f.step = 1; render(); taiDon();
        }
    }

    function renderPick() {
        $('[data-operiod]').innerHTML = [[0, 'Hôm nay'], [7, '7 ngày'], [30, '30 ngày']].map(([v, t]) => `<button type="button" aria-pressed="${f.operiod === v}" data-op="${v}">${t}</button>`).join('');
        const q = f.oq.trim();
        $('[data-ohint]').textContent = q ? `${f.orders.length} đơn khớp “${q}” (mọi ngày)` : 'Đơn của chi nhánh này · trả được trong 7 ngày kể từ lúc mua';
        $('[data-orders]').innerHTML = f.orders.length ? f.orders.map((o) => {
            const d = ngay(o.created_at);
            const n = daysAgo(d);
            const why = o.blocked_reason;
            return `<li class="th-ord${why ? ' is-locked' : ''}" data-ord="${o.id}">
                <div class="th-ord__main"><div class="th-ord__r1"><b>${esc(o.order_code)}</b><span>${n === 0 ? 'Hôm nay' : n === 1 ? 'Hôm qua' : dmy(d)} ${hm(d)}</span>${o.returned_quantity > 0 && o.returned_quantity < o.quantity ? '<span class="th-tag th-tag--ret">Đã trả một phần</span>' : ''}</div>
                <div class="th-ord__r2">${o.recipient_name ? `${esc(o.recipient_name)}${o.recipient_phone ? ` · ${esc(o.recipient_phone)}` : ''}` : 'Khách lẻ'} · ${o.item_count} món · ${esc(PM[o.payment_method] || o.payment_method || '')}</div></div>
                <span class="th-ord__amt">${num(o.total_amount)}</span>
                ${why ? '<span class="th-tag" style="background:#f0f2f5;color:#545b6b">Không trả được</span>' : '<button type="button" class="btn btn--outline">Chọn</button>'}
                ${why ? `<span class="th-ord__why"><i class="fa-solid fa-circle-info"></i>${esc(why)}</span>` : ''}
            </li>`;
        }).join('') : `<li class="th-empty" style="margin:12px"><i class="fa-solid fa-receipt" style="font-size:22px"></i>${q ? 'Không tìm thấy đơn. Kiểm lại mã đơn hoặc số điện thoại — đơn của chi nhánh khác không trả ở đây.' : 'Không có đơn nào trong khoảng này.'}</li>`;
    }

    function renderLines() {
        const o = f.order;
        const d = ngay(o.created_at);
        $('[data-ordchip]').innerHTML = `<i class="fa-solid fa-receipt"></i><span><b>${esc(o.order_code)}</b> · ${dmy(d)} ${hm(d)}<small>${o.recipient_name ? `${esc(o.recipient_name)}${o.recipient_phone ? ` · ${esc(o.recipient_phone)}` : ''}` : 'Khách lẻ'} · đã trả bằng ${esc(PM[o.payment_method] || o.payment_method)}</small></span><button type="button" data-repick>Đổi đơn</button>`;
        $('[data-lines]').innerHTML = monChinh().map((i) => {
            const max = i.remain_quantity;
            const q = f.qty[i.order_item_id] || 0;
            const don = i.paid_total / i.quantity;
            const giam = Math.round(don) < Math.round(i.unit_price);
            if (max <= 0) return `<li class="th-line is-off"><input type="checkbox" disabled aria-label="Đã trả hết"><span class="th-line__name">${esc(i.product_name)}</span><span class="th-line__right"><span class="th-tag th-tag--ret">Đã trả hết</span></span><span class="th-line__sub">${i.variant_name ? `${esc(i.variant_name)} · ` : ''}đã trả ${i.returned_quantity}/${i.quantity}</span></li>`;
            return `<li class="th-line${q ? ' is-on' : ''}">
                <input type="checkbox" ${q ? 'checked' : ''} data-chk="${i.order_item_id}" aria-label="Trả ${esc(i.product_name)}">
                <span class="th-line__name">${esc(i.product_name)}</span>
                <span class="th-line__right"><span class="th-line__amt">${num(tienDong(i, q))}</span>
                    <span class="th-qty"><button type="button" data-dec="${i.order_item_id}" ${q <= 0 ? 'disabled' : ''} aria-label="Bớt">−</button><span>${q} / ${max}</span><button type="button" data-inc="${i.order_item_id}" ${q >= max ? 'disabled' : ''} aria-label="Thêm">+</button></span></span>
                <span class="th-line__sub">${i.variant_name ? `${esc(i.variant_name)} · ` : ''}${giam ? `<s>${num(i.unit_price)}</s> ` : ''}${num(don)} / cái${giam ? ' <span class="th-tag th-tag--gift" title="Đã trừ phần giảm giá của dòng và của đơn">đã trừ giảm giá</span>' : ''}${i.returned_quantity ? `<span class="th-tag th-tag--ret">Đã trả ${i.returned_quantity}</span>` : ''}</span>
            </li>`;
        }).join('');
        const qua = f.items.filter((i) => i.is_gift);
        $('[data-giftnote]').hidden = !qua.length;
        $('[data-giftnote]').innerHTML = qua.length ? `<i class="fa-solid fa-gift"></i> Quà tặng kèm (${qua.map((i) => esc(i.product_name)).join(', ')}) không trả lẻ — trả hết món chính thì nhắc khách gửi lại quà.` : '';
        const con = monChinh().filter((i) => i.remain_quantity > 0);
        const all = con.length > 0 && con.every((i) => (f.qty[i.order_item_id] || 0) === i.remain_quantity);
        $('[data-all]').checked = all;
        $('[data-all]').indeterminate = !all && soMon() > 0;
        $('[data-linecount]').textContent = `${soMon()} món được chọn`;
    }

    function ketThieu() {
        if (f.pm !== 'cash') return -1;
        if (f.mode === 'refund') return Math.max(0, tongTra() - f.ded) - f.ket;
        const c = tongMoi() - tongTra();
        return c < 0 ? -c - f.ket : -1;
    }

    function renderRight() {
        pickBtns($('[data-mode]'), f.mode === 'refund' ? 'in' : 'out');
        dlg.classList.toggle('is-swap', f.mode === 'swap');
        dlg.classList.toggle('is-out', f.mode === 'swap');
        $('[data-reasons]').innerHTML = Object.entries(D.lyDo).map(([v, r]) => `<button type="button" aria-pressed="${f.reason === v}" data-reason="${v}">${esc(r.ten)}</button>`).join('');
        $('[data-restock]').checked = f.kho;
        $('[data-restock-hint]').textContent = f.kho ? 'Hàng còn bán được — cộng lại tồn của chi nhánh' : 'Hàng lỗi — không cộng tồn, để riêng chờ xử lý';
        $('[data-refund-only]').hidden = f.mode !== 'refund';
        $('[data-swap-only]').hidden = f.mode !== 'swap';
        pickBtns($('[data-pm]'), f.pm);
        $('[data-pm-hint]').textContent = `Đơn gốc trả bằng ${PM[f.order.payment_method] || f.order.payment_method}`;
        $('[data-newlines]').innerHTML = f.moi.length ? f.moi.map((m, k) => `<li class="th-newline"><span>${esc(m.ten)}${m.loai ? ` <small style="color:var(--tc-faint)">${esc(m.loai)}</small>` : ''}</span><span class="th-qty"><button type="button" data-ndec="${k}" aria-label="Bớt">−</button><span>${m.sl}</span><button type="button" data-ninc="${k}" ${m.sl >= m.ton ? 'disabled' : ''} aria-label="Thêm">+</button></span><b>${num(m.gia * m.sl)}</b><button type="button" class="icon-x" data-nrm="${k}" aria-label="Bỏ món"><i class="fa-solid fa-xmark"></i></button></li>`).join('')
            : '<li class="th-empty"><i class="fa-solid fa-bag-shopping" style="font-size:20px"></i>Chưa chọn món mới — gõ tên hàng ở ô trên.</li>';

        const tra = tongTra();
        let ok;
        let amt;
        if (f.mode === 'refund') {
            amt = Math.max(0, tra - f.ded);
            $('[data-sumbox]').innerHTML = `<div><span>Tiền hàng trả · ${soMon()} món</span><b>${num(tra)}</b></div>${f.ded ? `<div><span>Khấu trừ</span><b>−${num(f.ded)}</b></div>` : ''}<div><span>Két theo sổ ca #${CA.id}</span><b>${f.pm === 'cash' ? `${num(f.ket)} → ${num(f.ket - amt)}` : 'không đổi'}</b></div><div class="is-grand"><span>Hoàn cho khách</span><b>${vnd(amt)}</b></div>`;
            ok = f.confirmNeg ? 'Vẫn hoàn tiền' : 'Hoàn tiền & in phiếu';
        } else {
            const moi = tongMoi();
            const c = Math.round(moi - tra);
            amt = Math.abs(c);
            $('[data-sumbox]').innerHTML = `<div><span>Hàng trả · ${soMon()} món</span><b>−${num(tra)}</b></div><div><span>Hàng mới · ${f.moi.reduce((t, m) => t + m.sl, 0)} món (tạm tính)</span><b>+${num(moi)}</b></div>
                <div><span>Chênh lệch thu / trả bằng</span><b class="tcm-pm" style="display:inline-flex;font-weight:500" data-pm2><button type="button" data-v="cash" aria-pressed="${f.pm === 'cash'}">Tiền mặt</button><button type="button" data-v="bank_transfer" aria-pressed="${f.pm === 'bank_transfer'}">Chuyển khoản</button></b></div>
                <div class="is-grand${c > 0 ? ' is-owe' : ''}"><span>${c > 0 ? 'Khách trả thêm' : c < 0 ? 'Trả lại khách' : 'Đổi ngang giá'}</span><b>${vnd(amt)}</b></div>`;
            ok = f.confirmNeg ? 'Vẫn đổi hàng' : c > 0 ? 'Thu thêm & đổi hàng' : 'Đổi hàng & in phiếu';
        }
        $('[data-ok-t]').textContent = f.dangGui ? 'Đang lưu…' : ok;
        $('[data-ok-amt]').textContent = amt ? vnd(amt) : '';
        $('[data-ok]').classList.toggle('is-confirm', f.confirmNeg);
        $('[data-ok]').disabled = f.dangGui;
        $('[data-f-err]').hidden = !f.err;
        $('[data-f-err]').textContent = f.err;
    }

    function render() {
        $$('[data-pane]').forEach((p) => { p.hidden = +p.dataset.pane !== f.step; });
        $$('[data-step]').forEach((s) => { const n = +s.dataset.step; s.classList.toggle('is-on', n === f.step); s.classList.toggle('is-done', n < f.step); });
        $('[data-steps]').hidden = f.step === 3;
        $('[data-f-title]').textContent = f.step === 3 ? 'Đã lập phiếu trả' : f.mode === 'swap' && f.step === 2 ? 'Đổi hàng' : 'Lập phiếu trả';
        $('[data-f-badge]').innerHTML = `<i class="fa-solid ${f.step === 3 ? 'fa-check' : f.mode === 'swap' && f.step === 2 ? 'fa-arrow-right-arrow-left' : 'fa-rotate-left'}"></i>`;
        dlg.classList.toggle('is-step2', f.step === 2);
        $('[data-ok]').hidden = f.step !== 2;
        $('[data-back-step]').hidden = f.step !== 2;
        $('[data-keys]').hidden = f.step !== 2;
        $('[data-foot-close]').textContent = f.step === 3 ? 'Đóng' : 'Huỷ';
        if (f.step === 1) { dlg.classList.remove('is-swap', 'is-out'); renderPick(); }
        if (f.step === 2) { renderLines(); renderRight(); }
    }

    async function luu() {
        if (f.dangGui) return;
        f.err = '';
        if (!soMon()) { f.err = 'Chọn ít nhất một món khách trả.'; renderRight(); return; }
        if (!f.reason) { $('[data-reason-err]').hidden = false; return; }
        if (f.mode === 'swap' && !f.moi.length) { f.err = 'Chọn món khách đổi sang — hoặc chuyển về Hoàn tiền.'; renderRight(); return; }
        if (f.mode === 'refund' && f.ded > tongTra()) { f.err = 'Khấu trừ lớn hơn tiền hàng trả.'; renderRight(); return; }
        if (ketThieu() > 0 && !f.confirmNeg) {
            f.confirmNeg = true;
            $('[data-neg-text]').textContent = `Két theo sổ chỉ còn ${vnd(f.ket)} — không đủ để trả khách bằng tiền mặt. Chuyển khoản cho khách, hoặc bấm lại nếu tiền lấy từ nguồn khác.`;
            $('[data-neg]').hidden = false;
            renderRight();
            return;
        }
        const tra = monChinh().filter((i) => f.qty[i.order_item_id] > 0).map((i) => ({ order_item_id: i.order_item_id, quantity: f.qty[i.order_item_id] }));
        const chung = { order_id: f.order.id, reason: f.reason, reason_note: $('[data-note]').value.trim(), restock: f.kho };
        f.dangGui = true;
        renderRight();
        try {
            let id; let ma; let cau; let soTien; let nhan;
            if (f.mode === 'refund') {
                const body = await goi(D.url.store, { method: 'POST', body: JSON.stringify({ ...chung, refund_method: f.pm, deduction: f.ded, items: tra }) });
                const p = body.data || {};
                id = p.id; ma = p.return_code; soTien = p.refund_amount; nhan = 'Hoàn cho khách';
                cau = `Đã hoàn cho khách bằng ${PM[f.pm].toLowerCase()}${f.pm === 'cash' ? ' — lấy tiền trong két đưa khách' : ''}. ${f.kho ? 'Hàng đã cộng lại tồn.' : 'Hàng lỗi không cộng tồn.'}`;
            } else {
                const body = await goi(D.url.doi, { method: 'POST', body: JSON.stringify({ ...chung, payment_method: f.pm, tra, moi: f.moi.map((m) => ({ product_variant_id: m.id, quantity: m.sl })) }) });
                const p = body.data || {};
                id = p.return_id; ma = p.return_code; soTien = Math.abs(p.chenh_lech || 0);
                nhan = p.chenh_lech > 0 ? 'Thu thêm của khách' : p.chenh_lech < 0 ? 'Trả lại khách' : 'Đổi ngang giá';
                cau = `${p.message || 'Đã đổi hàng xong.'} Đơn mới ${p.order_code || ''}.`;
            }
            f.ketqua = { id, ma };
            daLapId = id;
            f.step = 3;
            $('[data-done]').innerHTML = `<span class="th-done__ic"><i class="fa-solid fa-check"></i></span><h3>${esc(ma)}</h3><p>${esc(cau)}</p>
                <div class="tc-hero ${f.mode === 'swap' ? 'is-swap' : 'is-ret'}"><div><div class="tc-hero__label">${esc(nhan)}</div><div class="tc-hero__amt t-num">${vnd(soTien)}</div></div><div class="tc-hero__pm">Đang in phiếu trả…<b>${esc(PM[f.pm])}</b></div></div>
                <div style="display:flex;gap:8px;margin-top:8px"><button type="button" class="btn btn--outline" data-reprint><i class="fa-solid fa-print"></i> In lại</button><button type="button" class="btn btn--ret" data-another><i class="fa-solid fa-rotate-left"></i> Lập phiếu khác</button></div>`;
            render();
            if (id) window.posIn(urlId(D.url.phieu, id));
        } catch (err) {
            f.dangGui = false;
            if (err.ma === 'CHUA_MO_CA') { dlg.close(); taiLai(null, err.message); return; }
            f.err = err.message;
            renderRight();
        }
    }

    // Đóng hộp sau khi đã lập phiếu (kể cả qua "Lập phiếu khác") → tải lại để danh sách, thẻ tổng, két cùng đúng.
    let daLapId = null;
    dlg.addEventListener('close', () => { if (daLapId) taiLai(daLapId); });
    // Bắt ở cấp trang: chọn món mới xong danh sách gợi ý bị dựng lại, con trỏ rơi ra ngoài hộp.
    document.addEventListener('keydown', (e) => { if (dlg.open && e.key === 'Enter' && (e.ctrlKey || e.metaKey) && f.step === 2) { e.preventDefault(); luu(); } });
    $('[data-form]').addEventListener('submit', (e) => { e.preventDefault(); if (f.step === 2) luu(); });

    let oqTimer;
    $('[data-oq]').addEventListener('input', (e) => { f.oq = e.target.value; clearTimeout(oqTimer); oqTimer = setTimeout(taiDon, 250); });
    // Máy quét gõ mã rồi Enter: khớp đúng một đơn trả được thì chọn luôn.
    $('[data-oq]').addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        clearTimeout(oqTimer);
        f.oq = e.target.value;
        await taiDon();
        const q = f.oq.trim().toUpperCase();
        const o = f.orders.find((x) => String(x.order_code).toUpperCase() === q) || (f.orders.length === 1 ? f.orders[0] : null);
        if (!o) return;
        if (o.blocked_reason) posToast(o.blocked_reason, 'error'); else chonDon(o.id);
    });
    $('[data-ded]').addEventListener('input', (e) => { f.ded = parseMoney(e.target.value); e.target.value = f.ded ? num(f.ded) : ''; f.confirmNeg = false; $('[data-neg]').hidden = true; renderRight(); });
    $('[data-restock]').addEventListener('change', (e) => { f.kho = e.target.checked; f.khoTay = true; renderRight(); });

    let nqTimer;
    let nqLuot = 0;
    $('[data-nq]').addEventListener('input', (e) => {
        clearTimeout(nqTimer);
        const q = e.target.value.trim();
        const ul = $('[data-nlist]');
        if (!q) { ul.hidden = true; return; }
        nqTimer = setTimeout(async () => {
            const luot = ++nqLuot;
            try {
                const u = new URL(D.url.sp, location.href);
                u.searchParams.set('q', q);
                u.searchParams.set('page_size', '8');
                const body = await goi(u);
                if (luot !== nqLuot) return;
                const ds = [];
                (body.data || []).forEach((p) => (p.variants || []).forEach((v) => ds.push({ id: v.id, ten: p.name, loai: v.name && v.name !== p.name ? v.name : '', gia: Number(v.final_price ?? v.price ?? p.sale_price ?? p.base_price) || 0, ton: Number(v.stock) || 0 })));
                ul.innerHTML = ds.length ? ds.slice(0, 12).map((p) => `<li><button type="button" data-np='${esc(JSON.stringify(p))}' ${p.ton > 0 ? '' : 'disabled'}><i class="fa-solid fa-bag-shopping" style="color:var(--tc-faint)"></i><span>${esc(p.ten)}${p.loai ? ` · ${esc(p.loai)}` : ''}</span><small>${p.ton > 0 ? `${num(p.gia)} · còn ${p.ton}` : 'Hết hàng'}</small></button></li>`).join('') : '<li class="th-empty">Không tìm thấy hàng.</li>';
                ul.hidden = false;
            } catch (err) {
                ul.innerHTML = `<li class="th-empty">${esc(err.message)}</li>`;
                ul.hidden = false;
            }
        }, 250);
    });

    /* ------------------------------------------------------------ sự kiện */
    document.addEventListener('click', (ev) => {
        const t = ev.target;
        if (!t.closest('.th-new-search')) $('[data-nlist]').hidden = true;
        const row = t.closest('.tc-row');
        if (row) { chon(+row.dataset.id); return; }
        const cr = t.closest('[data-create]');
        if (cr && !cr.disabled) { moHop(); return; }
        if (t.closest('[data-back]')) { detail.classList.remove('is-open'); return; }
        if (t.closest('[data-print]')) { const r = rowOf(sel); if (r) window.posInLai({ id: r.id, ma: r.return_code, loai: 'tra' }); return; }
        if (t.closest('[data-copy]')) { const r = rowOf(sel); navigator.clipboard?.writeText(r.return_code).catch(() => {}); posToast(`Đã chép ${r.return_code}`); return; }

        const op = t.closest('[data-op]');
        if (op) { f.operiod = +op.dataset.op; renderPick(); taiDon(); return; }
        const ord = t.closest('[data-ord]');
        if (ord && f.step === 1) { const o = f.orders.find((x) => x.id === +ord.dataset.ord); if (o?.blocked_reason) posToast(o.blocked_reason, 'error'); else if (o) chonDon(o.id); return; }
        if (t.closest('[data-repick], [data-back-step]')) { f.step = 1; f.confirmNeg = false; $('[data-neg]').hidden = true; render(); $('[data-oq]').focus(); if (!f.orders.length) taiDon(); return; }
        const chk = t.closest('[data-chk]');
        if (chk) { const it = f.items.find((i) => i.order_item_id === +chk.dataset.chk); f.qty[it.order_item_id] = chk.checked ? it.remain_quantity : 0; f.confirmNeg = false; renderLines(); renderRight(); return; }
        const inc = t.closest('[data-inc]');
        if (inc && !inc.disabled) { const id = +inc.dataset.inc; f.qty[id] = (f.qty[id] || 0) + 1; f.confirmNeg = false; renderLines(); renderRight(); return; }
        const dec = t.closest('[data-dec]');
        if (dec && !dec.disabled) { const id = +dec.dataset.dec; f.qty[id] = Math.max(0, (f.qty[id] || 0) - 1); f.confirmNeg = false; renderLines(); renderRight(); return; }
        if (t.closest('[data-all]')) { const all = t.closest('[data-all]').checked; monChinh().forEach((i) => { if (i.remain_quantity > 0) f.qty[i.order_item_id] = all ? i.remain_quantity : 0; }); renderLines(); renderRight(); return; }
        const md = t.closest('[data-mode] button');
        if (md) { f.mode = md.dataset.v === 'in' ? 'refund' : 'swap'; f.confirmNeg = false; f.err = ''; $('[data-neg]').hidden = true; render(); return; }
        const rs = t.closest('[data-reason]');
        if (rs) { f.reason = rs.dataset.reason; if (!f.khoTay) f.kho = D.lyDo[f.reason].kho; $('[data-reason-err]').hidden = true; renderRight(); return; }
        const pmb = t.closest('[data-pm] button, [data-pm2] button');
        if (pmb) { f.pm = pmb.dataset.v; f.confirmNeg = false; $('[data-neg]').hidden = true; renderRight(); return; }
        const np = t.closest('[data-np]');
        if (np && !np.disabled) {
            const p = JSON.parse(np.dataset.np);
            const had = f.moi.find((m) => m.id === p.id);
            if (had) had.sl = Math.min(p.ton, had.sl + 1); else f.moi.push({ ...p, sl: 1 });
            $('[data-nq]').value = '';
            $('[data-nlist]').hidden = true;
            f.err = '';
            renderRight();
            return;
        }
        const ninc = t.closest('[data-ninc]');
        if (ninc && !ninc.disabled) { f.moi[+ninc.dataset.ninc].sl += 1; renderRight(); return; }
        const ndec = t.closest('[data-ndec]');
        if (ndec) { const k = +ndec.dataset.ndec; f.moi[k].sl -= 1; if (f.moi[k].sl <= 0) f.moi.splice(k, 1); renderRight(); return; }
        const nrm = t.closest('[data-nrm]');
        if (nrm) { f.moi.splice(+nrm.dataset.nrm, 1); renderRight(); return; }
        if (t.closest('[data-reprint]') && f.ketqua) { window.posInLai({ id: f.ketqua.id, ma: f.ketqua.ma, loai: 'tra' }); return; }
        if (t.closest('[data-another]')) { moHop(); }
    });

    $('[data-range]')?.addEventListener('change', (e) => e.currentTarget.requestSubmit());

    document.addEventListener('keydown', (e) => {
        if (e.key === 'F1') { e.preventDefault(); $('[data-q]').focus(); $('[data-q]').select(); return; }
        if (document.querySelector('dialog[open]')) return;
        if (e.key === 'F2') { e.preventDefault(); moHop(); return; }
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
    if (D.moDon) moHop(D.moDon);
})();
</script>
@endpush
