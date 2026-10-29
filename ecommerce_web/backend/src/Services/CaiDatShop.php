<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;

/**
 * Thông tin cửa hàng trên web (bảng shop_settings): những gì hiện ở đầu trang, chân trang.
 * Màu/font không nằm ở đây — thuộc màn Trang trí cửa hàng.
 *
 * KHUON là nơi duy nhất khai ô nào có, kiểu gì, mặc định ra sao. Dữ liệu cũ thiếu ô
 * mới thì tự nhận mặc định; ô lạ gửi lên bị bỏ qua.
 */
final class CaiDatShop
{
    /** @var array<string, array{loai: string, mac_dinh: string, max?: int}> */
    private const KHUON = [
        'ten_hien_thi' => ['loai' => 'chu', 'mac_dinh' => '', 'max' => 80],
        'logo' => ['loai' => 'anh', 'mac_dinh' => ''],
        'gioi_thieu_ngan' => ['loai' => 'chu', 'mac_dinh' => '', 'max' => 300],
        'hotline' => ['loai' => 'sdt', 'mac_dinh' => ''],
        'zalo' => ['loai' => 'sdt', 'mac_dinh' => ''],
        'email' => ['loai' => 'email', 'mac_dinh' => ''],
        'dia_chi' => ['loai' => 'chu', 'mac_dinh' => '', 'max' => 255],
        'gio_mo_cua' => ['loai' => 'chu', 'mac_dinh' => '', 'max' => 80],
        'facebook' => ['loai' => 'link', 'mac_dinh' => ''],
        'instagram' => ['loai' => 'link', 'mac_dinh' => ''],
        'tiktok' => ['loai' => 'link', 'mac_dinh' => ''],
        'youtube' => ['loai' => 'link', 'mac_dinh' => ''],
    ];

    /** @return array<string, string> đủ mọi ô của KHUON */
    public static function doc(int $shopId): array
    {
        $row = Db::one('SELECT data FROM shop_settings WHERE shop_id = ?', [$shopId]);
        $luu = $row === null ? [] : (json_decode((string) $row['data'], true) ?: []);

        $kq = [];
        foreach (self::KHUON as $khoa => $o) {
            $kq[$khoa] = $luu[$khoa] ?? $o['mac_dinh'];
        }

        return $kq;
    }

    /**
     * Kiểm từng ô theo KHUON rồi lưu; sai ô nào báo đúng ô đó (422).
     *
     * @param array<string, mixed> $vao
     * @return array<string, string>
     */
    public static function luu(int $shopId, array $vao, string $nguoiSua): array
    {
        $kq = [];
        $loi = [];
        foreach (self::KHUON as $khoa => $o) {
            try {
                $kq[$khoa] = self::kiem($shopId, $o, $vao[$khoa] ?? $o['mac_dinh']);
            } catch (\InvalidArgumentException $e) {
                $loi[$khoa] = $e->getMessage();
            }
        }
        if ($loi) {
            throw new HttpException(422, 'Vui lòng kiểm tra lại các ô được đánh dấu', $loi);
        }

        Db::exec(
            'INSERT INTO shop_settings (shop_id, data, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by = VALUES(updated_by)',
            [$shopId, json_encode($kq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $nguoiSua],
        );

        return $kq;
    }

    /** @param array{loai: string, mac_dinh: string, max?: int} $o */
    private static function kiem(int $shopId, array $o, mixed $v): string
    {
        if (!is_string($v)) {
            throw new \InvalidArgumentException('Giá trị không hợp lệ');
        }
        // Gộp khoảng trắng: dữ liệu này in thẳng lên đầu/chân trang, xuống dòng là vỡ khung.
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));
        if ($v === '') {
            return '';
        }

        switch ($o['loai']) {
            case 'chu':
                if (mb_strlen($v) > $o['max']) {
                    throw new \InvalidArgumentException("Tối đa {$o['max']} ký tự");
                }
                return $v;
            case 'sdt':
                $so = (string) preg_replace('/[\s.\-]/', '', $v);
                if (!preg_match('/^\+?\d{8,15}$/', $so)) {
                    throw new \InvalidArgumentException('Số điện thoại không hợp lệ');
                }
                return $so;
            case 'email':
                if (mb_strlen($v) > 150 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                    throw new \InvalidArgumentException('Email không hợp lệ');
                }
                return $v;
            case 'link':
                // Chỉ http(s): link in thẳng vào href, để lọt "javascript:" là chạy mã trên web của shop.
                if (strlen($v) > 255 || !preg_match('#^https?://[^\s<>"]+$#i', $v) || filter_var($v, FILTER_VALIDATE_URL) === false) {
                    throw new \InvalidArgumentException('Link phải bắt đầu bằng https://');
                }
                return $v;
            case 'anh':
                if (!KhoAnh::coAnh($shopId, $v)) {
                    throw new \InvalidArgumentException('Ảnh không còn, vui lòng tải lại');
                }
                return $v;
        }

        throw new \LogicException('Loại ô lạ: ' . $o['loai']);
    }
}
