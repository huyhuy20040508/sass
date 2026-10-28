<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\PosAdmin;

/**
 * Người quản trị đang đăng nhập trong lượt gọi này.
 *
 * Route khai `admin: true` thì Router xác định shop theo tên miền rồi gọi
 * resolve(): phiên chỉ dùng được trên đúng shop đã đăng nhập — token của tiệm A
 * mang sang địa chỉ tiệm B không mở được gì.
 */
final class CurrentAdmin
{
    // Bằng hạn refresh token của POS (JWT_REFRESH_TTL 168h); mỗi lần hỏi lại POS được gia hạn thêm.
    public const SESSION_DAYS = 7;
    // Bao lâu hỏi lại POS một lần: bên POS khoá tài khoản thì chậm nhất chừng này phiên web mới chết.
    private const RECHECK_SECONDS = 300;

    /** @var array{session_id: int, username: string, full_name: string}|null */
    private static ?array $admin = null;

    public static function resolve(Request $req): void
    {
        $token = $req->bearerToken();
        $row = $token === '' ? null : Db::one(
            'SELECT id, username, full_name, pos_refresh_token,
                    pos_checked_at < NOW() - INTERVAL ' . self::RECHECK_SECONDS . ' SECOND AS due
               FROM admin_sessions
              WHERE token_hash = ? AND shop_id = ? AND expires_at > NOW()',
            [hash('sha256', $token), CurrentShop::id()],
        );
        if ($row === null) {
            throw new HttpException(401, 'Phiên đăng nhập đã hết, vui lòng đăng nhập lại');
        }
        if ($row['due']) {
            self::recheck((int) $row['id'], (string) $row['pos_refresh_token'], $req->ip());
        }

        self::$admin = ['session_id' => (int) $row['id'], 'username' => $row['username'], 'full_name' => $row['full_name']];
    }

    /** @return array{session_id: int, username: string, full_name: string} */
    public static function get(): array
    {
        return self::$admin ?? throw new \LogicException('Route này chưa khai admin: true');
    }

    private static function recheck(int $sessionId, string $sealed, string $ip): void
    {
        // Không giải mã được (đã đổi APP_KEY) thì không còn gì để hỏi POS.
        $old = Secret::open($sealed);
        if ($old === null || $old === '') {
            self::kill($sessionId);
        }
        try {
            $refresh = PosAdmin::recheck($old, $ip);
        } catch (HttpException $e) {
            // Chỉ đá ra khi POS nói rõ phiên không còn (401/403). POS bảo trì hay đang
            // chặn vì gọi dày (429) thì cho qua, lượt sau hỏi lại vì pos_checked_at chưa đổi.
            if ($e->status !== 401 && $e->status !== 403) {
                return;
            }
            // 403 mang lý do cụ thể (hết hạn, mất quyền) — chuyển cho màn đăng nhập hiện ra.
            self::kill($sessionId, $e->status === 403 ? $e->getMessage() : null);
        }
        Db::exec(
            'UPDATE admin_sessions SET pos_refresh_token = ?, pos_checked_at = NOW(), expires_at = NOW() + INTERVAL ' . self::SESSION_DAYS . ' DAY WHERE id = ?',
            [Secret::seal($refresh), $sessionId],
        );
    }

    private static function kill(int $sessionId, ?string $reason = null): never
    {
        Db::exec('DELETE FROM admin_sessions WHERE id = ?', [$sessionId]);
        throw new HttpException(401, $reason ?? 'Phiên đăng nhập đã hết, vui lòng đăng nhập lại');
    }
}
