{{--
    Biên nhận phiếu thu / phiếu chi lập ở quầy — khổ in nhiệt 80 / 58mm, cùng
    khuôn chữ với phiếu tính tiền (v2::pos.receipt): không layout, chữ đen nền
    trắng, một cột. Hai chỗ ký vì tạm ứng, nộp tiền về chủ, trả ship… người đưa
    và người nhận tiền đều phải ký.

    Người giao / Người nhận theo chiều tiền: phiếu CHI thì quầy giao, người nhận
    là đối tượng của phiếu; phiếu THU thì ngược lại.
--}}
@php
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $hep = $khoGiay === '58';
    $thu = (int) ($phieu['type'] ?? 1) === 0;
    $luc = \Illuminate\Support\Carbon::parse($phieu['created_at'] ?? now());
    $ma = (string) (($phieu['code'] ?? '') !== '' ? $phieu['code'] : '#'.($phieu['id'] ?? ''));
    $hinhThuc = \App\Http\Controllers\CashbookController::PHUONG_THUC[$phieu['payment_method'] ?? ''] ?? (string) ($phieu['payment_method'] ?? '');
    $doiTuong = trim((string) ($phieu['payer_name'] ?? ''));
    $nguoiLap = trim((string) ($phieu['created_by_name'] ?? ''));
    [$giao, $nhan] = $thu ? [$doiTuong, $nguoiLap] : [$nguoiLap, $doiTuong];
    // Nộp về công ty: thu ngân NỘP, người nhận là nhân viên được giao mang tiền đi.
    $nopVe = ($phieu['kind'] ?? '') === 'deposit';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Biên nhận {{ $ma }}</title>
    <style>
        /* Không đặt `size`: khổ cuộn là việc của driver máy in (xem pos/receipt). */
        @page { margin: 0; }
        * { box-sizing: border-box; }
        html { background: #fff; }
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
        .dam { font-weight: 700; }
        .nho { font-size: .86em; }
        .ten-tiem { margin: 0; font-size: 1.35em; font-weight: 700; line-height: 1.25; text-transform: uppercase; }
        .tieu-de { margin: 2px 0 0; font-size: 1.1em; font-weight: 700; letter-spacing: .5px; text-align: center; }
        .ke { margin: 5px 0; border-top: 1px dashed #000; }
        .dong { display: flex; justify-content: space-between; gap: 6px; }
        .dong > span:first-child { white-space: nowrap; }
        .dong > span:last-child { min-width: 0; text-align: right; word-break: break-word; }
        .tien { font-size: {{ $hep ? '1.25em' : '1.45em' }}; font-weight: 700; }
        .tien > span { white-space: nowrap; }
        .chu { margin-top: 2px; font-style: italic; }
        .muc { margin-top: 3px; }
        .muc b { display: block; }
        .ky { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 8px; text-align: center; }
        .ky .cho { display: block; height: 16mm; }
        .ban { padding: 3mm 0 6mm; }
        .thanh-cong-cu { margin: 10px 0; text-align: center; }
        .thanh-cong-cu a, .thanh-cong-cu button {
            display: inline-block; margin: 0 3px 4px; padding: 6px 12px; border: 1px solid #999; border-radius: 4px;
            background: #fff; font: inherit; color: #000; text-decoration: none; cursor: pointer;
        }
        @media print { .thanh-cong-cu { display: none; } }
    </style>
</head>
<body>
    <div class="ban">
        <div class="giua">
            <h1 class="ten-tiem">{{ $tenCuaHang }}</h1>
            @if($tenChiNhanh !== '')<div class="nho">{{ $tenChiNhanh }}</div>@endif
            @if($diaChi !== '')<div class="nho">{{ $diaChi }}</div>@endif
        </div>
        <div class="ke"></div>
        <div class="tieu-de">{{ $nopVe ? 'BIÊN NHẬN NỘP TIỀN VỀ CÔNG TY' : 'BIÊN NHẬN '.($thu ? 'PHIẾU THU' : 'PHIẾU CHI') }}</div>
        <div class="giua dam">{{ $ma }}</div>
        <div class="ke"></div>

        <div class="dong"><span>Ngày giờ</span><span>{{ $luc->format('H:i d/m/Y') }}</span></div>
        <div class="dong"><span>Ca</span><span>{{ ! empty($phieu['shift_id']) ? '#'.$phieu['shift_id'] : 'Ngoài ca' }}</span></div>
        <div class="dong"><span>Loại phiếu</span><span>{{ $nopVe ? 'Nộp về công ty' : ($thu ? 'Phiếu thu' : 'Phiếu chi') }}{{ $hinhThuc !== '' ? ' · '.$hinhThuc : '' }}</span></div>
        <div class="ke"></div>

        <div class="dong tien"><span>{{ $nopVe ? 'Số tiền nộp' : ($thu ? 'Số tiền thu' : 'Số tiền chi') }}</span><span>{{ $so($phieu['amount'] ?? 0) }}</span></div>
        <div class="chu">Bằng chữ: {{ \App\Support\TienBangChu::doc((float) ($phieu['amount'] ?? 0)) }}.</div>
        <div class="ke"></div>

        @if(($phieu['bank_account_label'] ?? '') !== '')
            <div class="dong muc"><span>Tài khoản</span><span>{{ $phieu['bank_account_label'] }}</span></div>
        @endif
        @if(($phieu['adjusts_code'] ?? '') !== '')
            <div class="dong muc"><span>Điều chỉnh cho</span><span>{{ $phieu['adjusts_code'] }}</span></div>
        @endif
        <div class="muc"><b>Lý do</b>{{ ($phieu['note'] ?? '') !== '' ? $phieu['note'] : '—' }}</div>
        <div class="dong muc"><span>Phân loại</span><span>{{ ($phieu['category_name'] ?? '') !== '' ? $phieu['category_name'] : 'Chưa phân loại' }}</span></div>
        <div class="dong muc"><span>{{ $thu ? 'Người nộp' : 'Người nhận' }}</span><span>{{ $doiTuong !== '' ? $doiTuong : '—' }}</span></div>
        <div class="dong muc"><span>Người lập</span><span>{{ $nguoiLap !== '' ? $nguoiLap : '—' }}</span></div>
        @if(($phieu['approved_by_name'] ?? '') !== '')
            <div class="dong muc"><span>Người duyệt</span><span>{{ $phieu['approved_by_name'] }}</span></div>
        @endif
        <div class="ke"></div>

        <div class="ky">
            <div><span class="dam">{{ $nopVe ? 'Người nộp' : 'Người giao' }}</span><span class="cho"></span><span class="nho">{{ $giao }}</span></div>
            <div><span class="dam">Người nhận</span><span class="cho"></span><span class="nho">{{ $nhan }}</span></div>
        </div>
        <div class="giua nho" style="margin-top:8px">In lúc {{ now()->format('H:i d/m/Y') }}</div>
    </div>

    @if($tuIn)
        <div class="thanh-cong-cu">
            <button type="button" onclick="window.print()">In lại</button>
            <a href="?paper={{ $hep ? '80' : '58' }}">Đổi sang khổ {{ $hep ? '80' : '58' }}mm</a>
            <button type="button" onclick="window.close()">Đóng</button>
        </div>
        <script>
            window.addEventListener('load', function () {
                window.setTimeout(function () { window.print(); }, 120);
            });
        </script>
    @endif
</body>
</html>
