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
        $ch = curl_init(rtrim(Env::get('POS_API_URL'), '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Forwarded-For: ' . $clientIp,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status === 0) {
            error_log("POS không trả lời $path: $error");
            throw new HttpException(503, 'Không kết nối được máy chủ POS, vui lòng thử lại sau');
        }
        $json = json_decode((string) $raw, true);

        return ['status' => $status, 'body' => is_array($json) ? $json : []];
    }
}
