{{--
    VỎ MODULE THU NGÂN — CHÉP KHUNG MÀN THU NGÂN table1.klkim.com/v2 (order/cashier).

    Giống bản gốc ở ba tầng, để CSS của bản gốc vẽ ra đúng màn ấy:
      · CSS: đúng bộ, đúng thứ tự trang gốc nạp — libs (bootstrap riêng, daterangepicker,
        toastr, select2, jquery-ui) → common · order-shift · style · style-for-new-design
        · style1 · custom · order-cashier · cashier-index · cashier-online → font-vi →
        cashier-page.css (khối <style> trong <head> của bản gốc, chép nguyên văn) →
        list-table-cashier → cashier-header.css (khối <style> đầu .wrapper, chép nguyên văn).
        Phông Roboto do order-cashier.css đặt (`* { font-family: Roboto }`).
      · Khung: body.app-scroll > .wrapper > .header_bg (thanh trên cùng) +
        .main-body-container + .cus-footer. KHÔNG có hàng tab — bản gốc không có.
      · Thanh trên cùng: logo · ô module · cờ · tài khoản · chuông · ☰ · loa.

    Khác bản gốc: ô "Đặt bàn" bỏ (quán ăn mới có); ô chưa có màn (Thu chi, Trả hàng, CRM)
    vẫn bày như bản gốc nhưng bấm vào chỉ báo "chưa dựng" chứ không dẫn tới trang lỗi.

    HAI KHUÔN THÂN — màn nào khai section nào thì layout dựng khuôn đó:
      KHUÔN QUẦY (có section 'khu-trai'): .table-order-container > .order-layout >
        .menu-area + .bill-area. Section: ten-khu-trai · dau-khu-trai · khu-trai ·
        dau-khu-phai · khu-phai.
      KHUÔN DANH SÁCH (mặc định): 'content' trong một tấm trắng.
    Section chung: title · than (thêm lớp cho <body>). Stack: styles · modals · scripts.
--}}
@php
    $tnApi = app(\App\Services\ApiClient::class);
    $tnTenTiem = $tnApi->settingString('site_name', config('app.name'));
    $tnLogo = $tnApi->settingString('store_logo') ?: asset('images/sellio-logo-full.svg');
    $tnDienThoai = $tnApi->settingString('contact_phone');

    $tnCn = \App\Services\CurrentBranch::danhSach();
    $tnUser = session('api.user');
    $tnTen = trim((string) data_get($tnUser, 'full_name', '')) ?: (string) data_get($tnUser, 'username', 'Tài khoản');
    // Hồ sơ và đổi chi nhánh là đường của khu quản trị: người CHỈ có cửa quầy không gọi được.
    $tnCoQuanLy = in_array('quan_ly', \App\Http\Middleware\EnsureWorkspace::cuaCuaPhien(), true);

    // Ô module — đúng thứ tự, hình và nhãn của bản gốc. route null = màn chưa dựng.
    $tnMuc = [
        ['nhan' => 'Trang chủ', 'tieu_de' => 'Trang chủ', 'anh' => 'icons/cashier-home.png', 'route' => 'thu-ngan.ban-hang.index', 'dang' => 'thu-ngan.ban-hang.*'],
        ['nhan' => 'Lịch sử', 'tieu_de' => 'Lịch sử đơn hàng', 'anh' => 'icons/cashier-history.svg', 'route' => 'thu-ngan.don-hang.index', 'dang' => 'thu-ngan.don-hang.*'],
        ['nhan' => 'Điều phối ca', 'tieu_de' => 'Điều phối ca', 'anh' => 'icons/cashier-shift.png', 'route' => 'thu-ngan.ca-lam-viec.index', 'dang' => 'thu-ngan.ca-lam-viec.*'],
        ['nhan' => 'Thu chi', 'tieu_de' => 'Quản lý thu chi', 'anh' => 'icons/cashier-expenditure.png', 'route' => null],
        ['nhan' => 'Trả hàng', 'tieu_de' => 'Trả hàng', 'anh' => 'icons/cashier-refund.png', 'route' => null],
        ['nhan' => 'CRM', 'tieu_de' => 'CRM', 'anh' => 'ic_CRM.png', 'route' => null],
    ];

    // Hộp ☰: nửa trên chi nhánh, nửa dưới lối sang module — danhSach() đã lọc theo cửa vào.
    $tnModuleDs = \App\Services\SubscriptionExpiry::daKhoa() ? [] : \App\Services\WorkspaceModule::danhSach();
    $tnModuleDangO = \App\Services\WorkspaceModule::hienTai();
    $tnAnhModule = [\App\Services\WorkspaceModule::QUAN_TRI => 'admin', \App\Services\WorkspaceModule::THU_NGAN => 'cashier'];
    $tnDoiCnDuoc = $tnCoQuanLy && count($tnCn['ds']) > 1;

    $tnCss = app()->environment('production') ? 'v2/build' : 'v2/css';
    $tnQuay = $__env->hasSection('khu-trai');
    $tnLopThan = trim('app-scroll '.$__env->yieldContent('than'));
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Thu ngân') — {{ $tnTenTiem }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=3" sizes="32x32">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100;0,300;0,400;0,500;0,700;0,900;1,100;1,300;1,400;1,500;1,700;1,900&family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="{{ asset('v2/css/libs/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('v2/css/libs/daterangepicker.css') }}">
    <link rel="stylesheet" href="{{ asset('v2/css/libs/toastr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('v2/css/libs/select2.min.css') }}">
    <link rel="stylesheet" href="{{ asset('v2/css/libs/jquery-ui.min.css') }}">
    @foreach(['common', 'order-shift', 'style', 'style-for-new-design', 'style1', 'custom', 'order-cashier', 'cashier-index', 'cashier-online'] as $tep)
        <link href="{{ asset($tnCss.'/'.$tep.'.css') }}" rel="stylesheet">
    @endforeach
    <link href="{{ asset('v2/css/font-vi.css') }}" rel="stylesheet">
    <link href="{{ asset('v2/css/cashier-page.css') }}?v={{ filemtime(public_path('v2/css/cashier-page.css')) }}" rel="stylesheet">
    <link href="{{ asset($tnCss.'/list-table-cashier.css') }}" rel="stylesheet">
    <link href="{{ asset('v2/css/cashier-header.css') }}?v={{ filemtime(public_path('v2/css/cashier-header.css')) }}" rel="stylesheet">
    {{-- Khối màu theo cửa hàng — bản gốc để ở cuối <body>, tức đứng SAU mọi CSS khác. --}}
    <link href="{{ asset('v2/css/cashier-theme.css') }}?v={{ filemtime(public_path('v2/css/cashier-theme.css')) }}" rel="stylesheet">

    <style>
        /* Chỉ những gì bản gốc không cần vì nó không có: khuôn danh sách, dòng chi nhánh
           không bấm được, menu tài khoản bằng form đăng xuất. */
        .tn-trang { padding: 8px 10px 12px; }
        .tn-tam { min-height: 200px; padding: 14px 16px; border-radius: 6px; background: #fff; }
        .dropdown-menu-branch-container li:not(.tn-doi-cn) { cursor: default; }
        .menu_user .action .menu form { margin: 0; }
        .menu_user .action .menu .tn-dang-xuat button { padding: 0; border: 0; background: none; color: inherit; }
        .main-menu-inner .icon-item a[data-chua-dung] { cursor: default; }
        /* Logo bản gốc là PNG có sẵn kích thước nên CSS gốc không ghìm; logo của tiệm
           (SVG hoặc ảnh tải lên) thì không có — để trần là nó phình hết thanh. */
        .header_content .logo img { height: 45px; width: auto; max-width: 150px; object-fit: contain; }
    </style>

    @stack('styles')
</head>

<body class="{{ $tnLopThan }}">
    <div class="block-action" style="display: none"></div>
    <div class="wrapper">
        <div class="container-fluid header_bg">
            <div class="header">
                <div class="pt-xl-2 container">
                    <div class="header_content">
                        <div class="logo">
                            <a href="{{ route('thu-ngan.ban-hang.index') }}"><img alt="{{ $tnTenTiem }}" src="{{ $tnLogo }}"></a>
                        </div>

                        <div class="main-menu-container">
                            <div class="main-menu-inner">
                                @foreach($tnMuc as $m)
                                    <div data-bs-toggle="tooltip" data-bs-placement="bottom" data-bs-title="{{ $m['tieu_de'] }}"
                                         class="sidebar-item icon-item {{ $m['route'] && request()->routeIs($m['dang']) ? 'active' : '' }}">
                                        <a href="{{ $m['route'] ? route($m['route']) : '#' }}" @unless($m['route']) data-chua-dung="{{ $m['tieu_de'] }}" @endunless>
                                            <img src="{{ asset('v2/images/'.$m['anh']) }}" alt="" width="30">
                                            <p class="text-detail">{{ $m['nhan'] }}</p>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="header_menu header-menu-container header-cashier-menu-container">
                            <div class="menu_language">
                                <div class="dropup dropup-language">
                                    <button class="dropbtn" id="languageBtn" type="button" title="Tiếng Việt">
                                        <img src="{{ asset('v2/images/vi.png') }}" alt="vi">
                                    </button>
                                </div>
                            </div>

                            <div class="menu_user">
                                <div class="action" id="dropdown-profile">
                                    <div class="profile d-flex flex-column align-items-center flex-xl-row justify-content-xl-around" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
                                        <span class="ps-2 text-center text-capitalize">{{ $tnTen }}</span>
                                        <i class="fa fa-user-circle"></i>
                                    </div>
                                    <div class="menu">
                                        <ul>
                                            @if($tnCoQuanLy)
                                                <li class="view-profile">
                                                    <i class="far fa-user-circle"></i>
                                                    <a href="{{ route('admin.profile.edit') }}">Tài khoản của tôi</a>
                                                </li>
                                            @endif
                                            <li class="tn-dang-xuat" style="border-top:1px solid rgba(0,0,0,0.05);">
                                                <i class="fas fa-sign-out-alt"></i>
                                                <form method="POST" action="{{ route('logout') }}">
                                                    @csrf
                                                    <button type="submit">Đăng xuất</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="menu_report">
                                <button type="button" class="dropbtn bell get_noti" id="tnChuong" title="Thông báo">
                                    <i class="fa fa-bell" aria-hidden="true"></i>
                                </button>
                            </div>

                            <div class="menu_setting btn-dropdown-container-wapper">
                                <div class="dropdown">
                                    <a class="btn btn-secondary dropdown-toggle btn-dropdown-container" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fa fa-bars"></i>
                                    </a>
                                    <div class="dropdown-menu z-index-10000">
                                        <ul class="dropdown-menu-branch-container">
                                            @foreach($tnCn['ds'] as $cn)
                                                <li data-value="{{ $cn['id'] }}"
                                                    class="{{ (int) $tnCn['dangChon'] === (int) $cn['id'] ? 'selected' : '' }} {{ $tnDoiCnDuoc ? 'tn-doi-cn' : '' }}"
                                                    @unless($tnDoiCnDuoc) title="Đổi chi nhánh là việc của khu quản trị" @endunless>
                                                    <span>{{ $cn['name'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <div class="diver-horizontal"></div>
                                        <ul class="dropdown-menu-function-method-container">
                                            @foreach($tnModuleDs as $md)
                                                @php $anh = $tnAnhModule[$md['ma']] ?? 'cashier'; @endphp
                                                <li class="quick-link-{{ $anh }}">
                                                    <a href="{{ $md['href'] }}">
                                                        <img class="dropdown-menu-normal" src="{{ asset('v2/images/'.$anh.'_normal.png') }}" alt="{{ $anh }}_normal">
                                                        <img class="dropdown-menu-hover" src="{{ asset('v2/images/'.$anh.'_hover.png') }}" alt="{{ $anh }}_hover">
                                                        {{ $md['ten'] }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div id="btnMute" class="cursor-pointer" title="Tắt / bật âm báo">
                                <i class="fa-solid fa fa-volume-up"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @if($tnDoiCnDuoc)
                <form method="POST" action="{{ route('admin.chi-nhanh.dangLam') }}" id="tnCnForm" class="d-none">
                    @csrf
                    <input type="hidden" name="id" id="tnCnId" value="">
                </form>
            @endif
        </div>

        <div class="main-body-container">
            @if($tnQuay)
                <div class="container-fluid table-order-container overflow-x-hidden custom-container hidden-download-app">
                    <div class="row table-order-inner custom-row-table">
                        <div class="table-order custom-col-table">
                            <div class="d-flex order-layout">
                                <div class="menu-area width-menu">
                                    <div class="header-order position-relative bg-header-order align-content-center action_chose_table">
                                        <div class="d-flex align-items-end w-100 position-relative">
                                            <a type="button" class="text-center text-decoration-none menu-tab active" style="color: #2C2C34; border-top-right-radius: 18px;">
                                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12.4073 16H13.6145C14.2255 16 14.7273 15.5273 14.8 14.9309L16 2.94545H12.3636V0H10.9309V2.94545H7.31636L7.53455 4.64727C8.77818 4.98909 9.94182 5.60727 10.64 6.29091C11.6873 7.32364 12.4073 8.39273 12.4073 10.1382V16ZM0 15.2727V14.5455H10.9309V15.2727C10.9309 15.6655 10.6036 16 10.1818 16H0.727273C0.327273 16 0 15.6655 0 15.2727ZM10.9309 10.1818C10.9309 4.36364 0 4.36364 0 10.1818H10.9309ZM0 11.6364H10.9091V13.0909H0V11.6364Z" fill="#2C2C34"></path></svg>
                                                <span class="ms-2">@yield('ten-khu-trai', 'Hàng hoá')</span>
                                            </a>
                                            @yield('dau-khu-trai')
                                        </div>
                                    </div>
                                    <div class="div-handle-list-product">
                                        @yield('khu-trai')
                                    </div>
                                </div>

                                <div class="bill-area px-0 width-payment">
                                    <div class="header-order bg-header-order d-flex justify-content-start align-items-center fw-bold position-relative">
                                        @yield('dau-khu-phai')
                                    </div>
                                    <div class="tab-content overflow-y-auto">
                                        <div class="tab-pane fade show active position-relative chose_invoice_code-tab h-100" id="chose_invoice_code" role="tabpanel">
                                            @yield('khu-phai')
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <div class="tn-trang">
                    <div class="tn-tam">
                        @yield('content')
                    </div>
                </div>
            @endif
        </div>

        <div class="cus-footer" style="max-width: calc(0px + 100vw);">
            <span>© {{ date('Y') }} {{ $tnTenTiem }}@if($tnDienThoai !== '') | Hỗ trợ: <span class="fw-bold">{{ $tnDienThoai }}</span>@endif</span>
        </div>
    </div>

    {{-- Hộp thoại ở cuối <body>, như bản gốc: .table-order có `transform` nên hộp thoại
         đặt bên trong sẽ bị lớp nền mờ của Bootstrap đè lên. --}}
    @stack('modals')

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script>
        if (window.toastr) {
            toastr.options = { positionClass: 'toast-top-right', preventDuplicates: true, timeOut: 3500 };
        }
        // Tooltip của ô module, như bản gốc.
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { new bootstrap.Tooltip(el); });

        // Ô module chưa có màn: báo, không dẫn tới trang lỗi.
        document.addEventListener('click', function (e) {
            var a = e.target.closest('a[data-chua-dung]');
            if (!a) return;
            e.preventDefault();
            if (window.toastr) toastr.info('Màn "' + a.dataset.chuaDung + '" của quầy đang được xây dựng.');
        });

        // Menu tài khoản: bấm để mở, bấm ra ngoài hoặc Esc để đóng.
        (function () {
            var boc = document.getElementById('dropdown-profile');
            if (!boc) return;
            var nut = boc.querySelector('.profile'), menu = boc.querySelector('.menu');
            function datMo(mo) { menu.classList.toggle('active', mo); nut.setAttribute('aria-expanded', mo ? 'true' : 'false'); }
            nut.addEventListener('click', function (e) { e.stopPropagation(); datMo(!menu.classList.contains('active')); });
            nut.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); datMo(!menu.classList.contains('active')); } });
            document.addEventListener('click', function (e) { if (!boc.contains(e.target)) datMo(false); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') datMo(false); });
        })();

        // Chuông & loa: quầy chưa có luồng thông báo / âm báo riêng.
        document.getElementById('tnChuong').addEventListener('click', function () {
            if (window.toastr) toastr.info('Chưa có thông báo nào cho quầy.');
        });
        document.getElementById('btnMute').addEventListener('click', function () {
            var i = this.querySelector('i');
            var tat = i.classList.toggle('fa-volume-xmark');
            i.classList.toggle('fa-volume-up', !tat);
            try { localStorage.setItem('pos.tat-tieng', tat ? '1' : '0'); } catch (err) {}
        });

        // Bấm một dòng chi nhánh trong hộp ☰ = đổi kho đang làm việc rồi nạp lại.
        document.addEventListener('click', function (e) {
            var dong = e.target.closest('.dropdown-menu-branch-container li.tn-doi-cn');
            var form = document.getElementById('tnCnForm');
            if (!dong || !form || dong.classList.contains('selected')) return;
            document.getElementById('tnCnId').value = dong.dataset.value;
            form.submit();
        });
    </script>

    @stack('scripts')
</body>
</html>
