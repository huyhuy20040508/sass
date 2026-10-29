<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Core\HttpException;

/** Gọi API POS từ máy chủ web. */
final class PosClient
{
    /**
     * @param string $clientIp IP trình duyệt của người dùng — POS chặn dò mật khẩu theo IP,
     *                         thiếu thì mọi người dùng web chung một hạn mức của máy chủ này
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function post(string $path, array $body, string $clientIp): array
    {
        return self::send('POST', $path, $body, $clientIp);
    }

    /**
     * Gọi bất kỳ đường nào, kèm danh tính: `bearer` (phiên POS của người đang đăng nhập)
     * hoặc `key` (khoá tích hợp của shop — web gọi thay cửa hàng, không cần ai đăng nhập).
     *
     * @param array{bearer?: string, key?: string} $auth
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function send(string $method, string $path, ?array $body, string $clientIp, array $auth = []): array
    {
        $headers = ['Accept: application/json'];
        if ($clientIp !== '') {
            $headers[] = 'X-Forwarded-For: ' . $clientIp;
        }
        if (!empty($auth['bearer'])) {
            $headers[] = 'Authorization: Bearer ' . $auth['bearer'];
        }
        if (!empty($auth['key'])) {
            $headers[] = 'X-Integration-Key: ' . $auth['key'];
        }

        $ch = curl_init(rtrim(Env::get('POS_API_URL'), '/') . $path);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            error_log("POS không trả lời $method $path: $error");
            throw new HttpException(503, 'Không kết nối được máy chủ POS, vui lòng thử lại sau');
        }
        $json = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($json) ? $json : []];
    }
}
