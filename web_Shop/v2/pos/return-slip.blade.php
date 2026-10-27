{{--
    Phiếu trả hàng khổ in nhiệt 58 / 80mm — in từ màn Đơn hàng của quầy (dòng
    "Trả hàng"). Đọc mẫu "Phiếu trả hàng" của chi nhánh (Cài đặt thiết bị), cùng
    khuôn chữ với phiếu tính tiền (v2::pos.receipt): không layout, không đặt khổ
    trang, chữ đen nền trắng.
--}}
@php
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $hien ??= fn (string $ma) => true;
    $tuIn ??= true;
    $don = (array) ($tra['order'] ?? []);
    $luc = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('H:i d/m/Y') : '';
    $hep = $khoGiay === '58';
    $maPhieu = (string) ($tra['return_code'] ?? '');

    // Lượt ĐỔI HÀNG: khách trả món cũ và lấy món mới ngay, tiền đi qua CHÊNH
    // LỆCH chứ không qua phiếu hoàn (API đặt refund_amount = 0, refund_method =
    // none). In theo khuôn phiếu hoàn thì tờ giấy ghi "HOÀN LẠI 0" trong khi két
    // vừa chi ra — hoặc vừa thu thêm — một khoản thật.
    $donMoi = (array) ($tra['exchange_order'] ?? []);
    $laDoi = $donMoi !== [];
    $tienTra = (float) ($tra['items_amount'] ?? 0) - (float) ($tra['deduction'] ?? 0);
    $tienMoi = (float) ($donMoi['total_amount'] ?? 0);
    $chenh = round($tienMoi - $tienTra);
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Phiếu trả {{ $maPhieu }}</title>
    <style>
        /* Không đặt `size` — khổ do driver máy in, xem chú thích ở pos/receipt. */
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body {
            width: {{ $hep ? '48' : '72' }}mm;
            margin: 0 auto;
            padding: 0 {{ $hep ? '1' : '2' }}mm;
            font-family: Arial, "Helvetica Neue", "Liberation Sans", "DejaVu Sans", sans-serif;
            font-variant-numeric: tabular-nums;
            font-size: {{ $hep ? '10.5px' : '12.5px' }};
            line-height: 1.35;
            color: #000;
            background: #fff;
        }
        .giua { text-align: center; }
        .nho { font-size: .86em; }
        .ten-tiem { margin: 0; font-size: 1.35em; font-weight: 700; text-transform: uppercase; }
        .tieu-de { margin: 2px 0; font-size: 1.1em; font-weight: 700; text-align: center; }
        .ke { margin: 5px 0; border-top: 1px dashed #000; }
        .ke-dam { margin: 5px 0; border-top: 2px solid #000; }
        .dong { display: flex; justify-content: space-between; gap: 6px; }
        .dong > span:last-child { white-space: nowrap; text-align: right; }
        .mon + .mon { margin-top: 4px; }
        .mon-ten { font-weight: 700; word-break: break-word; }
        .tong { font-size: {{ $hep ? '1.1em' : '1.25em' }}; font-weight: 700; }
        .tong > span { white-space: nowrap; }
        .ky { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 8px; text-align: center; }
        .ky span { display: block; height: 14mm; }
        .ban { padding: 3mm 0 6mm; }
        .ban + .ban { break-before: page; }
        .thanh-cong-cu { margin: 10px 0; text-align: center; }
        .thanh-cong-cu button { margin: 0 3px; padding: 6px 12px; border: 1px solid #999; border-radius: 4px; background: #fff; font: inherit; cursor: pointer; }
        @media print { .thanh-cong-cu { display: none; } }
    </style>
</head>
<body>
    @for($ban = 0; $ban < $soBan; $ban++)
    <div class="ban">
        <div class="giua">
            @if($hien('ten'))<h1 class="ten-tiem">{{ $tenCuaHang }}</h1>@endif
            @if($hien('diachi') && $diaChi !== '')<div class="nho">{{ $diaChi }}</div>@endif
        </div>
        <div class="ke"></div>
        <div class="tieu-de">{{ $laDoi ? 'PHIẾU ĐỔI HÀNG' : 'PHIẾU TRẢ HÀNG' }}</div>
        @if($hien('maphieu') || $hien('tgtra'))
            <div class="dong nho">
                <span>{{ $hien('maphieu') ? 'Số: '.$maPhieu : '' }}</span>
                <span>{{ $hien('tgtra') ? $luc($tra['created_at'] ?? null) : '' }}</span>
            </div>
        @endif
        @if($hien('khachtra') && filled($don['recipient_name'] ?? null))
            <div class="nho">Người trả: {{ $don['recipient_name'] }}{{ $hien('sdttra') && filled($don['recipient_phone'] ?? null) ? ' · '.$don['recipient_phone'] : '' }}</div>
        @endif

        @if($hien('sohd') || $hien('tg') || $hien('thungan') || $hien('khach') || $hien('pttt'))
            <div class="ke"></div>
            <div class="nho"><b>Đơn gốc</b>{{ $hien('sohd') ? ': '.($don['order_code'] ?? '') : '' }}</div>
            @if($laDoi && $hien('sohd') && filled($donMoi['order_code'] ?? null))<div class="nho"><b>Đơn mới</b>: {{ $donMoi['order_code'] }}</div>@endif
            @if($hien('tg') && filled($don['placed_at'] ?? $don['created_at'] ?? null))<div class="nho">Thanh toán lúc: {{ $luc($don['placed_at'] ?? $don['created_at']) }}</div>@endif
            @if($hien('thungan') && filled($don['created_by_name'] ?? null))<div class="nho">Thu ngân: {{ $don['created_by_name'] }}</div>@endif
            @if($hien('khach') && filled($don['recipient_name'] ?? null))<div class="nho">Khách: {{ $don['recipient_name'] }}</div>@endif
            @if($hien('pttt') && filled($don['payment_method'] ?? null))<div class="nho">Hình thức: {{ \App\Http\Controllers\OrderController::tenHinhThuc($don['payment_method']) }}</div>@endif
        @endif

        <div class="ke"></div>
        @if($laDoi)<div class="nho"><b>KHÁCH TRẢ LẠI</b></div>@endif
        @foreach(($tra['items'] ?? []) as $it)
            @php $bt = trim((string) ($it['variant_name'] ?? '')); @endphp
            <div class="mon">
                <div class="mon-ten">{{ $it['product_name'] ?? '' }}{{ $hien('bienthe') && $bt !== '' ? ' ('.$bt.')' : '' }}</div>
                <div class="dong">
                    <span>{{ (int) ($it['quantity'] ?? 0) }}{{ $hien('dvt') && filled($it['unit_name'] ?? null) ? ' '.$it['unit_name'] : '' }} × {{ $so($it['unit_price'] ?? 0) }}</span>
                    <span>{{ $so($it['total_price'] ?? ((float) ($it['unit_price'] ?? 0) * (int) ($it['quantity'] ?? 0))) }}</span>
                </div>
            </div>
        @endforeach

        @if($laDoi)
            <div class="ke"></div>
            <div class="nho"><b>KHÁCH LẤY VỀ</b></div>
            @foreach(($donMoi['items'] ?? []) as $it)
                @php $bt = trim((string) ($it['variant_name'] ?? '')); @endphp
                <div class="mon">
                    <div class="mon-ten">{{ $it['product_name'] ?? '' }}{{ $hien('bienthe') && $bt !== '' ? ' ('.$bt.')' : '' }}</div>
                    <div class="dong">
                        <span>{{ (int) ($it['quantity'] ?? 0) }}{{ $hien('dvt') && filled($it['unit_name'] ?? null) ? ' '.$it['unit_name'] : '' }} × {{ $so($it['unit_price'] ?? 0) }}</span>
                        <span>{{ $so($it['total_price'] ?? ((float) ($it['unit_price'] ?? 0) * (int) ($it['quantity'] ?? 0))) }}</span>
                    </div>
                </div>
            @endforeach
        @endif

        <div class="ke-dam"></div>
        @if($laDoi)
            {{-- Ba dòng này mới là chứng từ của khoản tiền thật sự đổi tay. --}}
            <div class="dong"><span>Tiền hàng trả</span><span>{{ $so($tra['items_amount'] ?? 0) }}</span></div>
            @if((float) ($tra['deduction'] ?? 0) > 0)
                <div class="dong"><span>Khấu trừ</span><span>-{{ $so($tra['deduction']) }}</span></div>
            @endif
            <div class="dong"><span>Tiền hàng mới</span><span>{{ $so($tienMoi) }}</span></div>
            <div class="dong tong">
                <span>{{ $chenh > 0 ? 'KHÁCH TRẢ THÊM' : ($chenh < 0 ? 'TRẢ LẠI KHÁCH' : 'KHÔNG CHÊNH LỆCH') }}</span>
                <span>{{ $so(abs($chenh)) }}</span>
            </div>
            @if($chenh != 0 && $hien('pthoan') && filled($donMoi['payment_method'] ?? null))
                <div class="dong"><span>Hình thức</span><span>{{ \App\Http\Controllers\OrderController::tenHinhThuc($donMoi['payment_method']) }}</span></div>
            @endif
        @else
            @if((float) ($tra['deduction'] ?? 0) > 0)
                <div class="dong"><span>Tiền hàng trả</span><span>{{ $so($tra['items_amount'] ?? 0) }}</span></div>
                <div class="dong"><span>Khấu trừ</span><span>-{{ $so($tra['deduction']) }}</span></div>
            @endif
            @if($hien('tienhoan'))
                <div class="dong tong"><span>HOÀN LẠI</span><span>{{ $so($tra['refund_amount'] ?? 0) }}</span></div>
            @endif
            @if($hien('pthoan') && filled($tra['refund_method'] ?? null))
                <div class="dong"><span>Hình thức hoàn</span><span>{{ \App\Http\Controllers\ReturnController::REFUND_METHODS[$tra['refund_method']] ?? $tra['refund_method'] }}</span></div>
            @endif
        @endif
        <div class="ke-dam"></div>
        @if($hien('lydo') && filled($tra['reason'] ?? null))
            <div class="nho">Lý do: {{ \App\Http\Controllers\ReturnController::REASONS[$tra['reason']] ?? $tra['reason'] }}</div>
        @endif
        @if($hien('noidung') && filled($tra['reason_note'] ?? null))
            <div class="nho">Ghi chú: {{ $tra['reason_note'] }}</div>
        @endif

        @if($hien('kyCuaHang') || $hien('kyKhach'))
            <div class="ky nho">
                @if($hien('kyCuaHang'))<div>Cửa hàng<span></span></div>@endif
                @if($hien('kyKhach'))<div>Khách hàng<span></span></div>@endif
            </div>
        @endif
    </div>
    @endfor

    @if($tuIn)
        <div class="thanh-cong-cu">
            <button type="button" onclick="window.print()">In lại</button>
            <button type="button" onclick="window.close()">Đóng</button>
        </div>
        <script>
            window.addEventListener('load', function () { window.setTimeout(function () { window.print(); }, 120); });
        </script>
    @endif
</body>
</html>
