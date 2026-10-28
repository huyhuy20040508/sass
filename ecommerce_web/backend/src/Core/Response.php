<?php

declare(strict_types=1);

namespace App\Core;

// Cùng khuôn {success, message, data, meta, errors} với API POS để frontend
// đọc lỗi một kiểu, dù lỗi đến từ máy chủ web hay chuyển tiếp từ POS.
final class Response
{
    public static function ok(mixed $data = null, array $meta = [], int $status = 200, string $message = ''): void
    {
        $body = ['success' => true];
        if ($message !== '') {
            $body['message'] = $message;
        }
        if ($data !== null) {
            $body['data'] = $data;
        }
        if ($meta) {
            $body['meta'] = $meta;
        }
        self::send($status, $body);
    }

    public static function error(int $status, string $message, array $errors = []): void
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors) {
            $body['errors'] = $errors;
        }
        self::send($status, $body);
    }

    private static function send(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
