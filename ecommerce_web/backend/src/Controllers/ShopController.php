<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CurrentShop;
use App\Core\Request;
use App\Core\Response;

final class ShopController
{
    /** Thông tin công khai của shop — trang khách gọi đầu tiên để biết mã trên link có hợp lệ không. */
    public function show(Request $req): void
    {
        $shop = CurrentShop::get();
        Response::ok(['code' => $shop['code'], 'name' => $shop['name']]);
    }
}
