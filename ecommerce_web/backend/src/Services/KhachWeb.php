<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;

/**
 * Tài khoản khách mua hàng trên web của một shop.
 *
 * Chia việc với POS: web giữ mật khẩu và phiên, POS giữ HỒ SƠ khách. Đăng ký
 * xong là ở POS có ngay khách ấy, nên quầy bán cho người đặt web mà không phải
 * khai lại, và mọi báo cáo khách hàng vẫn đếm đủ.
 */
final class KhachWeb
{
    public const SESSION_DAYS = 30;
    public const MAT_KHAU_TOI_THIEU = 6;

    /**
     * Số điện thoại là danh tính: chuẩn hoá về CHỈ CHỮ SỐ trước mọi việc.
     * "0912 345 678" và "0912345678" là một người; để nguyên thì hai lượt đăng ký
     * cùng số vẫn lọt qua khoá duy nhất và POS đẻ ra hai hồ sơ.
     */
    public static function chuanHoaSdt(string $sdt): string
    {
        return preg_replace('/\D+/', '', $sdt) ?? '';
    }

    /**
     * @return array{errors: array<string, string>, ten: string, sdt: string, email: string}
     */
    public static function soatHoSo(string $ten, string $sdt, string $email): array
    {
        $ten = trim($ten);
        $sdt = self::chuanHoaSdt($sdt);
        $email = strtolower(trim($email));

        $errors = [];
        if ($ten === '') {
            $errors['full_name'] = 'Nhập họ tên';
        }
        if ($sdt === '') {
            $errors['phone'] = 'Nhập số điện thoại';
        } elseif (strlen($sdt) < 9 || strlen($sdt) > 11) {
            $errors['phone'] = 'Số điện thoại phải có 9–11 chữ số';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email không đúng dạng';
        }

        return ['errors' => $errors, 'ten' => $ten, 'sdt' => $sdt, 'email' => $email];
    }

    /**
     * Mở tài khoản: tạo/khớp hồ sơ bên POS trước, rồi mới ghi tài khoản web.
     *
     * Thứ tự đó là cố ý. POS từ chối (trùng email, cửa hàng hết hạn, mất kết nối)
     * thì web chưa ghi gì cả — người dùng thử lại là xong, không để lại tài khoản
     * mồ côi không trỏ tới khách nào.
     *
     * @return array{id: int, pos_customer_id: int, full_name: string, phone: string, email: string}
     */
    public static function dangKy(int $shopId, string $ten, string $sdt, string $email, string $matKhau, string $ip): array
    {
        if (Db::one('SELECT id FROM khach_web WHERE shop_id = ? AND phone = ?', [$shopId, $sdt]) !== null) {
            throw new HttpException(422, 'Số điện thoại này đã có tài khoản', ['phone' => 'Số này đã đăng ký, hãy đăng nhập']);
        }

        $khoa = PosKetNoi::khoa($shopId);
        if ($khoa === null) {
            throw new HttpException(503, 'Web chưa kết nối với phần mềm bán hàng, vui lòng liên hệ cửa hàng');
        }

        $res = PosClient::send('POST', '/web-ban/khach', [
            'full_name' => $ten,
            'phone' => $sdt,
            'email' => $email,
        ], $ip, ['key' => $khoa]);
        if ($res['status'] >= 400) {
            $loi = $res['body']['errors'] ?? [];
            throw new HttpException(
                $res['status'] === 422 ? 422 : 502,
                (string) ($res['body']['message'] ?? 'Không tạo được hồ sơ khách bên phần mềm bán hàng'),
                is_array($loi) ? array_map('strval', $loi) : [],
            );
        }
        $posId = (int) ($res['body']['data']['id'] ?? 0);
        if ($posId === 0) {
            throw new HttpException(502, 'Phần mềm bán hàng không trả về hồ sơ khách');
        }

        $id = Db::insert(
            'INSERT INTO khach_web (shop_id, pos_customer_id, phone, email, full_name, password_hash)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$shopId, $posId, $sdt, $email, $ten, password_hash($matKhau, PASSWORD_DEFAULT)],
        );

        return [
            'id' => $id,
            'pos_customer_id' => $posId,
            'full_name' => $ten,
            'phone' => $sdt,
            'email' => $email,
        ];
    }

    /** @return array{id: int, pos_customer_id: int, full_name: string, phone: string, email: string} */
    public static function dangNhap(int $shopId, string $sdt, string $matKhau): array
    {
        $row = Db::one(
            'SELECT id, pos_customer_id, full_name, phone, email, password_hash FROM khach_web WHERE shop_id = ? AND phone = ?',
            [$shopId, self::chuanHoaSdt($sdt)],
        );
        // Sai số hay sai mật khẩu đều trả một câu: nói rõ "số này chưa đăng ký" là
        // cho người lạ dò xem ai đang mua hàng ở tiệm.
        if ($row === null || !password_verify($matKhau, (string) $row['password_hash'])) {
            throw new HttpException(422, 'Số điện thoại hoặc mật khẩu không đúng');
        }

        return [
            'id' => (int) $row['id'],
            'pos_customer_id' => (int) $row['pos_customer_id'],
            'full_name' => (string) $row['full_name'],
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
        ];
    }

    /** Cấp phiên mới, trả token thô (database chỉ giữ băm). */
    public static function moPhien(int $shopId, int $khachId): string
    {
        $token = bin2hex(random_bytes(32));
        Db::exec(
            'INSERT INTO khach_sessions (shop_id, khach_id, token_hash, expires_at)
             VALUES (?, ?, ?, NOW() + INTERVAL ' . self::SESSION_DAYS . ' DAY)',
            [$shopId, $khachId, hash('sha256', $token)],
        );

        return $token;
    }
}
