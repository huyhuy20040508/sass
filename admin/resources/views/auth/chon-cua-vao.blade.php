{{--
    MÀN CHỌN CỬA VÀO — hiện ngay sau khi đăng nhập, cho người được giao CẢ HAI khu.

    Đứng riêng chứ không dùng layouts.app hay layouts.thu-ngan, và đó là điều
    kiện của chính màn hình này: tới đây người dùng CHƯA chọn khu nào, nên không
    có thanh trái của khu quản trị lẫn thanh quầy nào để mượn. Mượn một trong hai
    là đã trả lời hộ câu đang hỏi.

    Lấy đúng dáng của trang đăng nhập (cùng ảnh nền, cùng thẻ trắng bo góc, cùng
    tông xanh) — hai màn hình này đi liền nhau trong vài giây, đổi kiểu giữa chừng
    thì trông như vừa nhảy sang một phần mềm khác.
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
            --au-primary: #1890ff;
            --au-primary-dark: #0e7ce0;
        }

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
            color: #212529;
            background: #2b2f36 url('{{ asset('images/login-bg.jpg') }}') center / cover no-repeat fixed;
        }

        .cua-box {
            box-sizing: border-box;
            width: 100%;
            max-width: 620px;
            padding: 30px 34px 26px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 0 24px rgba(16, 24, 40, .10),
                        0 8px 32px rgba(16, 24, 40, .18);
        }

        .cua-head { text-align: center; margin-bottom: 22px; }
        .cua-head img { max-width: 150px; height: auto; }
        .cua-head h1 {
            margin: 14px 0 4px;
            font-size: 17px;
            font-weight: 500;
            color: #1f2937;
        }
        .cua-head p { margin: 0; color: #6b7280; font-size: 13px; }

        /* Tên cửa hàng: người trông nhiều tiệm gõ nhầm mã cửa hàng là chuyện có
           thật, và đây là màn hình cuối cùng còn kịp nhận ra trước khi bắt đầu
           ghi sổ vào nhầm nơi. */
        .cua-shop {
            display: inline-block;
            margin-top: 8px;
            padding: 3px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 12px;
            font-weight: 500;
        }

        .cua-label {
            display: block;
            margin-bottom: .35rem;
            font-weight: 500;
            color: #374151;
        }

        /* Hai ô module. To và vuông: đây là thao tác duy nhất của cả trang, mà máy
           ở quầy thường là màn hình cảm ứng — một dòng chữ có link không đủ chỗ
           cho đầu ngón tay. */
        .cua-grid {
            display: grid;
            grid-template-columns: repeat({{ min(count($modules), 2) }}, 1fr);
            gap: 14px;
        }

        .cua-o {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 22px 16px 18px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            color: #1e293b;
            font-family: inherit;
            text-align: center;
            cursor: pointer;
            transition: border-color .15s, box-shadow .15s, transform .15s;
        }
        .cua-o:hover,
        .cua-o:focus-visible {
            border-color: var(--au-primary);
            box-shadow: 0 6px 18px rgba(24, 144, 255, .18);
            transform: translateY(-2px);
            outline: none;
        }
        .cua-o__ico {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 54px;
            height: 54px;
            border-radius: 12px;
            background: #eff6ff;
            color: #1d4ed8;
        }
        .cua-o__ico svg { width: 26px; height: 26px; }
        .cua-o__ten { font-size: 15px; font-weight: 700; }
        .cua-o__mo-ta {
            font-size: 12px;
            line-height: 1.45;
            color: #64748b;
        }

        .cua-foot {
            margin-top: 22px;
            padding-top: 16px;
            border-top: 1px solid #eef0f3;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 12px;
            color: #9aa3af;
        }
        .cua-foot button {
            border: 0;
            background: none;
            padding: 0;
            font: inherit;
            color: #6b7280;
            cursor: pointer;
            white-space: nowrap;
        }
        .cua-foot button:hover { color: #dc3545; }

        @media (max-width: 560px) {
            body { padding: 16px; }
            .cua-box { padding: 24px 20px 20px; }
            /* Màn hình hẹp: xếp dọc. Ép hai ô cạnh nhau ở đây thì dòng mô tả gãy
               làm bốn và mỗi ô cao gần bằng cả màn hình. */
            .cua-grid { grid-template-columns: 1fr; }
            .cua-o { flex-direction: row; text-align: left; padding: 14px; }
            .cua-o__ico { width: 44px; height: 44px; flex-shrink: 0; }
            .cua-foot { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>
    <div class="cua-box">
        <div class="cua-head">
            <img src="{{ asset('images/logo-default.svg') }}" alt="{{ config('app.name') }}">
            @php
                // Cùng thứ tự với topbar: họ tên người tự đặt trước, rồi tới tên
                // đăng nhập — thứ họ vừa gõ để vào đây.
                $ten = trim((string) data_get($nguoiDung, 'full_name', ''));
                if ($ten === '') {
                    $ten = trim((string) data_get($nguoiDung, 'username', ''));
                }
            @endphp
            <h1>{{ $ten !== '' ? 'Xin chào, '.$ten : 'Xin chào' }}</h1>
            <p>Chọn nơi bạn bắt đầu làm việc hôm nay</p>
            @if($cuaHang !== '')
                <span class="cua-shop"><i class="bi bi-shop me-1"></i>{{ $cuaHang }}</span>
            @endif
        </div>

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
                <div class="mb-4">
                    <label class="cua-label" for="chiNhanh">Chi nhánh làm việc</label>
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

            <span class="cua-label">Khu vực</span>
            <div class="cua-grid">
                @foreach($modules as $m)
                    <button type="submit" name="module" value="{{ $m['ma'] }}" class="cua-o">
                        <span class="cua-o__ico">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                                {!! $m['icon'] !!}
                            </svg>
                        </span>
                        <span>
                            <span class="cua-o__ten d-block">{{ $m['ten'] }}</span>
                            <span class="cua-o__mo-ta d-block">{{ $m['mo_ta'] }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </form>

        {{-- Đăng xuất nằm NGOÀI biểu mẫu trên: lồng form trong form thì trình duyệt
             bỏ cái bên trong, và nút này im lặng không làm gì cả. --}}
        <div class="cua-foot">
            <span>Chọn nhầm cũng không sao — đổi lại bất cứ lúc nào ở nút góc phải thanh trên cùng.</span>
            <form method="POST" action="{{ route('logout') }}" class="m-0">
                @csrf
                <button type="submit"><i class="bi bi-box-arrow-right me-1"></i>Đăng xuất</button>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    @include('partials.toasts')
</body>
</html>
