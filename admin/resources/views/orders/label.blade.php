{{--
    Tem giao hàng khổ 10×15 cm — dán lên kiện hàng. Độc lập với layout admin;
    mở tab mới, tự bật hộp thoại in.
--}}
@php
    $money = fn ($v) => number_format((float) $v, 0, ',', '.') . 'đ';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tem giao hàng{{ count($orders) > 1 ? ' ('.count($orders).' đơn)' : ' '.($orders[0]['order_code'] ?? '') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: "Segoe UI", Arial, sans-serif; color: #000; background: #e9ecef; font-size: 12px; }

        .label-toolbar {
            position: sticky; top: 0; z-index: 10; background: #212529; color: #fff;
            display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 18px;
        }
        /* Tiêu đề trang (h1) — cỡ chữ giữ nguyên như cũ, thanh này không in ra giấy. */
        .label-toolbar h1 { font-size: 14px; font-weight: 700; }
        .label-toolbar .lt-btns { display: flex; gap: 10px; }
        .label-toolbar button { border: 0; cursor: pointer; font-size: 13px; font-weight: 600; padding: 8px 18px; border-radius: 4px; display: inline-flex; align-items: center; gap: 6px; }
        .label-toolbar button svg { flex-shrink: 0; }
        .lt-print { background: #ffc107; color: #111; }
        .lt-print:hover { background: #e0a800; }
        .lt-close { background: #495057; color: #fff; }
        .lt-close:hover { background: #6c757d; }

        /* Khổ tem 100×150 mm */
        .label {
            width: 100mm; min-height: 150mm; margin: 18px auto; background: #fff; border: 1px solid #000;
            box-shadow: 0 2px 14px rgba(0,0,0,.18); display: flex; flex-direction: column;
        }
        .label + .label { margin-top: 20px; }
        .lb-row { border-bottom: 1px solid #000; padding: 6px 8px; }
        .lb-head { display: flex; justify-content: space-between; align-items: center; }
        .lb-shop { font-size: 13px; font-weight: 700; text-transform: uppercase; }
        .lb-ship { font-size: 11px; text-align: right; }
        .lb-ship b { font-size: 14px; }

        .lb-code { text-align: center; padding: 8px; }
        .lb-code .barcode { font-family: "Libre Barcode 39", monospace; letter-spacing: 2px; }
        .lb-code .barcode-fake {
            height: 42px; background-image: repeating-linear-gradient(90deg, #000 0 2px, #fff 2px 4px, #000 4px 5px, #fff 5px 9px);
            margin: 0 6px;
        }
        .lb-code .code-text { font-size: 16px; font-weight: 700; letter-spacing: 2px; margin-top: 4px; }

        .lb-party { padding: 7px 8px; }
        .lb-party .lb-label { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #333; }
        .lb-party .lb-name { font-size: 14px; font-weight: 700; }
        .lb-party .lb-line { font-size: 12px; margin-top: 2px; }
        .lb-to .lb-name { font-size: 16px; }
        .lb-to .lb-addr { font-size: 13px; margin-top: 3px; line-height: 1.35; }

        .lb-cod { display: flex; justify-content: space-between; align-items: center; padding: 8px; border-top: 2px solid #000; }
        .lb-cod .cod-lb { font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .lb-cod .cod-amt { font-size: 20px; font-weight: 800; }
        .lb-cod.is-paid .cod-amt { font-size: 15px; }

        .lb-foot { display: flex; justify-content: space-between; padding: 6px 8px; font-size: 11px; color: #333; margin-top: auto; }

        .grow { flex: 1; }

        @media print {
            body { background: #fff; }
            .label-toolbar { display: none; }
            .label { width: auto; min-height: 0; margin: 0; border: 0; box-shadow: none; }
            .label + .label { margin-top: 0; page-break-before: always; }
            @page { size: 100mm 150mm; margin: 3mm; }
        }
    </style>
</head>
<body>
    <div class="label-toolbar">
        <h1>Tem giao hàng @if(count($orders) > 1)({{ count($orders) }} đơn)@else{{ $orders[0]['order_code'] ?? '' }}@endif</h1>
        <div class="lt-btns">
            <button type="button" class="lt-print" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                In tem
            </button>
            <button type="button" class="lt-close" onclick="window.close()">Đóng</button>
        </div>
    </div>

    @foreach($orders as $o)
    @php
        $addr = \App\Http\Controllers\OrderController::shippingAddress($o);
        $totalQty = collect($o['items'] ?? [])->sum('quantity');
        $isCod = ($o['payment_method'] ?? '') === 'cod';
        $unpaid = ($o['payment_status'] ?? '') !== 'paid';
        $codAmount = ($isCod && $unpaid) ? (float) ($o['total_amount'] ?? 0) : 0.0;
    @endphp
    <div class="label">
        {{-- Shop + đơn vị vận chuyển --}}
        <div class="lb-row lb-head">
            <span class="lb-shop">{{ config('shop.name', 'Football Shop') }}</span>
            <span class="lb-ship">
                Đơn vị VC<br><b>{{ $o['shipping_method'] ?: 'Tự giao' }}</b>
            </span>
        </div>

        {{-- Mã đơn + mã vạch giả --}}
        <div class="lb-row lb-code">
            <div class="barcode-fake"></div>
            <div class="code-text">{{ $o['order_code'] ?? '—' }}</div>
        </div>

        {{-- Người gửi --}}
        <div class="lb-row lb-party lb-from">
            <div class="lb-label">Người gửi</div>
            <div class="lb-name">{{ config('shop.name', 'Football Shop') }}</div>
            <div class="lb-line">079.6666.468 — 22 Đồng Nai, P.15, Q.10, TP.HCM</div>
        </div>

        {{-- Người nhận --}}
        <div class="lb-row lb-party lb-to grow">
            <div class="lb-label">Người nhận</div>
            <div class="lb-name">{{ $o['recipient_name'] ?? '—' }} — {{ $o['recipient_phone'] ?? '' }}</div>
            <div class="lb-addr">{{ $addr !== '' ? $addr : '—' }}</div>
            <div class="lb-line" style="margin-top:6px">
                Số kiện: 1 &nbsp;·&nbsp; SL sản phẩm: {{ $totalQty }}
                @if(!empty($o['note'])) &nbsp;·&nbsp; Ghi chú: {{ \Illuminate\Support\Str::limit($o['note'], 60) }} @endif
            </div>
        </div>

        {{-- Tiền thu hộ --}}
        <div class="lb-cod {{ $codAmount > 0 ? '' : 'is-paid' }}">
            <span class="cod-lb">Thu hộ (COD)</span>
            <span class="cod-amt">
                @if($codAmount > 0)
                    {{ $money($codAmount) }}
                @else
                    Không thu hộ ({{ $PAY_STATUSES[$o['payment_status'] ?? ''] ?? 'Đã thanh toán' }})
                @endif
            </span>
        </div>

        <div class="lb-foot">
            <span>{{ $PAY_METHODS[$o['payment_method'] ?? ''] ?? '' }}</span>
            <span>In: {{ now()->format('H:i d/m/Y') }}</span>
        </div>
    </div>
    @endforeach

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
