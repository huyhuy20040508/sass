{{--
    Lịch sử đơn hàng — màn "Lịch sử" của quầy (khuôn order-cashier/history của v2).

    Trái: kỳ · bộ lọc · thẻ tổng · tab trạng thái · danh sách đơn (đọc từ sổ chứng từ,
    gồm cả phiếu trả). Phải: chi tiết đơn đang chọn, nạp bằng fetch; dưới 900px cột
    chi tiết thành tấm phủ trượt từ dưới lên.

    Lọc / chuyển trang là đường dẫn GET (tải lại trang) để nút Back và đường dẫn chép
    gửi nhau giữ đúng bộ lọc. `?don=<id>` giữ đơn đang mở qua lượt tải lại sau mỗi
    thao tác ghi (thu nợ, thu QR, xuất hoá đơn).

    Phím: F1 ô tìm · ↑/↓ chuyển đơn · Esc đóng menu / tấm chi tiết.
--}}
@extends('v2::layouts.pos')

@php
    $C = \App\Http\Controllers\CashierController::class;
    $O = \App\Http\Controllers\OrderController::class;
    $f = $filters;
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');

    // Tham số đang bật — mọi link lọc dựng từ đây rồi đè ô cần đổi.
    $giu = array_filter([
        'q' => $f['q'], 'period' => $f['period'],
        'from_date' => $f['period'] === 'custom' ? $f['from_date'] : '',
        'to_date' => $f['period'] === 'custom' ? $f['to_date'] : '',
        'status' => $f['status'], 'staff' => $f['staff'], 'pm' => $f['pm'], 'einv' => $f['einv'],
    ], fn ($v) => $v !== '' && $v !== null);
    $link = fn (array $doi) => route('thu-ngan.don-hang.index', array_filter(array_merge($giu, $doi), fn ($v) => $v !== '' && $v !== null));

    $thu = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
    $ngay = fn ($d) => \Illuminate\Support\Carbon::parse($d);
    if ($f['q'] !== '') {
        $phuDe = '';
    } elseif ($f['from_date'] !== '' && $f['from_date'] === $f['to_date']) {
        $phuDe = $ngay($f['from_date'])->format('d/m/Y');
    } else {
        $phuDe = trim(($f['from_date'] !== '' ? $ngay($f['from_date'])->format('d/m/Y') : '…').' – '.($f['to_date'] !== '' ? $ngay($f['to_date'])->format('d/m/Y') : '…'));
    }

    $soLoc = count(array_filter([$f['staff'], $f['pm'], $f['einv']]));
    $nhieuNgay = $f['q'] !== '' || $f['from_date'] !== $f['to_date'];

    // Chữ hình thức của một dòng: theo các cột tiền có số (đơn trả nhiều cách thì nối).
    $hinhThucDong = function (array $r) use ($C) {
        $ten = [];
        $ngan = [];
        foreach ($C::HINH_THUC as $h) {
            if ((float) ($r[$h['cot']] ?? 0) > 0) {
                $ten[] = $h['ten'];
                $ngan[] = $h['ngan'];
            }
        }
        if (! $ten && ($r['loai'] ?? 'don') === 'tra-hang' && ($n = $C::nhomHinhThuc($r['phuong_thuc'] ?? null))) {
            $ten[] = $C::HINH_THUC[$n]['ten'];
            $ngan[] = $C::HINH_THUC[$n]['ngan'];
        }

        return [implode(' + ', $ten), implode(' + ', $ngan)];
    };

    $cnQuay = \App\Services\CurrentBranch::danhSach();
    $tenChiNhanh = (string) (collect($cnQuay['ds'])->firstWhere('id', $cnQuay['dangChon'])['name'] ?? '');
@endphp

@section('title', $C::TITLE)
@section('than', 'hs-page')

@section('content')
    <div id="hsCauHinh" hidden
         data-detail-url="{{ route('thu-ngan.don-hang.chiTiet', ['id' => 0]) }}"
         data-debt-url="{{ route('thu-ngan.don-hang.thuNo', ['id' => 0]) }}"
         data-receipt-url="{{ route('thu-ngan.ban-hang.phieu', ['id' => 0]) }}"
         data-einvoice-url="{{ route('thu-ngan.ban-hang.hoaDon', ['id' => 0]) }}"
         data-switch-url="{{ route('thu-ngan.ban-hang.doiHinhThuc', ['id' => 0]) }}"
         data-cancel-qr-url="{{ route('thu-ngan.ban-hang.huyQr', ['id' => 0]) }}"
         data-returns-url="{{ $coQuanLy ? route('admin.returns.index') : '' }}"
         data-admin-orders-url="{{ $coQuanLy ? route('admin.orders.index') : '' }}"
         data-branch="{{ $tenChiNhanh }}"
         data-statuses='@json($O::STATUSES)'
         data-etax-statuses='@json(\App\Http\Controllers\EInvoiceController::TRANG_THAI)'></div>

    <section class="hs-list" aria-labelledby="hsTitle">
        <div class="hs-top">
            <div class="hs-title"><h1 id="hsTitle">Lịch sử đơn hàng</h1></div>
            <form class="search" method="GET" action="{{ route('thu-ngan.don-hang.index') }}" role="search" id="hsTim">
                <i class="fa-solid fa-magnifying-glass search__icon" aria-hidden="true"></i>
                <label class="sr-only" for="hsQ">Tìm đơn</label>
                <input id="hsQ" name="q" type="search" autocomplete="off" value="{{ $f['q'] }}" placeholder="Tìm mã đơn, SĐT, tên khách">
                <kbd>F1</kbd>
                @foreach(['period' => $f['period'], 'status' => $f['status'], 'pm' => $f['pm'], 'einv' => $f['einv']] as $k => $v)
                    @if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                @endforeach
            </form>
            <a class="hs-icon-btn" href="{{ route('thu-ngan.don-hang.xuat', $giu) }}" aria-label="Xuất Excel" title="Xuất Excel (CSV) theo bộ lọc đang xem"><i class="fa-solid fa-file-arrow-down"></i></a>
        </div>

        <div class="hs-bar">
            {{-- Ô chọn kỳ: kỳ nhanh + lịch chọn từ ngày – đến ngày (như bộ chọn ngày của v2). --}}
            <div class="hs-ky">
                <button type="button" class="hs-btn" aria-haspopup="dialog" aria-expanded="false" data-ky-btn>
                    <i class="fa-regular fa-calendar"></i>
                    @if($f['q'] !== '')
                        <span>Mọi ngày</span><small>đang tìm “{{ $f['q'] }}”</small>
                    @else
                        @if($f['period'] === 'custom')
                            <span class="t-num">{{ $phuDe }}</span>
                        @else
                            <span>{{ $C::KY[$f['period']] }}</span><small class="t-num">{{ $phuDe }}</small>
                        @endif
                    @endif
                    <i class="fa-solid fa-chevron-down"></i>
                </button>
                <form class="hs-ky__pop" role="dialog" aria-label="Chọn kỳ" method="GET" action="{{ route('thu-ngan.don-hang.index') }}" hidden data-ky-pop
                      data-from="{{ $f['from_date'] }}" data-to="{{ $f['to_date'] }}" data-today="{{ now()->toDateString() }}">
                    <ul class="hs-ky__nhanh">
                        @foreach(array_diff_key($C::KY, ['custom' => 1]) as $k => $nhan)
                            <li><a href="{{ $link(['period' => $k, 'q' => null, 'from_date' => null, 'to_date' => null, 'page' => null]) }}"
                                   @if($f['period'] === $k && $f['q'] === '') aria-current="true" @endif>{{ $nhan }}</a></li>
                        @endforeach
                    </ul>
                    <div class="hs-ky__lich">
                        <input type="hidden" name="period" value="custom">
                        <input type="hidden" name="from_date" data-ky-tu-gt>
                        <input type="hidden" name="to_date" data-ky-den-gt>
                        @foreach(['status' => $f['status'], 'staff' => $f['staff'], 'pm' => $f['pm'], 'einv' => $f['einv']] as $k => $v)
                            @if($v !== '')<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                        @endforeach
                        <div class="hs-ky__o">
                            <label>Từ ngày<input type="text" inputmode="numeric" placeholder="dd/mm/yyyy" maxlength="10" autocomplete="off" data-ky-tu></label>
                            <span>–</span>
                            <label>Đến ngày<input type="text" inputmode="numeric" placeholder="dd/mm/yyyy" maxlength="10" autocomplete="off" data-ky-den></label>
                        </div>
                        <div class="hs-ky__thang">
                            <button type="button" aria-label="Tháng trước" data-ky-lui><i class="fa-solid fa-chevron-left"></i></button>
                            <b data-ky-ten></b>
                            <button type="button" aria-label="Tháng sau" data-ky-toi><i class="fa-solid fa-chevron-right"></i></button>
                        </div>
                        <div class="hs-ky__ngay" data-ky-ngay></div>
                        <div class="hs-ky__chan">
                            <small data-ky-goi-y>Bấm ngày đầu rồi ngày cuối</small>
                            <span>
                                <button type="button" class="btn btn--ghost" data-ky-dong>Đóng</button>
                                <button type="submit" class="btn btn--primary" data-ky-xem>Xem</button>
                            </span>
                        </div>
                    </div>
                </form>
            </div>

            <div class="hs-filter">
                <button type="button" class="hs-btn hs-btn--loc" aria-haspopup="true" aria-expanded="false" data-filter-btn>
                    <i class="fa-solid fa-filter"></i><span>Lọc</span><b class="hs-filter__n" @if(! $soLoc) hidden @endif>{{ $soLoc }}</b>
                </button>
                <form class="hs-pop" role="dialog" aria-label="Bộ lọc" method="GET" action="{{ route('thu-ngan.don-hang.index') }}" hidden data-pop>
                    @foreach(array_intersect_key($giu, array_flip(['q', 'period', 'from_date', 'to_date', 'status'])) as $k => $v)
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endforeach
                    <label>Thu ngân
                        <select class="select" name="staff">
                            <option value="">Tất cả</option>
                            @foreach($nguoiBan as $nb)
                                <option @selected($f['staff'] === $nb)>{{ $nb }}</option>
                            @endforeach
                            @if($f['staff'] !== '' && ! in_array($f['staff'], $nguoiBan, true))
                                <option selected>{{ $f['staff'] }}</option>
                            @endif
                        </select>
                    </label>
                    <label>Hình thức thanh toán
                        <select class="select" name="pm">
                            <option value="">Tất cả</option>
                            @foreach($C::HINH_THUC as $k => $h)
                                <option value="{{ $k }}" @selected($f['pm'] === $k)>{{ $h['ten'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Hoá đơn điện tử
                        <select class="select" name="einv">
                            <option value="">Tất cả</option>
                            @foreach($C::HOA_DON as $k => $nhan)
                                <option value="{{ $k }}" @selected($f['einv'] === $k)>{{ $nhan }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="hs-pop__foot">
                        <a class="btn btn--ghost" href="{{ $link(['staff' => null, 'pm' => null, 'einv' => null, 'page' => null]) }}">Bỏ lọc</a>
                        <button type="submit" class="btn btn--primary">Áp dụng</button>
                    </div>
                </form>
            </div>
        </div>

        @if($soLoc)
            <div class="hs-chips">
                @if($f['staff'] !== '')<span>Thu ngân: {{ $f['staff'] }}<a href="{{ $link(['staff' => null, 'page' => null]) }}" aria-label="Bỏ lọc thu ngân"><i class="fa-solid fa-xmark"></i></a></span>@endif
                @if($f['pm'] !== '')<span>{{ $C::HINH_THUC[$f['pm']]['ten'] }}<a href="{{ $link(['pm' => null, 'page' => null]) }}" aria-label="Bỏ lọc hình thức"><i class="fa-solid fa-xmark"></i></a></span>@endif
                @if($f['einv'] !== '')<span>{{ $C::HOA_DON[$f['einv']] }}<a href="{{ $link(['einv' => null, 'page' => null]) }}" aria-label="Bỏ lọc hoá đơn"><i class="fa-solid fa-xmark"></i></a></span>@endif
                @if($soLoc > 1)<a class="hs-chips__all" href="{{ $link(['staff' => null, 'pm' => null, 'einv' => null, 'page' => null]) }}">Bỏ tất cả</a>@endif
            </div>
        @endif

        @if($biCat)
            <p class="hs-note-cat"><i class="fa-solid fa-circle-info"></i> Kỳ này có hơn {{ $so($C::MAX_DONG) }} dòng — số liệu chỉ tính {{ $so($C::MAX_DONG) }} dòng mới nhất. Chọn kỳ ngắn hơn để đủ.</p>
        @endif

        @unless($error)
            <div class="hs-sum" aria-label="Tổng theo bộ lọc">
                <div><span>Số đơn</span><b class="t-num" data-sum-count>{{ $so($tong['so_don']) }}</b></div>
                <div class="is-main"><span>Đã thu</span><b class="t-num" data-sum-total>{{ $so($tong['da_thu']) }}</b></div>
                <div class="{{ $tong['con_no'] > 0 ? 'is-debt' : '' }}" title="Phần còn phải thu của đơn còn nợ và đơn chưa trả (kể cả đang chờ QR)"><span>Chưa thu</span><b class="t-num" data-sum-debt>{{ $so($tong['con_no']) }}</b></div>
                <div class="{{ $tong['da_hoan'] > 0 ? 'is-refund' : '' }}"><span>Đã hoàn</span><b class="t-num" data-sum-refund>{{ $tong['da_hoan'] > 0 ? '−'.$so($tong['da_hoan']) : '0' }}</b></div>
                @foreach($C::HINH_THUC as $k => $h)
                    <div data-sum-pm="{{ $k }}"><span>{{ $h['ten'] }}</span><b class="t-num">{{ $so($tong['theo'][$k]) }}</b></div>
                @endforeach
            </div>
        @endunless

        <div class="hs-card">
            <nav class="hs-tabs" aria-label="Trạng thái">
                @foreach(['' => 'Tất cả'] + $C::TRANG_THAI as $k => $nhan)
                    <a class="hs-tab" href="{{ $link(['status' => $k, 'page' => null]) }}" data-st="{{ $k }}"
                       @if($f['status'] === $k) aria-current="page" @endif>{{ $nhan }}<span class="hs-tab__n">{{ $dem[$k] ?? 0 }}</span></a>
                @endforeach
            </nav>

            <div class="hs-scroll" data-scroll>
                @if($error)
                    <div class="hs-state">
                        <span class="hs-state__ic"><i class="fa-solid fa-wifi"></i></span>
                        <h3>Không tải được lịch sử đơn</h3>
                        <p>{{ $error }}</p>
                        <a class="btn btn--primary" href="{{ $link([]) }}">Thử lại</a>
                    </div>
                @elseif(! $list)
                    <div class="hs-state">
                        @if($f['q'] !== '' || $f['status'] !== '' || $soLoc)
                            <span class="hs-state__ic"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <h3>Không có đơn khớp</h3>
                            <p>{{ $f['q'] !== '' ? 'Không thấy đơn nào có “'.$f['q'].'” ở bất kỳ ngày nào.' : 'Bỏ bớt bộ lọc hoặc chọn kỳ dài hơn.' }}</p>
                            <a class="btn btn--outline" href="{{ route('thu-ngan.don-hang.index', ['period' => $f['period']]) }}">Bỏ lọc</a>
                        @else
                            <span class="hs-state__ic"><i class="fa-solid fa-receipt"></i></span>
                            <h3>{{ $f['period'] === 'today' ? 'Hôm nay chưa có đơn nào' : 'Không có đơn trong kỳ này' }}</h3>
                            <p>Đơn bán ở màn Bán hàng hiện ở đây ngay khi thanh toán xong.</p>
                            <a class="btn btn--primary" href="{{ route('thu-ngan.ban-hang.index') }}"><i class="fa-solid fa-cart-shopping"></i>Sang Bán hàng</a>
                        @endif
                    </div>
                @else
                    <ul class="hs-rows" role="listbox" aria-label="Đơn hàng" data-rows>
                        @php $ngayTruoc = null; @endphp
                        @foreach($list as $r)
                            @php
                                $luc = $ngay($r['created_at'] ?? now());
                                $laTra = ($r['loai'] ?? 'don') === 'tra-hang';
                                $tt = (string) ($r['trang_thai'] ?? '');
                                [$htTen, $htNgan] = $hinhThucDong($r);
                            @endphp
                            @if($nhieuNgay && $luc->toDateString() !== $ngayTruoc)
                                @php
                                    $ngayTruoc = $luc->toDateString();
                                    $mNgay = $theoNgay[$ngayTruoc] ?? ['so' => 0, 'thu' => 0];
                                    $nhanNgay = $luc->isToday() ? 'Hôm nay' : ($luc->isYesterday() ? 'Hôm qua' : $thu[$luc->dayOfWeek]);
                                @endphp
                                <li class="hs-day" role="presentation">{{ $nhanNgay }} · {{ $luc->format('d/m') }}<span>{{ $mNgay['so'] }} dòng · thu <span class="t-num">{{ $so($mNgay['thu']) }}</span></span></li>
                            @endif
                            <li class="hs-row" role="option" tabindex="-1" aria-selected="false"
                                data-id="{{ $r['id'] }}" data-loai="{{ $laTra ? 'tra-hang' : 'don' }}" data-row='@json($r)'>
                                <span class="hs-r1"><span class="hs-code">{{ $r['ma'] ?? '' }}</span><span class="hs-time">{{ $nhieuNgay ? $luc->format('d/m').' · ' : '' }}{{ $luc->format('H:i') }}</span></span>
                                <span class="hs-amt t-num {{ $laTra ? 'is-refund' : ($tt === 'cancelled' ? 'is-void' : '') }}">{{ $laTra ? '−' : '' }}{{ $so($r['tong_tien'] ?? 0) }}</span>
                                <span class="hs-r2">@if(filled($r['khach_hang'] ?? '')){{ $r['khach_hang'] }}@else<span class="is-walkin">Khách lẻ</span>@endif<span class="hs-staff"> · {{ $r['nguoi_tao'] ?: '—' }}</span>@if($htTen !== '') · <span class="pm-long">{{ $laTra ? 'Hoàn ' : '' }}{{ $htTen }}</span><span class="pm-short" title="{{ $htTen }}">{{ $laTra ? 'Hoàn ' : '' }}{{ $htNgan }}</span>@endif</span>
                                <span class="hs-r2r">
                                    @if($laTra)
                                        <span class="st st--slip">Phiếu trả</span>
                                    @elseif((float) ($r['con_no'] ?? 0) > 0 && $tt === 'partial')
                                        <span class="st st--partial t-num" title="Còn nợ">Nợ {{ $so($r['con_no']) }}</span>
                                    @else
                                        <span class="st st--{{ $tt }}">{{ $C::nhanTrangThai($r) }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="hs-foot">
                @if($meta['total_pages'] > 1)
                    <span>{{ ($meta['page'] - 1) * $meta['page_size'] + 1 }}–{{ min($meta['page'] * $meta['page_size'], $meta['total']) }} trong {{ $meta['total'] }} dòng</span>
                    <span>
                        <a href="{{ $meta['page'] > 1 ? $link(['page' => $meta['page'] - 1]) : '#' }}" aria-label="Trang trước" @if($meta['page'] <= 1) aria-disabled="true" @endif><i class="fa-solid fa-chevron-left"></i></a>
                        {{ $meta['page'] }}/{{ $meta['total_pages'] }}
                        <a href="{{ $meta['page'] < $meta['total_pages'] ? $link(['page' => $meta['page'] + 1]) : '#' }}" aria-label="Trang sau" @if($meta['page'] >= $meta['total_pages']) aria-disabled="true" @endif><i class="fa-solid fa-chevron-right"></i></a>
                    </span>
                @else
                    <span>{{ $meta['total'] }} dòng · mới nhất ở trên</span><span class="hs-keyhint">↑ ↓ để chuyển đơn</span>
                @endif
            </div>
        </div>
    </section>

    <section class="hs-detail" aria-label="Chi tiết đơn" data-detail>
        <div class="hs-paper" data-paper>
            <div class="hs-blank"><div><span class="hs-state__ic"><i class="fa-solid fa-receipt"></i></span>Chọn một đơn để xem chi tiết</div></div>
        </div>
    </section>
@endsection

@push('modals')
    {{-- Thu nợ --}}
    <dialog class="modal" id="hsDebt" aria-labelledby="hsDebtT">
        <form method="dialog" data-debt-form>
            <div class="modal__head"><h2 id="hsDebtT">Thu nợ · <span data-debt-code></span></h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body hs-form">
                <div class="hs-sumbox">
                    <div class="hs-kv"><span>Tổng đơn</span><b class="t-num" data-debt-total></b></div>
                    <div class="hs-kv"><span>Đã thu</span><b class="t-num" data-debt-paid></b></div>
                    <div class="hs-kv is-owe"><span>Còn nợ</span><b class="t-num" data-debt-owe></b></div>
                </div>
                <div class="field">
                    <span class="label">Hình thức</span>
                    <div class="hs-pm-pick" data-debt-pm>
                        <button type="button" aria-pressed="true" data-v="cash"><i class="fa-solid fa-money-bill-wave"></i>Tiền mặt</button>
                        <button type="button" aria-pressed="false" data-v="bank_transfer"><i class="fa-solid fa-building-columns"></i>Chuyển khoản</button>
                        <button type="button" aria-pressed="false" data-v="card"><i class="fa-solid fa-credit-card"></i>Quẹt thẻ</button>
                    </div>
                    <span class="hint">Tiền mặt vào sổ quỹ của ca đang mở.</span>
                </div>
                <div class="field">
                    <label for="hsDebtAmt">Số tiền thu lần này</label>
                    <input class="input t-num" id="hsDebtAmt" inputmode="numeric" autocomplete="off" data-debt-amt>
                    <span class="hs-err" data-debt-err hidden></span>
                </div>
                <div class="field">
                    <label for="hsDebtNote">Ghi chú</label>
                    <input class="input" id="hsDebtNote" maxlength="255" autocomplete="off" placeholder="Không bắt buộc" data-debt-note>
                </div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
                <button type="submit" class="btn btn--primary" data-debt-ok>Ghi lượt thu</button>
            </div>
        </form>
    </dialog>

    {{-- Thu đơn chờ QR bằng cách khác --}}
    <dialog class="modal" id="hsQr" aria-labelledby="hsQrT">
        <form method="dialog" data-qr-form>
            <div class="modal__head"><h2 id="hsQrT">Thu bằng cách khác</h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body hs-form">
                <div class="hs-alert hs-alert--warn" style="margin:0"><i class="fa-solid fa-triangle-exclamation"></i><span>Mã QR của đơn sẽ bị huỷ. Nếu tiền QR đã về, hệ thống từ chối để không thu hai lần.</span></div>
                <div class="hs-sumbox"><div class="hs-kv is-owe" style="border:0;padding:0"><span>Khách cần trả</span><b class="t-num" data-qr-total></b></div></div>
                <div class="hs-pm-pick" data-qr-pm>
                    <button type="button" aria-pressed="true" data-v="cash"><i class="fa-solid fa-money-bill-wave"></i>Tiền mặt</button>
                    <button type="button" aria-pressed="false" data-v="bank_transfer"><i class="fa-solid fa-building-columns"></i>Chuyển khoản</button>
                    <button type="button" aria-pressed="false" data-v="card"><i class="fa-solid fa-credit-card"></i>Quẹt thẻ</button>
                </div>
            </div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
                <button type="submit" class="btn btn--primary" data-qr-ok>Xác nhận đã thu</button>
            </div>
        </form>
    </dialog>

    {{-- Xác nhận chung: xuất HĐĐT, huỷ mã QR --}}
    <dialog class="modal" id="hsAsk" aria-labelledby="hsAskT">
        <form method="dialog" data-ask-form>
            <div class="modal__head"><h2 id="hsAskT" data-ask-title></h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
            <div class="modal__body hs-form" data-ask-body></div>
            <div class="modal__foot">
                <button type="button" class="btn btn--ghost" data-tn-close data-ask-no>Không</button>
                <button type="submit" class="btn btn--primary" data-ask-ok></button>
            </div>
        </form>
    </dialog>
@endpush

@push('styles')
    <link rel="stylesheet" href="{{ asset('v2/css/pos-v3/orders.css') }}?v={{ filemtime(public_path('v2/css/pos-v3/orders.css')) }}">
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
    const ngay = (s) => { const d = new Date(s); return Number.isNaN(+d) ? null : d; };
    const hm = (d) => (d ? `${pad(d.getHours())}:${pad(d.getMinutes())}` : '');
    const dmy = (d) => (d ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}` : '');
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const cfg = $('#hsCauHinh').dataset;
    const url = (mau, id) => mau.replace(/\/0(?=\/|$|\?)/, `/${id}`);
    const STATUSES = JSON.parse(cfg.statuses || '{}');
    const ETAX = JSON.parse(cfg.etaxStatuses || '{}');

    const PM = {
        cash: { ten: 'Tiền mặt' }, bank_transfer: { ten: 'Chuyển khoản' }, card: { ten: 'Quẹt thẻ' },
        payos: { ten: 'QR tự động' }, sepay: { ten: 'QR tự động' }, mpos_qr: { ten: 'QR tự động' },
    };
    const tenPm = (m) => PM[m]?.ten || (m ? 'Khác' : '');
    const MA_QR = ['payos', 'sepay', 'mpos_qr'];
    const NHAN_ST = { paid: 'Đã thanh toán', unpaid: 'Chưa thu', partial: 'Còn nợ', returned: 'Đã trả hàng', cancelled: 'Đã huỷ' };
    const choQr = (r) => r.loai === 'don' && r.trang_thai === 'unpaid' && MA_QR.includes(r.phuong_thuc);
    const badge = (r) => (r.loai === 'tra-hang' ? '<span class="st st--slip">Phiếu trả</span>'
        : `<span class="st st--${esc(r.trang_thai)}">${choQr(r) ? 'Chờ QR' : esc(NHAN_ST[r.trang_thai] || r.trang_thai)}</span>`);

    async function goi(u, opts = {}) {
        const res = await fetch(u, { headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF }, ...opts });
        let body = {};
        try { body = await res.json(); } catch (e) { /* trang lỗi không phải JSON */ }
        if (!res.ok) throw new Error(body.message || 'Không kết nối được máy chủ. Vui lòng thử lại.');
        return body;
    }

    // Tải lại trang, giữ đơn đang mở — sau mỗi thao tác ghi để dải tổng và tab cùng đúng.
    function taiLai(id, msg) {
        try { if (msg) sessionStorage.setItem('hs.toast', msg); } catch (e) { /* máy chặn lưu */ }
        const u = new URL(location.href);
        u.searchParams.set('don', id);
        location.href = u.toString();
    }

    /* ------------------------------------------------------------ chi tiết */
    const paper = $('[data-paper]');
    const detail = $('[data-detail]');
    let dangMo = null;   // { row, don, etax }
    let luotNap = 0;
    let tab = 'detail';

    const rowsEl = () => $$('.hs-row');
    function chon(li, focus) {
        rowsEl().forEach((x) => { const on = x === li; x.setAttribute('aria-selected', String(on)); x.tabIndex = on ? 0 : -1; });
        if (!li) return;
        if (focus) { li.focus({ preventScroll: true }); li.scrollIntoView({ block: 'nearest' }); }
        const u = new URL(location.href);
        u.searchParams.set('don', li.dataset.id);
        history.replaceState(null, '', u);
        moDon(JSON.parse(li.dataset.row));
    }

    async function moDon(row) {
        const lan = ++luotNap;
        detail.classList.toggle('is-open', innerWidth < 900);
        if (row.loai === 'tra-hang') { dangMo = { row }; veChiTiet(true); return; }
        paper.innerHTML = '<div class="hs-loading"><span><i class="fa-solid fa-spinner fa-spin"></i> Đang tải đơn…</span></div>';
        try {
            const body = await goi(url(cfg.detailUrl, row.id));
            if (lan !== luotNap) return;
            dangMo = { row, don: body.data || {}, etax: body.etax || null };
            veChiTiet(true);
        } catch (e) {
            if (lan !== luotNap) return;
            paper.innerHTML = `<div class="hs-blank"><div><span class="hs-state__ic"><i class="fa-solid fa-triangle-exclamation"></i></span>${esc(e.message)}<br><button type="button" class="link-btn" data-retry>Thử lại</button></div></div>`;
        }
    }

    // Luật nút — theo v2 order-cashier/history; nút bị khoá luôn nói lý do.
    function luat(r, don, etax) {
        const st = r.trang_thai;
        const coHd = !!etax;
        return {
            inLai: ['paid', 'partial', 'returned'].includes(st) ? { ok: true } : { ok: false, why: st === 'cancelled' ? 'Đơn đã huỷ không in lại phiếu.' : 'Đơn chưa thu tiền.' },
            thuNo: !choQr(r) && st !== 'cancelled' && Number(r.con_no) > 0,
            hoaDon: coHd ? { ok: false, why: `Đã có hoá đơn${etax.invoice_no ? ` số ${etax.invoice_no}` : ''}.` }
                : r.xuat_duoc_hoa_don ? { ok: true } : { ok: false, why: 'Chỉ xuất cho đơn đã thu tiền, chưa huỷ, chưa trả hàng.' },
            traHang: !cfg.returnsUrl ? { ok: false, why: 'Trả hàng làm ở khu Quản trị — cần cửa Quản lý.' }
                : coHd ? { ok: false, why: 'Đơn đã có HĐĐT — trả hàng phải kèm hoá đơn điều chỉnh.' }
                    : st === 'partial' ? { ok: false, why: 'Đơn còn nợ — thu đủ rồi mới trả hàng.' }
                        : ['paid', 'returned'].includes(st) ? { ok: true } : { ok: false, why: 'Chỉ trả hàng cho đơn đã thanh toán.' },
        };
    }

    function dongThoiGian(don, etax) {
        const ev = [];
        (don.histories || []).forEach((h) => ev.push({
            t: ngay(h.created_at), c: h.to_status === 'cancelled' ? '#c62828' : '#2d5a9b',
            b: h.from_status ? `${STATUSES[h.from_status] || h.from_status} → ${STATUSES[h.to_status] || h.to_status}` : 'Tạo đơn tại quầy',
            s: [h.note, h.changed_by && h.changed_by === don.created_by ? don.created_by_name : ''].filter(Boolean).join(' · '),
        }));
        (don.luot_thu || []).forEach((p) => ev.push({ t: ngay(p.paid_at || p.created_at), c: '#2f9e5b', b: `Thu ${vnd(p.amount)} · ${tenPm(p.payment_method)}`, s: p.note || '' }));
        if (etax) ev.push({ t: ngay(etax.updated_at || etax.created_at), c: etax.status === 'failed' ? '#c62828' : '#2d5a9b', b: `Hoá đơn điện tử${etax.invoice_no ? ` số ${etax.invoice_no}` : ''}`, s: ETAX[etax.status] || etax.status || '' });
        return ev.filter((e) => e.t).sort((a, b) => b.t - a.t);
    }

    function veChiTiet(doiDon) {
        if (!dangMo) return;
        const { row: r, don, etax } = dangMo;
        const luc = ngay(r.created_at);
        const top = `
            <div class="hs-d-top">
                <button type="button" class="hs-d-mini hs-back" aria-label="Quay lại danh sách" data-back><i class="fa-solid fa-arrow-left"></i></button>
                <h2>${esc(r.ma)}</h2>
                <button type="button" class="hs-d-mini" aria-label="Sao chép mã" title="Sao chép mã" data-act="copy"><i class="fa-regular fa-copy"></i></button>
                ${badge(r)}
            </div>
            <div class="hs-d-meta">${hm(luc)} ${dmy(luc)} · ${esc((don && don.created_by_name) || r.nguoi_tao || '—')}${cfg.branch ? ` · ${esc(cfg.branch)}` : ''}</div>`;
        const khach = `<div class="hs-cus"><span>Khách hàng:</span>${r.khach_hang ? `<b>${esc(r.khach_hang)}</b>${r.so_dien_thoai ? `<span class="t-num">${esc(r.so_dien_thoai)}</span>` : ''}` : '<b>Khách lẻ</b>'}</div>`;

        // Phiếu trả: sổ chỉ có số hoàn + hình thức; chi tiết phiếu là màn của khu Quản trị.
        if (r.loai === 'tra-hang') {
            paper.innerHTML = `
                <div class="hs-d-head hs-anim">${top}${khach}</div>
                <div class="hs-d-body hs-anim" style="border-top:1px solid var(--hs-line)"><div role="tabpanel">
                    <dl class="hs-tot"><div><dt>Hình thức hoàn</dt><dd>${esc(tenPm(r.phuong_thuc) || '—')}</dd></div>
                    <div class="is-grand"><dt>Hoàn cho khách</dt><dd class="t-num" style="color:var(--c-danger)">−${vnd(r.tong_tien)}</dd></div></dl>
                    <div class="hs-alert hs-alert--info"><i class="fa-solid fa-circle-info"></i><span>Phiếu trả hàng do ${esc(r.nguoi_tao || 'quản lý')} xử lý. Món trả và đơn gốc xem ở Quản trị → Trả hàng.</span></div>
                </div></div>
                <div class="hs-d-foot"><div class="hs-actions">
                    <button type="button" class="btn btn--primary" data-act="print"><i class="fa-solid fa-print"></i>In phiếu trả</button>
                    ${cfg.returnsUrl ? `<a class="btn btn--soft" href="${esc(cfg.returnsUrl)}?keyword=${encodeURIComponent(r.ma)}"><i class="fa-solid fa-up-right-from-square"></i>Mở ở Quản trị</a>` : '<p class="hs-why"><i class="fa-solid fa-circle-info"></i>Chi tiết phiếu trả cần cửa Quản lý.</p>'}
                </div></div>`;
            paper.classList.toggle('is-swap', !!doiDon);
            return;
        }

        const items = (don.items || []).map((l) => {
            const tang = !!l.is_gift;
            const bot = Number(l.discount_amount) || 0;
            return `<li class="hs-it">
                ${l.thumbnail ? `<img class="hs-it__img" src="${esc(l.thumbnail)}" alt="" loading="lazy">` : '<span class="hs-it__img"><i class="fa-solid fa-box"></i></span>'}
                <span class="hs-it__name">${esc(l.product_name)}${tang ? '<span class="hs-tag hs-tag--gift">Tặng</span>' : ''}</span>
                <span class="hs-it__amt t-num">${num(l.total_price)}</span>
                <span class="hs-it__sub">${l.variant_name ? `${esc(l.variant_name)} · ` : ''}<span class="t-num">${num(l.unit_price)}</span> × ${num(l.quantity)}${bot > 0 ? ` · bớt <span class="t-num">${num(bot)}</span>` : ''}</span>
            </li>`;
        }).join('');

        // Cùng cách tách các khoản giảm với phiếu in (pos.receipt).
        const botMon = (don.items || []).reduce((t, l) => t + (Number(l.discount_amount) || 0), 0);
        const giamTay = Number(don.order_discount_amount) || 0;
        const giamKm = Number(don.promotion_discount) || 0;
        const giamHang = Number(don.rank_discount) || 0;
        const tienDiem = Number(don.points_amount) || 0;
        const giamMa = Math.max(0, (Number(don.discount_amount) || 0) - giamTay - giamKm - giamHang - tienDiem);
        const tot = [
            ['Tiền hàng', num((Number(don.subtotal_amount) || 0) + botMon)],
            botMon ? ['Bớt từng món', `−${num(botMon)}`, 'is-off'] : null,
            giamTay ? ['Giảm cả đơn', `−${num(giamTay)}`, 'is-off'] : null,
            giamKm ? ['Khuyến mãi', `−${num(giamKm)}`, 'is-off'] : null,
            giamMa ? [`Mã giảm giá${don.voucher_code ? ` ${esc(don.voucher_code)}` : ''}`, `−${num(giamMa)}`, 'is-off'] : null,
            giamHang ? ['Ưu đãi hạng thành viên', `−${num(giamHang)}`, 'is-off'] : null,
            tienDiem ? [`Đổi ${num(don.points_used)} điểm`, `−${num(tienDiem)}`, 'is-off'] : null,
            Number(don.surcharge_amount) ? [`Phụ thu${don.surcharge_note ? `: ${esc(don.surcharge_note)}` : ''}`, num(don.surcharge_amount)] : null,
            Number(don.vat_amount) ? ['VAT', num(don.vat_amount)] : null,
        ].filter(Boolean).map(([a, b, c]) => `<div class="${c || ''}"><dt>${a}</dt><dd class="t-num">${b}</dd></div>`).join('');

        // Lượt thu: sổ thu tiền; đơn thu một lần lúc bán thì sổ trống — dựng một dòng từ chính đơn.
        let luot = (don.luot_thu || []).map((p) => ({ m: p.payment_method, a: p.amount, t: ngay(p.paid_at || p.created_at), note: p.note }));
        if (!luot.length && r.trang_thai !== 'cancelled' && Number(r.da_thu) > 0) {
            luot = [{ m: don.payment_method, a: r.da_thu, t: ngay(don.confirmed_at || don.created_at) }];
        }
        const tienKhach = don.amount_tendered != null ? ` · khách đưa ${num(don.amount_tendered)}, thối ${num(don.change_amount)}` : '';
        const pays = luot.map((p, i) => `<li><span><b>${esc(tenPm(p.m))}</b><small>${hm(p.t)} ${dmy(p.t)}${p.note ? ` · ${esc(p.note)}` : ''}${i === 0 && p.m === 'cash' ? tienKhach : ''}</small></span><span class="t-num">${num(p.a)}</span></li>`).join('');

        let alert = '';
        if (r.trang_thai === 'cancelled') alert = `<div class="hs-alert hs-alert--danger"><i class="fa-solid fa-ban"></i><span>Đơn đã huỷ${don.cancelled_at ? ` lúc ${hm(ngay(don.cancelled_at))} ${dmy(ngay(don.cancelled_at))}` : ''}.${don.cancel_reason ? ` Lý do: ${esc(don.cancel_reason)}` : ''}</span></div>`;
        else if (choQr(r)) alert = '<div class="hs-alert hs-alert--warn"><i class="fa-solid fa-qrcode"></i><span>Đang chờ tiền QR. Tiền về thì đơn tự sang “Đã thanh toán”.</span></div>';
        else if (etax && etax.status === 'failed') alert = `<div class="hs-alert hs-alert--danger"><i class="fa-solid fa-file-circle-exclamation"></i><span>Hoá đơn điện tử lỗi${etax.error ? `: ${esc(etax.error)}` : ''}. Phát hành lại ở Quản trị → Hoá đơn điện tử.</span></div>`;

        const L = luat(r, don, etax);
        const hdBox = etax
            ? `<div class="hs-box"><b>${etax.invoice_no ? `Số ${esc(etax.invoice_no)}` : 'Đã lập'}</b><span>${esc([etax.symbol, ETAX[etax.status] || etax.status].filter(Boolean).join(' · '))}</span></div>`
            : `<div class="hs-box"><span>Chưa xuất${L.hoaDon.ok ? '' : ` — ${esc(L.hoaDon.why)}`}</span>${L.hoaDon.ok ? '<button type="button" class="link-btn" data-act="einvoice">Xuất hoá đơn</button>' : ''}</div>`;

        // Đơn còn nợ: hai dòng ngay dưới tổng — đã thu bao nhiêu, còn bao nhiêu.
        const no = Number(r.con_no) > 0 && !choQr(r) && r.trang_thai !== 'cancelled'
            ? `<div><dt>Đã thu</dt><dd class="t-num">${num(r.da_thu)}</dd></div><div class="is-owe"><dt>Còn nợ</dt><dd class="t-num">${num(r.con_no)}</dd></div>` : '';

        let chinh = '';
        if (choQr(r)) chinh = '<button type="button" class="btn btn--primary" data-act="qr"><i class="fa-solid fa-money-bill-transfer"></i>Thu cách khác</button>';
        else if (L.thuNo) chinh = '<button type="button" class="btn btn--primary" data-act="debt"><i class="fa-solid fa-hand-holding-dollar"></i>Thu nợ</button>';
        else if (L.inLai.ok) chinh = '<button type="button" class="btn btn--primary" data-act="print"><i class="fa-solid fa-print"></i>In lại phiếu</button>';
        const phu = [];
        if (L.thuNo && L.inLai.ok) phu.push('<button type="button" class="btn btn--soft" data-act="print"><i class="fa-solid fa-print"></i>In lại</button>');
        if (!choQr(r) && r.trang_thai !== 'cancelled') {
            phu.push(L.traHang.ok
                ? `<a class="btn btn--soft" href="${esc(cfg.returnsUrl)}" data-act-link="return"><i class="fa-solid fa-rotate-left"></i>Trả hàng</a>`
                : `<button type="button" class="btn btn--soft" aria-disabled="true" title="${esc(L.traHang.why)}" data-act="return-why"><i class="fa-solid fa-rotate-left"></i>Trả hàng</button>`);
        }
        const menu = [
            !etax ? { act: 'einvoice', icon: 'fa-file-invoice', t: 'Xuất hoá đơn điện tử', r: L.hoaDon } : null,
            choQr(r) ? { act: 'cancel-qr', icon: 'fa-ban', t: 'Huỷ mã QR (huỷ đơn)', r: { ok: true } } : null,
            { act: 'copy', icon: 'fa-copy', t: 'Sao chép mã đơn', r: { ok: true } },
            cfg.adminOrdersUrl ? { act: 'admin', icon: 'fa-up-right-from-square', t: 'Mở trong Quản trị', r: { ok: true } } : null,
        ].filter(Boolean);
        const why = !L.traHang.ok && !choQr(r) && r.trang_thai !== 'cancelled' ? `<p class="hs-why"><i class="fa-solid fa-circle-info"></i>Trả hàng: ${esc(L.traHang.why)}</p>` : '';
        const nhatKy = dongThoiGian(don, etax);

        paper.innerHTML = `
            <div class="hs-d-head hs-anim">${top}${khach}</div>
            <div class="hs-dtabs" role="tablist">
                <button type="button" role="tab" id="hsTabD" aria-controls="hsPanD" aria-selected="${tab === 'detail'}" data-tab="detail">Chi tiết · ${(don.items || []).length} món</button>
                <button type="button" role="tab" id="hsTabH" aria-controls="hsPanH" aria-selected="${tab === 'log'}" data-tab="log">Lịch sử thao tác</button>
            </div>
            <div class="hs-d-body hs-anim">
                <div role="tabpanel" id="hsPanD" aria-labelledby="hsTabD" ${tab === 'detail' ? '' : 'hidden'}>
                    ${alert}
                    <ul class="hs-items">${items}</ul>
                    <dl class="hs-tot">${tot}<div class="is-grand"><dt>Khách cần trả</dt><dd class="t-num">${vnd(don.total_amount)}</dd></div>${no}</dl>
                    ${pays ? `<p class="hs-sec">Thanh toán</p><ul class="hs-pay">${pays}</ul>` : ''}
                    ${r.trang_thai === 'cancelled' || choQr(r) ? '' : `<p class="hs-sec">Hoá đơn điện tử</p>${hdBox}`}
                    ${don.note ? `<p class="hs-sec">Ghi chú</p><p class="hs-note">${esc(don.note)}</p>` : ''}
                </div>
                <div role="tabpanel" id="hsPanH" aria-labelledby="hsTabH" ${tab === 'log' ? '' : 'hidden'}>
                    ${nhatKy.length ? `<ul class="hs-tl">${nhatKy.map((e) => `<li style="--tl:${e.c}"><span class="hs-tl__dot"></span><span><b>${esc(e.b)}</b>${e.s ? `<small>${esc(e.s)}</small>` : ''}<time>${hm(e.t)} · ${dmy(e.t)}</time></span></li>`).join('')}</ul>` : '<p class="hs-why" style="margin-top:12px">Chưa có thao tác nào được ghi.</p>'}
                </div>
            </div>
            <div class="hs-d-foot">
                <div class="hs-actions">
                    ${chinh}${phu.join('')}
                    <div class="hs-more">
                        <button type="button" aria-label="Thao tác khác" aria-haspopup="menu" aria-expanded="false" data-more><i class="fa-solid fa-ellipsis"></i></button>
                        <div class="hs-menu" role="menu" hidden data-menu>
                            ${menu.map((m) => `<button type="button" role="menuitem" data-act="${m.act}" ${m.r.ok ? '' : 'disabled'}><i class="fa-solid ${m.icon}"></i><span>${m.t}</span>${m.r.ok ? '' : `<small>${esc(m.r.why)}</small>`}</button>`).join('')}
                        </div>
                    </div>
                </div>
                ${why}
            </div>`;
        paper.classList.toggle('is-swap', !!doiDon);
    }

    /* ------------------------------------------------------------ thao tác */
    const parseMoney = (s) => Number(String(s).replace(/\D/g, '')) || 0;
    const chonNut = (box, v) => $$('button', box).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === v)));
    const daChon = (box) => $('[aria-pressed="true"]', box)?.dataset.v;
    const dongMenu = () => $$('[data-menu]').forEach((m) => { m.hidden = true; });
    let hoi = null;

    function hoiXacNhan(tieuDe, noiDung, nutOk, lam) {
        const d = $('#hsAsk');
        $('[data-ask-title]', d).textContent = tieuDe;
        $('[data-ask-body]', d).innerHTML = noiDung;
        $('[data-ask-ok]', d).textContent = nutOk;
        $('[data-ask-ok]', d).disabled = false;
        hoi = lam;
        d.showModal();
        $('[data-ask-no]', d).focus();
    }

    function lamViec(ten) {
        dongMenu();
        if (!dangMo) return;
        const { row: r, don } = dangMo;
        if (ten === 'print') {
            // Hộp In lại của khung quầy: chọn khổ / số bản, in bằng khung ẩn — không mở tab.
            window.posInLai({ id: r.id, ma: r.ma, loai: r.loai === 'tra-hang' ? 'tra' : 'don' });
        } else if (ten === 'copy') {
            (navigator.clipboard?.writeText(r.ma) || Promise.reject()).then(() => posToast(`Đã chép ${r.ma}`), () => posToast(r.ma));
        } else if (ten === 'admin') {
            location.href = `${cfg.adminOrdersUrl}?keyword=${encodeURIComponent(r.ma)}`;
        } else if (ten === 'return-why') {
            posToast(luat(r, don, dangMo.etax).traHang.why, 'error');
        } else if (ten === 'debt') {
            const d = $('#hsDebt');
            $('[data-debt-code]', d).textContent = r.ma;
            $('[data-debt-total]', d).textContent = vnd(r.tong_tien);
            $('[data-debt-paid]', d).textContent = vnd(r.da_thu);
            $('[data-debt-owe]', d).textContent = vnd(r.con_no);
            $('[data-debt-amt]', d).value = num(r.con_no);
            $('[data-debt-note]', d).value = '';
            $('[data-debt-err]', d).hidden = true;
            $('[data-debt-ok]', d).disabled = false;
            chonNut($('[data-debt-pm]', d), 'cash');
            d.showModal();
            $('[data-debt-amt]', d).select();
        } else if (ten === 'qr') {
            $('[data-qr-total]').textContent = vnd(r.tong_tien);
            chonNut($('[data-qr-pm]'), 'cash');
            $('[data-qr-ok]').disabled = false;
            $('#hsQr').showModal();
        } else if (ten === 'cancel-qr') {
            hoiXacNhan('Huỷ mã QR', `<p style="margin:0">Huỷ mã QR của <b>${esc(r.ma)}</b>? Đơn sẽ chuyển sang <b>Đã huỷ</b>, hàng trả về kho. Không hoàn tác được.</p>`, 'Huỷ mã QR', async () => {
                const body = await goi(url(cfg.cancelQrUrl, r.id), { method: 'POST', body: '{}' });
                taiLai(r.id, body.message || `Đã huỷ mã QR của ${r.ma}.`);
            });
        } else if (ten === 'einvoice') {
            const mua = [don.buyer_company && `Đơn vị: ${esc(don.buyer_company)}`, don.buyer_tax_code && `MST: ${esc(don.buyer_tax_code)}`,
                `Người mua: ${esc(don.recipient_name || 'Khách lẻ')}`, don.buyer_address && `Địa chỉ: ${esc(don.buyer_address)}`, don.recipient_email && `Email: ${esc(don.recipient_email)}`].filter(Boolean);
            hoiXacNhan('Xuất hoá đơn điện tử', `<p style="margin:0">Phát hành hoá đơn cho <b>${esc(r.ma)}</b> · ${vnd(r.tong_tien)} theo thông tin người mua đã lưu trên đơn:</p><div class="hs-confirm">${mua.map((x) => `<span>${x}</span>`).join('')}</div><p class="hs-why">Cần sửa thông tin người mua thì sửa ở Quản trị → Đơn hàng trước khi xuất.</p>`, 'Phát hành', async () => {
                const body = await goi(url(cfg.einvoiceUrl, r.id), { method: 'POST', body: '{}' });
                taiLai(r.id, body.message || `Đã phát hành hoá đơn cho ${r.ma}.`);
            });
        }
    }

    $('[data-debt-amt]').addEventListener('input', (e) => {
        const v = parseMoney(e.target.value);
        e.target.value = v ? num(v) : '';
        const owe = Number(dangMo?.row.con_no) || 0;
        const err = $('[data-debt-err]');
        err.hidden = v > 0 && v <= owe;
        err.textContent = v > owe ? `Vượt số còn nợ (${vnd(owe)}).` : 'Nhập số tiền thu.';
        $('[data-debt-ok]').disabled = !err.hidden;
    });
    $('[data-debt-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const r = dangMo.row;
        const v = parseMoney($('[data-debt-amt]').value);
        if (v <= 0 || v > Number(r.con_no)) return;
        const nut = $('[data-debt-ok]');
        nut.disabled = true;
        try {
            await goi(url(cfg.debtUrl, r.id), { method: 'POST', body: JSON.stringify({ amount: v, payment_method: daChon($('[data-debt-pm]')), note: $('[data-debt-note]').value.trim() }) });
            taiLai(r.id, `Đã ghi ${vnd(v)} cho ${r.ma}${v >= Number(r.con_no) ? ' — đơn đã thu đủ' : ''}.`);
        } catch (err) {
            nut.disabled = false;
            const el = $('[data-debt-err]');
            el.hidden = false;
            el.textContent = err.message;
        }
    });
    $('[data-qr-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const r = dangMo.row;
        const nut = $('[data-qr-ok]');
        nut.disabled = true;
        const pm = daChon($('[data-qr-pm]'));
        try {
            await goi(url(cfg.switchUrl, r.id), { method: 'POST', body: JSON.stringify({ payment_method: pm }) });
            taiLai(r.id, `Đã thu ${r.ma} bằng ${tenPm(pm).toLowerCase()}, mã QR đã huỷ.`);
        } catch (err) {
            nut.disabled = false;
            posToast(err.message, 'error');
        }
    });
    $('[data-ask-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!hoi) return;
        const nut = $('[data-ask-ok]');
        nut.disabled = true;
        try { await hoi(); } catch (err) { nut.disabled = false; posToast(err.message, 'error'); }
    });

    /* ------------------------------------------------------------ ô chọn kỳ
       Lịch một tháng: bấm ngày đầu rồi ngày cuối; hai ô gõ tay dd/mm/yyyy đi cùng.
       Không dùng <input type="date"> vì nó hiện ngày theo ngôn ngữ trình duyệt
       (máy để tiếng Anh ra "10/01/2026" — đọc thành mùng 10 tháng 1). */
    const ky = $('[data-ky-pop]');
    const nutKy = $('[data-ky-btn]');
    const oTu = $('[data-ky-tu]', ky);
    const oDen = $('[data-ky-den]', ky);
    const tuNgay = (iso) => { const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || ''); return m ? new Date(+m[1], m[2] - 1, +m[3]) : null; };
    const raIso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    const docNgay = (txt) => {
        const m = /^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$/.exec(txt.trim());
        if (!m) return null;
        const d = new Date(+m[3], m[2] - 1, +m[1]);
        return d.getDate() === +m[1] && d.getMonth() === m[2] - 1 ? d : null;
    };
    const HOM_NAY = tuNgay(ky.dataset.today) || new Date();
    let kyTu = tuNgay(ky.dataset.from);
    let kyDen = tuNgay(ky.dataset.to);
    let kyThang = new Date((kyDen || HOM_NAY).getFullYear(), (kyDen || HOM_NAY).getMonth(), 1);

    function veLich() {
        const nam = kyThang.getFullYear();
        const thg = kyThang.getMonth();
        $('[data-ky-ten]', ky).textContent = `Tháng ${thg + 1}/${nam}`;
        $('[data-ky-toi]', ky).disabled = nam > HOM_NAY.getFullYear() || (nam === HOM_NAY.getFullYear() && thg >= HOM_NAY.getMonth());
        const lui = (new Date(nam, thg, 1).getDay() + 6) % 7; // tuần bắt đầu thứ Hai
        const soNgay = new Date(nam, thg + 1, 0).getDate();
        let html = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].map((x) => `<span>${x}</span>`).join('') + '<span></span>'.repeat(lui);
        for (let n = 1; n <= soNgay; n++) {
            const d = new Date(nam, thg, n);
            const lop = [];
            if (+d === +HOM_NAY) lop.push('is-hom-nay');
            if (kyTu && +d === +kyTu) lop.push('is-dau');
            if (kyDen && +d === +kyDen) lop.push('is-cuoi');
            if (kyTu && kyDen && d > kyTu && d < kyDen) lop.push('is-trong');
            html += `<button type="button" class="${lop.join(' ')}" data-ky-chon="${raIso(d)}" ${d > HOM_NAY ? 'disabled' : ''}>${n}</button>`;
        }
        $('[data-ky-ngay]', ky).innerHTML = html;
        $('[data-ky-goi-y]', ky).textContent = kyTu && !kyDen ? 'Bấm ngày cuối, hoặc Xem để lấy một ngày' : 'Bấm ngày đầu rồi ngày cuối';
    }
    function ghiO() {
        oTu.value = kyTu ? dmy(kyTu) : '';
        oDen.value = kyDen ? dmy(kyDen) : '';
        oTu.removeAttribute('aria-invalid');
        oDen.removeAttribute('aria-invalid');
    }
    const dongKy = () => { ky.hidden = true; nutKy.setAttribute('aria-expanded', 'false'); };
    nutKy.addEventListener('click', () => {
        const mo = ky.hidden;
        dongKy();
        if (!mo) return;
        ky.hidden = false;
        nutKy.setAttribute('aria-expanded', 'true');
        ghiO();
        veLich();
    });
    $('[data-ky-dong]', ky).addEventListener('click', dongKy);
    $('[data-ky-lui]', ky).addEventListener('click', () => { kyThang = new Date(kyThang.getFullYear(), kyThang.getMonth() - 1, 1); veLich(); });
    $('[data-ky-toi]', ky).addEventListener('click', () => { kyThang = new Date(kyThang.getFullYear(), kyThang.getMonth() + 1, 1); veLich(); });
    $('[data-ky-ngay]', ky).addEventListener('click', (e) => {
        const b = e.target.closest('[data-ky-chon]');
        if (!b || b.disabled) return;
        const d = tuNgay(b.dataset.kyChon);
        if (!kyTu || kyDen) { kyTu = d; kyDen = null; } else if (d < kyTu) { kyTu = d; } else { kyDen = d; }
        ghiO();
        veLich();
    });
    [oTu, oDen].forEach((o) => o.addEventListener('change', () => {
        const d = o.value.trim() === '' ? null : docNgay(o.value);
        if (o.value.trim() !== '' && (!d || d > HOM_NAY)) { o.setAttribute('aria-invalid', 'true'); return; }
        o.removeAttribute('aria-invalid');
        if (o === oTu) kyTu = d; else kyDen = d;
        if (kyTu && kyDen && kyDen < kyTu) [kyTu, kyDen] = [kyDen, kyTu];
        if (d) kyThang = new Date(d.getFullYear(), d.getMonth(), 1);
        ghiO();
        veLich();
    }));
    // Bấm Xem: đọc thẳng hai ô (người dùng có thể gõ rồi bấm luôn, chưa rời ô). Ô sai thì
    // giữ nguyên chữ đã gõ, tô đỏ và không gửi.
    ky.addEventListener('submit', (e) => {
        const sai = [];
        const doc = (o) => {
            if (o.value.trim() === '') return null;
            const d = docNgay(o.value);
            if (!d || d > HOM_NAY) sai.push(o);
            return d;
        };
        let tu = doc(oTu);
        let den = doc(oDen);
        if (!tu && !sai.includes(oTu)) sai.push(oTu);
        [oTu, oDen].forEach((o) => (sai.includes(o) ? o.setAttribute('aria-invalid', 'true') : o.removeAttribute('aria-invalid')));
        if (sai.length) { e.preventDefault(); sai[0].focus(); return; }
        den = den || tu;
        if (den < tu) [tu, den] = [den, tu];
        $('[data-ky-tu-gt]', ky).value = raIso(tu);
        $('[data-ky-den-gt]', ky).value = raIso(den);
    });

    /* ------------------------------------------------------------ sự kiện */
    const pop = $('[data-pop]');
    const nutLoc = $('[data-filter-btn]');
    const dongLoc = () => { pop.hidden = true; nutLoc.setAttribute('aria-expanded', 'false'); };

    document.addEventListener('click', (e) => {
        const t = e.target;
        if (!t.closest('.hs-filter')) dongLoc();
        // t.isConnected: bấm một ngày làm lịch vẽ lại — nút vừa bấm đã rời trang, không phải bấm ra ngoài.
        if (!t.closest('.hs-ky') && t.isConnected) dongKy();
        if (!t.closest('.hs-more')) dongMenu();

        const li = t.closest('.hs-row');
        if (li) { chon(li); return; }
        if (t.closest('[data-filter-btn]')) {
            pop.hidden = !pop.hidden;
            nutLoc.setAttribute('aria-expanded', String(!pop.hidden));
            if (!pop.hidden) $('select', pop).focus();
            return;
        }
        const tb = t.closest('[data-tab]');
        if (tb) { tab = tb.dataset.tab; veChiTiet(false); $(`[data-tab="${tab}"]`).focus(); return; }
        if (t.closest('[data-back]')) { detail.classList.remove('is-open'); return; }
        if (t.closest('[data-retry]')) { const s = $('.hs-row[aria-selected="true"]'); if (s) chon(s); return; }
        const more = t.closest('[data-more]');
        if (more) {
            const m = $('[data-menu]');
            m.hidden = !m.hidden;
            more.setAttribute('aria-expanded', String(!m.hidden));
            if (!m.hidden) $('button:not(:disabled)', m)?.focus();
            return;
        }
        const a = t.closest('[data-act]');
        if (a && !a.disabled) { lamViec(a.dataset.act); return; }
        const pick = t.closest('.hs-pm-pick button');
        if (pick && !pick.disabled) chonNut(pick.parentElement, pick.dataset.v);
    });

    // Gõ tìm: dừng tay nửa giây là tìm (như v2), Enter thì tìm ngay.
    const oTim = $('#hsQ');
    let hen;
    oTim.addEventListener('input', () => { clearTimeout(hen); hen = setTimeout(() => $('#hsTim').requestSubmit(), 600); });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'F1') { e.preventDefault(); oTim.focus(); oTim.select(); return; }
        if (document.querySelector('dialog[open]')) return;
        if (e.key === 'Escape') {
            dongLoc(); dongMenu(); dongKy();
            if (detail.classList.contains('is-open')) detail.classList.remove('is-open');
            return;
        }
        const ae = document.activeElement;
        if (/INPUT|SELECT|TEXTAREA/.test(ae?.tagName) && ae !== oTim) return;
        if (!['ArrowDown', 'ArrowUp'].includes(e.key)) return;
        const ds = rowsEl();
        if (!ds.length) return;
        e.preventDefault();
        const i = ds.findIndex((x) => x.getAttribute('aria-selected') === 'true');
        const n = i < 0 ? 0 : e.key === 'ArrowDown' ? Math.min(ds.length - 1, i + 1) : Math.max(0, i - 1);
        if (n !== i) chon(ds[n], true);
    });

    // Lúc mở trang: báo kết quả thao tác vừa làm, rồi mở lại đơn đang xem (hoặc đơn mới nhất ở màn rộng).
    try {
        const msg = sessionStorage.getItem('hs.toast');
        if (msg) { sessionStorage.removeItem('hs.toast'); posToast(msg); }
    } catch (e) { /* máy chặn lưu */ }

    const canMo = new URLSearchParams(location.search).get('don');
    const theoUrl = canMo ? $(`.hs-row[data-id="${CSS.escape(canMo)}"][data-loai="don"]`) || $(`.hs-row[data-id="${CSS.escape(canMo)}"]`) : null;
    if (theoUrl) {
        chon(theoUrl);
    } else if (canMo && /^\d+$/.test(canMo)) {
        // Đơn vừa thao tác có thể đã rơi khỏi bộ lọc (thu đủ nợ dưới tab "Còn nợ") —
        // vẫn mở nó bên phải để thu ngân thấy kết quả.
        goi(url(cfg.detailUrl, canMo)).then((body) => {
            const d = body.data || {};
            const daThu = Number(d.da_thu) || 0;
            const conNo = Number(d.con_no) || 0;
            const st = d.status === 'cancelled' ? 'cancelled' : (d.payment_status === 'paid' || d.payment_status === 'refunded') ? 'paid' : daThu > 0 ? 'partial' : 'unpaid';
            dangMo = {
                row: { loai: 'don', id: d.id, ma: d.order_code, created_at: d.created_at, khach_hang: d.recipient_name, so_dien_thoai: d.recipient_phone, nguoi_tao: d.created_by_name, tong_tien: d.total_amount, da_thu: daThu, con_no: conNo, trang_thai: st, phuong_thuc: d.payment_method, xuat_duoc_hoa_don: false },
                don: d, etax: body.etax || null,
            };
            if (innerWidth < 900) detail.classList.add('is-open');
            veChiTiet(true);
        }).catch(() => {});
    } else if (innerWidth >= 900 && rowsEl()[0]) {
        chon(rowsEl()[0]);
    }
    rowsEl()[0]?.setAttribute('tabindex', '0');
})();
</script>
@endpush
