<?php

declare(strict_types=1);

namespace App\Core;

/** Mã hoá chuỗi bí mật trước khi cất vào DB, khoá là APP_KEY trong .env. */
final class Secret
{
    public static function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** null = hỏng hoặc mã bằng khoá khác (đã đổi APP_KEY). */
    public static function open(string $sealed): ?string
    {
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key(),
        );

        return $plain === false ? null : $plain;
    }

    /** Chữ ký HMAC bằng APP_KEY — cho mã ngắn hạn (vd mã xem bản nháp giao diện). */
    public static function ky(string $noiDung): string
    {
        return hash_hmac('sha256', $noiDung, self::key());
    }

    private static function key(): string
    {
        $key = base64_decode(Env::get('APP_KEY'), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY trong .env chưa có hoặc sai độ dài');
        }

        return $key;
    }
}
