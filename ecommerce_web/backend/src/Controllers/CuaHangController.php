<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CuaHang;

final class CuaHangController
{
    public function show(Request $req): void
    {
        $ch = CuaHang::theoHost($req->host);
        Response::ok(['cua_hang' => $ch['cua_hang'], 'chi_nhanh' => $ch['chi_nhanh']]);
    }
}
