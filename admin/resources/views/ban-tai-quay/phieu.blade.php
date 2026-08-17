{{--
    Phiếu tính tiền khổ giấy in nhiệt (58mm / 80mm).

    KHÔNG dùng layout nào cả. Máy in nhiệt nhận đúng những gì trình duyệt vẽ ra
    trong khổ giấy đó, nên mọi thứ thừa — font tải từ mạng, khung, màu nền, bóng
    đổ — đều biến thành mực loang hoặc giấy trắng. Trang này chỉ có chữ đen trên
    nền trắng, một cột, font hệ thống.

    KHỔ GIẤY LÀ ?kho=58 HOẶC 80, mặc định 80. Hai khổ này khác nhau ở số ký tự
    lọt một dòng chứ không chỉ ở bề ngang: 58mm vừa khoảng 32 ký tự, nên tên hàng
    dài phải xuống dòng thay vì bị cắt. Chọn sai khổ thì máy in ra một cột chữ
    tràn mép, và đó là lỗi chỉ lộ ra sau khi đã in.

    TỰ BẬT HỘP THOẠI IN khi mở: trang này chỉ được mở từ nút "In phiếu" ở màn
    hình quầy, ngay sau khi khách trả tiền — bắt bấm thêm Ctrl+P là thêm một
    thao tác vào đúng lúc bận nhất.
--}}
@php
    $so = fn ($n) => number_format((float) $n, 0, ',', '.').'₫';
    $items = $don['items'] ?? [];

    // Tiền hàng TRƯỚC khi bớt từng món — cộng lại từ chính các dòng, vì
    // subtotal_amount của đơn đã trừ rồi.
    $tienHang = collect($items)->sum(fn ($it) => (float) $it['unit_price'] * (int) $it['quantity']);
    $botMon = collect($items)->sum(fn ($it) => (float) ($it['discount_amount'] ?? 0));

    $luc = filled($don['placed_at'] ?? null)
        ? \Illuminate\Support\Carbon::parse($don['placed_at'])
        : \Illuminate\Support\Carbon::parse($don['created_at'] ?? now());
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Phiếu {{ $don['order_code'] ?? '' }}</title>
    <style>
        /* Bề ngang giấy trừ hai bên mép máy in không kéo tới được (~3mm mỗi bên). */
        @page {
            size: {{ $khoGiay }}mm auto;
            margin: 0;
        }

        * { box-sizing: border-box; }

        body {
            width: {{ $khoGiay === '58' ? '54' : '76' }}mm;
            margin: 0 auto;
            padding: 4mm 2mm 8mm;
            /* Font ĐƠN CÁCH: cột số tiền bên phải chỉ thẳng hàng khi mọi chữ số
               rộng bằng nhau. Font thường thì "1" hẹp hơn "8" và cả cột lệch dần
               xuống dưới. */
            font-family: "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace;
            font-size: {{ $khoGiay === '58' ? '10.5px' : '12px' }};
            line-height: 1.45;
            color: #000;
            background: #fff;
        }

        .giua { text-align: center; }
        .dam { font-weight: 700; }
        .nho { font-size: .88em; }

        .ten-tiem { font-size: 1.25em; font-weight: 700; }

        /* Đường kẻ bằng dấu gạch chứ không phải border: máy in nhiệt vẽ đường
           mảnh 1px rất nhạt, có máy bỏ qua hẳn. */
        .ke {
            margin: 3px 0;
            overflow: hidden;
            white-space: nowrap;
            letter-spacing: -.5px;
        }

        .dong {
            display: flex;
            justify-content: space-between;
            gap: 6px;
        }

        .dong span:last-child { white-space: nowrap; }

        .mon { margin-top: 4px; }

        .mon-ten { word-break: break-word; }

        /* Dòng "2 × 90.000" thụt vào để phân biệt với tên hàng ở trên nó. */
        .mon-chi { padding-left: 8px; }

        .tong {
            margin-top: 4px;
            font-size: 1.2em;
            font-weight: 700;
        }

        .thoi {
            margin-top: 2px;
            font-size: 1.1em;
            font-weight: 700;
        }

        .chan { margin-top: 8px; }

        /* Nút chỉ để bấm trên màn hình — không bao giờ ra giấy. */
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

        @media print {
            .thanh-cong-cu { display: none; }
        }
    </style>
</head>
<body>
    <div class="giua">
        <div class="ten-tiem">{{ $tenCuaHang }}</div>
        @if($diaChi !== '')
            <div class="nho">{{ $diaChi }}</div>
        @endif
        @if($dienThoai !== '')
            <div class="nho">ĐT: {{ $dienThoai }}</div>
        @endif
    </div>

    <div class="ke">--------------------------------------------------</div>

    <div class="giua dam">PHIẾU TÍNH TIỀN</div>
    <div class="dong nho">
        <span>{{ $don['order_code'] ?? '' }}</span>
        <span>{{ $luc->format('d/m/Y H:i') }}</span>
    </div>
    @if(filled($don['recipient_name'] ?? null))
        <div class="nho">Khách: {{ $don['recipient_name'] }}{{ filled($don['recipient_phone'] ?? null) ? ' · '.$don['recipient_phone'] : '' }}</div>
    @endif

    <div class="ke">--------------------------------------------------</div>

    @foreach($items as $it)
        @php
            $tenBienThe = collect([$it['size'] ?? '', $it['color'] ?? ''])->filter()->implode(' / ');
            $sl = (int) $it['quantity'];
            $gia = (float) $it['unit_price'];
            $bot = (float) ($it['discount_amount'] ?? 0);
        @endphp
        <div class="mon">
            <div class="mon-ten">{{ $it['product_name'] ?? '' }}{{ $tenBienThe !== '' ? ' ('.$tenBienThe.')' : '' }}</div>
            <div class="dong mon-chi">
                <span>{{ $sl }} × {{ $so($gia) }}</span>
                <span>{{ $so($gia * $sl) }}</span>
            </div>
            @if($bot > 0)
                {{-- Phần bớt in thành DÒNG RIÊNG dưới chính món được bớt, không gộp
                     vào một ô giảm giá chung ở cuối: khách cầm phiếu phải thấy được
                     đã bớt cho món nào, đó là thứ họ sẽ hỏi. --}}
                <div class="dong mon-chi nho">
                    <span>Bớt {{ rtrim(rtrim(number_format((float) ($it['discount_percent'] ?? 0), 2, ',', '.'), '0'), ',') }}%</span>
                    <span>-{{ $so($bot) }}</span>
                </div>
            @endif
        </div>
    @endforeach

    <div class="ke">--------------------------------------------------</div>

    <div class="dong">
        <span>Tiền hàng</span>
        <span>{{ $so($tienHang) }}</span>
    </div>
    @if($botMon > 0)
        <div class="dong">
            <span>Bớt theo món</span>
            <span>-{{ $so($botMon) }}</span>
        </div>
    @endif
    @if((float) ($don['discount_amount'] ?? 0) > 0)
        <div class="dong">
            <span>Mã {{ $don['voucher_code'] ?? 'giảm giá' }}</span>
            <span>-{{ $so($don['discount_amount']) }}</span>
        </div>
    @endif
    @if((float) ($don['shipping_fee'] ?? 0) > 0)
        <div class="dong">
            <span>Phí giao hàng</span>
            <span>{{ $so($don['shipping_fee']) }}</span>
        </div>
    @endif

    <div class="dong tong">
        <span>TỔNG CỘNG</span>
        <span>{{ $so($don['total_amount'] ?? 0) }}</span>
    </div>

    @if(($don['amount_tendered'] ?? null) !== null)
        <div class="dong">
            <span>Tiền mặt</span>
            <span>{{ $so($don['amount_tendered']) }}</span>
        </div>
        <div class="dong thoi">
            <span>THỐI LẠI</span>
            <span>{{ $so($don['change_amount'] ?? 0) }}</span>
        </div>
    @endif

    <div class="ke">--------------------------------------------------</div>

    <div class="giua chan nho">
        <div>Cảm ơn quý khách!</div>
        <div>Đổi trả trong 3 ngày, giữ phiếu này.</div>
    </div>

    <div class="thanh-cong-cu">
        <button type="button" onclick="window.print()">In lại</button>
        {{-- Đổi khổ giấy ngay tại đây: máy in của mỗi tiệm mỗi khác, và người ta
             chỉ phát hiện ra khổ sai lúc nhìn tờ giấy vừa nhả ra. --}}
        <a href="?kho={{ $khoGiay === '58' ? '80' : '58' }}">Đổi sang khổ {{ $khoGiay === '58' ? '80' : '58' }}mm</a>
        <button type="button" onclick="window.close()">Đóng</button>
    </div>

    <script>
        // Đợi khung hình vẽ xong rồi mới gọi in: gọi ngay lúc script chạy thì có
        // trình duyệt chụp bản chưa dàn xong và nhả ra tờ giấy thiếu dòng cuối.
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 120);
        });
    </script>
</body>
</html>
