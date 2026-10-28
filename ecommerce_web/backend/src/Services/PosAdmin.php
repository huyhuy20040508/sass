<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Tài khoản quản trị web là tài khoản POS: web không giữ mật khẩu, mọi lượt
 * kiểm đều hỏi POS.
 */
final class PosAdmin
{
    // POS khoá cửa hàng (tenants.status = suspended) khi hợp đồng hết hạn — lượt quét nền
    // bên POS — hoặc khi bị khoá tay. Chủ tiệm vẫn vào được POS để gia hạn, web thì không.
    private const LOCKED = 'Cửa hàng đã hết hạn sử dụng hoặc đang tạm khoá, vui lòng gia hạn hoặc liên hệ nhà cung cấp phần mềm';

    /**
     * Đăng nhập 3 ô qua POS.
     *
     * @return array{tenant_code: string, user_id: int, username: string, full_name: string, refresh_token: string}
     */
    public static function login(string $shopCode, string $username, string $password, string $clientIp): array
    {
        $res = PosClient::post('/auth/shop-login', [
            'shop_code' => $shopCode,
            'username' => $username,
            'password' => $password,
        ], $clientIp);
        $data = self::data($res);

        if (!empty($data['cua_hang_khoa']) || ($data['tenant']['status'] ?? '') !== 'active') {
            throw new HttpException(403, self::LOCKED);
        }
        self::guardManager($data['user'] ?? []);

        return [
            'tenant_code' => (string) ($data['tenant']['code'] ?? ''),
            'user_id' => (int) $data['user']['id'],
            'username' => (string) $data['user']['username'],
            'full_name' => (string) ($data['user']['full_name'] ?? ''),
            'refresh_token' => (string) ($data['refresh_token'] ?? ''),
        ];
    }

    /**
     * Hỏi lại POS phiên còn sống không: tài khoản còn hoạt động, cửa hàng còn hạn,
     * người còn quyền Quản lý. Trả refresh token mới (POS cấp lại mỗi lượt).
     */
    public static function recheck(string $refreshToken, string $clientIp): string
    {
        $data = self::data(PosClient::post('/auth/refresh', ['refresh_token' => $refreshToken], $clientIp));
        if (!empty($data['cua_hang_khoa'])) {
            throw new HttpException(403, self::LOCKED);
        }
        if (isset($data['user'])) {
            self::guardManager($data['user']);
        }

        return (string) ($data['refresh_token'] ?? '');
    }

    /** @param array{status: int, body: array<string, mixed>} $res */
    private static function data(array $res): array
    {
        $body = $res['body'];
        if ($res['status'] >= 500 || ($res['status'] >= 400 && !isset($body['message']))) {
            error_log('POS trả lỗi ' . $res['status']);
            throw new HttpException(503, 'Máy chủ POS đang lỗi, vui lòng thử lại sau');
        }
        if ($res['status'] >= 400 || empty($body['success'])) {
            // Câu báo của POS đã cố ý chung chung (không nói ô nào sai) nên chuyển nguyên.
            throw new HttpException($res['status'] ?: 401, (string) ($body['message'] ?? 'Đăng nhập không thành công'));
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * Chỉ người được giao cửa "Quản lý" bên POS mới vào quản trị web. Cùng luật
     * CuaVao() của POS: cột access_areas trống thì suy theo vai trò.
     */
    private static function guardManager(array $user): void
    {
        $role = (string) ($user['role']['name'] ?? '');
        $areas = array_filter(explode(',', (string) ($user['access_areas'] ?? '')));
        $manager = $role === 'super_admin'
            || in_array('quan_ly', $areas, true)
            || (!$areas && $role === 'admin');
        if (!$manager || empty($user['id'])) {
            throw new HttpException(403, 'Tài khoản này không có quyền Quản lý bên POS nên không vào được trang quản trị web');
        }
    }
}
