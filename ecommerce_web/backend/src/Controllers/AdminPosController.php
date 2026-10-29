<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CurrentAdmin;
use App\Core\CurrentShop;
use App\Core\Request;
use App\Core\Response;
use App\Services\PosKetNoi;

/** Màn Cài đặt → Kết nối POS của admin web. */
final class AdminPosController
{
    public function show(Request $req): void
    {
        Response::ok(PosKetNoi::trangThai(CurrentShop::id(), $req->ip()));
    }

    public function connect(Request $req): void
    {
        $admin = CurrentAdmin::get();
        PosKetNoi::ketNoi(CurrentShop::id(), $admin['full_name'] ?: $admin['username'], CurrentAdmin::posAccessToken($req), $req->ip());
        Response::ok(PosKetNoi::trangThai(CurrentShop::id(), $req->ip()), message: 'Đã kết nối POS');
    }

    public function disconnect(Request $req): void
    {
        PosKetNoi::ngat(CurrentShop::id(), CurrentAdmin::posAccessToken($req), $req->ip());
        Response::ok(['ket_noi' => false], message: 'Đã ngắt kết nối POS');
    }

    public function products(Request $req): void
    {
        $kq = PosKetNoi::hang(
            CurrentShop::id(),
            $req->queryInt('page', 1, 1, 10000),
            mb_substr(trim((string) ($req->query['q'] ?? '')), 0, 100),
            $req->ip(),
        );
        Response::ok($kq['items'], $kq['meta']);
    }
}
