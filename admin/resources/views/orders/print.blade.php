{{--
    Trang in hóa đơn bán hàng — đứng độc lập (không dùng layout admin).
    Mở tab mới từ danh sách/chi tiết đơn; tự bật hộp thoại in, in xong đóng lại được.
--}}
@php
    use Illuminate\Support\Carbon;

    $money = fn ($v) => number_format((float) $v, 0, ',', '.') . '₫';

    // Đọc số tiền thành chữ tiếng Việt (đủ dùng cho hóa đơn: tỷ/triệu/nghìn)
    $docSo = function (int $n): string {
        if ($n <= 0) return 'Không đồng';
        $chuSo = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
        $docBaSo = function (int $so, bool $dayDu) use ($chuSo): string {
            $tram = intdiv($so, 100); $chuc = intdiv($so % 100, 10); $dv = $so % 10;
            $s = '';
            if ($tram > 0 || $dayDu) { $s .= $chuSo[$tram] . ' trăm'; }
            if ($chuc > 1) {
                $s .= ' ' . $chuSo[$chuc] . ' mươi';
                if ($dv === 1) $s .= ' mốt';
            } elseif ($chuc === 1) {
                $s .= ' mười';
                if ($dv === 1) $s .= ' một';
            } elseif ($dv > 0 && ($tram > 0 || $dayDu)) {
                $s .= ' lẻ';
            }
            if ($dv === 5 && $chuc > 0) $s .= ' lăm';
            elseif ($dv > 0 && !($dv === 1 && $chuc >= 1)) $s .= ' ' . $chuSo[$dv];
            return trim($s);
        };
        $donVi = ['', ' nghìn', ' triệu', ' tỷ'];
        $parts = [];
        $i = 0;
        while ($n > 0 && $i < 4) {
            $baSo = $n % 1000;
            $n = intdiv($n, 1000);
            if ($baSo > 0) { $parts[] = $docBaSo($baSo, $n > 0) . $donVi[$i]; }
            $i++;
        }
        return ucfirst(trim(implode(' ', array_reverse($parts)))) . ' đồng';
    };
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>In đơn{{ count($orders) > 1 ? ' ('.count($orders).' đơn)' : ' '.($orders[0]['order_code'] ?? '') }} — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 13px; color: #111; background: #e9ecef; }

        .print-toolbar {
            position: sticky; top: 0; z-index: 10; background: #212529; color: #fff;
            display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 18px;
        }
        .print-toolbar b { font-size: 14px; }
        .print-toolbar .pt-btns { display: flex; gap: 10px; }
        .print-toolbar button {
            border: 0; cursor: pointer; font-size: 13px; font-weight: 600; padding: 8px 18px; border-radius: 4px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .print-toolbar button svg { flex-shrink: 0; }
        .pt-print { background: #ffc107; color: #111; }
        .pt-print:hover { background: #e0a800; }
        .pt-close { background: #495057; color: #fff; }
        .pt-close:hover { background: #6c757d; }

        /* Khổ hóa đơn A5 đứng */
        .sheet {
            width: 148mm; min-height: 200mm; margin: 18px auto; background: #fff; padding: 10mm 9mm;
            box-shadow: 0 2px 14px rgba(0,0,0,.18);
        }
        .sheet + .sheet { margin-top: 24px; }

        .inv-head { display: flex; justify-content: space-between; gap: 10px; border-bottom: 2px solid #111; padding-bottom: 8px; }
        .inv-shop b { font-size: 16px; text-transform: uppercase; letter-spacing: .3px; }
        .inv-shop p { color: #333; font-size: 11.5px; margin-top: 2px; }
        .inv-meta { text-align: right; font-size: 11.5px; color: #333; white-space: nowrap; }

        .inv-title { text-align: center; margin: 12px 0 2px; }
        .inv-title h1 { font-size: 19px; text-transform: uppercase; letter-spacing: 1px; }
        .inv-title .inv-code { font-size: 13px; margin-top: 3px; }
        .inv-title .inv-code b { font-size: 14.5px; }
        .inv-status { text-align: center; font-size: 11.5px; color: #444; margin-bottom: 12px; }

        .inv-sec { margin-bottom: 10px; }
        .inv-sec-title { font-size: 11px; text-transform: uppercase; letter-spacing: .6px; color: #666; border-bottom: 1px solid #ddd; padding-bottom: 3px; margin-bottom: 6px; font-weight: 700; }
        .inv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 3px 16px; font-size: 12.5px; }
        .inv-grid .full { grid-column: 1 / -1; }
        .inv-grid span { color: #555; }

        table.inv-items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .inv-items th { background: #f1f3f5; border: 1px solid #ccc; padding: 6px 6px; font-size: 11.5px; text-transform: uppercase; }
        .inv-items td { border: 1px solid #ccc; padding: 6px 6px; vertical-align: top; font-size: 12.5px; }
        .inv-items .c { text-align: center; }
        .inv-items .r { text-align: right; white-space: nowrap; }
        .inv-items .it-name { font-weight: 600; }
        .inv-items .it-sub { color: #555; font-size: 11.5px; margin-top: 2px; }

        .inv-sum { margin-top: 8px; margin-left: auto; width: 62%; font-size: 12.5px; }
        .inv-sum div { display: flex; justify-content: space-between; padding: 3px 0; }
        .inv-sum .is-total { border-top: 1.5px solid #111; margin-top: 4px; padding-top: 6px; font-size: 15px; font-weight: 700; }
        .inv-words { font-size: 12px; font-style: italic; color: #333; margin-top: 6px; }

        .inv-note { font-size: 12px; color: #333; background: #f8f9fa; border: 1px dashed #bbb; padding: 6px 8px; margin-top: 8px; }

        .inv-sign { display: flex; justify-content: space-between; text-align: center; margin-top: 22px; font-size: 12.5px; }
        .inv-sign .col { width: 45%; }
        .inv-sign i { display: block; color: #666; font-size: 11px; font-style: normal; margin-top: 2px; }
        .inv-sign .space { height: 52px; }

        .inv-thanks { text-align: center; margin-top: 14px; font-size: 12px; color: #444; border-top: 1px solid #ddd; padding-top: 8px; }

        @media print {
            body { background: #fff; }
            .print-toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .sheet + .sheet { margin-top: 0; page-break-before: always; }
            @page { size: A5 portrait; margin: 9mm; }
        }
    </style>
</head>
<body>
    <div class="print-toolbar">
        <b>In hóa đơn @if(count($orders) > 1)({{ count($orders) }} đơn)@else{{ $orders[0]['order_code'] ?? '' }}@endif</b>
        <div class="pt-btns">
            <button type="button" class="pt-print" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                In hóa đơn
            </button>
            <button type="button" class="pt-close" onclick="window.close()">Đóng</button>
        </div>
    </div>

    @foreach($orders as $o)
    @php
        $addr = \App\Http\Controllers\OrderController::shippingAddress($o);
        $items = $o['items'] ?? [];
        $placedAt = !empty($o['created_at']) ? Carbon::parse($o['created_at'])->format('H:i d/m/Y') : '—';
    @endphp
    <div class="sheet">
        {{-- Đầu trang: thông tin cửa hàng + thời điểm --}}
        <div class="inv-head">
            <div class="inv-shop">
                <b>{{ config('shop.name', 'Football Shop') }}</b>
                <p>22 Đồng Nai, Phường 15, Quận 10, TP.HCM</p>
                <p>Hotline: 079.6666.468 — www.footballshop.vn</p>
            </div>
            <div class="inv-meta">
                <p>Ngày đặt: {{ $placedAt }}</p>
                <p>Ngày in: {{ now()->format('H:i d/m/Y') }}</p>
            </div>
        </div>

        <div class="inv-title">
            <h1>Hóa đơn bán hàng</h1>
            <p class="inv-code">Mã đơn: <b>{{ $o['order_code'] ?? '—' }}</b></p>
        </div>
        <p class="inv-status">
            Trạng thái: {{ $STATUSES[$o['status'] ?? ''] ?? '—' }}
            &nbsp;•&nbsp; Thanh toán: {{ $PAY_STATUSES[$o['payment_status'] ?? ''] ?? '—' }}
            ({{ $PAY_METHODS[$o['payment_method'] ?? ''] ?? '—' }})
        </p>

        {{-- Khách hàng --}}
        <div class="inv-sec">
            <p class="inv-sec-title">Thông tin khách hàng</p>
            <div class="inv-grid">
                <p><span>Người nhận:</span> <b>{{ $o['recipient_name'] ?? '—' }}</b></p>
                <p><span>Điện thoại:</span> {{ $o['recipient_phone'] ?? '—' }}</p>
                @if(!empty($o['recipient_email']))
                    <p class="full"><span>Email:</span> {{ $o['recipient_email'] }}</p>
                @endif
                <p class="full"><span>Địa chỉ giao:</span> {{ $addr !== '' ? $addr : '—' }}</p>
                @if(!empty($o['shipping_method']) || !empty($o['tracking_number']))
                    <p><span>Vận chuyển:</span> {{ ($o['shipping_method'] ?? '') ?: '—' }}</p>
                    <p><span>Mã vận đơn:</span> {{ ($o['tracking_number'] ?? '') ?: '—' }}</p>
                @endif
            </div>
        </div>

        {{-- Bảng sản phẩm --}}
        <div class="inv-sec">
            <p class="inv-sec-title">Chi tiết đơn hàng</p>
            <table class="inv-items">
                <thead>
                    <tr>
                        <th style="width:24px">#</th>
                        <th>Sản phẩm</th>
                        <th style="width:34px">SL</th>
                        <th style="width:78px">Đơn giá</th>
                        <th style="width:86px">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $i => $it)
                        @php
                            $qty = (int) ($it['quantity'] ?? 0);
                            $price = (float) ($it['unit_price'] ?? 0);
                            $line = (float) ($it['total_price'] ?? $price * $qty);
                            $props = array_filter([
                                ($it['variant_sku'] ?? '') !== '' ? 'SKU: ' . $it['variant_sku'] : '',
                                ($it['color'] ?? '') !== '' ? 'Màu: ' . $it['color'] : '',
                                ($it['size'] ?? '') !== '' ? 'Size: ' . $it['size'] : '',
                                ($it['custom_player_name'] ?? '') !== '' || ($it['custom_player_number'] ?? '') !== ''
                                    ? 'In áo: ' . trim(($it['custom_player_name'] ?? '') . ' ' . (($it['custom_player_number'] ?? '') !== '' ? '#' . $it['custom_player_number'] : ''))
                                    : '',
                            ]);
                        @endphp
                        <tr>
                            <td class="c">{{ $i + 1 }}</td>
                            <td>
                                <p class="it-name">{{ $it['product_name'] ?? '—' }}</p>
                                @if(count($props))<p class="it-sub">{{ implode(' • ', $props) }}</p>@endif
                            </td>
                            <td class="c">{{ $qty }}</td>
                            <td class="r">{{ $money($price) }}</td>
                            <td class="r">{{ $money($line) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="c">Đơn hàng không có sản phẩm.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="inv-sum">
                <div><span>Tiền hàng</span><span>{{ $money($o['subtotal_amount'] ?? 0) }}</span></div>
                @if((float) ($o['discount_amount'] ?? 0) > 0)
                    <div><span>Giảm giá</span><span>-{{ $money($o['discount_amount']) }}</span></div>
                @endif
                <div><span>Phí vận chuyển</span><span>{{ $money($o['shipping_fee'] ?? 0) }}</span></div>
                <div class="is-total"><span>Tổng thanh toán</span><span>{{ $money($o['total_amount'] ?? 0) }}</span></div>
            </div>
            <p class="inv-words">Bằng chữ: {{ $docSo((int) round((float) ($o['total_amount'] ?? 0))) }}.</p>
        </div>

        @if(!empty($o['note']))
            <p class="inv-note"><b>Ghi chú của khách:</b> {{ $o['note'] }}</p>
        @endif

        {{-- Chữ ký --}}
        <div class="inv-sign">
            <div class="col">
                <b>Người bán hàng</b>
                <i>(Ký, ghi rõ họ tên)</i>
                <div class="space"></div>
            </div>
            <div class="col">
                <b>Người mua hàng</b>
                <i>(Ký, ghi rõ họ tên)</i>
                <div class="space"></div>
            </div>
        </div>

        <p class="inv-thanks">
            Cảm ơn quý khách đã mua hàng tại {{ config('shop.name', 'Football Shop') }}!<br>
            Quý khách vui lòng kiểm tra hàng khi nhận. Hỗ trợ đổi size trong 3 ngày — hotline 079.6666.468.
        </p>
    </div>
    @endforeach

    <script>
        // Tự bật hộp thoại in khi trang tải xong (chờ font/style ổn định)
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
