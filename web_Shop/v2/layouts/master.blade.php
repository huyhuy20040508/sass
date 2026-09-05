{{--
    VỎ CỦA KHU V2 — độc lập hoàn toàn với layouts/app.blade.php (khu cũ).
    Màn nào đã dựng lại theo v2 thì @extends('v2::layouts.master'); route trỏ vào
    view trong resources/views/v2, không đụng view cũ.

    CSS/JS lấy y nguyên từ ordertable/v2/public, để ở public/v2. Thứ tự nạp theo
    khối "admin (layouts/master)" khai trong webpack.mix.js của v2.
--}}

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- CHI NHÁNH MÀ TRANG NÀY ĐÃ VẼ RA. Khối JS "CHI NHÁNH THEO TAB" ở cuối
         <body> đọc con số này để giữ mỗi tab ở đúng kho của nó. --}}
    <meta name="chi-nhanh" content="{{ \App\Services\ApiClient::chiNhanhDangLam() }}">
    <title>@yield('title', 'Quản trị') — {{ app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name')) }}</title>

    @php $adminFavicon = app(\App\Services\ApiClient::class)->settingString('store_favicon'); @endphp
    @if($adminFavicon !== '')
        <link rel="icon" href="{{ $adminFavicon }}">
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}?v=3">
    @endif

    {{-- Bắt tay sẵn với mấy nhà CDN ngay từ đầu <head>: đỡ được một vòng DNS +
         TLS cho mỗi nhà, mà vòng đó nằm ngay trên đường tới nét vẽ đầu tiên. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin />
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin />
    {{-- Inter thay cho Quicksand. Quicksand là phông bo tròn kiểu trang trí:
         chữ số 1/7, chữ I/l gần giống nhau, đọc một bảng giá dày đặc rất mỏi.
         Inter dựng riêng cho giao diện phần mềm, có đủ dấu tiếng Việt và có bộ
         CHỮ SỐ ĐỀU BỀ NGANG (tnum) để cột tiền thẳng hàng theo chiều dọc. --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
        integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    {{-- Dev đọc thẳng public/v2/css, production dùng bản minify ở public/v2/build.
         Hai thư mục cùng cấp nên url(../images/...) trong CSS vẫn trỏ đúng. --}}
    @php $v2css = app()->environment('production') ? 'v2/build' : 'v2/css'; @endphp
    <link href="{{ asset($v2css . '/common.css') }}" rel="stylesheet">
    <link href="{{ asset($v2css . '/style.css') }}" rel="stylesheet">
    <link href="{{ asset($v2css . '/style-for-new-design.css') }}" rel="stylesheet">
    <link href="{{ asset($v2css . '/style1.css') }}" rel="stylesheet">
    <link href="{{ asset($v2css . '/custom.css') }}" rel="stylesheet">
    <link href="{{ asset($v2css . '/sidebars.css') }}" rel="stylesheet">
    <link href="{{ asset($v2css . '/iconselect.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <link rel="stylesheet" href="{{ asset($v2css . '/responsive-manager.css') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    {{-- daterangepicker: bản gốc để tệp CSS này lạc giữa <body>, nên lịch chọn
         ngày nhảy hình một nhịp sau khi trang đã vẽ xong. Kéo lên <head>. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css">

    {{-- CSS riêng của khu v2 (thanh trên cùng, dãy ô module, hàng tab).
         PHẢI nằm ở <head>: để trong <body> như bản gốc thì header vẽ bằng CSS nền
         trước rồi mới tô lại — ra cái nháy lúc chuyển trang. --}}
    <link rel="stylesheet" href="{{ asset('v2/css/khu-v2.css') }}?v={{ filemtime(public_path('v2/css/khu-v2.css')) }}">

    {{-- Hai luật đổ đồng của style.css v2 gây hại, gỡ ra:
         input/option xám #999 làm mọi ô nhập nhìn như bị khoá. --}}
    <style>
        input, option { color: #212529 !important; }
        /* `.modal-body select` của style.css cũng tô #999, mà nó specific hơn nên
           luật `input` ở trên không với tới — mọi ô CHỌN trong hộp thoại chữ xám
           gần như trắng, nhìn hệt ô rỗng. Phải kê lại đúng bằng specificity ấy.
           Ô thật sự bị khoá thì để `:disabled` bên dưới lo, không dùng màu chữ. */
        .modal-body select, .modal-body select option { color: #212529 !important; }
        input::placeholder, textarea::placeholder { color: #9ca3af !important; }

        /* ---------- Phông chữ ----------
           CSS của v2 khai phông ở hai chỗ đá nhau: style.css để `font-family:
           roboto` (viết thường, không nháy — mà Roboto thì không nạp, nên rơi
           về phông mặc định của máy), còn responsive-manager.css lại đặt
           `* { font-family: "Quicksand", serif }` trong media query. Kết quả là
           máy tính một phông, điện thoại một phông.

           Chốt lại một phông duy nhất ở đây. Dùng `*:not(...)` chứ không phải
           `*` kèm !important: phải thắng luật `*` của v2 nhưng KHÔNG được đụng
           tới các thẻ icon — icon vẽ bằng ký tự trong phông riêng (Font Awesome,
           Bootstrap Icons), đổi phông của chúng là mất sạch icon. */
        :root {
            --v2-font: 'Inter', 'Segoe UI', system-ui, -apple-system, 'Helvetica Neue', Arial, sans-serif;
        }

        body,
        *:not(.fa):not(.fas):not(.far):not(.fab):not([class*="fa-"]):not(.bi):not([class*="bi-"]) {
            font-family: var(--v2-font);
        }

        /* Chữ số đều bề ngang: cột tiền, cột số lượng, số trang xếp thẳng cột
           thay vì so le theo bề rộng từng chữ số. */
        table, .form_pagi, input, .select2-container {
            font-variant-numeric: tabular-nums;
            font-feature-settings: 'tnum' 1;
        }

        /* Inter nét mảnh hơn Quicksand nên tiêu đề bảng và nhãn cần đậm thêm
           một nấc mới giữ được thứ bậc như cũ. */
        th, .form-label, .title_search { font-weight: 600; }

        /* Tiêu đề trang là h1 cho đúng thứ bậc, nhưng giữ nguyên dáng chữ của
           bản v2 — đổi thẻ không được đổi cỡ chữ trên màn.

           CON SỐ LẤY THẲNG TỪ CSS THẬT CỦA V2, không ước lượng theo bậc heading
           của Bootstrap: `div.content_midd_title h4` bên custom.css khai
           `font-size: 18px; margin: 0`, còn ≤991px thì 16px. Trước đây chỗ này
           để 1.5rem (24px) vì đọc "h4" rồi lấy cỡ h4 mặc định của Bootstrap —
           to hơn bản gốc hẳn một bậc rưỡi, và mọi trang v2 đều lệch theo. */
        .tieu-de-trang {
            font-size: 18px;
            font-weight: 500;
            line-height: 1.2;
            margin: 0;
        }

        /* v2 GIẤU HẲN tiêu đề ở khổ hẹp (`display: none` trong media query của
           custom.css). Bên này giữ lại và thu về 16px đúng con số v2 khai: thẻ
           h1 là mốc điều hướng của trình đọc màn hình, giấu đi thì người dùng
           bàn phím mất chỗ neo — mà chỗ ấy chỉ chiếm một dòng chữ. */
        @media screen and (max-width: 991px) {
            .tieu-de-trang { font-size: 16px; }
        }
        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }

        /* ---------- Khoảng cách giữa các khối lọc ----------
           Sáu khối xếp sát nhau thì mấy tiêu đề đọc như một khối chữ liền, không
           thấy đâu là ranh giới giữa hai bộ lọc.

           Đặt Ở ĐÂY chứ không rải `mb-3` lên từng khối trong mỗi trang: trước
           tệp này mỗi màn một kiểu — có màn `mt-3`, có màn `mb-3`, có màn không
           gì — nên khung lọc của hai trang cạnh nhau trông khác hẳn. Và tiện ích
           khoảng cách của Bootstrap mang !important, nên hễ trang nào lỡ để lại
           một cái là luật chung không sửa nổi.

           Dùng `div + div` chứ không phải `div`: khối ĐẦU tiên không được đẩy
           xuống, không thì nó rời khỏi mép trên của thẻ. */
        .fillter-box .card-body > form > div + div { margin-top: 22px; }
        /* Tiêu đề của một khối lọc: xuống dòng hẳn rồi mới tới ô, và cách ô một
           nhịp. `span` vốn là chữ nằm ngang nên phải nói rõ. */
        .fillter-box .title_search { display: block; margin-bottom: 8px; }
        /* Dãy ô tick trong một khối: giãn nhẹ cho dễ bấm, nhất là trên điện thoại. */
        .fillter-box .form-check { margin-bottom: 4px; }

        /* ---------- Ô chọn của bộ lọc ----------
           Mọi ô chọn trong khung lọc chạy select2 (xem khối script cuối trang),
           nên phải kéo select2 về đúng dáng .form-control: cùng chiều cao, cùng
           viền, cùng bo góc. Không nắn thì ô chọn lùn hơn ô nhập ngay cạnh nó. */
        .fillter-box .select2-container--default .select2-selection--single,
        .fillter-box .select2-container--default .select2-selection--multiple {
            min-height: 34px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        .fillter-box .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            padding-left: 10px;
            color: #212529;
        }
        .fillter-box .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
        .fillter-box .select2-container--default.select2-container--focus .select2-selection--single,
        .fillter-box .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #86b7fe;
            box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .2);
        }
        .fillter-box .select2-container { width: 100% !important; }
        /* Danh sách xổ ra: cùng cỡ chữ với phần còn lại của khung lọc. */
        .select2-container--default .select2-results__option { font-size: 13px; }
        .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #486a7f; }
    </style>

    @stack('styles')
</head>

<body class="app-scroll">
    <div class="block-action" style="display: none"></div>
    <div id="pullToRefresh">
        <div class="icon">
            <i class="fas fa-rotate"></i>
        </div>
    </div>
    <div class="wrapper">
        @include('v2::layouts.header')
        <div class="container-fluid wrapper-container-fluid">
            <div class="content">
                <div class="container inner-wrapper-container-fluid">
                    <div class="content_content position-relative">
                        @yield('content')
                    </div>
                </div>
            </div>
            @include('v2::layouts.offcanvas-bottom')
        </div>
        {{-- Dải chân trang của v2. Bản gốc ghi cứng tên và hotline của Nasys —
             ở đây lấy tên cửa hàng trong Cài đặt, và chỉ hiện số hỗ trợ khi đã
             khai (chưa khai mà in ra "Hỗ trợ: " trống thì trông như lỗi). --}}
        @php
            $ftApi = app(\App\Services\ApiClient::class);
            $ftTen = $ftApi->settingString('site_name', config('app.name'));
            $ftDienThoai = $ftApi->settingString('contact_phone');
        @endphp
        <div class="cus-footer">
            <span>© {{ date('Y') }} {{ $ftTen }}@if($ftDienThoai !== '') | {{ __('message.support') }}: <span class="fw-bold">{{ $ftDienThoai }}</span>@endif</span>
        </div>
    </div>

    <div class="loading-overlay d-none">
        <div class="spinner-border" role="status">
            <span class="sr-only">Loading...</span>
        </div>
    </div>

    {{-- MỌI script nằm ở cuối <body>, kể cả jQuery.

         Trước đây jQuery đứng trong <head> vì JS của header chạy ngay giữa thân
         trang. Một tệp 90KB chặn đường ở <head> nghĩa là trình duyệt chưa vẽ được
         gì cho tới khi tải xong nó. Nay JS của header đẩy xuống @stack('scripts')
         phía dưới nên jQuery về đúng chỗ của nó — trang vẽ trước, chạy sau. --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <script type="text/javascript" src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    {{-- Dấu phiên bản theo giờ sửa tệp, y như khu-v2.css ở <head>.
         Thiếu nó thì trình duyệt giữ bản cũ rất dai: sửa script.js xong, người
         dùng bấm F5 mấy lượt vẫn chạy mã cũ mà không ai hiểu vì sao — đúng cái
         bẫy vừa mất nửa buổi để lần ra. --}}
    @foreach(['script.js', 'sidebars.js', 'format-input-money.js'] as $tep)
        <script src="{{ asset('v2/js/'.$tep) }}?v={{ filemtime(public_path('v2/js/'.$tep)) }}"></script>
    @endforeach
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        {{-- toastr mặc định bắn ở GÓC TRÊN BÊN PHẢI, còn toast của máy chủ
             (partials.toasts, dựng bằng Bootstrap) thì ở góc dưới bên phải. Hai
             loại cùng một trang mà nhảy ra hai chỗ khác nhau: người dùng vừa nhìn
             xuống góc dưới tìm câu báo thì câu tiếp theo lại hiện trên đầu.

             Chốt hết về GÓC DƯỚI BÊN PHẢI. --}}
        toastr.options = {
            positionClass: 'toast-bottom-right',
            progressBar: true,
            newestOnTop: false,
            preventDuplicates: true,
            timeOut: 4000,
            extendedTimeOut: 2000,
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    {{-- Chart.js đã gỡ: khu v2 chưa màn nào vẽ biểu đồ, mà nó nặng ~200KB mỗi
         lượt vào trang. Dựng màn Thống kê thì nạp riêng ở màn đó. --}}

    {{-- LỊCH CHỌN NGÀY NÓI TIẾNG VIỆT — khai một lần cho cả khu v2.

         daterangepicker mặc định in ra "Su Mo Tu We Th Fr Sa" và "January"; truyền
         mỗi `format` vào thì ô nhập đúng khuôn DD-MM-YYYY nhưng mở lịch ra vẫn là
         một tờ lịch tiếng Anh. Bản v2 khai bộ chữ này ở layout (localeOptions
         trong layouts/master) nên mọi màn đều được — làm y vậy, thay vì mỗi màn
         chép lại mười ba dòng rồi sót một màn nào đó.

         Nơi dùng: `V2.lichVN()` trả về khối `locale`, gộp thêm khoá riêng nếu cần.
         Ví dụ: `$(o).daterangepicker({ locale: V2.lichVN() }, …)`. --}}
    <script>
        window.V2 = window.V2 || {};

        V2.LICH_VN = {
            format: 'DD-MM-YYYY',
            applyLabel: @json(__('message.apply')),
            cancelLabel: @json(__('message.cancel')),
            @php
                $v2Thu = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                $v2Thang = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            @endphp
            daysOfWeek: @json(array_map(fn ($t) => __('message.' . $t), $v2Thu)),
            monthNames: @json(array_map(fn ($t) => __('message.' . $t), $v2Thang)),
        };

        V2.lichVN = function (them) {
            return Object.assign({}, V2.LICH_VN, them || {});
        };
    </script>

    <script>
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                // Chi nhánh của TAB đi kèm mọi lượt gọi ngầm — xem khối
                // "CHI NHÁNH THEO TAB". Thiếu nó thì lượt gọi rơi về chi nhánh
                // trong phiên, tức là chi nhánh mà TAB KHÁC vừa chọn.
                'X-Chi-Nhanh-Tab': String((window.V2 && V2.chiNhanhTab != null) ? V2.chiNhanhTab : ''),
            },
            statusCode: {
                401: function (response) {
                    try {
                        const data = JSON.parse(response.responseText);
                        if (data.redirect) window.location.href = data.redirect;
                    } catch (e) {}
                }
            }
        });
    </script>

    {{-- Chuông thông báo chạy bằng SSE, dùng lại của khu cũ. --}}
    @unless(\App\Services\HanSuDung::daKhoa())
        <script src="{{ asset('js/realtime.js') }}?v=5"></script>
    @endunless

    {{-- Câu báo của máy chủ vẫn dựng ra dạng toast Bootstrap, nhưng GIẤU ĐI —
         giữ lại vì `V2.toastTu` đọc đúng khuôn này khi bóc câu báo từ phản hồi
         của một lượt ghi bằng AJAX. Ngay khi trang sẵn sàng thì chuyển hết sang
         toastr rồi dọn nốt phần xác.

         Vì sao không để cả hai cùng hiện: hai loại toast là hai chồng khác nhau,
         cùng ghim ở góc dưới bên phải thì chúng đè lên nhau. --}}
    <style>#jhToastContainer { display: none !important; }</style>
    @include('partials.toasts')
    <script>
        $(function () {
            V2.toastTu(document);
            var hop = document.getElementById('jhToastContainer');
            if (hop) hop.remove();
        });
    </script>

    {{-- Controller nạp dữ liệu hỏng thì trả view kèm ->with('error', ...). Đó là
         BIẾN của view chứ không phải flash trong session, nên partials.toasts ở
         trên không thấy nó. Không bắn thêm ở đây thì API sập là trang hiện bảng
         rỗng, nhìn y hệt "cửa hàng chưa khai gì". --}}
    @isset($error)
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                {{-- JSON_UNESCAPED_UNICODE: không có nó thì @json biến "sập"
                     thành ập — vẫn chạy, nhưng đọc mã nguồn thì mù. --}}
                toastr.error(@json($error, JSON_UNESCAPED_UNICODE));
            });
        </script>
    @endisset

    {{-- =================== CHI NHÁNH THEO TAB ===================

         Chi nhánh đang làm việc phải là chuyện của TỪNG TAB. Trước khối này nó
         nằm trong phiên — một giá trị cho cả trình duyệt — nên mở tab thứ hai
         xem kho khác là tab thứ nhất cũng đổi theo, và bấm F5 bên tab cũ thì nó
         "về lại" chi nhánh vừa chọn ở tab kia.

         Tệ hơn cái nhìn thấy: tab cũ VẪN hiện "chi nhánh 1" trên thanh trên
         cùng nhưng mọi lượt ghi từ nó đi vào kho 2 — máy chủ đọc phiên tại thời
         điểm nhận request, không phải chi nhánh mà trang ấy đã vẽ.

         sessionStorage là chỗ đúng để giữ: trình duyệt cấp cho MỖI TAB một kho
         riêng, và nó sống qua F5 — đúng hai thứ cần. localStorage thì dùng
         chung mọi tab, tức là quay lại đúng lỗi đang chữa.

         Hai móc, và chỉ hai:
           1. ĐỌC — trang vẽ ra không khớp chi nhánh của tab thì nạp lại MỘT lần
              kèm ?chi_nhanh=… Nhờ vậy không link nào phải sửa: bấm menu, bấm
              Back, gõ thẳng URL đều tự về đúng kho.
           2. GHI — nhét chi nhánh vào mọi form và mọi lượt gọi ngầm. Ghi thì
              không sửa lại được sau, nên phải khai TRƯỚC khi gửi. --}}
    <script>
        (function () {
            var KHOA = 'v2_chi_nhanh_tab';
            var the = document.querySelector('meta[name="chi-nhanh"]');
            var cuaTrang = the ? parseInt(the.content, 10) || 0 : 0;

            // null = tab CHƯA quyết; 0 = tab đã chọn "Tất cả chi nhánh" (xem gộp).
            // Hai thứ phải phân biệt: coi 0 là "chưa quyết" thì tab đang xem gộp
            // cứ mỗi lượt mở trang lại nhận lấy chi nhánh mà tab khác vừa chọn.
            var cuaTab = null;
            try {
                var luu = sessionStorage.getItem(KHOA);
                cuaTab = luu === null ? null : (parseInt(luu, 10) || 0);
            } catch (e) {}

            // Tab vừa mở (hoặc trình duyệt chặn sessionStorage): nhận lấy chi
            // nhánh mà máy chủ vừa vẽ và coi đó là của mình từ giờ.
            if (cuaTab === null) {
                cuaTab = cuaTrang;
                try { sessionStorage.setItem(KHOA, String(cuaTab)); } catch (e) {}
            }

            window.V2 = window.V2 || {};
            V2.chiNhanhTab = cuaTab;

            /** Đổi chi nhánh của RIÊNG tab này rồi nạp lại trang. */
            V2.doiChiNhanhTab = function (id) {
                id = parseInt(id, 10);
                if (isNaN(id) || id < 0) return;
                try { sessionStorage.setItem(KHOA, String(id)); } catch (e) {}
                V2.chiNhanhTab = id;
                location.href = V2.themChiNhanh(location.href, id);
            };

            /** Gắn ?chi_nhanh=… vào một địa chỉ. Bỏ qua link ra ngoài. */
            V2.themChiNhanh = function (url, id) {
                if (id == null) id = V2.chiNhanhTab;
                if (id == null) return url;
                try {
                    var u = new URL(url, location.origin);
                    if (u.origin !== location.origin) return url;
                    u.searchParams.set('chi_nhanh', String(id));

                    return u.pathname + u.search + u.hash;
                } catch (e) {
                    return url;
                }
            };

            // ---- Móc 1: trang đang vẽ sai kho thì nạp lại đúng kho của tab ----
            //
            // Chỉ nạp lại khi THẬT SỰ lệch, và chỉ với lượt mở trang (có thẻ
            // meta). Không có vòng lặp: lượt sau mang ?chi_nhanh nên máy chủ vẽ
            // đúng, hai con số bằng nhau và điều kiện này tắt.
            if (cuaTab !== null && cuaTrang !== cuaTab) {
                location.replace(V2.themChiNhanh(location.href, cuaTab));

                return;
            }

            // ---- Móc 2: mọi lượt GHI mang theo chi nhánh của tab ----
            //
            // Gắn ở giai đoạn CAPTURE để chạy trước mọi handler khác: màn nào
            // chặn submit rồi tự gửi bằng fetch thì cũng đã có sẵn ô này trong
            // form để đọc ra.
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form || form.tagName !== 'FORM' || V2.chiNhanhTab == null) return;
                if (form.querySelector('input[name="chi_nhanh"]')) return;

                var o = document.createElement('input');
                o.type = 'hidden';
                o.name = 'chi_nhanh';
                o.value = String(V2.chiNhanhTab);
                form.appendChild(o);
            }, true);
        })();
    </script>

    @stack('scripts')

    {{-- ĐẶT SAU @stack('scripts') là cố ý: màn nào tự gọi select2 cho ô của nó
         (kèm placeholder riêng, kèm dropdownParent của hộp thoại) thì chạy
         trước, khối này chỉ nhặt nốt phần còn sót — `.select2-hidden-accessible`
         là dấu select2 để lại trên ô đã dựng.

         Vì sao không để ô chọn mặc định của trình duyệt: mỗi hệ điều hành vẽ
         một kiểu, không đổi được cỡ chữ lẫn chiều cao, và danh sách dài thì
         không có ô tìm. --}}
    {{-- ================= NẠP LẠI DANH SÁCH KHÔNG TẢI LẠI TRANG =================

         Trước đây mọi thao tác đọc (lọc, đổi trang, đổi số dòng, bấm tiêu đề cột
         để sắp xếp) đều là một lượt tải lại cả trang: nạp lại jQuery, Bootstrap,
         select2, toàn bộ CSS, dựng lại khung, chuông thông báo gọi lại từ đầu —
         chỉ để thay mấy chục dòng trong bảng. Màn hình trắng một nhịp, mất chỗ
         đang cuộn, mất luôn con trỏ trong ô tìm.

         Nay chỉ tải HTML của chính trang đó rồi THAY ĐÚNG mấy khối danh sách.
         Không cần thêm route hay controller nào: máy chủ vẫn trả trang như cũ,
         phía trình duyệt lấy phần cần và bỏ phần còn lại.

         Vì sao vẫn chạy sau khi thay ruột: mọi trình xử lý của các màn đều gắn
         theo lối uỷ quyền (`$(document).on(...)`) chứ không gắn thẳng vào từng
         dòng, nên dòng mới thay vào vẫn ăn đủ sự kiện. --}}
    <script>
        window.V2 = window.V2 || {};

        {{-- Những khối được thay khi nạp lại. Khối nào trang không có thì bỏ qua. --}}
        V2.KHOI = ['.list', '.table-list-container', '.form_pagi'];

        V2.dangNap = false;

        V2.napLai = function (url, dayVaoLichSu) {
            if (V2.dangNap) return;
            V2.dangNap = true;
            $('.loading-overlay').removeClass('d-none');

            // Mọi địa chỉ nạp lại đều mang chi nhánh của TAB. Các màn dựng URL
            // bằng cách chép lại vài tham số đã biết (hide, page_size…), nên
            // không gắn ở đây thì `chi_nhanh` rụng ngay lượt lọc đầu tiên và
            // bảng quay về chi nhánh của phiên.
            url = V2.themChiNhanh ? V2.themChiNhanh(url) : url;

            return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (res) {
                    // Hết phiên thì máy chủ đá về trang đăng nhập — lúc đó phải
                    // tải lại thật, không thể nhét trang đăng nhập vào giữa bảng.
                    if (res.redirected && !res.url.includes(location.pathname)) {
                        location.href = res.url;

                        return null;
                    }

                    return res.text();
                })
                .then(function (html) {
                    if (html === null) return;
                    var doc = new DOMParser().parseFromString(html, 'text/html');

                    V2.KHOI.forEach(function (sel) {
                        var moi = doc.querySelector(sel);
                        var cu = document.querySelector(sel);
                        if (moi && cu) cu.innerHTML = moi.innerHTML;
                    });

                    if (dayVaoLichSu !== false) history.pushState({ v2: 1 }, '', url);
                    $(document).trigger('v2:da-nap');
                })
                .catch(function () {
                    // Mạng đứt giữa chừng: quay về cách cũ còn hơn đứng im.
                    location.href = url;
                })
                .then(function () {
                    V2.dangNap = false;
                    $('.loading-overlay').addClass('d-none');
                });
        };

        {{-- Bắn lại mấy câu báo mà máy chủ đã dựng sẵn trong trang trả về. --}}
        V2.toastTu = function (doc) {
            doc.querySelectorAll('.toast-container .toast').forEach(function (el) {
                var than = el.querySelector('.toast-body');
                if (!than) return;
                var chu = than.textContent.trim();
                if (!chu) return;
                el.className.indexOf('danger') !== -1 ? toastr.error(chu) : toastr.success(chu);
            });
        };

        {{-- GHI KHÔNG TẢI LẠI TRANG.

             Dùng cho các thao tác ghi NGAY TRÊN BẢNG: gạt công tắc trạng thái,
             bấm mũi tên đổi thứ tự. Trước đây chúng dựng một form ẩn rồi submit
             thật, nên mỗi lần gạt công tắc là cả trang trắng một nhịp.

             Máy chủ vẫn trả về chuyển hướng như cũ, không phải sửa controller:
             fetch tự đi theo, ta nhặt lấy câu báo và phần bảng trong trang đích.
             Trang đích có đúng địa chỉ đang xem hay không thì kiểm lại — màn nào
             gửi kèm `return` sẽ về đúng chỗ, màn nào không thì nạp lại theo địa
             chỉ hiện tại để khỏi mất bộ lọc. --}}
        V2.ghi = function (action, method, fields) {
            if (V2.dangNap) return;
            V2.dangNap = true;
            $('.loading-overlay').removeClass('d-none');

            var fd = new FormData();
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
            if (method && method !== 'POST') fd.append('_method', method);
            fd.append('return', location.pathname + location.search);
            // Lượt GHI phải khai chi nhánh TRƯỚC khi gửi: ghi xong rồi thì không
            // sửa lại được, và ghi nhầm kho là dữ liệu sai nằm lại vĩnh viễn.
            if (V2.chiNhanhTab) fd.append('chi_nhanh', String(V2.chiNhanhTab));
            $.each(fields || {}, function (k, v) {
                Array.isArray(v)
                    ? v.forEach(function (x) { fd.append(k, x); })
                    : fd.append(k, v == null ? '' : v);
            });

            return fetch(action, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then(function (res) { return res.text().then(function (t) { return { html: t, url: res.url }; }); })
                .then(function (r) {
                    var doc = new DOMParser().parseFromString(r.html, 'text/html');
                    V2.toastTu(doc);

                    if (r.url.split('?')[0] === location.origin + location.pathname
                        || r.url.split('?')[0] === location.pathname) {
                        V2.KHOI.forEach(function (sel) {
                            var moi = doc.querySelector(sel);
                            var cu = document.querySelector(sel);
                            if (moi && cu) cu.innerHTML = moi.innerHTML;
                        });
                        $(document).trigger('v2:da-nap');
                        V2.dangNap = false;
                        $('.loading-overlay').addClass('d-none');

                        return;
                    }

                    V2.dangNap = false;
                    $('.loading-overlay').addClass('d-none');

                    return V2.napLai(location.href, false);
                })
                .catch(function () {
                    // Ghi xong mà không đọc được phản hồi thì phải tải lại thật:
                    // dữ liệu có thể đã đổi, để bảng cũ trên màn là nói dối.
                    location.reload();
                });
        };

        {{-- Lưu từ MỘT HỘP THOẠI.
             Khác V2.ghi ở đúng một chỗ, mà chỗ ấy là tất cả: LƯU HỎNG THÌ GIỮ
             HỘP LẠI. Trước đây mọi hộp đều lưu bằng form ẩn nên trang tải lại,
             hộp biến mất rồi toast mới hiện — trùng tên một cái là mất trắng
             mọi thứ vừa gõ và phải khai lại từ đầu mới biết sai ở đâu. --}}
        V2.luuHop = function (hop, action, method, fields, $nut) {
            var $hop = $(hop);
            if ($nut && $nut.prop('disabled')) return;
            if ($nut) $nut.prop('disabled', true);

            var fd = new FormData();
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));
            if (method && method !== 'POST') fd.append('_method', method);
            fd.append('return', location.pathname + location.search);
            // Lượt GHI phải khai chi nhánh TRƯỚC khi gửi: ghi xong rồi thì không
            // sửa lại được, và ghi nhầm kho là dữ liệu sai nằm lại vĩnh viễn.
            if (V2.chiNhanhTab) fd.append('chi_nhanh', String(V2.chiNhanhTab));
            $.each(fields || {}, function (k, v) {
                Array.isArray(v)
                    ? v.forEach(function (x) { fd.append(k, x); })
                    : fd.append(k, v == null ? '' : v);
            });

            return fetch(action, {
                method: 'POST',
                body: fd,
                // Accept JSON: controller nhận ra là hộp thoại gọi nên trả thẳng
                // {success, message} thay vì chuyển hướng.
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then(function (res) {
                    return res.json().then(function (r) { return { ok: res.ok, body: r || {} }; });
                })
                .then(function (r) {
                    if (!r.ok) {
                        // Lấy lỗi theo TỪNG Ô trước, `message` chỉ là đường lùi.
                        // Laravel gộp sẵn message thành "Chưa nhập tên. (and 1
                        // more error)" — chữ Anh lẫn vào và vẫn giấu mất ô thứ hai.
                        var theoO = Object.keys(r.body.errors || {})
                            .map(function (k) { return [].concat(r.body.errors[k]).join(' '); })
                            .join(' ');
                        var cau = theoO || r.body.message || 'Lưu không thành công.';
                        toastr.error(cau);

                        return false;
                    }

                    $hop.modal('hide');
                    toastr.success(r.body.message || 'Đã lưu.');
                    V2.napLai(location.href, false);

                    return true;
                })
                .catch(function () {
                    toastr.error('Không gửi được lượt lưu. Kiểm tra kết nối rồi thử lại.');

                    return false;
                })
                // Mở khoá nút dù xong hay hỏng: khoá mà không mở thì sửa xong
                // không bấm Lưu lại được, phải đóng hộp làm từ đầu.
                .finally(function () { if ($nut) $nut.prop('disabled', false); });
        };

        {{-- Nút lùi/tiến của trình duyệt phải trả lại đúng bảng của địa chỉ đó. --}}
        window.addEventListener('popstate', function () { V2.napLai(location.href, false); });

        {{-- Bấm số trang và bấm tiêu đề cột để sắp xếp: cùng là "đọc lại danh
             sách theo một địa chỉ khác" nên đi chung một đường. --}}
        {{-- Form lọc của các màn đều là GET và đều nằm trong khung lọc. Chặn
             lượt gửi thật, đổi thành nạp lại danh sách tại chỗ. --}}
        $(document).on('submit', '.fillter-box form', function (e) {
            e.preventDefault();
            var q = $(this).serialize();
            V2.napLai((this.getAttribute('action') || location.pathname) + (q ? '?' + q : ''));
        });

        $(document).on('click', '.form_pagi a.page-link, a.js-table-sort', function (e) {
            var href = $(this).attr('href');
            if (!href || href === '#') return;
            e.preventDefault();
            V2.napLai(href);
        });
    </script>

    <script>
        {{-- Ô "số dòng mỗi trang": trước đây mỗi màn chép một đoạn onchange
             giống hệt nhau ngay trên thẻ. Gom về đây vì select2 nuốt thẻ gốc,
             mà dựa vào việc jQuery có gọi hộ handler viết thẳng trên thẻ hay
             không thì mong manh quá — đổi cỡ trang mà im lặng là hỏng ngầm.
             Tên tham số khác nhau giữa các màn nên đọc ở data-param. --}}
        $(document).on('change', 'select.item-per-page', function () {
            var q = new URLSearchParams(location.search);
            q.set($(this).data('param') || 'page_size', this.value);
            q.set('page', 1);
            V2.napLai(location.pathname + '?' + q);
        });

        {{-- CHỈ ô trong khung lọc. Ô "số dòng mỗi trang" thì KHÔNG:
             `div.content_midd .item-per-page` của v2 là ô nhỏ ghim tuyệt đối ở
             góc trái dưới (`position:absolute; width:auto`). select2 giấu thẻ
             gốc đi và dựng thẻ thay thế nằm trong dòng chảy bình thường, nên ô
             đang bé xíu ở góc biến thành một dải kéo hết bề ngang. --}}
        $(function () {
            $('.fillter-box select')
                .not('.select2-hidden-accessible')
                .each(function () {
                    var $o = $(this);

                    // KHÔNG khai `placeholder`. Ô lọc nào cũng có sẵn dòng rỗng
                    // mang nghĩa thật ("Tất cả"), mà select2 coi dòng rỗng là
                    // placeholder thì nó rút dòng đó khỏi danh sách — chọn một
                    // trạng thái xong là KHÔNG có đường quay lại "Tất cả" nữa.
                    $o.select2({
                        width: '100%',
                        // Danh sách ngắn thì ô tìm chỉ tổ vướng; dài mới cần.
                        minimumResultsForSearch: $o.find('option').length > 8 ? 0 : Infinity,
                        language: {
                            // Ô CHỌN NHIỀU: select2 giấu những dòng đã tick, nên
                            // tick hết là danh sách rỗng và nó bung ra câu này —
                            // trông hệt như không đọc được dữ liệu. Nói rõ ra để
                            // khỏi tưởng ô lọc hỏng.
                            noResults: function () {
                                return $o.prop('multiple')
                                    ? 'Đã chọn hết — không còn mục nào để thêm'
                                    : 'Không có mục nào khớp';
                            },
                            searching: function () { return 'Đang tìm…'; },
                        },
                    });
                });
        });
    </script>
</body>

</html>
