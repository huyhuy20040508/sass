{{--
    Phiếu tính tiền khổ giấy in nhiệt (58mm / 80mm), mở từ nút "Xác nhận & In"
    và link "In phiếu" ở màn bán hàng.

    KHÔNG dùng layout: máy in nhiệt nhận đúng những gì trình duyệt vẽ trong khổ
    giấy, nên font tải từ mạng, khung, màu nền đều thành mực loang hoặc giấy
    trắng. Chỉ có chữ đen nền trắng, một cột, font hệ thống.

    KHỔ GIẤY là ?paper=58 hoặc 80 (mặc định 80). 58mm chỉ lọt khoảng 32 ký tự một
    dòng, nên tên hàng dài phải xuống dòng thay vì bị cắt.
--}}
@php
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $phanTram = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $items = $don['items'] ?? [];

    // Tiền hàng TRƯỚC khi bớt từng món: subtotal_amount của đơn đã trừ phần bớt rồi.
    $tienHang = collect($items)->sum(fn ($it) => (float) ($it['unit_price'] ?? 0) * (int) ($it['quantity'] ?? 0));
    $botMon = collect($items)->sum(fn ($it) => (float) ($it['discount_amount'] ?? 0));

    // Mọi khoản giảm cả đơn đều đã cộng dồn vào discount_amount; phần của mã giảm
    // giá là phần còn lại sau khi tách các nguồn API có ghi riêng.
    $giamTay = (float) ($don['order_discount_amount'] ?? 0);
    $giamKm = (float) ($don['promotion_discount'] ?? 0);
    $giamHang = (float) ($don['rank_discount'] ?? 0);
    $tienDiem = (float) ($don['points_amount'] ?? 0);
    $giamMa = max(0, (float) ($don['discount_amount'] ?? 0) - $giamTay - $giamKm - $giamHang - $tienDiem);

    $luc = \Illuminate\Support\Carbon::parse($don['placed_at'] ?? $don['created_at'] ?? now());
    $hinhThuc = ($don['payment_method'] ?? '') !== '' ? \App\Http\Controllers\OrderController::tenHinhThuc($don['payment_method']) : '';
    $khach = trim((string) ($don['recipient_name'] ?? ''));
    $sdt = trim((string) ($don['recipient_phone'] ?? ''));
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Phiếu {{ $don['order_code'] ?? '' }}</title>
    <style>
        /* Bề ngang giấy trừ phần mép máy in không kéo tới được (~2mm mỗi bên). */
        @page { size: {{ $khoGiay }}mm auto; margin: 0; }

        * { box-sizing: border-box; }

        body {
            width: {{ $khoGiay === '58' ? '54' : '76' }}mm;
            margin: 0 auto;
            padding: 4mm 2mm 8mm;
            /* Font đơn cách: cột tiền bên phải chỉ thẳng hàng khi mọi chữ số rộng bằng nhau. */
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

        /* Đường kẻ nét đứt dày 1px: máy in nhiệt vẽ đường mảnh hơn thì nhạt hoặc bỏ hẳn. */
        .ke { margin: 4px 0; border-top: 1px dashed #000; }

        .dong { display: flex; justify-content: space-between; gap: 6px; }
        .dong span:last-child { white-space: nowrap; }
        .mon { margin-top: 4px; }
        .mon-ten { word-break: break-word; }
        .mon-chi { padding-left: 8px; }
        .tong { margin-top: 4px; font-size: 1.2em; font-weight: 700; }
        .thoi { margin-top: 2px; font-size: 1.1em; font-weight: 700; }
        .chan { margin-top: 8px; }

        .thanh-cong-cu { margin: 10px 0; text-align: center; }
        .thanh-cong-cu a, .thanh-cong-cu button {
            display: inline-block;
            margin: 0 3px;
            padding: 6px 12px;
            border: 1px solid #999;
            border-radius: 4px;
            background: #fff;
            font: inherit;
            color: #000;
            text-decoration: none;
            cursor: pointer;
        }

        @media print { .thanh-cong-cu { display: none; } }
    </style>
</head>
<body>
    <div class="giua">
        <h1 class="ten-tiem">{{ $tenCuaHang }}</h1>
        @if($diaChi !== '')
            <div class="nho">{{ $diaChi }}</div>
        @endif
        @if($dienThoai !== '')
            <div class="nho">ĐT: {{ $dienThoai }}</div>
        @endif
    </div>

    <div class="ke"></div>

    <div class="giua dam">HOÁ ĐƠN BÁN HÀNG</div>
    <div class="dong nho">
        <span>{{ $don['order_code'] ?? '' }}</span>
        <span>{{ $luc->format('d/m/Y H:i') }}</span>
    </div>
    @if(filled($don['created_by_name'] ?? null))
        <div class="nho">Thu ngân: {{ $don['created_by_name'] }}</div>
    @endif
    @if($khach !== '' || $sdt !== '')
        <div class="nho">Khách: {{ $khach }}{{ $khach !== '' && $sdt !== '' ? ' · ' : '' }}{{ $sdt }}</div>
    @endif
    @if(filled($don['buyer_company'] ?? null))
        <div class="nho">Đơn vị: {{ $don['buyer_company'] }}</div>
    @endif
    @if(filled($don['buyer_tax_code'] ?? null))
        <div class="nho">MST: {{ $don['buyer_tax_code'] }}</div>
    @endif

    <div class="ke"></div>

    @foreach($items as $it)
        @php
            $tenBienThe = trim((string) ($it['variant_name'] ?? ''));
            $sl = (int) ($it['quantity'] ?? 0);
            $gia = (float) ($it['unit_price'] ?? 0);
            $bot = (float) ($it['discount_amount'] ?? 0);
        @endphp
        <div class="mon">
            <div class="mon-ten">{{ $it['product_name'] ?? '' }}{{ $tenBienThe !== '' ? ' ('.$tenBienThe.')' : '' }}{{ ! empty($it['is_gift']) ? ' (Tặng)' : '' }}</div>
            <div class="dong mon-chi">
                <span>{{ $sl }} × {{ $so($gia) }}</span>
                <span>{{ $so($gia * $sl) }}</span>
            </div>
            @if($bot > 0)
                {{-- Bớt in dưới chính món được bớt: khách cầm phiếu sẽ hỏi bớt cho món nào. --}}
                <div class="dong mon-chi nho">
                    <span>Bớt {{ $phanTram($it['discount_percent'] ?? 0) }}%</span>
                    <span>-{{ $so($bot) }}</span>
                </div>
            @endif
        </div>
    @endforeach

    <div class="ke"></div>

    <div class="dong"><span>Tiền hàng</span><span>{{ $so($tienHang) }}</span></div>
    @if($botMon > 0)
        <div class="dong"><span>Bớt theo món</span><span>-{{ $so($botMon) }}</span></div>
    @endif
    @if($giamMa > 0)
        <div class="dong"><span>Mã {{ $don['voucher_code'] ?: 'giảm giá' }}</span><span>-{{ $so($giamMa) }}</span></div>
    @endif
    @if($giamTay > 0)
        <div class="dong">
            <span>Giảm cả đơn{{ (float) ($don['order_discount_percent'] ?? 0) > 0 ? ' '.$phanTram($don['order_discount_percent']).'%' : '' }}</span>
            <span>-{{ $so($giamTay) }}</span>
        </div>
    @endif
    @if($giamKm > 0)
        <div class="dong"><span>Khuyến mãi</span><span>-{{ $so($giamKm) }}</span></div>
    @endif
    @if($giamHang > 0)
        <div class="dong"><span>Giảm theo hạng</span><span>-{{ $so($giamHang) }}</span></div>
    @endif
    @if($tienDiem > 0)
        <div class="dong"><span>Đổi {{ $so($don['points_used'] ?? 0) }} điểm</span><span>-{{ $so($tienDiem) }}</span></div>
    @endif
    @if((float) ($don['surcharge_amount'] ?? 0) > 0)
        <div class="dong">
            <span>Phụ thu{{ filled($don['surcharge_note'] ?? null) ? ': '.$don['surcharge_note'] : '' }}</span>
            <span>{{ $so($don['surcharge_amount']) }}</span>
        </div>
    @endif
    @if((float) ($don['vat_amount'] ?? 0) > 0)
        <div class="dong"><span>Thuế VAT</span><span>{{ $so($don['vat_amount']) }}</span></div>
    @endif
    @if((float) ($don['shipping_fee'] ?? 0) > 0)
        <div class="dong"><span>Phí giao hàng</span><span>{{ $so($don['shipping_fee']) }}</span></div>
    @endif

    <div class="dong tong"><span>TỔNG CỘNG</span><span>{{ $so($don['total_amount'] ?? 0) }}</span></div>

    @if($hinhThuc !== '')
        <div class="dong"><span>Thanh toán</span><span>{{ $hinhThuc }}</span></div>
    @endif
    @if(($don['amount_tendered'] ?? null) !== null)
        <div class="dong"><span>Khách đưa</span><span>{{ $so($don['amount_tendered']) }}</span></div>
        <div class="dong thoi"><span>TIỀN THỐI</span><span>{{ $so($don['change_amount'] ?? 0) }}</span></div>
    @endif
    @if((int) ($don['points_earned'] ?? 0) > 0)
        <div class="dong nho"><span>Điểm tích được</span><span>+{{ $so($don['points_earned']) }}</span></div>
    @endif

    @if(filled($don['note'] ?? null))
        <div class="ke"></div>
        <div class="nho">Ghi chú: {{ $don['note'] }}</div>
    @endif

    <div class="ke"></div>

    <div class="giua chan nho">Cảm ơn quý khách!</div>

    <div class="thanh-cong-cu">
        <button type="button" onclick="window.print()">In lại</button>
        {{-- Đổi khổ ngay tại đây: người ta chỉ phát hiện khổ sai lúc nhìn tờ giấy vừa nhả ra. --}}
        <a href="?paper={{ $khoGiay === '58' ? '80' : '58' }}">Đổi sang khổ {{ $khoGiay === '58' ? '80' : '58' }}mm</a>
        <button type="button" onclick="window.close()">Đóng</button>
    </div>

    <script>
        // Đợi dàn trang xong mới in: gọi ngay thì có trình duyệt chụp bản thiếu dòng cuối.
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 120);
        });
    </script>
</body>
</html>
