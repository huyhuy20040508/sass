<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Khu "QLTK khách hàng web" — Website bán hàng (app `web`), tính năng BÁN THÊM cho cửa hàng
 * đang dùng POS: cửa hàng nào có hợp đồng web còn chạy thì web của họ mới mở.
 *
 * Cùng màn hình với khu Order, chỉ khác app. Không dựng cửa hàng hay tài khoản (web đăng nhập
 * bằng tài khoản POS), nên không có "Thêm tài khoản dùng thử", "Khách mới", đổi mật khẩu hay
 * Database — chỉ ký hợp đồng cho cửa hàng đã có. Thêm: mở / gỡ web cho từng chi nhánh.
 */
class CustomerWebController extends CustomerOrderController
{
    public const APP = 'web';

    public const TUYEN = 'khach-hang-web';

    public const TEN_APP = 'Website bán hàng';

    public const NHAN_KHU = 'QLTK khách hàng web';

    public const TAO_KHACH_MOI = false;

    protected function themChiTiet(int $hopDong): array
    {
        [$data, $loi] = $this->goi('web của chi nhánh', fn () => $this->api->webChiNhanh($hopDong));

        return ['webChiNhanh' => $data ?: ['chi_nhanh' => [], 'toi_da' => 0, 'dang_mo' => 0], 'loiWeb' => $loi];
    }

    public function moWeb(Request $request, int $hopDong)
    {
        $shop = (int) $request->validate(['shop_id' => ['required', 'integer', 'min:1']])['shop_id'];

        return $this->ghiWeb(fn () => $this->api->moWebChiNhanh($hopDong, $shop), 'Không mở được web cho chi nhánh.');
    }

    public function goWeb(int $hopDong, int $shop)
    {
        return $this->ghiWeb(fn () => $this->api->goWebChiNhanh($hopDong, $shop), 'Không gỡ được web của chi nhánh.');
    }

    private function ghiWeb(callable $ham, string $macDinh)
    {
        try {
            $res = $ham();
        } catch (\Throwable $e) {
            Log::error($macDinh, ['msg' => $e->getMessage()]);

            return back()->with('error', 'Không kết nối được máy chủ API. Vui lòng thử lại.');
        }
        if (! $res->successful()) {
            // Lỗi nghiệp vụ (vượt số chi nhánh, chi nhánh đã tắt…) nằm ở errors.shop_id / hop_dong.
            $chiTiet = collect($res->json('errors') ?: [])->first();

            return back()->with('error', $chiTiet ?: ($res->json('message') ?: $macDinh));
        }

        return back()->with('success', $res->json('message') ?: 'Đã lưu.');
    }
}
