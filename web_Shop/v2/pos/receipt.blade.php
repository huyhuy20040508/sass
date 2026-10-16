{{--
    Phiếu tính tiền khổ giấy in nhiệt (58mm / 80mm): in từ màn bán hàng, màn Đơn
    hàng của quầy, và vẽ ô xem trước của Cài đặt thiết bị (cùng khuôn này, dữ
    liệu mẫu) — xem trước giống hệt tờ in ra.

    KHÔNG dùng layout: máy in nhiệt nhận đúng những gì trình duyệt vẽ trong khổ
    giấy, nên font tải từ mạng, khung, màu nền đều thành mực loang hoặc giấy
    trắng. Chỉ có chữ đen nền trắng, một cột, font hệ thống.

    KHỔ GIẤY là ?paper=58 hoặc 80; không có thì theo mẫu "Hoá đơn" của chi nhánh
    (Cài đặt → Thông số chung → Cài đặt thiết bị). 58mm chỉ lọt khoảng 32 ký tự
    một dòng, nên tên hàng dài phải xuống dòng thay vì bị cắt.

    $hien('mã dòng') cho biết dòng đó đang bật trong mẫu; $soBan bản in nối nhau,
    mỗi bản một trang giấy. $tuIn = false khi chỉ để xem (ô xem trước).
--}}
@php
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $phanTram = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $hien ??= fn (string $ma) => true;
    $loiChao ??= 'Cảm ơn quý khách!';
    $soBan ??= 1;
    $logo ??= '';
    $qr ??= '';
    $wifi ??= '';
    $chinhSach ??= '';
    $tuIn ??= true;
    // Tắt dòng "Quà tặng" là bỏ hẳn món tặng khỏi phiếu — giá 0 nên tổng không đổi.
    $items = collect($don['items'] ?? [])->filter(fn ($it) => $hien('qua') || empty($it['is_gift']))->values()->all();

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
    // "Tiết kiệm" là phần cửa hàng bớt cho khách. Điểm đổi ra tiền là tiền của
    // chính khách nên không tính vào.
    $tietKiem = $botMon + $giamMa + $giamTay + $giamKm + $giamHang;

    $luc = \Illuminate\Support\Carbon::parse($don['placed_at'] ?? $don['created_at'] ?? now());
    $hinhThuc = ($don['payment_method'] ?? '') !== '' ? \App\Http\Controllers\OrderController::tenHinhThuc($don['payment_method']) : '';
    $khach = trim((string) ($don['recipient_name'] ?? ''));
    $sdt = trim((string) ($don['recipient_phone'] ?? ''));
    $thanhVien = (array) ($don['thanh_vien'] ?? []);
    $hang = trim((string) ($thanhVien['hang'] ?? ''));
    $maDon = (string) ($don['order_code'] ?? '');
    $maVach = $hien('mavach') && $maDon !== '' ? \App\Support\MaVach::svg($maDon) : '';
    $hep = $khoGiay === '58';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Phiếu {{ $maDon }}</title>
    <style>
        /* KHÔNG đặt `size`: khổ giấy là việc của driver máy in (khổ cuộn kiểu
           "80(72)mm x 3297mm" — in hết chữ thì dừng và cắt). Trang tự xin khổ khác
           khổ của driver thì Chrome thu nhỏ phiếu rồi đặt GIỮA tờ 297mm: giấy trắng
           cả trên lẫn dưới (14/10/2026). Phần mềm cũ cũng chỉ đặt lề 0. */
        @page { margin: 0; }

        * { box-sizing: border-box; }
        html { background: #fff; }

        body {
            /* Vùng in thật của cuộn 80mm là 72mm, của cuộn 58mm là 48mm; chừa lề 2mm
               mỗi bên cho chữ khỏi sát mép — cân như phiếu kết ca. */
            width: {{ $hep ? '48' : '72' }}mm;
            margin: 0 auto;
            padding: 0 {{ $hep ? '1' : '2' }}mm;
            /* Font không chân của hệ thống, chữ số đều bề ngang (tabular-nums) để
               cột tiền vẫn thẳng hàng như font đơn cách mà dễ đọc hơn. */
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
        .tieu-de { margin: 2px 0; font-size: 1.1em; font-weight: 700; letter-spacing: .5px; text-align: center; }

        /* Đường kẻ dày 1px: máy in nhiệt vẽ đường mảnh hơn thì nhạt hoặc bỏ hẳn. */
        .ke { margin: 5px 0; border-top: 1px dashed #000; }
        .ke-dam { margin: 5px 0; border-top: 2px solid #000; }

        .dong { display: flex; justify-content: space-between; gap: 6px; }
        .dong > span:first-child { min-width: 0; }
        .dong > span:last-child { white-space: nowrap; text-align: right; }
        .mon + .mon { margin-top: 4px; }
        .mon-ten { font-weight: 700; word-break: break-word; }
        {{-- Khổ 58 chỉ lọt ~30 ký tự: dòng số lượng × đơn giá của món giá cao phải nhỏ lại mới không gãy. --}}
        .mon-chi { padding-left: {{ $hep ? '0' : '6px' }}; {{ $hep ? 'font-size: .92em;' : '' }} }
        .mon-chi > span:first-child { white-space: nowrap; }
        .mon-phu { padding-left: 6px; font-size: .86em; }
        .tong { font-size: {{ $hep ? '1.15em' : '1.3em' }}; font-weight: 700; }
        .tong > span { white-space: nowrap; }
        .thoi { font-weight: 700; }
        .tiet-kiem { margin: 6px 0 2px; padding: 3px 4px; border: 1px dashed #000; text-align: center; font-weight: 700; }
        .chan { margin-top: 6px; }
        .ma-vach { margin-top: 6px; text-align: center; }
        .ma-vach svg { display: block; width: {{ $hep ? '44' : '56' }}mm; height: 10mm; margin: 0 auto; }
        .ma-vach div { font-size: .86em; letter-spacing: 1px; }

        /* Mỗi bản một trang, lề trên/dưới nằm trong từng bản để các bản in giống hệt nhau. */
        .ban { padding: 3mm 0 6mm; }
        .ban + .ban { break-before: page; }
        .sao { text-align: center; letter-spacing: 2px; }

        /* Máy in nhiệt chỉ có đen/trắng: ảnh màu đổi sang xám để không thành mảng loang. */
        .logo { display: block; max-width: 60%; max-height: 18mm; margin: 0 auto 3px; filter: grayscale(1); }
        .qr { display: block; width: {{ $hep ? '30' : '34' }}mm; height: auto; margin: 2px auto; filter: grayscale(1); }

        .thanh-cong-cu { margin: 10px 0; text-align: center; }
        .thanh-cong-cu a, .thanh-cong-cu button {
            display: inline-block;
            margin: 0 3px 4px;
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
    @for($ban = 0; $ban < $soBan; $ban++)
    <div class="ban">
    <div class="giua">
        {{-- Ảnh hỏng thì gỡ hẳn: để biểu tượng ảnh vỡ là in luôn cái khung đó ra giấy. --}}
        @if($logo !== '')
            <img class="logo" src="{{ $logo }}" alt="" onerror="this.remove()">
        @endif
        @if($hien('ten'))
            <h1 class="ten-tiem">{{ $tenCuaHang }}</h1>
        @endif
        @if($hien('diachi') && $diaChi !== '')
            <div class="nho">{{ $diaChi }}</div>
        @endif
        @if($hien('sdt') && $dienThoai !== '')
            <div class="nho">ĐT: {{ $dienThoai }}</div>
        @endif
    </div>

    <div class="ke"></div>

    <div class="tieu-de">HOÁ ĐƠN BÁN HÀNG</div>
    @if($hien('sohd') || $hien('tg'))
        <div class="dong nho">
            <span>{{ $hien('sohd') ? 'Số: '.$maDon : '' }}</span>
            <span>{{ $hien('tg') ? $luc->format('H:i d/m/Y') : '' }}</span>
        </div>
    @endif
    @if($hien('thungan') && filled($don['created_by_name'] ?? null))
        <div class="nho">Thu ngân: {{ $don['created_by_name'] }}</div>
    @endif
    @if($hien('khach') && ($khach !== '' || $sdt !== ''))
        <div class="nho">Khách: {{ $khach }}{{ $hang !== '' ? ' · '.$hang : '' }}{{ $sdt !== '' ? ' · '.$sdt : '' }}</div>
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
            $dvt = $hien('dvt') ? trim((string) ($it['unit_name'] ?? '')) : '';
            $sl = (int) ($it['quantity'] ?? 0);
            $gia = (float) ($it['unit_price'] ?? 0);
            $bot = (float) ($it['discount_amount'] ?? 0);
        @endphp
        <div class="mon">
            <div class="mon-ten">{{ $it['product_name'] ?? '' }}{{ $hien('bienthe') && $tenBienThe !== '' ? ' ('.$tenBienThe.')' : '' }}{{ ! empty($it['is_gift']) ? ' (Tặng)' : '' }}</div>
            <div class="dong mon-chi">
                <span>{{ $sl }}{{ $dvt !== '' ? ' '.$dvt : '' }} × {{ $so($gia) }}</span>
                <span>{{ $so($gia * $sl) }}</span>
            </div>
            @if($hien('chitietgia') && $bot > 0)
                {{-- Bớt in dưới chính món được bớt: khách cầm phiếu sẽ hỏi bớt cho món nào. --}}
                <div class="dong mon-phu">
                    <span>Bớt {{ $phanTram($it['discount_percent'] ?? 0) }}%</span>
                    <span>-{{ $so($bot) }}</span>
                </div>
            @endif
        </div>
    @endforeach

    <div class="ke"></div>

    {{-- Số món chỉ in ở khổ 80: khổ 58 thêm cụm này là dòng tổng gãy đôi. --}}
    <div class="dong"><span>Tiền hàng{{ $hep ? '' : ' ('.collect($items)->sum(fn ($it) => (int) ($it['quantity'] ?? 0)).' món)' }}</span><span>{{ $so($tienHang) }}</span></div>
    @if($botMon > 0)
        <div class="dong"><span>Bớt theo món</span><span>-{{ $so($botMon) }}</span></div>
    @endif
    @if($giamMa > 0)
        <div class="dong"><span>Mã {{ ($don['voucher_code'] ?? '') ?: 'giảm giá' }}</span><span>-{{ $so($giamMa) }}</span></div>
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
        <div class="dong"><span>Giảm hạng{{ $hang !== '' ? ' '.$hang : '' }}</span><span>-{{ $so($giamHang) }}</span></div>
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
    @if($hien('vat') && (float) ($don['vat_amount'] ?? 0) > 0)
        <div class="dong"><span>Thuế VAT</span><span>{{ $so($don['vat_amount']) }}</span></div>
    @endif
    @if((float) ($don['shipping_fee'] ?? 0) > 0)
        <div class="dong"><span>Phí giao hàng</span><span>{{ $so($don['shipping_fee']) }}</span></div>
    @endif

    <div class="ke-dam"></div>
    <div class="dong tong"><span>TỔNG CỘNG</span><span>{{ $so($don['total_amount'] ?? 0) }}</span></div>
    <div class="ke-dam"></div>

    @if($hinhThuc !== '')
        {{-- Tiền mặt in số khách đưa (dòng dưới là tiền thối); hình thức khác in đúng số đã thu. --}}
        <div class="dong"><span>{{ $hinhThuc }}</span><span>{{ $so($hien('tienthua') && ($don['amount_tendered'] ?? null) !== null ? $don['amount_tendered'] : ($don['total_amount'] ?? 0)) }}</span></div>
    @endif
    @if($hien('tienthua') && ($don['amount_tendered'] ?? null) !== null && (float) ($don['change_amount'] ?? 0) > 0)
        <div class="dong thoi"><span>Tiền thối</span><span>{{ $so($don['change_amount']) }}</span></div>
    @endif

    @if($hien('tietkiem') && $tietKiem > 0)
        <div class="tiet-kiem">Bạn đã tiết kiệm {{ $so($tietKiem) }}đ</div>
    @endif

    @if($hien('diem') && (int) ($don['points_earned'] ?? 0) > 0)
        <div class="dong nho"><span>Điểm tích thêm</span><span>+{{ $so($don['points_earned']) }}</span></div>
    @endif
    @if($hien('diemconlai') && $thanhVien !== [])
        <div class="dong nho"><span>{{ $hang !== '' ? 'Hạng '.$hang.' · ' : '' }}Điểm hiện có</span><span>{{ $so($thanhVien['diem'] ?? 0) }}</span></div>
    @endif

    @if($hien('ghichu') && filled($don['note'] ?? null))
        <div class="ke"></div>
        <div class="nho">Ghi chú: {{ $don['note'] }}</div>
    @endif

    @if($qr !== '')
        <div class="khoi-qr">
            <div class="ke"></div>
            <div class="giua">
                <img class="qr" src="{{ $qr }}" alt="" onerror="this.closest('.khoi-qr').remove()">
                <div class="nho">Quét để chuyển khoản {{ $so($don['total_amount'] ?? 0) }}đ</div>
            </div>
        </div>
    @endif

    @if(($hien('wifi') && $wifi !== '') || ($hien('chinhsach') && $chinhSach !== ''))
        <div class="ke"></div>
        @if($hien('wifi') && $wifi !== '')
            <div class="giua nho"><b>Wifi:</b> {{ $wifi }}</div>
        @endif
        @if($hien('chinhsach') && $chinhSach !== '')
            <div class="giua nho">{{ $chinhSach }}</div>
        @endif
    @endif

    @if($hien('footer') && $loiChao !== '')
        <div class="giua chan dam">{{ $loiChao }}</div>
    @endif
    @if($maVach !== '')
        <div class="ma-vach">{!! $maVach !!}<div>{{ $maDon }}</div></div>
    @endif
    @if($hien('sao'))
        <div class="sao">**********</div>
    @endif
    </div>
    @endfor

    @if($tuIn)
        <div class="thanh-cong-cu">
            <button type="button" onclick="window.print()">In lại</button>
            {{-- Đổi khổ ngay tại đây: người ta chỉ phát hiện khổ sai lúc nhìn tờ giấy vừa nhả ra. --}}
            <a href="?paper={{ $hep ? '80' : '58' }}">Đổi sang khổ {{ $hep ? '80' : '58' }}mm</a>
            <button type="button" onclick="window.close()">Đóng</button>
        </div>

        <script>
            // Đợi dàn trang xong (cả logo, QR) mới in: gọi ngay thì có trình duyệt chụp
            // bản thiếu dòng cuối.
            window.addEventListener('load', function () {
                window.setTimeout(function () { window.print(); }, 120);
            });
        </script>
    @endif
</body>
</html>
