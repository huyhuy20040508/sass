{{--
    MÀN CHỌN CỬA VÀO — hiện ngay sau khi đăng nhập, cho mọi người.

    Đứng riêng chứ không dùng layouts.app hay layouts.thu-ngan: tới đây người dùng
    CHƯA chọn khu nào, nên không có thanh trái của khu quản trị lẫn thanh quầy nào
    để mượn. Mượn một trong hai là đã trả lời hộ câu đang hỏi.

    BỐ CỤC HAI CỘT, như một tờ phiếu ca kẹp cạnh bảng chọn:
      - Cột trái (navy) là PHIẾU CA: tiệm nào, ai, mấy giờ, ca nào. Đây là chỗ
        cuối cùng còn kịp nhận ra mình gõ nhầm tiệm trước khi ghi sổ vào nhầm nơi,
        nên nó phải đứng riêng một khối, không lẫn vào dải tiêu đề mỏng như trước.
        Đường xé hoá đơn ngăn giữa phần tiệm và phần giờ — thứ máy quầy in cả ngày.
      - Cột phải là hai câu hỏi. Chi nhánh là Ô THẺ chọn được bằng ngón tay (máy ở
        quầy là màn cảm ứng) thay cho select; trụ sở gắn nhãn để người mới không
        đoán. KHÔNG có ô "Tất cả chi nhánh": vào ca là phải đứng ở một kho (chủ
        tiệm chốt). Khu vực là hai tấm ảnh chụp đúng thứ khu đó làm.

    Ảnh khu quản trị cắt từ CHÍNH ảnh nền của trang này — cố ý: nền là toàn cảnh
    tiệm, ô là một góc kệ trong đó, bấm vào giống như bước tới gần.
--}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chọn nơi làm việc — {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=3">

    {{-- Be Vietnam Pro: bộ chữ vẽ cho tiếng Việt, dấu không bị đội lên như Roboto;
         Roboto giữ làm phông dự phòng để không lệch khỏi phần còn lại của app. --}}
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --navy: #0b1b3a;
            --navy-2: #14284f;
            --xanh: #1890ff;      /* xanh chủ đạo, dùng chung toàn Shop Admin */
            --xanh-nhat: #e8f3ff;
            --muc: #0f172a;
            --chu: #475569;
            --mo: #8a94a6;
            --vien: #e6eaf1;
            --nen: #f6f8fb;
            --trang: #ffffff;
        }

        *, *::before, *::after { box-sizing: border-box; }

        /* Cùng ảnh nền với trang đăng nhập, đậy thêm một lớp tối: ở đây còn hai tấm
           ảnh trong thẻ, ba tấm cùng tranh nhau thì không tấm nào được nhìn. */
        body {
            min-height: 100vh;
            margin: 0;
            padding: 32px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: "Be Vietnam Pro", Roboto, system-ui, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            color: var(--muc);
            background:
                linear-gradient(rgba(6, 12, 26, .62), rgba(6, 12, 26, .62)),
                #2b2f36 url('{{ asset('images/login-bg.jpg') }}') center / cover no-repeat fixed;
        }

        .cua-the {
            width: 100%;
            max-width: 980px;
            display: grid;
            grid-template-columns: 300px 1fr;
            background: var(--trang);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(6, 12, 26, .18), 0 24px 60px rgba(6, 12, 26, .38);
            animation: cua-noi .32s ease-out both;
        }

        /* ===== Cột trái: phiếu ca ===== */
        .phieu {
            position: relative;
            display: flex;
            flex-direction: column;
            padding: 28px 28px 24px;
            color: #fff;
            background:
                radial-gradient(120% 80% at 0% 0%, rgba(24, 144, 255, .28), transparent 60%),
                linear-gradient(170deg, var(--navy-2) 0%, var(--navy) 100%);
        }
        .phieu__logo { display: flex; align-items: center; gap: 10px; margin-bottom: 34px; }
        .phieu__logo img { height: 28px; width: auto; }

        .phieu__nhan {
            display: block;
            margin-bottom: 6px;
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .55);
        }
        .phieu__tiem {
            margin: 0;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: -.01em;
            word-break: break-word;
        }
        .phieu__ai {
            margin: 6px 0 0;
            font-size: 13.5px;
            color: rgba(255, 255, 255, .78);
        }

        /* Đường xé hoá đơn: hai nửa vòng khoét ở mép + nét đứt giữa. */
        .phieu__xe {
            position: relative;
            margin: 26px -28px;
            height: 0;
            border-top: 2px dashed rgba(255, 255, 255, .22);
        }
        .phieu__xe::before, .phieu__xe::after {
            content: "";
            position: absolute;
            top: -9px;
            width: 16px; height: 16px;
            border-radius: 50%;
            background: var(--trang);
        }
        .phieu__xe::before { left: -8px; }
        .phieu__xe::after { right: -8px; }

        .phieu__gio {
            margin: 0;
            font-size: 40px;
            font-weight: 600;
            line-height: 1;
            letter-spacing: -.02em;
            font-variant-numeric: tabular-nums;
        }
        .phieu__gio small { font-size: 18px; font-weight: 500; color: rgba(255, 255, 255, .55); margin-left: 2px; }
        .phieu__ngay { margin: 8px 0 0; font-size: 13px; color: rgba(255, 255, 255, .78); text-transform: capitalize; }
        .phieu__ca {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 12px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(24, 144, 255, .22);
            border: 1px solid rgba(24, 144, 255, .45);
            font-size: 12px;
            font-weight: 600;
            color: #cfe6ff;
        }
        .phieu__ca i { font-size: 12px; }

        .phieu__chan { margin-top: auto; padding-top: 28px; }
        .phieu__chan form { margin: 0; }
        .phieu__chan button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0;
            border: 0;
            background: none;
            font: inherit;
            font-size: 13px;
            font-weight: 500;
            color: rgba(255, 255, 255, .72);
            cursor: pointer;
            transition: color .15s;
        }
        .phieu__chan button:hover { color: #fff; }
        .phieu__chan button:focus-visible { outline: 2px solid #fff; outline-offset: 4px; border-radius: 4px; }

        /* ===== Cột phải ===== */
        .chon { padding: 34px 36px 30px; background: var(--trang); }

        .chon__hoi {
            margin: 0 0 4px;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -.015em;
            line-height: 1.2;
        }
        .chon__dan { margin: 0 0 26px; font-size: 13.5px; color: var(--chu); }

        .chon__nhom + .chon__nhom { margin-top: 26px; }
        .chon__nhan {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 10px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--mo);
        }
        .chon__nhan small { font-size: 12px; font-weight: 400; letter-spacing: 0; text-transform: none; color: var(--mo); }

        /* Ô thẻ chi nhánh: input radio giấu đi, nhãn là cái thẻ. */
        .cn-luoi { display: flex; flex-wrap: wrap; gap: 10px; }
        .cn-o { position: relative; }
        .cn-o input {
            position: absolute;
            opacity: 0;
            width: 1px; height: 1px;
            margin: 0;
            pointer-events: none;
        }
        .cn-o label {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 46px;
            padding: 8px 14px 8px 12px;
            border: 1.5px solid var(--vien);
            border-radius: 10px;
            background: var(--trang);
            cursor: pointer;
            user-select: none;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }
        .cn-o label:hover { border-color: #b9cde6; background: var(--nen); }
        .cn-o__dau {
            width: 18px; height: 18px;
            flex-shrink: 0;
            border-radius: 50%;
            border: 1.5px solid #c3ccd9;
            display: grid;
            place-items: center;
            color: transparent;
            font-size: 10px;
            transition: all .15s;
        }
        .cn-o__ten { font-size: 14px; font-weight: 600; color: var(--muc); }
        .cn-o__phu {
            margin-left: 2px;
            padding: 2px 7px;
            border-radius: 6px;
            background: var(--nen);
            border: 1px solid var(--vien);
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: .04em;
            color: var(--chu);
            text-transform: uppercase;
        }
        .cn-o input:checked + label {
            border-color: var(--xanh);
            background: var(--xanh-nhat);
            box-shadow: inset 0 0 0 1px var(--xanh);
        }
        .cn-o input:checked + label .cn-o__dau { background: var(--xanh); border-color: var(--xanh); color: #fff; }
        .cn-o input:focus-visible + label { box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--xanh); }

        /* Hai ô khu vực: to, vuông, chạm được bằng ngón tay. */
        .khu-luoi {
            display: grid;
            grid-template-columns: repeat({{ min(count($modules), 2) }}, 1fr);
            gap: 14px;
        }
        .khu-luoi--mot { max-width: 300px; }

        .khu-o {
            position: relative;
            display: block;
            padding: 0;
            overflow: hidden;
            aspect-ratio: 16 / 10;
            border: 0;
            border-radius: 14px;
            background: #1e293b;
            color: #fff;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
            transition: box-shadow .18s, transform .18s;
            animation: cua-noi .32s ease-out both;
            animation-delay: calc(120ms + var(--i, 0) * 80ms);
        }
        .khu-o:hover { transform: translateY(-3px); box-shadow: 0 16px 32px rgba(11, 27, 58, .32); }
        .khu-o:focus-visible { outline: none; box-shadow: 0 0 0 3px #fff, 0 0 0 6px var(--xanh); }

        .khu-o__anh {
            position: absolute;
            inset: 0;
            background-position: center;
            background-size: cover;
            filter: saturate(.8) contrast(1.06) brightness(.96);
            transition: transform .5s ease-out;
        }
        .khu-o:hover .khu-o__anh { transform: scale(1.05); }
        .khu-o__phu {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top,
                        rgba(6, 12, 26, .92) 0%,
                        rgba(6, 12, 26, .64) 30%,
                        rgba(6, 12, 26, .18) 60%,
                        rgba(6, 12, 26, .04) 100%);
        }
        /* Huy hiệu góc trên: biểu tượng khu vực trên nền kính mờ, để hai ô đọc được
           cả khi ảnh bị đổi. */
        .khu-o__huy {
            position: absolute;
            top: 12px; left: 12px;
            width: 36px; height: 36px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .28);
            backdrop-filter: blur(6px);
            font-size: 17px;
        }
        .khu-o__chu { position: absolute; left: 0; right: 0; bottom: 0; padding: 14px 16px 15px; }
        .khu-o__ten {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -.01em;
        }
        .khu-o__ten .bi { margin-left: auto; font-size: 14px; opacity: .8; transition: transform .18s, opacity .18s; }
        .khu-o:hover .khu-o__ten .bi { transform: translateX(3px); opacity: 1; }
        .khu-o__mo-ta { display: block; margin-top: 2px; font-size: 12.5px; color: rgba(255, 255, 255, .78); }

        .chon__ghi {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin: 22px 0 0;
            font-size: 12.5px;
            color: var(--mo);
        }
        .chon__ghi .bi { margin-top: 2px; }

        @keyframes cua-noi {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: none; }
        }

        @media (max-width: 820px) {
            body { padding: 14px; align-items: flex-start; }
            .cua-the { grid-template-columns: 1fr; border-radius: 14px; }
            /* Phiếu ca gập lại: giữ tiệm, người và giờ; bỏ nhãn, đường xé, ngày, ca.
               Đăng xuất neo góc phải để không tốn thêm một hàng. */
            .phieu { padding: 18px 64px 16px 20px; }
            .phieu__logo { margin-bottom: 12px; }
            .phieu__logo img { height: 22px; }
            .phieu__nhan, .phieu__xe, .phieu__ngay, .phieu__ca { display: none; }
            .phieu__tiem { font-size: 17px; }
            .phieu__ai { font-size: 12.5px; }
            .phieu__gio { margin-top: 10px; font-size: 20px; }
            .phieu__gio small { font-size: 12px; }
            .phieu__chan { position: absolute; top: 18px; right: 18px; margin: 0; padding: 0; }
            .phieu__chan button span { display: none; }
            .phieu__chan button i { font-size: 18px; }
            .chon { padding: 22px 18px 20px; }
            .chon__hoi { font-size: 20px; }
            .khu-luoi { grid-template-columns: 1fr; }
            .khu-luoi--mot { max-width: none; }
            .khu-o { aspect-ratio: 16 / 9; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    @php
        // Cùng thứ tự với topbar: họ tên người tự đặt trước, rồi tới tên đăng nhập.
        $ten = trim((string) data_get($nguoiDung, 'full_name', ''));
        if ($ten === '') {
            $ten = trim((string) data_get($nguoiDung, 'username', ''));
        }
        $vaiTro = trim((string) data_get($nguoiDung, 'role.display_name', ''));
        $congTy = \App\Http\Controllers\ChiNhanhController::LOAI_CONG_TY;
    @endphp

    <div class="cua-the">
        {{-- Đăng xuất nằm ở đây, NGOÀI biểu mẫu chọn khu: lồng form trong form thì
             trình duyệt bỏ cái bên trong và nút này im lặng không làm gì. --}}
        <aside class="phieu">
            <div class="phieu__logo">
                <img src="{{ asset('images/logo-default-wide-light.svg') }}" alt="{{ config('app.name') }}">
            </div>

            <span class="phieu__nhan">Cửa hàng</span>
            <p class="phieu__tiem">{{ $cuaHang !== '' ? $cuaHang : config('app.name') }}</p>
            @if($ten !== '')
                <p class="phieu__ai">{{ $ten }}{{ $vaiTro !== '' ? ' · '.$vaiTro : '' }}</p>
            @endif

            <div class="phieu__xe" aria-hidden="true"></div>

            <span class="phieu__nhan">Bắt đầu ca</span>
            <p class="phieu__gio"><span id="gio">--:--</span><small id="giay">--</small></p>
            <p class="phieu__ngay" id="ngay"></p>
            <span class="phieu__ca" id="ca" hidden><i class="bi bi-sun"></i><span></span></span>

            <div class="phieu__chan">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Đăng xuất"><i class="bi bi-box-arrow-left"></i> <span>Đăng xuất</span></button>
                </form>
            </div>
        </aside>

        <main class="chon">
            <h1 class="chon__hoi">Hôm nay bạn làm việc ở đâu?</h1>
            <p class="chon__dan">
                @if($chiNhanh !== [])
                    Chọn chi nhánh đang đứng, rồi bấm vào khu vực để vào.
                @else
                    Bấm vào khu vực để vào.
                @endif
            </p>

            {{-- MỘT biểu mẫu cho cả chi nhánh lẫn khu vực: mỗi ô khu vực là một nút
                 gửi mang mã module của nó, nên bấm đúng một lần là xong cả hai câu. --}}
            <form method="POST" action="{{ route('chon-cua.vao') }}">
                @csrf

                @if($chiNhanh !== [])
                    {{-- Chỉ hiện khi tiệm có từ hai chi nhánh — cùng luật với ô chọn
                         trên hai thanh trên cùng. Đây là câu ĐẮT hơn câu chọn khu vực:
                         đứng nhầm kho thì hàng đi ra khỏi kho khác, và không ai biết
                         cho tới lúc kiểm kê. --}}
                    <fieldset class="chon__nhom" style="border:0; padding:0; margin:0 0 26px; min-width:0">
                        <legend class="chon__nhan" style="float:left; width:100%; padding:0">
                            Chi nhánh <small>hàng nhập, hàng bán ghi vào đây</small>
                        </legend>
                        <div class="cn-luoi">
                            @foreach($chiNhanh as $cn)
                                <div class="cn-o">
                                    {{-- Chưa chọn gì (phiên cũ ở trạng thái "xem gộp") thì đánh dấu ô
                                         đầu — trụ sở đã được đưa lên đầu danh sách. --}}
                                    <input type="radio" name="chi_nhanh" id="cn-{{ $cn['id'] }}" value="{{ $cn['id'] }}"
                                        {{ $chiNhanhDangChon === (int) $cn['id'] || (! $chiNhanhDangChon && $loop->first) ? 'checked' : '' }}>
                                    <label for="cn-{{ $cn['id'] }}">
                                        <span class="cn-o__dau"><i class="bi bi-check-lg"></i></span>
                                        <span class="cn-o__ten">{{ $cn['name'] }}</span>
                                        @if((int) ($cn['branch_type'] ?? 0) === $congTy)
                                            <span class="cn-o__phu">Trụ sở</span>
                                        @endif
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <div class="chon__nhom">
                    <span class="chon__nhan">Khu vực</span>
                    <div class="khu-luoi {{ count($modules) < 2 ? 'khu-luoi--mot' : '' }}">
                        @foreach($modules as $m)
                            <button type="submit" name="module" value="{{ $m['ma'] }}"
                                    class="khu-o" style="--i: {{ $loop->index }}">
                                @if(($m['anh'] ?? '') !== '')
                                    <span class="khu-o__anh" aria-hidden="true"
                                          style="background-image: url('{{ asset($m['anh']) }}')"></span>
                                @endif
                                <span class="khu-o__phu" aria-hidden="true"></span>
                                <span class="khu-o__huy" aria-hidden="true">
                                    <i class="bi {{ $m['ma'] === 'thu-ngan' ? 'bi-cash-coin' : 'bi-grid-1x2' }}"></i>
                                </span>
                                <span class="khu-o__chu">
                                    <span class="khu-o__ten">{{ $m['ten'] }} <i class="bi bi-arrow-right"></i></span>
                                    <span class="khu-o__mo-ta">{{ $m['mo_ta'] }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </form>

            {{-- Người một khu KHÔNG được hứa "đổi lại": nút trên thanh của họ là một
                 cái nhãn, bấm không ra gì. --}}
            <p class="chon__ghi">
                <i class="bi bi-info-circle"></i>
                @if(count($modules) > 1)
                    <span>Chọn nhầm cũng không sao — đổi lại bất cứ lúc nào ở nút góc phải thanh trên cùng.</span>
                @else
                    <span>Sai tài khoản hay sai tiệm thì đăng xuất rồi vào lại.</span>
                @endif
            </p>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @include('partials.toasts')
    <script>
        // Đồng hồ trên phiếu ca: giờ ở máy người dùng, chạy từng giây. Ca suy từ giờ —
        // chỉ là lời chào, không phải ca làm việc trong sổ.
        (function () {
            var THU = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
            var gio = document.getElementById('gio'), giay = document.getElementById('giay');
            var ngay = document.getElementById('ngay'), ca = document.getElementById('ca');
            var hai = function (n) { return (n < 10 ? '0' : '') + n; };
            function ve() {
                var d = new Date(), h = d.getHours();
                gio.textContent = hai(h) + ':' + hai(d.getMinutes());
                giay.textContent = ':' + hai(d.getSeconds());
                ngay.textContent = THU[d.getDay()] + ', ' + hai(d.getDate()) + '/' + hai(d.getMonth() + 1) + '/' + d.getFullYear();
                var ten = h < 5 ? 'Ca khuya' : h < 11 ? 'Ca sáng' : h < 17 ? 'Ca chiều' : h < 22 ? 'Ca tối' : 'Ca khuya';
                var icon = h < 5 || h >= 22 ? 'bi-moon-stars' : h < 11 ? 'bi-sunrise' : h < 17 ? 'bi-sun' : 'bi-sunset';
                ca.querySelector('span').textContent = ten;
                ca.querySelector('i').className = 'bi ' + icon;
                ca.hidden = false;
            }
            ve();
            setInterval(ve, 1000);
        })();
    </script>
</body>
</html>
