{{--
    Thu chi — màn "Thu chi" của quầy (bản gọn của Quản trị → Thu chi, thiết kế design/html/thu-chi.html).

    Trái: kỳ · thẻ tổng · tab Thu/Chi · danh sách phiếu MÌNH lập tay. Phải: tờ phiếu đang chọn,
    dựng bằng JS từ dữ liệu nhúng; dưới 900px thành tấm phủ trượt từ dưới lên.
    Lọc / chuyển trang là GET (tải lại trang); sau mỗi lượt ghi tải lại với ?phieu=<id>.

    Phím: F1 ô tìm · F2 phiếu thu · F3 phiếu chi · ↑/↓ chuyển phiếu · Ctrl+Enter lưu trong hộp.
--}}
@extends('v2::layouts.pos')

@php
    $C = \App\Http\Controllers\CounterCashbookController::class;
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $error = $error ?? null;
    $giu = array_filter(['q' => $f['q'], 'ky' => $f['ky'], 'type' => $f['type'], 'pham_vi' => $f['pham_vi'] === 'ca' ? 'ca' : '',
        'from_date' => $f['ky'] === 'tuy-chon' ? $f['from_date'] : '', 'to_date' => $f['ky'] === 'tuy-chon' ? $f['to_date'] : ''], fn ($v) => $v !== '');
    $link = fn (array $doi) => route('thu-ngan.thu-chi.index', array_filter(array_merge($giu, $doi), fn ($v) => $v !== '' && $v !== null));

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

    $soThu = (int) ($tong['income_count'] ?? 0);
    $soChi = (int) ($tong['expense_count'] ?? 0);
    $tienMat = (float) ($tong['cash_income'] ?? 0) - (float) ($tong['cash_expense'] ?? 0);
    $total = (int) ($meta['total'] ?? 0);
    $trang = (int) ($meta['page'] ?? 1);
    $soTrang = max(1, (int) ($meta['total_pages'] ?? 1));
    $khoa = ! $ca;

    $nhomNgay = collect($rows)->groupBy(fn ($r) => $ngay($r['created_at'])->toDateString());
    $chon = $f['phieu'] && collect($rows)->contains('id', $f['phieu']) ? $f['phieu'] : (int) ($rows[0]['id'] ?? 0);
    $tenNguoi = trim((string) data_get(session('api.user'), 'full_name', '')) ?: (string) data_get(session('api.user'), 'username', '');
    $cn = \App\Services\CurrentBranch::danhSach();
    $tenCn = (string) (collect($cn['ds'])->firstWhere('id', $cn['dangChon'])['name'] ?? '');
    $duLieu = [
    'rows' => $rows,
    'chon' => $chon,
    'ca' => $ca ? ['id' => (int) $ca['id']] : null,
    'ketTheoSo' => $ketTheoSo,
    'coTheLap' => $coTheLap,
    'hanMucChi' => (float) ($hanMucChi ?? 0),
    'laChu' => (bool) ($laChu ?? false),
    'danhMuc' => $danhMuc ?: ['phan_loai' => [], 'nhan_vien' => [], 'nguoi_nop' => []],
    'toi' => $tenNguoi,
    'chiNhanh' => $tenCn,
    'url' => [
        'store' => route('thu-ngan.thu-chi.store'),
        'update' => route('thu-ngan.thu-chi.update', ['id' => 0]),
        'destroy' => route('thu-ngan.thu-chi.destroy', ['id' => 0]),
        'bienNhan' => route('thu-ngan.thu-chi.bienNhan', ['id' => 0]),
        'nhatKy' => route('thu-ngan.thu-chi.nhatKy', ['id' => 0]),
        'payer' => route('thu-ngan.thu-chi.taoNguoiNop'),
        'attach' => route('thu-ngan.thu-chi.dinhKem'),
    ],
];
@endphp

@section('title', $C::TITLE)
@section('than', 'tc-page')

@section('content')
    <section class="tc-list" aria-labelledby="tcTitle">
        <div class="tc-top">
            <div class="tc-title">
                <h1 id="tcTitle">Thu chi</h1>
                <p>{{ $phuDe }}</p>
            </div>
            <form class="search" role="search" method="get" action="{{ route('thu-ngan.thu-chi.index') }}">
                <i class="fa-solid fa-magnifying-glass search__icon" aria-hidden="true"></i>
                <label class="sr-only" for="tcQ">Tìm phiếu</label>
                <input id="tcQ" name="q" type="search" autocomplete="off" placeholder="Tìm mã phiếu, người, mô tả" value="{{ $f['q'] }}" data-q>
                <kbd>F1</kbd>
            </form>
            @if($coTheLap)
                <div class="tc-new">
                    <button type="button" class="btn btn--in" data-create="in" title="{{ $khoa ? 'Chưa mở ca' : 'Lập phiếu thu (F2)' }}" @disabled($khoa)><i class="fa-solid fa-arrow-down"></i><span><span class="tc-new__w">Phiếu </span>thu</span><kbd>F2</kbd></button>
                    <button type="button" class="btn btn--out" data-create="out" title="{{ $khoa ? 'Chưa mở ca' : 'Lập phiếu chi (F3)' }}" @disabled($khoa)><i class="fa-solid fa-arrow-up"></i><span><span class="tc-new__w">Phiếu </span>chi</span><kbd>F3</kbd></button>
                </div>
            @endif
        </div>

        @if($khoa && $coTheLap)
            <div class="tc-alert tc-alert--lock">
                <i class="fa-solid fa-lock"></i>
                <span><b>Chi nhánh chưa mở ca</b>Mở ca rồi mới lập phiếu thu chi ở quầy — phiếu cũ vẫn xem được.</span>
                <a class="btn btn--primary" href="{{ route('thu-ngan.ca-lam-viec.index') }}">Mở ca</a>
            </div>
        @elseif(! $coTheLap && ! $error)
            <div class="tc-alert tc-alert--info">
                <i class="fa-solid fa-eye"></i>
                <span><b>Chỉ xem</b>Tài khoản chưa được cấp quyền ghi sổ quỹ nên chưa lập được phiếu. Nhờ quản lý cấp ở Quản trị → Nhân sự.</span>
            </div>
        @endif

        <div class="tc-bar">
            <div class="tc-seg" role="group" aria-label="Kỳ">
                @foreach($C::KY as $k => $nhan)
                    @if($k === 'ca' && ! $ca)
                        <button type="button" disabled title="Chưa mở ca">{{ $nhan }}</button>
                    @else
                        <a class="tc-seg__a" href="{{ $link(['ky' => $k, 'q' => '', 'page' => null, 'phieu' => null, 'pham_vi' => $k === 'ca' ? ($giu['pham_vi'] ?? null) : null]) }}" aria-pressed="{{ $f['q'] === '' && $f['ky'] === $k ? 'true' : 'false' }}">{{ $nhan }}</a>
                    @endif
                @endforeach
            </div>
            @if($f['ky'] === 'tuy-chon' && $f['q'] === '')
                <form class="tc-range" method="get" action="{{ route('thu-ngan.thu-chi.index') }}" data-range>
                    <input type="hidden" name="ky" value="tuy-chon">
                    @if($f['type'] !== '')<input type="hidden" name="type" value="{{ $f['type'] }}">@endif
                    <input type="date" name="from_date" aria-label="Từ ngày" value="{{ $f['from_date'] }}" max="{{ now()->toDateString() }}">
                    <span>–</span>
                    <input type="date" name="to_date" aria-label="Đến ngày" value="{{ $f['to_date'] }}" max="{{ now()->toDateString() }}">
                </form>
            @endif
            {{-- Két là CHUNG của ca: "Cả ca" cho người đóng ca thấy cả phiếu người khác
                 lập (chỉ xem — ai lập người ấy sửa). --}}
            <div class="tc-seg tc-pham-vi" role="group" aria-label="Phiếu của ai">
                <a class="tc-seg__a" href="{{ $link(['pham_vi' => null, 'page' => null, 'phieu' => null]) }}" aria-pressed="{{ $f['pham_vi'] === 'toi' ? 'true' : 'false' }}"><i class="fa-solid fa-user"></i>Của tôi</a>
                @if($ca)
                    <a class="tc-seg__a" href="{{ $link(['pham_vi' => 'ca', 'ky' => 'ca', 'q' => '', 'from_date' => null, 'to_date' => null, 'page' => null, 'phieu' => null]) }}" aria-pressed="{{ $f['pham_vi'] === 'ca' ? 'true' : 'false' }}" title="Mọi phiếu của ca #{{ $ca['id'] }} — phiếu người khác chỉ xem"><i class="fa-solid fa-users"></i>Cả ca</a>
                @else
                    <button type="button" disabled title="Chưa mở ca"><i class="fa-solid fa-users"></i>Cả ca</button>
                @endif
            </div>
            @if($tenCn !== '')<span class="tc-scope"><i class="fa-solid fa-store"></i>{{ $tenCn }}</span>@endif
        </div>

        @unless($error)
            <div class="tc-sum" aria-label="Tổng theo kỳ đang xem">
                <div class="tc-kpi is-in"><span>Tổng thu</span><b class="t-num">{{ $so($tong['total_income'] ?? 0) }}</b><small>{{ $soThu }} phiếu</small></div>
                <div class="tc-kpi is-out"><span>Tổng chi</span><b class="t-num">{{ $so($tong['total_expense'] ?? 0) }}</b><small>{{ $soChi }} phiếu</small></div>
                <div class="tc-kpi is-cash"><span>Tiền mặt qua két</span><b class="t-num">{{ $tienMat > 0 ? '+' : ($tienMat < 0 ? '−' : '') }}{{ $so(abs($tienMat)) }}</b>
                    @if($ca && $f['ky'] === 'ca' && $f['q'] === '')
                        <a class="link-btn" href="{{ route('thu-ngan.ca-lam-viec.index') }}">Két theo sổ {{ $so($ketTheoSo) }} đ <i class="fa-solid fa-arrow-right"></i></a>
                    @else
                        <small>Chỉ phiếu tiền mặt</small>
                    @endif
                </div>
            </div>
        @endunless

        <div class="tc-card">
            <div class="tc-tabs" role="tablist" aria-label="Loại phiếu">
                @foreach(['' => $soThu + $soChi, '0' => $soThu, '1' => $soChi] as $k => $n)
                    <a class="tc-tab" role="tab" href="{{ $link(['type' => $k, 'page' => null, 'phieu' => null]) }}" aria-selected="{{ $f['type'] === (string) $k ? 'true' : 'false' }}">{{ $C::LOAI[$k] }}<span class="tc-tab__n">{{ $n }}</span></a>
                @endforeach
            </div>
            <div class="tc-scroll">
                @if($error)
                    <div class="tc-state"><span class="tc-state__ic"><i class="fa-solid fa-wifi"></i></span><h3>Không tải được phiếu thu chi</h3><p>{{ $error }}</p><a class="btn btn--outline" href="{{ request()->fullUrl() }}">Thử lại</a></div>
                @elseif(! $rows)
                    <div class="tc-state">
                        @if($f['q'] !== '')
                            <span class="tc-state__ic"><i class="fa-solid fa-magnifying-glass"></i></span><h3>Không có phiếu khớp “{{ $f['q'] }}”</h3><p>Tìm theo mã phiếu, người nộp/nhận hoặc mô tả, ở mọi ngày.</p>
                            <a class="btn btn--ghost" href="{{ $link(['q' => '']) }}">Xoá từ khoá</a>
                        @else
                            <span class="tc-state__ic"><i class="fa-solid fa-file-invoice-dollar"></i></span><h3>{{ $f['ky'] === 'ca' ? 'Ca này chưa có phiếu thu chi' : 'Không có phiếu trong kỳ này' }}</h3>
                            <p>Trả ship, mua đồ dùng quầy, tạm ứng, nhận tiền cọc… lập phiếu để mỗi khoản tiền ra vào quầy đều có mã và lý do.</p>
                            @if($coTheLap && ! $khoa)
                                <div style="display:flex;gap:8px;margin-top:6px"><button type="button" class="btn btn--in" data-create="in"><i class="fa-solid fa-arrow-down"></i> Phiếu thu</button><button type="button" class="btn btn--out" data-create="out"><i class="fa-solid fa-arrow-up"></i> Phiếu chi</button></div>
                            @endif
                        @endif
                    </div>
                @else
                    <ul class="tc-rows" role="listbox" aria-label="Phiếu thu chi">
                        @foreach($nhomNgay as $d => $ds)
                            @php
                                $dd = $ngay($d);
                                $rong = $ds->sum(fn ($r) => (int) $r['type'] === 0 ? (float) $r['amount'] : -(float) $r['amount']);
                            @endphp
                            <li class="tc-day" role="presentation">{{ $dd->isToday() ? 'Hôm nay' : ($dd->isYesterday() ? 'Hôm qua' : $thu[$dd->dayOfWeek]) }} · {{ $dd->format('d/m') }}<span class="t-num">{{ $ds->count() }} phiếu · {{ $rong >= 0 ? '+' : '−' }}{{ $so(abs($rong)) }}</span></li>
                            @foreach($ds as $r)
                                @php
                                    $vao = (int) $r['type'] === 0;
                                    $moTa = trim((string) ($r['note'] ?? ''));
                                    $nguoi = trim((string) ($r['payer_name'] ?? ''));
                                    $cuaNguoiKhac = (int) ($r['created_by'] ?? 0) !== (int) data_get(session('api.user'), 'id');
                                @endphp
                                <li class="tc-row" role="option" tabindex="{{ (int) $r['id'] === $chon ? 0 : -1 }}" aria-selected="{{ (int) $r['id'] === $chon ? 'true' : 'false' }}" data-id="{{ $r['id'] }}">
                                    <span class="tc-dir {{ $vao ? 'is-in' : 'is-out' }}" aria-hidden="true"><i class="fa-solid fa-arrow-{{ $vao ? 'down' : 'up' }}"></i></span>
                                    <div class="tc-r1"><span class="tc-code">{{ $r['code'] ?: '#'.$r['id'] }}</span><span class="tc-time">{{ $ngay($r['created_at'])->format('H:i') }}</span>
                                        @if(! empty($r['attachment_url']))<i class="fa-solid fa-paperclip tc-clip" title="Có chứng từ"></i>@endif
                                        @if(($r['kind'] ?? '') === 'deposit')<span class="tc-by" title="Nộp tiền về công ty — không phải chi phí">Nộp về công ty</span>@endif
                                        @if(! empty($r['last_edit']))<i class="fa-solid fa-pen-to-square tc-clip" title="Đã sửa — lần cuối bởi {{ $r['last_edit']['by_name'] ?? '' }}"></i>@endif
                                        @if(! empty($r['locked']))<i class="fa-solid fa-lock tc-lock" title="{{ $r['locked_reason'] }}"></i>@endif
                                        @if($cuaNguoiKhac && ($r['created_by_name'] ?? '') !== '')<span class="tc-by" title="Người lập — chỉ xem">{{ $r['created_by_name'] }}</span>@endif
                                    </div>
                                    <span class="tc-amt t-num {{ $vao ? 'is-in' : 'is-out' }}">{{ $vao ? '+' : '−' }}{{ $so($r['amount']) }}</span>
                                    <div class="tc-r2">
                                        @if($moTa === '' && $nguoi === '')
                                            <span class="is-none">{{ $r['category_name'] ?: 'Chưa phân loại' }}</span>
                                        @else
                                            {{ $moTa }}{{ $moTa !== '' && $nguoi !== '' ? ' · ' : '' }}{{ $nguoi }}
                                        @endif
                                    </div>
                                    <span class="tc-pm {{ $r['payment_method'] === 'cash' ? 'is-cash' : '' }}" title="{{ $r['bank_account_label'] ?? '' }}">{{ $C::PHUONG_THUC[$r['payment_method']] ?? $r['payment_method'] }}@if(($r['bank_account_label'] ?? '') !== '') · {{ explode(' · ', $r['bank_account_label'])[0] }}@endif</span>
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

    <section class="tc-detail" aria-label="Chi tiết phiếu" data-detail>
        <div class="tc-paper" data-paper></div>
    </section>

<script type="application/json" id="tcDuLieu">@json($duLieu)</script>
@endsection

@push('modals')
<dialog class="modal tc-dlg is-out" id="tcHop" aria-labelledby="tcHopT">
    <form method="dialog" data-form novalidate>
        <header class="tcm-head">
            <span class="tcm-badge" aria-hidden="true" data-f-badge><i class="fa-solid fa-arrow-up"></i></span>
            <div class="tcm-head__t">
                <h2 id="tcHopT" data-form-title>Lập phiếu chi</h2>
                <p data-f-sub></p>
            </div>
            <button type="button" class="tcm-x" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button>
        </header>
        {{-- Mẫu phiếu nhanh (Cài đặt → Quầy bán hàng): bấm là điền sẵn, chỉ còn sửa số tiền. --}}
        <div class="tcm-mau" role="group" aria-label="Mẫu phiếu nhanh" data-f-mau hidden></div>

        <div class="modal__body tcm-body">
            <div class="tcm-col tcm-col--money">
                <div class="tcm-dir" role="group" aria-label="Loại phiếu" data-f-dir>
                    <button type="button" data-v="in" aria-pressed="false"><i class="fa-solid fa-arrow-down"></i>Phiếu thu</button>
                    <button type="button" data-v="out" aria-pressed="true"><i class="fa-solid fa-arrow-up"></i>Phiếu chi</button>
                    {{-- Nộp về công ty: tiền mặt ra khỏi két, KHÔNG phải chi phí. --}}
                    <button type="button" data-v="dep" aria-pressed="false" title="Đưa bớt tiền trong két cho chủ / quản lý giữa ca"><i class="fa-solid fa-building-columns"></i>Nộp về công ty</button>
                </div>
                {{-- Lập phiếu điều chỉnh: gõ số ĐÚNG của phiếu gốc, hộp tự ra vế + số chênh. --}}
                <section class="tcm-adj" data-f-adj hidden>
                    <p class="tcm-adj__goc" data-f-adj-goc></p>
                    <label for="tcDung">Số đúng</label>
                    <div class="tcm-money__in"><input id="tcDung" inputmode="numeric" autocomplete="off" placeholder="0" data-f-dung><span>đ</span></div>
                    <p class="tcm-adj__kq" data-f-adj-kq></p>
                    <span class="tc-err" data-f-adj-err hidden></span>
                </section>

                <section class="tcm-money" data-f-money>
                    <div class="tcm-money__top">
                        <label for="tcAmt" data-f-amt-label>Số tiền chi</label>
                        <span class="tc-err" data-f-amt-err hidden>Nhập số tiền lớn hơn 0</span>
                    </div>
                    <div class="tcm-money__in"><input id="tcAmt" inputmode="numeric" autocomplete="off" placeholder="0" data-f-amt><span>đ</span></div>
                    <div class="tcm-quick" data-f-quick></div>
                    <p class="tcm-han" data-f-han hidden></p>
                    <div class="tcm-sep"></div>
                    <div class="tcm-pmrow">
                        <div class="tcm-pm" role="group" aria-label="Hình thức" data-f-pm>
                            <button type="button" data-v="cash" aria-pressed="true"><i class="fa-solid fa-money-bill-wave"></i>Tiền mặt</button>
                            <button type="button" data-v="transfer" aria-pressed="false"><i class="fa-solid fa-building-columns"></i>Chuyển khoản</button>
                        </div>
                        <div class="tcm-till" data-f-till></div>
                    </div>
                    {{-- Chuyển khoản: tiền vào / ra tài khoản nào (đối sao kê). --}}
                    <div class="tcm-tk" data-f-tk-wrap hidden>
                        <label for="tcTk">Tài khoản</label>
                        <select class="select" id="tcTk" data-f-tk></select>
                    </div>
                </section>
                <div class="tc-warn" data-f-neg hidden><i class="fa-solid fa-triangle-exclamation"></i><span data-f-neg-text></span></div>

                <div class="tcm-f">
                    <div class="tcm-lab"><span>Chứng từ</span><small>Không bắt buộc</small></div>
                    <input type="file" accept="image/*,application/pdf" hidden data-f-file-in>
                    <button type="button" class="tcm-drop" data-f-file><span class="tcm-drop__ic"><i class="fa-solid fa-camera"></i></span><span><b data-f-file-t>Chụp hoặc chọn ảnh</b><small>Hoá đơn, biên nhận · JPG, PNG, PDF</small></span></button>
                    <button type="button" class="tc-att" data-f-att hidden><span class="tc-att__img"><i class="fa-solid fa-receipt"></i></span><span data-f-att-name></span><i class="fa-solid fa-xmark" style="margin-left:auto;color:var(--tc-faint)"></i></button>
                </div>
            </div>

            <div class="tcm-col">
                <div class="tcm-f" data-f-cat-wrap>
                    <div class="tcm-lab"><span>Phân loại</span><small class="is-req">Bắt buộc</small></div>
                    <div class="tcm-pills" data-f-cat></div>
                    <select class="select" style="width:100%" aria-label="Loại khác" data-f-cat-all hidden></select>
                    <span class="tc-err" data-f-cat-err hidden>Chọn phân loại — phiếu không phân loại sẽ lọt khỏi báo cáo theo phân loại.</span>
                </div>

                <div class="tcm-f">
                    <div class="tcm-lab"><label for="tcWho" data-f-who-label>Người nhận</label><small data-f-who-req>Không bắt buộc</small></div>
                    <div class="tc-who">
                        <div class="tcm-ic"><i class="fa-solid fa-user"></i><input class="input" id="tcWho" autocomplete="off" placeholder="Gõ tên nhân viên hoặc người ngoài" data-f-who role="combobox" aria-expanded="false" aria-controls="tcWhoList"></div>
                        <ul class="tc-who__list" id="tcWhoList" role="listbox" data-f-who-list hidden></ul>
                        <div class="tc-picked" data-f-picked hidden></div>
                    </div>
                    <div class="tc-newpayer" data-f-newpayer hidden>
                        <div class="field"><label for="tcNpName">Tên *</label><input class="input" id="tcNpName" maxlength="255" autocomplete="off" data-np-name></div>
                        <div class="field"><label for="tcNpPhone">Số điện thoại</label><input class="input" id="tcNpPhone" inputmode="tel" maxlength="20" autocomplete="off" data-np-phone></div>
                        <button type="button" class="btn btn--outline" data-np-save>Thêm</button>
                    </div>
                </div>

                <div class="tcm-f">
                    <div class="tcm-lab"><label for="tcNote">Mô tả</label><small data-f-note-req>Bắt buộc</small></div>
                    <div class="tcm-note">
                        <textarea class="input" id="tcNote" maxlength="200" rows="2" data-f-note></textarea>
                        <span class="tc-count" data-f-count>0/200</span>
                    </div>
                    <span class="tc-err" data-f-note-err hidden>Ghi mô tả — một khoản ra khỏi quầy không có lý do thì không đối soát được.</span>
                </div>
                {{-- Sửa phiếu: bắt buộc lý do, vào nhật ký cùng số cũ → mới. --}}
                <div class="tcm-f" data-f-reason-wrap hidden>
                    <div class="tcm-lab"><label for="tcLyDo">Lý do sửa</label><small class="is-req">Bắt buộc</small></div>
                    <input class="input" id="tcLyDo" maxlength="200" autocomplete="off" placeholder="vd. Gõ thiếu một số 0" data-f-reason>
                    <span class="tc-err" data-f-reason-err hidden>Ghi lý do sửa — nhật ký giữ lại số cũ và lý do này.</span>
                </div>
                <span class="tc-err" data-f-err hidden></span>
            </div>
        </div>

        <footer class="tcm-foot">
            <span class="tcm-keys"><kbd>Esc</kbd> đóng · <kbd>Ctrl</kbd> <kbd>Enter</kbd> lưu</span>
            <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
            <button type="submit" class="btn tcm-ok" data-f-ok><span data-f-ok-t>Lưu phiếu chi</span><b data-f-ok-amt></b></button>
        </footer>
    </form>
</dialog>

{{-- Phiếu chi vượt "Hạn mức phiếu chi ở quầy": chủ / quản trị nhập tài khoản để duyệt. --}}
<dialog class="modal" id="tcDuyet" aria-labelledby="tcDuyetT">
    <form method="dialog" data-duyet-form novalidate autocomplete="off">
        <div class="modal__head"><h2 id="tcDuyetT"><i class="fa-solid fa-user-shield"></i> Cần chủ / quản trị duyệt</h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <p style="margin:0 0 12px;line-height:1.5" data-duyet-cau></p>
            <div class="field">
                <label for="tcDuyetTen">Tên đăng nhập người duyệt</label>
                <input class="input" id="tcDuyetTen" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="100" data-duyet-ten>
            </div>
            <div class="field">
                <label for="tcDuyetMk">Mật khẩu</label>
                {{-- new-password: trình duyệt khỏi tự điền mật khẩu đã lưu của chính thu ngân. --}}
                <input class="input" type="password" id="tcDuyetMk" autocomplete="new-password" maxlength="72" data-duyet-mk>
            </div>
            <p class="tc-err" style="margin:0 0 8px" data-duyet-loi hidden></p>
            <span class="hint">Mật khẩu chỉ dùng để duyệt phiếu này, không được lưu. Phiếu ghi lại tên người duyệt.</span>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
            <button type="submit" class="btn btn--primary" data-duyet-ok><i class="fa-solid fa-check"></i> Duyệt &amp; lưu</button>
        </div>
    </form>
</dialog>

<dialog class="modal" id="tcXoa" aria-labelledby="tcXoaT">
    <form method="dialog" data-del-form>
        <div class="modal__head"><h2 id="tcXoaT">Xoá phiếu <span data-del-code></span>?</h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body">
            <p style="margin:0 0 12px;line-height:1.5" data-del-text></p>
            <div class="field">
                <label for="tcXoaLyDo">Lý do xoá *</label>
                <input class="input" id="tcXoaLyDo" maxlength="200" autocomplete="off" placeholder="vd. Lập trùng phiếu" data-del-reason>
                <span class="tc-err" data-del-err hidden></span>
            </div>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--ghost" data-tn-close>Không xoá</button>
            <button type="submit" class="btn btn--danger" data-del-ok>Xoá phiếu</button>
        </div>
    </form>
</dialog>

{{-- Nhật ký sửa / xoá của một phiếu. --}}
<dialog class="modal" id="tcNhatKy" aria-labelledby="tcNhatKyT">
    <div class="modal__head"><h2 id="tcNhatKyT">Nhật ký phiếu <span data-nk-code></span></h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal__body"><ol class="tc-nk" data-nk-list></ol></div>
</dialog>
@endpush

@push('styles')
<link rel="stylesheet" href="{{ asset('v2/css/pos-v3/cashbook.css') }}?v={{ filemtime(public_path('v2/css/pos-v3/cashbook.css')) }}">
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
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const D = JSON.parse($('#tcDuLieu').textContent);
    const ROWS = D.rows || [];
    const DM = D.danhMuc;
    const CA = D.ca;
    const VE = { 0: 'in', 1: 'out' };
    const rowOf = (id) => ROWS.find((r) => r.id === id);
    const urlId = (mau, id) => mau.replace(/\/0$/, `/${id}`);
    const initials = (s) => { const w = String(s || '').replace(/\(.*?\)/g, '').trim().split(/\s+/).filter(Boolean); return (w.length > 1 ? w[0][0] + w[w.length - 1][0] : (w[0] || '?').slice(0, 2)).toUpperCase(); };
    const VAI = { quan_ly: 'Quản lý', thu_ngan: 'Thu ngân' };

    // Người nộp / nhận: nhân viên (users.id + payer_type theo cửa) và danh mục người ngoài.
    const NGUOI = [
        ...(DM.nhan_vien || []).map((n) => ({ key: `u${n.id}`, id: n.id, ten: n.name, loai: n.payer_type, phu: VAI[n.payer_type] || 'Nhân viên' })),
        ...(DM.nguoi_nop || []).map((p) => ({ key: `p${p.id}`, id: p.id, ten: p.name, loai: 'other', phu: p.phone || 'Người ngoài', sdt: p.phone || '' })),
    ];
    const nguoiOf = (loai, id) => NGUOI.find((x) => x.id === id && (loai === 'other' ? x.loai === 'other' : x.loai !== 'other'));

    async function goi(u, opts = {}) {
        const laForm = opts.body instanceof FormData;
        const res = await fetch(u, { headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF, ...(laForm ? {} : { 'Content-Type': 'application/json' }) }, ...opts });
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
        try { if (msg) sessionStorage.setItem('tc.toast', msg); } catch (e) { /* máy chặn lưu */ }
        const u = new URL(location.href);
        if (id) u.searchParams.set('phieu', id); else u.searchParams.delete('phieu');
        location.href = u.toString();
    }
    try { const m = sessionStorage.getItem('tc.toast'); if (m) { sessionStorage.removeItem('tc.toast'); posToast(m); } } catch (e) { /* bỏ qua */ }
    @if(session('error'))
        posToast(@json(session('error')), 'error');
    @endif

    /* ------------------------------------------------------------ tờ phiếu */
    const paper = $('[data-paper]');
    const detail = $('[data-detail]');
    let sel = D.chon || null;
    let daHien = null;
    const choSua = (r) => D.coTheLap && CA && !r.locked;

    function lyDoKhoa(r) {
        if (r.locked) return r.locked_reason ? `${r.locked_reason[0].toUpperCase()}${r.locked_reason.slice(1)}.` : 'Phiếu đã khoá.';
        if (!CA) return 'Chưa mở ca — mở ca rồi mới sửa hay xoá được phiếu.';
        return '';
    }

    function veChiTiet() {
        const r = rowOf(sel);
        detail.classList.toggle('is-open', !!r && innerWidth < 900 && daHien !== null);
        if (!r) {
            paper.innerHTML = '<div class="tc-blank"><div><span class="tc-state__ic"><i class="fa-solid fa-file-invoice-dollar"></i></span>Chọn một phiếu để xem chi tiết</div></div>';
            return;
        }
        const vao = r.type === 0;
        const d = new Date(r.created_at);
        const n = r.payer_id ? nguoiOf(r.payer_type, r.payer_id) : null;
        const why = lyDoKhoa(r);
        paper.classList.toggle('is-swap', daHien !== r.id);
        paper.innerHTML = `
            <div class="tc-anim" style="display:contents">
            <div class="tc-d-head">
                <div class="tc-d-top">
                    <button type="button" class="tc-d-mini tc-back" aria-label="Quay lại danh sách" data-back><i class="fa-solid fa-arrow-left"></i></button>
                    <h2>${esc(r.code || `#${r.id}`)}</h2>
                    ${r.code ? '<button type="button" class="tc-d-mini" aria-label="Chép mã phiếu" title="Chép mã" data-copy><i class="fa-regular fa-copy"></i></button>' : ''}
                    ${r.kind === 'deposit' ? '<span class="tc-chip is-out"><i class="fa-solid fa-building-columns"></i>Nộp về công ty</span>' : `<span class="tc-chip is-${VE[r.type]}"><i class="fa-solid fa-arrow-${vao ? 'down' : 'up'}"></i>${vao ? 'Phiếu thu' : 'Phiếu chi'}</span>`}
                </div>
                <div class="tc-d-meta"><span><i class="fa-regular fa-clock"></i>${hm(d)} · ${dmy(d)}</span>${r.shift_id ? `<span><i class="fa-solid fa-cash-register"></i>Ca #${r.shift_id}</span>` : '<span><i class="fa-solid fa-cash-register"></i>Ngoài ca</span>'}<span><i class="fa-solid fa-user"></i>${esc(r.created_by_name || D.toi)}</span></div>
                <div class="tc-hero is-${VE[r.type]}">
                    <div><div class="tc-hero__label">${vao ? 'Số tiền thu' : 'Số tiền chi'}</div><div class="tc-hero__amt t-num">${vnd(r.amount)}</div></div>
                    <div class="tc-hero__pm">${r.payment_method === 'cash' ? 'Vào sổ quỹ ca' : 'Không qua két'}<b>${r.payment_method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản'}</b></div>
                </div>
            </div>
            <div class="tc-d-body">
                <dl class="tc-kv">
                    <div><dt>Mô tả</dt><dd><p class="tc-note">${r.note ? esc(r.note) : '<span style="color:var(--tc-faint)">—</span>'}</p></dd></div>
                    <div><dt>Phân loại</dt><dd>${r.category_name ? esc(r.category_name) : '<span style="color:var(--tc-faint)">Chưa phân loại</span>'}</dd></div>
                    <div><dt>${vao ? 'Người nộp' : 'Người nhận'}</dt><dd>${r.payer_name ? `${esc(r.payer_name)}<small>${esc(n ? (n.loai === 'other' ? n.phu : `Nhân viên · ${n.phu}`) : (r.payer_type === 'other' ? 'Người ngoài' : 'Nhân viên'))}</small>` : '<span style="color:var(--tc-faint)">—</span>'}</dd></div>
                    ${r.bank_account_label ? `<div><dt>Tài khoản</dt><dd>${esc(r.bank_account_label)}</dd></div>` : ''}
                    ${r.adjusts_id ? `<div><dt>Điều chỉnh cho</dt><dd><button type="button" class="tc-link" data-den="${r.adjusts_id}" data-den-ma="${esc(r.adjusts_code || '')}">${esc(r.adjusts_code || `#${r.adjusts_id}`)}</button><small>Phiếu gốc ở ca đã đóng — số của ca ấy giữ nguyên</small></dd></div>` : ''}
                    ${(r.adjusted_by || []).length ? `<div><dt>Đã điều chỉnh bởi</dt><dd>${r.adjusted_by.map((x) => `<button type="button" class="tc-link" data-den="${x.id}" data-den-ma="${esc(x.code)}">${esc(x.code || `#${x.id}`)}</button>`).join(', ')}</dd></div>` : ''}
                    ${r.last_edit ? `<div><dt>Sửa lần cuối</dt><dd><button type="button" class="tc-link" data-nhat-ky title="Xem cả nhật ký sửa / xoá">${suaCuoi(r.last_edit)}</button><small>Lý do: ${esc(r.last_edit.reason)}</small></dd></div>` : ''}
                    ${r.approved_by_name ? `<div><dt>Người duyệt</dt><dd>${esc(r.approved_by_name)}<small>Phiếu chi vượt hạn mức ở quầy</small></dd></div>` : ''}
                    <div><dt>Chứng từ</dt><dd>${r.attachment_url ? `<a class="tc-att" href="${esc(r.attachment_url)}" target="_blank" rel="noopener"><span class="tc-att__img"><i class="fa-solid fa-receipt"></i></span><span>${esc(r.attachment_url.split('/').pop())}<small>Bấm để xem</small></span></a>` : '<span style="color:var(--tc-faint)">Không có</span>'}</dd></div>
                </dl>
                ${why && D.coTheLap ? `<p class="tc-why"><i class="fa-solid fa-lock"></i><span>${esc(why)}</span></p>` : ''}
            </div>
            <div class="tc-d-foot">
                <button type="button" class="btn btn--soft" data-in-bn><i class="fa-solid fa-print"></i> In biên nhận</button>
                <button type="button" class="btn btn--kho" data-doi-kho title="Khổ giấy ${khoBn}mm — bấm để đổi sang ${khoBn === '80' ? '58' : '80'}mm" aria-label="Khổ giấy ${khoBn}mm, bấm để đổi">${khoBn}<small>mm</small></button>
                ${D.coTheLap && CA && r.can_adjust && r.kind !== 'deposit' ? '<button type="button" class="btn btn--primary" data-adjust title="Phiếu của ca đã đóng không sửa được — lập phiếu mới vào ca đang mở"><i class="fa-solid fa-scale-balanced"></i> Lập phiếu điều chỉnh</button>' : D.coTheLap ? `<button type="button" class="btn btn--primary" data-edit${choSua(r) ? '' : ' disabled'}><i class="fa-solid fa-pen"></i> Sửa</button>` : ''}
                ${D.coTheLap ? `
                <button type="button" class="btn btn--del" aria-label="Xoá phiếu" title="Xoá phiếu" data-delete${choSua(r) ? '' : ' disabled'}><i class="fa-solid fa-trash-can"></i></button>` : ''}
            </div>
            </div>`;
        daHien = r.id;
    }

    // Khổ biên nhận nhớ theo máy: quầy dùng một cuộn giấy cố định.
    const KHOA_KHO_BN = 'pos.bien-nhan-kho';
    let khoBn = '80';
    try { khoBn = localStorage.getItem(KHOA_KHO_BN) === '58' ? '58' : '80'; } catch (err) { /* máy chặn lưu */ }

    // "11:33 18/10 bởi Quản trị viên (200.000 → 320.000)" — số tiền chỉ hiện khi có đổi.
    function suaCuoi(e) {
        const d = new Date(e.at);
        const tien = e.amount_old !== e.amount_new ? ` (${num(e.amount_old)} → ${num(e.amount_new)})` : '';
        return `${hm(d)} ${pad(d.getDate())}/${pad(d.getMonth() + 1)} bởi ${esc(e.by_name || 'không rõ')}${tien}`;
    }

    // Hộp nhật ký: mỗi lượt sửa / xoá kèm những ô ĐÃ ĐỔI (cũ → mới).
    async function moNhatKy(r) {
        const hop = $('#tcNhatKy');
        $('[data-nk-code]').textContent = r.code || `#${r.id}`;
        const ds = $('[data-nk-list]');
        ds.innerHTML = '<li class="tc-nk__trong">Đang tải…</li>';
        hop.showModal();
        try {
            const body = await goi(D.url.nhatKy.replace(/\/0\/log$/, `/${r.id}/log`));
            const dong = (body.data || []).map((n) => {
                const c = n.changes || {};
                const doi = [];
                const them = (nhan, cu, moi, laTien) => {
                    if (moi === undefined || moi === null) return;
                    if (cu === moi) return;
                    doi.push(`<span>${nhan}: <s>${laTien ? num(cu) : esc(cu || '—')}</s> → <b>${laTien ? num(moi) : esc(moi || '—')}</b></span>`);
                };
                them('Số tiền', c.so_tien_cu, c.so_tien_moi, true);
                them('Phân loại', c.phan_loai_cu, c.phan_loai_moi);
                them('Hình thức', c.hinh_thuc_cu === 'cash' ? 'Tiền mặt' : 'Chuyển khoản', c.hinh_thuc_moi == null ? null : (c.hinh_thuc_moi === 'cash' ? 'Tiền mặt' : 'Chuyển khoản'));
                them('Mô tả', c.mo_ta_cu, c.mo_ta_moi);
                if (n.action === 'thu_chi.xoa') doi.push(`<span>Phiếu ${num(c.so_tien_cu)} đ · ${esc(c.phan_loai_cu || 'chưa phân loại')}</span>`);
                const d = new Date(n.at);
                return `<li class="${n.action === 'thu_chi.xoa' ? 'is-xoa' : ''}"><div class="tc-nk__dau"><b>${n.action === 'thu_chi.xoa' ? 'Xoá' : 'Sửa'}</b><span>${hm(d)} · ${dmy(d)} · ${esc(n.by_name || 'không rõ')}</span></div>`
                    + `<div class="tc-nk__ly">Lý do: ${esc(n.reason)}</div>${doi.length ? `<div class="tc-nk__doi">${doi.join('')}</div>` : '<div class="tc-nk__doi"><span>Không đổi số liệu</span></div>'}</li>`;
            });
            ds.innerHTML = dong.join('') || '<li class="tc-nk__trong">Phiếu chưa bị sửa lần nào.</li>';
        } catch (err) {
            ds.innerHTML = `<li class="tc-nk__trong">${esc(err.message)}</li>`;
        }
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

    /* ------------------------------------------------------------ hộp Lập phiếu */
    const dlg = $('#tcHop');
    const QUICK = { in: [100000, 200000, 500000, 1000000], out: [20000, 50000, 100000, 200000] };
    const form = { editId: null, dir: 'out', nop: false, cat: null, who: null, pm: 'cash', tk: null, att: '', attTen: '', confirmNeg: false, dangGui: false, adj: null };
    const pickBtns = (box, v) => $$('button', box).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === v)));
    // Tên ngắn cho viên chọn: phần trước dấu phẩy ("Vận chuyển, giao hàng, …" → "Vận chuyển").
    const tenNgan = (t) => String(t).split(',')[0].trim();
    const catsOf = (d) => (DM.phan_loai || []).filter((c) => VE[c.type] === d);
    // Dãy viên chọn do API xếp: chủ ghim → hay dùng ở chi nhánh 30 ngày → việc của quầy.
    const GHIM = new Set(DM.phan_loai_ghim || []);
    function catTop(d) {
        const theoId = new Map(catsOf(d).map((c) => [c.id, c]));
        return (DM.phan_loai_nhanh || []).map((id) => theoId.get(id)).filter(Boolean);
    }

    function ketHienTai() {
        let k = Number(D.ketTheoSo) || 0;
        const ed = form.editId ? rowOf(form.editId) : null;
        if (ed && ed.payment_method === 'cash' && ed.shift_id === CA?.id) k -= ed.type === 0 ? ed.amount : -ed.amount;
        return k;
    }

    function renderTill() {
        const el = $('[data-f-till]');
        const v = parseMoney($('[data-f-amt]').value);
        if (form.pm !== 'cash' || !CA) { el.className = 'tcm-till'; el.innerHTML = '<span>Không qua két</span><b>Sổ quỹ ca không đổi</b>'; $('[data-f-neg]').hidden = true; form.confirmNeg = false; return; }
        const now = ketHienTai();
        const after = now + (form.dir === 'in' ? v : -v);
        el.className = `tcm-till${after < 0 ? ' is-neg' : ''}`;
        el.innerHTML = `<span>Két theo sổ ca #${CA.id}</span><b>${v ? `<s>${num(now)} →</s> ` : ''}${num(after)} đ</b>`;
        if (after >= 0) { $('[data-f-neg]').hidden = true; form.confirmNeg = false; }
    }

    function renderPicked() {
        const n = form.who;
        // Đã chọn người nhận là nhân viên thì bỏ lời nhắc "chọn người nhận" của phiếu nộp về.
        if (form.nop && n && n.loai !== 'other' && $('[data-f-err]').textContent.startsWith('Chọn người nhận tiền')) $('[data-f-err]').hidden = true;
        const box = $('[data-f-picked]');
        box.hidden = !n;
        $('[data-f-who]').hidden = !!n;
        if (n) box.innerHTML = `<span class="tc-who__av">${esc(initials(n.ten))}</span><span>${esc(n.ten)} <small>· ${esc(n.loai === 'other' ? n.phu : `Nhân viên · ${n.phu}`)}</small></span><button type="button" class="icon-btn" aria-label="Bỏ chọn" data-who-clear><i class="fa-solid fa-xmark"></i></button>`;
    }

    function renderForm() {
        const d = form.dir;
        const ed = form.editId ? rowOf(form.editId) : null;
        dlg.classList.toggle('is-in', d === 'in');
        dlg.classList.toggle('is-out', d === 'out');
        if (form.cat) $('[data-f-cat-err]').hidden = true;
        const adj = form.adj;
        const nop = form.nop;
        dlg.classList.toggle('is-nop', nop);
        $('[data-form-title]').textContent = adj ? `Điều chỉnh ${maPhieu(adj.goc)}` : ed ? `Sửa ${ed.code || `#${ed.id}`}` : nop ? 'Nộp tiền về công ty' : d === 'in' ? 'Lập phiếu thu' : 'Lập phiếu chi';
        $('[data-f-badge]').innerHTML = `<i class="fa-solid ${adj ? 'fa-scale-balanced' : ed ? 'fa-pen' : nop ? 'fa-building-columns' : `fa-arrow-${d === 'in' ? 'down' : 'up'}`}"></i>`;
        $('[data-f-sub]').textContent = ed
            ? `${d === 'in' ? 'Phiếu thu' : 'Phiếu chi'} · lập ${hm(new Date(ed.created_at))} · ${ed.shift_id ? `ca #${ed.shift_id}` : 'ngoài ca'}`
            : [`Ca #${CA?.id}`, D.chiNhanh, D.toi].filter(Boolean).join(' · ');
        $('[data-f-amt-label]').textContent = nop ? 'Số tiền nộp' : d === 'in' ? 'Số tiền thu' : 'Số tiền chi';
        const veNut = nop ? 'dep' : d;
        pickBtns($('[data-f-dir]'), veNut);
        $$('[data-f-dir] button').forEach((b) => { b.disabled = !!ed && b.dataset.v !== veNut; });
        // Nộp về: chỉ tiền mặt, không phân loại chi phí, người nhận bắt buộc là nhân viên.
        $('[data-f-cat-wrap]').hidden = nop;
        $$('[data-f-pm] button').forEach((b) => { b.disabled = nop && b.dataset.v !== 'cash'; });
        $('[data-f-who-req]').textContent = nop ? 'Bắt buộc · nhân viên' : 'Không bắt buộc';
        $('[data-f-who-req]').classList.toggle('is-req', nop);
        const mau = DM.mau_phieu || [];
        $('[data-f-mau]').hidden = !!ed || !!adj || nop || !mau.length;
        $('[data-f-dir]').hidden = !!adj;
        $('[data-f-adj]').hidden = !adj;
        $('[data-f-quick]').hidden = !!adj;
        $('[data-f-amt]').readOnly = !!adj;
        $('[data-f-mau]').innerHTML = mau.map((m, i) => `<button type="button" class="is-${VE[m.loai]}" data-mau="${i}" title="${esc(m.mo_ta || m.ten)}"><i class="fa-solid fa-arrow-${m.loai === 0 ? 'down' : 'up'}"></i>${esc(m.ten)}${m.so_tien ? `<small>${m.so_tien >= 1e6 ? `${m.so_tien / 1e6}tr` : `${m.so_tien / 1000}k`}</small>` : ''}</button>`).join('');
        $('[data-f-quick]').innerHTML = QUICK[d].map((v) => `<button type="button" data-quick="${v}">${v >= 1e6 ? `${v / 1e6}tr` : `${v / 1000}k`}</button>`).join('');

        const all = catsOf(d);
        const top = catTop(d);
        const trongTop = !form.cat || top.some((c) => c.id === form.cat);
        const dang = all.find((c) => c.id === form.cat);
        $('[data-f-cat]').innerHTML = top.map((c) => `<button type="button" aria-pressed="${form.cat === c.id}" data-cat="${c.id}" title="${esc(c.name)}${GHIM.has(c.id) ? ' · chủ ghim' : ''}">${GHIM.has(c.id) ? '<i class="fa-solid fa-thumbtack" aria-hidden="true"></i> ' : ''}${esc(tenNgan(c.name))}</button>`).join('')
            + (all.length > top.length ? `<button type="button" class="tc-pills__more" data-cat-more aria-pressed="${!trongTop}" title="${esc(dang?.name || '')}">${trongTop ? 'Loại khác…' : esc(tenNgan(dang?.name || ''))}</button>` : '')
            + (!all.length ? '<small style="color:var(--tc-faint)">Chưa có loại nào — thêm ở Quản trị → Loại thu chi.</small>' : '');
        // Phiếu điều chỉnh giữ đúng phân loại của phiếu gốc (kể cả khi đảo vế).
        if (adj) $('[data-f-cat]').innerHTML = `<button type="button" aria-pressed="true" disabled title="Theo phiếu gốc">${esc(adj.goc.category_name || 'Chưa phân loại')}</button>`;
        const rest = all.filter((c) => !top.includes(c));
        $('[data-f-cat-all]').innerHTML = `<option value="">Chọn loại ${d === 'in' ? 'thu' : 'chi'}</option>${rest.map((c) => `<option value="${c.id}"${form.cat === c.id ? ' selected' : ''}>${esc(c.name)}</option>`).join('')}`;

        $('[data-f-who-label]').textContent = d === 'in' ? 'Người nộp' : nop ? 'Người nhận tiền' : 'Người nhận';
        $('[data-f-reason-wrap]').hidden = !ed;
        $('[data-f-note-req]').textContent = d === 'out' ? 'Bắt buộc' : 'Không bắt buộc';
        $('[data-f-note-req]').classList.toggle('is-req', d === 'out');
        $('[data-f-note]').placeholder = d === 'out' ? 'Chi cho việc gì — vd. Trả ship đơn HD001250' : 'Thu tiền gì — vd. Khách đặt cọc giữ hàng';
        pickBtns($('[data-f-pm]'), form.pm);
        // Tài khoản: mặc định tài khoản đánh dấu mặc định, một tài khoản thì chọn sẵn.
        // Phiếu cũ ghi tài khoản nay đã tắt vẫn bày ra để sửa không mất tài khoản.
        const dsTk = [...(DM.tai_khoan || [])];
        if (ed?.bank_account_id && !dsTk.some((x) => x.id === ed.bank_account_id)) dsTk.push({ id: ed.bank_account_id, label: `${ed.bank_account_label || `#${ed.bank_account_id}`} (đã tắt)` });
        const coTk = form.pm === 'transfer' && dsTk.length > 0;
        $('[data-f-tk-wrap]').hidden = !coTk;
        if (coTk) {
            if (!dsTk.some((x) => x.id === form.tk)) form.tk = (dsTk.find((x) => x.is_default) || dsTk[0]).id;
            $('[data-f-tk]').innerHTML = dsTk.map((x) => `<option value="${x.id}"${x.id === form.tk ? ' selected' : ''}>${esc(x.label)}</option>`).join('');
        }
        renderPicked();
        renderTill();
        const v = parseMoney($('[data-f-amt]').value);
        const vuot = d === 'out' && !nop && D.hanMucChi > 0 && v > D.hanMucChi && !D.laChu;
        $('[data-f-han]').hidden = !vuot;
        if (vuot) $('[data-f-han]').innerHTML = `<i class="fa-solid fa-user-shield"></i> Vượt hạn mức ${vnd(D.hanMucChi)} ở quầy — lưu sẽ cần chủ hoặc quản trị viên duyệt.`;
        $('[data-f-ok-t]').textContent = form.dangGui ? 'Đang lưu…' : form.confirmNeg ? 'Vẫn lưu phiếu chi' : ed ? 'Lưu thay đổi' : nop ? 'Lưu & in biên nhận' : adj ? `Lưu phiếu ${d === 'in' ? 'thu' : 'chi'} điều chỉnh` : d === 'in' ? 'Lưu phiếu thu' : 'Lưu phiếu chi';
        $('[data-f-ok-amt]').textContent = v ? vnd(v) : '';
        $('[data-f-ok]').classList.toggle('is-confirm', form.confirmNeg);
        $('[data-f-ok]').disabled = form.dangGui;
        $('[data-f-att]').hidden = !form.att;
        $('[data-f-file]').hidden = !!form.att;
        $('[data-f-att-name]').innerHTML = form.att ? `${esc(form.attTen || form.att.split('/').pop())}<small>Bấm để bỏ ảnh</small>` : '';
    }

    function renderWhoList() {
        const raw = $('[data-f-who]').value.trim();
        const q = raw.toLowerCase();
        const ul = $('[data-f-who-list]');
        const khop = (x) => !q || x.ten.toLowerCase().includes(q) || (x.sdt && x.sdt.replace(/\s/g, '').includes(q.replace(/\s/g, '')));
        const nv = NGUOI.filter((x) => x.loai !== 'other' && khop(x));
        const ngoai = form.nop ? [] : NGUOI.filter((x) => x.loai === 'other' && khop(x));
        const opt = (x) => `<li><button type="button" class="tc-who__opt" data-who="${x.key}"><span class="tc-who__av">${esc(initials(x.ten))}</span>${esc(x.ten)}<small>${esc(x.phu)}</small></button></li>`;
        ul.innerHTML = (nv.length ? `<li class="tc-who__grp">Nhân viên</li>${nv.map(opt).join('')}` : '')
            + (ngoai.length ? `<li class="tc-who__grp">Người nộp / nhận khác</li>${ngoai.map(opt).join('')}` : '')
            + (q && !form.nop && !NGUOI.some((x) => x.ten.toLowerCase() === q) ? `<li><button type="button" class="tc-who__opt is-add" data-who-add><span class="tc-who__av"><i class="fa-solid fa-plus"></i></span>Thêm “${esc(raw)}” vào danh mục</button></li>` : '');
        ul.hidden = !ul.innerHTML;
        $('[data-f-who]').setAttribute('aria-expanded', String(!ul.hidden));
    }

    // Bấm mẫu: điền loại, phân loại, mô tả, số tiền gợi ý rồi đưa con trỏ vào ô số tiền.
    function apMau(m) {
        if (!m) return;
        form.dir = VE[m.loai];
        form.cat = m.phan_loai || null;
        form.confirmNeg = false;
        anLoi();
        $('[data-f-cat-all]').hidden = true;
        const note = $('[data-f-note]');
        note.value = m.mo_ta || '';
        note.dispatchEvent(new Event('input'));
        const amt = $('[data-f-amt]');
        amt.value = m.so_tien ? num(m.so_tien) : '';
        amt.dispatchEvent(new Event('input'));
        renderForm();
        amt.focus();
        amt.select();
    }

    function anLoi() {
        ['[data-f-amt-err]', '[data-f-note-err]', '[data-f-cat-err]', '[data-f-neg]', '[data-f-err]', '[data-f-reason-err]'].forEach((s) => { $(s).hidden = true; });
        $('[data-f-money]').classList.remove('is-bad');
        $('[data-f-note]').removeAttribute('aria-invalid');
    }

    function moHop(dir, r) {
        if (!D.coTheLap) return;
        if (!CA) { posToast('Chi nhánh chưa mở ca — mở ca rồi mới lập phiếu.', 'error'); return; }
        const n = r?.payer_id ? nguoiOf(r.payer_type, r.payer_id) : null;
        Object.assign(form, {
            editId: r?.id || null, dir: r ? VE[r.type] : dir, cat: r?.category_id || null,
            who: n || (r?.payer_id ? { key: 'cu', id: r.payer_id, ten: r.payer_name, loai: r.payer_type, phu: r.payer_type === 'other' ? 'Người ngoài' : 'Nhân viên' } : null),
            nop: r?.kind === 'deposit', pm: r?.payment_method || 'cash', tk: r?.bank_account_id || null, att: r?.attachment_url || '', attTen: '', confirmNeg: false, dangGui: false, adj: null,
        });
        $('[data-f-amt]').value = r ? num(r.amount) : '';
        $('[data-f-note]').value = r?.note || '';
        $('[data-f-count]').textContent = `${$('[data-f-note]').value.length}/200`;
        $('[data-f-who]').value = '';
        $('[data-f-who-list]').hidden = true;
        $('[data-f-newpayer]').hidden = true;
        $('[data-f-cat-all]').hidden = true;
        $('[data-f-reason]').value = '';
        anLoi();
        renderForm();
        dlg.showModal();
        $('[data-f-amt]').focus();
        $('[data-f-amt]').select();
    }

    const maPhieu = (r) => r.code || `#${r.id}`;
    // Mở hộp lập phiếu ở chế độ điều chỉnh cho phiếu gốc `g` (khoá vì ca đã đóng).
    function moDieuChinh(g) {
        moHop(VE[g.type], null);
        if (!dlg.open) return;
        form.adj = { goc: g, noteTay: false };
        form.cat = g.category_id || null;
        const ve = g.type === 0 ? 'thu' : 'chi';
        $('[data-f-adj-goc]').innerHTML = `Phiếu gốc <b>${esc(maPhieu(g))}</b> · ${ve} <b>${vnd(g.amount)}</b> · ca #${g.shift_id} đã đóng — số của ca ấy giữ nguyên, phiếu mới vào ca #${CA.id}.`;
        $('[data-f-dung]').value = '';
        $('[data-f-adj-kq]').textContent = 'Gõ số đúng — hộp tự ra vế và số chênh.';
        $('[data-f-amt]').value = '';
        $('[data-f-note]').value = '';
        renderForm();
        $('[data-f-dung]').focus();
    }
    function tinhDieuChinh() {
        const g = form.adj.goc;
        const cu = Number(g.amount) || 0;
        const o = $('[data-f-dung]');
        const dung = parseMoney(o.value);
        o.value = o.value.trim() === '' ? '' : num(dung);
        const veGoc = VE[g.type];
        const nguoc = veGoc === 'out' ? 'in' : 'out';
        const chenh = Math.abs(cu - dung);
        $('[data-f-adj-err]').hidden = true;
        if (o.value === '' || chenh === 0) {
            $('[data-f-amt]').value = '';
            $('[data-f-adj-kq]').textContent = o.value === '' ? 'Gõ số đúng — hộp tự ra vế và số chênh.' : 'Số đúng bằng số cũ — không cần điều chỉnh.';
        } else {
            form.dir = dung < cu ? nguoc : veGoc;
            $('[data-f-amt]').value = num(chenh);
            $('[data-f-adj-kq]').innerHTML = `→ Lập phiếu <b>${form.dir === 'in' ? 'thu' : 'chi'} ${vnd(chenh)}</b> (${dung < cu ? 'đảo vế, trả lại phần dư' : 'cùng vế, bù phần thiếu'}).`;
            if (!form.adj.noteTay) {
                $('[data-f-note]').value = `Điều chỉnh ${maPhieu(g)} (${veGoc === 'out' ? 'chi' : 'thu'} ${num(cu)} → đúng ${num(dung)})`;
                $('[data-f-count]').textContent = `${$('[data-f-note]').value.length}/200`;
                $('[data-f-note-err]').hidden = true;
            }
        }
        form.confirmNeg = false;
        renderForm();
    }
    $('[data-f-dung]').addEventListener('input', () => { if (form.adj) tinhDieuChinh(); });
    $('[data-f-tk]').addEventListener('change', (e) => { form.tk = +e.target.value || null; });

    $('[data-f-amt]').addEventListener('input', (e) => {
        const v = parseMoney(e.target.value);
        e.target.value = v ? num(Math.min(v, 999999999999)) : '';
        $('[data-f-amt-err]').hidden = true;
        $('[data-f-money]').classList.remove('is-bad');
        form.confirmNeg = false;
        $('[data-f-neg]').hidden = true;
        renderForm();
    });
    $('[data-f-note]').addEventListener('input', (e) => {
        if (form.adj && e.isTrusted) form.adj.noteTay = true;
        $('[data-f-count]').textContent = `${e.target.value.length}/200`;
        $('[data-f-note-err]').hidden = true;
        e.target.removeAttribute('aria-invalid');
    });
    $('[data-f-who]').addEventListener('input', renderWhoList);
    $('[data-f-who]').addEventListener('focus', renderWhoList);
    $('[data-f-cat-all]').addEventListener('change', (e) => { form.cat = +e.target.value || null; e.target.hidden = !!form.cat; renderForm(); });
    dlg.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); $('[data-form]').requestSubmit(); } });

    $('[data-f-file-in]').addEventListener('change', async (e) => {
        const tep = e.target.files[0];
        e.target.value = '';
        if (!tep) return;
        const fd = new FormData();
        fd.append('file', tep);
        $('[data-f-file-t]').textContent = 'Đang tải ảnh lên…';
        try {
            const body = await goi(D.url.attach, { method: 'POST', body: fd });
            form.att = body.url;
            form.attTen = tep.name;
        } catch (err) {
            posToast(err.message, 'error');
        }
        $('[data-f-file-t]').textContent = 'Chụp hoặc chọn ảnh';
        renderForm();
    });

    $('[data-form]').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        if (form.dangGui) return;
        const v = parseMoney($('[data-f-amt]').value);
        const note = $('[data-f-note]').value.trim();
        anLoi();
        if (form.adj && v <= 0) {
            const e = $('[data-f-adj-err]');
            e.hidden = false;
            e.textContent = 'Gõ số đúng khác số cũ của phiếu gốc.';
            $('[data-f-dung]').focus();
            return;
        }
        let bad = false;
        if (v <= 0) { $('[data-f-amt-err]').hidden = false; $('[data-f-money]').classList.add('is-bad'); bad = true; }
        if (form.dir === 'out' && !note) { $('[data-f-note-err]').hidden = false; $('[data-f-note]').setAttribute('aria-invalid', 'true'); bad = true; }
        if (!form.cat && !form.nop) { $('[data-f-cat-err]').hidden = false; bad = true; }
        if (form.nop && (!form.who || form.who.loai === 'other')) {
            $('[data-f-err]').hidden = false;
            $('[data-f-err]').textContent = 'Chọn người nhận tiền trong danh sách nhân viên — biên nhận cần chữ ký của người ấy.';
            bad = true;
        }
        const lyDo = $('[data-f-reason]').value.trim();
        if (form.editId && !lyDo) { $('[data-f-reason-err]').hidden = false; bad = true; }

        if (bad) {
            const dau = v <= 0 ? $('[data-f-amt]') : (form.dir === 'out' && !note) ? $('[data-f-note]') : !form.cat ? $('[data-f-cat] button') : $('[data-f-reason]');
            dau?.focus();
            return;
        }
        // Chi tiền mặt quá két theo sổ: hỏi lại một lần, như KET_AM của sổ quỹ ca.
        if (form.dir === 'out' && form.pm === 'cash' && !form.confirmNeg && v > ketHienTai()) {
            form.confirmNeg = true;
            $('[data-f-neg-text]').textContent = `Két theo sổ chỉ còn ${vnd(ketHienTai())} — khoản chi lớn hơn tiền đang có. Kiểm lại số tiền, hoặc bấm “Vẫn lưu” nếu tiền lấy từ nguồn khác bỏ vào.`;
            $('[data-f-neg]').hidden = false;
            renderForm();
            return;
        }
        const payload = {
            type: form.dir === 'in' ? 0 : 1, amount: v, payment_method: form.pm, note,
            category_id: form.cat, payer_type: form.who?.loai || null, payer_id: form.who?.id || null, attachment: form.att,
            ...(form.editId ? { reason: lyDo } : {}),
            ...(form.adj ? { adjusts_id: form.adj.goc.id } : {}),
            ...(form.nop ? { kind: 'deposit', category_id: null } : {}),
            bank_account_id: form.pm === 'transfer' ? form.tk : null,
        };
        form.dangGui = true;
        renderForm();
        try {
            await luuPhieu(payload);
        } catch (err) {
            form.dangGui = false;
            if (err.ma === 'CHUA_MO_CA') { dlg.close(); taiLai(null, err.message); return; }
            if (err.ma === 'CAN_DUYET_CHI' || err.ma === 'SAI_DUYET_CHI') { renderForm(); moDuyet(payload, err.message); return; }
            $('[data-f-err]').textContent = err.message;
            $('[data-f-err]').hidden = false;
            renderForm();
        }
    });

    async function luuPhieu(payload) {
        const body = form.editId
            ? await goi(urlId(D.url.update, form.editId), { method: 'PUT', body: JSON.stringify(payload) })
            : await goi(D.url.store, { method: 'POST', body: JSON.stringify(payload) });
        const p = body.data || {};
        const duyet = p.approved_by_name && payload.approver_username ? ` · ${p.approved_by_name} duyệt` : '';
        if (hopDuyet.open) hopDuyet.close();
        dlg.close();
        const dc = form.adj ? ` — điều chỉnh ${maPhieu(form.adj.goc)}` : '';
        // Nộp về công ty: in biên nhận ngay để hai bên ký (người nộp / người nhận).
        if (form.nop && !form.editId && p.id) {
            const u = new URL(D.url.bienNhan.replace(/\/0\/receipt$/, `/${p.id}/receipt`), location.href);
            if (khoBn === '58') u.searchParams.set('paper', '58');
            await window.posIn(u.href);
        }
        taiLai(p.id || form.editId, form.editId ? `Đã lưu ${p.code || 'phiếu'}${duyet}.` : `Đã lập ${p.code || 'phiếu'} · ${vnd(payload.amount)}${dc}${form.pm === 'cash' ? ' · vào sổ quỹ ca' : ''}${duyet}.`);
    }

    /* ------------------------------------------------------------ duyệt phiếu chi vượt hạn mức */
    const hopDuyet = $('#tcDuyet');
    let choDuyet = null;
    function moDuyet(payload, cau) {
        choDuyet = payload;
        $('[data-duyet-cau]').textContent = `${payload.amount ? vnd(payload.amount) : ''} — vượt hạn mức ${vnd(D.hanMucChi)} của phiếu chi ở quầy. Chủ hoặc quản trị viên nhập tài khoản của mình để duyệt.`;
        const loi = $('[data-duyet-loi]');
        loi.hidden = !hopDuyet.open;
        loi.textContent = cau;
        $('[data-duyet-mk]').value = '';
        $('[data-duyet-ok]').disabled = false;
        if (!hopDuyet.open) hopDuyet.showModal();
        ($('[data-duyet-ten]').value ? $('[data-duyet-mk]') : $('[data-duyet-ten]')).focus();
    }
    $('[data-duyet-form]').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const ten = $('[data-duyet-ten]').value.trim();
        const mk = $('[data-duyet-mk]').value;
        const loi = $('[data-duyet-loi]');
        if (!ten || !mk) { loi.hidden = false; loi.textContent = 'Nhập tên đăng nhập và mật khẩu của chủ / quản trị viên.'; return; }
        $('[data-duyet-ok]').disabled = true;
        try {
            await luuPhieu({ ...choDuyet, approver_username: ten, approver_password: mk });
        } catch (err) {
            if (err.ma === 'CHUA_MO_CA') { hopDuyet.close(); dlg.close(); taiLai(null, err.message); return; }
            moDuyet(choDuyet, err.message);
        }
    });
    // Đóng hộp duyệt thì quên mật khẩu đã gõ.
    hopDuyet.addEventListener('close', () => { $('[data-duyet-mk]').value = ''; });

    $('[data-del-form]').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const r = rowOf(sel);
        if (!r) return;
        const lyDo = $('[data-del-reason]').value.trim();
        const loiXoa = $('[data-del-err]');
        if (!lyDo) { loiXoa.hidden = false; loiXoa.textContent = 'Ghi lý do xoá — nhật ký giữ lại phiếu cũ và lý do này.'; $('[data-del-reason]').focus(); return; }
        $('[data-del-ok]').disabled = true;
        try {
            await goi(urlId(D.url.destroy, r.id), { method: 'DELETE', body: JSON.stringify({ reason: lyDo }) });
            $('#tcXoa').close();
            taiLai(null, `Đã xoá ${r.code || 'phiếu'}.${r.payment_method === 'cash' ? ' Dòng sổ quỹ ca cũng đã gỡ.' : ''}`);
        } catch (err) {
            $('[data-del-ok]').disabled = false;
            $('#tcXoa').close();
            posToast(err.message, 'error');
            if (err.ma === 'CHUA_MO_CA') taiLai(r.id);
        }
    });

    /* ------------------------------------------------------------ sự kiện */
    document.addEventListener('click', async (ev) => {
        const t = ev.target;
        if (!t.closest('.tc-who')) $('[data-f-who-list]').hidden = true;
        const row = t.closest('.tc-row');
        if (row) { chon(+row.dataset.id); return; }
        const cr = t.closest('[data-create]');
        if (cr && !cr.disabled) { moHop(cr.dataset.create); return; }
        if (t.closest('[data-back]')) { detail.classList.remove('is-open'); return; }
        if (t.closest('[data-edit]') && !t.closest('[data-edit]').disabled) { moHop(null, rowOf(sel)); return; }
        if (t.closest('[data-delete]') && !t.closest('[data-delete]').disabled) {
            const r = rowOf(sel);
            $('[data-del-code]').textContent = r.code || `#${r.id}`;
            $('[data-del-text]').textContent = `${r.type === 0 ? 'Phiếu thu' : 'Phiếu chi'} ${vnd(r.amount)} — ${r.note || 'không mô tả'}. ${r.payment_method === 'cash' ? `Dòng tương ứng trong sổ quỹ ca${r.shift_id ? ` #${r.shift_id}` : ''} cũng bị gỡ, két theo sổ ${r.type === 0 ? 'giảm' : 'tăng'} ${vnd(r.amount)}.` : 'Phiếu chuyển khoản không ảnh hưởng két.'}`;
            $('[data-del-ok]').disabled = false;
            $('[data-del-reason]').value = '';
            $('[data-del-err]').hidden = true;
            $('#tcXoa').showModal();
            $('[data-del-reason]').focus();
            return;
        }
        if (t.closest('[data-nhat-ky]')) { moNhatKy(rowOf(sel)); return; }
        if (t.closest('[data-adjust]')) { moDieuChinh(rowOf(sel)); return; }
        const den = t.closest('[data-den]');
        if (den) {
            // Phiếu liên kết đang có trong danh sách thì chọn luôn, không thì tìm theo mã.
            const id = +den.dataset.den;
            if (rowOf(id)) { chon(id, true); return; }
            const u = new URL(location.href);
            ['phieu', 'page'].forEach((k) => u.searchParams.delete(k));
            if (den.dataset.denMa) u.searchParams.set('q', den.dataset.denMa); else u.searchParams.set('phieu', id);
            location.href = u.href;
            return;
        }
        if (t.closest('[data-in-bn]')) {
            const u = new URL(D.url.bienNhan.replace(/\/0\/receipt$/, `/${sel}/receipt`), location.href);
            if (khoBn === '58') u.searchParams.set('paper', '58');
            window.posIn(u.href);
            return;
        }
        if (t.closest('[data-doi-kho]')) {
            khoBn = khoBn === '80' ? '58' : '80';
            try { localStorage.setItem(KHOA_KHO_BN, khoBn); } catch (err) { /* máy chặn lưu */ }
            veChiTiet();
            posToast(`Biên nhận in khổ ${khoBn}mm.`);
            return;
        }
        if (t.closest('[data-copy]')) { const r = rowOf(sel); navigator.clipboard?.writeText(r.code).catch(() => {}); posToast(`Đã chép ${r.code}`); return; }

        const dirB = t.closest('[data-f-dir] button');
        if (dirB && !dirB.disabled) {
            const v = dirB.dataset.v;
            const nop = v === 'dep';
            if (form.nop !== nop || (!nop && form.dir !== v)) {
                form.nop = nop;
                form.dir = nop ? 'out' : v;
                form.cat = null;
                form.confirmNeg = false;
                if (nop) {
                    form.pm = 'cash';
                    if (form.who?.loai === 'other') form.who = null;
                    if (!$('[data-f-note]').value.trim()) { $('[data-f-note]').value = 'Nộp tiền về công ty'; $('[data-f-count]').textContent = `${$('[data-f-note]').value.length}/200`; }
                }
                anLoi();
            }
            renderForm();
            return;
        }
        const mauB = t.closest('[data-mau]');
        if (mauB) { apMau(DM.mau_phieu[+mauB.dataset.mau]); return; }
        const qk = t.closest('[data-quick]');
        if (qk) { $('[data-f-amt]').value = num(+qk.dataset.quick); $('[data-f-amt]').dispatchEvent(new Event('input')); return; }
        const cat = t.closest('[data-cat]');
        if (cat) { form.cat = form.cat === +cat.dataset.cat ? null : +cat.dataset.cat; $('[data-f-cat-all]').hidden = true; renderForm(); return; }
        if (t.closest('[data-cat-more]')) { const s = $('[data-f-cat-all]'); s.hidden = !s.hidden; if (!s.hidden) s.focus(); return; }
        const pmB = t.closest('[data-f-pm] button');
        if (pmB) { form.pm = pmB.dataset.v; form.confirmNeg = false; $('[data-f-neg]').hidden = true; renderForm(); return; }
        const who = t.closest('[data-who]');
        if (who) { form.who = NGUOI.find((x) => x.key === who.dataset.who); $('[data-f-who-list]').hidden = true; $('[data-f-newpayer]').hidden = true; renderPicked(); return; }
        if (t.closest('[data-who-clear]')) { form.who = null; renderPicked(); $('[data-f-who]').value = ''; $('[data-f-who]').focus(); return; }
        if (t.closest('[data-who-add]')) {
            $('[data-f-who-list]').hidden = true;
            $('[data-f-newpayer]').hidden = false;
            $('[data-np-name]').value = $('[data-f-who]').value.trim();
            $('[data-np-phone]').focus();
            return;
        }
        const npSave = t.closest('[data-np-save]');
        if (npSave) {
            const ten = $('[data-np-name]').value.trim();
            if (!ten) { $('[data-np-name]').focus(); return; }
            npSave.disabled = true;
            try {
                const body = await goi(D.url.payer, { method: 'POST', body: JSON.stringify({ name: ten, phone: $('[data-np-phone]').value.trim() }) });
                const p = body.data;
                const x = { key: `p${p.id}`, id: p.id, ten: p.name, loai: 'other', phu: p.phone || 'Người ngoài', sdt: p.phone || '' };
                NGUOI.push(x);
                form.who = x;
                $('[data-f-newpayer]').hidden = true;
                renderPicked();
                posToast(`Đã thêm ${p.name} vào danh mục người nộp/nhận.`);
            } catch (err) {
                posToast(err.message, 'error');
            }
            npSave.disabled = false;
            return;
        }
        if (t.closest('[data-f-file]')) { $('[data-f-file-in]').click(); return; }
        if (t.closest('[data-f-att]')) { form.att = ''; form.attTen = ''; renderForm(); }
    });

    $('[data-range]')?.addEventListener('change', (e) => e.currentTarget.requestSubmit());

    // Phím: F1 tìm · F2 phiếu thu · F3 phiếu chi · ↑/↓ đổi phiếu · Esc đóng tờ phiếu (màn hẹp).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F1') { e.preventDefault(); $('[data-q]').focus(); $('[data-q]').select(); return; }
        if (document.querySelector('dialog[open]')) return;
        if (e.key === 'F2' || e.key === 'F3') { e.preventDefault(); moHop(e.key === 'F2' ? 'in' : 'out'); return; }
        if (e.key === 'Escape') { if (detail.classList.contains('is-open')) detail.classList.remove('is-open'); return; }
        const ae = document.activeElement;
        if (/INPUT|SELECT|TEXTAREA/.test(ae?.tagName)) return;
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
