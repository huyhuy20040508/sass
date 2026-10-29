<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CurrentAdmin;
use App\Core\CurrentShop;
use App\Core\Request;
use App\Core\Response;
use App\Services\CaiDatShop;
use App\Services\KhoAnh;

/** Màn Cài đặt → Thông tin cửa hàng của admin web. */
final class AdminCaiDatController
{
    public function show(Request $req): void
    {
        Response::ok(self::kemUrl(CaiDatShop::doc(CurrentShop::id())));
    }

    public function update(Request $req): void
    {
        $admin = CurrentAdmin::get();
        $cd = CaiDatShop::luu(CurrentShop::id(), $req->body, $admin['full_name'] ?: $admin['username']);
        Response::ok(self::kemUrl($cd), message: 'Đã lưu, web cập nhật ngay');
    }

    public function uploadAnh(Request $req): void
    {
        $ten = KhoAnh::luu(CurrentShop::id(), $_FILES['anh'] ?? []);
        Response::ok(['ten' => $ten, 'url' => KhoAnh::url($ten)], status: 201);
    }

    /** Ô logo lưu tên tệp; màn chỉnh cần thêm link để hiện ảnh. */
    private static function kemUrl(array $cd): array
    {
        return $cd + ['logo_url' => KhoAnh::url((string) $cd['logo'])];
    }
}
