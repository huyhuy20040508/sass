<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CurrentShop;
use App\Core\Request;
use App\Core\Response;
use App\Services\CaiDatShop;
use App\Services\GiaoDien;
use App\Services\HangWeb;
use App\Services\KhoAnh;
use App\Services\NhomHangWeb;
use App\Services\TruyCap;

final class ShopController
{
    /** Thông tin công khai của shop — trang khách gọi đầu tiên để biết mã trên link có hợp lệ không. */
    public function show(Request $req): void
    {
        $shop = CurrentShop::get();
        Response::ok(['code' => $shop['code'], 'name' => $shop['name']]);
    }

    /**
     * Mọi thứ phần dùng chung của trang khách cần (đầu trang, chân trang, màu/font):
     * Next gọi một lần cho mỗi lượt dựng trang.
     */
    public function khung(Request $req): void
    {
        $shop = CurrentShop::get();
        $cd = CaiDatShop::doc($shop['id']);
        $cd['ten_hien_thi'] = $cd['ten_hien_thi'] ?: $shop['name'];
        $cd['logo'] = KhoAnh::url((string) $cd['logo']);

        Response::ok([
            'shop' => ['code' => $shop['code'], 'name' => $shop['name']],
            'cai_dat' => $cd,
            'giao_dien' => array_diff_key(self::giaoDien($req), ['trang_chu' => 0]),
            'nhom_hang' => NhomHangWeb::cay($shop['id'], $req->ip()),
        ]);
    }

    /** Các khối trang chủ theo giao diện của shop. */
    public function trangChu(Request $req): void
    {
        Response::ok(self::giaoDien($req)['trang_chu']);
    }

    /** Lưới hàng của một khối Hàng hoá. */
    public function hang(Request $req): void
    {
        $nguon = (string) ($req->query['nguon'] ?? 'moi');
        Response::ok(HangWeb::luoi(
            CurrentShop::id(),
            in_array($nguon, ['moi', 'ban_chay', 'giam', 'nhom'], true) ? $nguon : 'moi',
            $req->queryInt('nhom', 0, 0, PHP_INT_MAX),
            $req->queryInt('so', 8, 1, 24),
            $req->ip(),
        ));
    }

    /**
     * Bản đang chạy; khung xem trước của màn chỉnh gửi kèm ?xem_truoc=<mã> để thấy bản nháp.
     * Mã sai/hết hạn thì lặng lẽ trả bản đang chạy — không để lộ là có bản nháp.
     *
     * @return array<string, mixed>
     */
    private static function giaoDien(Request $req): array
    {
        $shopId = CurrentShop::id();
        $ma = (string) ($req->query['xem_truoc'] ?? '');
        if ($ma === '' || !GiaoDien::maXemTruocHopLe($shopId, $ma)) {
            return GiaoDien::congKhai(GiaoDien::doc($shopId, 'dang_chay'));
        }
        // "Xem thử" ở Kho giao diện: mẫu khác dựng bằng nội dung của shop, nháp không đổi.
        $thu = (string) ($req->query['thu_mau'] ?? '');
        if ($thu !== '' && in_array($thu, array_column(GiaoDien::dsTheme(), 'ma'), true)) {
            return GiaoDien::congKhai(GiaoDien::tinhDoiTheme($shopId, $thu, true));
        }

        return GiaoDien::congKhai(GiaoDien::doc($shopId, 'nhap'));
    }

    /** Trang khách báo một lượt xem (components/shop/DemLuot.tsx). */
    public function dem(Request $req): void
    {
        TruyCap::dem(CurrentShop::id(), (string) $req->input('k', ''), $req->header('user-agent'));
        Response::ok();
    }

    public function anh(Request $req, array $p): void
    {
        KhoAnh::phat(CurrentShop::id(), (string) $p['ten']);
    }
}
