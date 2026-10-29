<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CurrentAdmin;
use App\Core\CurrentShop;
use App\Core\Request;
use App\Core\Response;
use App\Services\GiaoDien;
use App\Services\TruyCap;

/** Màn Chỉnh sửa giao diện của admin web. */
final class AdminGiaoDienController
{
    public function show(Request $req): void
    {
        Response::ok(GiaoDien::choManChinh(CurrentShop::id()));
    }

    public function saveDraft(Request $req): void
    {
        GiaoDien::luuNhap(CurrentShop::id(), $req->body, self::nguoi());
        Response::ok(GiaoDien::choManChinh(CurrentShop::id()));
    }

    public function switchTheme(Request $req): void
    {
        GiaoDien::doiTheme(CurrentShop::id(), (string) $req->input('theme', ''), (bool) $req->input('dung_mau', true), self::nguoi());
        Response::ok(GiaoDien::choManChinh(CurrentShop::id()), message: 'Đã đổi mẫu trong bản nháp — bấm Áp dụng để khách thấy');
    }

    public function publish(Request $req): void
    {
        GiaoDien::apDung(CurrentShop::id(), self::nguoi());
        Response::ok(GiaoDien::choManChinh(CurrentShop::id()), message: 'Đã áp dụng, khách thấy giao diện mới ngay');
    }

    public function discard(Request $req): void
    {
        GiaoDien::boNhap(CurrentShop::id());
        Response::ok(GiaoDien::choManChinh(CurrentShop::id()), message: 'Đã bỏ các thay đổi chưa áp dụng');
    }

    /** Thẻ Tổng quan ở trang Website của tôi. */
    public function traffic(Request $req): void
    {
        $so = $req->queryInt('ngay', 7, 1, 90);
        Response::ok(TruyCap::tongQuan(CurrentShop::id(), in_array($so, [7, 30, 90], true) ? $so : 7));
    }

    private static function nguoi(): string
    {
        $a = CurrentAdmin::get();
        return $a['full_name'] ?: $a['username'];
    }
}
