{{--
    Thanh trên cùng của khu v2 — CHÉP NGUYÊN VĂN từ mã nguồn thật của bản đang
    chạy (ordertable, layouts/header.blade.php — tệp working-copy bị 0 byte nên
    lấy từ .svn/pristine/87/877905f1…, khớp từng dòng với HTML mà
    table1.klkim.com/v2 đang trả về).

    Markup, CSS và JS giữ đúng bản gốc. Chỉ nắn phần BUỘC phải nắn vì web_Shop
    không có cùng hạ tầng, gom hết vào khối PHP dưới đây (LƯU Ý: đừng viết chữ
    a-còng-php trong comment này — Blade sẽ bắt nhầm nó làm đầu một khối PHP
    thật và nuốt trọn nửa tệp):
      - quyền hasPermission() của v2  → cờ bool tính sẵn (module chưa dựng = link '#')
      - route v2 (warehouse.index…)   → route của mình hoặc '#'
      - $branches (Eloquent)          → ChiNhanhDangLam::danhSach()
      - Auth::user()->name            → session('api.user')
      - endpoint chuông/hồ sơ/đổi mật khẩu/đổi chi nhánh → endpoint của mình
    Khối menu cũ 950 dòng (d-none toàn phần, "đã chuyển sang menu button mới")
    không chép — nó tàng hình trên bản chạy thật.
--}}
@php
    $u = session('api.user');
    $tenDangNhap = trim((string) data_get($u, 'full_name', '')) ?: (string) data_get($u, 'username', 'Admin');
    $cnDangLam = \App\Services\ChiNhanhDangLam::danhSach();

    $tenCuaHang = app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name'));

    /*
       Ảnh của thanh header nhúng thẳng vào HTML dạng data URI.

       Để là tệp ảnh rời thì mỗi lần chuyển trang là mỗi ảnh một lượt gọi mạng,
       mà máy chủ dev không gửi header cache nên lần nào cũng tải lại thật — ô
       module vẽ trống trước, icon nhảy vào sau, thành cái nháy. Khu quản trị cũ
       không dính vì icon của nó là SVG nằm ngay trong HTML.

       Nhúng vào thì ảnh tới cùng HTML, vẽ một nhịp là xong, không phụ thuộc
       header cache của máy chủ. Cả bộ (7 icon đã nén + cờ + logo) ~23KB.

       Đọc đĩa một lần rồi cất cache; khoá cache mang mtime mới nhất nên thay
       ảnh là tự lấy bản mới, không phải xoá cache tay.
    */
    $anhHeader = [
        'thong-ke' => public_path('images/modules/thong-ke.png'),
        'menu' => public_path('images/modules/menu.png'),
        'kho' => public_path('images/modules/kho.png'),
        'thu-chi' => public_path('images/modules/thu-chi.png'),
        'nhan-su' => public_path('images/modules/nhan-su.png'),
        'crm' => public_path('images/modules/crm.png'),
        'cai-dat' => public_path('images/modules/cai-dat.png'),
        'co-vi' => public_path('v2/images/vi.png'),
        'logo' => public_path('images/sellio-logo-full.svg'),
    ];

    $moiNhat = 0;
    foreach ($anhHeader as $duong) {
        if (is_file($duong)) {
            $moiNhat = max($moiNhat, filemtime($duong));
        }
    }

    $boAnh = \Illuminate\Support\Facades\Cache::remember(
        'v2.anh-header.'.$moiNhat,
        86400,
        function () use ($anhHeader) {
            $ds = [];
            foreach ($anhHeader as $ten => $duong) {
                if (! is_file($duong)) {
                    $ds[$ten] = '';

                    continue;
                }
                $kieu = str_ends_with($duong, '.svg') ? 'image/svg+xml' : 'image/png';
                $ds[$ten] = 'data:'.$kieu.';base64,'.base64_encode(file_get_contents($duong));
            }

            return $ds;
        }
    );

    // Thiếu tệp thì vẫn trỏ đường dẫn thường, đừng để trống thẻ img.
    $icon = fn (string $ten) => $boAnh[$ten] ?: asset("images/modules/{$ten}.png");

    // Cửa hàng tải logo riêng (ảnh nằm ở máy chủ ảnh) thì dùng đường dẫn đó;
    // chưa tải thì lấy logo Sellio đã nhúng sẵn.
    $logoRieng = app(\App\Services\ApiClient::class)->settingString('store_logo');
    $logoCuaHang = $logoRieng !== ''
        ? $logoRieng
        : ($boAnh['logo'] ?: asset('images/sellio-logo-full.svg'));

    /*
       Bày đủ bảy ô module như bản v2, kể cả màn chưa dựng — ô vẫn đứng đó, chỉ
       là link về '#'.

       DỰNG XONG MỘT MÀN THÌ LÀM HAI VIỆC:
         1. thêm tên route vào ChiHienGiaoDienV2::DA_CO_V2
         2. điền route thật vào biến đường dẫn ngay bên dưới (đang để '#')
    */
    $ulDashboardPer = true;
    $ulMenuPer = true;
    $ulWarehousePer = true;
    $ulCashbookPer = true;
    $ulEmployeePer = true;
    $ulCrmPer = true;
    $ulTaxDeclarationPer = false; // v2 cũng giấu khi không khai thuế trực tiếp
    $ulSettingsPer = true;

    // Section đang mở — bản gốc dò bằng is_menu_active(đường v2); mình dò theo
    // đường của web_Shop.
    $isStatisticSection = request()->is('admin/dashboard');
    $isMenuSection = request()->is('admin/products*', 'admin/categories*', 'admin/taxes*', 'admin/units*', 'admin/attributes*');
    $isWarehouseSection = request()->is('admin/suppliers*', 'admin/inventory-adjustments*', 'admin/purchase-orders*', 'admin/supplier-returns*', 'admin/stock-transfers*', 'admin/inventory*');
    $isCashbookSection = request()->is('admin/cashbook*');
    $isHumanSection = request()->is('admin/staff*');
    $isCrmSection = request()->is('admin/customers*');
    $isSettingSection = request()->is('admin/settings*', 'admin/branches*');

    // Đường vào từng module. Bật cờ ở trên rồi thì thay '#' bằng route thật.
    $statisticDefaultRoute = '#';
    $routeUlMenu = route('admin.products.index');
    $routeUlWarehouse = route('admin.nha-cung-cap.index');
    $routeUlCashbook = '#';
    $routeUlEmployee = '#';
    $routeUlCrm = '#';
    $routeUlSettings = route('admin.chi-nhanh.index');

    // Tab trong module CÀI ĐẶT — cùng luật: màn nào chưa dựng thì giấu.
    $branchPer = true;                  // Quản lý chi nhánh — ĐÃ CÓ

    /*
       Tab trong module KHO — CHỈ bày màn đã dựng.

       Khác dãy ô module ở trên: ô module chưa dựng thì đứng yên (href='#'), còn
       tab ở đây đều trỏ route thật, bấm vào là bị ChiHienGiaoDienV2 đá về Nhà
       cung cấp — trông như bấm nhầm. Nên màn nào chưa dựng thì giấu tab luôn.

       Dựng xong màn nào: thêm route vào DA_CO_V2 rồi bật cờ tương ứng ở đây.

    */
    // Tab trong module HÀNG HÓA — cùng luật: màn nào chưa dựng thì giấu.
    $categoryPer = true;                // Nhóm hàng hoá — ĐÃ CÓ
    $productPer = true;                 // Hàng hoá — ĐÃ CÓ
    $attributePer = true;               // Thuộc tính — ĐÃ CÓ
    $unitPer = true;                    // Đơn vị tính — ĐÃ CÓ
    $taxPer = true;                     // Quản lý thuế — ĐÃ CÓ

    $warehousePer = true;               // Tồn kho chi nhánh hiện tại — ĐÃ CÓ
    $supplierPer = true;                // Nhà cung cấp — ĐÃ CÓ
    $purchaseOrderPer = true;           // Phiếu mua hàng — ĐÃ CÓ
    $supplierReturnPer = true;          // Trả hàng nhà cung cấp — ĐÃ CÓ
    $transferSlipPer = true;            // Phiếu điều chuyển — ĐÃ CÓ
    $initialInventoryPer = false;       // Nhập tồn kho ban đầu — chưa dựng
    $warehouseImportPer = false;        // Phiếu nhập kho — bản gốc cũng để d-none
    $warehouseExportPer = false;        // Phiếu xuất kho — bản gốc cũng để d-none
    $warehouseAdjustPer = true;         // Điều chỉnh tồn kho — ĐÃ CÓ
    $importExportInventoryPer = false;  // Báo cáo kho — chưa dựng

    // Nút nhanh trong dropdown ba gạch — mình có hai module: Quản lý & Thu ngân.
    $dashboardPer = true;
    $mdswDs = \App\Services\HanSuDung::daKhoa() ? [] : \App\Services\ModuleLamViec::danhSach();
    $cashierMuc = collect($mdswDs)->firstWhere('ma', \App\Services\ModuleLamViec::THU_NGAN);
    $cashierPer = $cashierMuc !== null; // người không có cửa Thu ngân thì giấu nút, như v2 giấu theo quyền
    $routeCashier = $cashierMuc['href'] ?? '#';
@endphp

<div class="container-fluid header_bg main-head-container">
    <div class="header">
        <div class="container">
            <div class="header_content">
                <div class="d-flex">
                    <div id="showMenu" class="border rounded bg-white p-2 cursor-pointer me-3" style="height: 32px;">
                        <i class="fa-solid fa-bars"></i>
                    </div>
                    {{-- Logo lấy từ Cài đặt → Cấu hình cửa hàng, dùng chung với
                         website và thanh trái khu cũ. Chưa tải ảnh thì về logo
                         Sellio bản chữ sáng — thanh này nền đen, logo chữ tối
                         tải lên sẽ chìm. --}}
                    <div class="logo">
                        <a href="{{ route('admin.nha-cung-cap.index') }}"><img alt="{{ $tenCuaHang }}"
                                src="{{ $logoCuaHang }}"></a>
                    </div>
                </div>

                <div class="main-menu-container mx-auto">
                    <div class="main-menu-inner d-flex position-relative">
                        {{-- 1. THỐNG KÊ --}}
                        @if($ulDashboardPer)
                        <div class="sidebar-item icon-item me-xl-2 {{ $isStatisticSection ? 'active' : '' }}">
                            <a href="{{ $statisticDefaultRoute }}">
                                <img src="{{ $icon('thong-ke') }}"
                                    alt="{{ __('message.statistic') }}">
                                <p class="text-detail text-uppercase">{{ __('message.statistic') }}</p>
                            </a>
                        </div>
                        @endif

                        {{-- 2. MENU --}}
                        @if($ulMenuPer)
                            <div class="sidebar-item icon-item me-xl-2 {{ $isMenuSection ? 'active' : '' }}">
                                <a href="{{ $routeUlMenu }}">
                                    <img src="{{ $icon('menu') }}" alt="{{ __('message.goods') }}">
                                    <p class="text-detail text-uppercase">{{ __('message.goods') }}</p>
                                </a>
                            </div>
                        @endif

                        {{-- 3. KHO --}}
                        @if($ulWarehousePer)
                            <div class="sidebar-item icon-item me-xl-2 {{ $isWarehouseSection ? 'active' : '' }}">
                                <a href="{{ $routeUlWarehouse }}">
                                    <img src="{{ $icon('kho') }}"
                                        alt="{{ __('message.warehouse') }}">
                                    <p class="text-detail text-uppercase">{{ __('message.warehouse') }}</p>
                                </a>
                            </div>
                        @endif

                        {{-- 4. THU CHI --}}
                        @if($ulCashbookPer)
                            <div class="sidebar-item icon-item me-xl-2 {{ $isCashbookSection ? 'active' : '' }}">
                                <a href="{{ $routeUlCashbook }}">
                                    <img src="{{ $icon('thu-chi') }}"
                                        alt="{{ __('message.cashbook') }}">
                                    <p class="text-detail text-uppercase">{{ __('message.cashbook') }}</p>
                                </a>
                            </div>
                        @endif

                        @if($ulEmployeePer)
                        {{-- 5. NHÂN SỰ --}}
                        <div class="sidebar-item icon-item me-xl-2 {{ $isHumanSection ? 'active' : '' }}">
                            <a href="{{ $routeUlEmployee }}">
                                <img src="{{ $icon('nhan-su') }}" alt="{{ __('message.personnel') }}">
                                <p class="text-detail text-uppercase">{{ __('message.personnel') }}</p>
                            </a>
                        </div>
                        @endif


                        {{-- 6 CRM --}}
                        @if($ulCrmPer)
                            <div class="sidebar-item icon-item me-xl-2 {{ $isCrmSection ? 'active' : '' }}">
                                <a href="{{ $routeUlCrm }}">
                                    <img src="{{ $icon('crm') }}" alt="CRM">
                                    <p class="text-detail text-uppercase">CRM</p>
                                </a>
                            </div>
                        @endif

                        {{-- 8. CÀI ĐẶT --}}
                        @if($ulSettingsPer)
                        <div class="sidebar-item icon-item {{ $isSettingSection ? 'active' : '' }}">
                            <a href="{{ $routeUlSettings }}">
                                <img src="{{ $icon('cai-dat') }}" alt="{{ __('message.setting') }}">
                                <p class="text-detail text-uppercase">{{ __('message.setting') }}</p>
                            </a>
                        </div>
                        @endif

                        <button id="closeMenu" title="{{ __('message.close') }}"
                            class="border-0 position-absolute p-1 top-0 end-0">
                            <i class="fa-solid fa-xmark text-white fs-3"></i>
                        </button>
                    </div>
                </div>

                <div class="header_menu">
                    <div class="btn-dropdown-container-wapper">
                        <div class="dropdown">
                            {{-- TÊN CHI NHÁNH ĐANG LÀM VIỆC, hiện ngay trên nút.
                                 Trước đây nút này chỉ có ba gạch, nên muốn biết mình
                                 đang đứng ở kho nào phải bấm ra xem — mà con số tồn,
                                 doanh thu và mọi chứng từ vừa lập đều đổi nghĩa theo
                                 nó. Cửa hàng một chi nhánh thì giấu đi cho gọn. --}}
                            <button class="btn btn-dropdown-container dropdown-toggle d-flex align-items-center gap-2"
                                data-bs-toggle="dropdown"
                                title="{{ $cnDangLam['dangChon'] ? 'Chi nhánh đang làm việc — bấm để đổi' : '' }}">
                                <i class="fa fa-bars"></i>
                                @if(count($cnDangLam['ds']) > 1 && $cnDangLam['dangChon'])
                                    <span class="ten-chi-nhanh-dang-lam d-none d-md-inline">
                                        {{ \App\Services\ChiNhanhDangLam::ten() }}
                                    </span>
                                @endif
                            </button>
                            <div class="dropdown-menu z-index-10000">
                                <ul class="dropdown-menu-branch-container">
                                    @foreach ($cnDangLam['ds'] as $branch)
                                        <li data-value="{{ $branch['id'] }}"
                                            class="{{ $cnDangLam['dangChon'] === (int) $branch['id'] ? 'selected' : '' }}">
                                            <span>{{ $branch['name'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="diver-horizontal"></div>
                                <ul class="dropdown-menu-function-method-container">
                                    @if($dashboardPer)
                                        <li class="quick-link-admin">
                                            <a href="{{ route('admin.nha-cung-cap.index') }}">
                                                <img class="dropdown-menu-normal"
                                                    src="{{ asset('v2/images/admin_normal.png') }}" alt="admin_normal" />
                                                <img class="dropdown-menu-hover" src="{{ asset('v2/images/admin_hover.png') }}"
                                                    alt="admin_hover" />
                                                {{ __('message.manager') }}
                                            </a>
                                        </li>
                                    @endif

                                    @if($cashierPer)
                                        <li class="quick-link-cashier">
                                            <a href="{{ $routeCashier }}">
                                                <img class="dropdown-menu-normal"
                                                    src="{{ asset('v2/images/cashier_normal.png') }}" alt="cashier_normal" />
                                                <img class="dropdown-menu-hover"
                                                    src="{{ asset('v2/images/cashier_hover.png') }}" alt="cashier_hover" />
                                                {{ __('message.cashier') }}
                                            </a>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="menu_report">
                        <button type="button" onclick="myFunction(event)" class="dropbtn bell">
                            <span id="notificationCount" class="number_report">0</span>
                        </button>
                        <div id="myDropdown" class="dropdown-content">
                            <div class="header_report_content">
                                <div class="header_report_title">
                                    <h5>{{ __('message.notification') }}</h5>
                                </div>
                                <div class="header_report_midd">
                                    <div class="list_report scroll-container position-relative" id="notificationList">
                                    </div>
                                    <div class="tab-content d-flex justify-content-around" id="form_btn">
                                        <button class="border-0 d-flex m-auto btn_unread">
                                            <i class="fa fa-envelope" aria-hidden="true"></i>
                                            <p class="my-auto mx-1">{{ __('message.unread') }}</p>
                                        </button>
                                        <button class="border-0 d-flex m-auto btn_check_read_all">
                                            <i class="fa fa-envelope-open" aria-hidden="true"></i>
                                            <p class="my-auto mx-1">{{ __('message.mark_all_as_read') }}</p>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="menu_user">
                        <div class="action" id="dropdown-profile">
                            <div class="profile d-flex">
                                <span class="text-capitalize m-auto">{{ $tenDangNhap }}</span>
                                <i class="fa fa-user-circle"></i>
                            </div>
                            <div class="menu menu-wrapper-container">
                                <ul class="row">
                                    <li class="col-12 col-md-6 view-profile">
                                        <i class="far fa-user-circle"></i>
                                        <a>{{ __('message.my-profile') }}</a>
                                    </li>

                                    <li class="col-12 col-md-6 edit-password">
                                        <i class="fa-solid fa-unlock"></i>
                                        <a>{{ __('message.change_password') }}</a>
                                    </li>

                                    <li class="col-12 col-md-6 view-package">
                                        <i class="fa-solid fa-cube"></i>
                                        <a href="{{ route('admin.goi-dich-vu.index') }}">{{__('message.service_package')}} </a>
                                    </li>

                                    <li class="col-12 col-md-6" onclick="confirmLogout(event)">
                                        <i class="fas fa-sign-out-alt"></i>
                                        <a>{{ __('message.logout') }}</a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="menu_language">
                        @php
                            // Khu quản trị mới chỉ có tiếng Việt — nút cờ đứng đó như v2,
                            // dropdown rỗng vì không còn ngôn ngữ nào khác để đổi.
                            $locale = 'vi';
                            $locales = ['vi'];
                        @endphp

                        <div class="dropup dropup-language">
                            <button class="dropbtn" id="languageBtn">
                                <img src="{{ $boAnh['co-vi'] ?: asset('v2/images/' . $locale . '.png') }}" alt="{{ $locale }}">
                            </button>
                            <input type="hidden" name="lang" id="lang" value="{{ $locale }}">
                            <div class="dropup-content" id="dropdown-language" style="display: none">
                                <ul>
                                    @foreach ($locales as $item)
                                        @if ($item != $locale)
                                            <li class="list_locale ">
                                                <a href="#">
                                                    <img src="{{ asset('v2/images/' . $item . '.png') }}" alt="{{ $item }}">
                                                </a>
                                            </li>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="header-menu-for-mobile">
    {{-- Đổ dữ liệu tại đây --}}
    <nav class="footer-menu-container">
        <div data-bs-toggle="collapse" data-bs-target="#collapseMenuForMobile" aria-controls="collapseMenuForMobile"
            aria-expanded="false" aria-label="Toggle navigation" class="navbar-menu-collapse footer-menu-hamberger">
            <div class="hamburger" id="hamburger-1">
                <span class="line"></span>
                <span class="line"></span>
                <span class="line"></span>
            </div>
            <p class="menu-item-href-name"> {{ __('message.more') }} </p>
        </div>
    </nav>
    <div class="collapse navbar-collapse" id="headerMenuForMobile">
    </div>
</div>

<div class="container-fluid main-breadcrumb-container">
    <div class="menu_center">
        <div class="container">
            <div class="sub-nav-wrapper">
                <div class="sub-nav-inner">
                    {{-- 2. HÀNG HÓA --}}
                    @if($ulMenuPer && $isMenuSection)
                        @if($productPer)
                            <a href="{{ route('admin.products.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.products.*') ? 'active' : '' }}">
                                {{ __('message.goods') }}
                            </a>
                        @endif

                        @if($categoryPer)
                            <a href="{{ route('admin.categories.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">
                                Nhóm hàng hoá
                            </a>
                        @endif

                        @if($attributePer)
                            <a href="{{ route('admin.thuoc-tinh.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.thuoc-tinh.*') ? 'active' : '' }}">
                                {{ __('message.attribute') }}
                            </a>
                        @endif

                        @if($unitPer)
                            <a href="{{ route('admin.don-vi-tinh.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.don-vi-tinh.*') ? 'active' : '' }}">
                                {{ __('message.unit') }}
                            </a>
                        @endif

                        @if($taxPer)
                            <a href="{{ route('admin.thue.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.thue.*') ? 'active' : '' }}">
                                {{ __('message.manage-tax') }}
                            </a>
                        @endif
                    @endif

                    {{-- 3. KHO --}}
                    @if($ulWarehousePer && $isWarehouseSection)
                        @if($warehousePer)
                            <a href="{{ route('admin.ton-kho-chi-nhanh.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.ton-kho-chi-nhanh.*') ? 'active' : '' }}">
                                {{ __('message.inventorys') }}
                            </a>
                        @endif

                        @if($supplierPer)
                            <a href="{{ route('admin.nha-cung-cap.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.nha-cung-cap.*') ? 'active' : '' }}">
                                {{ __('message.supplier') }}
                            </a>
                        @endif

                        @if($purchaseOrderPer)
                            <a href="{{ route('admin.phieu-mua-hang.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.phieu-mua-hang.*') ? 'active' : '' }}">
                                {{ __('message.purchase_order') }}
                            </a>
                        @endif

                        @if($supplierReturnPer)
                            <a href="{{ route('admin.tra-hang-nha-cung-cap.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.tra-hang-nha-cung-cap.*') ? 'active' : '' }}">
                                {{ __('message.return-order') }} {{ strtolower(__('message.supplier')) }}
                            </a>
                        @endif

                        @if($transferSlipPer)
                            <a href="{{ route('admin.phieu-dieu-chuyen.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.phieu-dieu-chuyen.*') ? 'active' : '' }}">
                                {{ __('message.transfer_slip') }}
                            </a>
                        @endif


                        @if($initialInventoryPer)

                             <a class="sub-nav-btn"
                                href="#">{{__('message.initial_inventory_entry') }}
                            </a>

                        @endif

                        @if($warehouseImportPer)
                            <a href="#"
                                class="sub-nav-btn d-none">
                                {{ __('message.stock_in_note') }}
                            </a>
                        @endif

                        @if($warehouseExportPer)
                            <a href="#"
                                class="sub-nav-btn d-none">
                                {{ __('message.stock_out_note') }}
                            </a>
                        @endif
                        @if($warehouseAdjustPer)
                            <a href="{{ route('admin.dieu-chinh-ton-kho.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.dieu-chinh-ton-kho.*') ? 'active' : '' }}">
                                {{ __('message.warehouse_adjustment') }}
                            </a>
                        @endif
                        @if($importExportInventoryPer)
                            <a href="#"
                                class="sub-nav-btn">
                                {{ __('message.warehouse_report') }}
                            </a>
                        @endif
                    @endif

                    {{-- 8. CÀI ĐẶT --}}
                    @if($ulSettingsPer && $isSettingSection)
                        @if($branchPer)
                            <a href="{{ route('admin.chi-nhanh.index') }}"
                                class="sub-nav-btn {{ request()->routeIs('admin.chi-nhanh.index') ? 'active' : '' }}">
                                {{ \App\Http\Controllers\ChiNhanhController::TITLE }}
                            </a>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="menu_child d-none">
        <div class="container">
            <div class="menu_child_content">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="{{ route('admin.nha-cung-cap.index') }}">
                                <i class="fa fa-home"></i>
                            </a>
                        </li>
                        @if (isset($arrTitle) && is_array($arrTitle))
                            @foreach ($arrTitle as $title)
                                <li class="breadcrumb-item {{ $loop->last ? 'active' : '' }}">
                                    @if ($title['route'] != '')
                                        <a href="{{ route($title['route']) }}">{{ $title['name'] }}</a>
                                    @else
                                        {{ $title['name'] }}
                                    @endif
                                </li>
                            @endforeach
                        @endif
                    </ol>
                </nav>
                <div class="menu_child_right">
                    @yield('menu_child_right')
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="confirmLogout">
    <div class="modal-dialog modal-dialog-centered ">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">{{ __('message.logout') }} ?</h6>
                <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
            </div>
            <!-- Modal body -->
            <div class="modal-body">
                <input type="hidden" id="deleteValue">
                <div class="modal_center">
                    <div class="row">
                        <div class="col">
                            <label class="form-label">{{ __('message.confirm-logout') }}</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal footer -->
            <div class="modal-footer">
                <button type="button" class="bt btn_gray denied-delete"
                    data-bs-dismiss="modal">{{ __('message.close') }}</button>
                <button type="button" class="bt btn_red btn_logout">{{ __('message.logout') }}</button>
            </div>

        </div>
    </div>
</div>

<div class="modal" id="modalProfile">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">{{ __('message.my-profile') }}</h4>
                <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="container">
                    <div class="row mt-3">
                        <div class="col-3"><label class="form-label">{{ __('message.full_name') }}</label></div>
                        <div class="col-3 full_name"></div>
                        <div class="col-3"><label class="form-label">{{ __('message.position') }}</label></div>
                        <div class="col-3 position"></div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-3"><label class="form-label">{{ __('message.email') }}</label></div>
                        <div class="col-3 email"></div>
                        <div class="col-3"><label class="form-label">{{ __('message.phone-number') }}</label></div>
                        <div class="col-3 phone-number"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="bt btn_gray" data-bs-dismiss="modal">{{ __('message.close') }}</button>
            </div>

        </div>
    </div>
</div>

<div class="modal" id="modalEditPassword">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">{{ __('message.change_password') }}</h4>
                <button type="button" class="btn-close denied-delete" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="container">
                    <div class="row mt-3">
                        <div class="col-4">
                            <label class="form-label">{{ __('message.old_password') }}<span
                                    class="required">*</span></label>
                        </div>
                        <div class="col-8">
                            <input type="password" value="" name="old-password" class="form-control" autocomplete="off">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-4">
                            <label class="form-label">{{ __('message.new_password') }}<span
                                    class="required">*</span></label>
                        </div>
                        <div class="col-8">
                            <input type="password" value="" name="new-password" class="form-control" autocomplete="off">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-4">
                            <label class="form-label">{{ __('message.re_enter_password') }}<span
                                    class="required">*</span></label>
                        </div>
                        <div class="col-8">
                            <input type="password" value="" name="re-enter-password" class="form-control"
                                autocomplete="off">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="bt btn_gray" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                <button type="button" class="bt btn_green update-password">{{ __('message.save') }}</button>
            </div>

        </div>
    </div>
</div>

<form id="v2LogoutForm" method="POST" action="{{ route('logout') }}" class="d-none">@csrf</form>

{{-- JS của header đẩy xuống cuối <body> qua @push: jQuery giờ nạp ở dưới đó,
     và đoạn này cũng không việc gì phải chạy trước lúc trang vẽ xong. --}}
@push('scripts')
<script type="text/javascript">
    // ===== Chuông thông báo — khuôn hàm của v2, gọi endpoint của mình =====
    @php
        // Đọc lại từ session thay vì $u: khối <script> nằm sau nhiều @section
        // lồng nhau, biến cục bộ phía trên không chắc còn trong tầm.
        $v2U = session('api.user');
        $v2HoSo = [
            'full_name' => (string) data_get($v2U, 'full_name', ''),
            'username' => (string) data_get($v2U, 'username', ''),
            'email' => (string) data_get($v2U, 'email', ''),
            'phone' => (string) data_get($v2U, 'phone', ''),
            // `role` của API là cả một object Role, không phải chuỗi — lấy tên
            // hiển thị bên trong. Ép thẳng object sang chuỗi là vỡ nguyên trang.
            //
            // CHỦ CỬA HÀNG gọi là "Admin master", đúng chữ của bản v2 (`is_master`),
            // chứ không phải "Quản trị viên": mỗi cửa hàng có một tài khoản
            // super_admin đi tắt qua mọi phép kiểm quyền, còn "quản trị viên" là
            // vai trò `admin` mà chủ tiệm cấp cho người khác — hai thứ khác nhau,
            // gọi chung một tên thì nhìn màn hình không biết mình đang là ai.
            //
            // Đè ở đây chứ không sửa `display_name` dưới database: tên hiển thị của
            // vai trò do từng cửa hàng tự đặt (bảng role_labels), sửa là giẫm lên
            // chữ họ đã chọn.
            'role' => (string) (data_get($v2U, 'role.name') === 'super_admin'
                ? 'Admin master'
                : (data_get($v2U, 'role.display_name') ?: data_get($v2U, 'role.name', ''))),
        ];
    @endphp
    const V2_HO_SO = @json($v2HoSo);
    let notiChiChuaDoc = false;

    function veMotThongBao(n) {
        const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
            { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
        ));
        const luc = n.created_at ? new Date(String(n.created_at)).toLocaleString('vi-VN') : '';
        const mau = n.is_read ? '#d9d9d9' : '#1890ff';
        return `<div class="notification">
            <div class="status" style="background:${mau}"></div>
            <div class="content-noti ${n.is_read ? '' : 'content-unread'}" data-id="${n.id}">
                <p><b>${esc(n.title || '')}</b></p>
                <p>${esc(n.content || '')}</p>
                <p class="time">${esc(luc)}</p>
            </div>
        </div>`;
    }

    function loadAdminNotifications() {
        $.ajax({
            url: "{{ route('admin.notifications.index') }}",
            type: 'GET',
            dataType: 'json',
            beforeSend: function() {
                $('#notificationList').html('<div class="loader-content m-auto"></div>');
            },
            success: function(response) {
                let items = (response && response.items) || [];
                if (notiChiChuaDoc) items = items.filter((n) => !n.is_read);

                $('#notificationList').html(
                    items.length ? items.map(veMotThongBao).join('')
                                 : '<p class="text-center text-muted my-3">{{ __('message.no_notifications_yet') }}</p>'
                );

                const soChuaDoc = (response && response.unread_count) || 0;
                $('#notificationCount').text(soChuaDoc);
                if (soChuaDoc > 0) {
                    $('#notificationCount').show();
                } else {
                    $('#notificationCount').hide();
                }
            },
            error: function() {
                $('#notificationList').html('');
            }
        });
    }
    loadAdminNotifications();

    function myFunction(e) {
        if (e) {
            e.stopPropagation();
        }
        $('#myDropdown').toggleClass('show');
        if($('#myDropdown').hasClass('show')) {
            loadAdminNotifications();
        }
    }

    // Prevent dropdown from closing when clicking inside it
    $(document).on('click', '#myDropdown', function(e) {
        e.stopPropagation();
    });

    $(document).on('click', '.content-unread', function() {
        var id = $(this).data('id');
        if(id) {
            $.ajax({
                url: "{{ url('/admin/notifications') }}/" + id + "/read",
                type: 'POST',
                data: { _token: $('meta[name="csrf-token"]').attr('content') },
                success: function() {
                    loadAdminNotifications();
                }
            });
        }
    });

    $(document).on('click', '#form_btn .btn_unread', function() {
        notiChiChuaDoc = !notiChiChuaDoc;
        loadAdminNotifications();
    });

    $(document).on('click', '#form_btn .btn_check_read_all', function() {
        $.ajax({
            url: "{{ route('admin.notifications.readAll') }}",
            type: 'POST',
            data: { _token: $('meta[name="csrf-token"]').attr('content') },
            success: function() {
                loadAdminNotifications();
            }
        });
    });

    $(document).click(function(event) {
        var $target = $(event.target);
        if(!$target.closest('.menu_report, #myDropdown').length && $('#myDropdown').hasClass('show')) {
            $('#myDropdown').removeClass('show');
        }
    });

    // ===== Đăng xuất =====
    function confirmLogout(event) {
        $('#confirmLogout').modal('show');
    }

    $('.btn_logout').on('click', function () {
        // Đăng xuất của web_Shop là POST — gửi form ẩn thay vì đổi location.
        document.getElementById('v2LogoutForm').submit();
    });

    $('.menu-item-collapse-container').each(function () {
        if ($(this).is(':empty')) {
            $(this).remove();
        }
    });

    document.addEventListener("DOMContentLoaded", function () {

        // Giữ menu KHÔNG tự đóng khi bấm vào bên trong — nhưng CHỈ với menu tự
        // khai `data-giu-mo`, không phải mọi .dropdown-menu của trang.
        //
        // Bản chép từ mẫu quét hết mọi .dropdown-menu, và stopPropagation ở đó
        // cắt luôn đường về của mọi handler uỷ nhiệm `$(document).on('click',
        // '.abc')` — kiểu gắn mà cả khu v2 đang dùng. Hậu quả: mục nào trong
        // menu chạy bằng JS thì bấm không ra gì (mục "Nhập file" của màn Hàng
        // hoá đã nằm im như thế), còn mục nào là <a href> thì vẫn chạy vì nó
        // không cần bubble — nên lỗi trông như "chỉ một mục hỏng".
        document.querySelectorAll('.dropdown-menu[data-giu-mo]').forEach(function (element) {
            element.addEventListener('click', function (e) {
                e.stopPropagation();
            });
        })

        // make it as accordion for smaller screens
        if (window.innerWidth < 992) {

            // close all inner dropdowns when parent is closed
            document.querySelectorAll('.navbar .dropdown').forEach(function (everydropdown) {
                everydropdown.addEventListener('hidden.bs.dropdown', function () {
                    // after dropdown is hidden, then find all submenus
                    this.querySelectorAll('.submenu').forEach(function (everysubmenu) {
                        // hide every submenu as well
                        everysubmenu.style.display = 'none';
                    });
                })
            });

            document.querySelectorAll('.dropdown-menu a').forEach(function (element) {
                element.addEventListener('click', function (e) {

                    let nextEl = this.nextElementSibling;
                    if (nextEl && nextEl.classList.contains('submenu')) {
                        // prevent opening link if link needs to open dropdown
                        e.preventDefault();
                        if (nextEl.style.display == 'block') {
                            nextEl.style.display = 'none';
                        } else {
                            nextEl.style.display = 'block';
                        }

                    }
                });
            })
        }
        // end if innerWidth

    });

    // ===== Đổi chi nhánh đang làm việc =====
    //
    // Đổi ở HAI chỗ, và hai chỗ ấy phục vụ hai việc khác nhau:
    //   - sessionStorage của TAB: quyết định tab này từ giờ đứng ở kho nào, và
    //     nó không kéo theo tab nào khác — đó là toàn bộ điểm của khối
    //     "CHI NHÁNH THEO TAB" bên layout;
    //   - phiên trên máy chủ: giá trị MẶC ĐỊNH cho tab mở SAU, để người dùng
    //     không phải chọn lại mỗi lần mở thêm cửa sổ.
    $('.dropdown-menu-branch-container li').on('click', function () {
        let branch_id = $(this).attr("data-value");
        if (!branch_id) return;

        // Ghi cho tab TRƯỚC khi gửi: controller `dangLam` trả về chính trang
        // đang mở, và lúc trang ấy vẽ lại thì khối bên layout đã phải đọc được
        // con số mới — không thì nó thấy lệch và nạp lại thêm một lượt nữa.
        if (window.V2 && V2.chiNhanhTab !== undefined) {
            try { sessionStorage.setItem('v2_chi_nhanh_tab', String(branch_id)); } catch (e) {}
            V2.chiNhanhTab = parseInt(branch_id, 10) || 0;
        }

        const f = document.createElement('form');
        f.method = 'POST';
        f.action = "{{ route('admin.chi-nhanh.dangLam') }}";
        f.style.display = 'none';
        f.innerHTML = '<input type="hidden" name="_token" value="{{ csrf_token() }}">'
            + '<input type="hidden" name="id" value="' + branch_id + '">';
        document.body.appendChild(f);
        f.submit();
    })

    // ===== Hồ sơ của tôi — đọc từ phiên, không cần gọi thêm =====
    $('.view-profile').click(function (e) {
        $('#modalProfile .full_name').text(V2_HO_SO.full_name || V2_HO_SO.username);
        $('#modalProfile .position').text(V2_HO_SO.role || '');
        $('#modalProfile .email').text(V2_HO_SO.email || '');
        $('#modalProfile .phone-number').text(V2_HO_SO.phone || '');
        $('#modalProfile').modal('show')
    })

    $('.edit-password').click(function (e) {
        $('#modalEditPassword input').val('')
        $('#modalEditPassword').modal('show')
    })

    $('.update-password').click(function () {
        // Đường đổi mật khẩu của web_Shop nhận form thường (PUT) rồi quay lại
        // trang kèm toast — gửi form ẩn với đúng tên trường của nó.
        const cu = $('#modalEditPassword input[name="old-password"]').val();
        const moi = $('#modalEditPassword input[name="new-password"]').val();
        const nhacLai = $('#modalEditPassword input[name="re-enter-password"]').val();

        const f = document.createElement('form');
        f.method = 'POST';
        f.action = "{{ route('admin.profile.password') }}";
        f.style.display = 'none';
        const add = (n, v) => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = n; i.value = v;
            f.appendChild(i);
        };
        add('_token', '{{ csrf_token() }}');
        add('_method', 'PUT');
        add('current_password', cu);
        add('new_password', moi);
        add('new_password_confirmation', nhacLai);
        document.body.appendChild(f);
        f.submit();
    });

    $('.view-package').click(function () {
        let url = $(this).find('a').attr('href');
        window.location.href = url
    })

    // Quick links Quản lý / Thu ngân trong dropdown ba gạch
    $('.quick-link-admin').click(function () {
        let url = $(this).find('a').attr('href');
        if (url) {
            window.location.href = url;
        }
    });

    $('.quick-link-cashier').click(function () {
        let url = $(this).find('a').attr('href');
        if (url) {
            window.location.href = url;
        }
    });

    $(document).ready(function () {
        // Mở / đóng sidebar
        const showMenu = document.getElementById("showMenu");
        const menu = document.querySelector(".main-menu-container");

        showMenu.addEventListener("click", function () {
            menu.classList.toggle("active");
        });

        // Click ra ngoài thì đóng menu
        document.addEventListener("click", function (e) {
            if (
                window.innerWidth <= 1200 &&
                !menu.contains(e.target) &&
                !showMenu.contains(e.target)
            ) {
                menu.classList.remove("active");
            }
        });

        // Đóng sidebar khi bấm nút Close
        $('#closeMenu').on('click', function (e) {
            e.stopPropagation();
            $('.main-menu-container').removeClass('active');
        });

        // Click ra ngoài sidebar thì đóng
        $(document).on('click', function (e) {
            if (
                $(window).width() <= 1200 &&
                !$(e.target).closest('.main-menu-container').length &&
                !$(e.target).closest('#showMenu').length
            ) {
                $('.main-menu-container').removeClass('active');
            }
        });

        // Let Bootstrap handle dropdown toggling.
        $(document).on('click', function (e) {
            if (!$(e.target).closest('.dropdown').length) {
                $('.btn-dropdown-container').each(function () {
                    let dropdown = bootstrap.Dropdown.getInstance(this);
                    if (dropdown) dropdown.hide();
                });
            }
        });

    });
</script>
@endpush
