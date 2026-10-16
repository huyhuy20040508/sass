{{--
    ĐIỀU PHỐI CA — màn của module Thu ngân.

    Trái: ca đang mở (hoặc lời mời Mở ca), tiền mặt ngoài ca hôm nay, lịch sử ca đã
    đóng. Phải: tờ ca đang chọn — dải két (đầu ca + thu − chi = theo sổ; ca đã đóng
    thêm đếm được, chênh lệch), sổ quỹ có tồn két sau từng dòng, doanh thu ca, thông
    tin mở / đóng.

    Chọn ca khác là tải lại trang với ?ca= (giữ bộ lọc lịch sử trên URL). JS chỉ lo
    lọc sổ quỹ tại chỗ, chuyển tab và ba hộp thoại Mở ca / Đóng ca / Thu chi.
    CHỈ TIỀN MẶT vào sổ ca — chuyển khoản, thẻ, QR không đi qua két.
--}}
@extends('v2::layouts.pos')

@php
    $C = \App\Http\Controllers\WorkShiftController::class;

    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $vnd = fn ($n) => $so($n).' đ';
    // Két thật không bao giờ âm: số âm là sổ thiếu một khoản thu hoặc gõ nhầm — tô đỏ, nói rõ.
    $vndKet = fn ($n) => (float) $n < 0 ? '<span class="ket-am">'.e($vnd($n)).' · két âm</span>' : e($vnd($n));
    $sgn = fn ($n) => $n > 0 ? '+'.$vnd($n) : ($n < 0 ? '−'.$vnd(-$n) : $vnd(0));
    $gio = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('H:i') : '';
    $ngay = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('d/m/Y') : '';
    $thoiLuong = function ($a, $b) {
        $phut = max(0, (int) round(\Illuminate\Support\Carbon::parse($a)->diffInMinutes(\Illuminate\Support\Carbon::parse($b ?? now()), true)));

        return $phut < 60 ? "$phut phút" : intdiv($phut, 60).' giờ'.($phut % 60 ? ' '.($phut % 60).' phút' : '');
    };
    $nhanNgay = function ($t) {
        $d = \Illuminate\Support\Carbon::parse($t)->startOfDay();
        $cach = (int) $d->diffInDays(now()->startOfDay(), true);

        return $cach === 0 ? 'Hôm nay' : ($cach === 1 ? 'Hôm qua' : ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'][$d->dayOfWeek]);
    };
    // Ca đã đóng: tiền theo sổ là con số ĐÃ CHỐT lúc đóng, không cộng lại từ sổ.
    $theoSo = fn ($ca) => ! empty($ca['closed_at']) && isset($ca['expected_cash'])
        ? (float) $ca['expected_cash']
        : (float) ($ca['opening_cash'] ?? 0) + (float) ($ca['tong_thu'] ?? 0) - (float) ($ca['tong_chi'] ?? 0);
    $lechCls = fn ($d) => abs($d) < 0.5 ? 'ok' : ($d < 0 ? 'short' : 'over');
    $lechChu = fn ($d) => abs($d) < 0.5 ? 'Khớp két' : ($d < 0 ? 'Thiếu '.$vnd(-$d) : 'Dư '.$vnd($d));

    $boLoc = array_filter([
        'ky' => $filters['ky'] !== '7-ngay' ? $filters['ky'] : null,
        'from_date' => $filters['ky'] === 'tuy-chon' ? $filters['from_date'] : null,
        'to_date' => $filters['ky'] === 'tuy-chon' ? $filters['to_date'] : null,
        'opened_by' => $filters['opened_by'] ?: null,
        'lech' => $filters['lech'] ? 1 : null,
        'page' => $filters['page'] > 1 ? $filters['page'] : null,
    ]);
    $url = fn (array $them = []) => route('thu-ngan.ca-lam-viec.index', array_filter(array_merge($boLoc, $them), fn ($v) => $v !== null && $v !== ''));

    $cn = \App\Services\CurrentBranch::danhSach();
    $tenCn = (string) (collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? '');
    $toi = trim((string) data_get(session('api.user'), 'full_name', '')) ?: (string) data_get(session('api.user'), 'username', '');
    // Người trực két; ca mở trước khi có ô này thì lùi về người bấm Mở ca.
    // Số để lại trong két cho ca sau; ca đóng trước migration 0090 chưa có thì là cả số đếm.
    $giuLai = fn ($c) => (float) ($c['kept_cash'] ?? $c['counted_cash'] ?? 0);
    $tenTruc = fn ($c) => ($c['employee_name'] ?? '') !== '' ? $c['employee_name'] : ($c['opened_by_name'] ?? '');

    $ca = $chon['ca'] ?? null;
    $soQuy = $chon['so_quy'] ?? [];
    $dt = $chon['doanh_thu'] ?? [];
    $dangMo = $ca && empty($ca['closed_at']);

    // Tồn két sau từng dòng: cộng theo đúng thứ tự đã xảy ra (API trả cũ → mới), rồi đảo để dòng mới lên đầu.
    $ton = (float) ($ca['opening_cash'] ?? 0);
    $dongSo = [];
    foreach ($soQuy as $e) {
        $ton += ($e['direction'] ?? '') === 'in' ? (float) $e['amount'] : -(float) $e['amount'];
        $dongSo[] = $e + ['ton' => $ton];
    }
    $dongSo = array_reverse($dongSo);
    $ngoaiTruocCa = $dangMo ? array_values(array_filter($ngoaiCa, fn ($e) => \Illuminate\Support\Carbon::parse($e['created_at'])->lt(\Illuminate\Support\Carbon::parse($ca['opened_at'])))) : [];
    $nguon = ['order' => ['fa-cart-shopping', ''], 'order_return' => ['fa-rotate-left', 'ca-src--return'], 'income_expense' => ['fa-file-invoice-dollar', 'ca-src--voucher'], 'manual' => ['fa-pen', 'ca-src--manual']];
    $caJs = [
        'hienTai' => $hienTai ? ['id' => $hienTai['id'], 'ai' => $tenTruc($hienTai), 'mo' => $hienTai['opened_at']] : null,
        'caTruoc' => $caTruoc ? ['id' => $caTruoc['id'], 'ai' => $tenTruc($caTruoc), 'banGiao' => $giuLai($caTruoc), 'coGiu' => isset($caTruoc['kept_cash'])] : null,
        'url' => [
            'trang' => route('thu-ngan.ca-lam-viec.index'),
            'hienTai' => route('thu-ngan.ca-lam-viec.hienTai'),
            'mo' => route('thu-ngan.ca-lam-viec.mo'),
            'ganNgoai' => route('thu-ngan.ca-lam-viec.ganNgoaiCa'),
            'dong' => route('thu-ngan.ca-lam-viec.dong'),
            'soQuy' => route('thu-ngan.ca-lam-viec.soQuy'),
            'phieu' => route('thu-ngan.ca-lam-viec.phieu', ['id' => '__ID__']),
        ],
        'soNgoaiCa' => count($ngoaiCa),
    ];
@endphp

@section('title', $C::TITLE)
@section('than', 'tn-ca')

@section('content')
    <aside class="ca-side" aria-labelledby="caTitle">
        <div class="ca-side__head">
            <h1 class="ca-title" id="caTitle">Điều phối ca</h1>
            @if($tenCn !== '')
                <span class="meta-chip meta-chip--branch"><i class="fa-solid fa-store"></i>{{ $tenCn }}</span>
            @endif
        </div>

        @if($quaLau ?? null)
            <div class="ca-alert ca-alert--warn ca-qua-lau" role="alert"><i class="fa-solid fa-clock"></i><span>{{ $quaLau['cau'] }} <button type="button" class="link-btn" data-act="close">Đóng ca</button></span></div>
        @endif

        @if($hienTai)
            <a class="ca-now" href="{{ $url(['ca' => $hienTai['id']]) }}" aria-current="{{ $ca && (int) $ca['id'] === (int) $hienTai['id'] ? 'true' : 'false' }}">
                <span class="ca-now__top"><span class="ca-now__id">Ca #{{ $hienTai['id'] }}</span><span class="ca-st ca-st--open">Đang mở</span></span>
                <span class="ca-now__who">{{ $tenTruc($hienTai) }} <small>· từ {{ $gio($hienTai['opened_at']) }} · {{ $thoiLuong($hienTai['opened_at'], null) }}</small></span>
                <span class="ca-now__cash"><span>Tiền trong két theo sổ</span><b>{!! $vndKet($theoSo($hienTai)) !!}</b></span>
            </a>
        @else
            <div class="ca-closed">
                <span class="ca-closed__ic"><i class="fa-solid fa-lock"></i></span>
                <h2>{{ $caTruoc ? 'Chưa mở ca' : 'Chi nhánh chưa có ca nào' }}</h2>
                <p>
                    @if($caTruoc)
                        Ca gần nhất: #{{ $caTruoc['id'] }} · {{ $tenTruc($caTruoc) }} · đóng {{ $gio($caTruoc['closed_at']) }} {{ mb_strtolower($nhanNgay($caTruoc['closed_at'])) }}, {{ isset($caTruoc['kept_cash']) ? 'giữ lại trong két' : 'bàn giao' }} <b>{{ $vnd($giuLai($caTruoc)) }}</b>.
                    @else
                        Mở ca khi bắt đầu buổi bán — từ đó mọi lượt thu chi tiền mặt vào sổ của ca.
                    @endif
                </p>
                <button type="button" class="btn btn--primary" data-act="open"><i class="fa-solid fa-lock-open"></i> Mở ca</button>
                {{-- Câu này từng ghi "Quầy vẫn bán được khi chưa mở ca". Nay SAI: màn Bán
                     hàng phủ hẳn lớp khoá #posCaKhoa khi chi nhánh không có ca mở, hai
                     khung hàng + hoá đơn bị `inert` nên không thêm được món nào. Để câu
                     cũ thì thu ngân tưởng cứ bán rồi tính sau, còn chủ tiệm tưởng số
                     "ngoài ca" vẫn sinh ra từ bán hàng — trong khi nay nó chỉ còn sinh
                     từ phiếu thu/chi tiền mặt lập lúc không có ca. --}}
                <p class="ca-why">Chưa mở ca thì màn Bán hàng bị khoá. Phiếu thu/chi tiền mặt lập lúc này được ghi là “ngoài ca” — mở ca xong có thể gắn vào ca.</p>
            </div>
        @endif

        @if($ngoaiCa)
            @php $rong = collect($ngoaiCa)->sum(fn ($e) => ($e['direction'] ?? '') === 'in' ? (float) $e['amount'] : -(float) $e['amount']); @endphp
            <div class="ca-alert ca-alert--warn"><i class="fa-solid fa-triangle-exclamation"></i><span>Hôm nay có <b>{{ count($ngoaiCa) }} khoản tiền mặt ngoài ca</b> (ròng {{ $sgn($rong) }}) — đã vào két nhưng không thuộc ca nào. <button type="button" class="link-btn" data-open="caNgoai">Xem</button></span></div>
        @endif

        <section class="ca-hist" aria-labelledby="caHistT">
            <div class="ca-hist__head">
                <h2 id="caHistT"><i class="fa-regular fa-clock"></i> Lịch sử ca <small>· theo giờ đóng</small></h2>
                <a class="btn btn--outline" href="{{ route('thu-ngan.ca-lam-viec.phieuTongHop', array_merge($boLoc, ['ky' => $filters['ky'], 'from_date' => $filters['from_date'], 'to_date' => $filters['to_date']])) }}" target="_blank" rel="noopener" title="In phiếu tổng hợp các ca đã đóng theo bộ lọc đang xem" @if(! $list) aria-disabled="true" data-khoa="Không có ca nào để in" @endif><i class="fa-solid fa-print"></i> In tổng hợp</a>
            </div>
            <div class="seg" role="group" aria-label="Kỳ (tính theo giờ đóng ca)">
                @foreach($C::KY as $k => $nhan)
                    @if($k === 'tuy-chon')
                        <button type="button" aria-pressed="{{ $filters['ky'] === $k ? 'true' : 'false' }}" data-tuy-chon>{{ $nhan }}</button>
                    @else
                        <a class="seg-link" role="button" aria-pressed="{{ $filters['ky'] === $k ? 'true' : 'false' }}" href="{{ route('thu-ngan.ca-lam-viec.index', array_filter(['ky' => $k === '7-ngay' ? null : $k, 'opened_by' => $filters['opened_by'] ?: null, 'lech' => $filters['lech'] ? 1 : null])) }}">{{ $nhan }}</a>
                    @endif
                @endforeach
            </div>
            <form class="ca-range" method="GET" action="{{ route('thu-ngan.ca-lam-viec.index') }}" data-range @if($filters['ky'] !== 'tuy-chon') hidden @endif>
                <input type="hidden" name="ky" value="tuy-chon">
                @if($filters['opened_by'])<input type="hidden" name="opened_by" value="{{ $filters['opened_by'] }}">@endif
                @if($filters['lech'])<input type="hidden" name="lech" value="1">@endif
                <input type="date" name="from_date" aria-label="Từ ngày" value="{{ $filters['from_date'] }}" max="{{ now()->toDateString() }}">
                <span>→</span>
                <input type="date" name="to_date" aria-label="Đến ngày" value="{{ $filters['to_date'] }}" max="{{ now()->toDateString() }}">
                <button type="submit" class="btn btn--outline" style="min-height:var(--tap)">Xem</button>
            </form>
            <form class="ca-hist__filters" method="GET" action="{{ route('thu-ngan.ca-lam-viec.index') }}" data-loc>
                @foreach(array_diff_key($boLoc, ['opened_by' => 1, 'lech' => 1, 'page' => 1]) as $k => $v)
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endforeach
                <label class="sr-only" for="caStaff">Người trực</label>
                <select class="select" id="caStaff" name="opened_by" data-tu-gui>
                    <option value="">Mọi người trực</option>
                    @foreach($nguoiTruc as $n)
                        <option value="{{ $n['id'] }}" @selected((int) $filters['opened_by'] === (int) $n['id'])>{{ $n['name'] ?: 'Tài khoản #'.$n['id'] }}</option>
                    @endforeach
                </select>
                <a class="ca-chip" aria-pressed="{{ $filters['lech'] ? 'true' : 'false' }}" href="{{ route('thu-ngan.ca-lam-viec.index', array_filter(array_merge(array_diff_key($boLoc, ['page' => 1]), ['lech' => $filters['lech'] ? null : 1]))) }}" title="Chỉ hiện ca có tiền đếm được lệch với sổ"><i class="fa-solid fa-scale-unbalanced"></i>Lệch két</a>
            </form>

            <ul class="ca-items">
                @if(! empty($error))
                    <li class="ca-alert ca-alert--danger"><i class="fa-solid fa-circle-exclamation"></i><span>{{ $error }}</span></li>
                @elseif(! $list)
                    <li class="ca-alert ca-alert--info"><i class="fa-solid fa-circle-info"></i><span>{{ $filters['opened_by'] || $filters['lech'] || $filters['ky'] !== '7-ngay' ? 'Không có ca đã đóng nào khớp bộ lọc.' : 'Chưa có ca nào được đóng trong 7 ngày qua.' }}</span></li>
                @else
                    @php $ngayTruoc = ''; @endphp
                    @foreach($list as $s)
                        {{-- Nhóm theo ngày ĐÓNG ca, cùng mốc với bộ lọc kỳ. --}}
                        @php $d = $ngay($s['closed_at']); $lech = (float) ($s['difference'] ?? 0); @endphp
                        @if($d !== $ngayTruoc)
                            <li class="ca-day">{{ $nhanNgay($s['closed_at']) }} <span>· {{ $d }}</span></li>
                            @php $ngayTruoc = $d; @endphp
                        @endif
                        <li>
                            <a class="ca-item" href="{{ $url(['ca' => $s['id']]) }}" aria-current="{{ $ca && (int) $ca['id'] === (int) $s['id'] ? 'true' : 'false' }}">
                                <span class="ca-item__t">@if($ngay($s['opened_at']) !== $d){{ \Illuminate\Support\Carbon::parse($s['opened_at'])->format('d/m') }} @endif{{ $gio($s['opened_at']) }}–{{ $gio($s['closed_at']) }}<small>#{{ $s['id'] }}</small></span>
                                <span class="ca-item__cash">{{ $vnd($s['counted_cash'] ?? 0) }}</span>
                                <span class="ca-item__who">{{ $tenTruc($s) }}</span>
                                <span class="ca-diff ca-diff--{{ $lechCls($lech) }}">{{ $lechChu($lech) }}</span>
                            </a>
                        </li>
                    @endforeach
                @endif
            </ul>
            @if(($meta['total'] ?? 0) > 0)
                <div class="ca-pager">
                    @if(($meta['total_pages'] ?? 1) > 1)
                        <a class="btn btn--ghost" href="{{ $url(['page' => max(1, $filters['page'] - 1), 'ca' => $filters['ca'] ?: null]) }}" @if($filters['page'] <= 1) aria-disabled="true" data-khoa="Đang ở trang đầu" @endif aria-label="Trang trước"><i class="fa-solid fa-chevron-left"></i></a>
                        <span>Trang {{ $filters['page'] }}/{{ $meta['total_pages'] }} · {{ $meta['total'] }} ca</span>
                        <a class="btn btn--ghost" href="{{ $url(['page' => $filters['page'] + 1, 'ca' => $filters['ca'] ?: null]) }}" @if($filters['page'] >= $meta['total_pages']) aria-disabled="true" data-khoa="Đang ở trang cuối" @endif aria-label="Trang sau"><i class="fa-solid fa-chevron-right"></i></a>
                    @else
                        <span>{{ $meta['total'] }} ca</span>
                    @endif
                </div>
            @endif
        </section>
    </aside>

    <section class="ca-detail {{ $filters['ca'] ? 'is-chon' : '' }}" aria-label="Chi tiết ca" data-detail>
        <div class="paper">
            @if(! $ca)
                <div class="ca-empty"><div>
                    <i class="fa-solid fa-cash-register big"></i>
                    @if($filters['ca'])
                        <h2>Không mở được ca #{{ $filters['ca'] }}</h2>
                        <p>Ca không tồn tại, thuộc chi nhánh khác, hoặc chưa tải được. <a class="link-btn" href="{{ $url() }}">Về ca hiện tại</a></p>
                    @else
                        <h2>{{ $caTruoc ? 'Chưa mở ca' : 'Bắt đầu ca đầu tiên' }}</h2>
                        <p>Ca là khoảng thời gian <b>một người</b> chịu trách nhiệm về két tiền. Mỗi chi nhánh chỉ mở một ca một lúc.</p>
                        <ol>
                            <li>Đếm tiền đang có trong két, bấm <b>Mở ca</b>.</li>
                            <li>Bán hàng như thường — tiền mặt, hoàn tiền, thu chi tự vào sổ của ca.</li>
                            <li>Hết ca: đếm lại két, bấm <b>Đóng ca</b>. Máy so với sổ và chốt chênh lệch.</li>
                        </ol>
                        <button type="button" class="btn btn--primary" data-act="open"><i class="fa-solid fa-lock-open"></i> Mở ca</button>
                    @endif
                </div></div>
            @else
                @php
                    $book = $theoSo($ca);
                    $lech = (float) ($ca['difference'] ?? 0);
                    $nIn = collect($soQuy)->where('direction', 'in')->count();
                @endphp
                <div class="ca-d-head">
                    <div class="ca-d-top">
                        <button type="button" class="icon-btn ca-back" aria-label="Quay lại danh sách ca" data-back><i class="fa-solid fa-arrow-left"></i></button>
                        <h2 class="ca-d-id">Ca #{{ $ca['id'] }}</h2>
                        <span class="ca-d-time"><b>{{ $gio($ca['opened_at']) }}</b> → @if($dangMo) đang mở @else <b>{{ $gio($ca['closed_at']) }}</b> @endif · {{ $nhanNgay($ca['opened_at']) }} {{ $ngay($ca['opened_at']) }} · {{ $thoiLuong($ca['opened_at'], $ca['closed_at'] ?? null) }}</span>
                        <span class="ca-st {{ $dangMo ? 'ca-st--open' : 'ca-st--closed' }}">{{ $dangMo ? 'Đang mở' : 'Đã đóng' }}</span>
                    </div>
                    <div class="paper__meta">
                        @if($tenCn !== '')<span class="meta-chip meta-chip--branch"><i class="fa-solid fa-store"></i>{{ $tenCn }}</span>@endif
                        <span class="meta-chip meta-chip--cashier">Người trực <b>{{ $tenTruc($ca) }}</b></span>
                        @if(! $dangMo && ($ca['closed_by'] ?? null) && ($ca['closed_by'] ?? null) != ($ca['opened_by'] ?? null))
                            <span class="meta-chip meta-chip--cashier">Người đóng <b>{{ $ca['closed_by_name'] ?? '' }}</b></span>
                        @endif
                    </div>
                    <dl class="ca-till" aria-label="Đối chiếu két">
                        <div><dt>Tiền đầu ca</dt><dd>{{ $vnd($ca['opening_cash'] ?? 0) }}</dd></div>
                        <div class="is-in"><dt><i class="fa-solid fa-plus"></i>Thu tiền mặt</dt><dd>{{ $vnd($ca['tong_thu'] ?? 0) }}</dd></div>
                        <div class="is-out"><dt><i class="fa-solid fa-minus"></i>Chi tiền mặt</dt><dd>{{ $vnd($ca['tong_chi'] ?? 0) }}</dd></div>
                        <div class="is-book"><dt><i class="fa-solid fa-equals"></i>Tiền theo sổ</dt><dd>{!! $vndKet($book) !!}</dd></div>
                        @unless($dangMo)
                            <div class="is-count"><dt>Đếm được</dt><dd>{{ $vnd($ca['counted_cash'] ?? 0) }}</dd></div>
                            <div class="is-diff is-{{ $lechCls($lech) }}"><dt>Chênh lệch</dt><dd>{{ abs($lech) < 0.5 ? 'Khớp' : $sgn($lech) }}</dd></div>
                        @endunless
                    </dl>
                    @if($ngoaiTruocCa)
                        <div class="ca-alert ca-alert--warn"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ count($ngoaiTruocCa) }} khoản tiền mặt phát sinh trước giờ mở ca không nằm trong “Tiền theo sổ”. <button type="button" class="link-btn" data-open="caNgoai">Xem</button></span></div>
                    @endif
                    @if(! $dangMo && abs($lech) >= 0.5)
                        <div class="ca-alert ca-alert--{{ $lech < 0 ? 'danger' : 'warn' }}"><i class="fa-solid fa-scale-unbalanced"></i><span><b>{{ $lechChu($lech) }}.</b> {{ ($ca['close_note'] ?? '') !== '' ? $ca['close_note'] : 'Không ghi lý do.' }}</span></div>
                    @endif
                </div>

                <div class="ca-tabs" role="tablist" aria-label="Nội dung ca">
                    <button type="button" role="tab" id="tab-so" aria-selected="true" aria-controls="panel-so" data-tab="so">Sổ quỹ<span class="n">{{ count($soQuy) }}</span></button>
                    <button type="button" role="tab" id="tab-dt" aria-selected="false" aria-controls="panel-dt" data-tab="dt">Doanh thu ca</button>
                    <button type="button" role="tab" id="tab-tt" aria-selected="false" aria-controls="panel-tt" data-tab="tt">Thông tin ca</button>
                </div>

                <div class="ca-d-body">
                    <div role="tabpanel" id="panel-so" aria-labelledby="tab-so">
                        @if(! $soQuy)
                            <div class="ca-blind"><i class="fa-solid fa-book-open"></i><h3>Sổ quỹ chưa có dòng nào</h3><p>Bán thu tiền mặt, hoàn tiền trả hàng hay ghi tay một khoản thu chi là dòng hiện ở đây.</p></div>
                        @else
                            <div class="ca-led-tools">
                                <div class="seg" role="group" aria-label="Chiều tiền">
                                    <button type="button" aria-pressed="true" data-dir="">Tất cả ({{ count($soQuy) }})</button>
                                    <button type="button" aria-pressed="false" data-dir="in">Thu ({{ $nIn }})</button>
                                    <button type="button" aria-pressed="false" data-dir="out">Chi ({{ count($soQuy) - $nIn }})</button>
                                </div>
                                <label class="sr-only" for="caSrc">Nguồn</label>
                                <select class="select" id="caSrc" data-src>
                                    <option value="">Mọi nguồn</option>
                                    @foreach($C::SOURCES as $k => $nhan)
                                        <option value="{{ $k }}">{{ $nhan }}</option>
                                    @endforeach
                                </select>
                                <label class="search" for="caQ"><i class="fa-solid fa-magnifying-glass search__icon" aria-hidden="true"></i><span class="sr-only">Tìm trong sổ</span><input id="caQ" type="search" autocomplete="off" placeholder="Mã chứng từ, lý do, người ghi…" data-led-q></label>
                            </div>
                            <div class="ca-led-wrap">
                                <table class="ca-led">
                                    <thead><tr><th scope="col">Giờ</th><th scope="col">Chứng từ</th><th scope="col">Nguồn</th><th scope="col" class="r">Thu</th><th scope="col" class="r">Chi</th><th scope="col" class="r" title="Tiền trong két ngay sau dòng này">Tồn két</th><th scope="col">Nội dung</th><th scope="col" class="col-by">Người ghi</th></tr></thead>
                                    <tbody>
                                        @foreach($dongSo as $e)
                                            @php
                                                $vao = ($e['direction'] ?? '') === 'in';
                                                $loai = $e['reference_type'] ?? 'manual';
                                                [$icon, $cls] = $nguon[$loai] ?? ['fa-circle', ''];
                                                $ma = (string) ($e['reference_code'] ?? '');
                                                $ai = (string) ($e['created_by_name'] ?? '');
                                            @endphp
                                            <tr data-dir="{{ $vao ? 'in' : 'out' }}" data-src="{{ $loai }}" data-amt="{{ (float) $e['amount'] }}" data-q="{{ mb_strtolower($ma.' '.($e['reason'] ?? '').' '.$ai) }}">
                                                <td class="mono">{{ $gio($e['created_at']) }}</td>
                                                <td class="doc">
                                                    @if($ma !== '' && $loai === 'order')
                                                        <a href="{{ route('thu-ngan.don-hang.index', ['q' => $ma]) }}" title="Mở đơn trong Lịch sử">{{ $ma }}</a>
                                                    @elseif($ma !== '')
                                                        {{ $ma }}
                                                    @else
                                                        <span class="muted">—</span>
                                                    @endif
                                                </td>
                                                <td><span class="ca-src {{ $cls }}"><i class="fa-solid {{ $icon }}"></i>{{ $C::SOURCES[$loai] ?? $loai }}</span></td>
                                                <td class="r">@if($vao)<span class="in">+{{ $so($e['amount']) }}</span>@endif</td>
                                                <td class="r">@unless($vao)<span class="out">−{{ $so($e['amount']) }}</span>@endunless</td>
                                                <td class="r bal">{{ $so($e['ton']) }}</td>
                                                <td>{{ $e['reason'] ?? '' }}@if(! empty($e['attached_at']))<small class="ca-gan-sau" title="Khoản ngoài ca, gắn vào ca sau">Gắn sau · {{ $e['attached_by_name'] ?? '' }} · {{ $gio($e['attached_at']) }}</small>@endif</td>
                                                <td class="col-by">@if($ai !== ''){{ $ai }}@else<span class="muted" title="Dòng sinh tự động — người chịu trách nhiệm là người trực ca">Tự động</span>@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot><tr><td colspan="3" data-sum-n>Cộng {{ count($soQuy) }} dòng</td><td class="r in" data-sum-in></td><td class="r out" data-sum-out></td><td class="r bal" data-sum-bal>{{ $so($ton) }}</td><td colspan="2"></td></tr></tfoot>
                                </table>
                            </div>
                            <ul class="ca-led-cards">
                                @foreach($dongSo as $e)
                                    @php $vao = ($e['direction'] ?? '') === 'in'; $loai = $e['reference_type'] ?? 'manual'; [$icon, $cls] = $nguon[$loai] ?? ['fa-circle', '']; $ma = (string) ($e['reference_code'] ?? ''); $ai = (string) ($e['created_by_name'] ?? ''); @endphp
                                    <li data-dir="{{ $vao ? 'in' : 'out' }}" data-src="{{ $loai }}" data-q="{{ mb_strtolower($ma.' '.($e['reason'] ?? '').' '.$ai) }}">
                                        <span class="t">{{ $gio($e['created_at']) }}@if($ma !== '') · <b>{{ $ma }}</b>@endif</span>
                                        <span class="amt {{ $vao ? 'in' : 'out' }}">{{ $vao ? '+' : '−' }}{{ $so($e['amount']) }}</span>
                                        <span><span class="ca-src {{ $cls }}"><i class="fa-solid {{ $icon }}"></i>{{ $C::SOURCES[$loai] ?? $loai }}</span></span>
                                        <span class="bal">két {{ $so($e['ton']) }}</span>
                                        <span class="why">{{ $e['reason'] ?? '' }}@if($ai !== '') · {{ $ai }}@endif @if(! empty($e['attached_at']))<small class="ca-gan-sau">Gắn sau · {{ $e['attached_by_name'] ?? '' }} · {{ $gio($e['attached_at']) }}</small>@endif</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="ca-led-sum"><span>Thu <span style="color:var(--c-success)" data-sum-in></span></span><span>Chi <span style="color:var(--c-danger)" data-sum-out></span></span></div>
                            <div class="ca-blind" data-led-empty hidden><i class="fa-solid fa-filter-circle-xmark"></i><h3>Không có dòng nào khớp</h3><p><button type="button" class="link-btn" data-led-clear>Bỏ lọc</button></p></div>
                        @endif
                    </div>

                    <div role="tabpanel" id="panel-dt" aria-labelledby="tab-dt" hidden>
                        @php
                            $hinhThuc = [
                                ['fa-money-bill-wave', 'Tiền mặt', (float) ($dt['total_cash'] ?? 0)],
                                ['fa-building-columns', 'Chuyển khoản', (float) ($dt['total_transfer'] ?? 0)],
                                ['fa-credit-card', 'Quẹt thẻ', (float) ($dt['total_card'] ?? 0)],
                                ['fa-qrcode', 'QR tự động', (float) ($dt['total_auto_qr'] ?? 0)],
                            ];
                            $tongDt = (float) ($dt['total_revenue'] ?? 0);
                        @endphp
                        <h3 class="ca-sec">Bán hàng trong ca</h3>
                        <div class="ca-kpis">
                            <div class="ca-kpi is-main"><span>Doanh thu đơn quầy</span><b>{{ $vnd($tongDt) }}</b></div>
                            <div class="ca-kpi"><span>Số đơn</span><b>{{ (int) ($dt['order_count'] ?? 0) }}</b></div>
                            <div class="ca-kpi"><span>Lượt bán thu tiền mặt</span><b>{{ (int) ($ca['so_don_tien_mat'] ?? 0) }}</b></div>
                        </div>
                        <h3 class="ca-sec">Theo hình thức thanh toán</h3>
                        <ul class="ca-pm">
                            @foreach($hinhThuc as [$ic, $nhan, $v])
                                <li><span class="ca-pm__ic"><i class="fa-solid {{ $ic }}"></i></span><b>{{ $nhan }}</b><span class="bar"><i style="width: {{ $tongDt > 0 ? round($v / $tongDt * 100) : 0 }}%"></i></span><span class="num">{{ $vnd($v) }}</span></li>
                            @endforeach
                            @php $khac = $tongDt - collect($hinhThuc)->sum(fn ($h) => $h[2]); @endphp
                            @if(abs($khac) >= 0.5)
                                <li><span class="ca-pm__ic"><i class="fa-solid fa-ellipsis"></i></span><b>Khác / kết hợp<small>Đơn trả nhiều hình thức</small></b><span class="bar"><i style="width: {{ $tongDt > 0 ? round($khac / $tongDt * 100) : 0 }}%"></i></span><span class="num">{{ $vnd($khac) }}</span></li>
                            @endif
                            <li class="is-total"><span></span><b>Cộng</b><span class="bar" style="background:none"></span><span class="num">{{ $vnd($tongDt) }}</span></li>
                        </ul>
                        <p class="ca-why" style="margin-top:var(--s-3)"><i class="fa-solid fa-circle-info"></i>Đơn thuộc ca = đơn quầy của chi nhánh, tạo trong giờ ca, đã thu đủ, chưa huỷ — cùng quy ước Báo cáo kết ca. Tiền mặt ở đây là tiền BÁN; két còn chịu hoàn tiền, thu chi, ghi tay (xem Sổ quỹ).</p>
                    </div>

                    <div role="tabpanel" id="panel-tt" aria-labelledby="tab-tt" hidden>
                        <div class="ca-info">
                            <section>
                                <h3><i class="fa-solid fa-lock-open"></i> Mở ca</h3>
                                <dl>
                                    <div><dt>Người trực két</dt><dd>{{ $tenTruc($ca) }}</dd></div>
                                    <div><dt>Người mở máy</dt><dd>{{ $ca['opened_by_name'] ?? '' }}</dd></div>
                                    <div><dt>Lúc</dt><dd class="mono">{{ $gio($ca['opened_at']) }} · {{ $ngay($ca['opened_at']) }}</dd></div>
                                    <div><dt>Tiền đầu ca (đếm)</dt><dd class="mono">{{ $vnd($ca['opening_cash'] ?? 0) }}</dd></div>
                                </dl>
                                @include('v2::pos._bang-dem', ['ds' => $ca['opening_count'] ?? null, 'nhan' => 'Đếm theo tờ lúc mở'])
                                <p class="ca-note {{ ($ca['note'] ?? '') === '' ? 'is-empty' : '' }}">{{ ($ca['note'] ?? '') !== '' ? $ca['note'] : 'Không ghi chú' }}</p>
                            </section>
                            <section>
                                <h3><i class="fa-solid fa-lock"></i> Đóng ca</h3>
                                @if($dangMo)
                                    <p class="ca-note is-empty">Ca đang mở · {{ $thoiLuong($ca['opened_at'], null) }}.</p>
                                @else
                                    <dl>
                                        <div><dt>Người đóng</dt><dd>{{ $ca['closed_by_name'] ?? '' }}</dd></div>
                                        <div><dt>Lúc</dt><dd class="mono">{{ $gio($ca['closed_at']) }} · {{ $ngay($ca['closed_at']) }}</dd></div>
                                        <div><dt>Thời lượng</dt><dd>{{ $thoiLuong($ca['opened_at'], $ca['closed_at']) }}</dd></div>
                                        <div><dt>Tiền theo sổ lúc đóng</dt><dd class="mono">{{ $vnd($book) }}</dd></div>
                                        <div><dt>Đếm được</dt><dd class="mono">{{ $vnd($ca['counted_cash'] ?? 0) }}</dd></div>
                                        @if(isset($ca['delivered_cash']))
                                            <div><dt>Giao về chủ</dt><dd class="mono">{{ $vnd($ca['delivered_cash']) }}</dd></div>
                                            <div><dt>Giữ lại cho ca sau</dt><dd class="mono">{{ $vnd($ca['kept_cash'] ?? 0) }}</dd></div>
                                        @endif
                                    </dl>
                                    @include('v2::pos._bang-dem', ['ds' => $ca['counted_count'] ?? null, 'nhan' => 'Đếm theo tờ lúc đóng'])
                                    <p class="ca-note {{ ($ca['close_note'] ?? '') === '' ? 'is-empty' : '' }}">{{ ($ca['close_note'] ?? '') !== '' ? $ca['close_note'] : 'Không ghi chú' }}</p>
                                @endif
                            </section>
                        </div>
                    </div>
                </div>

                <div class="ca-d-foot">
                    @if($dangMo)
                        <div class="ca-d-actions">
                            <button type="button" class="btn btn--outline" data-act="cash"><i class="fa-solid fa-right-left"></i> Thu / chi tiền mặt</button>
                            <a class="btn btn--outline" href="{{ route('thu-ngan.ca-lam-viec.phieu', ['id' => $ca['id'], 'tam' => 1]) }}" target="_blank" rel="noopener" title="Phiếu tạm: số liệu tới giờ, chưa chốt"><i class="fa-solid fa-print"></i> In phiếu tạm</a>
                            <button type="button" class="btn btn--primary" data-act="close"><i class="fa-solid fa-lock"></i> Đóng ca</button>
                        </div>
                    @else
                        <div class="ca-d-actions">
                            <a class="btn btn--outline" href="{{ route('thu-ngan.don-hang.index') }}"><i class="fa-solid fa-clock-rotate-left"></i> Lịch sử đơn</a>
                            <a class="btn btn--primary" href="{{ route('thu-ngan.ca-lam-viec.phieu', $ca['id']) }}" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i> In phiếu kết ca</a>
                        </div>
                        <p class="ca-why"><i class="fa-solid fa-lock"></i>Ca đã chốt: tiền theo sổ, tiền đếm được và chênh lệch giữ nguyên như lúc đóng.</p>
                    @endif
                </div>
            @endif
        </div>
    </section>
@endsection

@push('modals')
    {{-- Mở ca --}}
    <dialog class="modal" id="caMo" aria-labelledby="caMoT">
        <form method="dialog" data-mo-form novalidate>
            <div class="modal__head"><h2 id="caMoT">Mở ca</h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body ca-form">
                <div class="ca-box">
                    @if($tenCn !== '')<div class="ca-kv"><span>Chi nhánh</span><b>{{ $tenCn }}</b></div>@endif
                    <div class="ca-kv"><span>Người mở máy</span><b>{{ $toi }}</b></div>
                </div>
                @include('v2::pos._mo-ca-nguoi-gio', ['p' => 'caMo', 'truc' => $truc ?? ['ds' => [], 'macDinh' => null]])
                <div class="field">
                    <label for="caMoTien">Tiền đang có trong két *</label>
                    <div class="ca-money-wrap"><input class="input num ca-money" id="caMoTien" inputmode="numeric" autocomplete="off" placeholder="0" data-mo-tien></div>
                    <span class="ca-err" data-mo-loi hidden></span>
                    <span class="hint" data-mo-goi-y></span>
                </div>
                <button type="button" class="link-btn ca-count-toggle" data-denom-toggle="caMoTo" aria-expanded="false"><i class="fa-solid fa-money-bills"></i> Đếm theo mệnh giá</button>
                <div class="ca-denoms" id="caMoTo" data-denoms="caMoTien" hidden></div>
                <div class="field">
                    <label for="caMoGhi">Ghi chú</label>
                    <input class="input" id="caMoGhi" maxlength="500" autocomplete="off" placeholder="Ví dụ: nhận két từ ca tối, chủ đã rút 5 triệu" data-mo-ghi>
                </div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
                <button type="submit" class="btn btn--primary" data-mo-ok>Mở ca</button>
            </div>
        </form>
    </dialog>

    {{-- Đóng ca: đếm → xác nhận khi lệch → kết quả, trong một hộp --}}
    <dialog class="modal modal--wide" id="caDong" aria-labelledby="caDongT">
        <form method="dialog" data-dong-form novalidate>
            <div class="modal__head"><h2 id="caDongT">Đóng ca <span data-dong-id></span></h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>

            <div class="modal__body ca-form" data-step="count">
                <div class="ca-box">
                    <div class="ca-kv"><span>Người trực</span><b data-dong-ai></b></div>
                    <div class="ca-kv"><span>Mở lúc</span><span class="num" data-dong-mo></span></div>
                    <div class="ca-kv"><span>Đóng lúc</span><span class="num" data-dong-luc></span></div>
                </div>
                <div class="field">
                    <label for="caDongTien">Tiền đếm được trong két *</label>
                    <div class="ca-money-wrap"><input class="input num ca-money" id="caDongTien" inputmode="numeric" autocomplete="off" placeholder="0" data-dong-tien></div>
                    <span class="ca-err" data-dong-loi hidden></span>
                    <span class="hint">Đếm cả tiền lẻ để thối. Chỉ đếm tiền mặt — chuyển khoản, quẹt thẻ, QR không đi qua két.</span>
                </div>
                <button type="button" class="link-btn ca-count-toggle" data-denom-toggle="caDongTo" aria-expanded="false"><i class="fa-solid fa-money-bills"></i> Đếm theo mệnh giá</button>
                <div class="ca-denoms" id="caDongTo" data-denoms="caDongTien" hidden></div>
                <div class="ca-live" data-dong-song></div>
                <div class="ca-ban-giao">
                    <div class="field">
                        <label for="caDongGiu">Giữ lại cho ca sau</label>
                        <div class="ca-money-wrap"><input class="input num ca-money" id="caDongGiu" inputmode="numeric" autocomplete="off" placeholder="0" data-dong-giu></div>
                    </div>
                    <div class="field">
                        <label for="caDongGiao">Giao về chủ</label>
                        <div class="ca-money-wrap"><input class="input num ca-money" id="caDongGiao" inputmode="numeric" autocomplete="off" placeholder="0" data-dong-giao></div>
                    </div>
                    <span class="hint ca-ban-giao__goi-y">Mặc định giao hết. Nhập số giữ lại (tiền lẻ để thối) thì phần giao tự tính; hai ô cộng lại phải bằng tiền đếm được.</span>
                    <span class="ca-err" data-giao-loi hidden></span>
                </div>
                <div class="field">
                    <label for="caDongGhi" data-dong-ghi-nhan>Ghi chú đóng ca</label>
                    <textarea class="input" id="caDongGhi" rows="2" maxlength="500" placeholder="Ví dụ: bàn giao két cho ca tối, đã nộp 5 triệu về chủ" data-dong-ghi></textarea>
                    <span class="ca-count-hint"><span data-dong-dem>0</span>/500</span>
                </div>
                <label class="ca-check"><input type="checkbox" checked data-dong-in> In phiếu kết ca sau khi đóng</label>
            </div>

            <div class="modal__body ca-form" data-step="confirm" hidden>
                <div class="ca-warnbox" data-xn-hop></div>
                <div class="ca-box">
                    <div class="ca-kv"><span>Tiền theo sổ</span><span class="num" data-xn-so></span></div>
                    <div class="ca-kv"><span>Tiền đếm được</span><span class="num" data-xn-dem></span></div>
                    <div class="ca-kv"><span>Giao về chủ · giữ lại</span><span class="num" data-xn-giao></span></div>
                    <div class="ca-kv"><span>Lý do</span><span data-xn-ghi></span></div>
                </div>
                <p class="ca-why" style="font-size:var(--fs-sm)"><i class="fa-solid fa-lock"></i>Đóng xong, ba con số này được chốt và không sửa được — kể cả khi sổ quỹ ghi thêm sau đó.</p>
            </div>

            <div class="modal__body ca-form" data-step="done" hidden>
                <div class="ca-done">
                    <span class="ca-done__ic"><i class="fa-solid fa-check"></i></span>
                    <h3 data-xong-tieu-de></h3>
                    <div class="ca-box" data-xong-hop></div>
                </div>
                <div data-xong-ngoai></div>
            </div>

            <div class="modal__foot" data-foot="count">
                <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
                <button type="submit" class="btn btn--primary" data-dong-ok>Đóng ca</button>
            </div>
            <div class="modal__foot" data-foot="confirm" hidden>
                <button type="button" class="btn btn--ghost" data-dem-lai><i class="fa-solid fa-arrow-left"></i> Đếm lại</button>
                <button type="button" class="btn btn--danger" data-xn-ok>Vẫn đóng ca</button>
            </div>
            <div class="modal__foot" data-foot="done" hidden>
                <a class="btn btn--outline" href="#" target="_blank" rel="noopener" data-xong-in><i class="fa-solid fa-print"></i> In phiếu kết ca</a>
                <span class="spacer"></span>
                <button type="button" class="btn btn--ghost" data-xong-dong>Xong</button>
                <button type="button" class="btn btn--primary" data-xong-mo><i class="fa-solid fa-lock-open"></i> Mở ca mới</button>
            </div>
        </form>
    </dialog>

    {{-- Thu / chi tiền mặt --}}
    <dialog class="modal" id="caThuChi" aria-labelledby="caThuChiT">
        <form method="dialog" data-tc-form novalidate>
            <div class="modal__head"><h2 id="caThuChiT">Thu / chi tiền mặt</h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body ca-form">
                <div class="ca-dir" role="group" aria-label="Chiều tiền" data-tc-chieu>
                    <button type="button" aria-pressed="false" data-v="in"><i class="fa-solid fa-arrow-down"></i>Thu vào két</button>
                    <button type="button" aria-pressed="true" data-v="out"><i class="fa-solid fa-arrow-up"></i>Chi ra khỏi két</button>
                </div>
                <div class="field">
                    <label for="caTcTien">Số tiền *</label>
                    <div class="ca-money-wrap"><input class="input num ca-money" id="caTcTien" inputmode="numeric" autocomplete="off" placeholder="0" data-tc-tien></div>
                    <span class="ca-err" data-tc-loi hidden></span>
                    <span class="hint" data-tc-sau></span>
                </div>
                <div class="field">
                    <label for="caTcLyDo">Lý do *</label>
                    <input class="input" id="caTcLyDo" maxlength="255" autocomplete="off" data-tc-ly-do>
                    <div class="ca-reasons" data-tc-goi-y></div>
                    <span class="ca-err" data-tc-ly-do-loi hidden>Ghi lý do — một khoản ra khỏi két không có lý do thì đúng bằng mất tiền.</span>
                </div>
                <div class="ca-alert ca-alert--info"><i class="fa-solid fa-circle-info"></i><span>Ghi vào sổ quỹ của ca đang mở. Cần phiếu thu/chi chính thức (đối tượng, loại thu chi) thì lập ở khu quản trị → <b>Thu chi</b> — phiếu tiền mặt ở đó cũng tự vào sổ ca.</span></div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
                <button type="submit" class="btn btn--primary" data-tc-ok>Ghi khoản chi</button>
            </div>
        </form>
    </dialog>

    {{-- Tiền mặt ngoài ca hôm nay --}}
    <dialog class="modal" id="caNgoai" aria-labelledby="caNgoaiT">
        <div class="modal__head"><h2 id="caNgoaiT">Tiền mặt ngoài ca</h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body ca-form">
            <p style="margin:0">Các khoản dưới đây đã vào / ra két thật nhưng lúc đó <b>chưa ai mở ca</b>, nên không nằm trong con số đối chiếu của ca nào. Người mở ca kế tiếp nên đếm két có tính cả các khoản này.</p>
            @php
                // Gắn được khi: có ca đang mở, người xem có quyền so-quy.sua, và khoản phát
                // sinh SAU giờ đóng ca trước (trước đó là trách nhiệm của ca trước).
                $moc = ($ganNgoai['tu'] ?? null) ? \Illuminate\Support\Carbon::parse($ganNgoai['tu']) : null;
                $ganDuoc = fn ($e) => $hienTai && ($ganNgoai['co'] ?? false) && (! $moc || \Illuminate\Support\Carbon::parse($e['created_at'])->gt($moc));
                $soGanDuoc = collect($ngoaiCa)->filter($ganDuoc)->count();
            @endphp
            <ul class="ca-outside">
                @foreach($ngoaiCa as $e)
                    @php $vao = ($e['direction'] ?? '') === 'in'; @endphp
                    <li>
                        <span class="t">{{ $gio($e['created_at']) }}</span>
                        <span>{{ $e['reason'] ?? '' }}<small>{{ $C::SOURCES[$e['reference_type'] ?? ''] ?? '' }}@if(($e['reference_code'] ?? '') !== '') · {{ $e['reference_code'] }}@endif</small>
                            @if($hienTai && ($ganNgoai['co'] ?? false) && ! $ganDuoc($e))<small class="ca-gan-khong">Trước khi ca trước đóng — thuộc ca trước, không gắn được</small>@endif</span>
                        <span class="{{ $vao ? 'in' : 'out' }}">{{ $vao ? '+' : '−' }}{{ $vnd($e['amount']) }}</span>
                        @if($ganDuoc($e))
                            <button type="button" class="btn btn--outline btn--sm" data-gan-ngoai="{{ $e['id'] }}">Gắn vào ca #{{ $hienTai['id'] }}</button>
                        @endif
                    </li>
                @endforeach
            </ul>
            @if($hienTai && ! ($ganNgoai['co'] ?? false) && $ngoaiCa)
                <p class="hint" style="margin:0">Cần quyền <b>Sổ quỹ · Sửa</b> để gắn các khoản này vào ca đang mở.</p>
            @elseif(! $hienTai && $ngoaiCa)
                <p class="hint" style="margin:0">Mở ca rồi mới gắn được các khoản này vào ca.</p>
            @endif
        </div>
        <div class="modal__foot">
            @if($soGanDuoc > 1)
                <button type="button" class="btn btn--outline" data-gan-ngoai="{{ collect($ngoaiCa)->filter($ganDuoc)->pluck('id')->implode(',') }}">Gắn tất cả ({{ $soGanDuoc }}) vào ca #{{ $hienTai['id'] }}</button>
            @endif
            <button type="button" class="btn btn--primary" data-tn-close>Đã hiểu</button>
        </div>
    </dialog>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('v2/css/pos-v3/shift.css') }}?v={{ filemtime(public_path('v2/css/pos-v3/shift.css')) }}">
@endpush

@push('scripts')
<script>
(function () {
    const CA = @json($caJs);
    const $ = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => [...r.querySelectorAll(s)];
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const so = (n) => Math.round(n).toLocaleString('vi-VN');
    const vnd = (n) => `${so(n)} đ`;
    const vndKet = (n) => (+n < 0 ? `<span class="ket-am">${vnd(n)} · két âm</span>` : vnd(n));
    const sgn = (n) => (n > 0 ? `+${vnd(n)}` : n < 0 ? `−${vnd(-n)}` : vnd(0));
    const pad = (n) => String(n).padStart(2, '0');
    const gioNgay = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())} · ${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
    const docTien = (s) => Number(String(s).replace(/\D/g, '')) || 0;
    // Giữ số 0 gõ vào: két trống mở / đóng ca phải nhập được 0, xoá trắng là bị chặn "chưa nhập".
    const dinhDang = (el) => { const v = docTien(el.value); el.value = /\d/.test(el.value) ? so(v) : ''; return v; };
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const MENH_GIA = [500000, 200000, 100000, 50000, 20000, 10000, 5000, 2000, 1000];
    const GOI_Y = {
        out: ['Nộp tiền về chủ', 'Mua đồ dùng cho quầy', 'Tạm ứng nhân viên', 'Trả tiền ship'],
        in: ['Bổ sung tiền lẻ', 'Chủ đưa thêm tiền két'],
    };

    async function goi(url, body) {
        const res = await fetch(url, {
            method: body ? 'POST' : 'GET',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: body ? JSON.stringify(body) : undefined,
        });
        let json = {};
        try { json = await res.json(); } catch (e) { /* trả không phải JSON */ }
        if (!res.ok) {
            const loi = new Error(json.message || (res.status === 419 ? 'Phiên làm việc đã hết hạn, tải lại trang.' : 'Không gọi được máy chủ, thử lại.'));
            loi.status = res.status;
            loi.ma = json.errors?.ma || '';
            throw loi;
        }
        return json.data;
    }
    const moHop = (d) => { $$('dialog[open]').forEach((x) => { if (x !== d) x.close(); }); if (!d.open) d.showModal(); };
    const veTrang = (them) => { const u = new URL(CA.url.trang); Object.entries(them || {}).forEach(([k, v]) => u.searchParams.set(k, v)); location.href = u; };

    /* ---------------- Liên kết bị khoá, phân trang, lọc người trực, kỳ tuỳ chọn */
    document.addEventListener('click', (e) => {
        const khoa = e.target.closest('[aria-disabled="true"][data-khoa]');
        if (khoa) { e.preventDefault(); posToast(khoa.dataset.khoa); }
    });
    $('[data-tu-gui]')?.addEventListener('change', (e) => e.target.form.submit());
    $('[data-tuy-chon]')?.addEventListener('click', (e) => {
        $$('.ca-hist .seg [aria-pressed]').forEach((b) => b.setAttribute('aria-pressed', String(b === e.currentTarget)));
        const f = $('[data-range]');
        f.hidden = false;
        $('input[type=date]', f).focus();
    });

    /* ---------------- Màn hẹp: tờ ca phủ màn khi vừa chọn một ca */
    const toCa = $('[data-detail]');
    if (toCa.classList.contains('is-chon') && matchMedia('(max-width: 899px)').matches) toCa.classList.add('is-open');
    $('[data-back]')?.addEventListener('click', () => toCa.classList.remove('is-open'));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('dialog[open]')) toCa.classList.remove('is-open'); });

    /* ---------------- Tab */
    $$('.ca-tabs [data-tab]').forEach((b) => b.addEventListener('click', () => {
        $$('.ca-tabs [data-tab]').forEach((x) => {
            x.setAttribute('aria-selected', String(x === b));
            $(`#panel-${x.dataset.tab}`).hidden = x !== b;
        });
    }));

    /* ---------------- Lọc sổ quỹ tại chỗ */
    const loc = { dir: '', src: '', q: '' };
    function locSo() {
        const hop = (el) => (!loc.dir || el.dataset.dir === loc.dir) && (!loc.src || el.dataset.src === loc.src) && (!loc.q || el.dataset.q.includes(loc.q));
        let thu = 0; let chi = 0; let n = 0;
        $$('.ca-led tbody tr').forEach((tr) => {
            const ok = hop(tr);
            tr.hidden = !ok;
            if (!ok) return;
            n += 1;
            if (tr.dataset.dir === 'in') thu += +tr.dataset.amt; else chi += +tr.dataset.amt;
        });
        $$('.ca-led-cards li').forEach((li) => { li.hidden = !hop(li); });
        const dangLoc = loc.dir || loc.src || loc.q;
        $$('[data-sum-in]').forEach((x) => { x.textContent = `+${so(thu)}`; });
        $$('[data-sum-out]').forEach((x) => { x.textContent = `−${so(chi)}`; });
        if ($('[data-sum-n]')) $('[data-sum-n]').textContent = dangLoc ? `Cộng ${n} dòng đang lọc` : `Cộng ${n} dòng`;
        if ($('[data-sum-bal]')) $('[data-sum-bal]').style.visibility = dangLoc ? 'hidden' : '';
        const rong = n === 0;
        if ($('[data-led-empty]')) {
            $('[data-led-empty]').hidden = !rong;
            $('.ca-led-wrap').style.display = rong ? 'none' : '';
            $('.ca-led-cards').style.visibility = rong ? 'hidden' : '';
        }
    }
    $$('.ca-led-tools [data-dir]').forEach((b) => b.addEventListener('click', () => {
        loc.dir = b.dataset.dir;
        $$('.ca-led-tools [data-dir]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
        locSo();
    }));
    $('select[data-src]')?.addEventListener('change', (e) => { loc.src = e.target.value; locSo(); });
    let hen;
    $('[data-led-q]')?.addEventListener('input', (e) => { clearTimeout(hen); hen = setTimeout(() => { loc.q = e.target.value.trim().toLowerCase(); locSo(); }, 200); });
    $('[data-led-clear]')?.addEventListener('click', () => {
        Object.assign(loc, { dir: '', src: '', q: '' });
        $('select[data-src]').value = '';
        $('[data-led-q]').value = '';
        $$('.ca-led-tools [data-dir]').forEach((x) => x.setAttribute('aria-pressed', String(x.dataset.dir === '')));
        locSo();
    });
    if ($('.ca-led')) locSo();

    /* ---------------- Đếm theo mệnh giá (dùng chung hộp Mở ca / Đóng ca) */
    $$('[data-denoms]').forEach((box) => {
        box.innerHTML = `${MENH_GIA.map((d) => `<div class="ca-denom"><label for="${box.id}-${d}">${so(d)}</label><input class="input" id="${box.id}-${d}" inputmode="numeric" autocomplete="off" placeholder="0" data-d="${d}" aria-label="Số tờ ${so(d)}"><output data-o="${d}">0</output></div>`).join('')}<div class="ca-denoms__sum"><span>Cộng theo tờ</span><span class="num" data-dsum>0 đ</span></div>`;
        box.addEventListener('input', () => congTo(box));
    });
    function congTo(box) {
        const t = $$('[data-d]', box).reduce((acc, el) => {
            const n = docTien(el.value);
            el.value = n ? String(n) : '';
            $(`[data-o="${el.dataset.d}"]`, box).textContent = so(n * el.dataset.d);
            return acc + n * el.dataset.d;
        }, 0);
        $('[data-dsum]', box).textContent = vnd(t);
        const o = document.getElementById(box.dataset.denoms);
        o.value = t ? so(t) : '';
        o.dispatchEvent(new Event('input', { bubbles: true }));
    }
    // Bảng đếm gửi kèm mở / đóng ca để lưu vào tờ ca; chỉ các mệnh giá có tờ.
    const bangDem = (id) => $$('[data-d]', document.getElementById(id))
        .map((el) => ({ menh_gia: +el.dataset.d, so_to: docTien(el.value) }))
        .filter((d) => d.so_to > 0);
    function lechDem(ds, tien) {
        const t = ds.reduce((a, d) => a + d.menh_gia * d.so_to, 0);
        return ds.length && t !== tien ? `Bảng đếm cộng ra ${vnd(t)}, khác số đã nhập ${vnd(tien)} — sửa một trong hai.` : '';
    }
    function xoaTo(id) {
        const box = document.getElementById(id);
        $$('[data-d]', box).forEach((el) => { el.value = ''; });
        congTo(box);
        box.hidden = true;
        $(`[data-denom-toggle="${id}"]`).setAttribute('aria-expanded', 'false');
    }
    $$('[data-denom-toggle]').forEach((b) => b.addEventListener('click', () => {
        const box = document.getElementById(b.dataset.denomToggle);
        box.hidden = !box.hidden;
        b.setAttribute('aria-expanded', String(!box.hidden));
        if (!box.hidden) $('[data-d]', box).focus();
    }));

    /* ---------------- Gắn tiền ngoài ca vào ca đang mở */
    $$('[data-gan-ngoai]').forEach((b) => b.addEventListener('click', async () => {
        const ids = b.dataset.ganNgoai.split(',').map(Number).filter(Boolean);
        b.disabled = true;
        try {
            await goi(CA.url.ganNgoai, { ids });
            posToast(`Đã gắn ${ids.length} khoản vào ca đang mở.`);
            veTrang();
        } catch (err) {
            b.disabled = false;
            posToast(err.message, 'error');
        }
    }));

    /* ---------------- Mở ca */
    const hopMo = $('#caMo');
    function moHopMo() {
        window.gioMoCa.dat(hopMo);
        $('[data-mo-tien]').value = '';
        $('[data-mo-ghi]').value = '';
        $('[data-mo-loi]').hidden = true;
        $('[data-mo-ok]').disabled = false;
        xoaTo('caMoTo');
        $('[data-mo-goi-y]').innerHTML = CA.caTruoc
            ? `Ca trước (#${CA.caTruoc.id} · ${esc(CA.caTruoc.ai)}) ${CA.caTruoc.coGiu ? 'giữ lại trong két' : 'bàn giao'} <b>${vnd(CA.caTruoc.banGiao)}</b>. Đếm lại két thật — không lấy số này thay cho đếm.`
            : 'Đếm cả tiền lẻ để thối.';
        moHop(hopMo);
        $('[data-mo-tien]').focus();
    }
    $('[data-mo-tien]').addEventListener('input', (e) => {
        const v = dinhDang(e.target);
        e.target.removeAttribute('aria-invalid');
        const loi = $('[data-mo-loi]');
        if (CA.caTruoc && e.target.value && v !== CA.caTruoc.banGiao) {
            loi.hidden = false;
            loi.style.color = '#9a4f00';
            loi.textContent = `Khác số ca trước bàn giao ${sgn(v - CA.caTruoc.banGiao)} — ghi chú lý do (ví dụ: chủ rút tiền về).`;
        } else loi.hidden = true;
    });
    $('[data-mo-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const o = $('[data-mo-tien]');
        const loi = $('[data-mo-loi]');
        if (!o.value.trim()) {
            loi.hidden = false; loi.style.color = ''; loi.textContent = 'Đếm và nhập số tiền đang có trong két (két trống thì nhập 0).';
            o.setAttribute('aria-invalid', 'true'); o.focus();
            return;
        }
        const them = window.gioMoCa.doc(hopMo);
        if (them.loi) { loi.hidden = false; loi.style.color = ''; loi.textContent = them.loi; $('[data-mo-gio]', hopMo).focus(); return; }
        const dem = bangDem('caMoTo');
        const lech = lechDem(dem, docTien(o.value));
        if (lech) { loi.hidden = false; loi.style.color = ''; loi.textContent = lech; o.focus(); return; }
        $('[data-mo-ok]').disabled = true;
        try {
            await goi(CA.url.mo, { opening_cash: docTien(o.value), note: $('[data-mo-ghi]').value.trim(), ...them.du, ...(dem.length ? { opening_count: dem } : {}) });
            veTrang();
        } catch (err) {
            $('[data-mo-ok]').disabled = false;
            if (err.status === 409) { hopMo.close(); posToast(err.message, 'error'); setTimeout(() => veTrang(), 1800); return; }
            loi.hidden = false; loi.style.color = ''; loi.textContent = err.message;
        }
    });

    /* ---------------- Đóng ca */
    const hopDong = $('#caDong');
    let soCa = null; // ca đang mở, đọc lại MỖI lần mở hộp: trong lúc màn để yên quầy vẫn bán
    let cho = null;
    const buoc = (b) => {
        $$('[data-step]', hopDong).forEach((x) => { x.hidden = x.dataset.step !== b; });
        $$('[data-foot]', hopDong).forEach((x) => { x.hidden = x.dataset.foot !== b; });
    };
    const theoSo = () => soCa.opening_cash + soCa.tong_thu - soCa.tong_chi;
    function veSong() {
        const el = $('[data-dong-tien]');
        const v = el.value ? docTien(el.value) : null;
        const lech = v == null ? null : v - theoSo();
        const lop = lech == null ? '' : lech === 0 ? 'is-ok' : lech < 0 ? 'is-short' : 'is-over';
        $('[data-dong-song]').innerHTML = `
            <div class="ca-kv"><span>Tiền đầu ca</span><span class="num">${vnd(soCa.opening_cash)}</span></div>
            <div class="ca-kv"><span>+ Thu tiền mặt</span><span class="num" style="color:var(--c-success)">${vnd(soCa.tong_thu)}</span></div>
            <div class="ca-kv"><span>− Chi tiền mặt</span><span class="num" style="color:var(--c-danger)">${vnd(soCa.tong_chi)}</span></div>
            <div class="ca-kv"><span><b>= Tiền theo sổ</b></span><span class="num">${vndKet(theoSo())}</span></div>
            <div class="ca-kv is-diff ${lop}"><span><b>Chênh lệch</b></span><span class="num">${lech == null ? '—' : lech === 0 ? 'Khớp' : `${sgn(lech)} (${lech < 0 ? 'thiếu' : 'dư'})`}</span></div>`;
        $('[data-dong-ghi-nhan]').textContent = lech ? 'Lý do chênh lệch *' : 'Ghi chú đóng ca';
    }
    async function moHopDong() {
        try {
            soCa = await goi(CA.url.hienTai);
        } catch (err) { soCa = null; }
        if (!soCa) { posToast('Ca này đã được đóng ở máy khác. Đang tải lại…', 'error'); setTimeout(() => veTrang(), 1500); return; }
        soCa.opening_cash = +soCa.opening_cash || 0; soCa.tong_thu = +soCa.tong_thu || 0; soCa.tong_chi = +soCa.tong_chi || 0;
        $('[data-dong-id]').textContent = `#${soCa.id}`;
        $('[data-dong-ai]').textContent = soCa.employee_name || soCa.opened_by_name || '';
        $('[data-dong-mo]').textContent = gioNgay(new Date(soCa.opened_at));
        $('[data-dong-luc]').textContent = gioNgay(new Date());
        $('[data-dong-tien]').value = '';
        $('[data-dong-tien]').removeAttribute('aria-invalid');
        $('[data-dong-giu]').value = ''; $('[data-dong-giao]').value = '';
        delete $('[data-dong-giao]').dataset.tay;
        $('[data-giao-loi]').hidden = true;
        $('[data-dong-ghi]').value = '';
        $('[data-dong-dem]').textContent = '0';
        $('[data-dong-loi]').hidden = true;
        $('[data-dong-ok]').disabled = false;
        $('[data-xn-ok]').disabled = false;
        xoaTo('caDongTo');
        buoc('count');
        veSong();
        moHop(hopDong);
        $('[data-dong-tien]').focus();
    }
    $('[data-dong-tien]').addEventListener('input', (e) => { dinhDang(e.target); $('[data-dong-loi]').hidden = true; e.target.removeAttribute('aria-invalid'); if (soCa) veSong(); tinhGiao(); });

    /* Bàn giao: giữ lại + giao về chủ = tiền đếm. Ô Giao tự tính tới khi người dùng gõ tay vào nó. */
    function banGiao() {
        const dem = $('[data-dong-tien]').value.trim() ? docTien($('[data-dong-tien]').value) : null;
        const giu = docTien($('[data-dong-giu]').value);
        const oGiao = $('[data-dong-giao]');
        const giao = oGiao.dataset.tay ? docTien(oGiao.value) : (dem == null ? null : dem - giu);
        let loi = '';
        if (dem != null && giu > dem) loi = `Giữ lại ${vnd(giu)} nhiều hơn tiền đếm được ${vnd(dem)}.`;
        else if (dem != null && giao != null && giao + giu !== dem) loi = `Giao ${vnd(giao)} + giữ ${vnd(giu)} = ${vnd(giao + giu)}, khác tiền đếm được ${vnd(dem)}.`;
        return { dem, giu, giao, loi };
    }
    function tinhGiao() {
        const b = banGiao(), oGiao = $('[data-dong-giao]');
        if (!oGiao.dataset.tay) oGiao.value = b.giao == null ? '' : so(Math.max(0, b.giao));
        const l = $('[data-giao-loi]'); l.hidden = !b.loi; l.textContent = b.loi;
    }
    $('[data-dong-giu]').addEventListener('input', (e) => { dinhDang(e.target); tinhGiao(); });
    $('[data-dong-giao]').addEventListener('input', (e) => { dinhDang(e.target); if (e.target.value) e.target.dataset.tay = '1'; else delete e.target.dataset.tay; tinhGiao(); });
    $('[data-dong-ghi]').addEventListener('input', (e) => { $('[data-dong-dem]').textContent = e.target.value.length; $('[data-dong-loi]').hidden = true; });
    const baoLoiDong = (msg) => { buoc('count'); const l = $('[data-dong-loi]'); l.hidden = false; l.textContent = msg; };

    $('[data-dong-form]').addEventListener('submit', (e) => {
        e.preventDefault();
        const el = $('[data-dong-tien]');
        if (!el.value.trim()) { baoLoiDong('Đếm và nhập số tiền thực tế trong két (két trống thì nhập 0).'); el.setAttribute('aria-invalid', 'true'); el.focus(); return; }
        const dem = docTien(el.value);
        const demTo = bangDem('caDongTo');
        const lechTo = lechDem(demTo, dem);
        if (lechTo) { baoLoiDong(lechTo); el.focus(); return; }
        const lech = dem - theoSo();
        const ghi = $('[data-dong-ghi]').value.trim();
        if (lech !== 0 && !ghi) { baoLoiDong(`Két ${lech < 0 ? 'thiếu' : 'dư'} ${vnd(Math.abs(lech))} — ghi lý do chênh lệch trước khi đóng.`); $('[data-dong-ghi]').focus(); return; }
        const bg = banGiao();
        if (bg.loi) { tinhGiao(); $('[data-dong-giu]').focus(); return; }
        cho = { dem, demTo, ghi, giao: bg.giao, giu: bg.giu, inPhieu: $('[data-dong-in]').checked };
        if (lech !== 0) {
            const hop = $('[data-xn-hop]');
            hop.className = `ca-warnbox${lech > 0 ? ' is-over' : ''}`;
            hop.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i><div><b>Két đang ${lech < 0 ? 'thiếu' : 'dư'} ${vnd(Math.abs(lech))}</b> so với sổ.<br>Đếm lại một lần nữa trước khi chốt? Thường gặp: thối nhầm, quên ghi một khoản chi, tiền của khoản “ngoài ca”.</div>`;
            $('[data-xn-so]').innerHTML = vndKet(theoSo());
            $('[data-xn-dem]').textContent = vnd(dem);
            $('[data-xn-ghi]').textContent = ghi;
            $('[data-xn-giao]').textContent = `${vnd(cho.giao)} · ${vnd(cho.giu)}`;
            buoc('confirm');
            return;
        }
        chotCa();
    });
    $('[data-dem-lai]').addEventListener('click', () => { buoc('count'); $('[data-dong-tien]').focus(); });
    $('[data-xn-ok]').addEventListener('click', chotCa);

    async function chotCa() {
        // Mở cửa sổ in NGAY trong lượt bấm: mở sau khi chờ API thì trình duyệt chặn bật lên.
        const cuaSoIn = cho.inPhieu ? window.open('', '_blank') : null;
        $('[data-dong-ok]').disabled = true;
        $('[data-xn-ok]').disabled = true;
        let kq;
        try {
            kq = await goi(CA.url.dong, { counted_cash: cho.dem, note: cho.ghi, delivered_cash: cho.giao, kept_cash: cho.giu, ...(cho.demTo.length ? { counted_count: cho.demTo } : {}) });
        } catch (err) {
            cuaSoIn?.close();
            $('[data-dong-ok]').disabled = false;
            $('[data-xn-ok]').disabled = false;
            if (err.status === 409) { hopDong.close(); posToast(err.message, 'error'); setTimeout(() => veTrang(), 1800); return; }
            baoLoiDong(err.message);
            return;
        }
        const ca = kq.ca;
        const lech = +ca.difference || 0;
        const phieu = CA.url.phieu.replace('__ID__', ca.id);
        $('[data-xong-tieu-de]').textContent = `Đã đóng ca #${ca.id}`;
        $('[data-xong-hop]').innerHTML = `
            <div class="ca-kv"><span>Tiền theo sổ</span><span class="num">${vnd(+ca.expected_cash)}</span></div>
            <div class="ca-kv"><span>Tiền đếm được</span><span class="num">${vnd(+ca.counted_cash)}</span></div>
            ${ca.delivered_cash != null ? `<div class="ca-kv"><span>Giao về chủ</span><span class="num">${vnd(+ca.delivered_cash)}</span></div><div class="ca-kv"><span>Giữ lại cho ca sau</span><span class="num">${vnd(+ca.kept_cash)}</span></div>` : ''}
            <div class="ca-kv"><span><b>Chênh lệch</b></span><span class="num" style="color:${lech === 0 ? 'var(--c-success)' : lech < 0 ? 'var(--c-danger)' : '#9a4f00'}">${lech === 0 ? 'Khớp két' : `${sgn(lech)} (${lech < 0 ? 'thiếu' : 'dư'})`}</span></div>`;
        const ngoai = kq.ngoai_ca || [];
        $('[data-xong-ngoai]').innerHTML = ngoai.length
            ? `<div class="ca-alert ca-alert--warn"><i class="fa-solid fa-triangle-exclamation"></i><span>${ngoai.length} khoản tiền mặt phát sinh trong giờ ca nhưng không thuộc ca nào — không nằm trong con số trên.</span></div>`
            : '';
        $('[data-xong-in]').href = phieu;
        if (cuaSoIn) cuaSoIn.location = phieu;
        CA.caTruoc = { id: ca.id, ai: ca.employee_name || ca.opened_by_name || '', banGiao: ca.kept_cash != null ? +ca.kept_cash : +ca.counted_cash, coGiu: ca.kept_cash != null };
        buoc('done');
        hopDong.dataset.daDong = ca.id;
    }
    // Hộp kết quả đóng lại bằng cách nào cũng phải vẽ lại trang: ca đã đổi trạng thái.
    hopDong.addEventListener('close', () => { if (hopDong.dataset.daDong) veTrang({ ca: hopDong.dataset.daDong }); });
    $('[data-xong-dong]').addEventListener('click', () => hopDong.close());
    $('[data-xong-mo]').addEventListener('click', () => { delete hopDong.dataset.daDong; hopDong.close(); moHopMo(); });

    /* ---------------- Thu / chi */
    const hopTc = $('#caThuChi');
    let chieu = 'out';
    let soTc = null;
    // Đã hỏi lại "két sẽ âm — vẫn ghi?" cho đúng số tiền / chiều đang nhập. Đổi
    // số hay đổi chiều là mất xác nhận (veSauTc), phải hỏi lại.
    let xacNhanAm = false;
    function veChieu() {
        $$('[data-tc-chieu] button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === chieu)));
        $('[data-tc-goi-y]').innerHTML = GOI_Y[chieu].map((r) => `<button type="button" data-ly-do="${esc(r)}">${esc(r)}</button>`).join('');
        $('[data-tc-ok]').textContent = chieu === 'in' ? 'Ghi khoản thu' : 'Ghi khoản chi';
        veSauTc();
    }
    function veSauTc() {
        if (xacNhanAm) { xacNhanAm = false; $('[data-tc-ok]').textContent = chieu === 'in' ? 'Ghi khoản thu' : 'Ghi khoản chi'; }
        const v = docTien($('[data-tc-tien]').value);
        const loi = $('[data-tc-loi]');
        loi.hidden = true;
        if (soTc == null) { $('[data-tc-sau]').textContent = ''; return; }
        const sau = soTc + (chieu === 'in' ? v : -v);
        $('[data-tc-sau]').innerHTML = v ? `Két theo sổ: ${vndKet(soTc)} → <b>${vndKet(sau)}</b>` : `Két theo sổ đang có ${vndKet(soTc)}.`;
        if (chieu === 'out' && sau < 0) { loi.hidden = false; loi.style.color = '#9a4f00'; loi.textContent = `Lớn hơn tiền trong két theo sổ ${vnd(-sau)} — két sẽ âm. Kiểm lại số tiền; đúng là vậy thì bấm Ghi rồi xác nhận.`; }
    }
    async function moHopTc() {
        chieu = 'out';
        $('[data-tc-tien]').value = '';
        $('[data-tc-tien]').removeAttribute('aria-invalid');
        $('[data-tc-ly-do]').value = '';
        $('[data-tc-ly-do-loi]').hidden = true;
        $('[data-tc-ok]').disabled = false;
        soTc = null;
        veChieu();
        moHop(hopTc);
        $('[data-tc-tien]').focus();
        try {
            const c = await goi(CA.url.hienTai);
            if (c) { soTc = (+c.opening_cash || 0) + (+c.tong_thu || 0) - (+c.tong_chi || 0); veSauTc(); }
        } catch (err) { /* thiếu dòng gợi ý két thì vẫn ghi được */ }
    }
    $('[data-tc-chieu]').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) { chieu = b.dataset.v; veChieu(); } });
    $('[data-tc-goi-y]').addEventListener('click', (e) => { const b = e.target.closest('[data-ly-do]'); if (b) { $('[data-tc-ly-do]').value = b.dataset.lyDo; $('[data-tc-ly-do-loi]').hidden = true; $('[data-tc-ly-do]').focus(); } });
    $('[data-tc-tien]').addEventListener('input', (e) => { dinhDang(e.target); e.target.removeAttribute('aria-invalid'); veSauTc(); });
    $('[data-tc-ly-do]').addEventListener('input', () => { $('[data-tc-ly-do-loi]').hidden = true; });
    $('[data-tc-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const v = docTien($('[data-tc-tien]').value);
        const lyDo = $('[data-tc-ly-do]').value.trim();
        const loi = $('[data-tc-loi]');
        if (!v) { loi.hidden = false; loi.style.color = ''; loi.textContent = 'Số tiền phải lớn hơn 0.'; $('[data-tc-tien]').setAttribute('aria-invalid', 'true'); $('[data-tc-tien]').focus(); return; }
        if (!lyDo) { $('[data-tc-ly-do-loi]').hidden = false; $('[data-tc-ly-do]').focus(); return; }
        $('[data-tc-ok]').disabled = true;
        try {
            await goi(CA.url.soQuy, { direction: chieu, amount: v, reason: lyDo, xac_nhan_am: xacNhanAm });
            veTrang(CA.hienTai ? { ca: CA.hienTai.id } : {});
        } catch (err) {
            $('[data-tc-ok]').disabled = false;
            loi.hidden = false; loi.style.color = ''; loi.textContent = err.message;
            // API chặn chi vượt két: nói đúng câu của nó, bấm lần nữa là xác nhận vẫn ghi.
            if (err.ma === 'KET_AM') { loi.style.color = '#9a4f00'; xacNhanAm = true; $('[data-tc-ok]').textContent = 'Vẫn ghi chi'; }
        }
    });

    /* ---------------- Nút hành động + hộp mở bằng data-open */
    document.addEventListener('click', (e) => {
        const a = e.target.closest('[data-act]');
        if (a && !a.disabled) {
            if (a.dataset.act === 'open') moHopMo();
            if (a.dataset.act === 'close') moHopDong();
            if (a.dataset.act === 'cash') moHopTc();
            return;
        }
        const op = e.target.closest('[data-open]');
        if (op) moHop(document.getElementById(op.dataset.open));
    });

    @if(session('error'))
        posToast(@json(session('error')), 'error');
    @endif
})();
</script>
@endpush
