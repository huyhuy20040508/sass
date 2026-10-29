<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CurrentKhach;
use App\Core\CurrentShop;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\KhachWeb;

/**
 * Đăng ký / đăng nhập của KHÁCH MUA HÀNG trên web của shop.
 *
 * Khác AdminAuthController ở chỗ tài khoản này là của web, không phải tài khoản
 * POS: người mua không có chỗ nào trong POS để đăng nhập. Nhưng hồ sơ khách thì
 * nằm bên POS ngay sau lượt đăng ký — xem KhachWeb::dangKy.
 */
final class KhachAuthController
{
    public function dangKy(Request $req): void
    {
        $soat = KhachWeb::soatHoSo(
            (string) $req->input('full_name', ''),
            (string) $req->input('phone', ''),
            (string) $req->input('email', ''),
        );
        $matKhau = (string) $req->input('password', '');
        $errors = $soat['errors'];
        if ($matKhau === '') {
            $errors['password'] = 'Nhập mật khẩu';
        } elseif (mb_strlen($matKhau) < KhachWeb::MAT_KHAU_TOI_THIEU) {
            $errors['password'] = 'Mật khẩu ít nhất ' . KhachWeb::MAT_KHAU_TOI_THIEU . ' ký tự';
        }
        if ($errors) {
            throw new HttpException(422, 'Vui lòng kiểm tra lại thông tin', $errors);
        }

        $shopId = CurrentShop::id();
        $khach = KhachWeb::dangKy($shopId, $soat['ten'], $soat['sdt'], $soat['email'], $matKhau, $req->ip());

        Response::ok([
            'token' => KhachWeb::moPhien($shopId, $khach['id']),
            'khach' => ['full_name' => $khach['full_name'], 'phone' => $khach['phone'], 'email' => $khach['email']],
        ], [], 201, 'Đã tạo tài khoản');
    }

    public function dangNhap(Request $req): void
    {
        $sdt = (string) $req->input('phone', '');
        $matKhau = (string) $req->input('password', '');

        $errors = [];
        if (trim($sdt) === '') {
            $errors['phone'] = 'Nhập số điện thoại';
        }
        if ($matKhau === '') {
            $errors['password'] = 'Nhập mật khẩu';
        }
        if ($errors) {
            throw new HttpException(422, 'Vui lòng nhập đủ thông tin', $errors);
        }

        $shopId = CurrentShop::id();
        $khach = KhachWeb::dangNhap($shopId, $sdt, $matKhau);

        Response::ok([
            'token' => KhachWeb::moPhien($shopId, $khach['id']),
            'khach' => ['full_name' => $khach['full_name'], 'phone' => $khach['phone'], 'email' => $khach['email']],
        ]);
    }

    public function toi(Request $req): void
    {
        $khach = CurrentKhach::get();
        Response::ok([
            'khach' => ['full_name' => $khach['full_name'], 'phone' => $khach['phone'], 'email' => $khach['email']],
        ]);
    }

    public function dangXuat(Request $req): void
    {
        Db::exec('DELETE FROM khach_sessions WHERE id = ?', [CurrentKhach::get()['session_id']]);
        Response::ok(null);
    }
}
