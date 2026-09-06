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
class ChiHienGiaoDienV2
{
    /** Màn đã có bản v2 — cho qua. */
    public const DA_CO_V2 = [
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
        'admin.nhan-su.index',
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
        'admin.nhan-su.',        // xuất CSV / ảnh / hàng loạt
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

        foreach (self::KHONG_PHAI_TRANG as $tienTo) {
            if (str_starts_with($ten, $tienTo)) {
                return $next($request);
            }
        }

        return redirect()->route('admin.nha-cung-cap.index');
    }
}
