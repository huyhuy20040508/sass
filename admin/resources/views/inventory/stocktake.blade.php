{{--
    Phiếu kiểm kê kho — trang IN, đứng độc lập với layout admin.

    Không extends layouts.app: trang này chỉ để ra giấy, kéo cả sidebar/topbar vào
    chỉ tổ phải ẩn lại bằng CSS in.

    Cột "Số đếm thực tế" và "Chênh lệch" cố ý để TRỐNG — đây là chỗ người đi đếm
    viết tay giữa các kệ. In sẵn số vào đó thì phiếu chỉ còn là bản sao màn hình.
--}}
@php
    $KIT_TYPES = \App\Http\Controllers\ProductController::KIT_TYPES;
    $nf = fn ($v) => number_format((int) $v, 0, ',', '.');

    // Tên / địa chỉ / liên hệ của cửa hàng — đọc từ Cài đặt, xem ApiClient::shopInfo().
    ['name' => $shopName, 'address' => $shopAddress, 'contact' => $shopContact]
        = app(\App\Services\ApiClient::class)->shopInfo();

    // Mô tả bộ lọc đã tạo ra phiếu này. Phiếu rời khỏi màn hình rồi thì phải tự nói
    // được nó là danh sách gì, nếu không hai tuần sau không ai biết đã đếm những gì.
    $scope = [];
    if ($selected) {
        $scope[] = 'các dòng đã chọn trên bảng';
    } else {
        if ($filters['keyword'] !== '') {
            $scope[] = 'từ khoá “'.$filters['keyword'].'”';
        }
        if ($filters['stock'] !== 'all') {
            $scope[] = 'mức tồn: '.($STOCK_STATES[$filters['stock']] ?? $filters['stock']);
        }
        if ($filters['is_active'] !== '') {
            $scope[] = $filters['is_active'] === '1' ? 'chỉ hàng đang bán' : 'chỉ hàng ngừng bán';
        }
        if ($filters['category_id'] > 0) {
            $scope[] = 'lọc theo danh mục';
        }
        if ($filters['brand_id'] > 0) {
            $scope[] = 'lọc theo thương hiệu';
        }
    }
    $scopeText = $scope ? implode(' · ', $scope) : 'toàn bộ kho';
    $truncated = $total > count($rows);
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Phiếu kiểm kê kho ({{ count($rows) }} dòng) — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; background: #e9ecef; color: #111;
            font-family: 'Times New Roman', Times, serif; font-size: 12px;
        }

        /* Thanh công cụ chỉ có trên màn hình, không ra giấy */
        .st-toolbar {
            position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 16px;
            flex-wrap: wrap; padding: 10px 16px; background: #212529; color: #fff;
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: 13px;
        }
        .st-btns { margin-left: auto; display: flex; gap: 8px; }
        .st-toolbar button {
            border: 0; border-radius: 4px; padding: 7px 14px; font-size: 13px; font-weight: 600;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
        }
        .st-print { background: #ffc107; color: #111; }
        .st-close { background: #495057; color: #fff; }
        .st-blind { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none; }
        .st-blind input { margin: 0; cursor: pointer; }

        /* Khổ A4 đứng */
        .sheet {
            width: 210mm; min-height: 297mm; margin: 18px auto; padding: 12mm 10mm;
            background: #fff; box-shadow: 0 2px 14px rgba(0, 0, 0, .18);
        }

        .st-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
        .st-shop b { font-size: 13px; text-transform: uppercase; }
        .st-shop p, .st-meta p { margin: 2px 0 0; font-size: 11px; }
        .st-meta { text-align: right; }

        .st-title { margin: 14px 0 2px; text-align: center; font-size: 18px; font-weight: 700; text-transform: uppercase; }
        .st-sub { margin: 0 0 12px; text-align: center; font-size: 11px; }

        .st-scope { margin: 0 0 10px; padding: 6px 8px; border: 1px solid #999; font-size: 11px; }
        .st-scope b { font-weight: 700; }
        .st-warn { margin: 0 0 10px; padding: 6px 8px; border: 1px solid #111; font-size: 11px; font-weight: 700; }

        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 5px; vertical-align: middle; }
        th {
            background: #f1f3f5; font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .02em; text-align: center;
        }
        /* Dòng cao để còn chỗ viết tay — đây là mục đích của cả tờ giấy này. */
        tbody td { height: 9mm; }
        .c { text-align: center; }
        .r { text-align: right; }
        .st-sku { font-family: 'Courier New', monospace; font-size: 11px; white-space: nowrap; }
        .st-name { font-weight: 600; }
        .st-variant { font-size: 11px; }
        /* Ô điền tay: nền hơi xám trên màn hình cho dễ nhận ra, in ra thì trắng trơn. */
        .st-fill { background: #fbfbfb; }

        .st-foot { margin-top: 14px; display: flex; justify-content: space-between; gap: 12px; font-size: 11px; }
        .st-sign { width: 32%; text-align: center; }
        .st-sign b { display: block; font-size: 12px; }
        .st-sign span { display: block; font-style: italic; font-size: 10px; }
        .st-sign i { display: block; height: 52px; }

        /* Ẩn cột tồn sổ sách khi đếm mù */
        .is-blind .col-sys { display: none; }
        /* Giá vốn mặc định ẨN: người đi đếm hàng không cần biết, và tờ giấy có giá vốn
           rơi ra ngoài kho là lộ biên lợi nhuận. Bật khi cần phiếu đánh giá trị kho. */
        .col-cost { display: none; }
        .show-cost .col-cost { display: table-cell; }

        @media print {
            body { background: #fff; }
            .st-toolbar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            .st-fill { background: #fff; }
            /* Tiêu đề bảng lặp lại ở mọi trang: phiếu vài chục trang mà chỉ trang đầu
               có tên cột thì từ trang hai trở đi không biết đang điền vào ô nào. */
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .st-foot { page-break-inside: avoid; }
            @page { size: A4 portrait; margin: 10mm; }
        }
    </style>
</head>
<body>
    <div class="st-toolbar">
        <b>Phiếu kiểm kê kho — {{ count($rows) }} biến thể</b>
        <label class="st-blind">
            <input type="checkbox" id="stBlind">
            Ẩn cột tồn sổ sách (đếm mù)
        </label>
        <label class="st-blind">
            <input type="checkbox" id="stCost">
            Hiện giá vốn
        </label>
        <div class="st-btns">
            <button type="button" class="st-print" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                In phiếu
            </button>
            <button type="button" class="st-close" onclick="window.close()">Đóng</button>
        </div>
    </div>

    <div class="sheet" id="stSheet">
        <div class="st-head">
            {{-- Xem ghi chú ở orders/print.blade.php: thông tin cửa hàng đọc từ
                 Cài đặt, không ghi cứng. --}}
            <div class="st-shop">
                <b>{{ $shopName }}</b>
                @if ($shopAddress !== '')<p>{{ $shopAddress }}</p>@endif
                @if ($shopContact !== '')<p>{{ $shopContact }}</p>@endif
            </div>
            <div class="st-meta">
                <p>Ngày in: {{ now()->format('H:i d/m/Y') }}</p>
                <p>Số dòng: {{ $nf(count($rows)) }}</p>
            </div>
        </div>

        <h1 class="st-title">Phiếu kiểm kê kho</h1>
        <p class="st-sub">Đếm thực tế trên kệ rồi ghi vào cột “Số đếm thực tế”. Chênh lệch = số đếm − tồn sổ sách.</p>

        <p class="st-scope">
            <b>Phạm vi:</b> {{ $scopeText }} · <b>Sắp xếp:</b> {{ $SORTS[$filters['sort']] ?? $filters['sort'] }}
            · <b>Ngưỡng sắp hết:</b> {{ $low }}
        </p>

        @if($truncated)
            <p class="st-warn">
                Danh sách có {{ $nf($total) }} dòng, phiếu này chỉ in {{ $nf(count($rows)) }} dòng đầu.
                Hãy lọc hẹp lại rồi in tiếp phần còn lại.
            </p>
        @endif

        <table>
            <thead>
                <tr>
                    <th style="width:9mm">STT</th>
                    <th style="width:34mm">SKU</th>
                    <th>Sản phẩm</th>
                    <th style="width:30mm">Phân loại</th>
                    <th class="col-sys" style="width:18mm">Tồn sổ sách</th>
                    <th class="col-cost" style="width:20mm">Giá vốn</th>
                    <th style="width:24mm">Số đếm thực tế</th>
                    <th style="width:20mm">Chênh lệch</th>
                    <th style="width:30mm">Ghi chú</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $r)
                    @php
                        $variant = array_filter([
                            $KIT_TYPES[$r['kit_type'] ?? ''] ?? '',
                            $r['size'] ?? '',
                            $r['color'] ?? '',
                        ]);
                    @endphp
                    <tr>
                        <td class="c">{{ $i + 1 }}</td>
                        <td class="st-sku">{{ $r['sku'] ?? '—' }}</td>
                        <td>
                            <span class="st-name">{{ $r['product_name'] ?? '—' }}</span>
                        </td>
                        <td class="st-variant">{{ $variant ? implode(' / ', $variant) : '—' }}</td>
                        <td class="col-sys r">{{ $nf($r['stock_quantity'] ?? 0) }}</td>
                        <td class="col-cost r">
                            {{ ($r['cost_price'] ?? null) === null ? '—' : $nf($r['cost_price']).'₫' }}
                        </td>
                        <td class="st-fill"></td>
                        <td class="st-fill"></td>
                        <td class="st-fill"></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="st-foot">
            <div class="st-sign">
                <b>Người đếm</b>
                <span>(Ký, ghi rõ họ tên)</span>
                <i></i>
            </div>
            <div class="st-sign">
                <b>Thủ kho</b>
                <span>(Ký, ghi rõ họ tên)</span>
                <i></i>
            </div>
            <div class="st-sign">
                <b>Người duyệt</b>
                <span>(Ký, ghi rõ họ tên)</span>
                <i></i>
            </div>
        </div>
    </div>

    <script>
        // Đếm mù: giấu số tồn trên sổ để người đếm không bị con số có sẵn dẫn dắt.
        // Nhớ lựa chọn cho lần in sau — một đợt kiểm kê thường in nhiều phiếu liền.
        (function () {
            var sheet = document.getElementById('stSheet');

            // Cả hai lựa chọn đều nhớ cho lần in sau — một đợt kiểm kê in nhiều phiếu liền.
            [
                { id: 'stBlind', cls: 'is-blind', key: 'invStocktakeBlind' },
                { id: 'stCost', cls: 'show-cost', key: 'invStocktakeCost' },
            ].forEach(function (opt) {
                var box = document.getElementById(opt.id);
                if (!box) return;
                try { box.checked = localStorage.getItem(opt.key) === '1'; } catch (e) { /* trình duyệt chặn storage */ }
                sheet.classList.toggle(opt.cls, box.checked);

                box.addEventListener('change', function () {
                    sheet.classList.toggle(opt.cls, box.checked);
                    try { localStorage.setItem(opt.key, box.checked ? '1' : '0'); } catch (e) { /* bỏ qua */ }
                });
            });
        })();

        // Tự bật hộp thoại in khi trang tải xong (chờ font/style ổn định)
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>
</body>
</html>
