{{--
    Hoá đơn bán hàng khổ A5 / A4 cho máy in văn phòng — in từ hộp "In lại" của
    quầy khi khách cần tờ hoá đơn to (giao hàng, công ty lấy chứng từ). Đọc mẫu
    "Hoá đơn A5" / "Hoá đơn A4" của chi nhánh bán ra đơn (Cài đặt thiết bị).

    Khác phiếu nhiệt: ở đây ĐƯỢC đặt @page size — máy in giấy A5/A4 có sẵn khổ đó
    nên Chrome không phải thu nhỏ hay đặt giữa tờ như với cuộn nhiệt.
--}}
@php
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $phanTram = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $hien ??= fn (string $ma) => true;
    $tuIn ??= true;
    $d = $hien('donvitien') ? 'đ' : '';
    $items = collect($don['items'] ?? [])->filter(fn ($it) => $hien('qua') || empty($it['is_gift']))->values()->all();
    $tienHang = collect($items)->sum(fn ($it) => (float) ($it['unit_price'] ?? 0) * (int) ($it['quantity'] ?? 0));
    $botMon = collect($items)->sum(fn ($it) => (float) ($it['discount_amount'] ?? 0));
    $giamDon = max(0, (float) ($don['discount_amount'] ?? 0) - (float) ($don['points_amount'] ?? 0));
    $tienDiem = (float) ($don['points_amount'] ?? 0);
    $tietKiem = $botMon + $giamDon;
    $luc = \Illuminate\Support\Carbon::parse($don['placed_at'] ?? $don['created_at'] ?? now());
    $hinhThuc = ($don['payment_method'] ?? '') !== '' ? \App\Http\Controllers\OrderController::tenHinhThuc($don['payment_method']) : '';
    $khach = trim((string) ($don['recipient_name'] ?? ''));
    $sdt = trim((string) ($don['recipient_phone'] ?? ''));
    $diaChiKhach = trim(implode(', ', array_filter([
        $don['buyer_address'] ?? '', $don['shipping_address'] ?? '', $don['shipping_ward'] ?? '', $don['shipping_district'] ?? '', $don['shipping_province'] ?? '',
    ], fn ($x) => filled($x))));
    $hang = trim((string) (($don['thanh_vien'] ?? [])['hang'] ?? ''));
    $maDon = (string) ($don['order_code'] ?? '');
    $maVach = $hien('mavach') && $maDon !== '' ? \App\Support\MaVach::svg($maDon) : '';
    $ky = array_values(array_filter([
        ['ckXuatKho', 'Người xuất kho'], ['ckGiaoHang', 'Người giao hàng'], ['ckCuaHang', 'Cửa hàng'], ['ckKhach', 'Khách hàng'],
    ], fn ($k) => $hien($k[0])));
    $a4 = $khoGiay === 'a4';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Hoá đơn {{ $maDon }}</title>
    <style>
        @page { size: {{ $a4 ? 'A4' : 'A5' }} portrait; margin: {{ $a4 ? '12mm 14mm' : '8mm 9mm' }}; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, "Helvetica Neue", "Liberation Sans", sans-serif;
            font-variant-numeric: tabular-nums;
            font-size: {{ $a4 ? '12.5px' : '11px' }};
            line-height: 1.4;
            color: #000;
            background: #fff;
        }
        .to { width: 100%; max-width: {{ $a4 ? '182mm' : '130mm' }}; margin: 0 auto; }
        .to + .to { break-before: page; }
        .dau { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding-bottom: 8px; border-bottom: 2px solid #000; }
        .tiem { display: flex; gap: 10px; align-items: flex-start; min-width: 0; }
        .logo { max-height: {{ $a4 ? '20mm' : '15mm' }}; max-width: 30mm; filter: grayscale(1); }
        .ten-tiem { margin: 0 0 2px; font-size: 1.35em; text-transform: uppercase; }
        .nho { font-size: .9em; }
        .so-hd { text-align: right; white-space: nowrap; }
        .so-hd h2 { margin: 0 0 4px; font-size: 1.45em; letter-spacing: .5px; }
        .khach { display: grid; grid-template-columns: auto 1fr; gap: 2px 10px; margin: 10px 0; }
        .khach b { font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 5px; border: 1px solid #000; vertical-align: top; }
        th { background: #eee; font-weight: 700; text-align: center; white-space: nowrap; }
        td.so { text-align: right; white-space: nowrap; }
        td.giua { text-align: center; white-space: nowrap; }
        .phu { font-size: .88em; }
        .cuoi { display: flex; justify-content: space-between; gap: 16px; margin-top: 8px; }
        .cuoi-trai { flex: 1; min-width: 0; }
        .tong { min-width: {{ $a4 ? '78mm' : '62mm' }}; }
        .dong { display: flex; justify-content: space-between; gap: 10px; padding: 1px 0; }
        .dong > span:last-child { white-space: nowrap; }
        .dong.lon { margin-top: 3px; padding-top: 4px; border-top: 2px solid #000; font-size: 1.2em; font-weight: 700; }
        .chu { margin-top: 6px; font-style: italic; }
        .tiet-kiem { display: inline-block; margin-top: 6px; padding: 2px 8px; border: 1px dashed #000; font-weight: 700; }
        .qr { width: {{ $a4 ? '30mm' : '24mm' }}; filter: grayscale(1); }
        .khoi-qr { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
        .ky { display: grid; grid-template-columns: repeat({{ max(1, count($ky)) }}, 1fr); gap: 8px; margin-top: 14px; text-align: center; }
        .ky b { display: block; }
        .ky i { display: block; font-size: .85em; margin-bottom: {{ $a4 ? '20mm' : '14mm' }}; }
        .chan { margin-top: 10px; text-align: center; }
        .ma-vach svg { display: block; width: 50mm; height: 9mm; margin: 4px auto 0; }
        .ma-vach div { text-align: center; font-size: .85em; letter-spacing: 1px; }
        .thanh-cong-cu { margin: 12px 0; text-align: center; }
        .thanh-cong-cu button { margin: 0 3px; padding: 6px 14px; border: 1px solid #999; border-radius: 4px; background: #fff; font: inherit; cursor: pointer; }
        @media screen { body { padding: 10px; background: #e9ecef; } .to { background: #fff; padding: 10mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); } .to + .to { margin-top: 12px; } }
        @media print { .thanh-cong-cu { display: none; } }
    </style>
</head>
<body>
    @for($ban = 0; $ban < $soBan; $ban++)
    <div class="to">
        <div class="dau">
            <div class="tiem">
                @if($logo !== '')
                    <img class="logo" src="{{ $logo }}" alt="" onerror="this.remove()">
                @endif
                <div>
                    @if($hien('ten'))<h1 class="ten-tiem">{{ $tenCuaHang }}</h1>@endif
                    @if($hien('diachi') && $diaChi !== '')<div class="nho">{{ $diaChi }}</div>@endif
                    @if($hien('sdt') && $dienThoai !== '')<div class="nho">ĐT: {{ $dienThoai }}</div>@endif
                </div>
            </div>
            <div class="so-hd">
                <h2>HOÁ ĐƠN BÁN HÀNG</h2>
                @if($hien('sohd'))<div>Số: <b>{{ $maDon }}</b></div>@endif
                @if($hien('tg'))<div class="nho">{{ $luc->format('H:i \n\g\à\y d/m/Y') }}</div>@endif
            </div>
        </div>

        @if($hien('khach') && ($khach !== '' || $sdt !== '' || filled($don['buyer_company'] ?? null)))
            <div class="khach">
                @if($khach !== '')<span>Khách hàng:</span><b>{{ $khach }}{{ $hang !== '' ? ' · Hạng '.$hang : '' }}</b>@endif
                @if($sdt !== '')<span>Điện thoại:</span><span>{{ $sdt }}</span>@endif
                @if(filled($don['buyer_company'] ?? null))<span>Đơn vị:</span><span>{{ $don['buyer_company'] }}</span>@endif
                @if(filled($don['buyer_tax_code'] ?? null))<span>Mã số thuế:</span><span>{{ $don['buyer_tax_code'] }}</span>@endif
                @if($diaChiKhach !== '')<span>Địa chỉ:</span><span>{{ $diaChiKhach }}</span>@endif
            </div>
        @else
            <div style="height:8px"></div>
        @endif

        <table>
            <thead>
                <tr>
                    <th style="width:7%">STT</th>
                    <th>Tên hàng</th>
                    @if($hien('dvt'))<th>ĐVT</th>@endif
                    <th>SL</th>
                    <th>Đơn giá</th>
                    @if($hien('chitietgia') && $botMon > 0)<th>Bớt</th>@endif
                    <th>Thành tiền</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $i => $it)
                    @php
                        $sl = (int) ($it['quantity'] ?? 0);
                        $gia = (float) ($it['unit_price'] ?? 0);
                        $bot = (float) ($it['discount_amount'] ?? 0);
                        $bt = trim((string) ($it['variant_name'] ?? ''));
                    @endphp
                    <tr>
                        <td class="giua">{{ $i + 1 }}</td>
                        <td>{{ $it['product_name'] ?? '' }}{{ ! empty($it['is_gift']) ? ' (Tặng)' : '' }}@if($hien('bienthe') && $bt !== '')<div class="phu">{{ $bt }}</div>@endif</td>
                        @if($hien('dvt'))<td class="giua">{{ $it['unit_name'] ?? '' }}</td>@endif
                        <td class="giua">{{ $sl }}</td>
                        <td class="so">{{ $so($gia) }}{{ $d }}</td>
                        @if($hien('chitietgia') && $botMon > 0)<td class="so">{{ $bot > 0 ? '-'.$so($bot).$d : '' }}</td>@endif
                        <td class="so">{{ $so($gia * $sl - $bot) }}{{ $d }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="cuoi">
            <div class="cuoi-trai">
                @if($hinhThuc !== '')<div>Hình thức thanh toán: <b>{{ $hinhThuc }}</b></div>@endif
                @if($hien('ghichu') && filled($don['note'] ?? null))<div>Ghi chú: {{ $don['note'] }}</div>@endif
                <div class="chu">Bằng chữ: {{ \App\Support\TienBangChu::doc($don['total_amount'] ?? 0) }}.</div>
                @if($hien('tietkiem') && $tietKiem > 0)
                    <div class="tiet-kiem">Bạn đã tiết kiệm {{ $so($tietKiem) }}đ</div>
                @endif
                @if($qr !== '')
                    <div class="khoi-qr">
                        <img class="qr" src="{{ $qr }}" alt="" onerror="this.closest('.khoi-qr').remove()">
                        <div>Quét để chuyển khoản<br><b>{{ $so($don['total_amount'] ?? 0) }}đ</b></div>
                    </div>
                @endif
            </div>
            <div class="tong">
                <div class="dong"><span>Tiền hàng</span><span>{{ $so($tienHang) }}{{ $d }}</span></div>
                @if($botMon > 0)<div class="dong"><span>Bớt theo món</span><span>-{{ $so($botMon) }}{{ $d }}</span></div>@endif
                @if($giamDon > 0)<div class="dong"><span>Giảm giá đơn</span><span>-{{ $so($giamDon) }}{{ $d }}</span></div>@endif
                @if($tienDiem > 0)<div class="dong"><span>Đổi {{ $so($don['points_used'] ?? 0) }} điểm</span><span>-{{ $so($tienDiem) }}{{ $d }}</span></div>@endif
                @if((float) ($don['surcharge_amount'] ?? 0) > 0)<div class="dong"><span>Phụ thu</span><span>{{ $so($don['surcharge_amount']) }}{{ $d }}</span></div>@endif
                @if((float) ($don['vat_amount'] ?? 0) > 0)<div class="dong"><span>Thuế VAT</span><span>{{ $so($don['vat_amount']) }}{{ $d }}</span></div>@endif
                @if((float) ($don['shipping_fee'] ?? 0) > 0)<div class="dong"><span>Phí giao hàng</span><span>{{ $so($don['shipping_fee']) }}{{ $d }}</span></div>@endif
                <div class="dong lon"><span>TỔNG CỘNG</span><span>{{ $so($don['total_amount'] ?? 0) }}{{ $d }}</span></div>
                @if($hien('tienthua') && ($don['amount_tendered'] ?? null) !== null)
                    <div class="dong"><span>Khách đưa</span><span>{{ $so($don['amount_tendered']) }}{{ $d }}</span></div>
                    <div class="dong"><span>Tiền thối</span><span>{{ $so($don['change_amount'] ?? 0) }}{{ $d }}</span></div>
                @endif
            </div>
        </div>

        @if($ky !== [])
            <div class="ky">
                @foreach($ky as [$ma, $ten])
                    <div><b>{{ $ten }}</b><i>(Ký, ghi rõ họ tên)</i></div>
                @endforeach
            </div>
        @endif

        @if($hien('chinhsach') && $chinhSach !== '')
            <div class="chan nho">{{ $chinhSach }}</div>
        @endif
        @if($hien('footer') && $loiChao !== '')
            <div class="chan"><b>{{ $loiChao }}</b></div>
        @endif
        @if($maVach !== '')
            <div class="ma-vach">{!! $maVach !!}<div>{{ $maDon }}</div></div>
        @endif
        @if($hien('sao'))
            <div class="chan">**********</div>
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
