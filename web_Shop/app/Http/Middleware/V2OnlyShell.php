<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn mọi trang còn dùng giao diện cũ.
 *
 * Đang trong đợt chuyển sang giao diện v2 (view nằm ở web_Shop/v2). Màn nào chưa
 * dựng lại thì KHÔNG mở ra bản cũ nữa mà dồn về màn v2 gần nhất — nếu không thì
 * bấm nhầm một đường dẫn là lại thấy hai giao diện lẫn lộn trong cùng một phiên.
 *
 * Dựng xong màn nào thì thêm tên route của nó vào DA_CO_V2.
 */
class V2OnlyShell
{
    /** Màn đã có bản v2 — cho qua. */
    public const DA_CO_V2 = [
        'admin.dashboard',
        'admin.nha-cung-cap.index',
        'admin.dieu-chinh-ton-kho.index',
        'admin.ton-kho-chi-nhanh.index',
        'admin.categories.index',
        'admin.products.index',
        'admin.thue.index',
        'admin.don-vi-tinh.index',
        'admin.thuoc-tinh.index',
        'admin.phieu-mua-hang.index',
        'admin.tra-hang-nha-cung-cap.index',
        'admin.phieu-dieu-chuyen.index',
        'admin.chi-nhanh.index',
        'admin.staff.index',
        'admin.loai-thu-chi.index',
        'admin.cashbook.index',
        'admin.cong-no.index',
        'admin.customers.index',
        'admin.orders.index',
        'admin.hoa-don-dien-tu.index',
        // Thống kê → Khách hàng dựng bằng v2::reports.khach-hang từ lâu, chỉ là
        // quên khai ở đây nên vẫn bị chính cổng này dồn đi.
        'admin.reports.customers',
    ];

    /**
     * Màn CÒN BẢN CŨ nhưng vẫn phải mở được.
     *
     * Cổng này sinh ra để không ai vô tình lạc vào giao diện cũ. Nhưng với mấy
     * màn dưới đây thì cái giá đắt hơn cái lợi: chúng KHÔNG có bản v2, cũng
     * KHÔNG có màn v2 nào thay thế, nên chặn không phải là "dồn về màn gần
     * nhất" mà là xoá trắng một chức năng — hạn mức giảm giá của nhân viên,
     * khuyến mãi, mã giảm giá, quy tắc đánh số, ba trang báo cáo. Người dùng gõ
     * đúng đường dẫn vẫn bị ném về Khách hàng mà không một lời giải thích.
     *
     * Đây là danh sách TẠM. Port xong màn nào thì CHUYỂN tên nó sang DA_CO_V2,
     * đừng để hai chỗ cùng khai một màn.
     */
    public const CON_BAN_CU = [
        // Cài đặt: /admin/settings/pos là nơi đặt hạn mức giảm giá cho thu ngân.
        'admin.settings.index',
        'admin.settings.page',
        // Thông số chung + quy tắc đánh số chứng từ.
        'admin.thong-so-chung.index',
        'admin.thong-so-chung.numbering-rule',
        'admin.promotions.index',
        'admin.promotions.export',
        'admin.vouchers.index',
        'admin.vouchers.export',
        // Ba trang báo cáo còn bản cũ. Trang Khách hàng đã là v2 nên nằm ở trên.
        'admin.reports.index',
        'admin.reports.revenue',
        'admin.reports.orders',
        'admin.reports.products',
        // Phân quyền và Tài khoản đăng nhập: đường DUY NHẤT cấp quyền lẻ cho một
        // tài khoản. Chặn chúng là chủ tiệm không có cách nào sửa quyền nhân viên.
        // Tổng quan — mục đầu tiên của menu, bản cũ vẫn chạy.
        'admin.phan-quyen.index',
        'admin.users.index',
        'admin.returns.index',
        'admin.vi-tri.index',
        'admin.banners.index',
        'admin.contacts.index',
        'admin.newsletter.index',
        'admin.profile.edit',
        // Phiếu kiểm kho: trang IN độc lập, không nằm trong vỏ nào.
        'admin.ton-kho-chi-nhanh.stocktake',
    ];

    /**
     * Route KHÔNG vẽ trang: tải tệp, gọi ngầm, hoặc là hạ tầng của chính khung v2
     * (chuông thông báo, đổi chi nhánh, hồ sơ). Chặn mấy cái này là gãy khung.
     */
    public const KHONG_PHAI_TRANG = [
        'admin.notifications.',  // chuông gọi ngầm, trả JSON
        'admin.chi-nhanh.dangLam',
        'admin.chi-nhanh.etax',  // hộp hoá đơn điện tử đọc ngầm, trả JSON
        'admin.nha-cung-cap.',   // xuất / nhập / mẫu / ảnh / phiếu mua
        'admin.dieu-chinh-ton-kho.', // xuất / tìm hàng / hàng âm / ảnh đính kèm
        // Tồn kho chi nhánh: CHỈ mấy đường tải tệp. Không mở cả tiền tố vì
        // 'stocktake' vẫn là trang vẽ bằng khu cũ, chưa dựng lại theo v2.
        'admin.ton-kho-chi-nhanh.export',
        'admin.ton-kho-chi-nhanh.history',
        'admin.phieu-mua-hang.', // xuất / tìm hàng / ảnh / chi tiết một phiếu
        'admin.tra-hang-nha-cung-cap.', // xuất / phiếu mua của NCC / dòng phiếu mua / chi tiết
        'admin.phieu-dieu-chuyen.', // tìm mặt hàng cho hộp lập phiếu
        'admin.products.',       // xuất / nhập / mẫu / ảnh / chi tiết một mặt hàng
        'admin.staff.',        // xuất CSV / ảnh / hàng loạt
        'admin.cashbook.',        // xuất Excel / nạp phân loại, người nộp cho hộp lập phiếu
        'admin.cong-no.',        // xuất Excel / lịch sử trả nợ / ghi lượt trả
        'admin.customers.',      // xuất CSV / mẫu nhập / chi tiết / sổ đơn của khách / ảnh
        'admin.orders.',         // xuất CSV / chi tiết một đơn / bản in đơn, tem / hoá đơn điện tử
        'admin.hoa-don-dien-tu.', // xuất CSV sổ hoá đơn
        // Xuất tệp / gọi ngầm của các màn vừa mở ở CON_BAN_CU. Chặn chúng là nút
        // Xuất Excel trên chính mấy màn ấy ném người dùng về Khách hàng.
        'admin.banners.',
        'admin.contacts.',
        'admin.newsletter.',
        'admin.returns.',
        'admin.vi-tri.',
        'admin.users.',
        'admin.phan-quyen.',
        'admin.ton-kho-chi-nhanh.importTemplate',
        'admin.ton-kho-chi-nhanh.importCostTemplate',
        'admin.goi-dich-vu.',    // cửa hàng hết hạn bị dồn về đây, chặn là kẹt cứng
    ];

    // Trang Tài khoản của tôi CÒN dùng giao diện cũ nên cũng bị chặn. Dựng lại
    // theo v2 rồi thì thêm 'admin.profile.edit' vào DA_CO_V2.

    public function handle(Request $request, Closure $next): Response
    {
        // Chỉ soi lượt MỞ TRANG. POST/PUT/DELETE là thao tác, và request ngầm
        // (fetch/ajax) thì chuyển hướng chỉ làm hỏng phần xử lý lỗi của trang.
        if (! $request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
            return $next($request);
        }

        $ten = (string) optional($request->route())->getName();

        if (in_array($ten, self::DA_CO_V2, true)) {
            return $next($request);
        }

        if (in_array($ten, self::CON_BAN_CU, true)) {
            return $next($request);
        }

        foreach (self::KHONG_PHAI_TRANG as $tienTo) {
            if (str_starts_with($ten, $tienTo)) {
                return $next($request);
            }
        }

        return redirect()->route('admin.customers.index');
    }
}
