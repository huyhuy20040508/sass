<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\CurrentAdmin;
use App\Core\CurrentShop;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Secret;
use App\Services\PosAdmin;

final class AdminAuthController
{
    /** Ba ô như POS: mã cửa hàng + tên đăng nhập + mật khẩu, kiểm bằng chính tài khoản POS. */
    public function login(Request $req): void
    {
        $storeCode = strtolower(trim((string) $req->input('store_code', '')));
        $username = trim((string) $req->input('username', ''));
        $password = (string) $req->input('password', '');

        $errors = [];
        if ($storeCode === '') {
            $errors['store_code'] = 'Nhập mã cửa hàng';
        }
        if ($username === '') {
            $errors['username'] = 'Nhập tên đăng nhập';
        }
        if ($password === '') {
            $errors['password'] = 'Nhập mật khẩu';
        }
        if ($errors) {
            throw new HttpException(422, 'Vui lòng nhập đủ thông tin', $errors);
        }

        $shop = CurrentShop::get();
        // Ô mã cửa hàng phải là tiệm của tên miền đang mở: đăng nhập tài khoản tiệm B
        // trên địa chỉ tiệm A thì phiên sinh ra cũng không dùng được ở đâu.
        if ($storeCode !== $shop['code']) {
            throw new HttpException(422, 'Mã cửa hàng không khớp với địa chỉ web này', ['store_code' => 'Đây là web của cửa hàng ' . $shop['code']]);
        }

        $pos = PosAdmin::login($storeCode, $username, $password, $req->ip());
        if ($pos['tenant_code'] !== $shop['code']) {
            throw new HttpException(403, 'Tài khoản không thuộc cửa hàng này');
        }

        $token = bin2hex(random_bytes(32));
        Db::exec(
            'INSERT INTO admin_sessions (shop_id, pos_user_id, username, full_name, token_hash, pos_refresh_token, pos_checked_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW() + INTERVAL ' . CurrentAdmin::SESSION_DAYS . ' DAY)',
            [$shop['id'], $pos['user_id'], $pos['username'], $pos['full_name'], hash('sha256', $token), Secret::seal($pos['refresh_token'])],
        );

        Response::ok([
            'token' => $token,
            'admin' => ['username' => $pos['username'], 'full_name' => $pos['full_name']],
            'shop' => ['code' => $shop['code'], 'name' => $shop['name']],
        ]);
    }

    public function me(Request $req): void
    {
        $admin = CurrentAdmin::get();
        $shop = CurrentShop::get();
        Response::ok([
            'admin' => ['username' => $admin['username'], 'full_name' => $admin['full_name']],
            'shop' => ['code' => $shop['code'], 'name' => $shop['name']],
        ]);
    }

    public function logout(Request $req): void
    {
        Db::exec('DELETE FROM admin_sessions WHERE id = ?', [CurrentAdmin::get()['session_id']]);
        Response::ok(null);
    }
}
