{{--
    MÀN CHỌN CỬA VÀO — hiện ngay sau khi đăng nhập, cho mọi người.

    Đứng riêng chứ không dùng layouts.app hay layouts.thu-ngan: tới đây người dùng
    CHƯA chọn khu nào, nên không có thanh trái của khu quản trị lẫn thanh quầy nào
    để mượn. Mượn một trong hai là đã trả lời hộ câu đang hỏi.

    HAI Ô LÀ HAI TẤM ẢNH CHỤP, mỗi tấm là đúng thứ khu đó làm: người đứng quầy
    tính tiền, và hàng xếp trên kệ. Đọc thẳng ra hai dòng mô tả bên dưới chúng.

    Ảnh khu quản trị cắt từ CHÍNH ảnh nền của trang này — cố ý, không phải nhặt
    nhầm: nền là toàn cảnh tiệm, ô là một góc kệ trong đó, nên bấm vào giống như
    bước tới gần. Bản trước ghép một ảnh chụp với một hình vẽ tổng đài mua sẵn, và
    đó là thứ duy nhất trên màn hình trông như làm vội.
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

    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --muc: #0f172a;
            --chu: #475569;
            --mo: #94a3b8;
            --vien: #e5e9f0;
            --nen: #f8fafc;
            --xanh: #1890ff;
        }

        /* Ảnh nền giống trang đăng nhập nhưng ĐẬY thêm một lớp tối. Ở trang đăng
           nhập nó là hình duy nhất nên để nguyên; ở đây có thêm hai tấm ảnh trong
           thẻ, mà ba tấm ảnh cùng tranh nhau thì không tấm nào được nhìn. */
        body {
            min-height: 100vh;
            margin: 0;
            padding: 32px;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Roboto, sans-serif;
            font-size: 14.4px;
            color: var(--muc);
            background:
                linear-gradient(rgba(8, 15, 30, .52), rgba(8, 15, 30, .52)),
                #2b2f36 url('{{ asset('images/login-bg.jpg') }}') center / cover no-repeat fixed;
        }

        /* Cùng thẻ trắng bo góc, cùng bóng toả bốn phía như trang đăng nhập — hai
           màn hình này đi liền nhau trong vài giây, đổi kiểu giữa chừng thì trông
           như vừa nhảy sang một phần mềm khác. Chỉ rộng hơn, cho hai ô ảnh đủ chỗ. */
        .cua-the {
            box-sizing: border-box;
            width: 100%;
            max-width: 720px;
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 0 24px rgba(16, 24, 40, .10),
                        0 8px 32px rgba(16, 24, 40, .18);
            animation: cua-noi .3s ease-out both;
        }

        /* ===== Thanh nhận diện ===== */
        /* Tiệm nào · ai đang đăng nhập, gom lên đầu thành một dải. Người trông nhiều
           tiệm gõ nhầm mã cửa hàng là chuyện có thật, và đây là màn hình cuối cùng
           còn kịp nhận ra trước khi bắt đầu ghi sổ vào nhầm nơi. */
        .cua-danh {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 22px;
            background: var(--nen);
            border-bottom: 1px solid var(--vien);
        }
        .cua-danh__tiem { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .cua-danh__tiem img { height: 24px; width: auto; flex-shrink: 0; }
        .cua-danh__ten {
            padding-left: 12px;
            border-left: 1px solid #d7dde7;
            font-size: 13px;
            font-weight: 500;
            color: var(--chu);
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cua-danh__nguoi {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            font-size: 12.5px;
            color: var(--mo);
            white-space: nowrap;
        }
        .cua-danh__nguoi form { margin: 0; }
        .cua-danh__nguoi button {
            border: 0; background: none; padding: 0;
            font: inherit; color: var(--chu); cursor: pointer;
        }
        .cua-danh__nguoi button:hover { color: #dc3545; }

        /* ===== Thân ===== */
        .cua-than { padding: 26px 30px 28px; }

        .cua-hoi {
            margin: 0 0 22px;
            font-size: 20px;
            font-weight: 500;
            letter-spacing: -.01em;
        }

        .cua-nhom + .cua-nhom { margin-top: 22px; }

        /* Nhãn nhỏ in hoa: hai nhãn này là hai NỬA của cùng một câu trả lời (đứng
           chi nhánh nào, vào khu nào), nên chúng phải trông giống nhau và cùng nhẹ
           hơn câu hỏi ở trên. */
        .cua-nhan {
            display: block;
            margin-bottom: 8px;
            font-size: 11px;
            font-weight: 500;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--mo);
        }

        .cua-than .form-select { max-width: 340px; }
        .cua-than .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
        }

        /* Hai ô module. To và vuông: đây là thao tác duy nhất của cả trang, mà máy
           ở quầy thường là màn hình cảm ứng — một dòng chữ có link không đủ chỗ cho
           đầu ngón tay. */
        .cua-luoi {
            display: grid;
            grid-template-columns: repeat({{ min(count($modules), 2) }}, 1fr);
            gap: 16px;
        }
        /* Một khu duy nhất: giữ ô ở đúng bề ngang như khi có hai. Thả cho nó rộng
           hết thẻ thì ảnh bị kéo hơn gấp đôi và nhoè hẳn. */
        .cua-luoi--mot { max-width: 330px; }

        .cua-o {
            position: relative;
            display: block;
            padding: 0;
            overflow: hidden;
            aspect-ratio: 4 / 3;
            border: 0;
            border-radius: 10px;
            background: #1e293b;
            color: #fff;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
            transition: box-shadow .18s, transform .18s;
            animation: cua-noi .3s ease-out both;
            animation-delay: calc(90ms + var(--i, 0) * 70ms);
        }
        .cua-o:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(15, 23, 42, .28);
        }
        .cua-o:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px #fff, 0 0 0 6px var(--xanh);
        }

        /* Ảnh nằm ở lớp nền, không phải thẻ <img>: ô này là một cái NÚT, mà nút thì
           không có chỗ cho một tấm ảnh cần mô tả riêng — tên khu vực ngay trên ảnh
           đã là lời mô tả rồi.

           Cùng một lớp lọc cho cả hai tấm. Ảnh quầy ngả cam vì đèn biển hiệu, ảnh
           kệ hàng ngả lạnh vì đèn tủ; bớt bão hoà và thêm chút tương phản là đủ kéo
           hai tông về gần nhau. */
        .cua-o__anh {
            position: absolute;
            inset: 0;
            background-position: center;
            background-size: cover;
            filter: saturate(.82) contrast(1.06) brightness(.98);
            transition: transform .4s ease-out;
        }
        .cua-o:hover .cua-o__anh { transform: scale(1.05); }

        /* Lớp phủ tối dồn về đáy. Chữ trắng đặt thẳng lên ảnh thì đọc được hay không
           là tuỳ tấm ảnh — trừ khi có lớp này, lúc đó tấm nào cũng đọc được. */
        .cua-o__phu {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top,
                        rgba(3, 8, 20, .90) 0%,
                        rgba(3, 8, 20, .66) 24%,
                        rgba(3, 8, 20, .22) 54%,
                        rgba(3, 8, 20, .02) 100%);
            transition: opacity .18s;
        }
        .cua-o:hover .cua-o__phu { opacity: .88; }

        .cua-o__chu {
            position: absolute;
            left: 0; right: 0; bottom: 0;
            padding: 14px 16px 15px;
        }
        .cua-o__ten {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: -.005em;
        }
        .cua-o__ten svg {
            width: 16px; height: 16px;
            margin-left: auto;
            opacity: .75;
            transition: transform .18s, opacity .18s;
        }
        .cua-o:hover .cua-o__ten svg { transform: translateX(3px); opacity: 1; }
        .cua-o__mo-ta {
            display: block;
            margin-top: 3px;
            font-size: 12.5px;
            line-height: 1.45;
            color: rgba(255, 255, 255, .76);
        }

        /* Module chưa có ảnh riêng: nền xám đậm + nét biểu tượng của nó, đừng để ô
           trống hoác. */
        .cua-o__thay {
            position: absolute;
            top: 16px; left: 16px;
            width: 34px; height: 34px;
            opacity: .8;
        }

        .cua-ghi {
            margin: 20px 0 0;
            font-size: 12px;
            color: var(--mo);
        }

        @keyframes cua-noi {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: none; }
        }

        @media (max-width: 620px) {
            body { padding: 14px; }
            .cua-danh { padding: 10px 16px; }
            .cua-danh__ten { font-size: 12px; }
            /* Bỏ tên người, giữ tên TIỆM và nút đăng xuất. Dải hẹp mà giữ cả ba thì
               tên tiệm — mẩu duy nhất co được — bị bóp còn vài chữ cái, đúng cái mẩu
               đáng đọc nhất: gõ nhầm tiệm mới là cái sai đắt. */
            .cua-danh__ai { display: none; }
            .cua-than { padding: 20px 18px 22px; }
            .cua-hoi { font-size: 18px; }
            /* Xếp dọc, và ô dẹt bớt: hai ô 4:3 chồng lên nhau trên màn điện thoại là
               phải cuộn mới thấy hết lựa chọn. Dẹt tới 21:9 thì tiết kiệm thêm được
               ít chỗ nhưng cắt mất đầu người trong ảnh quầy — 16:9 là chỗ dừng. */
            .cua-luoi { grid-template-columns: 1fr; }
            .cua-luoi--mot { max-width: none; }
            .cua-o { aspect-ratio: 16 / 9; }
            .cua-than .form-select { max-width: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="cua-the">
        {{-- Đăng xuất nằm ở đây, NGOÀI biểu mẫu chọn khu phía dưới: lồng form trong
             form thì trình duyệt bỏ cái bên trong và nút này im lặng không làm gì. --}}
        <header class="cua-danh">
            <div class="cua-danh__tiem">
                <img src="{{ asset('images/logo-default.svg') }}" alt="{{ config('app.name') }}">
                @if($cuaHang !== '')
                    <span class="cua-danh__ten">{{ $cuaHang }}</span>
                @endif
            </div>
            @php
                // Cùng thứ tự với topbar: họ tên người tự đặt trước, rồi tới tên
                // đăng nhập — thứ họ vừa gõ để vào đây.
                $ten = trim((string) data_get($nguoiDung, 'full_name', ''));
                if ($ten === '') {
                    $ten = trim((string) data_get($nguoiDung, 'username', ''));
                }
            @endphp
            <div class="cua-danh__nguoi">
                @if($ten !== '')
                    <span class="cua-danh__ai">{{ $ten }}</span>
                    <span class="cua-danh__ai" aria-hidden="true">·</span>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Đăng xuất</button>
                </form>
            </div>
        </header>

        <div class="cua-than">
            <h1 class="cua-hoi">Hôm nay bạn làm việc ở đâu?</h1>

            {{-- MỘT biểu mẫu cho cả chi nhánh lẫn khu vực: mỗi ô là một nút gửi mang
                 theo mã module của nó, nên người dùng bấm đúng một lần và ô chọn chi
                 nhánh phía trên đi kèm luôn. --}}
            <form method="POST" action="{{ route('chon-cua.vao') }}">
                @csrf

                @if($chiNhanh !== [])
                    {{-- Chỉ hiện khi tiệm có từ hai chi nhánh — cùng luật với ô chọn
                         trên hai thanh trên cùng. Đây là câu ĐẮT hơn câu chọn khu vực:
                         đứng nhầm kho thì hàng đi ra khỏi kho khác, và không ai biết
                         cho tới lúc kiểm kê. --}}
                    <div class="cua-nhom">
                        <label class="cua-nhan" for="chiNhanh">Chi nhánh</label>
                        <select name="chi_nhanh" id="chiNhanh" class="form-select">
                            {{-- 0 = xem gộp cả cửa hàng, cũng là trạng thái mặc định. --}}
                            <option value="0" {{ $chiNhanhDangChon ? '' : 'selected' }}>Tất cả chi nhánh</option>
                            @foreach($chiNhanh as $cn)
                                <option value="{{ $cn['id'] }}" {{ $chiNhanhDangChon === (int) $cn['id'] ? 'selected' : '' }}>
                                    {{ $cn['name'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="cua-nhom">
                    <span class="cua-nhan">Khu vực</span>
                    <div class="cua-luoi {{ count($modules) < 2 ? 'cua-luoi--mot' : '' }}">
                        @foreach($modules as $m)
                            <button type="submit" name="module" value="{{ $m['ma'] }}"
                                    class="cua-o" style="--i: {{ $loop->index }}">
                                @if(($m['anh'] ?? '') !== '')
                                    <span class="cua-o__anh" aria-hidden="true"
                                          style="background-image: url('{{ asset($m['anh']) }}')"></span>
                                    <span class="cua-o__phu" aria-hidden="true"></span>
                                @else
                                    <svg class="cua-o__thay" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                                         aria-hidden="true">
                                        {!! $m['icon'] !!}
                                    </svg>
                                @endif
                                <span class="cua-o__chu">
                                    <span class="cua-o__ten">
                                        {{ $m['ten'] }}
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m9 6 6 6-6 6"/>
                                        </svg>
                                    </span>
                                    <span class="cua-o__mo-ta">{{ $m['mo_ta'] }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </form>

            {{-- Người một khu KHÔNG được hứa "đổi lại": nút trên thanh của họ là một
                 cái nhãn, bấm không ra gì. --}}
            <p class="cua-ghi">
                @if(count($modules) > 1)
                    Chọn nhầm cũng không sao — đổi lại bất cứ lúc nào ở nút góc phải thanh trên cùng.
                @else
                    Sai tài khoản hay sai tiệm thì đăng xuất rồi vào lại.
                @endif
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @include('partials.toasts')
</body>
</html>
