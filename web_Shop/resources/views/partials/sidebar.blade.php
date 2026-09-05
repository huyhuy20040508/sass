{{--
    Sidebar port 1:1 từ sidebar.tsx (Next.js/Tailwind) sang Blade + CSS thuần.
    - Thu gọn / mở rộng, ghi nhớ trạng thái bằng localStorage.
    - Dropdown accordion (chỉ mở 1 menu), nhóm "Sắp ra mắt", active state.
    - Icon inline SVG (line style) đúng như bản gốc.
--}}
@php
    // Vai trò của người đang đăng nhập. Nhân viên (staff) là THU NGÂN: chỗ làm
    // việc của họ là module Thu ngân (/cashier), và trong khu quản trị này menu
    // của họ chỉ còn Tổng quan và Đơn hàng. Sản phẩm, Marketing, Kho, Trả hàng,
    // Khách hàng, Báo cáo và Cài đặt đều đã bị route chặn bằng middleware
    // `admin.manage`, ở đây bỏ luôn khỏi menu để không mời bấm vào chỗ sẽ bị đá ra.
    $role = (string) data_get(session('api.user'), 'role.name', '');
    $canManage = in_array($role, ['super_admin', 'admin'], true);

    // CỬA HÀNG HẾT HẠN HỢP ĐỒNG: bỏ hẳn phần điều hướng.
    //
    // Mọi mục trong đó đều bị `admin.khoa` đá về trang Các gói dịch vụ (và API
    // cũng từ chối), nên để chúng lại chỉ mời người dùng bấm mười lần và mười lần
    // quay về cùng một chỗ. Còn lại đúng một lối đi, là lối duy nhất còn nghĩa.
    $khoa = (bool) session('phien.cua_hang_khoa');

    // Cấu trúc điều hướng — mirror NAV trong sidebar.tsx.
    $nav = [
        [
            'items' => [
                ['href' => route('admin.dashboard'), 'label' => 'Tổng quan', 'icon' => 'dashboard', 'active' => request()->routeIs('admin.dashboard')],
                [
                    // Trả hàng là module riêng (ReturnController) nhưng vẫn nằm trong
                    // dropdown Đơn hàng: người xử lý đơn và người lập phiếu trả nhìn
                    // vào cùng một chỗ, gom chung thì đỡ phải đi tìm. Chỉ khác quyền —
                    // trả hàng là tiền ra khỏi két nên chỉ quản trị viên thấy mục đó.
                    //
                    // BÁN TẠI QUẦY VÀ CA LÀM VIỆC KHÔNG CÒN Ở ĐÂY: cả hai đã sang
                    // module Thu ngân (/cashier), đi bằng nút đổi module ở góc phải
                    // thanh trên cùng. Để lại một bản sao trong menu này thì hai lối
                    // vào cùng một trang, mà một trong hai sẽ lạc hậu.
                    'label' => 'Đơn hàng', 'icon' => 'orders', 'count' => (int) ($pendingOrders ?? 0),
                    'children' => [
                        [
                            'href' => route('admin.orders.index'),
                            'label' => \App\Http\Controllers\OrderController::VIEWS['all']['label'],
                            'active' => request()->routeIs('admin.orders.*'),
                        ],
                        ...($canManage ? [[
                            'href' => route('admin.returns.index'),
                            'label' => \App\Http\Controllers\ReturnController::TITLE,
                            'active' => request()->routeIs('admin.returns.*'),
                        ]] : []),
                    ],
                ],
                ...($canManage ? [[
                    'label' => 'Hàng hóa', 'icon' => 'products',
                    'children' => [
                        ['href' => route('admin.products.index'), 'label' => \App\Http\Controllers\ProductController::TITLE_PAGE, 'active' => request()->routeIs('admin.products.*')],
                        ['href' => route('admin.categories.index'), 'label' => 'Nhóm hàng hóa', 'active' => request()->routeIs('admin.categories.*')],
                        ['href' => route('admin.thuoc-tinh.index'), 'label' => \App\Http\Controllers\ThuocTinhController::TITLE, 'active' => request()->routeIs('admin.thuoc-tinh.*')],
                        ['href' => route('admin.don-vi-tinh.index'), 'label' => \App\Http\Controllers\DonViTinhController::TITLE, 'active' => request()->routeIs('admin.don-vi-tinh.*')],
                        ['href' => route('admin.vi-tri.index'), 'label' => \App\Http\Controllers\ViTriController::TITLE, 'active' => request()->routeIs('admin.vi-tri.*')],
                        ['href' => route('admin.thue.index'), 'label' => 'Thuế', 'active' => request()->routeIs('admin.thue.*')],
                    ],
                ]] : []),
                // Marketing đứng ngay sau Hàng hóa: đây đều là thứ khách nhìn thấy
                // ngoài cửa hàng, sửa xong thường xem lại trang chủ luôn.
                //
                // Khuyến mãi, Voucher và Banner đã có trang thật. Riêng "Bài viết" mới
                // GOM SẴN CHỖ, chưa dựng gì — đánh dấu 'soon' nên bấm vào sẽ nói rõ là
                // chưa làm, không im lặng (quy tắc trong CLAUDE.md).
                ...($canManage ? [[
                    'label' => 'Marketing', 'icon' => 'marketing',
                    'children' => [
                        [
                            'href' => route('admin.promotions.index'),
                            'label' => \App\Http\Controllers\PromotionController::TITLE,
                            'active' => request()->routeIs('admin.promotions.*'),
                        ],
                        [
                            'href' => route('admin.vouchers.index'),
                            'label' => \App\Http\Controllers\VoucherController::TITLE,
                            'active' => request()->routeIs('admin.vouchers.*'),
                        ],
                        [
                            'href' => route('admin.banners.index'),
                            'label' => \App\Http\Controllers\BannerController::TITLE,
                            'active' => request()->routeIs('admin.banners.*'),
                        ],
                        [
                            // Yêu cầu của khách nằm trong Marketing chứ không phải
                            // Khách hàng: nhóm Marketing gom đúng những thứ khách
                            // nhìn thấy / chạm vào ngoài storefront, mà hai form này
                            // sinh ra từ đó.
                            'href' => route('admin.contacts.index'),
                            'label' => \App\Http\Controllers\ContactController::TITLE,
                            'active' => request()->routeIs('admin.contacts.*'),
                            'count' => (int) ($pendingContacts ?? 0),
                            'countLabel' => 'yêu cầu chưa xử lý',
                        ],
                        [
                            'href' => route('admin.newsletter.index'),
                            'label' => \App\Http\Controllers\ContactController::TITLE_NEWSLETTER,
                            'active' => request()->routeIs('admin.newsletter.*'),
                        ],
                        ['label' => 'Bài viết', 'soon' => true],
                    ],
                ]] : []),
                ...($canManage ? [
                    ['href' => route('admin.customers.index'), 'label' => 'Khách hàng', 'icon' => 'customers', 'active' => request()->routeIs('admin.customers.*')],
                ] : []),
                // Nhân sự đứng ngay sau Khách hàng: hai module cùng nói về NGƯỜI,
                // một bên ngoài cửa hàng một bên trong. Cố ý KHÔNG nhét vào Cài
                // đặt cạnh "Người dùng & vai trò" — hai trang đó dễ lẫn sẵn rồi,
                // xếp chung một chỗ thì càng khó phân biệt hồ sơ nhân viên với
                // tài khoản đăng nhập.
                ...($canManage ? [
                    [
                        'href' => route('admin.nhan-su.index'),
                        'label' => \App\Http\Controllers\NhanSuController::TITLE,
                        'icon' => 'staff',
                        'active' => request()->routeIs('admin.nhan-su.*'),
                    ],
                ] : []),
                ...($canManage ? [[
                    // Module kho: tồn kho, phiếu mua hàng và nhà cung cấp. Ba màn
                    // Đặt hàng nhập / Nhập hàng / Trả hàng nhập cũ đã gỡ — chiều
                    // mua vào nay gom vào MỘT chứng từ duy nhất.
                    'label' => 'Quản lý kho', 'icon' => 'inventory',
                    'children' => [
                        [
                            'href' => route('admin.ton-kho-chi-nhanh.index'),
                            'label' => \App\Http\Controllers\TonKhoChiNhanhController::TITLE,
                            'active' => request()->routeIs('admin.ton-kho-chi-nhanh.*'),
                        ],
                        // Điều chỉnh tồn kho đứng ngay sau Tồn kho: cùng nói về
                        // số tồn, một bên xem một bên nắn lại có duyệt.
                        [
                            'href' => route('admin.dieu-chinh-ton-kho.index'),
                            'label' => \App\Http\Controllers\DieuChinhTonKhoController::TITLE,
                            'active' => request()->routeIs('admin.dieu-chinh-ton-kho.*'),
                        ],
                        [
                            'href' => route('admin.phieu-mua-hang.index'),
                            'label' => \App\Http\Controllers\PhieuMuaHangController::TITLE,
                            'active' => request()->routeIs('admin.phieu-mua-hang.*'),
                        ],
                        // Chiều trả lại đứng ngay sau phiếu mua: cùng một cặp
                        // chứng từ, đọc menu từ trên xuống là đi đúng thứ tự.
                        [
                            'href' => route('admin.tra-hang-nha-cung-cap.index'),
                            'label' => \App\Http\Controllers\TraHangNhaCungCapController::TITLE,
                            'active' => request()->routeIs('admin.tra-hang-nha-cung-cap.*'),
                        ],
                        [
                            'href' => route('admin.nha-cung-cap.index'),
                            'label' => \App\Http\Controllers\NhaCungCapController::TITLE,
                            'active' => request()->routeIs('admin.nha-cung-cap.*'),
                        ],
                    ],
                ]] : []),
                // Báo cáo đứng sau các module nghiệp vụ và trước Cài đặt: nó chỉ ĐỌC
                // lại những gì các module trên sinh ra, nên đọc menu từ trên xuống là
                // đi đúng đường "làm việc → xem lại kết quả → chỉnh cấu hình".
                // Cùng nhóm quyền với Khách hàng (route đã chặn bằng `admin.manage`):
                // báo cáo phơi ra giá vốn, lợi nhuận và mức chi tiêu của từng khách.
                ...($canManage ? [[
                    'label' => \App\Http\Controllers\ReportController::TITLE, 'icon' => 'report',
                    'children' => collect(\App\Http\Controllers\ReportController::PAGES)
                        ->map(fn ($meta) => [
                            'href' => route($meta['route']),
                            'label' => $meta['label'],
                            'active' => request()->routeIs($meta['route']),
                        ])
                        ->values()
                        ->all(),
                ]] : []),
                // Cài đặt là dropdown như mọi module nhiều trang khác, đặt cuối nhóm.
                // Trước đây nó là một link chết ở chân sidebar (href="#") — bỏ hẳn chỗ
                // đó đi, điều hướng chỉ nên có một lối duy nhất.
                ...($canManage ? [[
                    'label' => 'Cài đặt', 'icon' => 'settings',
                    'children' => [
                        // Mỗi nhóm cấu hình là một trang riêng — nhãn lấy từ
                        // SettingController::GROUPS để sidebar và tiêu đề trang
                        // không bao giờ nói hai tên khác nhau.
                        ...collect(\App\Http\Controllers\SettingController::GROUPS)
                            ->map(fn ($meta, $code) => [
                                'href' => route('admin.settings.page', $code),
                                'label' => $meta['title'],
                                'active' => request()->routeIs('admin.settings.*')
                                    && request()->route('group') === $code,
                            ])
                            ->values()
                            ->all(),
                        // Thông số chung: bộ khung của tiệm theo bản ERP cũ. Mới có
                        // trang Quy tắc đánh số chứng từ, các trang còn lại làm sau.
                        [
                            'href' => route('admin.thong-so-chung.index'),
                            'label' => \App\Http\Controllers\ThongSoChungController::TITLE,
                            'active' => request()->routeIs('admin.thong-so-chung.*'),
                        ],
                        // Phân quyền: chọn chi nhánh → nhân viên → tick từng việc.
                        // Đứng ngay sau các trang cấu hình vì cùng một loại việc —
                        // dựng bộ khung của tiệm, không phải bán hàng hằng ngày.
                        [
                            'href' => route('admin.phan-quyen.index'),
                            'label' => \App\Http\Controllers\PhanQuyenController::TITLE,
                            'active' => request()->routeIs('admin.phan-quyen.*'),
                        ],
                        // "Người dùng & vai trò" ĐÃ BỎ KHỎI MENU (17/08/2026).
                        //
                        // Chủ tiệm mua phần mềm để quản lý NHÂN VIÊN, không phải để
                        // quản lý tài khoản: hai trang cùng nói về một con người mà
                        // bắt tạo hai lần ở hai chỗ. Việc cấp tài khoản đăng nhập nay
                        // là một khối trong hồ sơ nhân sự (/admin/staff).
                        //
                        // Route /admin/users vẫn còn sống nhưng KHÔNG có lối vào:
                        // trang nhân sự chưa lưu được gì (chưa có bảng + API), nên gỡ
                        // luôn đường cũ là cửa hàng mất hẳn cách cấp tài khoản cho
                        // người mới. Xoá hẳn UserController khi API nhân sự cấp được
                        // tài khoản.

                        // Chi nhánh: các ĐIỂM BÁN của cửa hàng này. Nằm trong Cài đặt
                        // vì cùng một loại việc — dựng bộ khung của tiệm, không phải
                        // bán hàng hằng ngày.
                        [
                            'href' => route('admin.chi-nhanh.index'),
                            'label' => \App\Http\Controllers\ChiNhanhController::TITLE,
                            'active' => request()->routeIs('admin.chi-nhanh.*'),
                        ],
                    ],
                ]] : []),
            ],
        ],
        // Không còn nhóm "Sắp ra mắt" riêng: Khuyến mãi đã về đúng nhóm nghiệp vụ
        // của nó (Marketing). Một mục chưa làm thì nằm cạnh những mục cùng loại vẫn
        // dễ hiểu hơn là bị tách ra một khu riêng ở cuối menu.
    ];

    // Nội dung path của từng icon (giống component Icon trong sidebar.tsx).
    $icons = [
        // Bảng số liệu: 2 ô biểu đồ bên trái + 1 ô báo cáo dọc bên phải.
        'dashboard' => '<rect x="2" y="3" width="9" height="8" rx="1"/><rect x="2" y="13" width="9" height="8" rx="1"/><rect x="13" y="3" width="9" height="18" rx="1"/><path d="M4.6 8.8V7.6M6.5 8.8V6.2M8.4 8.8V4.8"/><path d="m4.2 18.6 2.1-2.2 1 1 2.5-2.6"/><circle cx="17.5" cy="8" r="3.1"/><path d="M17.5 4.9V8h3.1"/><path d="M15 13.6h5M15.5 16h4M15.5 18.4h4"/>',
        // Thùng hàng có dấu tích — đơn hàng đã xử lý.
        'orders' => '<path d="M20.4 11.4V7.6H3.6V20a1 1 0 0 0 1 1h8"/><path d="M3.6 7.6 6.3 3.5a1 1 0 0 1 .84-.45h9.72a1 1 0 0 1 .84.45l2.7 4.1"/><path d="M9.6 7.6v3.9l2.4-1.5 2.4 1.5V7.6"/><circle cx="17.4" cy="16.6" r="4.4"/><path d="m15.5 16.7 1.4 1.4 2.4-3.5"/>',
        // Thùng hàng có mũi tên trả hàng — icon do chủ dự án chọn cho mục Hàng hóa.
        // Bản gốc là hình vuông 512 nét dày; vẽ lại ở dạng nét 24x24 cho khớp bộ icon
        // còn lại của thanh này, bỏ ký hiệu "chiều dựng thùng" vì cỡ này nhìn ra mực.
        'products' => '<path d="M2.2 8.2 5.2 3.4h9.6l3 4.8"/><path d="M2.2 8.2h15.6"/><path d="M2.2 8.2v12.2a.6.6 0 0 0 .6.6h12"/><path d="M17.8 8.2v5"/><path d="M8.6 3.4v7.2M13.4 3.4v7.2"/><path d="m8.6 10.6 1.2 1.1 1.2-1.1 1.2 1.1 1.2-1.1"/><circle cx="17.6" cy="17.6" r="4.4"/><path d="m16.4 15.5-1.6 1.5 1.6 1.5"/><path d="M14.8 17h3.4a1.8 1.8 0 0 1 0 3.6h-1.1"/>',
        'categories' => '<path d="M3 3h7l11 11-7 7L3 10V3Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        // Khung ảnh có núi và mặt trời — banner quảng cáo trên trang chủ.
        'banners' => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><circle cx="8" cy="9.6" r="1.5"/><path d="m3.4 16.8 4.4-4 3.2 2.9 3.4-3.4 6.2 5.9"/>',
        // Nhà kho: mái, cột hai bên và cửa cuốn.
        'inventory' => '<path d="M2 8.6a.6.6 0 0 1 .35-.55l9.25-4.15a1 1 0 0 1 .8 0l9.25 4.15a.6.6 0 0 1 .35.55v1a.6.6 0 0 1-.6.6H2.6a.6.6 0 0 1-.6-.6Z"/><path d="M9.6 6.4v1.5M11.1 5.7v2.2M12.9 5.7v2.2M14.4 6.4v1.5"/><path d="M4 10.2V21M20 10.2V21"/><path d="M6 21v-8a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v8"/><path d="M6 15h12M6 18h12"/><path d="M2.4 21h19.2"/>',
        'customers' => '<circle cx="9" cy="7.6" r="4"/><path d="M2 21v-.8a7 7 0 0 1 14 0v.8"/><path d="M15.7 3.6a4 4 0 0 1 0 8"/><path d="M15.7 11.6a6.3 6.3 0 0 1 6.3 6.3v.9"/>',
        'promotion' => '<path d="M4 12 12 4h8v8l-8 8-8-8Z"/><circle cx="16" cy="8" r="1.5"/><path d="M9 15l6-6"/>',
        // Ba người đứng sau một bánh răng — icon do chủ dự án chọn cho module Nhân
        // sự. Dựng lại ở dạng nét 24×24 cho khớp bộ icon còn lại của thanh này
        // (bản gốc là hình vuông 512, nét dày, đặt nguyên vào đây thì lệch hẳn với
        // các mục xung quanh).
        //
        // Bánh răng 8 răng vẽ bằng toạ độ tính sẵn: cung ngoài bán kính 5,9 ở đỉnh
        // răng, cung trong 4,72 ở chân răng, tâm (12; 15,2). Sửa tay từng số là
        // cách chắc chắn làm răng lệch nhau — muốn đổi số răng hay độ sâu thì tính
        // lại cả vòng.
        'staff' => '<circle cx="12" cy="3.9" r="2.3"/><path d="M8.15 9.2a4.2 4.2 0 0 1 7.7 0"/>'
            .'<circle cx="5" cy="6.2" r="2.2"/><path d="M1.05 12.2a4.6 4.6 0 0 1 7.15-2.1"/>'
            .'<circle cx="19" cy="6.2" r="2.2"/><path d="M22.95 12.2a4.6 4.6 0 0 0-7.15-2.1"/>'
            .'<path d="M10.9 10.61L10.92 9.4A5.9 5.9 0 0 1 13.08 9.4L13.1 10.61A4.72 4.72 0 0 1 14.47 11.18L15.34 10.34A5.9 5.9 0 0 1 16.86 11.86L16.02 12.73A4.72 4.72 0 0 1 16.59 14.1L17.8 14.12A5.9 5.9 0 0 1 17.8 16.28L16.59 16.3A4.72 4.72 0 0 1 16.02 17.67L16.86 18.54A5.9 5.9 0 0 1 15.34 20.06L14.47 19.22A4.72 4.72 0 0 1 13.1 19.79L13.08 21A5.9 5.9 0 0 1 10.92 21L10.9 19.79A4.72 4.72 0 0 1 9.53 19.22L8.66 20.06A5.9 5.9 0 0 1 7.14 18.54L7.98 17.67A4.72 4.72 0 0 1 7.41 16.3L6.2 16.28A5.9 5.9 0 0 1 6.2 14.12L7.41 14.1A4.72 4.72 0 0 1 7.98 12.73L7.14 11.86A5.9 5.9 0 0 1 8.66 10.34L9.53 11.18A4.72 4.72 0 0 1 10.9 10.61Z"/>'
            .'<circle cx="12" cy="15.2" r="2.3"/>',
        // Loa cầm tay + hai vòng sóng — nhóm việc rao ra bên ngoài cửa hàng.
        'marketing' => '<path d="M4 9.5h2.5L12 5.2v13.6L6.5 14.5H4a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1Z"/><path d="M6.6 14.5v3a1.4 1.4 0 0 0 1.4 1.4h.9a1.4 1.4 0 0 0 1.4-1.4v-1.1"/><path d="M15.6 9.4a3.9 3.9 0 0 1 0 5.2"/><path d="M18.2 6.8a7.6 7.6 0 0 1 0 10.4"/>',
        'report' => '<path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/>',
        // Thẻ có dấu tích — gói dịch vụ đang dùng và hạn của nó.
        'plan' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9.5h19"/><path d="M6 14h4"/><path d="m14.5 15 1.6 1.6 3.4-3.6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
    ];

    // Vài icon nhiều chi tiết hơn mức nét 1,6 chịu được ở cỡ 20px: các nét nằm sát
    // nhau nhòe vào nhau thành một vệt đặc. Khai riêng độ dày cho chúng ở đây thay
    // vì vẽ lại icon đơn giản hơn.
    $strokes = ['staff' => '1.35'];

    $svg = fn ($name, $sw = null) => '<svg class="jh-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . ($sw ?? $strokes[$name] ?? '1.6') . '" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$name] ?? '') . '</svg>';
@endphp

<aside id="jhSidebar" class="jh-sidebar">
    {{-- Brand — logo lấy từ Cài đặt → Cấu hình cửa hàng, dùng chung với website.
         Gỡ ảnh đi thì quay về logo-default-wide-light.svg (logo Sellio bản chữ sáng,
         giống trang giới thiệu selliotech.store).
         Lưu ý nền sidebar là xanh đậm: logo chữ đen tải lên sẽ chìm, nên chọn ảnh
         nền trong suốt chữ sáng. --}}
    @php
        $brandName = app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name'));
        $brandLogo = app(\App\Services\ApiClient::class)->settingString('store_logo', asset('images/logo-default-wide-light.svg'));
    @endphp
    <div class="jh-brand">
        <a href="{{ route('admin.dashboard') }}" class="jh-brand__link">
            <img src="{{ $brandLogo }}" alt="{{ $brandName }}" class="jh-brand__logo">
        </a>
        <button id="jhCollapse" type="button" class="jh-collapse-btn" aria-label="Thu gọn / Mở rộng">
            <svg class="jh-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="m15 18-6-6 6-6"/>
            </svg>
        </button>
    </div>

    {{-- Nav --}}
    <nav class="jh-nav">
        @if($khoa)
            <p class="jh-locked">
                Cửa hàng đã hết hạn. Các chức năng tạm khoá cho tới khi gia hạn.
            </p>
        @endif
        @foreach(($khoa ? [] : $nav) as $gi => $group)
            <div class="jh-group">
                @if(!empty($group['title']))
                    <p class="jh-group__title">{{ $group['title'] }}</p>
                    @if($gi > 0)
                        <div class="jh-divider"></div>
                    @endif
                @endif

                <ul class="jh-list">
                    @foreach($group['items'] as $item)
                        {{-- Dropdown (có children) --}}
                        @if(!empty($item['children']))
                            @php $childActive = collect($item['children'])->contains('active', true); @endphp
                            <li class="jh-menu {{ $childActive ? 'open' : '' }}">
                                <button type="button" data-menu-toggle
                                        class="jh-item {{ $childActive ? 'parent-active' : '' }} {{ !empty($item['count']) ? 'has-count' : '' }}"
                                        title="{{ $item['label'] }}{{ !empty($item['count']) ? ' — ' . $item['count'] . ' ' . ($item['countLabel'] ?? 'đơn chờ xác nhận') : '' }}">
                                    <span class="jh-item__icon">{!! $svg($item['icon']) !!}</span>
                                    <span class="jh-item__label">{{ $item['label'] }}</span>
                                    @if(!empty($item['count']))
                                        <span class="jh-count">{{ $item['count'] > 99 ? '99+' : $item['count'] }}</span>
                                    @endif
                                    <svg class="jh-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m6 9 6 6 6-6"/>
                                    </svg>
                                </button>
                                <div class="jh-submenu">
                                    <div class="jh-submenu__inner">
                                        <ul>
                                            @foreach($item['children'] as $child)
                                                <li>
                                                    @if(!empty($child['soon']))
                                                        {{-- Chưa có trang, nhưng vẫn bấm được và nói rõ lý do —
                                                             nút im lặng thì người dùng tưởng hỏng (CLAUDE.md). --}}
                                                        <button type="button" class="jh-sublink soon" data-soon="{{ $child['label'] }}">
                                                            {{ $child['label'] }}
                                                            <span class="jh-badge">Sắp có</span>
                                                        </button>
                                                    @else
                                                        {{-- Mục con cũng hiện được huy hiệu số việc đang chờ:
                                                             thiếu nó thì con số chỉ nằm ở nút cha, mà nút cha
                                                             đang đóng thì chẳng ai thấy có việc cần làm. --}}
                                                        <a href="{{ $child['href'] }}"
                                                            class="jh-sublink {{ !empty($child['active']) ? 'active' : '' }}"
                                                            title="{{ $child['label'] }}{{ !empty($child['count']) ? ' — ' . $child['count'] . ' ' . ($child['countLabel'] ?? 'mục chờ xử lý') : '' }}">
                                                            {{ $child['label'] }}
                                                            @if(!empty($child['count']))
                                                                <span class="jh-count jh-count--sub">{{ $child['count'] > 99 ? '99+' : $child['count'] }}</span>
                                                            @endif
                                                        </a>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </li>

                        {{-- Item "Sắp có" (disabled) --}}
                        @elseif(!empty($item['soon']))
                            <li>
                                <button type="button" class="jh-item soon" data-soon="{{ $item['label'] }}" title="{{ $item['label'] }}">
                                    <span class="jh-item__icon">{!! $svg($item['icon']) !!}</span>
                                    <span class="jh-item__label">{{ $item['label'] }}</span>
                                    <span class="jh-badge">Sắp có</span>
                                </button>
                            </li>

                        {{-- Link thường --}}
                        @else
                            <li>
                                <a href="{{ $item['href'] }}" class="jh-item {{ !empty($item['active']) ? 'active' : '' }} {{ !empty($item['count']) ? 'has-count' : '' }}" title="{{ $item['label'] }}{{ !empty($item['count']) ? ' — ' . $item['count'] . ' ' . ($item['countLabel'] ?? 'đơn chờ xác nhận') : '' }}">
                                    <span class="jh-item__icon">{!! $svg($item['icon']) !!}</span>
                                    <span class="jh-item__label">{{ $item['label'] }}</span>
                                    @if(!empty($item['count']))
                                        <span class="jh-count">{{ $item['count'] > 99 ? '99+' : $item['count'] }}</span>
                                    @endif
                                </a>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- Footer.

         Chỗ này từng in VAI TRÒ của người đang đăng nhập ("Super Admin") — một
         dòng chữ không bấm được và không nói thêm gì: ai cũng biết mình là chủ
         tiệm, và cái nhãn đó chiếm đúng vị trí dễ thấy nhất của thanh trái.

         Thay bằng lối vào trang Các gói dịch vụ: hợp đồng phần mềm sắp hết hạn là
         thứ chủ tiệm cần thấy mà trước nay không có chỗ nào nói, dấu hiệu duy
         nhất là một hôm đăng nhập không được nữa.

         Nhân viên (staff) KHÔNG mở được trang đó — route đã chặn bằng
         `admin.manage` — nên với họ chỗ này giữ nguyên nhãn vai trò cũ thay vì
         mời bấm vào một đường sẽ bị đá ra. --}}
    <div class="jh-footer">
        @if($canManage || $khoa)
            <a href="{{ route('admin.goi-dich-vu.index') }}"
               class="jh-item {{ request()->routeIs('admin.goi-dich-vu.*') ? 'active' : '' }}"
               title="Gói phần mềm bạn đang dùng và hạn gia hạn">
                <span class="jh-item__icon">{!! $svg('plan') !!}</span>
                <span class="jh-item__label">Các gói dịch vụ</span>
            </a>
        @else
            <div class="jh-role" title="Vai trò của tài khoản bạn đang dùng">
                <span class="jh-item__icon">{!! $svg('settings') !!}</span>
                <span class="jh-item__label">{{ data_get(session('api.user'), 'role.display_name', 'Tài khoản') }}</span>
            </div>
        @endif
    </div>
</aside>

<style>
    .jh-sidebar {
        position: sticky; top: 0;
        display: flex; flex-direction: column;
        height: 100vh; width: 230px;
        overflow-x: hidden;
        background: rgb(24, 37, 55);
        color: #cbd5e1;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
        font-size: 14px; font-weight: 450; line-height: 20px; letter-spacing: -0.2px;
        -webkit-font-smoothing: antialiased;
        transition: width .3s cubic-bezier(0.4, 0, 0.2, 1);
        will-change: width;
    }
    .jh-sidebar.collapsed { width: 48px; }

    /* Brand */
    .jh-brand {
        display: flex; align-items: center; height: 56px;
        gap: 8px; padding: 0 12px;
        border-bottom: 1px solid rgba(255, 255, 255, .05);
    }
    .jh-sidebar.collapsed .jh-brand { justify-content: center; padding: 0; gap: 0; }
    .jh-brand__link { flex: 1; min-width: 0; display: block; }
    .jh-sidebar.collapsed .jh-brand__link { display: none; }
    /* Logo nằm ngang (tỉ lệ ~4,4:1) nên ở 32px chữ nhỏ tới mức không đọc được.
       Hàng brand cao 56px, để 42px là kịch trần mà vẫn còn khoảng thở hai bên.
       max-width chặn bề ngang: logo dài hơn chỗ trống thì tự co lại thay vì tràn
       đè lên nút thu gọn — cần cho cả ảnh người dùng tự tải lên. */
    .jh-brand__logo { height: 42px; width: auto; max-width: 100%; object-fit: contain; object-position: left; }
    .jh-collapse-btn {
        border: 0; background: transparent; cursor: pointer;
        display: inline-flex; padding: 4px; border-radius: 4px;
        color: #94a3b8;
    }
    .jh-collapse-btn:hover { background: rgba(255, 255, 255, .05); color: #fff; }
    .jh-chevron { height: 16px; width: 16px; transition: transform .2s; }
    .jh-sidebar.collapsed .jh-chevron { transform: rotate(180deg); }

    /* Nav */
    .jh-nav {
        display: flex; flex: 1; flex-direction: column; gap: 4px;
        overflow-y: auto; padding: 8px 5px;
        scrollbar-width: thin; scrollbar-color: rgb(43, 66, 99) rgb(24, 37, 55);
    }
    .jh-sidebar.collapsed .jh-nav { padding-left: 6px; padding-right: 6px; }
    .jh-nav::-webkit-scrollbar { width: 8px; }
    .jh-nav::-webkit-scrollbar-thumb { background: rgb(43, 66, 99); border-radius: 4px; }
    .jh-nav::-webkit-scrollbar-track { background: rgb(24, 37, 55); }

    .jh-group { display: flex; flex-direction: column; gap: 4px; }
    .jh-group__title {
        margin: 0; padding: 12px 12px 2px;
        font-size: 11px; font-weight: 600; text-transform: uppercase;
        letter-spacing: .05em; color: #64748b;
    }
    .jh-sidebar.collapsed .jh-group__title { display: none; }
    .jh-divider { display: none; margin: 4px; border-top: 1px solid rgba(255, 255, 255, .1); }
    .jh-sidebar.collapsed .jh-divider { display: block; }
    .jh-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }

    /* Item base */
    .jh-item {
        display: flex; align-items: center; gap: 12px;
        width: 100%; box-sizing: border-box;
        padding: 8px 12px; border-radius: 6px;
        font-size: 14px; text-align: left; text-decoration: none;
        color: #cbd5e1; background: transparent; border: 0; cursor: pointer;
        transition: background .15s, color .15s;
    }
    .jh-sidebar.collapsed .jh-item { justify-content: center; padding-left: 0; padding-right: 0; gap: 0; }
    .jh-item:hover { background: rgba(255, 255, 255, .05); color: #fff; }
    .jh-item.active { background: #2563eb; color: #fff; font-weight: 500; }
    .jh-item.parent-active { color: #fff; }
    .jh-item__icon { display: inline-flex; flex-shrink: 0; opacity: .7; }
    .jh-item:hover .jh-item__icon,
    .jh-item.active .jh-item__icon,
    .jh-item.parent-active .jh-item__icon { opacity: 1; }
    .jh-ico { height: 20px; width: 20px; }
    .jh-item__label {
        flex: 1; min-width: 0; overflow: hidden;
        text-overflow: ellipsis; white-space: nowrap;
    }
    .jh-sidebar.collapsed .jh-item__label,
    .jh-sidebar.collapsed .jh-caret,
    .jh-sidebar.collapsed .jh-badge,
    .jh-sidebar.collapsed .jh-submenu { display: none; }

    /* Caret + submenu accordion */
    .jh-caret { height: 16px; width: 16px; flex-shrink: 0; color: #94a3b8; transition: transform .2s; }
    .jh-menu.open > .jh-item .jh-caret { transform: rotate(180deg); }
    .jh-submenu { display: grid; grid-template-rows: 0fr; transition: grid-template-rows .2s ease-in-out; }
    .jh-menu.open > .jh-submenu { grid-template-rows: 1fr; }
    .jh-submenu__inner { overflow: hidden; }
    .jh-submenu ul { list-style: none; margin: 0; padding: 4px 0 0 36px; display: flex; flex-direction: column; gap: 4px; }
    /* flex + gap: mục con có thể mang huy hiệu số việc chờ xử lý ở cuối dòng,
       display:block thì con số rơi xuống hàng dưới. */
    .jh-sublink {
        display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 6px;
        font-size: 14px; text-decoration: none; color: #94a3b8;
        transition: background .15s, color .15s;
    }
    .jh-count--sub { margin-left: auto; }
    .jh-sublink:hover { background: rgba(255, 255, 255, .05); color: #fff; }
    .jh-sublink.active { background: #2563eb; color: #fff; font-weight: 500; }
    /* Mục chưa có trang: mờ đi cho biết là chưa dùng được, nhưng VẪN bấm được —
       bấm vào hiện thông báo nói rõ vì sao, thay vì đứng im như hỏng. */
    .jh-sublink.soon {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        width: 100%; box-sizing: border-box;
        border: 0; background: transparent; cursor: pointer;
        font-family: inherit; text-align: left; color: #64748b;
    }
    .jh-sublink.soon:hover { background: rgba(255, 255, 255, .05); color: #94a3b8; }

    /* Soon */
    .jh-item.soon { color: #64748b; }
    .jh-item.soon:hover { background: rgba(255, 255, 255, .05); color: #94a3b8; }
    .jh-item.soon .jh-item__icon { opacity: .7; }
    .jh-badge {
        border-radius: 4px; background: rgba(255, 255, 255, .05);
        padding: 2px 6px; font-size: 10px; color: #64748b;
    }

    /* Badge số đơn chờ xác nhận */
    .jh-item { position: relative; }
    .jh-count {
        flex-shrink: 0; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px;
        display: inline-flex; align-items: center; justify-content: center;
        background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; line-height: 1;
    }
    /* Khi thu gọn: ẩn pill, hiện chấm đỏ ở góc icon để vẫn báo có đơn mới. */
    .jh-sidebar.collapsed .jh-count { display: none; }
    .jh-sidebar.collapsed .jh-item.has-count::after {
        content: ''; position: absolute; top: 6px; right: 8px;
        width: 8px; height: 8px; border-radius: 50%; background: #ef4444;
        box-shadow: 0 0 0 2px rgb(24, 37, 55);
    }

    /* Footer */
    .jh-footer { padding: 8px; border-top: 1px solid rgba(255, 255, 255, .05); }
    .jh-sidebar.collapsed .jh-footer { padding-left: 6px; padding-right: 6px; }
    /* Nhãn vai trò — chỉ hiển thị, không bấm được nên không dùng màu/hover của .jh-item */
    .jh-role {
        display: flex; align-items: center; gap: 12px;
        padding: 8px 12px; font-size: 13px; color: #94a3b8;
    }
    .jh-sidebar.collapsed .jh-role { justify-content: center; padding-left: 0; padding-right: 0; gap: 0; }
    .jh-role .jh-item__icon { opacity: .7; }
    .jh-sidebar.collapsed .jh-role .jh-item__label { display: none; }

    /* Câu giải thích thay cho cả thanh điều hướng khi cửa hàng hết hạn */
    .jh-locked {
        margin: 4px; padding: 10px 12px; border-radius: 6px;
        background: rgba(255, 255, 255, .05);
        font-size: 12px; line-height: 1.6; color: #94a3b8;
    }
    .jh-sidebar.collapsed .jh-locked { display: none; }
</style>

<script>
    (function () {
        var KEY = 'sidebar-collapsed';
        var el = document.getElementById('jhSidebar');
        if (!el) return;

        // Khôi phục trạng thái thu gọn — tắt transition để không animate lúc tải.
        el.style.transition = 'none';
        if (localStorage.getItem(KEY) === '1') el.classList.add('collapsed');
        requestAnimationFrame(function () { el.style.transition = ''; });

        function setCollapsed(on) {
            el.classList.toggle('collapsed', on);
            localStorage.setItem(KEY, on ? '1' : '0');
        }

        // Nút thu gọn / mở rộng.
        document.getElementById('jhCollapse').addEventListener('click', function () {
            setCollapsed(!el.classList.contains('collapsed'));
        });

        // Mục chưa dựng trang: nói thẳng ra là chưa làm. Bấm vào mà không có phản
        // hồi gì thì người dùng ngồi bấm lại vài lần rồi tưởng hệ thống hỏng.
        el.querySelectorAll('[data-soon]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ten = btn.getAttribute('data-soon');
                if (typeof window.adminToast === 'function') {
                    window.adminToast({
                        title: ten + ' — chưa mở',
                        body: 'Mục này mới được xếp sẵn chỗ trong menu, chức năng chưa được xây dựng.',
                        tone: 'info',
                    });
                } else {
                    alert(ten + ': mục này mới được xếp sẵn chỗ trong menu, chức năng chưa được xây dựng.');
                }
            });
        });

        // Dropdown accordion — chỉ mở 1 menu.
        el.querySelectorAll('[data-menu-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var li = btn.closest('.jh-menu');
                var menus = el.querySelectorAll('.jh-menu');

                // Đang thu gọn → mở rộng rồi mở đúng menu này.
                if (el.classList.contains('collapsed')) {
                    setCollapsed(false);
                    menus.forEach(function (m) { m.classList.remove('open'); });
                    li.classList.add('open');
                    return;
                }

                var isOpen = li.classList.contains('open');
                menus.forEach(function (m) { m.classList.remove('open'); });
                if (!isOpen) li.classList.add('open');
            });
        });
    })();
</script>
