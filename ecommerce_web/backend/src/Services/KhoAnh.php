<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Ảnh riêng của web (logo, banner…), mỗi shop một thư mục storage/media/<shop_id>/.
 *
 * Tên tệp là băm nội dung: tải lại cùng ảnh không sinh bản trùng, và trình duyệt
 * được cache vĩnh viễn vì đổi ảnh là đổi tên.
 */
final class KhoAnh
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    // Không nhận SVG: SVG chở được mã chạy trên tên miền của shop.
    private const DUOI = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    private const TEN_RE = '/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/';

    /** @param array{tmp_name?: string, error?: int, size?: int} $tep một phần tử của $_FILES */
    public static function luu(int $shopId, array $tep): string
    {
        if (($tep['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($tep['size'] ?? 0) > self::MAX_BYTES) {
            throw new HttpException(422, 'Ảnh tối đa 2 MB');
        }
        if (($tep['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($tep['tmp_name'] ?? ''))) {
            throw new HttpException(422, 'Chưa chọn ảnh hoặc tải lên bị lỗi');
        }
        // Xem nội dung thật chứ không tin đuôi tệp hay Content-Type trình duyệt gửi.
        $info = @getimagesize($tep['tmp_name']);
        $duoi = $info === false ? null : (self::DUOI[$info[2]] ?? null);
        if ($duoi === null) {
            throw new HttpException(422, 'Chỉ nhận ảnh JPG, PNG, WEBP hoặc GIF');
        }

        $ten = substr(hash_file('sha256', $tep['tmp_name']), 0, 24) . '.' . $duoi;
        $dir = self::thuMuc($shopId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Không tạo được thư mục ảnh $dir");
        }
        if (!is_file("$dir/$ten") && !move_uploaded_file($tep['tmp_name'], "$dir/$ten")) {
            throw new \RuntimeException("Không lưu được ảnh vào $dir");
        }

        return $ten;
    }

    public static function coAnh(int $shopId, string $ten): bool
    {
        return preg_match(self::TEN_RE, $ten) === 1 && is_file(self::thuMuc($shopId) . '/' . $ten);
    }

    /** Gửi thẳng tệp ảnh ra trình duyệt (route /api/anh/{ten}). */
    public static function phat(int $shopId, string $ten): void
    {
        if (!self::coAnh($shopId, $ten)) {
            throw new HttpException(404, 'Không tìm thấy ảnh');
        }
        $tep = self::thuMuc($shopId) . '/' . $ten;
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][pathinfo($ten, PATHINFO_EXTENSION)];
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($tep));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($tep);
    }

    public static function url(string $ten): string
    {
        return $ten === '' ? '' : '/api/anh/' . $ten;
    }

    private static function thuMuc(int $shopId): string
    {
        return dirname(__DIR__, 2) . '/storage/media/' . $shopId;
    }
}
