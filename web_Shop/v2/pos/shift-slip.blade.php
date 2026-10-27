{{--
    Phiếu kết ca / phiếu tạm / phiếu tổng hợp ca — khổ in nhiệt 58 / 80mm, cùng
    khuôn với phiếu tính tiền (v2::pos.receipt): không layout, chữ đen nền trắng,
    font đơn cách để cột tiền thẳng hàng.

    $ca != null  → phiếu MỘT ca ($tam = ca còn mở, in số tới giờ, chưa chốt).
    $tongHop     → phiếu tổng hợp các ca đã đóng theo bộ lọc lịch sử.
--}}
@php
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $dau = fn ($n) => abs((float) $n) < 0.5 ? '0 (khớp)' : ((float) $n > 0 ? '+' : '-').$so(abs((float) $n));
    $luc = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('H:i d/m/Y') : '';
    $ngay = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('d/m/Y') : '';
    // Người trực két; ca mở trước khi có ô này thì lùi về người bấm Mở ca.
    $tenTruc = fn ($c) => ($c['employee_name'] ?? '') !== '' ? $c['employee_name'] : ($c['opened_by_name'] ?? '');
    if ($ca) {
        $dongCa = ! empty($ca['closed_at']);
        $theoSo = $dongCa && isset($ca['expected_cash'])
            ? (float) $ca['expected_cash']
            : (float) ($ca['opening_cash'] ?? 0) + (float) ($ca['tong_thu'] ?? 0) - (float) ($ca['tong_chi'] ?? 0);
        $hoan = collect($soQuy)->where('reference_type', 'order_return')->sum('amount');
        $tay = collect($soQuy)->where('reference_type', 'manual');
    }
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>{{ $ca ? ($tam ? 'Phiếu tạm ca #' : 'Phiếu kết ca #').$ca['id'] : 'Tổng hợp ca' }}</title>
    <style>
        @page { size: {{ $khoGiay }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body {
            width: {{ $khoGiay === '58' ? '54' : '76' }}mm;
            margin: 0 auto;
            padding: 4mm 2mm 8mm;
            font-family: "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace;
            font-size: {{ $khoGiay === '58' ? '10.5px' : '12px' }};
            line-height: 1.45;
            color: #000;
            background: #fff;
        }
        .giua { text-align: center; }
        .dam { font-weight: 700; }
        .nho { font-size: .88em; }
        .ten-tiem { margin: 0; font-size: 1.25em; font-weight: 700; line-height: 1.45; }
        .ke { margin: 4px 0; border-top: 1px dashed #000; }
        .dong { display: flex; justify-content: space-between; gap: 6px; }
        .dong span:last-child { white-space: nowrap; }
        .lon { font-size: 1.15em; font-weight: 700; }
        .muc { margin-top: 4px; font-weight: 700; }
        .ky { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 8px; text-align: center; }
        .ky span { display: block; height: 14mm; }
        .tam { margin: 4px 0; padding: 2px 0; border: 1px solid #000; text-align: center; font-weight: 700; }
        .thanh-cong-cu { margin: 10px 0; text-align: center; }
        .thanh-cong-cu a, .thanh-cong-cu button {
            display: inline-block; margin: 0 3px; padding: 6px 12px; border: 1px solid #999; border-radius: 4px;
            background: #fff; font: inherit; color: #000; text-decoration: none; cursor: pointer;
        }
        @media print { .thanh-cong-cu { display: none; } }
    </style>
</head>
<body>
    <div class="giua">
        <h1 class="ten-tiem">{{ $tenCuaHang }}</h1>
        @if($tenChiNhanh !== '')<div class="nho">{{ $tenChiNhanh }}</div>@endif
        @if($diaChi !== '')<div class="nho">{{ $diaChi }}</div>@endif
    </div>
    <div class="ke"></div>

    @if($ca)
        <div class="giua dam">{{ $tam ? 'PHIẾU TẠM — CHƯA KẾT CA' : 'PHIẾU KẾT CA' }}</div>
        <div class="giua nho">Ca #{{ $ca['id'] }}</div>
        @if($tam)<div class="tam">Số liệu tới lúc in, chưa chốt</div>@endif
        <div class="ke"></div>
        <div class="dong"><span>Người trực</span><span>{{ $tenTruc($ca) }}</span></div>
        <div class="dong"><span>Người mở máy</span><span>{{ $ca['opened_by_name'] ?? '' }}</span></div>
        <div class="dong"><span>Mở ca</span><span>{{ $luc($ca['opened_at']) }}</span></div>
        @if($dongCa)
            <div class="dong"><span>Đóng ca</span><span>{{ $luc($ca['closed_at']) }}</span></div>
            @if(($ca['closed_by'] ?? null) != ($ca['opened_by'] ?? null))
                <div class="dong"><span>Người đóng</span><span>{{ $ca['closed_by_name'] ?? '' }}</span></div>
            @endif
        @else
            <div class="dong"><span>In lúc</span><span>{{ now()->format('H:i d/m/Y') }}</span></div>
        @endif
        <div class="ke"></div>

        <div class="muc">TIỀN MẶT TRONG KÉT</div>
        <div class="dong"><span>Tiền đầu ca</span><span>{{ $so($ca['opening_cash'] ?? 0) }}</span></div>
        <div class="dong"><span>+ Thu tiền mặt</span><span>{{ $so($ca['tong_thu'] ?? 0) }}</span></div>
        <div class="dong"><span>- Chi tiền mặt</span><span>{{ $so($ca['tong_chi'] ?? 0) }}</span></div>
        @if((float) ($ca['da_nop_ve'] ?? 0) > 0)
            <div class="dong nho"><span>&nbsp;&nbsp;Đã nộp về công ty trong ca</span><span>{{ $so($ca['da_nop_ve']) }}</span></div>
        @endif
        <div class="dong dam"><span>= Tiền theo sổ</span><span>{{ $so($theoSo) }}</span></div>
        @if($dongCa)
            <div class="dong dam"><span>Tiền đếm được</span><span>{{ $so($ca['counted_cash'] ?? 0) }}</span></div>
            <div class="dong lon"><span>Chênh lệch</span><span>{{ $dau($ca['difference'] ?? 0) }}</span></div>
            @if(isset($ca['delivered_cash']))
                <div class="dong"><span>Giao về chủ</span><span>{{ $so($ca['delivered_cash']) }}</span></div>
                <div class="dong"><span>Giữ lại cho ca sau</span><span>{{ $so($ca['kept_cash'] ?? 0) }}</span></div>
            @endif
        @endif
        @foreach(['opening_count' => 'Đếm đầu ca', 'counted_count' => 'Đếm lúc đóng'] as $cot => $nhan)
            @if(! empty($ca[$cot]))
                <div class="nho dam">{{ $nhan }}:</div>
                @foreach($ca[$cot] as $d)
                    <div class="dong nho"><span>{{ $so($d['menh_gia']) }} x {{ (int) $d['so_to'] }}</span><span>{{ $so($d['menh_gia'] * $d['so_to']) }}</span></div>
                @endforeach
            @endif
        @endforeach
        @if($hoan > 0)
            <div class="dong nho"><span>Trong đó hoàn tiền trả hàng</span><span>{{ $so($hoan) }}</span></div>
        @endif
        @foreach($tay as $e)
            <div class="dong nho"><span>{{ ($e['direction'] ?? '') === 'in' ? 'Thu' : 'Chi' }}: {{ $e['reason'] ?? '' }}</span><span>{{ ($e['direction'] ?? '') === 'in' ? '+' : '-' }}{{ $so($e['amount']) }}</span></div>
        @endforeach
        <div class="ke"></div>

        <div class="muc">BÁN HÀNG ({{ (int) ($doanhThu['order_count'] ?? 0) }} đơn)</div>
        <div class="dong"><span>Tiền mặt</span><span>{{ $so($doanhThu['total_cash'] ?? 0) }}</span></div>
        <div class="dong"><span>Chuyển khoản</span><span>{{ $so($doanhThu['total_transfer'] ?? 0) }}</span></div>
        <div class="dong"><span>Quẹt thẻ</span><span>{{ $so($doanhThu['total_card'] ?? 0) }}</span></div>
        <div class="dong"><span>QR tự động</span><span>{{ $so($doanhThu['total_auto_qr'] ?? 0) }}</span></div>
        <div class="dong dam"><span>Doanh thu</span><span>{{ $so($doanhThu['total_revenue'] ?? 0) }}</span></div>

        @if(filled($ca['close_note'] ?? null))
            <div class="ke"></div>
            <div class="nho">Ghi chú: {{ $ca['close_note'] }}</div>
        @endif
        <div class="ke"></div>
        <div class="ky nho"><div>Người giao<span></span></div><div>Người nhận<span></span></div></div>
    @else
        <div class="giua dam">TỔNG HỢP CA</div>
        <div class="giua nho">{{ $ngay($tongHop['tu']) }}{{ $tongHop['tu'] !== $tongHop['den'] ? ' - '.$ngay($tongHop['den']) : '' }}</div>
        <div class="ke"></div>
        @php $tongLech = 0; @endphp
        @forelse($tongHop['ds'] as $s)
            @php $tongLech += (float) ($s['difference'] ?? 0); @endphp
            <div class="dam">#{{ $s['id'] }} · {{ $tenTruc($s) }}</div>
            <div class="dong nho"><span>{{ \Illuminate\Support\Carbon::parse($s['opened_at'])->format('H:i d/m') }} - {{ \Illuminate\Support\Carbon::parse($s['closed_at'])->format('H:i d/m') }}</span><span></span></div>
            <div class="dong"><span>Theo sổ / Đếm</span><span>{{ $so($s['expected_cash'] ?? 0) }} / {{ $so($s['counted_cash'] ?? 0) }}</span></div>
            <div class="dong"><span>Chênh lệch</span><span>{{ $dau($s['difference'] ?? 0) }}</span></div>
            <div class="ke"></div>
        @empty
            <div class="giua">Không có ca nào đã đóng trong kỳ.</div>
            <div class="ke"></div>
        @endforelse
        <div class="dong dam"><span>Số ca</span><span>{{ count($tongHop['ds']) }}{{ $tongHop['total'] > count($tongHop['ds']) ? ' / '.$tongHop['total'] : '' }}</span></div>
        <div class="dong lon"><span>Tổng chênh lệch</span><span>{{ $dau($tongLech) }}</span></div>
        @if($tongHop['total'] > count($tongHop['ds']))
            <div class="nho">Chỉ in {{ count($tongHop['ds']) }} ca mới nhất — thu hẹp kỳ để in đủ.</div>
        @endif
    @endif

    <div class="giua nho" style="margin-top:6px">In lúc {{ now()->format('H:i d/m/Y') }}</div>

    <div class="thanh-cong-cu">
        <button type="button" onclick="window.print()">In lại</button>
        <a href="?{{ http_build_query(array_merge(request()->query(), ['paper' => $khoGiay === '58' ? '80' : '58'])) }}">Đổi sang khổ {{ $khoGiay === '58' ? '80' : '58' }}mm</a>
        <button type="button" onclick="window.close()">Đóng</button>
    </div>

    <script>
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 120);
        });
    </script>
</body>
</html>
